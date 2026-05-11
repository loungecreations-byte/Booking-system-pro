---
name: ddb-audit
description: Audit DagjeDenBosch implementations against platform truth, CSOT, OMDB/Woo boundaries, participants truth, availability truth, provider capability logic, and booking-flow integrity before implementation.
---

# DDB Audit

## Use when
Use this skill when the task is to:
- audit an existing page or flow
- review a bugfix before merge
- inspect planner/cart/checkout behavior
- validate detail pages, spots, activiteiten, account, offerte, or booking flow
- review availability, participants, provider integration, or combo logic
- identify whether a problem is local, systemic, or architectural

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
12. relevant contract yaml files if touched by the task

## Audit sequence
1. Classify the task into the correct page family, domain flow, or architectural concern.
2. Identify which truths are touched.
3. Identify where the current implementation violates those truths.
4. Separate symptoms from root cause.
5. Classify each issue by severity.
6. Classify each issue by type.
7. Decide whether the smallest safe fix is local, systemic, or needs an explicit decision.
8. List exact files to inspect or change.
9. Define the required QA path.

## Mandatory checks
Always check:
- page-role purity
- CTA hierarchy
- CSOT compliance
- OMDB/Woo/planner boundary integrity
- participants truth integrity
- availability decision integrity
- provider capability separation
- checkout gate integrity
- combo direct/request logic where relevant

## Forbidden behavior
- Do not patch immediately.
- Do not recommend broad refactors without showing root cause.
- Do not treat provider absence as product-policy truth.
- Do not let planner logic silently replace execution truth.

## Output format
Return exactly:
1. Context
2. Classification
3. Truths touched
4. Violations found
5. Severity per violation
6. Fix policy per violation
7. Smallest safe fix
8. Exact files to inspect
9. QA checklist
10. Risks / do-not-touch areas

## Audit command guidance
When helpful, run targeted repo searches, builds, tests, and smoke checks.
Always prefer targeted checks over vague observations.