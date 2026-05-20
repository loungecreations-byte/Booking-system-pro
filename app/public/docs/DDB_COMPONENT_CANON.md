# DDB Component Canon

> **Authority document** — Governance truth. Do not edit without an approved governance task.

## Purpose

One button, one card, one filter, one tab, one shell family. Prevents component proliferation and design drift.

## Button

- One canonical button: `.ui-btn` with modifiers `--primary`, `--secondary`, `--ghost`, `--danger`
- Request/offerte actions: `.ui-btn--request`
- No inline `style=` overrides on buttons
- No custom button classes outside the canon

## Card

- One canonical card: `.ui-card` with modifiers `--compact`, `--elevated`, `--interactive`
- Spot cards in overview: always `.ui-card--interactive`
- Order/booking summary cards: `.ui-card--elevated`

## Filter

- One canonical filter component: `.ui-filter`
- Filter state lives in URL params, never in-component only
- No custom filter implementations per page

## Tab

- One canonical tab: `.ui-tab` with `--active` state modifier
- Admin tabs: use WordPress admin tab conventions inside `bsp-admin-shell`

## Shell

- Public shell: `ddb-shell` (header + main + footer)
- Commerce shell overrides: `ddb-cart-shell`, `ddb-order-received-layout`
- Account shell: `ddb-account-shell`
- Admin shell: `bsp-admin-shell`
- No page may create a new shell variant without a governance task

## Prohibited Patterns

- Multiple button styles on same page without canon modifier
- Inline `style=` on any canon component
- `!important` overrides on component tokens
- Duplicate card implementations per page family
