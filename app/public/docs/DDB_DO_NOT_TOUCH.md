# DDB Do Not Touch

> **Authority document** — Governance truth. Do not edit without an approved governance task.

## Purpose

Protected zones. Modifications to these areas require an approved governance task and explicit sign-off.

## Protected Zones

### OMDB truth
- `_omdb_*` post meta fields
- OMDB semantic taxonomy terms
- OMDB template rendering pipeline

**Block if:** Any agent or developer directly writes OMDB fields outside an approved OMDB update task.

### Woo commerce truth
- WooCommerce product price fields (`_price`, `_regular_price`, `_sale_price`)
- WooCommerce order status transitions
- WooCommerce tax configuration
- WooCommerce payment gateway configuration

**Block if:** Planner, booking runtime, or frontend code directly sets Woo price/tax/order truth.

### Canonical shell
- `header.php`, `footer.php` in the active theme
- The `ddb-shell` wrapper class structure
- Admin shell layout (`bsp-admin-shell`)

**Block if:** Any page-local CSS or inline style changes the shell layout.

### Planner continuity
- `sbdp_planner_state` session/cookie structure
- Planner handoff payload fields

**Block if:** Any non-planner code writes to or interprets planner state.

### Booking truth runtime
- `BookingTruthRuntimeService` capability resolution
- `BookingModeService` mode resolution
- Product 115 routing: always `supplier_confirmation`, never `direct`

**Block if:** Any code sets `directBookable: true` for product 115.
