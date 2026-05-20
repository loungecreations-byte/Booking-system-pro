# DDB Regression Checklist

> **Authority document** — Governance truth. Do not edit without an approved governance task.

## Purpose

Regression gates for shell, design system, mobile, planner, and execution surfaces. Must be run before any merge to main or launch.

## Shell Regression

- [ ] Cart page renders with `ddb-cart-shell` wrapper
- [ ] Checkout page renders without inline style drift
- [ ] Thank-you page renders with `ddb-order-received-layout` wrapper
- [ ] Account page renders without shell breakage
- [ ] No page has two `<header>` elements outside the canonical shell structure

## Design System Regression

- [ ] No `!important` overrides introduced on token-controlled properties
- [ ] No inline `style=` on UI component elements
- [ ] Dark mode toggle functions on admin design backend page
- [ ] CSS custom properties resolve correctly in light and dark mode

## Mobile Regression

- [ ] Cart page readable on 375px viewport
- [ ] Planner readable on 375px viewport
- [ ] Booking flow functional on touch device

## Planner Regression

- [ ] Add-to-day from detail page correctly sets participants
- [ ] Request-only products route to quote, not checkout
- [ ] Product 115 routes to supplier confirmation, not direct checkout
- [ ] Planner handoff payload carries `sbdp_canonical_participants`

## Execution Surface Regression

- [ ] Direct-bookable product completes checkout without errors
- [ ] Request-only product cannot reach WooCommerce checkout
- [ ] Supplier confirmation product shows "Partneractie vereist" in quote workspace
- [ ] Supplier request draft can be generated from QuoteBuilder

## Authority Docs Regression

- [ ] All authority docs readable from governance cockpit
- [ ] No doc missing at expected path
