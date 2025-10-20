# Notifications Module

The Notifications module delivers customer- and staff-facing updates through:

- **Settings ▸ Booking Pro**: toggle outbound notifications via the `bsp_notifications_enabled` option.
- **REST endpoint**: `GET /bsp/v1/notifications` (public, read-only) returns `{ ok: true, timestamp, data[] }` for lightweight health checks.
- **Shortcode**: `[booking_notifications]` renders a responsive list (`aria-live="polite"`) using `assets/notifications/shortcode.css`.

## Admin Configuration

1. Go to *Settings ▸ Booking Pro*.
2. Enable or disable notifications with the “Send notifications to guests and staff” checkbox.
3. Changes are saved via the standard WordPress Settings API.

## Shortcode Usage

```
[booking_notifications]
```

The shortcode enqueues the notifications stylesheet automatically and displays a list of sample notices when the module is enabled.

## REST Health Check

```
curl https://your-site.local/wp-json/bsp/v1/notifications
```

Use this endpoint in smoke tests or monitoring jobs to verify that the module is registered correctly.
