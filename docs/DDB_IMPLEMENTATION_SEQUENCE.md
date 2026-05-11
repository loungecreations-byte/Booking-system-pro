# DagjeDenBosch Implementation Sequence

## Purpose
This document fixes the order in which the platform must be normalized.

Rule:
Do not optimize randomly.
Do not redesign page by page without sequence.
Do not polish before truth and primitives are stable.

---

## 1. Phase 0 — Freeze the truth ✅ DONE (2026-04-02)

### Goal
Define the truth before implementation starts.

### Required outputs
- `DDB_PLATFORM_CONSTITUTION.md` ✅
- `DDB_CTA_MAP.md` ✅
- `DDB_DO_NOT_TOUCH.md` ✅
- `DDB_PAGE_FAMILIES.md` ✅
- `DDB_COMPONENT_CANON.md` ✅
- `DDB_OMDB_WOO_BOUNDARIES.md` ✅
- `DDB_SHELL_RULES.md` ✅ (added)

### Do not
- start random UI fixes before these documents exist

---

## 2. Phase 1 — Shell stabilization ✅ DONE (2026-04-02)

### Goal
Enforce:
- header top
- main middle
- footer bottom

### Includes
- template audit
- Elementor conditions audit
- wrapper audit
- shell mount fixes

### Completed
- ddb-core-ui guard added for app routes (plan-je-dag, activiteiten, plattegrond)
- force-elementor-wrapper.php confirmed active
- ddb-header-global-fix.php confirmed active

### Why first
Because unstable shell breaks everything else.

---

## 3. Phase 2 — Shared primitive normalization ✅ DONE (2026-04-02)

### Goal
Normalize the true shared UI base.

### Includes
- surfaces
- spacing rhythm
- button family
- card family
- form/filter family
- tabs
- summary/CTA family
- dark/light mapping
- responsive primitives

### Completed
- 306 → 12 !important in ddb-core-ui/design-system.css
- 92 → 14 !important in day-planner-refresh.css
- PHP removed from sbdp-single-product-planner.css
- Dual :root conflict resolved (BPM design-system.css :root block removed)
- Dark theme token resync: BPM design-system.css now references --ddb-dark-* vars
- Form-kill CSS scoped to .single-product:has(#sbdp-booking-form)
- Button alias block added (ddb-add-to-plan, ddb-direct-book, ddb-listing-btn)
- 6× background:#fff tokenized to var(--ui-color-surface) in ddb-ui.css

### Why before page polish
Because page polish on fragmented primitives creates more drift.

---

## 4. Phase 3 — Overview Family alignment 🔄 CURRENT PHASE

### Includes
- Activities Overview
- Spots Overview

### Goal
Create one canonical overview family:
- compact intro
- clean filter bar
- scanable cards
- correct CTA hierarchy
- no heavy stacked multi-tool logic

### Active execution companion (2026-04-15)
- `12-unified-ui-parity-sprint-spec.md`
- this sprint spec enforces cross-family parity for typography, chips, cards, buttons, colorstyle, spacing, and site-width while phase 3 through phase 6 are being completed

---

## 5. Phase 4 — Detail Family alignment

### Includes
- Spot Detail
- Product Detail

### Goal
Create one canonical detail family:
- hero
- context strip
- practical info
- reviews
- combinations
- closing CTA

---

## 6. Phase 5 — Execution Family alignment

### Includes
- Planner
- Planning Cart
- Checkout

### Goal
Align execution surfaces visually and structurally without touching:
- OMDB meaning
- Woo price truth
- planner domain logic

### Focus
- trust
- clarity
- continuity
- summary hierarchy
- clean execution flow

---

## 7. Phase 6 — Management and Experience alignment

### Includes
- Account
- Portal
- Tour

### Goal
Bring them into the same family at surface level.

### Note
This phase may be lighter in the first launch sprint.
Deep operational/experience redesign can follow later.

---

## 8. Phase 7 — Regression and boundary review

### Must pass
- shell review
- design system truth review
- OMDB / Woo boundary review
- planner safety review
- mobile / regression QA review

### Why
No implementation is done until safety is confirmed.

---

## 9. Launch sequence

### Launch baseline
Must be visibly strong across:
1. Homepage
2. Activities Overview
3. Spots Overview
4. Spot Detail
5. Product Detail

### Launch execution alignment
Must be safe and visually coherent across:
6. Planner
7. Planning Cart
8. Checkout

### Launch surface alignment
Must not feel disconnected across:
9. Account
10. Tour

---

## 10. Sequence law

If work is done outside this order, the risk of design drift, shell drift, and business regression increases sharply.

This order is mandatory unless explicitly overridden.