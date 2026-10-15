<?php

namespace Omnilab\Tides;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * NOAA CO-OPS (Tides and Currents): a station's water levels, tide
 * predictions and water temperature as series, the station itself - no key.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent
 *     datum: MLLW                      # the water levels' datum: MLLW, MSL, MHHW, MLW, NAVD, STND...
 *     radius_km: 50                    # how far from a Point the nearest station is looked for
 *     application: omnilab             # the name the API logs the calls under
 *     throttle: 0.5                    # seconds between two calls: the API asks its callers to space them
 *     base_uri: 'https://api.tidesandcurrents.noaa.gov/api/prod/datagetter'
 *     metadata_uri: 'https://api.tidesandcurrents.noaa.gov/mdapi/prod/webapi/'
 */
final class TidesSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'tides',
            'omnilab.required_options' => [],
            'datum' => 'MLLW',
            'radius_km' => 50.0,
            'application' => 'omnilab',
            'throttle' => 0.5,
            'base_uri' => TidesSource::DATA_URI,
            'metadata_uri' => TidesSource::METADATA_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new TidesSource(
            $this->http ?? HttpClient::create(),
            strtoupper((string) $c->get('datum')),
            (float) $c->get('radius_km'),
            (string) $c->get('application'),
            (string) $c->get('metadata_uri'),
            (string) $c->get('base_uri'),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
