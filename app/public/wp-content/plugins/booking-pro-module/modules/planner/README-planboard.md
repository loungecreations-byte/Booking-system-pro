# Planboard v2 API

Base namespace: `bsp/v2`

## Snapshot

`GET /wp-json/bsp/v2/planboard/snapshot?start=YYYY-MM-DDTHH:MM:SSZ&end=YYYY-MM-DDTHH:MM:SSZ&compress=1`

## Bookings

- `POST /wp-json/bsp/v2/planboard/bookings` (create)
- `POST /wp-json/bsp/v2/planboard/bookings/move`
- `POST /wp-json/bsp/v2/planboard/bookings/checkin`
- `POST /wp-json/bsp/v2/planboard/bookings/payment`

## Closures

- `GET /wp-json/bsp/v2/planboard/closures`
- `POST /wp-json/bsp/v2/planboard/closures`
- `GET /wp-json/bsp/v2/planboard/closures/{id}`
- `PUT /wp-json/bsp/v2/planboard/closures/{id}`
- `DELETE /wp-json/bsp/v2/planboard/closures/{id}`

## Products

- `GET /wp-json/bsp/v2/planboard/products?resource_id=123&search=city&limit=50`

## Pricing

- `POST /wp-json/bsp/v2/planboard/pricing/preview`

## Feature flag

Enable by defining `SBDP_PLANBOARD_V2` or filtering `bsp/planboard/v2_enabled`.

## Notes

- TODO:PROJECT_SPECIFIC - align closure storage with existing availability rules, if applicable.
- TODO:PROJECT_SPECIFIC - replace manual payment meta keys if there is an existing payments table.
