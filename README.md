# omnilab/tides

NOAA's tides and water levels for [omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab), from
the Center for Operational Oceanographic Products and Services (CO-OPS, Tides and Currents): a
station's water level observed every six minutes, its verified hourly heights and highs and lows,
its tide predictions (every six minutes, hourly, or the highs and lows), its water and air
temperature - as series, at a station named or at the nearest one of a point -, and a station
itself. Through the CO-OPS Data and Metadata APIs, no key.

```php
$tides = (new TidesSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$battery = $tides->entity('coops:8518750');      // The Battery, NY: 40.700554,-74.01417, a mixed tide

[$level, $highLow] = $tides->series(new SeriesQuery(['water_level', 'high_low_predictions'],
    Identifier::of(Scheme::COOPS_STATION, '8518750'), new \DateTimeImmutable('2026-10-14'), new \DateTimeImmutable('2026-10-14')));
$level->values();                                 // 1.241 1.45 1.579 ... (m above MLLW, each hour's six-minute value)
$highLow->values();                               // 1.363 0.228 1.581 0.208 (the day's predicted highs and lows)

// A point: the nearest water level station within 50 km.
$tides->series(new SeriesQuery(['hourly_predictions'], new Point(40.70, -74.02), new \DateTimeImmutable('tomorrow'), new \DateTimeImmutable('tomorrow')));
```

```yaml
omnilab:
    sources:
        tides:
            factory: tides
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent
                datum: MLLW                    # the water levels' datum: MLLW, MSL, MHHW, NAVD, STND...
                radius_km: 50                  # how far from a Point the nearest station is looked for
```

See [docs/](docs/index.md) for what is read, how it maps, and what the APIs do not give here.

## Terms of use

CO-OPS's data are NOAA's, in the U.S. public domain; credit NOAA CO-OPS (Tides and Currents)
where you show them. Recent water levels are preliminary, not verified; the predictions are
astronomical, they do not include the weather. The API asks its callers to space their calls
under heavy load ("sleep statements" between successive calls) and to download only what they
need: the source waits half a second between two calls, sends `application=omnilab`, and keeps
each request within the API's length limits.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by NOAA's Center for Operational Oceanographic Products and Services (CO-OPS). It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
