# omnilab/tides

NOAA CO-OPS (Tides and Currents), through its Data API and Metadata API
(`https://api.tidesandcurrents.noaa.gov/`), for [omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab):
a station's water level every six minutes, verified hourly heights and highs and lows, tide
predictions, water and air temperature, at a `coops.station` or the nearest station of a point, as
series; the station itself as an entity. No key.

## Installation

```sh
composer require omnilab/tides
```

```php
use Omnilab\Tides\TidesSourceFactory;

$tides = (new TidesSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // optional
    'datum' => 'MLLW',                 // optional: the water levels' datum
    'radius_km' => 50,                 // optional: how far from a Point the nearest station is looked for
    'throttle' => 0.5,                 // optional: seconds between two calls
]);
```

With the Symfony bundle, the factory is registered as `tides` when the bundle knows it; otherwise
register `TidesSourceFactory` as a service.

## Credentials

None: CO-OPS's Data and Metadata APIs take no key and no account. The API logs the calls under the
`application` name, and asks its callers to space their calls.

## Options

The factory's options, with the defaults it applies (`TidesSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `datum` | `string` | `MLLW` | no | the water levels' datum, upper-cased: `MLLW` (the charts'), `MSL`, `MHHW`, `MLW`, `NAVD`, `STND`... |
| `radius_km` | `float` | `50` | no | how far from a `Point` the nearest water level station is looked for, in kilometres |
| `application` | `string` | `omnilab` | no | the name the API logs the calls under, sent as `application` |
| `metadata_uri` | `string` | `https://api.tidesandcurrents.noaa.gov/mdapi/prod/webapi/` | no | the Metadata API, for a station (`stations/{id}.json`) and the list the nearest one is chosen from |
| `mailto` | `?string` | null | no | a contact address, sent in the User-Agent |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `0.5` | no | seconds left between two calls: the API asks its callers to space them |
| `base_uri` | `string` | `https://api.tidesandcurrents.noaa.gov/api/prod/datagetter` | no | the Data API (`datagetter`) |

## Capabilities

`capabilities()` answers `entity`, `series`, `forecast`, `historical`, `station`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | yes | a CO-OPS station (`coops:8518750`, `coops.station:`, its page, its Metadata API record); another scheme refused before any call; one call |
| `search` | no |  |
| `series` | yes | the variables of [Calls](#calls) at a `coops.station` or at the nearest water level station of a `Point` within `radius_km`; one call per variable and per slice of the API's length limit; no station near: an empty list |
| `products` | no |  |
| `occurrences` | no |  |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | no |  |
| `cross_references` | no | none: the SHEF code (the NWS's) is a property |
| `forecast` | yes | the astronomical predictions (`forecast` true, past and future alike) |
| `historical` | yes | the observed and verified water levels, the temperatures |
| `area` | no | a box is refused before any call |
| `station` | yes | `coops.station` |
| `cloud_cover` | no |  |

## Which API

CO-OPS's Data API (`https://api.tidesandcurrents.noaa.gov/api/prod/datagetter`): one call per
product and period, `units=metric`, `time_zone=gmt`, `format=json`, `application=omnilab`, the
datum for the water levels; and its Metadata API
(`https://api.tidesandcurrents.noaa.gov/mdapi/prod/webapi/`): `stations/{id}.json` for a station,
`stations.json?type=waterlevels` (302 stations on 2026-10-15, read once per source) for the
nearest one of a point. Errors come as `{"error": {"message": ...}}` with a 200 (a 400 for some);
"No data was found" (a product the station does not have, verified data not yet published) is a
series without values, any other message a `ProviderException`.

## Its shapes

A station is an **entity** of the core's `coops.station` scheme, and its records are **series**
(`Capability::STATION`); a `Point` answers its nearest water level station within `radius_km`; a
`BBox` is refused. The predictions are series with `forecast` true (astronomical predictions,
for the past as for the future).

## Calls

| Method | Reads | Calls |
|---|---|---|
| `entity()` | `coops:8518750`, `coops.station:8518750`, its page on tidesandcurrents.noaa.gov, its record in the Metadata API | `stations/{id}.json` |
| `series()` | the variables below; a `coops.station` or a `Point`; the period (today by default; a date alone is the whole day, in the query's time zone, UTC by default); a step and an aggregation | one call per variable and per slice of the API's length limit, half a second apart |

| Variable | Product (interval) | Unit | Per request |
|---|---|---|---|
| `water_level` | `water_level`: observed, six minutes, preliminary or verified | m above the datum | 31 days |
| `hourly_height` | `hourly_height`: verified, hourly | m | 365 days |
| `high_low` | `high_low`: verified highs and lows (irregular) | m | 365 days |
| `predictions` | `predictions` (`interval=6`) | m | 31 days |
| `hourly_predictions` | `predictions` (`interval=h`) | m | 365 days |
| `high_low_predictions` | `predictions` (`interval=hilo`, irregular) | m | 365 days |
| `water_temperature`, `air_temperature` | six minutes | °C | 31 days |

The regular series are put on the step's grid from the start of the period: without an
aggregation the value at each step's start (null when none), with one (`Series::MAX`, `MIN`,
`MEAN`, `SUM`) the step's aggregate; `PT6M` gives every six-minute value. The highs and lows come
as they are (`step` null). A period longer than a product's limit is asked in slices (75 days of
six-minute water levels: three calls). Another variable or aggregation, a box or another
scheme's station are refused before any call. An unknown station is `null` (404).

## What an entity holds

`id` (`coops.station:8518750`), `label` (`The Battery`), `domain` earth, `kind` `station`,
`place`, `url` (its station page), and `state`, `time_zone` (`EST (UTC-5)`), `tide_type`
(`Mixed`), `tidal`, `great_lakes`, `shef_code` (the NWS's code: `BATN6`), `affiliations`.

## The Collector

No path: the APIs give no id of another base. A CO-OPS station's SHEF code is the NWS's, kept as
a property, not an identifier of the `nws.station` scheme (whose stations are observation ones).

## What the APIs do not give here

The currents and their predictions, wind, air pressure, conductivity, salinity, visibility,
humidity (units the API leaves to "appropriate for the data"), one-minute water levels, daily and
monthly means, the datums, harmonic constituents, flood levels, benchmarks, the OFS model
forecasts, the high tide flooding outlooks, and the derived products API are not read.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Model\Point;
use Omnilab\Source\SeriesQuery;
use Omnilab\Tides\TidesSourceFactory;
use Symfony\Component\HttpClient\HttpClient;

$tides = (new TidesSourceFactory(HttpClient::create()))->create(['mailto' => 'you@example.org']);

$battery = $tides->entity('coops:8518750');
echo $battery->label, ' (', $battery->id, '), ', $battery->value('state'), ', ', $battery->value('tide_type'), ' tide, SHEF ', $battery->value('shef_code'), "\n";

$day = new DateTimeImmutable('2026-10-26');
[$level, $highLow] = $tides->series(new SeriesQuery(['water_level', 'high_low_predictions'], $battery->id, $day, $day));
printf("%s: %d water levels (%s to %s %s)\n", $level->label, count(array_filter($level->values(), 'is_numeric')), $level->min(), $level->max(), $level->unit);
foreach ($highLow as [$time, $value]) {
    echo '  predicted ', $time->format('H:i'), ' UTC ', $value, " m\n";
}

[$near] = $tides->series(new SeriesQuery(['hourly_predictions'], new Point(40.70, -74.02), new DateTimeImmutable('2026-10-28'), new DateTimeImmutable('2026-10-28')));
printf("nearest station of 40.70,-74.02: %s, %d hourly predictions, max %s m\n", $near->label, count($near), $near->max());
```

```
The Battery (coops.station:8518750), NY, Mixed tide, SHEF BATN6
The Battery (8518750), MLLW: 24 water levels (-0.043 to 1.79 m)
  predicted 00:13 UTC 1.563 m
  predicted 06:33 UTC -0.033 m
  predicted 12:27 UTC 1.823 m
  predicted 19:19 UTC -0.054 m
nearest station of 40.70,-74.02: The Battery (8518750), MLLW, 24 hourly predictions, max 1.837 m
```

(as answered on 2026-10-27, half a second between calls; the water level is the value at each
hour's start: `SeriesQuery::HOURLY` by default)

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        tides: { factory: tides, options: { mailto: '%env(OMNILAB_MAILTO)%', datum: MLLW, radius_km: 50 } }
```

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

CO-OPS's own: its errors come as `{"error": {"message": ...}}`, often with a 200: "No data was
found" (a product the station lacks, verified data not yet published) is a series without
values, any other message a `ProviderException` with it ("Range Limit Exceeded" never: the source
cuts the period into slices). Before any call (`NotSupportedException`): a variable not served, an
aggregation it does not make, a box, a station of another scheme. An unknown station is the
Metadata API's 404, null.

## Verified, and not

Against the real APIs on 2026-10-15: The Battery by its id (New York, a mixed tide) and an
unknown station (404, null); its water level, six-minute predictions, predicted highs and lows
(1.363, 0.228, 1.581, 0.208 m) and water temperature (18.4 to 18.8 °C) on 2026-10-14, hourly and
every six minutes (240 values); the daily maxima from 2026-08-01 to 10-14 in three requests (a
single one is the API's "Range Limit Exceeded ... 31 days"); the verified hourly heights and
highs and lows of 2025-01-01; the hourly predictions for 2026-10-16 at the nearest station of a
point at the tip of Manhattan; the hourly heights of 2026-10-14, not yet verified ("No data was
found": no values). The fixtures in `Tests/Fixtures/` are its answers, the stations' list cut to
five around New York.

Again on 2026-10-27: The Battery by its id, its water levels and predicted highs and lows of
2026-10-26, the hourly predictions of 2026-10-28 at the nearest station of a point (the example
above).

Not verified: the API's throttling (no refusal met at half a second between calls), the other
datums than MLLW and MSL's request shape, the Great Lakes' stations.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by NOAA or its Center for Operational Oceanographic Products and Services. CO-OPS's data
are in the U.S. public domain; credit NOAA CO-OPS (Tides and Currents); recent water levels are
preliminary, the predictions astronomical (no weather); space the calls and download only what is
needed, as the README's [Terms of use](https://github.com/glitchr-omnisense/omnilab-tides#terms-of-use)
says. Whoever runs the package is responsible for any use made of what it returns: see the README's
[Responsibility](https://github.com/glitchr-omnisense/omnilab-tides#responsibility) section.
