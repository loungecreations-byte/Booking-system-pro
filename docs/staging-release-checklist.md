# Staging & Release Checklist

## Branch Protection
1. Open **Settings ? Branches ? Branch protection rules**.
2. Add rules for `master` (and `main` if applicable):
   - Require status checks: **CI**, **PHPStan**, **Composer Audit**.
   - Require pull request reviews (minimaal 1) en dismiss stale reviews bij nieuwe commits.
   - Blokkeer force pushes en vereis up-to-date branch.
3. (Optioneel) Activeer *Require linear history* en *Restrict who can push*. Voeg release engineers toe als uitzondering.

## Staging Smoke
1. Deploy de release branch/tag naar staging.
2. Draai `bash scripts/run-staging-checks.sh` op de staging host (vereist WP-CLI en PowerShell 7):
   - Deactiveert/activeert plugin.
   - Seed demo data.
   - Rebuild yield cache en synchroniseert kanalen.
   - Voert analytics rapport uit en syntax sweep.
3. Documenteer output in de PR en zet communicatie klaar voor domeinexperts.

## Handmatige QA
- REST smoke (`pwsh -File scripts/rest-smoke.ps1 -BaseUrl ...`).
- Planner UI: drag & drop, compose_booking pay/request.
- Admin availability CRUD + preview.
- Pricing/yield quote en kanaalsync (REST + CLI).
- Promotions CRUD/activate en reviewflow.

## Release Workflow (`.github/workflows/release.yml`)
- Tag `vX.Y.Z` triggert:
  1. Composer install.
  2. Quality checks + PHPUnit.
  3. Bouw zip `dist/booking-pro-<tag>.zip`.
  4. Publiceer GitHub Release met artefact.
- Controleer de Release-pagina; voeg release notes, impact, tests en rollback toe.

## Nazorg
- Monitor Action Scheduler hooks (`bsp_sales/nightly_yield`, `bsp_sales/hourly_channel_sync`).
- Bewaak REST error rates en `bsp_price_log`/`bsp_channels` status.
- Plan 24u post-mortem: lessons learned, opvolgissues.