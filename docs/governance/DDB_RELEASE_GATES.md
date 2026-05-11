# DagjeDenBosch Release Gates

## Purpose
This document defines the mandatory gates that must pass before a release may go live.

Rule:
No significant release may go live without gate review.

---

## Gate result scale

Each gate must be marked as one of:
- `pass`
- `pass_with_warnings`
- `fail`
- `not_applicable`

A release may only go live if:
- no critical gate fails
- any warnings are explicitly accepted
- no blocked condition remains open

---

## Gate 1 — Strategy / Constitution Gate

### Question
Does this change align with the platform constitution?

### Check
- page role still correct
- journey phase still correct
- CTA hierarchy still correct
- page family still correct
- component canon still respected

### Pass when
- the change strengthens or preserves platform truth

### Fail when
- the change introduces page-family drift
- the change violates canonical CTA logic
- the change conflicts with the constitution

---

## Gate 2 — Design System Truth Gate

### Question
Does this release use the design system as runtime truth?

### Check
- shared primitives used correctly
- no competing local component family introduced
- no Elementor custom CSS acting as system truth
- dark/light mapping still coherent
- component canon still respected

### Pass when
- the design system remains authoritative

### Fail when
- page-level or plugin-level design truth competes with shared system truth
- duplicate component families are introduced or expanded

---

## Gate 3 — Shell Integrity Gate

### Question
Is the canonical shell still structurally correct?

### Check
- header at top
- main in middle
- footer at bottom
- no detached plugin-page feeling
- no shell drift on key public pages
- Elementor conditions still correct

### Pass when
- shell is stable and consistent

### Fail when
- header/footer/main order is broken
- shell continuity breaks on key pages

---

## Gate 4 — Page Family Gate

### Question
Does the change preserve or improve the correct page-family behavior?

### Check
- Overview Family behaves like overview
- Detail Family behaves like detail
- Execution Family behaves like execution
- Management Family behaves like management
- Experience Family behaves like experience

### Pass when
- page-family behavior is clearer and more consistent

### Fail when
- overview pages become landing pages or planner pages
- detail pages become SEO dumps
- execution pages become discovery pages
- account/tour feel like disconnected products

---

## Gate 5 — OMDB Boundary Gate

### Question
Are OMDB semantics protected?

### Check
- no domain meaning changed
- no rendering layer redefines OMDB fields
- no field semantics casually changed
- no adapters were replaced with hidden reinterpretation

### Pass when
- OMDB meaning remains intact

### Fail when
- domain meaning is changed or blurred without explicit approval

---

## Gate 6 — Woo / Commerce Truth Gate

### Question
Is WooCommerce commercial truth still protected?

### Check
- no duplicated pricing logic in UI
- no duplicated VAT logic in UI
- no duplicated totals logic in UI
- cart/checkout/order truth preserved
- real-time booking truth preserved

### Pass when
- Woo remains the final commercial truth

### Fail when
- UI introduces independent pricing/booking logic

---

## Gate 7 — Planner Continuity Gate

### Question
Does the change preserve planner continuity?

### Check
- add-to-day works
- canonical participants truth remains correct without UI fallback heuristics
- planner handoff remains correct
- summary continuity remains correct
- combi continuity remains correct
- runtime route intent remains authoritative for checkout / quote / blocked entry
- provider capability separation remains intact for request-only vs direct-capable paths

### Pass when
- planner continuity is preserved

### Fail when
- UI changes break canonical participants, handoff, route intent, or summary behavior

---

## Gate 8 — Cart / Checkout Execution Gate

### Question
Is cart and checkout execution still safe?

### Check
- cart summary correct
- checkout flow correct
- request flow correct
- runtime route intent (`checkout`, `quote`, `blocked`) drives the correct entry path
- request-only items cannot leak into direct checkout
- no discovery clutter reintroduced
- trust-first structure preserved

### Pass when
- users can still move safely from plan to payment/request

### Fail when
- cart/checkout become functionally or structurally unsafe

---

## Gate 9 — Mobile / Responsive Gate

### Question
Is the change safe on mobile?

### Check
- layout remains readable
- cards remain tappable
- filters remain usable
- CTA hierarchy remains clear
- no desktop-only assumption breaks the flow

### Pass when
- mobile remains strong or improves

### Fail when
- mobile degrades materially

---

## Gate 10 — Dark / Light Integrity Gate

### Question
Are dark and light mode still coherent?

### Check
- surfaces correct
- contrast correct
- no local visual drift
- no mode-specific breakage
- hierarchy consistent across modes

### Pass when
- both modes remain coherent

### Fail when
- either mode becomes visually inconsistent or broken

---

## Gate 11 — Performance / Cleanliness Gate

### Question
Does the release improve or at least preserve speed and cleanliness?

### Check
- duplicate visual clutter reduced where expected
- no reckless asset bloat introduced
- legacy CSS influence reduced where safe
- no obvious performance regressions introduced

### Pass when
- performance impact is neutral or better
- cleanliness is improved

### Fail when
- the release adds obvious bloat or duplicated visual layers

---

## Gate 12 — Launch Readiness Gate

### Question
Is this actually safe to release?

### Check
- all mandatory gates reviewed
- blockers resolved or formally excepted
- owner assigned
- rollback path known
- launch board updated
- warnings acknowledged

### Pass when
- release is governed, reviewed, and safe enough to launch

### Fail when
- significant uncertainty remains
- blockers remain unresolved
- responsibility is unclear

---

## Mandatory gate bundle by release type

### Type A — public page refinement
Must pass:
- 1 Constitution
- 2 Design System Truth
- 3 Shell Integrity
- 4 Page Family
- 9 Mobile
- 10 Dark/Light
- 12 Launch Readiness

### Type B — planner/cart/checkout surface change
Must pass:
- 1 Constitution
- 2 Design System Truth
- 3 Shell Integrity
- 4 Page Family
- 6 Woo / Commerce Truth
- 7 Planner Continuity
- 8 Cart / Checkout Execution
- 9 Mobile
- 10 Dark/Light
- 12 Launch Readiness

### Type C — OMDB/Woo-sensitive change
Must pass:
- all relevant gates
- explicit human review
- no silent merge

---

## Final law

A release is not approved because the code is complete.
A release is approved only when the required gates have been checked and passed.
