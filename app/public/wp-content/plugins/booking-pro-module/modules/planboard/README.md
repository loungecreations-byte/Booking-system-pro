# Planboard v2 (UI shell)

Planboard v2 is a thin UI module that relies on the Planner Planboard API layer for all domain logic.

## Enable

Set a feature flag to keep v1 and v2 side-by-side:

```php
define('SBDP_PLANBOARD_V2', true);
```

Or filter it:

```php
add_filter('bsp/planboard/v2_enabled', '__return_true');
```

## Admin page

- Menu: `Planboard v2`
- Slug: `sbdp_planboard_v2`
- Assets: `modules/planboard/assets/js/planboard-v2.js`, `modules/planboard/assets/css/planboard-v2.css`

## REST base

`/wp-json/bsp/v2/planboard`

See `modules/planner/README-planboard.md` for endpoint details.

