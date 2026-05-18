# Local Smoke Fixtures

These fixtures are local database data for browser smoke tests only. They are
not production products and must not be used as commercial catalog content.

## Request-only product

- ID: `2832`
- Title: `DDB Smoke Request Only Test`
- Slug: `ddb-smoke-request-only-test`
- URL: `/product/ddb-smoke-request-only-test/`
- Marker: `_local_test_fixture=1`
- Expected runtime: `REQUEST`, `route_intent=quote`, `reason_code=requires_confirmation`

Used for:
- product detail request/offerte CTA
- planner to quote/request flow
- mobile sticky request CTA

## Blocked availability product

- ID: `2833`
- Title: `DDB Smoke Blocked Availability Test`
- Slug: `ddb-smoke-blocked-availability-test`
- URL: `/product/ddb-smoke-blocked-availability-test/`
- Marker: `_local_test_fixture=1`
- Availability rule: `_sbdp_av_rules = array('default' => 'closed')`

Used for:
- availability/blocker smoke
- verifying direct checkout is not offered when runtime cannot safely allow it

Current semantics note: default-closed availability resolves as a request path
with `reason_code=time_unavailable` in the current runtime. Treat hard
`UNAVAILABLE/provider_closed` as a separate contract decision unless the
runtime already exposes that contract.

## Local quote alias

- ID: `2836`
- Title: `Offerte aanvraag`
- Slug: `offerte-aanvraag`
- URL: `/offerte-aanvraag/`
- Content: `[sbdp_offerte_aanvragen]`
- Marker: `_local_test_fixture=1`

This keeps existing hardcoded local smoke links to `/offerte-aanvraag/` from
404ing while the canonical planner quote URL remains `/offerte/`.

## Rollback

```powershell
wp post delete 2832 --force
wp post delete 2833 --force
wp post delete 2836 --force
```
