# Booking Pro Module

Modernised WordPress plugin providing booking management, planner experience, and booking-board dashboards.

## Quick Start

### Prerequisites
- PHP 8.1+
- Node.js 20+
- WordPress 6.5+
- Composer & npm

### Installation

```bash
composer install
npm install
```

### Local Development

```bash
# start Vite dev server for booking board & planner assets
npm run dev

# run unit tests
composer test

# run quality checks (syntax + phpcs)
pwsh scripts/run-quality-checks.ps1
```

### WordPress Setup
Activate the plugin `booking-pro-module/booking-pro-module.php` within your local WordPress install.  
REST base namespace: `/wp-json/bsp/v1/…`

### Manual Verification
- Plannerkaart toont checklist + alleen de knoppen `Boek direct` en `Plan je dag`; het Boek direct-pad moet een WooCommerce-winkelwagen item opleveren.
- Op een boekbaar product staat de planner rechts, met een subtiele plannerkaart; Plan je dag opent de planner/prefill zonder verplicht tijdslot en Direct boeken zet een bestelling klaar zodra datum/tijd en deelnemers gevuld zijn.
- Controleer dat planner tijdsloten de geconfigureerde openingstijden gebruiken en dat de dagtimeline de statuskleuren (vrij, geboekt, conflict) laat zien.
- Draai `pwsh -File scripts/rest-smoke.ps1 -BaseUrl https://site.local/wp-json -QuoteProductId <id>` op een WordPress-installatie met de plugin actief om REST-paden te controleren.
- Verwijder of archiveer bestaand demo-materiaal (zoals de voormalige Jeroen Bosch tour) wanneer dit niet meer gewenst is.


## Key Modules

| Module | Summary |
| --- | --- |
| **Core** | Module registry, logging, bootstrap. |
| **Bookings** | Booking persistence, payment capture, REST endpoints. |
| **Planner** | Planner UI REST endpoints, schedule generation. |
| **Booking Board** | Admin dashboard + REST for bookings list/reschedule/export. |
| **Notifications** | Notification admin + REST hooks (Brain Monkey coverage). |

Legacy modules (Private Tours, Vendor Portal, Commerce stubs, etc.) are documented in `docs/modules-inventory.md`.

## Docs
- `docs/modules-inventory.md` - ownership & status of each module.
- `docs/testing-strategy.md` - roadmap for unit/integration/E2E coverage.
- `docs/booking-domain-rework.md` - upcoming persistence refactor.
- `docs/frontend-build-plan.md` - asset pipeline upgrades.
- `docs/module-enhancements-plan.md` - planner/booking board enhancements.

## Releases
- Use the scripts in `scripts/` (see `docs/frontend-build-plan.md`) to build assets and package releases.
- CI pipeline (planned) will run composer/npm tests prior to tagging.

## Legacy Access
Legacy API endpoints and modules remain available for compatibility but are being phased out. Private tours now default to the modern Elementor-driven module; define `SBDP_FORCE_LEGACY_PRIVATE_TOURS` or set the `sbdp_private_tours_mode` option to `legacy` if a site must retain the classic flow. Refer to documentation for additional migration paths.

## Planner REST Examples

```bash
# List available products for the planner UI
curl https://site.local/wp-json/booking/v1/products
```

```bash
# Validate a plan (requires a logged-in nonce, e.g. wp_create_nonce('wp_rest'))
curl -X POST https://site.local/wp-json/booking/v1/validate-plan \
  -H "X-WP-Nonce: ${WP_REST_NONCE}" \
  -H "Content-Type: application/json" \
  -d '{
    "date": "2025-11-01",
    "participants": 6,
    "items": [
      {"product_id": 12, "start": "10:00", "end": "11:30"},
      {"product_id": 31, "start": "12:00", "end": "14:00"}
    ]
  }'
```

```bash
# Persist a plan draft (returns plan_id + session_id tokens)
curl -X POST https://site.local/wp-json/booking/v1/plan \
  -H "X-WP-Nonce: ${WP_REST_NONCE}" \
  -H "Content-Type: application/json" \
  -d '{
    "session_id": "abc123",
    "date": "2025-11-01",
    "participants": 6,
    "items": [
      {"product_id": 12, "start": "10:00", "end": "11:30"}
    ]
  }'
```

```bash
# Submit a plan (server re-validates and stores final totals)
curl -X POST https://site.local/wp-json/booking/v1/submit \
  -H "X-WP-Nonce: ${WP_REST_NONCE}" \
  -H "Content-Type: application/json" \
  -d '{
    "plan_id": 42,
    "session_id": "abc123",
    "date": "2025-11-01",
    "participants": 6,
    "items": [
      {"product_id": 12, "start": "10:00", "end": "11:30"},
      {"product_id": 31, "start": "12:00", "end": "14:00"}
    ]
  }'
```

