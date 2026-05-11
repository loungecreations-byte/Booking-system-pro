# DagjeDenBosch Governance Dashboard Spec

## Purpose
This document defines the backend Governance Cockpit / Platform Health Dashboard.

The dashboard must make the real platform truth visible.

It must not become:
- a second source of truth
- a hidden settings engine
- a manual override system for architecture or business truth

It must become:
- a governance overview
- a health dashboard
- a launch-readiness cockpit
- a review-state monitor

---

## 1. Dashboard objective

The Governance Cockpit must answer these questions:

1. Are we using the design system as runtime truth?
2. Which page families are aligned and which are drifting?
3. Is shell integrity stable?
4. Are OMDB and Woo boundaries protected?
5. Is planner continuity safe?
6. What is launch-ready right now?
7. What blocks release?
8. Which reviews have passed and which have not?

---

## 2. Top-level tab structure

### Tab 1 — Strategy
Purpose:
show the platform truth.

### Tab 2 — System Health
Purpose:
show current health, drift, and risk.

### Tab 3 — Launch Board
Purpose:
show launch readiness, blockers, and release decision.

---

## 2.1 Related backend mirror

The governance cockpit has a paired read-only backend design mirror:

- `Bookings > Design Backend`

Purpose:
- show admin-side design system truth
- show backend shell and component drift
- show route-targeted admin asset use
- show that governance and design backend are not separate truth systems

This page may mirror, summarize and link.
It may not override governance or platform truth.

---

## 3. Tab 1 — Strategy

### Widget A — Platform Constitution Summary
Source:
- `docs/DDB_PLATFORM_CONSTITUTION.md`

Shows:
- core platform purpose
- 3 truths
- page-family law
- shell law
- completion law

### Widget B — Journey Model
Source:
- `docs/DDB_PLATFORM_CONSTITUTION.md`

Shows:
- Ontdek
- Bewaar
- Plan
- Boek
- Beheer & upgrade
- Beleef
- Kom terug

### Widget C — Page Family Matrix
Sources:
- `docs/DDB_PAGE_FAMILIES.md`
- `docs/DDB_PLATFORM_CONSTITUTION.md`

Shows by family:
- family name
- purpose
- primary phase
- what it must do
- what it must not do

### Widget D — CTA Map Summary
Source:
- `docs/DDB_CTA_MAP.md`

Shows per page family:
- primary CTA
- secondary CTA
- common mistakes

### Widget E — Component Canon Summary
Source:
- `docs/DDB_COMPONENT_CANON.md`

Shows:
- button canon
- card canon
- filter canon
- tab canon
- summary canon
- map/detail canon

### Widget F — Implementation Sequence
Source:
- `docs/DDB_IMPLEMENTATION_SEQUENCE.md`

Shows:
- current phase
- what comes next
- what should not be skipped

---

## 4. Tab 2 — System Health

### Widget A — Platform Status Cards
Sources:
- `docs/DDB_REGRESSION_CHECKLIST.md`
- `docs/DDB_OMDB_WOO_BOUNDARIES.md`
- `docs/DDB_SHELL_RULES.md`
- review outputs where available

Cards:
1. Design System Truth
2. Shell Integrity
3. OMDB Boundary
4. Woo Boundary
5. Planner Safety
6. Mobile Readiness

Each card shows:
- status: pass / warning / fail / unknown
- short explanation
- last reviewed
- link to detail

### Widget B — Design System Drift
Sources:
- review outputs
- runtime checks
- component inventory if available

Shows:
- duplicate component family warnings
- page-local visual truth warnings
- Elementor design-truth warnings
- legacy CSS still active
- family drift indicators

### Widget C — Shell Health
Sources:
- `docs/DDB_SHELL_RULES.md`
- review outputs
- runtime template checks where feasible

Shows:
- header/footer/main compliance
- pages with shell drift
- Elementor condition mismatches
- template exceptions

### Widget D — OMDB / Woo Boundary Health
Sources:
- `docs/DDB_OMDB_WOO_BOUNDARIES.md`
- `docs/DDB_AVAILABILITY_TRUTH.md`
- `docs/DDB_PROVIDER_INTEGRATION_TRUTH.md`
- review outputs

Shows:
- UI pricing duplication found? yes/no
- UI availability duplication found? yes/no
- OMDB field meaning risk found? yes/no
- Woo truth risk found? yes/no
- add-to-day contract safe? yes/no
- runtime route intent drift found? yes/no
- request-only checkout leak found? yes/no

### Widget E — Planner Safety
Sources:
- `docs/DDB_PARTICIPANTS_TRUTH.md`
- `docs/DDB_AVAILABILITY_TRUTH.md`
- `docs/DDB_PROVIDER_INTEGRATION_TRUTH.md`
- planner safety review outputs

Shows:
- add-to-day continuity
- canonical participants continuity
- summary continuity
- combi continuity
- planner handoff safety
- runtime route intent continuity
- provider capability separation

### Widget F — Runtime Health Signals
Sources:
- runtime checks where safely available
- otherwise scaffold as unknown

Signal examples:
- duplicate component family detected
- legacy CSS still active
- raw field rendering detected
- shell drift detected
- page-family mismatch detected
- add-to-day continuity risk detected

---

## 5. Tab 3 — Launch Board

### Widget A — Launch Readiness Matrix
Source:
- `docs/DDB_LAUNCH_BOARD.md`

Rows:
- Homepage
- Activities Overview
- Spots Overview
- Spot Detail
- Product Detail
- Planner
- Planning Cart
- Checkout
- Account
- Tour
- Shell
- Design System Primitives
- OMDB/Woo Safety

Columns:
- status
- family
- primary phase
- owner
- blockers
- ready when
- last updated

### Widget B — Critical Blockers
Sources:
- `docs/DDB_LAUNCH_BOARD.md`
- review outputs

Shows:
- blocker title
- severity
- affected page/family
- owner
- required next action

### Widget C — Review Loop Status
Source:
- `docs/DDB_REVIEW_LOOP.md`
- review output files if available

Shows:
1. Platform Governor
2. Design System Truth Agent
3. OMDB / Woo Boundary Agent
4. Planner Safety Agent
5. Mobile / Regression QA Agent
6. Final CSOT / OMDB Review Agent

For each:
- pass / warning / fail / not run
- last reviewed
- notes

### Widget D — Safe to Launch
Sources:
- launch board
- review loop
- regression checklist

Shows:
- Safe to launch? yes/no
- blocking reasons
- warnings
- next mandatory actions

---

## 6. Data sources

### Primary sources
- governance docs in `docs/`
- repo truth
- review outputs
- runtime checks where safely possible

### Secondary sources
- derived summaries
- computed status based on doc/review values

### Forbidden sources
- fake manual truth
- hidden admin overrides
- settings that silently redefine platform truth
- business logic duplication

If a value cannot be safely derived, show:
- `unknown`

Never invent truth.

---

## 7. Visual/UI requirements

The Governance Cockpit must feel:
- structured
- clean
- calm
- operational
- premium
- not like generic WP admin clutter

Use:
- cards
- tables
- status badges
- clear hierarchy
- compact summaries
- readable tabs
- controlled accent usage

Do not:
- overload with text
- create a second design system
- use decorative admin gimmicks
- bury blockers

---

## 8. Status model

Allowed statuses:
- `pass`
- `warning`
- `fail`
- `unknown`
- `not_run`
- `not_started`
- `in_progress`
- `review`
- `ready`
- `blocked`
- `later`

Statuses must be used consistently.

---

## 9. KPI section

The dashboard should eventually surface these KPI groups.

### Governance KPIs
- releases with full gates completed (%)
- blocked releases (count)
- exceptions active (count)
- exceptions expired unresolved (count)

### Stability KPIs
- release-related incidents
- post-release critical regressions
- shell regressions
- planner continuity regressions

### Flow KPIs
- planner start rate
- add-to-day completion rate
- planner -> booking/request rate
- activities overview -> detail rate
- activities/spots -> add-to-day rate

These may start as placeholders if not yet wired.

---

## 10. Ownership display

Where possible, the dashboard should display:
- owner
- last reviewed date
- next action
- blocking role if applicable

This is important for accountability.

---

## 11. Dashboard law

The dashboard must mirror truth, not replace it.

If a governance status shown in the cockpit conflicts with the authoritative docs or review outputs, the source documents and review outputs win.
