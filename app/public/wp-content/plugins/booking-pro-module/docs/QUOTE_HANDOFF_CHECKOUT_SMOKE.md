# Quote Handoff Checkout Smoke

## Purpose
Deterministic MVP smoke for:

`quote -> approved_version_id handoff -> Woo cart -> checkout -> place order -> Woo order created`

This script is the fallback proof for the final browser checkout leg until there is a dedicated authenticated Playwright admin fixture for quote acceptance and launch.

## Guardrails
- Do not edit pricing during the smoke.
- Do not bypass quote acceptance.
- Do not use any public order/payment mutation endpoint directly.
- Use Woo checkout with a local offline gateway such as `Bankoverschrijving` / `Direct bank transfer`.

## Preconditions
- Local site is running.
- Woo checkout is enabled.
- An offline Woo gateway is enabled.
- The quote line points to an existing purchasable Woo product.
- The quote has valid VAT config and valid totals.

## Deterministic Test Data
- Customer email: use a real local test inbox value.
- Quote line: one direct-capable Woo-backed product with fixed accepted amount.
- Currency: `EUR`
- Keep the accepted quote snapshot amount visible before handoff.

## Admin Steps
1. Open the BSP quote admin for the deterministic test quote, or create one from a request with one direct-capable Woo-backed line.
2. Verify the quote has a current version and the commercial line has a fixed amount.
3. If the quote is not yet `sent`, move it to `sent`.
4. Accept or admin-approve the quote so `approved_version_id` is pinned.
5. Confirm the accepted version amount and currency in the quote version snapshot.
6. Launch the quote handoff from the approved quote flow.
7. Confirm the handoff source is the pinned `approved_version_id`, not a later draft/current revision.

## Storefront Steps
1. After launch, open the returned Woo cart or checkout URL.
2. Verify the Woo cart contains the quote item and no unrelated request-only item.
3. Verify the cart line title matches the accepted quote line.
4. Verify the line amount matches the accepted quote snapshot.
5. Open checkout if the handoff landed on cart first.
6. Select `Bankoverschrijving` or the local offline fallback gateway.
7. Click `Plaats bestelling`.

## Expected Checkout Result
1. Woo creates an order.
2. The browser reaches either:
   - `order-received` / thank-you page, or
   - a valid Woo pay/order page when the offline gateway flow uses that route.
3. No BSP public payment/order mutation route is used for order placement.

## Woo Admin Verification
1. Open the newest Woo order.
2. Verify order total equals the accepted quote snapshot total.
3. Verify the order item meta contains:
   - `quote_id`
   - `quote_version_id`
4. Verify `quote_version_id` equals the quote `approved_version_id`.
5. Verify booking metadata is still present where expected:
   - `sbdp_participants`
   - `sbdp_start`
   - `sbdp_end`
   - `sbdp_pricing_source`
6. Verify there is no evidence that a request-only line entered direct checkout.

## Evidence To Record
- Quote id
- Approved version id
- Woo order id
- Checkout URL reached after submit
- Accepted quote total
- Woo order total
- Screenshot of Woo order item meta showing `quote_id` and `quote_version_id`

## Pass Criteria
- Quote handoff reaches Woo cart/checkout.
- Woo order is created.
- Woo total matches the accepted quote snapshot.
- Woo order item meta contains `quote_id` and `quote_version_id`.
- `quote_version_id` equals `approved_version_id`.
- No security boundary is bypassed.

## Current Known Gap
There is not yet a dedicated authenticated Playwright fixture that can deterministically create or accept a quote in admin and then drive the final Woo checkout submission in one browser test.
