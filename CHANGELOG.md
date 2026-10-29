# Changelog

## [Unreleased]

### Fixed

- A Point with no water level station near is a series without points per variable, at the point,
  where it was an empty list (`SourceInterface::series()`); an unknown station is still an empty list.
