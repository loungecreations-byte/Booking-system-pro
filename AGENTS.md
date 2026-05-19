# DagjeDenBosch — AGENTS.md
- `DO_NOT_TOUCH`
- `NEEDS_DECISION`

---

## 8. Forbidden patterns

Always search for and flag:
- design truth defined outside the active design-system runtime
- scattered `!important` overrides used as structural truth
- inline style truth or page-local token drift
- planner-side total calculations
- planner inventing booking truth instead of consuming runtime decisions
- participants fallback chains such as `item.participants || form.participants || 1`
- blur-only commit for canonical participants
- request items entering direct checkout
- provider-specific API logic embedded directly in UI components
- combideals marked direct when required components are only request-capable
- schedule endpoints treated as availability truth
- external `available:true` treated as hold, booking confirmation, or direct checkout permission
- provider prices treated as WooCommerce price truth
- Eliio `POST /booking-widget` used for direct checkout
- `directBookable:true` for DDB product `115` without an approved governance task

---

## 8.1 Provider integration guardrails

Before any provider integration, supplier availability, direct/request routing, or cancellation/status work, report:

1. Which truths are touched: participants, availability, provider integration, price/Woo, request/direct routing, cancellation.
2. Endpoint type: schedule, availability, hold, booking, cancellation, webhook/status.
3. Whether canonical participants are used.
4. Whether Woo price/order/payment/tax is touched.
5. Whether `directBookable` can ever become `true`.
6. API-error fallback.
7. Idempotency or duplicate-request protection.
8. Cancellation/change path.
9. Commercial permission.

Provider integrations must go through server-side adapters/services. Frontend/planner must not call providers directly as booking truth.

For Eliio/Eropuitje product `115` (`E-Chopper tour`):
- `GET /availability/widget` may be used only for participant-sensitive server-side availability pre-checks.
- `available:true` means only that the slot appears available for exact `participants=N` at that moment.
- `POST /booking-widget` is forbidden for direct checkout.
- `directBookable=false`, `supplierConfirmationRequired=true`, route `REQUEST` / offerte.

Detailed guardrails live in `docs/governance/AGENT_PROVIDER_INTEGRATION_GUARDRAILS.md`.

## 9. Required skills

Use these skills when available:
- `ddb-audit`
- `ddb-platform-governor`
- `ddb-booking-flow-qa`

Recommended sequence:
1. `ddb-platform-governor`
2. `ddb-audit`
3. implementation pass
4. `ddb-booking-flow-qa`

---

## 10. Output format

Prefer structured outputs with:
1. Context
2. Classification
3. Truths touched
4. Violations
5. Severity
6. Fix policy
7. Smallest safe fix
8. Exact files
9. QA checklist
10. Risks / do-not-touch areas

---

## 11. If implementing

If the user explicitly asks to implement:
- implement the smallest safe fix only
- preserve CSOT
- preserve OMDB/Woo boundaries
- preserve participants truth
- preserve availability truth
- preserve provider capability separation
- avoid opportunistic refactors unless required to preserve truth
- run relevant validation/build/tests after changes
- summarize exactly what changed and what remains risky

## Build and validation

Use or replace the commands below with the real project commands.
Keep this block updated.

- Build planner: `[REPLACE_WITH_REAL_BUILD_COMMAND]`
- Run JS tests: `[REPLACE_WITH_REAL_JS_TEST_COMMAND]`
- Run PHP tests: `[REPLACE_WITH_REAL_PHP_TEST_COMMAND]`
- Run smoke flow checks: `[REPLACE_WITH_REAL_SMOKE_COMMAND]`

Done means:
- relevant build passes
- relevant tests pass
- no CSOT violations introduced
- no OMDB/Woo boundary violations introduced
- participants truth remains intact
- availability truth remains intact
- request items still cannot leak into direct checkout
- relevant booking-flow QA is completed for the touched flow
