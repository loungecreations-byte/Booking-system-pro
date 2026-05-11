# DagjeDenBosch Launch Board

## Purpose
This document tracks launch readiness for the platform.

Rule:
A page or family is not launch-ready because it “looks better”.
It is only launch-ready when:
- page role is clear
- CTA hierarchy is correct
- shell is stable
- design system truth is respected
- OMDB semantics are preserved
- Woo execution truth is preserved
- regressions are checked

---

## Status legend

- `not_started`
- `in_progress`
- `review`
- `ready`
- `blocked`

---

## Implementation phase status (updated 2026-04-08)

| Phase | Status |
|---|---|
| Phase 0 — Freeze truth (docs) | ✅ Done |
| Phase 1 — Shell stabilization | ✅ Done |
| Phase 2 — Primitive normalization | ✅ Done |
| Phase 3 — Overview Family | 🔄 Current |
| Phase 4 — Detail Family | Not started |
| Phase 5 — Execution Family | Not started |
| Phase 6 — Management + Experience | Not started |
| Phase 7 — Regression + boundary review | Not started |

### Changes since 2026-04-02 snapshot
- ✅ `ddb-browser-bar--experience` stripped from HTML output via output buffer regex in `ddb-core-design-system.php`
- ✅ Combi deals restored in `sbdp-single-product-planner.php` (reads `_sbdp_combi_deals` meta directly)
- ✅ `[sbdp_product_planner]` shortcode gate fixed — resolves `product_id` from atts before gate check
- ✅ Typography canon conflict resolved — Quattrocento (Serif) + Quattrocento Sans is now the single canonical decision across all agent docs
- ✅ `DDB_DO_NOT_TOUCH.md` CSOT reference corrected to `ddb-core-ui/core-ui.php`
- ✅ Three duplicate governance files archived to `docs/governance/archive/`
- ✅ `AGENTS.md` carries derived-document disclaimer
- ⏳ CSS typography migration (Inter/Manrope → Quattrocento) — Codex prompt ready at `docs/codex-prompts/typography-migration-quattrocento.md`

---

## 1. Public launch baseline

### 1.1 Homepage
- Status: `in_progress`
- Family: Overview / Entry
- Primary phase: Ontdek + start Plan
- Owner: TBD
- Primary CTA: Start met plannen
- Blockers:
  - hero must be strong and direct
  - homepage must route clearly into discover vs plan
  - no editorial clutter
- Ready when:
  - strong hero
  - clear routing
  - clean CTA hierarchy
  - shell stable
  - mobile good

### 1.2 Activities Overview
- Status: `in_progress`
- Family: Overview
- Primary phase: Ontdek -> Plan
- Owner: TBD
- Primary CTA: Bekijk activiteit
- Blockers:
  - top still too landing-page-like
  - cards too dense
  - overview role must stay clear
- Ready when:
  - compact intro
  - clean filter bar
  - scanable cards
  - no heavy sidebar logic
  - add-to-day preserved

### 1.3 Spots Overview
- Status: `in_progress`
- Family: Overview
- Primary phase: Ontdek
- Owner: TBD
- Primary CTA: Bekijk plek
- Blockers:
  - filter layer too heavy
  - cards too dense
  - sidebar too much like second interface
- Ready when:
  - calmer overview
  - lighter right panel
  - CTA hierarchy fixed
  - add-to-day remains contextual

### 1.4 Spot Detail
- Status: `review`
- Family: Detail
- Primary phase: Plan bridge
- Owner: TBD
- Primary CTA: Voeg toe aan mijn dag
- Blockers:
  - practical section quality
  - field rendering quality
  - combinations need stronger value
- Ready when:
  - hero strong
  - context strip human and useful
  - practical info clean
  - combinations useful
  - footer transition clean

### 1.5 Product Detail
- Status: `in_progress`
- Family: Detail
- Primary phase: Plan -> Boek
- Owner: TBD
- Primary CTA: Boek nu / Voeg toe aan mijn dag
- Progress (2026-04-02):
  - ✅ Form-kill CSS scoped to .single-product:has(#sbdp-booking-form)
  - ✅ WooCommerce form.cart no longer hidden on products without sbdp form
  - ✅ PHP extracted from CSS (LegacyFormHooks.php archival)
- Remaining blockers:
  - disconnected old sections still visible
  - detail family visual alignment not started
- Ready when:
  - detail family aligned
  - CTA role clear
  - planner/booking handoff intact

---

## 2. Execution layer readiness

### 2.1 Planner / Plan je dag
- Status: `review`
- Family: Execution
- Primary phase: Plan -> Boek
- Owner: TBD
- Primary CTA: Boek mijn dag
- Blockers:
  - visual density
  - summary clarity
  - family alignment with detail/overview
- Hard safety rule:
  - planner domain logic may not be rewritten in launch sprint

### 2.2 Planning Cart
- Status: `not_started`
- Family: Execution
- Primary phase: Boek
- Owner: TBD
- Primary CTA: Verder naar afrekenen
- Blockers:
  - likely generic Woo/cart behavior
  - summary trust layer may be weak
- Ready when:
  - summary calm
  - visual family aligned
  - price truth untouched

### 2.3 Checkout / Afrekenen
- Status: `not_started`
- Family: Execution
- Primary phase: Boek
- Owner: TBD
- Primary CTA: Bevestig en betaal / Verstuur aanvraag
- Blockers:
  - likely visually disconnected
  - trust and clarity may be inconsistent
- Ready when:
  - visual continuity with planner/detail
  - friction reduced
  - price/tax/order truth untouched

---

## 3. Management and experience layer readiness

### 3.1 Account
- Status: `not_started`
- Family: Management
- Primary phase: Beheer & upgrade
- Owner: TBD
- Primary CTA: Bekijk je planning / Voeg nog iets toe
- Blockers:
  - likely too admin-like
- Ready when:
  - clear overview
  - operational but branded
  - relevant upsell possible

### 3.2 Portal
- Status: `later`
- Family: Management
- Primary phase: Beheer & operational collaboration
- Owner: TBD
- Notes:
  - may receive surface alignment only in first launch sprint

### 3.3 Tour / Beleef
- Status: `later`
- Family: Experience
- Primary phase: Beleef
- Owner: TBD
- Primary CTA: Start route / Volgende stop
- Notes:
  - should receive surface alignment and shell consistency
  - deep experience redesign can follow later

---

## 4. Shared platform readiness

### 4.1 Shell / Header / Footer
- Status: `review`
- Owner: TBD
- Ready when:
  - header always top
  - main always middle
  - footer always bottom
  - no template drift
  - no detached plugin-page feeling

### 4.2 Design System Primitives
- Status: `in_progress`
- Owner: TBD
- Ready when:
  - one button family
  - one card family
  - one form/filter family
  - one tab family
  - one summary family
  - dark/light coherent

### 4.3 OMDB / Woo Boundary Safety
- Status: `must_pass`
- Owner: TBD
- Ready when:
  - no duplicated pricing logic
  - no duplicated availability logic
  - no broken OMDB semantics
  - no broken Woo truth

---

## 5. Launch blockers

A launch is blocked if any of these are true:
- shell is unstable
- CTA hierarchy is wrong on key public pages
- major family drift remains
- raw field rendering is still visible
- add-to-day flow is broken
- planner continuity is broken
- pricing truth is duplicated in UI
- checkout/cart are visually or functionally unsafe
- mobile is clearly broken on public pages

---

## 6. Launch decision

### Safe to launch?
- Current: `not_yet`

### Launch only when:
- Homepage = ready
- Activities Overview = ready
- Spots Overview = ready
- Spot Detail = ready
- Product Detail = ready
- Shell = ready
- Design System Primitives = ready
- OMDB / Woo Boundary Safety = pass
- Regression Checklist = pass