# ============================================================================
# FILE: bookingboard.md
# DESCRIPTION: Rebuild Booking Board operational dashboard (admin)
# ============================================================================

task: rebuild-booking-board
output-policy: [verbose, color, fail-fast]

steps:

# 1?? Bootstrap admin screen & enqueue
- name: "Register Booking Board screen and capability"
  run: >
    agent.run("admin.screen.register", {
      "type": "submenu",
      "parent_slug": "sbdp_dashboard",
      "slug": "sbdp_booking_board",
      "page_title": "Booking Board",
      "menu_title": "Booking Board",
      "capability": "sbdp_manage_bookings",
      "render_callback": "\\SBDP_Booking_Board_Page::render",
      "enqueue_callback": "\\SBDP_Booking_Board_Page::enqueue_assets"
    })

- name: "Implement Booking Board page presenter"
  run: >
    agent.run("php.write_class", {
      "file": "includes/class-sbdp-booking-board-page.php",
      "class": "SBDP_Booking_Board_Page",
      "responsibilities": [
        "Register admin hooks on admin_menu, current_screen, and admin_enqueue_scripts",
        "Render wrapper div with data attributes for hydration and a fallback message when JS disabled",
        "Localize booking board config (restUrl, nonce, defaultFilters, statusLabels) via wp_localize_script using text-domain sbdp",
        "Enqueue assets booking-board.css and booking-board.js only on sbdp_booking_board screen",
        "Reuse shared admin header partial for consistency with planner and vendor portal"
      ]
    })

# 2?? Data services: slice bookings for lanes & metrics
- name: "Add Booking Board query service"
  run: >
    agent.run("php.write_class", {
      "file": "includes/class-sbdp-booking-board-query.php",
      "class": "SBDP_Booking_Board_Query",
      "dependencies": [
        "\\SBDP_Booking_Repository",
        "\\SBDP_Pricing_Service",
        "\\SBDP_Channel_Service"
      ],
      "responsibilities": [
        "Fetch booking data with filters (status[], date_range, location, channel, product_type, outlet_id, assigned_agent)",
        "Normalize bookings with denormalized totals, payment state, guest counts, and latest activity timestamp",
        "Split bookings into lanes ordered by business priority: requested, pending_payment, confirmed, in_progress, completed, cancelled, failed, refunded",
        "Attach per-booking actions matrix (confirm, mark_paid, send_payment_link, log_note, email_customer) respecting capability checks",
        "Expose summary counts and revenue per lane for header metrics"
      ],
      "methods": [
        "get_board_snapshot(array $args): array",
        "get_lane_counts(array $args): array",
        "get_available_filters(): array"
      ],
      "caching": {
        "group": "sbdp_booking_board",
        "ttl": 15,
        "invalidate_on": [
          "bpm.booking.created",
          "bpm.booking.updated",
          "bpm.payment.paid",
          "sbdp/booking_board/invalidate_cache"
        ]
      }
    })

- name: "Create Booking Board metrics and audit helper"
  run: >
    agent.run("php.write_class", {
      "file": "includes/class-sbdp-booking-board-metrics.php",
      "class": "SBDP_Booking_Board_Metrics",
      "responsibilities": [
        "Aggregate totals for today, next_7_days, overdue_payments, check_ins_due",
        "Format amounts via wp_kses post-processing and i18n aware currency helper",
        "Persist updates into audit log using \\SBDP_Audit_Trail_Service with context booking_board",
        "Emit do_action hooks when lane counts cross thresholds (e.g., pending_payment > 10)",
        "Support calculate_trends(array $snapshot, \\DateTimeInterface $start, \\DateTimeInterface $end)"
      ]
    })

# 3?? REST API contract
- name: "Expose Booking Board routes under sbdp/v1"
  run: >
    agent.run("rest.routes.register", {
      "namespace": "sbdp/v1",
      "routes": [
        {
          "method": "GET",
          "path": "/booking-board",
          "callback": "\\SBDP_Booking_Board_Controller::get_board",
          "permission_callback": "\\SBDP_Booking_Board_Controller::can_view"
        },
        {
          "method": "GET",
          "path": "/booking-board/filters",
          "callback": "\\SBDP_Booking_Board_Controller::get_filters",
          "permission_callback": "\\SBDP_Booking_Board_Controller::can_view"
        },
        {
          "method": "POST",
          "path": "/booking-board/(?P<booking_id>\\d+)/status",
          "callback": "\\SBDP_Booking_Board_Controller::update_status",
          "permission_callback": "\\SBDP_Booking_Board_Controller::can_manage"
        },
        {
          "method": "POST",
          "path": "/booking-board/(?P<booking_id>\\d+)/note",
          "callback": "\\SBDP_Booking_Board_Controller::add_note",
          "permission_callback": "\\SBDP_Booking_Board_Controller::can_manage"
        },
        {
          "method": "POST",
          "path": "/booking-board/bulk/assign",
          "callback": "\\SBDP_Booking_Board_Controller::bulk_assign",
          "permission_callback": "\\SBDP_Booking_Board_Controller::can_manage"
        }
      ]
    })

- name: "Implement Booking Board controller"
  run: >
    agent.run("php.write_class", {
      "file": "includes/class-sbdp-booking-board-controller.php",
      "class": "SBDP_Booking_Board_Controller",
      "responsibilities": [
        "Bridge REST requests to SBDP_Booking_Board_Query and SBDP_Booking_Board_Metrics",
        "Guard capability checks: view requires sbdp_manage_bookings, manage requires sbdp_manage_bookings + sbdp_manage_financials when touching payments",
        "Validate and sanitize request params (statuses array, YYYY-MM-DD date filters, integers)",
        "Wrap mutations in database transactions when available and record audit log entries",
        "Return structured payload with lanes, metrics, filters, pagination cursors, and refreshed nonce"
      ],
      "error_codes": {
        "invalid_filters": "rest_invalid_param",
        "status_conflict": "sbdp_booking_board_status_conflict"
      }
    })

# 4?? Front-end data store & polling
- name: "Create booking-board data store"
  run: >
    agent.run("frontend.store.create", {
      "file": "assets/js/admin/booking-board/store.js",
      "type": "vanilla-js",
      "exports": [
        "bootstrapBoard",
        "fetchBoard",
        "updateStatus",
        "submitNote",
        "bulkAssign",
        "setFilter",
        "setSearch",
        "setSort",
        "subscribe"
      ],
      "state": [
        "lanes",
        "metrics",
        "filters",
        "pagination",
        "selectedBookings",
        "isLoading",
        "error"
      ],
      "fetches": [
        "/booking-board",
        "/booking-board/filters"
      ],
      "polling": {
        "interval": 20000,
        "refresh_on_focus": true,
        "refresh_on_webhook": "sbdp.booking_board.refresh"
      }
    })

- name: "Create Booking Board API helper"
  run: >
    agent.run("frontend.module.create", {
      "file": "assets/js/admin/booking-board/api.js",
      "exports": [
        "getBoard",
        "getFilters",
        "postStatus",
        "postNote",
        "postAssign"
      ],
      "features": [
        "Include X-WP-Nonce header from localized data",
        "Serialize filters into query string with multi-value support",
        "Surface error messages via __() with domain sbdp",
        "Handle 409 conflict by returning structured response for optimistic UI rollback"
      ]
    })

# 5?? Admin UI & interactions
- name: "Build Booking Board layout"
  run: >
    agent.run("frontend.admin.create", {
      "file": "assets/js/admin/booking-board/index.js",
      "template": "includes/admin/viwaews/booking-board.php",
      "styles": "assets/css/admin/booking-board.css",
      "features": [
        "Render multi-column kanban lanes with sticky headers for each booking status",
        "Show hero metrics (Today, Next 7 days, Pending payments, Overdue check-ins)",
        "Provide filter drawer for date range, location/outlet, channel, product type, agent",
        "Support instant search by booking id, guest name, or voucher code",
        "Enable drag-and-drop across lanes with confirmation modal and optimistic update",
        "Surface inline actions: view booking, open customer contact, send payment link, mark paid, add note",
        "Display activity timeline per booking with audit entries and partner sync status",
        "Offer export CSV and print agenda for selected filters"
      ],
      "accessibility": [
        "Ensure focus order supports keyboard drag alternative (Move to lane dropdown)",
        "Announce lane changes via aria-live polite region",
        "Respect prefers-reduced-motion for animations"
      ]
    })

- name: "Wire UX details"
  run: >
    agent.run("frontend.behaviour.add", {
      "file": "assets/js/admin/booking-board/interactions.js",
      "behaviours": [
        "Queue toast notifications for success/error using existing admin notifier",
        "Throttle bulk action submissions and show progress indicator",
        "Remember last used filters per user via localStorage key sbdpBookingBoardFilters",
        "Highlight bookings with departures in < 2 hours and pending payment in header",
        "Trigger planner deep-link (compose_booking) when clicking 'Plan follow-up'"
      ]
    })

# 6?? Automation, notifications, audit
- name: "Hook booking updates into notifications"
  run: >
    agent.run("module.hook", {
      "file": "includes/class-sbdp-booking-board-hooks.php",
      "class": "SBDP_Booking_Board_Hooks",
      "hooks": [
        {
          "action": "bpm.booking.updated",
          "priority": 15,
          "callback": "maybe_notify_finance",
          "description": "Send finance notification when payment status toggles via booking board"
        },
        {
          "action": "bpm.booking.status.changed",
          "priority": 12,
          "callback": "log_lane_move",
          "description": "Append audit trail entry with previous/new status, agent, and channel"
        },
        {
          "action": "bpm.booking.note.created",
          "priority": 10,
          "callback": "broadcast_note",
          "description": "Notify assigned vendor when note marked notify_vendor"
        }
      ],
      "filters": [
        {
          "tag": "sbdp/booking_board/default_filters",
          "callback": "filter_default_filters"
        }
      ]
    })

- name: "Schedule stale lane watcher"
  run: >
    agent.run("cron.register", {
      "hook": "sbdp_booking_board_cron_check_stale",
      "interval": "hourly",
      "callback": "\\SBDP_Booking_Board_Metrics::flag_stale_pending",
      "description": "Escalate bookings that remain in requested or pending_payment beyond SLA"
    })

# 7?? Docs, i18n, tests
- name: "Document Booking Board usage"
  run: >
    agent.run("docs.update", {
      "files": {
        "docs/booking-board.md": [
          "Explain lane definitions, SLA pointers, and available filters",
          "List REST endpoints with sample payloads and permission requirements",
          "Outline notification escalation rules and audit logging behaviour"
        ],
        "CHANGELOG.md": [
          "Add entry describing rebuilt Booking Board, new REST contract, and UX improvements"
        ]
      }
    })

- name: "Add automated coverage"
  run: >
    agent.run("tests.add", {
      "unit": [
        "tests/Unit/BookingBoard/BookingBoardQueryTest.php",
        "tests/Unit/BookingBoard/BookingBoardControllerTest.php"
      ],
      "integration": [
        "tests/Integration/Rest/BookingBoardRestTest.php"
      ],
      "assertions": [
        "Lane ordering and counts are correct for mixed status data",
        "Status transition enforces capability and logs audit entry",
        "Filter combinations (channel + outlet + date range) return scoped results"
      ]
    })

# 8?? Validation & release checklist
- name: "Validate Booking Board rebuild"
  run: >
    agent.run("booking_core.validate", {
      "rest_routes": [
        "GET /wp-json/sbdp/v1/booking-board",
        "POST /wp-json/sbdp/v1/booking-board/<id>/status"
      ],
      "screens": [
        "booking-pro-module_page_sbdp_booking_board"
      ],
      "assets": [
        "assets/js/admin/booking-board/index.js",
        "assets/css/admin/booking-board.css"
      ],
      "qa": [
        "Verify drag-drop lane move updates status and triggers audit",
        "Send payment link from pending lane and check email log",
        "Confirm filters persist per user and export respects scope",
        "Spot check requested lane load with demo seed data command"
      ]
    })

- name: "? Booking Board ready for ops"
  run: >
    log.success("?? Booking Board rebuilt: faster triage, richer metrics, safer audit trail.")
