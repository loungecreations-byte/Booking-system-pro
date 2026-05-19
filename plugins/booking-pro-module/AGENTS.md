# Booking Pro Module PR Review Guide

## Purpose
This repository powers a custom WooCommerce booking and planner system. Reviews must be strict, business-aware, and biased toward catching regressions in pricing, VAT, booking integrity, order integrity, and security.

Do not review this codebase like a generic WordPress plugin. The highest-risk failures here are:
- wrong customer-visible prices
- VAT shown or stored incorrectly
- planner totals diverging from cart, checkout, or order totals
- booking metadata being lost or changed in transit
- insecure AJAX, REST, admin, or form handlers
- Elementor or Theme Builder breakage caused by destructive template changes

Approve only when the booking flow remains deterministic, traceable, and reproducible end to end.

## Review Mindset
- Prioritize real bugs, regressions, missing validation, access-control failures, and architectural risk.
- Prefer fewer, high-signal comments over many small style notes.
- Treat unclear pricing truth, hidden calculations, and duplicated business logic as serious findings.
- Assume any change touching pricing, planner state, cart item data, order item meta, AJAX, or REST is high risk until proven otherwise.
- When uncertain, state the assumption you are making and why it matters.

## Severity
- `P0`: checkout failure, order creation failure, critical security flaw, data corruption, or irreversible booking loss
- `P1`: pricing/VAT error, planner/cart/checkout/order mismatch, booking metadata loss, or access-control issue
- `P2`: important compatibility, maintainability, or architecture risk that is likely to cause future bugs
- `P3`: lower-priority quality suggestion with limited business impact

## Repository Reality
This codebase has mixed architectures. Important logic exists in both legacy `includes/` code and newer `modules/` services. Do not assume one layer is authoritative without tracing the full flow.

Key source roots:
- `booking-pro-module.php`
- `core/`
- `includes/`
- `modules/`
- `templates/`
- `assets/`
- `tests/`

Generated or low-signal directories are not primary review targets unless the PR touches them:
- `node_modules/`
- `vendor/`
- `.build-test/`
- `build/`
- `ops/codex-output/`

## Critical Architecture Hotspots

### Pricing and VAT
These paths are pricing-sensitive and must be reviewed together when one changes:
- `includes/Pricing/PricingService.php`
- `modules/core/Rest/RestService.php`
- `modules/product-page-refresh/Module.php`
- `modules/day-planner/Service/PriceEngine.php`
- `modules/pricing/Services/PricingService.php`
- `modules/sales/Pricing/PricingService.php`
- `modules/sales/DynamicPricingService.php`
- `modules/core/Assets/EnqueueService.php`
- `assets/product-summary.js`
- `modules/planner/assets/planner.js`

### Planner, cart, checkout, order
These paths define the booking transaction boundary:
- `modules/product-page-refresh/Module.php`
- `modules/core/WooCommerce/Checkout/CheckoutService.php`
- `modules/core/Emails/EmailsService.php`
- `modules/planner/Services/PlannerService.php`
- `modules/day-planner/Service/PlanService.php`
- `includes/planning-sessions.php`
- `modules/core/WooCommerce/Display/MetaDisplay.php`
- `modules/core/WooCommerce/Display/ProductForm.php`

Specific metadata and payloads that must survive intact when touched:
- `sbdp_summary`
- `sbdp_pricing`
- `sbdp_plan_item`
- `sbdp_plan_aggregate`
- `sbdp_planner_input`
- `sbdp_start`
- `sbdp_end`
- `sbdp_participants`
- `sbdp_resource_id`
- `sbdp_resource_label`
- `sbdp_plan_item_key`

### Security-sensitive entry points
Review permission and validation carefully in:
- `modules/bookings/Rest/Controller.php`
- `modules/core/Rest/RestService.php`
- `modules/planner/Rest/`
- `modules/day-planner/Rest/`
- `modules/booking-board/Rest/`
- `modules/vendor-portal/Rest/`
- `modules/commerce/Rest/Controller.php`
- `modules/product-overview/product-overview.class.php`
- `modules/core/Admin/SetupWizard.php`
- `modules/planner/Vendor/Admin/ProfileAdmin.php`
- `includes/class-sbdp-private-tours-rest.php`
- `includes/class-sbdp-private-tours-admin.php`
- any `wp_ajax_*`, `admin_post_*`, or `register_rest_route()` additions

### Elementor and template compatibility
Review backwards compatibility carefully in:
- `booking-pro-module.php`
- `includes/class-elementor.php`
- `includes/elementor/`
- `templates/`
- `templates/elementor-bookable-product.json`
- `modules/core/Assets/EnqueueService.php`
- `modules/product-page-refresh/Module.php`
- `modules/core/WooCommerce/Display/ProductForm.php`

## Non-Negotiable Review Rules

### 1. WooCommerce pricing and VAT logic
- All customer-visible prices must be inclusive of VAT.
- Prices shown in frontend UI, planner summaries, cart, checkout, thank-you page, emails, and admin order summaries must match WooCommerce taxed totals.
- The planner is a UX layer, not an independent pricing truth.
- Do not accept raw `_price`, `_regular_price`, or manual meta as the final display source for customer-visible amounts.
- Do not accept `get_post_meta($id, '_price', true)` for display logic.
- Treat direct reads of `_sbdp_base_price` and `_sbdp_price_per_person` as configuration storage, not as authoritative display totals.
- Prefer WooCommerce tax-aware helpers and centralized pricing services.
- New manual VAT arithmetic is a finding unless it is inside an explicitly justified central pricing layer and no WooCommerce helper can safely express it.
- Multiple pricing truths are a `P1` by default.

### 2. Planner, cart, checkout, and order consistency
- Flag any change that can make planner totals differ from cart totals, checkout totals, order totals, or email totals.
- Cart and order creation must not trust frontend-computed totals.
- Product IDs, quantities, date, time, participants, segment labels, resource assignments, and planner metadata must survive the full flow intact.
- Watch for double multiplication by participant count or quantity.
- Watch for duplicate segment insertion, stale aggregate totals, stale session totals, or incorrect line-item reconstruction.
- Review the full path whenever touched:
  `plan-je-dag -> planner state/REST -> add to cart payload -> cart item data -> before_calculate_totals -> checkout line items -> order item meta -> emails/admin summaries`

### 3. Security
- Every admin action, AJAX endpoint, REST route, and form handler must have the right nonce and capability boundary for its risk level.
- `permission_callback` returning `true` or only `is_user_logged_in()` is usually not sufficient for admin, pricing, booking, or cross-tenant operations.
- Do not trust client-supplied totals, prices, discounts, tax, order amounts, vendor IDs, product IDs, participants, or booking metadata without server-side validation.
- Flag privilege escalation, CSRF, insecure REST access, token misuse, weak session validation, and unsafe admin actions as high severity.
- Direct SQL must use prepared statements where input is involved and must not widen data exposure.

### 4. Validation, sanitization, and escaping
- All request data from `$_POST`, `$_GET`, `$_REQUEST`, `$_SERVER`, JSON payloads, and REST params must be sanitized and normalized before use.
- All dynamic output in admin and frontend must be escaped appropriately.
- Flag silent type coercion for quantities, participants, dates, times, product IDs, prices, and booking metadata.
- Dates and times must be normalized consistently. Mixed raw strings and partial ISO values are a review concern.

### 5. Elementor and Theme Builder compatibility
- Favor additive changes over destructive rewrites to template structure or hooks.
- Flag removal or relocation of hooks that existing Elementor or Theme Builder templates may depend on.
- Flag changes that assume only one rendering path when both legacy WooCommerce output and Elementor-based output still exist.
- Review asset enqueue changes for editor, preview, and frontend behavior separately.

### 6. Duplicate logic and architecture quality
- This repo already has pricing logic in multiple places. Any new duplicated pricing, tax, cart, booking, or metadata logic is suspicious.
- If a PR copies rules between PHP, JS, templates, AJAX, and REST instead of centralizing them, flag it.
- If a change expands a fallback path into a second source of truth, flag it.
- Hidden coupling between legacy `includes/` code and module code should be called out explicitly.

## What To Inspect In Relevant PRs
When a PR touches any booking, pricing, planner, cart, checkout, order, or REST code, inspect all applicable areas below:

- WooCommerce hooks and filters
- `woocommerce_add_cart_item_data`
- `woocommerce_before_calculate_totals`
- `woocommerce_checkout_create_order_line_item`
- `woocommerce_checkout_create_order`
- `woocommerce_get_item_data`
- cart item hydration from session
- order item meta creation and reads
- email rendering of booking data
- AJAX and REST route registration and permission callbacks
- admin save handlers and `admin_post_*` actions
- template overrides and shortcode rendering
- JS and PHP duplication in planner or pricing logic
- any use of raw meta-based price retrieval

## What To Flag Immediately
- `get_post_meta($id, '_price', true)` used for display, cart, checkout, planner totals, emails, or order summaries
- manual `price * qty` display logic outside the approved central helper layer
- customer-visible amounts built from `_regular_price`, `_price`, `_sbdp_base_price`, or `_sbdp_price_per_person` instead of WooCommerce taxed totals or the centralized quote flow
- frontend totals, planner totals, or hidden form fields trusted by backend order/cart logic
- missing nonce on admin action, AJAX action, or privileged REST action
- missing capability checks on admin, AJAX, REST, or vendor operations
- direct use of `$_POST`, `$_GET`, or REST payload values without sanitization and normalization
- outputting request or meta values without escaping
- any new pricing formula duplicated across JS and PHP
- any new tax logic duplicated in multiple files
- planner/cart/order mismatch risk
- line item rebuild logic that can drop date, time, participants, segment, resource, or aggregate metadata
- `permission_callback` that is too broad for mutation routes or private booking/vendor data
- direct DB access that bypasses validation, capability checks, or tenant boundaries
- provider schedule endpoints treated as availability truth
- external `available:true` treated as hold, booking confirmation, supplier confirmation, or direct checkout permission
- provider prices treated as WooCommerce price truth
- provider-specific API calls embedded directly in frontend/planner components
- Eliio `POST /booking-widget` used for direct checkout
- `directBookable:true` for DDB product `115` without an approved governance task

## Provider Integration Guardrails

Before any provider integration, supplier availability, request/direct routing, cancellation, or webhook/status work, report:

1. Which truths are touched: participants truth, availability truth, provider integration truth, price/Woo truth, request/direct routing, cancellation truth.
2. Endpoint type: schedule, availability, hold, booking, cancellation, webhook/status.
3. Whether canonical participants are used.
4. Whether WooCommerce price/order/payment/tax is touched.
5. Whether `directBookable` can ever become `true`.
6. API-error fallback.
7. Idempotency or duplicate-request protection.
8. Cancellation/change path.
9. Commercial permission.

Provider integrations must go through server-side adapters/services. Frontend and planner may consume only normalized runtime decisions.

For Eliio/Eropuitje product `115` (`E-Chopper tour`):
- `GET /availability/widget` may be used only for participant-sensitive server-side availability pre-checks.
- `available:true` means only that the slot appears available for exact `participants=N` at that moment.
- `available:true` does not mean hold, booking, price confirmation, supplier confirmation, or direct checkout.
- `POST /booking-widget` is forbidden for direct checkout.
- `directBookable=false`, `supplierConfirmationRequired=true`, route `REQUEST` / offerte until a separate approved governance task changes this.

## Review Heuristics For This Repo
- Customer-visible prices must always match WooCommerce taxed totals.
- The planner may estimate, preview, or aggregate, but it must not become the final source of monetary truth.
- Backend code must be able to reproduce totals without trusting browser state.
- Booking logic must be deterministic and traceable from the saved inputs.
- If you cannot explain where a total came from, treat that as a defect.
- If a PR introduces a new price source, a new fallback pricing branch, or a new place where totals are recomputed differently, that is likely a `P1`.
- If a PR changes both JS and PHP pricing behavior, verify they still agree on participants, quantities, bundles, timing, and segment totals.

## Testing Expectations
When a PR touches the critical flow, expect regression coverage or explicit manual verification.

Useful existing checks:
- `vendor/bin/phpunit --configuration tests/phpunit.xml.dist`
- `composer test`
- `pwsh -File scripts/run-quality-checks.ps1`
- `npx playwright test tests/e2e`

Current automated coverage is limited and does not fully protect pricing/cart/checkout/order integrity. Missing tests are therefore meaningful review findings when a PR changes:
- pricing calculations
- VAT behavior
- cart item data propagation
- `woocommerce_before_calculate_totals`
- checkout or order item meta handling
- REST or AJAX authorization

## Review Output Style
- Be concise.
- Be direct.
- Findings first, ordered by severity.
- Explain why the issue matters in business terms, not only code terms.
- Propose the safest fix path.
- Avoid nitpicks unless they affect reliability, security, compatibility, or maintainability.
- If no findings are discovered, say so explicitly and mention any residual risk or testing gap.

## Approval Standard
Do not approve code that introduces:
- unclear price sources
- ex-VAT leakage to customer-visible surfaces
- hidden calculations
- planner/cart/order mismatches
- non-reproducible totals
- weak authorization boundaries
- missing validation on booking-critical inputs
- destructive template or hook changes that can break Elementor or Theme Builder integrations
