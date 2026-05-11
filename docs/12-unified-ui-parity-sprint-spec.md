# DagjeDenBosch Unified UI Parity Sprint Spec

## Purpose
This sprint spec turns the Unified UI Master Agent into execution.

Goal:
Make all public page families feel like one product by enforcing parity on:
- typography
- chips
- cards
- buttons
- colorstyle
- spacing rhythm
- site width

This document is implementation-focused and file-targeted.

---

## 1. Current drift (from screenshots, 2026-04-15)

### 1.1 Planner vs cart mismatch
- Planner has stronger visual grammar and density structure.
- Cart and totals panel still read as a separate surface language.
- CTA and summary spacing differs too much from planner summary bars.

### 1.2 Offerte page drift
- Offerte page is close to canon but still carries local style truth.
- Offerte controls are not fully mapped to shared form/button primitives.
- Offerte styles currently ship inline CSS in shortcode output.

### 1.3 Account page drift
- Account shell is visually close but navigation/content module balance diverges.
- My account nav and content modules need stricter shared card and control behavior.

### 1.4 Component variant sprawl
- Chip and button variants are fragmented across planner, commerce, and quote surfaces.
- Multiple local style files still define near-duplicate component behavior.

---

## 2. Sprint objective

By end of sprint:
- one typography system in runtime usage
- one chip family runtime behavior
- one card family runtime behavior
- one button family runtime behavior
- one spacing rhythm across planner/cart/checkout/offerte/account
- one site-width/container system across these families
- no inline-offerte visual truth

---

## 3. Hard boundaries

Do not break:
- OMDB semantics
- Woo pricing and VAT truth
- planner domain logic
- add-to-day and quote handoff logic

Allowed:
- token and CSS refactors
- class normalization
- template/class-name normalization
- replacing inline styles with shared design-system classes

---

## 4. Target files by family

### 4.1 Shared design system foundation
- app/public/wp-content/plugins/booking-pro-module/assets/css/design-system.css
- app/public/wp-content/plugins/booking-pro-module/assets/css/ddb-ui.css

### 4.2 Planner family
- app/public/wp-content/plugins/booking-pro-module/assets/css/day-planner.css
- app/public/wp-content/plugins/booking-pro-module/assets/css/day-planner-refresh.css
- app/public/wp-content/plugins/booking-pro-module/assets/js/day-planner/app/components/*.jsx
- app/public/wp-content/plugins/booking-pro-module/assets/js/day-planner/store/PlannerProvider.jsx

### 4.3 Cart / checkout / account execution family
- app/public/wp-content/plugins/booking-pro-module/assets/css/sbdp-cart-checkout.css
- app/public/wp-content/plugins/booking-pro-module/modules/core/WooCommerce/CommercialFlowService.php
- app/public/wp-content/plugins/booking-pro-module/modules/product-page-refresh/Module.php

### 4.4 Offerte family
- app/public/wp-content/plugins/booking-pro-module/modules/bookings/Shortcodes/OfferteForm.php

### 4.5 Tour family parity check
- app/public/wp-content/themes/hello-biz/single-sbdp_private_tour.php

---

## 5. Execution waves

### Wave 1 — Primitive convergence (must finish first)
1. Freeze canonical tokens for type/spacing/radius/container in shared CSS only.
2. Remove duplicate per-file constants where shared tokens already exist.
3. Define shared UI utility classes for:
- chip sizes and states
- card shells and inner spacing
- button variants and focus states
- container width and section rhythm

Exit criteria:
- zero new hardcoded local color/radius/spacing values in touched files unless documented as temporary.

### Wave 2 — Planner and cart/checkout parity
1. Align planner and cart summary zones to one summary grammar.
2. Align button heights, radius, spacing, and state behavior.
3. Align chip style semantics between planner tags and cart/order item chips.

Exit criteria:
- planner, cart, and checkout screenshots pass parity checklist for chips/cards/buttons/spacing.

### Wave 3 — Offerte normalization
1. Move inline CSS out of OfferteForm shortcode into shared stylesheet(s).
2. Keep shortcode markup lean and mapped to shared DS class contract.
3. Reuse the same form control and CTA primitives used in checkout/account where possible.

Exit criteria:
- Offerte no longer ships full inline design system.
- Offerte form controls visually match execution family controls.

### Wave 4 — Account module parity
1. Normalize my-account nav and content panels to shared card and list grammar.
2. Align section spacing with checkout and offerte surfaces.
3. Ensure selected/hover/focus state parity for account nav items.

Exit criteria:
- account page no longer feels detached from planner/cart/checkout flow.

### Wave 5 — Final pass and governance gate
Run mandatory review loop:
1. Platform Governor
2. Design System Truth Agent
3. OMDB / Woo Boundary Agent
4. Planner Safety Agent
5. Mobile / Regression QA Agent
6. Final CSOT / OMDB Review Agent

Exit criteria:
- all review steps pass.

---

## 6. Parity checklist (must pass on desktop + mobile)

### Typography
- heading font family parity
- body/control font family parity
- heading scale parity
- helper/meta text parity

### Chips
- same radius and height
- same selected and muted states
- same label weight and spacing

### Cards
- same surface hierarchy
- same border and elevation logic
- same inner spacing and title rhythm

### Buttons
- same control heights and radii
- same primary/secondary hierarchy
- same focus and disabled states

### Colorstyle
- same dark surface ladder
- restrained accent distribution
- no local color islands

### Spacing
- same section rhythm
- same card padding rhythm
- same stack and grid gap rhythm

### Site-width
- same max width behavior for main content zones
- same gutter behavior on desktop and mobile

---

## 7. Risk and rollback notes

### High risk
- touching cart/checkout templates in ways that bypass WooCommerce hooks
- touching planner JS state contracts while doing visual refactor

### Mitigation
- isolate visual/class changes from domain logic
- keep pricing and availability code paths untouched
- verify add-to-day, cart add, checkout refresh, quote submit, account order view

### Rollback strategy
- keep changes split by wave and file group
- if regression appears, roll back only affected wave files

---

## 8. Definition of done

Done means:
- no page family behaves like a design island
- planner/cart/checkout/offerte/account look and feel system-related
- shared DS primitives are visibly and technically dominant
- runtime behavior remains correct for planner and commerce truth
