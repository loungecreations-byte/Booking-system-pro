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

---

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