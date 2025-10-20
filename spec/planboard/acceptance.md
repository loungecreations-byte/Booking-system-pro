# Acceptatiecriteria  Planboard v1

## Live Board
- **AC1**: Initiele load ≤ 2s voor 7 dagen en ≤ 200 resources.
- **AC2**: Realtime update binnen 1s na event (BookingCreated/Updated/CapacityAdjusted/PriceUpdated).
- **AC3**: Overbook indicator zichtbaar met tooltip & oorzaak.

## Manage Bookings
- **AC4**: Zoekresultaat binnen 500ms voor 10k records (met index).
- **AC5**: Edit PATCH valideert rolrechten en business rules.
- **AC6**: Refunds loggen audit trail met actor, bedrag en tijdstempel.

## Drag & Drop
- **AC7**: DnD respecteert quota/blackouts; bij conflict duidelijke foutmelding.
- **AC8**: Commit maakt een atomair change-set; rollback herstelt volledig.
- **AC9**: Slepen tussen outlets alleen voor rollen manager/owner.

## Availability Ops
- **AC10**: Block toevoegen/verwijderen zichtbaar op board binnen 1s.
- **AC11**: Quota wijzigingen triggeren channel-sync (indien aan).

## Beveiliging
- **AC12**: Elke schrijf-call vereist WP nonce + capability check.
- **AC13**: Webhooks zijn gesigneerd + replay protection (timestamp + nonce).
