# RoamWise

Trip planner: destination discovery, a deterministic editable itinerary planner, Leaflet map, budget tracker, weather, read-only sharing and printable export.
Laravel 12 · Inertia + Vue 3 + TypeScript · Tailwind 3 · MySQL (SQLite for tests) · Leaflet/OpenStreetMap.

## Runtime
PHP 8.3 (composer requires ^8.2), Composer 2, Node 22 / npm 10, MySQL 8 or MariaDB 10.6+. Extensions: pdo_mysql, mbstring, xml, ctype, json.

## Local setup
```bash
composer install
npm install
cp .env.example .env && php artisan key:generate
# MySQL: CREATE DATABASE roamwise CHARACTER SET utf8mb4; create a user; set DB_* in .env
php artisan migrate --seed      # expense categories + curated catalog (6 cities, 79 places)
npm run dev                     # or: npm run build
php artisan serve
```
Without MySQL: `DB_CONNECTION=sqlite` and `touch database/database.sqlite`. Password-reset mail uses `MAIL_MAILER` (default `log`).

## Tests and checks
```bash
php artisan test        # PHPUnit, in-memory SQLite, external HTTP faked
vendor/bin/pint --test
npm run test            # vitest
npm run types           # vue-tsc
npm run lint:check
npm run build
```

## Works without credentials
Auth, destinations, wizard, planner, editor, map, budget, share, export. Optional: Open-Meteo weather/geocoding (no key; `none` disables), OSRM routes (`ROUTING_OSRM_URL`), AI overview (`AI_PROVIDER`).

## Architecture
- `app/Services/Planning/TripPlanner`: pure deterministic planner (interest selection, dedupe, geographic grouping, pace, route order, optional verified opening hours). `ItineraryService` persists/edits; user-touched stops are locked and survive regeneration.
- `Weather/WeatherService`, `Destinations/GeocodingService`, `Routing/RouteService`, `Ai/*Narrator`: adapters with timeouts, caching, response validation, graceful failure. AI only writes a text overview; it never changes stops.
- `Trips/BudgetService`, `Support/Money`: integer minor units; foreign currency only with a user-entered rate.
- Tables: users, data_sources, destinations, points_of_interest, trips, trip_preferences, itinerary_days, itinerary_stops, saved_destinations, saved_places, expense_categories, expenses, trip_shares.

## Routes (CSRF-protected; Inertia unless noted)
Public: `/`, `/destinations`, `/destinations/{slug}`, `/s/{token}`. Authenticated: `/trips` (CRUD), `/trips/{id}/regenerate|summary|export|stops|expenses|shares`, `GET /trips/{id}/weather` (JSON), `GET /trips/{id}/days/{day}/route` (JSON), `/stops/{id}` (patch/delete/move), `/expenses/{id}`, `/shares/{id}`, `/lookup/destinations` (JSON), `/saved`. Non-owners get 404.

## Data and attribution
- Tiles © OpenStreetMap contributors (ODbL). The default tile server policy forbids heavy use; set `MAP_TILE_URL` to a licensed provider in production.
- Weather/geocoding: Open-Meteo.com (CC BY 4.0, non-commercial free tier), GeoNames data.
- Catalog: `database/data/catalog.json` (`tools/build_catalog.py`, `tools/verify_catalog.py`). 72 of 79 coordinates matched OpenStreetMap Nominatim within 400 m and are flagged verified; 7 are shown as "approximate". No opening hours, prices or photos are stored; imagery is generated colour fields (add licensed photos via `image_url`/`image_attribution`).

## Security
Policy-based ownership (404), no mass-assigned `user_id`, validated input, share tokens (48 random chars, hashed + encrypted, revocable, noindex), rate limits on planning/lookup/weather/routes/AI/share views, popups built with textContent, http(s)-only links, validated provider payloads, secret-free logs.

## Production deployment
```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env    # APP_ENV=production APP_DEBUG=false APP_URL, DB_*, MAIL_*
php artisan key:generate
php artisan migrate --force && php artisan db:seed --force   # seeder is idempotent
php artisan config:cache && php artisan route:cache && php artisan view:cache
```
Serve `public/` through nginx/Apache + php-fpm over HTTPS; keep `storage` and `bootstrap/cache` writable; configure a real mailer.

## Troubleshooting
- "Vite manifest not found": `npm run build`.
- Blank tiles: tile host blocked; markers and lists still work.
- Weather empty: forecasts cover 16 days; later dates show last year's observed data only.
- Seeder "Invalid coordinates": catalog.json edited wrongly; the seed rolls back.

## Known limitations
No multi-editor collaboration (deferred). No live prices, hotel/flight data or currency rates. Reordering uses buttons/selects, not drag-and-drop. Accessibility needs are stored, not used for filtering. Travel times are straight-line estimates. PDF = browser print. Only 6 curated cities; looked-up cities have no stored places. Migrations verified on SQLite only (no MySQL in the build sandbox).
