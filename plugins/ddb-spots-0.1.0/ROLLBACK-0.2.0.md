# Rollback Plan 0.2.0

## Scope
Rollback target: `DDB Spots 0.2.0` to previous stable `0.1.0`.

## Pre-check
1. Confirm current plugin directory: `wp-content/plugins/ddb-spots-0.1.0`
2. Export current option for safety:
   - `ddb_spots_engine_config`
3. Note active cron hook:
   - `ddb_spots_google_sync_event`

## Rollback Steps
1. Put site in maintenance mode (optional but recommended).
2. Deactivate `DDB Spots` plugin in WP admin.
3. Restore previous plugin code from backup/git commit.
4. Verify plugin header + constant version read `0.1.0`.
5. Reactivate plugin.
6. Flush rewrite rules:
   - Visit `Settings -> Permalinks` and save once
   - or run `wp rewrite flush`
7. Validate:
   - Spot edit screen opens
   - `/wp-json/ddb/v1/spots` returns 200
   - No PHP fatal in debug log

## Data Compatibility Notes
- New lock meta keys introduced in `0.2.0` are additive:
  - `_ddb_lock_location`
  - `_ddb_lock_contact`
  - `_ddb_lock_hours`
- Leaving these keys in DB is safe after rollback; old code ignores unknown meta.
- Sync log option `ddb_spots_sync_logs` is additive and can remain in place.

## Post-rollback Verification
1. Run:
   - `wp eval-file tests/wp-cli-runtime-check.php --path='<site-public-path>'`
2. Spot publish flow:
   - Ensure publish/draft behavior matches expected pre-0.2.0 baseline.
3. Cron:
   - Confirm one scheduled event for `ddb_spots_google_sync_event`.

