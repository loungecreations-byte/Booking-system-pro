# Planner Regression Checklist

Gebruik dit stappenplan bij staging en productie smoke-tests.

## Voorbereiding
- Plannerpagina geladen in browser (zonder cache).
- WooCommerce ingelogd account beschikbaar voor backend-controles.

## UI & Functionaliteit
1. **Datum & deelnemers**
   - Selecteer datum (vandaag +1) en wijzig deelnemers (1 ? 4); bevestig dat samenvatting update.
2. **Activiteit toevoegen**
   - Sleep of klik �Toevoegen� bij eerste activiteit.
   - Controleer dat item in samenvatting verschijnt met juiste tijden en prijs.
3. **Conflictbewaking**
   - Kies activiteit buiten beschikbaarheid ? planner toont conflictmelding (rode badge/toast).
4. **Pricing preview**
   - Hover/selecteer activiteit en bevestig dat prijsinformatie verschijnt (evt. in samenvatting).
5. **Compose - pay**
   - Klik �Boek & Betaal�; verwacht redirect naar checkout of succesmelding.
6. **Compose - request**
   - Klik �Doe aanvraag�; verwacht groene bevestiging.
7. **Share**
   - Klik �Deel programma�; controleer clipboard-notificatie.

## Backend Checks
- **Bookings dashboard**: aantal activiteiten/resources en boekingen vandaag tonen waarden > 0.
- **Availability UI**: kalender shell zichtbaar, info-notice en JS component laadt.

## REST Smoke
- Controleer `GET /wp-json/sbdp/v1/planner/bundles` (200, minimaal 1 bundle) en `POST /wp-json/sbdp/v1/planner/bundles` met een geldig `bundle_id` (payload bevat compose-data).
- Draai `pwsh -File scripts/rest-smoke.ps1 -BaseUrl https://<omgeving>/wp-json -QuoteProductId <id>` voor services/channels/quote.

## Fallback
- Test met blokkeringsnonce (lege planner ? HTTP 403) om security te valideren.

Documenteer resultaten in releasechecklist.
