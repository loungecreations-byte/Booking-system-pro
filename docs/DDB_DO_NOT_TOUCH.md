# DagjeDenBosch Do Not Touch

## Purpose
This document lists the platform truths that must not be silently redefined.

Rule:
If a change touches one of these areas, the truth must be updated intentionally and reviewed explicitly.

---

## 1. Non-negotiable truths

- OMDB remains the source of domain meaning
- WooCommerce remains the source of final commercial truth
- `ddb-core-ui/core-ui.php` is the active design CSOT (migrated 2026-04-08 — `ddb-core-design-system.php` is disabled unless `DDB_ENABLE_LEGACY_MU_DESIGN_SYSTEM` is set)
- `ddb-core-design-system.php` may remain as a compatibility/orchestration bridge, but must not enqueue competing public frontend stylesheet truth when `ddb-core-ui` is active
- The canonical shell remains header, main, footer
- Planner continuity must not be broken by UI refactors
- Customer-visible pricing must remain VAT-inclusive

---

## 2. Do not touch without explicit review

- pricing arithmetic outside the approved central pricing layer
- VAT logic outside Woo-aware helpers
- availability truth outside the booking execution layer
- cart truth outside WooCommerce
- checkout truth outside WooCommerce
- order truth outside WooCommerce
- planner domain meaning inside page templates or JS
- OMDB field meaning in rendering layers
- shell order in public page families
- shared component canon by local page CSS

---

## 3. Forbidden replacement patterns

- Replacing Woo price truth with front-end computed totals
- Replacing OMDB semantics with UI assumptions
- Replacing shared design tokens with page-local CSS tokens
- Replacing shared buttons/cards/forms with local component systems
- Replacing planner continuity with one-off page behavior

---

## 4. High-risk zones

- pricing and tax code
- cart and checkout templates
- order rendering
- planner handoff logic
- REST routes that mutate booking or pricing state
- admin actions that edit platform truth
- template overrides that affect shell integrity

---

## 5. Do not touch law

- Do not silently widen business logic
- Do not duplicate pricing or availability logic
- Do not invent a second design system
- Do not create a second config system
- Do not bypass the review loop
- Do not change domain meaning to match a visual preference

---

## 6. Safe alternative

If a surface is ugly, fix the adapter or presentation layer.
If a surface is confusing, align the CTA and shell.
If a source is wrong, update the authority layer first.

---

## 7. Provider Integration Do-Not-Touch Rules

Do not silently change provider capability or booking mode.

Forbidden without explicit governance approval:

- Frontend or planner calls directly to external provider APIs for booking truth.
- Using provider schedule as availability truth.
- Using external `available:true` as direct checkout permission when no hold exists.
- Using provider prices as WooCommerce price truth.
- Sending request-only items into direct checkout.
- Setting `directBookable:true` for a provider product without proven hold, booking confirmation, cancellation/change path, idempotency, and commercial permission.
- Calling Eliio `POST /booking-widget` for direct WooCommerce checkout.
- Changing DDB product `115` from request-only to direct bookable.

Protected state for product `115` (`E-Chopper tour`):

- `directBookable=false`
- `supplierConfirmationRequired=true`
- route intent `REQUEST` / quote

Any task that changes this state is `NEEDS_DECISION` and must update provider integration, availability, CTA, OMDB/Woo, participants, and supplier capability documentation first.
