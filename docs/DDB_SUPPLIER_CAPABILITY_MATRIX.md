# DagjeDenBosch Supplier Capability Matrix

## Purpose

This matrix records supplier integration capability at governance level. It prevents availability pre-checks from being mistaken for direct booking capability.

## Capability Terms

- `schedule`: possible dates/times only; never availability truth.
- `availability`: runtime go/no-go signal for exact canonical participants.
- `hold`: capacity lock or reservation with expiry and server-side ownership.
- `booking`: supplier booking creation with documented response schema and idempotency.
- `cancellation/change`: documented supplier path for cancellation or changes.
- `webhook/status`: supplier status callback or documented manual status confirmation process.

## Current Matrix

| DDB product | Supplier | Schedule | Availability | Hold | Booking | Cancellation/change | Webhook/status | Direct bookable | Confirmation required | Route |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `115` E-Chopper tour | Eliio/Eropuitje | Possible via widget product schedule, candidate times only | `GET /availability/widget`, participant-sensitive pre-check only | Not proven | `POST /booking-widget` exists but forbidden for direct checkout | Not proven | Not proven | `false` | `true` | `REQUEST` / quote |

## Eliio / Eropuitje Notes For Product 115

Allowed:

- Server-side `GET /availability/widget`.
- Exact canonical `participants=N`.
- Normalized DDB response for customer guidance.

Not allowed:

- Frontend/planner direct Eliio calls.
- Direct checkout from Eliio `available:true`.
- `POST /booking-widget` for direct checkout.
- Provider price data as WooCommerce price truth.

Direct booking remains blocked until a separate approved task proves:

- response schema
- idempotency
- server-side price validation
- capacity lock/hold
- booking confirmation
- cancellation/change path
- webhook/status callback or manual confirmation process
- commercial permission
