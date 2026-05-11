## 2025-11-03 Productplanner Light v2
- Rebuilt planner timeline options from availability windows and highlighted limited/full slots in the UI.
- Slimmed the summary card, added the "Open de planner..." teaser, and aligned CTA ordering for mobile/desktop.
- Removed the deprecated queue booking selector from `product-booking.js` to keep direct booking wiring clean.
- Added a `/plan-je-dag/` permalink fallback and expanded planner telemetry for config/timeline updates.

## 2025-10-15 Weekend Pipeline
- Refreshed Composer autoloader.
- Added minified planner assets (dist/bsp.min.css, dist/bsp.min.js).
- PHPUnit run completed (1 skipped due to missing Brain Monkey helpers).
- Created weekend distribution archive.

## 2025-10-15 Vendor Portal
- Added Vendor Portal module with REST-driven dashboard and shortcode.
- Introduced vendor authentication service with token sessions.
- Added planner/finance aggregation via VendorDashboardService.
- Registered module in bootstrap and documented usage (docs/vendor-portal.md).

## 2025-10-15 Geo Dashboard
- Introduced GeoDashboard module with admin map view and filters.
- Added REST endpoint /bsp/v1/geodashboard delivering vendor/booking geo data.
- Included Leaflet-based assets and documentation (docs/geo-dashboard.md).

