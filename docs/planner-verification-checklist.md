# Planner Verification Checklist

Use deze checklist om drag-and-drop planner flows, pricing, beschikbaarheidssync en WooCommerce-cart integraties consistent te verifiëren vóór release of na ingrijpende wijzigingen.

## 1. Automatische checks
- `php composer.phar install --no-interaction --no-progress` – zorgt dat alle PHP/JS afhankelijkheden aanwezig zijn (triggered build bundels).
- `vendor\bin\phpunit.bat --configuration tests/phpunit.xml.dist` – voert unit/integratietests uit inclusief planner capture, payment dispatcher en booking manager scenario’s.
- `pwsh -File scripts/run-quality-checks.ps1 -NoPhpcs` – snelle syntaxiscontrole, interval-linting zonder PHPCS voor snelle feedback (voer zonder `-NoPhpcs` als volledige sweep nodig is).
- `pwsh -File scripts/rest-smoke.ps1 -BaseUrl https://site.local/wp-json -QuoteProductId <product_id>` – smoke-test REST API’s (planner bundels, bookings, pricing). Vervang `BaseUrl` door lokale/staging endpoint en gebruik een bestaand boekbaar product.
- `bash scripts/run-staging-checks.sh` (of via WSL) – end-to-end pipeline: plugin activatie, data seed, planner cache rebuild, channel sync en analytics sweep. Controleer eindlog op errors/warnings (specifiek availability rebuild en order sync).
- `npx playwright test tests/e2e/planner-smoke.spec.ts --config playwright.config.ts` – UI smoke voor compose/request flow (vereist draaiende WP site + `PLANNER_BASE_URL`). Controleer console voor JS errors.

## 2. Drag & Drop / Planner UI
- Open planner pagina (`/planner` of shortcode omgeving). Controleer dat datumselector, resource kolommen en timeline laden zonder JS errors.
- Sleep activiteit naar timeline, wijzig duur en resource: zorg dat stickers correct herpositioneren en availability waarschuwingen verschijnen bij conflicts.
- Verwijder activiteit (drag naar prullenbak of via context menu) en bevestig dat REST `DELETE` call 200 teruggeeft; planner lijst moet slot direct vrijgeven.
- Voer reschedule uit via Booking Board en controleer dat planner timeline synchroniseert (verstuur `POST /bsp/v1/booking-board/reschedule`, herlaad planner). Geen caching issues.
- Test multi-day/timeslot scenario’s: vul start/stop tijden, controleer dat timeline segment spanning toont en dat `duration_minutes` meeschuift in Booking Board.

## 3. Pricing & Rules
- Maak boeking via planner compose met verschillende deelnemers en upsells; verifieer prijsberekening tegen commerce rules (kortingen, toeslagen). Controleer `pricing_rules` in REST response.
- Gebruik coupons of last-minute regels indien geconfigureerd en bevestig dat `total` in booking record en WooCommerce order overeenkomen.
- Controleer valuta wissel indien meerdere valuta actief zijn (Booking Manager fallback naar `EUR`); API response moet correct currency label tonen in planner en board.

## 4. Payment Requests & WooCommerce
- Maak booking request (planner of REST `POST /bsp/v1/booking/request`). Response moet `status=captured` + `captured_at` + `payment_request.url` bevatten als WooCommerce/Mollie actief is.
- Open WooCommerce order: check meta `_sbdp_booking_id`, deelnemers (`_sbdp_participants`), order notes (eventuele planner opmerkingen).
- Gebruik Mollie betaallink flow (indien plugin actief) en controleer dat link in planner response naar Mollie URL wijst; fallback op WooCommerce `order-pay` als Mollie uitgeschakeld is.
- Rond betaling af via `POST /bsp/v1/booking/pay` of WooCommerce betaallink en verifieer dat Booking Board status `paid` toont en order status naar `processing` gaat.

## 5. WooCommerce Cart & Frontend
- Controleer productpagina van boekbare activiteit (`bookable_service`): voeg activiteit toe aan winkelmand via klassieke flow (niet planner). Cart moet datum/tijd/participants tonen en checkout zonder errors.
- Bezoek planner compose > kies “Boek & betaal” (indien beschikbaar) en bevestig dat redirect naar WooCommerce checkout met geselecteerde items werkt.
- Test meerdere activiteiten in winkelmand, update aantallen, verwijder items en voer checkout uit om persistente meta te verifiëren.

## 6. Beschikbaarheid & Sync
- Voer `wp eval "do_action('sbdp_seed_demo_data');"` om basisdata te resetten; controleer dat planner resources en availability direct matchen (geen lege timeline).
- Trigger channel sync via staging script (`bash scripts/run-staging-checks.sh`) of handmatig `wp eval "do_action('sbdp_sync_channels');"`; inspecteer logs voor foutmeldingen bij externe koppelingen (GetYourGuide, Viator, etc.).
- Controleer vendor availability overlays in Booking Board en Geo Dashboard filters (status created/requested/captured/paid).

## 7. Logging & Monitoring
- Inspecteer `logs/` directory na smoke runs. Focus op `booking`, `planner`, `woocommerce` entries; geen uncaught exceptions toegestaan.
- Verifieer dat hooks `sbdp/booking/captured`, `sbdp/booking/updated`, `sbdp/booking/rescheduled` worden gelogd (zie `CoreServiceProvider::logger()` output). Gebruik eventueel `wp eval` om custom listeners te triggeren.
- Controleer PHP error log en browser console tijdens drag/drop sessies; geen warnings of 500 responses.

## 8. Release Checklist
- Alle automatische checks groen (PHPUnit, rest-smoke, staging pipeline).
- Handmatige planner tests (drag/drop, pricing, payment, cart) uitgevoerd en gedocumenteerd.
- Screenshot of screen recording van planner compose, Booking Board status update en WooCommerce order.
- Release notes updaten (`CHANGELOG.md`) met highlights + eventuele hardwarerandvoorwaarden (tablets, kiosks). Link naar relevante REST traces of analytics rapporten.

Bewaar ingevulde checklist in projectnotities of release docs als bewijs van volledige QA-cycle.

