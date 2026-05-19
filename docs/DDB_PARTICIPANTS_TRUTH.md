# DagjeDenBosch Participants Truth

## 1. Context
This document defines the canonical participants truth for planner, cart, checkout, and order handoff.

## 2. Classification
- Truth class: Execution handoff truth
- Owner: booking-pro-module runtime
- Scope: Planner input, cart item meta, order item meta

## 3. Truths Touched
- Canonical participants key: `sbdp_canonical_participants`
- Legacy compatibility key: `sbdp_participants`
- Participants must be derived from explicit handoff sources, not UI fallback heuristics

## 4. Violations
Forbidden patterns:
- Fallback chains such as `item.participants || form.participants || 1`
- Quantity used as participant truth without explicit participants source
- Blur-only commit patterns for canonical participants
- UI-side participant inference overriding runtime canonical value

## 5. Severity
- P1 if participants truth can silently drift between planner, cart, and checkout
- P2 if only labels or non-canonical mirrors are inconsistent

## 6. Fix Policy
- Resolve participants through one canonical resolver in PHP runtime
- Persist canonical key across cart and order metadata
- Keep legacy mirrors only for compatibility, never as primary truth

## 7. Smallest Safe Fix
Current canonical strategy:
1. Resolve participants from explicit handoff sources only
2. Persist to `sbdp_canonical_participants`
3. Mirror to `sbdp_participants` and `sbdp_meta` for compatibility
4. Reject silent quantity fallback as canonical truth

## 8. Exact Files
- `app/public/wp-content/plugins/booking-pro-module/modules/product-page-refresh/Module.php`
- `app/public/wp-content/plugins/booking-pro-module/tests/e2e/planner-journey.spec.ts`

## 9. QA Checklist
- Confirm cart items contain `sbdp_canonical_participants`
- Confirm order item meta contains canonical participants mirror
- Confirm checkout metadata reflects planner-selected participants
- Confirm no quantity-only participant fallback path remains in canonical resolver

## 10. Risks / Do-Not-Touch Areas
- Do not remove legacy mirrors until all consumers are migrated
- Do not reintroduce client-side participant heuristics in React components
- Do not move canonical resolution to template/UI layer

---

## 11. Provider Availability Participants Rule

Provider availability must use canonical participants, not inferred participants.

Required:

- Resolve participants through the runtime canonical source before provider calls.
- Send exact `participants=N` to provider availability endpoints when participants are required.
- Treat `participants=1` and `participants=10` as separate availability checks.
- Include participants in transient/cache keys.
- Return validation errors for missing, zero, negative, or non-numeric participants in public availability endpoints.

Forbidden:

- Fallback chains such as `item.participants || form.participants || 1` for provider availability.
- Using Woo quantity as provider participants unless runtime explicitly resolved it as canonical participants.
- Calling provider availability without participants when the provider requires participants.
- Treating schedule output as valid for every participant count.

For Eliio/Eropuitje product `115`, `GET /availability/widget` must always receive the exact customer participant count.
