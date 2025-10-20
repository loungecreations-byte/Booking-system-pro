# Planner Bundle Handshake

De bundlestroom koppelt de planner-UI met compose en ordermeta via nieuwe REST-hulproutes.

## REST-routes
- `GET https://site.local/wp-json/sbdp/v1/planner/bundles` — geeft beschikbare bundels terug (aanpasbaar via `sbdp/planner/default_bundles` of de actie `bsp/planner/register_bundle`).
- `POST https://site.local/wp-json/sbdp/v1/planner/bundles` — body `{ "bundle_id": "<id>" }`; antwoord bevat de compose payload en triggert `sbdp/planner/bundle/applied`.
- `POST https://site.local/wp-json/sbdp/v1/compose_booking` — bestaande compose endpoint; voer de payload in die de vorige stap oplevert.

## Voorbeeldpayload
```json
{
  "mode": "request",
  "bundle_id": "DEMO-123",
  "items": [],
  "meta": {
    "bundle_label": "Demo Bundle",
    "description": "Sample bundle used for smoke tests."
  }
}
```

## Sequence
1. `initBundles()` markeert de planner-root zodra de UI klaar is.
2. Gebruiker kiest een bundel → `window.applyBundle(bundleId)` cachet de keuze (localStorage) en vuurt `sbdp:bundle:apply` voor UI-listeners.
3. Front-end doet `POST /planner/bundles` met `bundle_id`; response bevat de compose payload.
4. FE stuurt payload naar `compose_booking` (pay of request flow).
5. REST-controller verrijkt ordermeta (`bundle_id`, `bundle_label`, extra `meta`) zodat checkout & fulfilment dezelfde context hebben.
6. `sbdp/planner/bundle/applied` kan logging, analytics of automatisering triggeren.

## Notities
- Standaardbundels staan in `PlannerBundleService::maybeSeedDefaultBundles()` en zijn per outlet aan te passen (filter `sbdp/planner/default_bundles`).
- Via `bsp/planner/register_bundle` kun je dynamisch bundles toevoegen (bijvoorbeeld vanuit een partnerconnector of mu-plugin).
- Audit en notificaties lopen via bestaande `NotificationCenter`; duplicates worden voorkomen door `BundleRegistry`.
