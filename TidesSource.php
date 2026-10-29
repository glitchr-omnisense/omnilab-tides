<?php

namespace Omnilab\Tides;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Point;
use Omnilab\Model\Property;
use Omnilab\Model\Scheme;
use Omnilab\Model\Series;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\SeriesQuery;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * NOAA's tides and water levels, from the Center for Operational
 * Oceanographic Products and Services (CO-OPS, Tides and Currents): the
 * water level observed at a station every six minutes, the verified
 * hourly heights and highs and lows, the tide predictions (every six
 * minutes, hourly, or the highs and lows), the water and air temperature,
 * through its Data API (api.tidesandcurrents.noaa.gov/api/prod/datagetter);
 * a station itself through its Metadata API.
 *
 * No key. The API asks its callers to space their calls: half a second
 * between two by default. A request is cut to the API's length limits (a
 * month of six-minute values, a year of hourly ones or of highs and lows).
 */
final class TidesSource extends HttpSource
{
    public const DATA_URI = 'https://api.tidesandcurrents.noaa.gov/api/prod/datagetter';
    public const METADATA_URI = 'https://api.tidesandcurrents.noaa.gov/mdapi/prod/webapi/';

    /**
     * The variables series() serves: the API's product, its interval, its
     * unit in metric, the days a request may cover, whether a prediction,
     * whether its points are irregular (highs and lows).
     *
     * @var array<string, array{string, string|null, string, int, bool, bool}>
     */
    public const VARIABLES = [
        'water_level' => ['water_level', null, 'm', 31, false, false],
        'hourly_height' => ['hourly_height', null, 'm', 365, false, false],
        'high_low' => ['high_low', null, 'm', 365, false, true],
        'predictions' => ['predictions', '6', 'm', 31, true, false],
        'hourly_predictions' => ['predictions', 'h', 'm', 365, true, false],
        'high_low_predictions' => ['predictions', 'hilo', 'm', 365, true, true],
        'water_temperature' => ['water_temperature', null, '°C', 31, false, false],
        'air_temperature' => ['air_temperature', null, '°C', 31, false, false],
    ];

    /** The products measured against a datum. */
    private const LEVELS = ['water_level', 'hourly_height', 'high_low', 'predictions'];

    /** @var list<array{Identifier, Point, string}>|null the water level stations, read once */
    private ?array $stations = null;

    /**
     * @param string $datum  the datum of the water levels: MLLW (the charts'), MSL, MHHW, NAVD, STND...
     * @param float  $radius how far from a Point the nearest station is looked for, in kilometres
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly string $datum = 'MLLW',
        private readonly float $radius = 50.0,
        private readonly string $application = 'omnilab',
        private readonly string $metadataUri = self::METADATA_URI,
        string $baseUri = self::DATA_URI,
        array $headers = [],
        float $throttle = 0.5,
    ) {
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'tides';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY, Capability::SERIES, Capability::FORECAST, Capability::HISTORICAL, Capability::STATION];
    }

    /** A station (coops.station:8518750, coops:8518750, its page): its name, place, state, time zone, tide type. One call; null when unknown. */
    public function entity(Identifier|string $id): ?Entity
    {
        $station = self::identify($id, Scheme::COOPS_STATION) ?? throw NotSupportedException::identifier('tides', (string) $id);
        $data = $this->getJson($this->metadataUri.'stations/'.$station->value.'.json');
        $s = $data['stations'][0] ?? null;
        if (!\is_array($s) || !is_numeric($s['lat'] ?? null) || !is_numeric($s['lng'] ?? null)) {
            return null;
        }
        $properties = [];
        $add = static function (string $key, string $label, mixed $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'tides');
            }
        };
        $add('state', 'State', self::text($s['state'] ?? null));
        $add('time_zone', 'Time zone', null === ($tz = self::text($s['timezone'] ?? null)) ? null : $tz.(is_numeric($s['timezonecorr'] ?? null) ? \sprintf(' (UTC%+d)', $s['timezonecorr']) : ''));
        $add('tide_type', 'Tide type', self::text($s['tideType'] ?? null));
        $add('tidal', 'Tidal', \is_bool($s['tidal'] ?? null) ? $s['tidal'] : null);
        $add('great_lakes', 'Great Lakes', \is_bool($s['greatlakes'] ?? null) ? $s['greatlakes'] : null);
        $add('shef_code', 'SHEF code (NWS)', self::text($s['shefcode'] ?? null));
        $add('affiliations', 'Affiliations', self::text($s['affiliations'] ?? null));

        return new Entity(
            id: $station,
            label: self::text($s['name'] ?? null) ?? $station->value,
            domain: Domain::EARTH,
            kind: 'station',
            properties: $properties,
            url: $station->url(),
            place: new Point((float) $s['lat'], (float) $s['lng']),
            source: 'tides',
        );
    }

    /**
     * The series of a station named (coops.station) or of the nearest water
     * level station of a Point, one per variable asked (VARIABLES), in UTC:
     * the six-minute products on the step's grid (the value at each step's
     * start, or the step's MAX, MIN, MEAN or SUM; PT6M gives them all), the
     * hourly ones likewise, the highs and lows as they come (irregular). The
     * period defaults to today; a date without a time is the whole day, in
     * the query's time zone (UTC by default). A product the station does
     * not have: a series without points.
     */
    public function series(SeriesQuery $query): array
    {
        foreach ($query->variables as $variable) {
            if (!isset(self::VARIABLES[$variable])) {
                throw NotSupportedException::operation('tides', \sprintf('serve "%s" (it serves %s)', $variable, implode(', ', array_keys(self::VARIABLES))));
            }
        }
        if (null !== $query->aggregation && !\in_array($query->aggregation, [Series::MAX, Series::MIN, Series::MEAN, Series::SUM], true)) {
            throw NotSupportedException::operation('tides', 'aggregate by '.$query->aggregation);
        }
        $station = $this->station($query, Scheme::COOPS_STATION);
        if (null === $station && !$query->place instanceof Point) {
            throw NotSupportedException::operation('tides', 'serve a series over an area (a Point: the nearest station answers; or a coops.station)');
        }
        $timezone = new \DateTimeZone($query->timezone ?? 'UTC');
        [$from, $to] = self::period($query->from, $query->to, $timezone);
        $step = new \DateInterval($query->step);

        if (null === $station) {
            $nearest = $this->nearest($query->place);
            if (null === $nearest) {
                // No station near: a series without points per variable, at the point asked.
                return array_map(static fn (string $v) => new Series($v, self::VARIABLES[$v][2], $query->place, [], self::VARIABLES[$v][5] ? null : $query->step, self::VARIABLES[$v][5] ? null : $query->aggregation, null, self::VARIABLES[$v][4], 'tides'), $query->variables);
            }
            [$station, $place, $name] = $nearest;
        } else {
            $place = null;
            $name = null;
        }

        $series = [];
        foreach ($query->variables as $variable) {
            [$product, $interval, $unit, $days, $prediction, $irregular] = self::VARIABLES[$variable];
            $points = [];
            for ($start = $from; $start < $to; $start = $end) {
                $end = min($to, $start->modify('+'.$days.' days'));
                $data = $this->getJson($this->baseUri, [
                    'product' => $product,
                    'station' => $station->value,
                    'begin_date' => $start->setTimezone(new \DateTimeZone('UTC'))->format('Ymd H:i'),
                    'end_date' => $end->modify('-1 minute')->setTimezone(new \DateTimeZone('UTC'))->format('Ymd H:i'),
                    'datum' => \in_array($product, self::LEVELS, true) ? $this->datum : null,
                    'interval' => $interval,
                    'units' => 'metric',
                    'time_zone' => 'gmt',
                    'format' => 'json',
                    'application' => $this->application,
                ]) ?? [];
                if (isset($data['error'])) {
                    $message = self::text(\is_array($data['error']) ? ($data['error']['message'] ?? null) : (string) $data['error']) ?? 'an error';
                    // A product the station does not have over the period: nothing, not an error.
                    if (str_contains($message, 'No data was found')) {
                        continue;
                    }
                    throw new ProviderException('tides', $message);
                }
                if (isset($data['metadata']['lat'], $data['metadata']['lon']) && is_numeric($data['metadata']['lat']) && is_numeric($data['metadata']['lon'])) {
                    $place ??= new Point((float) $data['metadata']['lat'], (float) $data['metadata']['lon']);
                    $name ??= self::text($data['metadata']['name'] ?? null);
                }
                foreach ($data['data'] ?? $data['predictions'] ?? [] as $row) {
                    $time = \is_string($row['t'] ?? null) ? \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $row['t'], new \DateTimeZone('UTC')) : false;
                    if (false !== $time) {
                        $points[$time->getTimestamp()] = [$time, is_numeric($row['v'] ?? null) ? 0 + $row['v'] : null];
                    }
                }
            }
            ksort($points);
            $points = array_values($points);
            $place ??= $this->entity($station)?->place;
            if (null === $place) {
                return [];
            }
            $label = ($name ?? $station->value).' ('.$station->value.')'.(\in_array($product, self::LEVELS, true) ? ', '.$this->datum : '');
            $series[] = $irregular
                ? new Series($variable, $unit, $place, $points, null, null, $label, $prediction, 'tides')
                : new Series($variable, $unit, $place, self::grid($points, $from, $to, $step, $query->aggregation), $query->step, $query->aggregation, $label, $prediction, 'tides');
        }

        return $series;
    }

    /**
     * The nearest water level station of a point, within the radius: its
     * id, place and name. The stations are read once (one call, ~300).
     *
     * @return array{Identifier, Point, string}|null
     */
    private function nearest(Point $point): ?array
    {
        if (null === $this->stations) {
            $this->stations = [];
            foreach ($this->getJson($this->metadataUri.'stations.json', ['type' => 'waterlevels'])['stations'] ?? [] as $s) {
                $id = Identifier::tryOf(Scheme::COOPS_STATION, (string) ($s['id'] ?? ''));
                if (null !== $id && is_numeric($s['lat'] ?? null) && is_numeric($s['lng'] ?? null)) {
                    $this->stations[] = [$id, new Point((float) $s['lat'], (float) $s['lng']), self::text($s['name'] ?? null) ?? $id->value];
                }
            }
        }
        $best = null;
        foreach ($this->stations as $station) {
            $distance = self::distance($point, $station[1]);
            if ($distance <= $this->radius && (null === $best || $distance < $best[1])) {
                $best = [$station, $distance];
            }
        }

        return $best[0] ?? null;
    }

    /**
     * The points on the step's grid: without an aggregation the value at
     * each step's start, with one the step's aggregate.
     *
     * @param list<array{\DateTimeImmutable, int|float|null}> $points in time order
     *
     * @return list<array{\DateTimeImmutable, int|float|null}>
     */
    private static function grid(array $points, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateInterval $step, ?string $aggregation): array
    {
        $at = [];
        foreach ($points as [$time, $value]) {
            $at[$time->getTimestamp()] = $value;
        }
        $grid = [];
        $i = 0;
        $n = \count($points);
        for ($start = $from; $start < $to && \count($grid) < 1_000_000; $start = $end) {
            $end = $start->add($step);
            if (null === $aggregation) {
                $grid[] = [$start, $at[$start->getTimestamp()] ?? null];
                continue;
            }
            $values = [];
            for (; $i < $n && $points[$i][0] < $end; ++$i) {
                if ($points[$i][0] >= $start && null !== $points[$i][1]) {
                    $values[] = $points[$i][1];
                }
            }
            $grid[] = [$start, $values ? match ($aggregation) {
                Series::MAX => max($values),
                Series::MIN => min($values),
                Series::MEAN => round(array_sum($values) / \count($values), 3),
                Series::SUM => array_sum($values),
            } : null];
        }

        return $grid;
    }

    /**
     * The period as two instants: a date without a time stands for the whole
     * day; no start is the end's day, no end is the start's day's end (today).
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    private static function period(?\DateTimeInterface $from, ?\DateTimeInterface $to, \DateTimeZone $timezone): array
    {
        $local = static fn (\DateTimeInterface $t) => new \DateTimeImmutable($t->format('Y-m-d H:i:s'), $timezone);
        $start = null === $from ? (null === $to ? new \DateTimeImmutable('today', $timezone) : $local($to)->setTime(0, 0)) : $local($from);
        $end = null === $to ? $start->setTime(0, 0)->modify('+1 day') : $local($to);
        if (null !== $to && '00:00:00' === $end->format('H:i:s')) {
            $end = $end->modify('+1 day');
        }

        return [$start, $end];
    }

    /** The great-circle distance between two points, in kilometres. */
    private static function distance(Point $a, Point $b): float
    {
        $dLat = deg2rad($b->latitude - $a->latitude);
        $dLon = deg2rad($b->longitude - $a->longitude);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($a->latitude)) * cos(deg2rad($b->latitude)) * sin($dLon / 2) ** 2;

        return 6371.0 * 2 * asin(min(1.0, sqrt($h)));
    }
}
