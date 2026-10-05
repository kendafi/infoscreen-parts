# Infoscreen parts

These are different parts of an infoscreen I built for an old tablet we have next to our front door at home.

Include the PHP files in your `index.php`.

## File layout

```
flag-days.php         Statutory Finnish flag days
sun.php               Today's sunrise/sunset via PHP date_sun_info()
warning-signal.php    Finnish monthly alarm-test indicator
weather.php           FMI HARMONIE point forecast
weather-warnings.php  FMI CAP warnings feed, Helsinki-area keyword filter
bus-stops.php         HSL/Digitransit departures
```

Most `.php` renderers are self-contained: they write their rendered HTML to a sibling `.html` file into the `html` folder and serves that file on subsequent requests until the cache is considered stale.

## Data sources and refresh schedule

| Column / element | Source | Cache TTL |
|---|---|---|
| Flag days | Hardcoded list (Finnish Flag Act) | n/a — deterministic |
| Sunrise / sunset | PHP `date_sun_info()` (local computation, no network) | once per calendar day |
| Warning-signal test day | Finds out using PHP if today is first non-holiday Monday of the month | 5 minutes |
| Weather | FMI Open Data WFS — HARMONIE point forecast | 5 minutes |
| Weather warnings | FMI CAP Atom feed (`alerts.fmi.fi`) | 30 minutes |
| Bus stops | Digitransit GraphQL (HSL) | 5 minutes |

In addition, add the index page itself to reload every 5 minutes via `<meta http-equiv="refresh">`.

If an upstream API returns nothing (e.g. brief outage), the renderer leaves the existing cache untouched and serves it as a stale fallback rather than overwriting it with blank HTML — so a momentary network blip won't blank the dashboard.

## Configuration

Most things are hardcoded near the top of the relevant file:

- **Weather location** — `FMI_LATLON` in `weather.php` (paste lat,lon from Google Maps).
- **Forecast length** — `FMI_FORECAST_HOURS` in `weather.php`.
- **Warning area keywords** — `$weather_warnings_area_keywords` in `weather-warnings.php`. Substring-matched (case-insensitive) against each CAP warning's `<areaDesc>`; tune for whichever region the dashboard is in.
- **Bus stops and lines** — `$bus_stops` in `bus-stops.php`. Keys are Digitransit GTFS IDs (e.g. `HSL:1301126`), *not* the human-readable codes (`H1392`) printed at the stop. Look the GTFS ID up via the Digitransit GraphQL API: `{ stops(name: "...") { gtfsId code name } }`.
- **Bus language** — `Accept-Language: sv` header in `bus-stops.php` (`fi`, `sv`, or `en`). Localizes stop names, headsigns and route names.
- **Cache TTLs** — `*_CACHE_TTL` constants near the top of each renderer.
- **Page refresh interval** — `<meta http-equiv="refresh" content="300">` in `index.php`.

## Requirements

- PHP with `allow_url_fopen=On` (uses `file_get_contents()` against URLs).
- The `simplexml` extension (for parsing the FMI WFS response).
- Write permission on the `html` folder for the web-server user (so `*.html` cache files can be created).
- Outbound HTTPS access from the web server to: `api.digitransit.fi`, `opendata.fmi.fi`, `alerts.fmi.fi`.
- A Digitransit subscription key — register at https://portal-api.digitransit.fi and paste it into `bus-stops.php` (`DIGITRANSIT_API_KEY_PRIMARY`).

## Credits / data licences

- **Weather data:** Finnish Meteorological Institute (FMI) — *Open Data*, licensed CC BY 4.0. Attribution to FMI is required.
- **Weather warnings:** FMI CAP Atom feed — CC BY 4.0. Attribution to FMI is required.
- **Bus data:** HSL via Digitransit — CC BY 4.0. Attribution to HSL/Digitransit is required.
