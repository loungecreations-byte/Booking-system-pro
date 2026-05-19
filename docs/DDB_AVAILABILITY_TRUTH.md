# DagjeDenBosch Availability Truth

## 1. Context
This document defines canonical booking availability/capability truth and route intent semantics.

## 2. Classification
- Truth class: Booking capability and routing truth
- Owner: PlanService runtime
- Scope: Planner item capability, plan-level capability, CTA routing

## 3. Truths Touched
- Normalized capability status: `DIRECT`, `DIRECT_LIMITED`, `REQUEST`, `UNAVAILABLE`
- Route intent: `checkout`, `quote`, `blocked`
- Compatibility field: `legacy_status`

## 4. Violations
Forbidden patterns:
- UI heuristics deciding quote vs checkout (for example audience/count thresholds)
- Local item-count thresholds deciding checkout eligibility
- Planner inventing booking truth instead of consuming runtime decisions
- Availability truth duplicated in component-level guessed logic

## 5. Severity
- P1 if checkout/quote/blocked route can diverge from runtime capability
- P2 if only explanatory labels diverge while routing stays correct

## 6. Fix Policy
- Compute capability in runtime service layer
- Publish normalized status and route intent to consumers
- Keep legacy fields for compatibility only
- Ensure blocked intent disables checkout and quote entry points

## 7. Smallest Safe Fix
Current canonical strategy:
1. Resolve item capability profile in PlanService
2. Aggregate to plan capability profile
3. Map status to route intent (`checkout`, `quote`, `blocked`)
4. Drive UI CTA behavior from runtime intent only

## 8. Exact Files
- `app/public/wp-content/plugins/booking-pro-module/modules/day-planner/Service/PlanService.php`
- `app/public/wp-content/plugins/booking-pro-module/components/CartSummary.tsx`
- `app/public/wp-content/plugins/booking-pro-module/components/HomeOnboardingWidget.tsx`

## 9. QA Checklist
- Confirm plan payload includes normalized status and route intent
- Confirm cart summary CTA state is runtime-intent driven
- Confirm onboarding redirect uses runtime config and not heuristics
- Confirm blocked capability cannot leak into checkout flow

## 10. Risks / Do-Not-Touch Areas
- Do not replace route intent with local React heuristics
- Do not collapse normalized statuses back to legacy booleans
- Do not treat static metadata availability as final booking truth

---

## 11. Schedule, Availability, Hold, Booking, Cancellation

Availability governance separates five truths:

- Schedule truth: possible days, opening windows, durations, and start times. Schedule is never proof that a slot is available for a group.
- Availability truth: a runtime answer for a specific date/time/resource and exact canonical participants.
- Hold truth: a capacity lock or reservation with expiry and server-side ownership.
- Booking truth: confirmed supplier or Woo booking state after execution.
- Cancellation/change truth: documented ability to cancel or change a confirmed booking and reconcile status.

Runtime may use schedule data to discover candidate times only. Runtime may not use schedule data to claim availability, direct checkout eligibility, or booking success.

## 12. External Availability Semantics

External provider availability must always be participant-sensitive:

- Use canonical participants from runtime, not UI fallback chains.
- Query external availability with exact `participants=N`.
- Treat different participant counts as different availability checks.
- Cache only briefly and key cache entries by product, date, selected time/resource, provider IDs, and participants.

External `available:true` means only:

- The slot appears available for `participants=N` at the check moment.

External `available:true` does not mean:

- hold
- definitive booking
- price confirmation
- supplier confirmation
- direct checkout eligibility
- cancellation/change coverage

When a provider returns `available:true` without a proven hold and booking path, route to `REQUEST`, not `DIRECT`.

## 13. Eliio / Eropuitje Availability Rule

For DDB product `115` (`E-Chopper tour`):

- Eliio `GET /availability/widget` may be used server-side for participant-sensitive availability pre-checks.
- Eliio `GET /widget/product/schedule/{productId}` may only be used to discover possible times, never as availability truth.
- Eliio `POST /booking-widget` is forbidden for direct checkout unless a separate approved task proves the full direct-booking preconditions.
- API failure, timeout, missing mapping, or unknown status must fall back to request-only handling.

Product `115` remains:

- `directBookable=false`
- `supplierConfirmationRequired=true`
- route intent `REQUEST` / quote
