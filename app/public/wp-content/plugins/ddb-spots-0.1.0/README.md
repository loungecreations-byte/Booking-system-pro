# DDB Spots Engine (CityOS Conversion Layer)

`ddb_spot` plugin voor DagjeDenBosch.nl met:
- Canonical custom data tables (`wp_dbspots_spots`, `wp_dbspots_events`, `wp_dbspots_audit`)
- Versioned migrator (`dbspots_schema_version`) + dbDelta bootstrap
- Conversion-first editor + Spot Health publish gates
- Google Places import/sync met canonical upsert flow
- Event tracking + Insights dashboard
- API-first control layer (`/wp-json/dbspots/v1/*`)
- RBAC: `admin`, `editor`, `ddb_spots_analyst`

## 1) Architectuur

Belangrijkste modules:
- `includes/Core/DbTables.php`
- `includes/Core/Migrator.php`
- `includes/Core/Installer.php`
- `includes/Domain/SpotRepository.php`
- `includes/Domain/EventRepository.php`
- `includes/Domain/AuditRepository.php`
- `includes/Services/CanonicalSync.php`
- `includes/Services/SuggestService.php`
- `includes/Services/RateLimiter.php`
- `includes/Rest/Api.php`
- `includes/Admin/SettingsPage.php`
- `includes/Admin/EditorTabs.php`
- `includes/Admin/SpotHealth.php`
- `includes/Admin/InsightsPage.php`
- `includes/Integrations/GooglePlaces.php`
- `includes/Cron/Sync.php`
- `includes/Frontend/Render.php`
- `includes/class-ddb-spots.php` (CPT/tax/meta/REST)

## 2) Engine settings

Menu:
- `Spots -> Settings (Engine)`

Settings worden opgeslagen in:
- option key: `ddb_spots_engine_config`

Tabs:
1. Spot Types
2. Booking & Monetization
3. Ranking & Visibility
4. Data Sources
5. UX Rules (Data Quality)
6. Integrations

## 3) Spot editor UX

Alleen voor `post_type=ddb_spot`.

Tabs:
1. Basis
2. Content
3. Booking
4. Location
5. Media
6. SEO
7. Advanced

Kenmerken:
- Type/provider gebaseerde veldweergave
- Keyboard nav (Left/Right/Home/End)
- Laatste tab per gebruiker via localStorage
- RankMath metabox verplaatst naar SEO-tab indien aanwezig
- Ruis-metaboxes verborgen (bookmark/owner/legacy-boxes)

## 4) Spot Health

Sidebar metabox (bovenaan):
- quality score `0-100`
- checks voor type, hero image, location, booking, excerpt, gallery, opening hours, source, sync timestamp, max tags/categories
- `Fix` deep links naar tab + veldfocus
- `Sync now` knop voor Google spots

Thresholds komen uit `ddb_spots_engine_config`:
- `ux_rules.min_gallery_count`
- `ux_rules.min_excerpt_length`
- `ux_rules.hero_image_required`
- `ux_rules.max_tags`
- `ux_rules.max_categories`

## 5) Booking rules

Belangrijkste booking meta:
- `_ddb_booking_provider` (`none|formitable|external|ticket`)
- `_ddb_formitable_venue_id`
- `_ddb_formitable_embed`
- `_ddb_cta_url`
- `_ddb_group_max`

Gedrag:
- scripts/iframes worden bij opslaan uit `post_content` verwijderd
- formitable widget rendert alleen op single `ddb_spot` (restaurant + provider `formitable` + embed/venue data)

## 6) Google Places import

Menu:
- `Spots -> Import (Google Places)`

Features:
- query + optionele location/radius
- resultaten met: naam, adres, rating, place_id
- import selected / sync now
- deep import mode (multi-query city crawl, dedup op place_id, bulk import)
- autosync toggle per geselecteerde rij
- upsert op `_ddb_google_place_id`
- optionele verrijking bij import:
  - editorial summary (excerpt fallback)
  - reviews JSON (gesanitized)
  - rating/price/business status
  - optionele photo sideload naar WP Media (featured + gallery)

Data Sources tab in Engine settings bevat:
- Google API key
- default query/city/radius
- deep import queries/radius/max places
- sync frequency (`daily` of `every_3_days`)
- import toggles voor summary/reviews/quality/media

## 7) Google/locatie meta keys

Google:
- `_ddb_google_place_id`
- `_ddb_google_last_synced_at`
- `_ddb_google_opening_hours_json`
- `_ddb_google_phone`
- `_ddb_google_website`
- `_ddb_google_photo_refs_json`
- `_ddb_google_attribution_json`
- `_ddb_google_autosync`

Locatie:
- `_ddb_address`
- `_ddb_city`
- `_ddb_region`
- `_ddb_country`
- `_ddb_lat`
- `_ddb_lng`

## 8) Cron sync

Hook:
- `ddb_spots_google_sync_event`

Service:
- `includes/Cron/Sync.php`

Flow:
- schedule volgt `data_sources.sync_frequency`
- synct spots met:
  - `_ddb_source = google_places`
  - `_ddb_google_autosync = 1`
- handmatige sync via `admin-post.php?action=ddb_spots_sync_now&post_id=...` + nonce

## 9) REST

Legacy endpoint:
- `GET /wp-json/ddb/v1/spots`

CityOS endpoints:
- `GET /wp-json/dbspots/v1/spots`
- `GET /wp-json/dbspots/v1/spots/{id}`
- `POST /wp-json/dbspots/v1/events`
- `POST /wp-json/dbspots/v1/suggest`
- `POST /wp-json/dbspots/v1/spots` (admin/editor)
- `PUT /wp-json/dbspots/v1/spots/{id}` (admin/editor)
- `POST /wp-json/dbspots/v1/publish` (admin/editor)
- `POST /wp-json/dbspots/v1/archive` (admin/editor)

Security behavior:
- Legacy `ddb/v1` endpoints are disabled by default.
- Public reads return only `publish` spots unless user can edit spots.
- Public ingestion is disabled by default (`integrations.public_ingest_enabled = false`).
- If ingestion is enabled, `/events` and `/suggest` require `X-DDB-Ingest-Key` header.
- `ingest_key` query parameters are not supported.

Filter support:
- `type`, `area`, `tag`, `category` (comma-separated)
- `per_page`, `page`

## 10) Smoke checks

```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
node --check assets/js/ddb-spots-admin.js
node --check assets/js/ddb-spots.js
wp eval-file tests/wp-cli-smoke.php --path "C:\Users\Gebruiker\Local Sites\dagjedenboschnl\app\public"
```

Runtime pages (handmatig in admin):
1. Spot editor (`ddb_spot`) opent zonder fatal
2. `Settings (Engine)` opent en slaat op
3. `Import (Google Places)` toont vriendelijke fout zonder API key
4. Met API key: zoek + import + update bestaande place_id

## 11) Troubleshooting

- Geen importresultaten:
  - query aanpassen, radius vergroten, API key controleren
- `REQUEST_DENIED` / quota fouten:
  - controleer Places API enablement + billing in Google Cloud
- Autosync lijkt stil:
  - controleer `_ddb_source`, `_ddb_google_autosync`, cron activiteit
- Canonical tabel lijkt leeg:
  - open een adminpagina; backfill draait in batches op `admin_init`
- Widget ontbreekt frontend:
  - controleer type=restaurant, provider=formitable, venue/embed aanwezig
