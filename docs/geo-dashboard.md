# Geo Dashboard Module

The Geo Dashboard gives administrators a clustered map overview of vendors and bookings, with flexible filters for date range and status.

## Features

- Admin menu entry "Geo Dashboard" (capability `manage_options`).
- Leaflet + MarkerCluster map that groups vendors and bookings as zoomable clusters.
- Filters for vendor status, booking status, travel radius and optional start/end date.
- REST endpoint `/bsp/v1/geodashboard` returning combined vendor/booking geo data tailored to filters.

## Data Sources

- Vendors pulled from `BSP\Sales\Vendors\VendorService::list()`; expects `metadata.location => ['lat' => ..., 'lng' => ...]` for mapping.
- Bookings sourced from `BSP\Bookings\Service\BookingService::getBookings()`; filterable by status and ISO date range. Bookings may provide a `location` array with coordinates.

## Extending

- Override clustering behaviour by dequeuing `sbdp-geodashboard` and enqueueing a custom script, or by hooking filters to modify the REST response returned by `GeoDataProvider`.
- Additional filters (e.g. channel, vendor segment) can be added by extending the filter toolbar markup and appending query parameters in the front-end script.
