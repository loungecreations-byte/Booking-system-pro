# Release Gates - Verplicht voor productie

## Gate 1: Functionele acceptatie
- Kernroutes werken: /spots/, /activiteiten/, /plan-je-dag/.
- Kritieke userflows doorlopen: selecteren, plannen, boeken.
- Geen blocker defects open.

## Gate 2: Technische kwaliteit
- Geen javascript runtime errors op kernroutes.
- Geen failed requests op kritieke assets/API-calls.
- PHP lint en build checks geslaagd.

## Gate 3: Architectuur-compliance
- Wijziging past binnen goedgekeurde doelarchitectuur.
- Eventuele afwijking (dispensatie) gedocumenteerd en goedgekeurd.

## Gate 4: Operations readiness
- Rollback plan aanwezig en getest.
- Release window, owner en communicatie vastgelegd.
- Monitoring en checklijst na livegang bevestigd.

## Gate 5: Post-release validatie
- Smoke test binnen 30 minuten na livegang.
- KPI impact check binnen 24 uur.
- Incidentvrije status of escalatie geactiveerd.
