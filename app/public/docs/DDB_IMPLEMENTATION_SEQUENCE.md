# DDB Implementation Sequence

> **Authority document** — Governance truth. Do not edit without an approved governance task.

## Purpose

Mandatory normalization order. Changes to the platform must follow this sequence to avoid truth drift.

## Phase 0 — Foundation (must be complete before any feature work)

1. Design system tokens (`--ddb-*` CSS custom properties) must be declared in the canonical tokens file
2. Shell structure must be normalized (canonical header, main, footer)
3. Booking truth runtime must be wired (`BookingModeService`, `BookingTruthRuntimeService`)
4. OMDB / Woo boundaries must be enforced

## Phase 1 — Surface normalization

Order within each phase must be respected:

1. **Cart template override** — apply `ddb-cart-shell`
2. **Thank-you template override** — apply `ddb-order-received-layout`
3. **Checkout template override** — apply `ddb-commerce-shell`
4. **Account template override** — apply `ddb-account-shell`

## Phase 2 — Booking flow

1. **Quote flow** — QuoteBuilder, QuoteWorkspace
2. **Supplier confirmation** — SupplierConfirmationService, QuoteBuilderRenderer panels
3. **Supplier request draft** — draft generation, partner actions, status advance
4. **Direct booking** — only for products with `directBookable: true` (never product 115)

## Phase 3 — Content and SEO

Only after Phase 0 + 1 are complete:
- OMDB content population
- Page family SEO metadata
- CTA copy aligned with CTA Map

## Phase 4 — Launch gates

See `DDB_LAUNCH_BOARD.md` and `DDB_REGRESSION_CHECKLIST.md`.

## Rules

- Never skip a phase.
- Never implement Phase 2+ features on surfaces where Phase 1 is incomplete.
- Authority docs must be present before launch gates are evaluated.
