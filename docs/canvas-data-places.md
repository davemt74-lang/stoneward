# Public locations for Canvas Data

The city search uses all 32,363 records in the U.S. Census Bureau 2026 national Places Gazetteer. It includes the 50 states, the District of Columbia and Puerto Rico. The catalog includes incorporated municipalities and Census-designated places. The location is the representative point supplied by Census, rounded to four decimal places for weather lookup.

City IDs use the seven-digit Census place GEOID, including leading zeroes. Search supports state names and abbreviations. Results show the state, and duplicate names within a state retain their official legal designation or Census place code. Common names for a handful of consolidated municipalities map to the corresponding official place.

A published show ID uses its current public city and state. The picker labels venue weather as city-level because it uses the city's Census point. Missing, ambiguous, withdrawn and explicitly non-U.S. venues are unavailable. The lookup accepts city or venue text and sends only public location IDs and labels to the picker. [Canvas resource bindings](canvas-resources.md) also cover map geometry and optional device proximity to a separate exact point.

## Packaged reference data

The deployment ZIP includes `config/canvas-data-places/us-places-*.tsv`. The catalog needs no database, geocoding account or runtime Census request. Rebuild it with a downloaded copy of the official national Places archive.

```sh
python3 scripts/build-canvas-data-places.py /path/to/2026_Gaz_place_national.zip
```

The exact upstream ZIP used for this release has SHA-256 `af678e2d990827c89ee39b98c82de6e90b693c7361ff0e559ae3076670dd2863`. The files contain GEOID, state abbreviation, common place label, optional official name, latitude, longitude and an optional label for ambiguous results. [U.S. Census Bureau 2026 Gazetteer Files](https://www.census.gov/geographies/reference-files/time-series/geo/gazetteer-files.2026.html) · [Official national Places ZIP](https://www2.census.gov/geo/docs/maps-data/data/gazetteer/2026_Gazetteer/2026_Gaz_place_national.zip)

The map layer uses the 2025 Census state cartographic boundaries at 1:20,000,000 scale. It includes the 50 states, DC and Puerto Rico. The regional data keeps all polygon parts and the overview keeps the largest components within the canvas payload budget. Rebuild both files with `python3 scripts/build-canvas-maps.py /path/to/cb_2025_us_state_20m.zip`. The source ZIP has SHA-256 `efddd884f1442ef233b1ba9c12dddbd66b6fdf94da6a373e1556aefe3dbc5751`. [U.S. Census Cartographic Boundary Files](https://www.census.gov/geographies/mapping-files/time-series/geo/cartographic-boundary.2025.html)
