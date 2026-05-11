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
