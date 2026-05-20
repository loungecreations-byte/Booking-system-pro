# DDB Review Loop

> **Authority document** — Governance truth. Do not edit without an approved governance task.

## Purpose

Mandatory review chain before any merge or launch. No change may be merged to main without completing the applicable review steps.

## Review Levels

### Level 1 — Self review (all changes)

- [ ] Change touches only the stated scope
- [ ] No opportunistic refactors included
- [ ] No CSOT violations
- [ ] No OMDB/Woo boundary violations
- [ ] No `!important` or inline style drift introduced
- [ ] Relevant tests pass

### Level 2 — Booking truth review (if booking flow touched)

- [ ] Participants truth uses `sbdp_canonical_participants` — no fallback chain
- [ ] Request-only products cannot reach direct checkout
- [ ] Product 115 routes to `supplier_confirmation`, not `direct`
- [ ] `directBookable` is never `true` for product 115
- [ ] Provider API calls are server-side only

### Level 3 — Governance review (if authority docs, shell, or design tokens touched)

- [ ] Authority doc change is approved in governance task
- [ ] Shell marker change is reflected in `DDB_SHELL_RULES.md`
- [ ] Token change is reflected in the canonical tokens file
- [ ] `ddb-platform-governor` skill run result is attached

### Level 4 — Launch review (before go-live)

- [ ] All authority docs present (cockpit shows 0 FAILs)
- [ ] Shell status = PASS
- [ ] All regression checklist items checked
- [ ] `ddb-booking-flow-qa` skill run result is attached

## Blocking Rights

Any reviewer may block a merge by raising a NEEDS_DECISION flag. The flag must be resolved before the merge proceeds.
