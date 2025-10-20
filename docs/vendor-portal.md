# Vendor Portal Sub-Plugin

The Vendor Portal module surfaces a lightweight dashboard for partner vendors to review upcoming bookings and financial performance.

## Features

- REST endpoints under `bsp/v1/vendor-portal` for login, dashboard retrieval and logout.
- Token-based session handling with filterable access-key validation (`sbdp/vendor_portal/validate_key`).
- Dashboard aggregates bookings created via the Booking module, including counts and revenue breakdowns.
- WordPress shortcode `[bsp_vendor_portal]` renders the login form and dashboard container.

## Usage

1. Ensure the Booking module records vendor identifiers (`vendor_id`) when creating bookings.
2. Place the `[bsp_vendor_portal]` shortcode on a WordPress page.
3. Vendors authenticate with their numeric vendor ID and access key. The default key is `demo`; customize via the `sbdp/vendor_portal/validate_key` filter.
4. After login, the front-end script fetches `/bsp/v1/vendor-portal/dashboard?token=<token>` to populate schedule and finance panels.

## REST Endpoints

| Method | Route                          | Description                         |
| ------ | ------------------------------ | ----------------------------------- |
| POST   | `/bsp/v1/vendor-portal/login`  | Validates credentials and returns a token. |
| GET    | `/bsp/v1/vendor-portal/dashboard` | Returns aggregated schedule and financial data. |
| POST   | `/bsp/v1/vendor-portal/logout` | Invalidates the active token. |

## Assets

The module registers `sbdp-vendor-portal` scripts and styles. They are only enqueued when the shortcode renders and include localized strings plus REST base URL metadata.
