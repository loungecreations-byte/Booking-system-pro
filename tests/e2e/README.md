# Planner End-to-End Smoke Tests

Deze map bevat Playwright-voorbeelden om cruciale plannerflows geautomatiseerd te verifiëren.

## Setup

`
npm install
npx playwright install
`

1. Installeer Node.js 18+
2. `npm init -y`
3. `npm install --save-dev @playwright/test`
4. `npx playwright install`

## Uitvoeren
```
npx playwright test tests/e2e/planner-smoke.spec.ts --project=chromium --headed
```

### Configuratie
- Stel `BASE_URL` en `PLANNER_PATH` in via `.env` of testconfig (zie spec).
- Voor staging waar basic-auth actief is, voeg `auth.use` in Playwright config toe.

## Good Practices
- Run de smoke test in CI na staging deploy.
- Combineer met `scripts/run-staging-checks.sh` voor volledige regressie.
- Bewaar screenshots bij falende stappen (`--trace on`).
