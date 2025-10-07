# Booking Sales Module

## Overview
This module provides pricing and channel management capabilities for Booking System Pro:

- Dynamic pricing with the `BSP\Sales\Pricing\YieldEngine`
- REST APIs for quotes, yield rules, and price logs
- Channel manager with sync workflows and CLI support
- Scheduled background jobs (Action Scheduler)
- Admin interface under “Sales Suite”

## Installation
The module is autoloaded via Composer (namespace `BSP\Sales`). Tables are created on plugin activation:

- `bsp_products`
- `bsp_yield_rules`
- `bsp_price_log`
- `bsp_channels`

Administrators receive the `manage_bsp_sales` capability.

## REST API
Base namespace: `bsp/v1`

- `POST /pricing/quote` – calculate a quoted price
- `GET|POST /yield/rules` – list or create yield rules
- `GET /yield/log` – view price adjustments
- `GET /channels` – list channels
- `POST /channels/sync` – sync one or more channels

Requests require capability `manage_bsp_sales` (falls back to `manage_woocommerce`).

## CLI Commands
```
wp bsp-sales yield run
wp bsp-sales channels sync [--channel=<id|all>]
wp bsp-sales analytics report --range=<week|month>
```

## Scheduling
Action Scheduler queues:
- Nightly yield cache rebuild (`bsp_sales/nightly_yield`)
- Hourly channel synchronisation (`bsp_sales/hourly_channel_sync`)

## Testing
Unit test stubs are available under `tests/Unit/`.

## Admin
The “Sales Suite” admin menu exposes Pricing & Yield and Channels pages. Assets can be extended via `assets/js/bsp-sales-admin.js`.
