## Samenvatting
- [ ] Impact uitgelegd in max 3 bullets

## Tests
- [ ] `pwsh -File scripts/run-quality-checks.ps1`
- [ ] `php vendor/bin/phpunit --configuration tests/phpunit.xml.dist`
- [ ] `pwsh -File scripts/rest-smoke.ps1 -BaseUrl ...`
- [ ] Handmatige QA:
  - Planner drag & drop / compose_booking (pay & request)
  - Admin availability CRUD + preview
  - Pricing quote + kanaalsync (REST + CLI)
  - Promotions CRUD + reviewflow

## Uitrol
- [ ] Release-notes/rollbackplan toegevoegd
- [ ] Communicatie afgestemd met support/sales
- [ ] Monitoring scenario’s geüpdatet

## Checklist
- [ ] Code owners gereviewd
- [ ] Nieuwe migrations/Installer-wijzigingen gedocumenteerd
- [ ] Secrets/API keys buiten code gedeeld