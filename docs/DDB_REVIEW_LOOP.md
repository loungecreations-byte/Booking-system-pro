# DagjeDenBosch Review Loop

## Purpose
This document defines the mandatory review loop for all significant UI, template, shell, or page-family changes.

Rule:
No major change is done until it passes the review loop.

> **What to check at each step:** use `DDB_REGRESSION_CHECKLIST.md` as the detailed checklist during steps 2, 5, and 6.

---

## 1. Review loop overview

The mandatory review sequence is:

1. Platform Governor
2. Design System Truth Agent
3. OMDB / Woo Boundary Agent
4. Planner Safety Agent
5. Mobile / Regression QA Agent
6. Final CSOT / OMDB Review Agent

Each step has a distinct purpose.
Do not skip steps.

---

## 2. Step 1 — Platform Governor

### Purpose
Check whether the work still aligns with:
- platform constitution
- page roles
- CTA map
- component canon
- do-not-touch rules

### Output
- aligned / not aligned
- governance notes
- blockers if the work violates core platform truth

### Block if
- page role is wrong
- CTA role is wrong
- component choice violates canon
- work touches do-not-touch zone without approval

---

## 3. Step 2 — Design System Truth Agent

### Purpose
Check whether the implementation actually uses the design system as runtime truth.
Use `11-unified-ui-master-agent.md` for mandatory parity checks on typography, chips, cards, buttons, spacing, colorstyle, and site-width.

### Output
- component drift report
- duplicate family report
- page-family drift report
- unified UI parity report
- pass / fail on design system truth

### Block if
- local visual truth overrides shared DS truth
- page/plugin invents its own component family
- Elementor custom CSS becomes system truth
- public page behaves like a design island

---

## 4. Step 3 — OMDB / Woo Boundary Agent

### Purpose
Check whether domain truth and execution truth remain protected.

### Output
- OMDB boundary report
- Woo boundary report
- pricing duplication report
- availability duplication report
- pass / fail

### Block if
- UI duplicates price logic
- UI duplicates availability logic
- OMDB meaning is changed by rendering layer
- Woo truth is reinterpreted in page-level code

---

## 5. Step 4 — Planner Safety Agent

### Purpose
Check whether planner continuity remains safe.

### Output
- add-to-day status
- planner continuity status
- participant state status
- summary continuity status
- pass / fail

### Block if
- add-to-day breaks
- planner handoff breaks
- combi continuity breaks
- execution continuity breaks

---

## 6. Step 5 — Mobile / Regression QA Agent

### Purpose
Check whether the result still works well and still feels coherent.

### Output
- shell pass/fail
- dark/light pass/fail
- mobile pass/fail
- CTA hierarchy pass/fail
- family consistency pass/fail
- issue list

### Block if
- mobile is clearly degraded
- shell breaks
- footer/header regress
- CTA hierarchy regresses
- family consistency is visibly broken

---

## 7. Step 6 — Final CSOT / OMDB Review Agent

### Purpose
Make the final decision:
- safe to merge
- safe to deploy
- blocked

### Output
- final pass/fail
- critical blockers
- warnings
- safe next steps

### Final merge law
No significant public-facing change is merged or launched until this step passes.

---

## 8. What counts as a significant change

The review loop is mandatory for:
- shell/template changes
- homepage changes
- overview-family changes
- detail-family changes
- planner/cart/checkout surface changes
- account/portal/tour family changes
- shared component changes
- token/theme-output changes
- dark/light changes
- CTA hierarchy changes

---

## 9. Review evidence sources

Reviewers should use:
- repository docs
- diff review
- screenshots
- runtime behavior
- mobile rendering
- shell output
- planner continuity checks
- pricing/booking continuity checks

---

## 10. Final law

A change is not done because it compiles.
A change is not done because it looks nicer.
A change is done only when:
- it respects the constitution
- it uses the design system as truth
- it preserves OMDB and Woo boundaries
- it preserves planner continuity
- it passes regression review