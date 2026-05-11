# DDB Spots 0.2.0

Released: 2026-02-27

## Added
- Sync observability:
  - New admin submenu: `Spots -> Sync Dashboard`
  - Central sync event logging for manual sync, batch import/refresh and cron runs
- Insights KPI funnel:
  - Views, CTA clicks, add-to-plan, book-click and derived rates (CTR/plan/book)
- REST ranking debug:
  - New endpoint: `GET /wp-json/ddb/v1/spots/{id}/ranking-debug`
  - Returns component-level weighted scoring breakdown
- Editor pre-publish validation:
  - AJAX precheck before publish intent in the spot editor
  - Shows critical failures and links focus back to relevant tabs/fields
- New sync lock fields:
  - `_ddb_lock_location`
  - `_ddb_lock_contact`
  - `_ddb_lock_hours`

## Changed
- Plugin version bump from `0.1.0` to `0.2.0`
- Listing/REST query caching added with versioned transient keys and shared invalidation path
- Cache invalidation wired into:
  - Spot save/delete
  - Spot taxonomy assignment changes
  - Google Places import/sync updates
- Legacy `ddb/v1` deprecation window configurable via Engine settings:
  - `integrations.legacy_rest_sunset_date`
  - Adds `Deprecation` + `Sunset` headers on legacy `/ddb/v1/spots`
  - Legacy REST is disabled by default (`integrations.legacy_rest_enabled = false`)
- REST security hardening:
  - Public `GET /dbspots/v1/spots` en `GET /dbspots/v1/spots/{id}` tonen alleen `publish` spots voor niet-editors
  - Public ingestion standaard uit (`integrations.public_ingest_enabled = false`)
  - `/events` en `/suggest` vereisen enabled ingest + `X-DDB-Ingest-Key` header
  - Query-param ingest keys verwijderd (geen `?ingest_key=...` meer)
  - Rate limiting fingerprint verscherpt en suggest-cache payload genormaliseerd tegen key-flooding
- Google Places sync now respects all lock groups:
  - title/excerpt/cta (existing)
  - location/contact/hours (new)
- Google Places import enrichment:
  - imports editorial summary, reviews JSON, place types, maps URL and quality signals
  - optional WP media sideload for place photos with warning logs on failures
  - import screen now shows first concrete error messages in batch notices
  - new Deep Import mode for city-wide multi-query crawl (3-page Google cap per query) with dedup on `place_id`

## Tests
- Expanded WP-CLI checks for:
  - Cron schedule/hook runtime
  - Ranking debug endpoint shape
  - Extended lock enforcement during sync
