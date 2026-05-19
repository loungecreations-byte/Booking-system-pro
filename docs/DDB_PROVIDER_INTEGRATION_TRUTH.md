# DagjeDenBosch Provider Integration Truth

## 1. Context
This document defines provider capability separation and safe handoff rules between request-only and direct-capable booking paths.

## 2. Classification
- Truth class: Provider capability boundary truth
- Owner: Booking runtime and provider adapters
- Scope: Planner item capability, cart handoff, checkout entry

## 3. Truths Touched
- Provider capability must resolve to runtime normalized status
- Request-only items must never enter direct checkout as direct bookables
- Combi flows must respect weakest required component capability

## 4. Violations
Forbidden patterns:
- Provider-specific API logic embedded directly in UI components
- Request items entering direct checkout
- Combideals marked direct when required components are request-capable only
- UI inferring provider capability from labels instead of runtime status

## 5. Severity
- P1 if provider capability boundaries allow invalid checkout execution
- P2 if provider labels are inaccurate but execution remains guarded

## 6. Fix Policy
- Keep provider-specific integration logic in backend adapters/services
- Expose only normalized capability/intent to UI
- Enforce request-only separation at plan and cart handoff layers
- Preserve OMDB domain boundaries and Woo execution boundaries

## 7. Smallest Safe Fix
Current canonical strategy:
1. Runtime resolves provider/item capability to normalized status
2. Plan-level route intent is derived from aggregated capability
3. UI consumes route intent only, without provider API branching
4. Request-only capability routes to quote/request path and cannot auto-enter direct checkout

## 8. Exact Files
- `app/public/wp-content/plugins/booking-pro-module/modules/day-planner/Service/PlanService.php`
- `app/public/wp-content/plugins/booking-pro-module/components/CartSummary.tsx`
- `app/public/wp-content/plugins/booking-pro-module/components/HomeOnboardingWidget.tsx`

## 9. QA Checklist
- Confirm request-only items produce `quote` or `blocked` route intent
- Confirm mixed capability plans do not force invalid direct checkout
- Confirm UI has no provider-specific booking API branches
- Confirm combi arrangements honor request-only required components

## 10. Risks / Do-Not-Touch Areas
- Do not move provider branching into front-end components
- Do not bypass runtime route-intent guardrails for convenience links
- Do not weaken cart/checkout guards for request-only items

---

## 11. External Provider Endpoint Taxonomy

Provider endpoints must be classified before implementation:

- `schedule`: lists possible dates, opening windows, durations, or start times. Schedule is never availability truth.
- `availability`: answers whether a date/time/resource appears available for canonical participants at check time.
- `hold`: locks capacity or creates a reservation window with expiry, idempotency, and server-side ownership.
- `booking`: creates a supplier booking after commercial truth and availability/hold truth are already proven.
- `cancellation`: cancels or changes a supplier booking through a documented path.
- `webhook/status`: confirms supplier-side booking, payment, cancellation, or change status asynchronously.

Do not collapse these endpoint types. A product can have a public schedule endpoint and still be request-only. A product can have an availability endpoint and still be request-only when no hold, booking confirmation, cancellation path, or commercial approval exists.

## 12. Eliio / Eropuitje Rule For Product 115

DDB WooCommerce product `115` (`E-Chopper tour`) is mapped to Eliio/Eropuitje only for participant-sensitive availability pre-checks.

Allowed:

- Server-side calls to Eliio `GET /availability/widget`.
- Querying with exact canonical `participants=N`.
- Normalizing `available:true|false` into DDB availability status for customer guidance.

Not allowed:

- Frontend or planner calls directly to Eliio.
- `POST /booking-widget` for direct checkout.
- Supplier price data becoming Woo price truth.
- Marking product `115` as direct bookable.

For product `115`, until an explicitly approved governance task changes this:

- `directBookable=false`
- `supplierConfirmationRequired=true`
- route intent is `REQUEST` / quote, not `DIRECT` / checkout

## 13. Direct Booking Preconditions

No provider integration may set `directBookable:true` unless all of the following are proven and documented:

- Response schema for booking creation.
- Idempotency or duplicate-request protection.
- Server-side price validation that preserves WooCommerce price truth.
- Capacity lock, hold, or reservation semantics.
- Booking confirmation semantics.
- Cancellation/change path.
- Webhooks/status callbacks or a documented manual status confirmation process.
- Commercial permission to use the supplier endpoint for direct booking.

If any item is missing, the provider route must be `REQUEST` or `UNAVAILABLE`, never `DIRECT`.
