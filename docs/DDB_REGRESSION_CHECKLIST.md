# DagjeDenBosch Regression Checklist

## Purpose
This checklist must be used after every significant implementation pass.

Rule:
No page family is done until regression is checked.

> **How this fits:** This checklist is the detailed check used during Review Loop steps 2, 5, and 6 (see `DDB_REVIEW_LOOP.md`).

---

## 1. Shell regression

### Check
- header renders at top
- main content is structurally in the middle
- footer renders at bottom
- no page bypasses the canonical shell
- no product/spot page mounts header or footer in content flow
- Elementor display conditions still correct

### Result
- Pass / Fail
- Notes:

---

## 2. Design system regression

### Check
- button family remains consistent
- card family remains consistent
- form/filter family remains consistent
- tabs remain consistent
- summary/CTA family remains consistent
- no new local component systems introduced
- no local CSS is acting as a second design system

### Result
- Pass / Fail
- Notes:

---

## 3. Page-family regression

### Overview Family
Check:
- compact intro
- clean filters
- cards scanable
- no giant hero behavior
- no second heavy interface
- CTA hierarchy correct

### Detail Family
Check:
- hero clear
- context strip useful
- practical info readable
- reviews usable
- combinations useful
- closing CTA clear

### Execution Family
Check:
- planner, cart, checkout feel related
- trust-first hierarchy
- no discovery clutter
- summary strong
- CTA logic correct

### Management Family
Check:
- account/portal aligned at surface level
- operational but not generic admin

### Experience Family
Check:
- tour progression clear
- no browse/admin clutter
- visually related to platform

### Result
- Pass / Fail
- Notes:

---

## 4. CTA regression

### Check
- each page has one clear primary CTA
- primary and secondary CTA do not compete equally
- overview pages favor inspect/view over execution
- detail pages favor add-to-day or booking correctly
- planner/cart/checkout favor execution correctly
- no generic “Plan je dag” misuse across all pages

### Result
- Pass / Fail
- Notes:

---

## 5. Dark mode regression

### Check
- surfaces remain dark and coherent
- accent use remains restrained
- cards and panels remain readable
- footer/header stay visually aligned
- no random light slabs inside dark mode without system logic

### Result
- Pass / Fail
- Notes:

---

## 6. Light mode regression

### Check
- surfaces remain truly light
- contrast remains strong
- same hierarchy as dark mode
- no dark-theme leftovers breaking light mode
- cards/forms/tabs remain aligned

### Result
- Pass / Fail
- Notes:

---

## 7. Mobile regression

### Check
- home is readable and routable
- overview pages are scanable
- cards are tappable
- filters are usable
- sidebars collapse correctly
- detail pages remain readable
- planner/cart/checkout remain usable
- tap targets are large enough
- no desktop-only layout assumptions break mobile

### Result
- Pass / Fail
- Notes:

---

## 8. Planner continuity regression

### Check
- add-to-day still works
- selected state still transfers correctly
- participant state remains correct
- planner summary remains correct
- no visual refactor broke planner continuity
- no visual refactor broke combi handoff

### Result
- Pass / Fail
- Notes:

---

## 9. Cart / checkout continuity regression

### Check
- cart summary still reflects correct Woo truth
- checkout still reflects correct Woo truth
- order flow not broken
- request flow not broken
- no visual alignment work broke execution flow

### Result
- Pass / Fail
- Notes:

---

## 10. OMDB / Woo boundary regression

### Check
- no UI pricing duplication introduced
- no UI availability duplication introduced
- no OMDB field meaning changed by rendering layer
- no Woo truth replaced with front-end assumptions

### Result
- Pass / Fail
- Notes:

---

## 11. Final decision

### Ready to merge / launch?
- Yes / No

### Blocking issues
- ...

### Warnings
- ...

### Safe next steps
- ...