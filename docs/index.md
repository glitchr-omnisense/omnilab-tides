# omnilab/tides

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

Not verified: the API's throttling (no refusal met at half a second between calls), the other
datums than MLLW and MSL's request shape, the Great Lakes' stations.
