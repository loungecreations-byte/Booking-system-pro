# E-chopper variants / Eliio integration — initial guarded domain layer

## Status
This branch adds **only** a pure domain validation class. It is deliberately NOT connected to ProductForm, RestService, the WooCommerce cart, Mollie, quote generation, or Eliio.

Nothing becomes selectable, charged or booked as a result of this commit. This is not a staging deployment.

## Verified in this repository
- `booking-core/src/WooCommerce/Display/ProductForm.php` renders a legacy bookable_service frontend with date, time, participants and a combi selector.
- `assets/product-booking.js` calls `sbdp/v1/compose_booking` with only product/resource/start/end, plus participants.
- `booking-core/src/Rest/RestService.php` revalidates slots, calculates unit prices, and adds order/cart line metadata, but does not accept tour variant or upgrade selections.
- `booking-core/src/WooCommerce/Checkout/CheckoutService.php` persists an itinerary snapshot without variant/upgrades.
- This repository has not been shown to match deployed staging; **version check required**.

## Domain model
- Exactly one server-approved variant (two_hours, two_hours_city_quest, three_hours).
- City Quest is represented as a variant, not a timed activity.
- Independent, compatible upgrade codes; immutable snapshot shape with terms version and external mapping.
- Never accept browser-provided prices or supplier IDs as authorities.
- Fail closed when missing mapping, incomplete terms, or unsafe booking prerequisites.

## Next gated implementation
1. Confirm repo commit and active BSP release match staging, and inspect newer product planner/Quote OS code if different.
2. Obtain Eliio OpenAPI export and supplier-scoped API permissions; verify branch/venue, IDs, variants, options, price and availability.
3. Implement server-side policy repository and product/vendor configuration with explicit default-off flags and validation.
4. Implement end-to-end pricing, WooCommerce order lines, tax configuration, PDF/Quote OS, and itinerary persistence; run integration and regression tests.
5. Solve supplier-booking vs payment consistency with idempotency, reconciliation and refund/exception handling before any direct booking is enabled.
6. Only then enable the UI after complete end-to-end tests on staging; no live supplier writes.

## Strict NO-GO
No public sale of unconfirmed Flex/damage cover, no production Eliio booking write, no assumption that public widget IDs equal API catalog IDs.
