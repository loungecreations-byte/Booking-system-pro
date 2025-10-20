# Booking System Pro (Core + 4 Modules)

**Core**: bootstrapping, module registry, logger.

**Modules**
- **Commerce**: processOrder, calculatePrice, applyCoupons, reserveInventory, saveOrderMeta, getOrderStatus. REST: `/commerce/calc-price`, `/commerce/process-order`
- **Planner**: generateSchedule, hasOverlap, availableSlots, assignResource, moveBooking, validateBooking. REST: `/planner/schedule`, `/planner/availability`
- **Sales**: calculateRevenue, topProducts, conversionRate, buildSalesFeed, runPromotionEngine, cohortRevenue. REST: `/sales/revenue`, `/sales/top-products`
- **Intelligence**: analyzeTrends, detectAnomalies, forecastDemand, recommendUpsell, computeKPIs, segmentCustomers. REST: `/intel/trends`, `/intel/forecast`

## Install
```
composer dump-autoload
```

## WordPress
Activeer plugin `booking-system-pro.php`. REST base: `/wp-json/bsp/v1/...`

## Quick checks (WP-CLI curl)
- `POST /wp-json/bsp/v1/commerce/calc-price` body: `{"base":100,"rules":[{"type":"percent","value":10}]}`
- `POST /wp-json/bsp/v1/planner/availability` body: `{"all":["09:00","10:00"],"booked":["10:00"]}`
