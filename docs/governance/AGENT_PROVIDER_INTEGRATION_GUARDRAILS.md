# Agent Provider Integration Guardrails

Use these guardrails before any provider integration, supplier availability, booking, cancellation, or route-intent change.

## Required Pre-Implementation Report

Every provider integration task must first report:

1. Which truths are touched?
   - participants truth
   - availability truth
   - provider integration truth
   - price/Woo truth
   - request/direct routing
   - cancellation truth
2. What is the endpoint type?
   - schedule
   - availability
   - hold
   - booking
   - cancellation
   - webhook/status
3. Are canonical participants used?
4. Is WooCommerce price/order/payment/tax touched?
5. Can `directBookable` ever become `true`?
6. What is the fallback on API error, timeout, missing mapping, or unknown response?
7. Is there idempotency or duplicate-request protection?
8. Is cancellation/change documented and implemented?
9. Is commercial permission present?

## Default Policy

- Provider-specific API logic belongs in server-side adapters/services.
- Frontend and planner consume normalized DDB runtime decisions only.
- Schedule is never availability truth.
- Availability without hold routes to `REQUEST`, not `DIRECT`.
- Provider prices are never WooCommerce price truth.
- Direct booking requires proven hold, booking confirmation, idempotency, cancellation/change path, status reconciliation, and commercial permission.

## Eliio / Eropuitje Product 115

For DDB product `115` (`E-Chopper tour`):

- Eliio `GET /availability/widget` may be used as a server-side participant-sensitive availability pre-check.
- Eliio `available:true` means only that the slot appears available for exact `participants=N` at check time.
- Eliio `available:true` does not mean hold, booking, supplier confirmation, price confirmation, or direct checkout.
- Eliio `POST /booking-widget` is forbidden for direct checkout.
- `directBookable` must remain `false`.
- `supplierConfirmationRequired` must remain `true`.
- Route must remain `REQUEST` / quote unless a separate approved governance task changes it.
