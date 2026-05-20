# DDB Shell Rules

> **Authority document** — Governance truth. Do not edit without an approved governance task.

## Purpose

Canonical header, main, and footer law. Defines what constitutes the DDB shell and how it must be applied.

## Public Shell Law

Every public-facing page renders inside:

```html
<body class="ddb-shell [page-family-modifier]">
  <header class="ddb-shell__header"> <!-- canonical header --> </header>
  <main class="ddb-shell__main"> <!-- page content --> </main>
  <footer class="ddb-shell__footer"> <!-- canonical footer --> </footer>
</body>
```

## Commerce Shell Overrides

WooCommerce pages use surface-specific shell classes inside the canonical shell:

| Page | Required class | Template location |
|------|---------------|-------------------|
| Cart | `ddb-cart-shell` | `woocommerce/cart/cart.php` |
| Checkout | `ddb-commerce-shell` | `woocommerce/checkout/form-checkout.php` |
| Thank-you | `ddb-order-received-layout` | `woocommerce/checkout/thankyou.php` |
| Account | `ddb-account-shell` | `woocommerce/myaccount/my-account.php` |
| Dashboard | `ddb_account_hub` | `woocommerce/myaccount/dashboard.php` |
| Order detail | `ui-card` | `woocommerce/order/order-details.php` |

## Admin Shell Law

All admin pages rendered by the booking-pro-module plugin carry `bsp-admin-shell` as the root container class.

## Prohibited Patterns

- Page-local `<style>` blocks that override shell layout
- Inline `style=` on shell structural elements
- A second `<header>` or `<footer>` element rendered outside the canonical shell
- Any page that wraps its content outside `ddb-shell__main`

## Drift Detection

The governance cockpit (`sbdp_design_backend`) checks all canonical WooCommerce template overrides for shell markers. A missing or wrong marker triggers Shell=WARN.
