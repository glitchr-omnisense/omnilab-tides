<?php

namespace Omnilab\Tides\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Model\BBox;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Point;
use Omnilab\Model\Scheme;
use Omnilab\Model\Series;
use Omnilab\Source\Capability;
use Omnilab\Source\SeriesQuery;
use Omnilab\Tides\TidesSource;
use Omnilab\Tides\TidesSourceFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from api.tidesandcurrents.noaa.gov on 2026-10-15: The
 * Battery, New York (8518750), its metadata; its water level, tide
 * predictions (six-minute and highs and lows) and water temperature on
 * 2026-10-14, its verified hourly heights and highs and lows on
 * 2025-01-01, its hourly predictions for 2026-10-16; the API's answer for
 * hourly heights not yet verified (2026-10-14) and for more than 31 days
 * of six-minute values; an unknown station (404). The water level
 * stations' list (302 on 2026-10-15) is cut to five around New York.
 */
final class TidesSourceTest extends TestCase
{
    /** @var list<array{string, array<string, string>}> */
    private array $calls = [];

    private function source(?MockResponse $response = null, array $options = []): TidesSource
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $this->calls[] = [$path, $query];
            if ($response) {
                return $response;
            }
            $day = substr((string) ($query['begin_date'] ?? ''), 0, 8);
            $fixture = match (true) {
                str_ends_with($path, '/stations/8518750.json') => 'station-8518750.json',
                str_contains($path, '/stations/') => 'station-unknown.json',
                str_ends_with($path, '/stations.json') => 'stations-waterlevels.json',
                'hourly_height' === $query['product'] && '20261014' === $day => 'no-data.json',
                'predictions' === $query['product'] => ['6' => 'predictions-2026-10-14.json', 'hilo' => 'hilo-2026-10-14.json', 'h' => 'hourly-predictions-2026-10-16.json'][$query['interval']],
                default => $query['product'].'-'.substr($day, 0, 4).'-'.substr($day, 4, 2).'-'.substr($day, 6, 2).'.json',
            };

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture), ['http_code' => 'station-unknown.json' === $fixture ? 404 : 200]);
        });
        $source = (new TidesSourceFactory($http))->create(['throttle' => 0] + $options);
        self::assertInstanceOf(TidesSource::class, $source);

        return $source;
    }

    public function testAStation(): void
    {
        $battery = $this->source()->entity('coops:8518750');

        self::assertSame('coops.station:8518750', $battery->id->key());
        self::assertSame(['The Battery', Domain::EARTH, 'station'], [$battery->label, $battery->domain, $battery->kind]);
        self::assertEquals(new Point(40.700554, -74.01417), $battery->place);
        self::assertSame(['NY', 'EST (UTC-5)', 'Mixed', true, 'BATN6'], [$battery->value('state'), $battery->value('time_zone'), $battery->value('tide_type'), $battery->value('tidal'), $battery->value('shef_code')]);
        self::assertSame('https://tidesandcurrents.noaa.gov/stationhome.html?id=8518750', $battery->url);
        self::assertNull($this->source()->entity('https://tidesandcurrents.noaa.gov/stationhome.html?id=9999999'));
    }

    public function testAnotherBaseIsRefusedBeforeAnyCall(): void
    {
        $this->expectException(NotSupportedException::class);
        try {
            $this->source()->entity('nws.station:KNYC');
        } finally {
            self::assertSame([], $this->calls);
        }
    }

    public function testCapabilities(): void
    {
        self::assertSame([Capability::ENTITY, Capability::SERIES, Capability::FORECAST, Capability::HISTORICAL, Capability::STATION], $this->source()->capabilities());
    }

    public function testADaysWaterLevelPredictionsAndTemperatureHourly(): void
    {
        [$level, $predicted, $highLow, $temperature] = $this->source()->series(new SeriesQuery(['water_level', 'predictions', 'high_low_predictions', 'water_temperature'], Identifier::of(Scheme::COOPS_STATION, '8518750'), new \DateTimeImmutable('2026-10-14'), new \DateTimeImmutable('2026-10-14')));

        self::assertSame(['water_level', 'm', SeriesQuery::HOURLY, false, 'tides'], [$level->variable, $level->unit, $level->step, $level->forecast, $level->source]);
        self::assertSame('The Battery (8518750), MLLW', $level->label);
        self::assertEquals(new Point(40.7006, -74.0142), $level->place);
        self::assertCount(24, $level);
        self::assertSame([1.241, 1.45, 1.579], \array_slice($level->values(), 0, 3));
        self::assertSame([0.889, 1.154, 1.311], \array_slice($predicted->values(), 0, 3));
        self::assertTrue($predicted->forecast);
        self::assertNull($highLow->step);
        self::assertSame([1.363, 0.228, 1.581, 0.208], $highLow->values());
        self::assertSame('2026-10-14T02:56:00+00:00', $highLow->times()[0]->format(\DATE_ATOM));
        self::assertSame(['°C', 18.7], [$temperature->unit, $temperature->values()[0]]);
        self::assertSame('The Battery (8518750)', $temperature->label);

        $call = $this->calls[0][1];
        self::assertSame(['water_level', '8518750', '20261014 00:00', '20261014 23:59', 'MLLW', 'metric', 'gmt', 'json', 'omnilab'], [$call['product'], $call['station'], $call['begin_date'], $call['end_date'], $call['datum'], $call['units'], $call['time_zone'], $call['format'], $call['application']]);
        self::assertArrayNotHasKey('datum', $this->calls[3][1]);
        self::assertSame(['6', 'hilo'], [$this->calls[1][1]['interval'], $this->calls[2][1]['interval']]);
    }

    public function testEverySixMinutesAndTheDaysMaximum(): void
    {
        $battery = Identifier::of(Scheme::COOPS_STATION, '8518750');
        $source = $this->source();
        [$level] = $source->series(new SeriesQuery(['water_level'], $battery, new \DateTimeImmutable('2026-10-14'), new \DateTimeImmutable('2026-10-14'), 'PT6M'));
        [$hourly, $highLow] = $source->series(new SeriesQuery(['hourly_height', 'high_low'], $battery, new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2025-01-01'), SeriesQuery::DAILY, Series::MAX));

        self::assertCount(240, $level);
        self::assertSame([1.874], $hourly->values());
        self::assertSame([1.627, 0.341, 1.923, -0.095], $highLow->values());
    }

    public function testThePointsNearestStationAndAPrediction(): void
    {
        [$predicted] = $this->source()->series(new SeriesQuery(['hourly_predictions'], new Point(40.70, -74.02), new \DateTimeImmutable('2026-10-16'), new \DateTimeImmutable('2026-10-16')));

        self::assertSame('The Battery (8518750), MLLW', $predicted->label);
        self::assertSame([0.485, 0.739, 0.962, 1.107], \array_slice($predicted->values(), 0, 4));
        self::assertTrue($predicted->forecast);
        self::assertSame(['waterlevels', '8518750', 'h'], [$this->calls[0][1]['type'], $this->calls[1][1]['station'], $this->calls[1][1]['interval']]);
        [$none] = $this->source()->series(new SeriesQuery(['water_level'], new Point(0, -150)));
        self::assertSame(['water_level', 'm', [], 'tides'], [$none->variable, $none->unit, $none->points, $none->source], 'no station near: a series without points');
        self::assertEquals(new Point(0, -150), $none->place);
    }

    public function testADatumOption(): void
    {
        $this->source(null, ['datum' => 'msl'])->series(new SeriesQuery(['water_level'], Identifier::of(Scheme::COOPS_STATION, '8518750'), new \DateTimeImmutable('2026-10-14'), new \DateTimeImmutable('2026-10-14')));

        self::assertSame('MSL', $this->calls[0][1]['datum']);
    }

    public function testAProductNotYetPublishedIsASeriesWithoutValues(): void
    {
        [$hourly] = $this->source()->series(new SeriesQuery(['hourly_height'], Identifier::of(Scheme::COOPS_STATION, '8518750'), new \DateTimeImmutable('2026-10-14'), new \DateTimeImmutable('2026-10-14')));

        self::assertCount(24, $hourly);
        self::assertNull($hourly->max());
    }

    public function testAMonthIsTheLongestRequestOfSixMinuteValues(): void
    {
        $this->source(new MockResponse('{"metadata":{"id":"8518750","name":"The Battery","lat":"40.7006","lon":"-74.0142"}, "data": []}'))->series(new SeriesQuery(['water_level'], Identifier::of(Scheme::COOPS_STATION, '8518750'), new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-10-14'), SeriesQuery::DAILY, Series::MAX));

        self::assertSame([['20260801 00:00', '20260831 23:59'], ['20260901 00:00', '20261001 23:59'], ['20261002 00:00', '20261014 23:59']], array_map(static fn (array $c) => [$c[1]['begin_date'], $c[1]['end_date']], $this->calls));
    }

    public function testTheApisErrorsAndRefusals(): void
    {
        try {
            $this->source(new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/range-exceeded.json')))->series(new SeriesQuery(['water_level'], Identifier::of(Scheme::COOPS_STATION, '8518750')));
            self::fail('An error.');
        } catch (ProviderException $e) {
            self::assertStringContainsString('Range Limit Exceeded', $e->getMessage());
        }
        $this->calls = [];
        $source = $this->source();
        foreach ([new SeriesQuery(['currents'], new Point(40.7, -74)), new SeriesQuery(['water_level'], new BBox(-74.1, 40.6, -73.9, 40.8)), new SeriesQuery(['water_level'], Identifier::of('ghcn', 'USW00094728'))] as $query) {
            try {
                $source->series($query);
                self::fail('Refused.');
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }
}
