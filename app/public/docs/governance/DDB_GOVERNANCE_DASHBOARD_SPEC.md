# DDB Governance Dashboard Spec

> **Authority document** — Governance truth. Do not edit without an approved governance task.

## Purpose

Required cockpit tabs, widgets, and status model for the DDB governance dashboard (`sbdp_design_backend`).

## Status Model

All status signals use one of four values:

| Value | Meaning |
|-------|---------|
| `pass` | Gate is met; no action required |
| `warn` | Issue detected; action recommended before launch |
| `fail` | Gate is not met; release blocked |
| `unknown` | Check not yet wired; manual validation required |

## Required Status Cards

| Card | Signal source | Pass condition |
|------|--------------|----------------|
| Design System | Runtime template scan | No inline `<style>` or `style=` in critical Woo templates |
| Shell | Runtime template scan | All WooCommerce overrides have canonical shell markers |
| OMDB | Authority docs | `DDB_OMDB_WOO_BOUNDARIES.md` present + live semantic probe |
| Woo | Authority docs | `DDB_OMDB_WOO_BOUNDARIES.md` present + live pricing probe |
| Planner | Authority docs | Planner continuity harness attached |
| Mobile | Authority docs | Viewport regression sweep attached |

## Required Tabs

### 1. Overzicht (Overview)

- Status cards (6 cards, all signals)
- Critical blockers list
- Backend launch status (Ja / Nee)

### 2. Runtime Signalen (Runtime Signals)

- Full runtime check results table
- Legacy CSS status
- Shell drift details

### 3. Pagina Lancering (Page Launch)

- Page family launch readiness matrix
- Status per page family (pass / warn / fail / unknown)

### 4. Autoriteitsdocs (Authority Docs)

- All 17 authority docs with pass/fail status
- Excerpts from present docs
- Links to expected file paths

### 5. Strategie (Strategy)

- Governance policy excerpts
- RACI overview
- Implementation sequence

## Required Widgets

- **Launch status indicator**: prominent Ja/Nee indicator showing whether backend launch is ready
- **Critical blockers count**: number badge on tab or card

## Notes

- The dashboard is read-only. It must not modify any truth.
- Status is computed fresh on each page load; no caching of signals.
- Missing authority docs must be shown as FAIL, not suppressed.
