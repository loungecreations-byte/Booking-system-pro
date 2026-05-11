---
name: ddb-platform-governor
description: Classify DagjeDenBosch work against platform constitution, page-family truth, CTA hierarchy, CSOT, OMDB/Woo boundaries, participants truth, availability truth, and provider integration truth before implementation.
---

# DDB Platform Governor

## Use when
Use this skill before:
- new pages
- large page refactors
- structural changes to planner/cart/checkout/account/offerte
- changes touching CTA hierarchy or shell structure
- changes to participants, availability, combo, request/direct routing, or provider integration

## Required reading
Read in order:
1. `AGENTS.md`
2. `docs/DDB_PLATFORM_CONSTITUTION.md`
3. `docs/DDB_CTA_MAP.md`
4. `docs/DDB_DO_NOT_TOUCH.md`
5. `docs/DDB_PAGE_FAMILIES.md`
6. `docs/DDB_COMPONENT_CANON.md`
7. `docs/DDB_OMDB_WOO_BOUNDARIES.md`
8. `docs/DDB_SHELL_RULES.md`
9. `docs/DDB_PARTICIPANTS_TRUTH.md`
10. `docs/DDB_AVAILABILITY_TRUTH.md`
11. `docs/DDB_PROVIDER_INTEGRATION_TRUTH.md`

## Governor sequence
1. Classify the page family or domain flow.
2. Determine the primary page role or domain role.
3. Determine the primary CTA or primary flow outcome.
4. Check whether the task violates platform constitution or do-not-touch rules.
5. Check whether it breaks CSOT or component canon.
6. Check whether it breaks OMDB/Woo/planner boundaries.
7. Check whether it breaks participants truth, availability truth, or provider capability separation.
8. Define what may change and what may not change.
9. Recommend the next skill.

## Mandatory participants truth check
Always verify:
- is there exactly one canonical participants source?
- is it `planner.form.participants`?
- do valid numeric inputs commit immediately?
- does blur only normalize?
- do add/select/timeline/summary/cart/checkout read the same truth?
- is there no fallback chain that can overwrite fresh input?

## Mandatory availability truth check
Always verify:
- is booking mode explicit?
- is participant policy explicit?
- is provider capability explicit?
- is runtime status resolved as `DIRECT`, `DIRECT_LIMITED`, `REQUEST`, or `UNAVAILABLE`?
- are request items prevented from direct checkout?
- do combos require all required components to be direct before becoming direct?

## Output format
Return exactly:
1. Context
2. Page family / flow class
3. Truth docs read
4. Constitutional violations
5. Boundary violations
6. Guardrails for implementation
8. Recommended next skill