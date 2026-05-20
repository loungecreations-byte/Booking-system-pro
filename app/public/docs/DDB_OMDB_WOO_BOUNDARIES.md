# DDB OMDB / Woo Boundaries

> **Authority document** — Governance truth. Do not edit without an approved governance task.

## Purpose

OMDB owns semantic meaning. WooCommerce owns final commerce truth. These boundaries must not be crossed.

## OMDB Owns

- What a spot/experience IS (type, category, tags)
- Who it is for (audience, group size guidance)
- What it contains (description, media, location)
- Semantic booking context (duration, activity type)
- Availability meaning (what "available" means for this product type)

OMDB data is stored in `_omdb_*` post meta and OMDB taxonomies.

## Woo Owns

- **Price** — `_price`, `_regular_price`, `_sale_price` — never overridden by OMDB or planner
- **Tax** — WooCommerce tax classes and calculations — never bypassed
- **Order** — Order creation, line items, order status — owned by WooCommerce
- **Payment** — Payment gateway, payment intent, payment confirmation — owned by WooCommerce
- **Stock** — WooCommerce stock management (where applicable)

## Booking Truth Owns

- Whether a booking slot is available for given participants/date/time
- Route decision: direct checkout, request/quote, or supplier confirmation
- Canonical participants count

## Boundary Rules

1. Planner may READ Woo price for display; it may NOT set or recalculate it.
2. OMDB fields may inform UI display; they do NOT override Woo price at checkout.
3. Provider availability responses (e.g., Eliio `available:true`) are NOT booking confirmation.
4. `POST /booking-widget` to Eliio is FORBIDDEN for direct checkout.
5. Provider prices from supplier APIs are NOT WooCommerce price truth.
6. Quote line totals shown in the planner are estimates; Woo checkout total is truth.
