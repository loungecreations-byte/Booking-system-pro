<?php

declare(strict_types=1);

namespace BSPModule\Core\Rest;

use SBDP\Pricing\PricingService;
use BSP\Bookings\Service\BookingService;
use WC_Cart;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
use BSPModule\Core\Product\AvailabilityRules;
use BSPModule\Core\Admin\AdminMenu;
use BSPModule\Core\Product\ProductMeta;
use BSPModule\Core\Resource\ResourceCalendar;
use BSPModule\Core\Rest\ResourceCalendarController;
use BSPModule\Core\Services\AvailabilityExecutionService;
use BSPModule\Core\Services\AvailabilityProjectionService;
use BSPModule\Core\Services\BookingTruthRuntimeService;
use WP_Error;
use WP_Query;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use DateTimeImmutable;
use Exception;
use wpdb;

final class RestService {

	public const PUBLIC_NONCE_ACTION = 'sbdp_public_rest';

	/**
	 * Default rate limit window in seconds.
	 */
	private const RATE_LIMIT_WINDOW = 60;

	/**
	 * Default number of requests allowed per window.
	 */
	private const RATE_LIMIT_MAX_REQUESTS = 30;

	/**
	 * Transient prefix used for rate limit tracking buckets.
	 */
	private const RATE_LIMIT_TRANSIENT_PREFIX = 'sbdp_rl_';

	/**
	 * Transient storage key used for service listings.
	 */
	private const SERVICES_CACHE_KEY = 'sbdp_services_cache';

	/**
	 * Default TTL (in seconds) for the services cache.
	 */
	private const SERVICES_CACHE_TTL = 300;

	/**
	 * Cache for table existence lookups during a request.
	 *
	 * @var array<string, bool>
	 */
	private static $table_exists_cache = array();

	/**
	 * Cache of table columns keyed by table name.
	 *
	 * @var array<string, array<int, string>>
	 */
	private static $table_columns_cache = array();

	public static function init() {
		add_action( 'rest_api_init', 'BSPModule\\Core\\Rest\\RestService::routes' );
		add_action( 'save_post_product', 'BSPModule\\Core\\Rest\\RestService::handle_product_saved', 10, 2 );
		add_action( 'deleted_post', 'BSPModule\\Core\\Rest\\RestService::handle_deleted_post' );
		add_action( 'set_object_terms', 'BSPModule\\Core\\Rest\\RestService::handle_terms_set', 10, 6 );
	}

	public static function routes() {
		register_rest_route(
			'sbdp/v1',
			'/services',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'authorize_service_listing' ),
				'callback'            => array( __CLASS__, 'get_services' ),
			)
		);

		register_rest_route(
			'sbdp/v1',
			'/compose_booking',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'authorize_compose_booking' ),
				'callback'            => array( __CLASS__, 'compose_booking' ),
			)
		);

		// Admin: confirm availability and send payment link to customer.
		register_rest_route(
			'sbdp/v1',
			'/booking/(?P<id>[\d]+)/dispatch_payment',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'can_manage_woocommerce' ),
				'callback'            => array( __CLASS__, 'dispatch_booking_payment' ),
				'args'                => array(
					'id'    => array( 'required' => true, 'sanitize_callback' => 'absint' ),
					'force' => array( 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ),
				),
			)
		);

		register_rest_route(
			'sbdp/v1',
			'/availability/rules',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'can_manage_woocommerce' ),
				'callback'            => array( __CLASS__, 'get_rules' ),
			)
		);

		register_rest_route(
			'sbdp/v1',
			'/availability/rules',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'can_manage_woocommerce' ),
				'callback'            => array( __CLASS__, 'save_rules' ),
			)
		);

		register_rest_route(
			'sbdp/v1',
			'/availability/preview',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'authorize_public_availability' ),
				'callback'            => array( __CLASS__, 'preview_availability' ),
			)
		);

		register_rest_route(
			'sbdp/v1',
			'/availability/plan',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'authorize_public_availability' ),
				'callback'            => array( __CLASS__, 'plan_availability' ),
			)
		);
		register_rest_route(
			'sbdp/v1',
			'/availability/slots',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'authorize_public_availability' ),
				'callback'            => array( __CLASS__, 'availability_slots' ),
			)
		);

		register_rest_route(
			'sbdp/v1',
			'/resources',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'can_manage_woocommerce' ),
				'callback'            => array( __CLASS__, 'get_resources' ),
			)
		);

		register_rest_route(
			'sbdp/v1',
			'/pricing/rules',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'can_manage_woocommerce' ),
				'callback'            => array( __CLASS__, 'get_pricing_rules' ),
			)
		);

		register_rest_route(
			'sbdp/v1',
			'/pricing/rules',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'can_manage_woocommerce' ),
				'callback'            => array( __CLASS__, 'save_pricing_rules' ),
			)
		);

		register_rest_route(
			'sbdp/v1',
			'/pricing/preview',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'authorize_pricing_preview' ),
				'callback'            => array( __CLASS__, 'preview_pricing' ),
			)
		);

		register_rest_route(
			'sbdp/v1',
			'/schedule/overview',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'can_manage_woocommerce' ),
				'callback'            => array( __CLASS__, 'get_schedule_overview' ),
			)
		);
		register_rest_route(
			'sbdp/v1',
			'/dashboard/metrics',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'can_manage_woocommerce' ),
				'callback'            => array( __CLASS__, 'get_dashboard_metrics' ),
			)
		);

		register_rest_route(
			'sbdp/v1',
			'/dashboard/export',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'can_manage_woocommerce' ),
				'callback'            => array( __CLASS__, 'export_dashboard' ),
				'args'                => array(
					'range' => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'start' => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'end'   => array( 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		register_rest_route(
			'sbdp/v1',
			'/dashboard/availability',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'can_manage_woocommerce' ),
				'callback'            => array( __CLASS__, 'get_dashboard_availability' ),
				'args'                => array(
					'start'       => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'end'         => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'resource_id' => array( 'sanitize_callback' => 'absint' ),
				),
			)
		);
		$calendar_controller = new ResourceCalendarController();
		$calendar_controller->register_routes();
	}

	public static function can_manage_woocommerce(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * POST sbdp/v1/booking/{id}/dispatch_payment
	 *
	 * Admin action: confirm availability and send payment link to customer.
	 * Wraps BookingService::dispatchInvoice which syncs/creates the WC order,
	 * generates a Mollie or WC checkout-payment URL, and emails the customer.
	 *
	 * Request body (optional):
	 *   force: bool  — resend even if a payment request was already sent
	 */
	public static function dispatch_booking_payment( WP_REST_Request $request ) {
		$booking_id = (int) $request->get_param( 'id' );
		$force      = (bool) $request->get_param( 'force' );

		if ( $booking_id <= 0 ) {
			return new WP_Error( 'sbdp_invalid_booking', __( 'Ongeldig boekingsnummer.', 'sbdp' ), array( 'status' => 400 ) );
		}

		if ( ! class_exists( \BSP\Bookings\Service\BookingService::class ) ) {
			return new WP_Error( 'sbdp_unavailable', __( 'BookingService niet beschikbaar.', 'sbdp' ), array( 'status' => 500 ) );
		}

		try {
			$updated = BookingService::dispatchInvoice( $booking_id, $force );
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error( 'sbdp_booking_not_found', $e->getMessage(), array( 'status' => 404 ) );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'sbdp_dispatch_failed', $e->getMessage(), array( 'status' => 500 ) );
		}

		return rest_ensure_response(
			array(
				'ok'      => true,
				'booking' => $updated,
			)
		);
	}

	public static function get_dashboard_metrics( WP_REST_Request $request ) {
		$range_param   = $request->get_param( 'range' );
		$revenue_days  = self::normalise_days_param( $request->get_param( 'revenue_days' ) ?? $range_param, 7 );
		$upcoming_days = self::normalise_days_param( $request->get_param( 'upcoming_days' ), 14 );

		$metrics = AdminMenu::collect_dashboard_metrics( $revenue_days, $upcoming_days );

		return rest_ensure_response( $metrics );
	}

	public static function export_dashboard( WP_REST_Request $request ) {
		$bounds = self::resolve_bounds_from_request( $request, 7 );
		$rows   = self::fetch_bookings_for_range( $bounds['start'], $bounds['end'] );

		$lines = array(
			'"Booking ID","Order ID","Status","Start","End","Total","Currency","Channel"',
		);

		foreach ( $rows as $row ) {
			$lines[] = implode(
				',',
				array(
					self::csv_value( (string) $row['id'] ),
					self::csv_value( (string) $row['order_id'] ),
					self::csv_value( (string) $row['status'] ),
					self::csv_value( $row['start'] ),
					self::csv_value( $row['end'] ),
					self::csv_value( self::format_decimal( (float) $row['total'] ) ),
					self::csv_value( $row['currency'] ),
					self::csv_value( $row['channel'] ),
				)
			);
		}

		$csv = implode( "\r\n", $lines ) . "\r\n";

		$response = new WP_REST_Response( $csv );
		$response->set_headers(
			array(
				'Content-Type'        => 'text/csv; charset=utf-8',
				'Content-Disposition' => 'attachment; filename=\"sbdp-dashboard-export-' . gmdate( 'Ymd-His' ) . '.csv\"',
				'Cache-Control'       => 'private, no-store, must-revalidate',
			)
		);

		return $response;
	}

	public static function get_dashboard_availability( WP_REST_Request $request ) {
		$start_raw = $request->get_param( 'start' );
		$end_raw   = $request->get_param( 'end' );

		if ( ! is_string( $start_raw ) || ! is_string( $end_raw ) ) {
			return new WP_Error( 'bad_request', __( 'Start- en eindparameter zijn verplicht.', 'sbdp' ), array( 'status' => 400 ) );
		}

		$start = self::normalise_to_utc( $start_raw );
		$end   = self::normalise_to_utc( $end_raw );
		if ( ! $start || ! $end || $end < $start ) {
			return new WP_Error( 'bad_request', __( 'Ongeldige periode opgegeven.', 'sbdp' ), array( 'status' => 400 ) );
		}

		$resource_id = absint( (int) $request->get_param( 'resource_id' ) );
		if ( $resource_id <= 0 ) {
			$resource_id = null;
		}

		$events = self::load_dashboard_events( $start, $end, $resource_id );

		return rest_ensure_response(
			array(
				'events' => $events,
			)
		);
	}

	private static function normalise_days_param( $value, int $default ): int {
		if ( is_numeric( $value ) ) {
			$value = (int) $value;
			return $value > 0 ? $value : $default;
		}

		if ( is_string( $value ) ) {
			$trimmed = strtolower( trim( $value ) );
			if ( preg_match( '/^(\d+)d$/', $trimmed, $matches ) ) {
				$days = (int) $matches[1];
				return $days > 0 ? $days : $default;
			}
			if ( in_array( $trimmed, array( 'week', '7', '7d' ), true ) ) {
				return 7;
			}
			if ( in_array( $trimmed, array( 'month', '30', '30d', '4w' ), true ) ) {
				return 30;
			}
			if ( in_array( $trimmed, array( 'day', '1', '1d' ), true ) ) {
				return 1;
			}
		}

		return $default;
	}

	private static function resolve_bounds_from_request( WP_REST_Request $request, int $fallback_days ): array {
		$start = $request->get_param( 'start' );
		$end   = $request->get_param( 'end' );

		if ( is_string( $start ) && is_string( $end ) ) {
			$start_utc = self::normalise_to_utc( $start );
			$end_utc   = self::normalise_to_utc( $end );

			if ( $start_utc && $end_utc && $end_utc >= $start_utc ) {
				return array( 'start' => $start_utc, 'end' => $end_utc );
			}
		}

		$days = self::normalise_days_param( $request->get_param( 'range' ), $fallback_days );
		return self::bounds_for_days( $days );
	}

	private static function bounds_for_days( int $days ): array {
		$days  = max( 1, $days );
		$end   = time();
		$start = strtotime( '-' . ( $days - 1 ) . ' days', $end );

		return array(
			'start' => gmdate( 'Y-m-d 00:00:00', $start ),
			'end'   => gmdate( 'Y-m-d 23:59:59', $end ),
		);
	}

	private static function normalise_to_utc( string $value ): ?string {
		$timestamp = strtotime( $value );
		if ( false === $timestamp ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	private static function fetch_bookings_for_range( string $start, string $end ): array {
		$bookings_table = self::get_bookings_table();
		if ( ! $bookings_table ) {
			return array();
		}

		global $wpdb;
		$start_column = self::resolve_table_column( $bookings_table, array( 'start_at', 'start_datetime' ) );
		$end_column   = self::resolve_table_column( $bookings_table, array( 'end_at', 'end_datetime' ) );
		if ( ! $start_column || ! $end_column ) {
			return array();
		}

		$meta_column = self::resolve_table_column( $bookings_table, array( 'meta_json', 'meta' ) );
		$meta_source = $meta_column ? $meta_column : 'NULL';

		$sql  = sprintf(
			"SELECT id, order_id, status, %1\$s AS start_at, %2\$s AS end_at, total, currency, %3\$s AS meta FROM {$bookings_table} WHERE %1\$s BETWEEN %%s AND %%s ORDER BY %1\$s ASC",
			$start_column,
			$end_column,
			$meta_source
		);
		$rows = $wpdb->get_results(
			$wpdb->prepare( $sql, $start, $end ),
			ARRAY_A
		) ?: array();

		$output = array();
		foreach ( $rows as $row ) {
			$meta    = self::decode_booking_meta( $row['meta'] ?? null );
			$channel = self::extract_channel_from_meta( $meta );

			$output[] = array(
				'id'       => (int) ( $row['id'] ?? 0 ),
				'order_id' => isset( $row['order_id'] ) ? (int) $row['order_id'] : 0,
				'status'   => (string) ( $row['status'] ?? '' ),
				'start'    => self::format_iso( $row['start_at'] ?? '' ),
				'end'      => self::format_iso( $row['end_at'] ?? '' ),
				'total'    => isset( $row['total'] ) ? (float) $row['total'] : 0.0,
				'currency' => (string) ( $row['currency'] ?? '' ),
				'channel'  => $channel,
			);
		}

		return $output;
	}

	private static function load_dashboard_events( string $start, string $end, ?int $resource_id ): array {
		$bookings_table    = self::get_bookings_table();
		$assignments_table = self::get_assignments_table();
		$resources_table   = self::get_resources_table();

		if ( ! $bookings_table ) {
			return array();
		}

		global $wpdb;
		$events = array();

		$booking_start_column = self::resolve_table_column( $bookings_table, array( 'start_at', 'start_datetime' ) );
		$booking_end_column   = self::resolve_table_column( $bookings_table, array( 'end_at', 'end_datetime' ) );
		if ( ! $booking_start_column || ! $booking_end_column ) {
			return array();
		}

		$meta_column            = self::resolve_table_column( $bookings_table, array( 'meta_json', 'meta' ) );
		$meta_source_with_alias = $meta_column ? 'b.' . $meta_column : 'NULL';
		$meta_source_plain      = $meta_column ? $meta_column : 'NULL';

		$rows             = array();
		$used_assignments = false;

		if ( $assignments_table ) {
			$assignment_start_column = self::resolve_table_column( $assignments_table, array( 'start_at', 'start_datetime' ) );
			$assignment_end_column   = self::resolve_table_column( $assignments_table, array( 'end_at', 'end_datetime' ) );

			if ( $assignment_start_column && $assignment_end_column ) {
				$assignment_start_sql = 'a.' . $assignment_start_column;
				$assignment_end_sql   = 'a.' . $assignment_end_column;

				$resource_join  = '';
				$resource_field = "NULL AS resource_title";
				if ( $resources_table ) {
					$resource_join  = " LEFT JOIN {$resources_table} r ON r.id = a.resource_id";
					$resource_field = 'r.title AS resource_title';
				}

				$sql = sprintf(
					"SELECT a.id, a.booking_id, a.resource_id, %1\$s AS start_at, %2\$s AS end_at, b.status, b.total, b.currency, %3\$s AS meta, %4\$s FROM {$assignments_table} a INNER JOIN {$bookings_table} b ON b.id = a.booking_id%5\$s WHERE %1\$s BETWEEN %%s AND %%s",
					$assignment_start_sql,
					$assignment_end_sql,
					$meta_source_with_alias,
					$resource_field,
					$resource_join
				);

				$params = array( $start, $end );
				if ( null !== $resource_id ) {
					$sql    .= ' AND a.resource_id = %d';
					$params[] = $resource_id;
				}

				$sql      .= ' ORDER BY ' . $assignment_start_sql . ' ASC';
				$prepared  = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $params ) );
				$rows      = $prepared ? $wpdb->get_results( $prepared, ARRAY_A ) : array();
				$used_assignments = true;
			}
		}

		if ( ! $used_assignments ) {
			$sql = sprintf(
				"SELECT id, %1\$s AS start_at, %2\$s AS end_at, status, total, currency, %3\$s AS meta, NULL AS resource_title, 0 AS resource_id FROM {$bookings_table} WHERE %1\$s BETWEEN %%s AND %%s ORDER BY %1\$s ASC",
				$booking_start_column,
				$booking_end_column,
				$meta_source_plain
			);

			$rows = $wpdb->get_results(
				$wpdb->prepare( $sql, $start, $end ),
				ARRAY_A
			) ?: array();
		}

		foreach ( $rows as $row ) {
			$status         = (string) ( $row['status'] ?? 'unknown' );
			$meta           = self::decode_booking_meta( $row['meta'] ?? null );
			$channel        = self::extract_channel_from_meta( $meta );
			$resource_title = isset( $row['resource_title'] ) && '' !== $row['resource_title'] ? (string) $row['resource_title'] : __( 'Niet toegewezen', 'sbdp' );
			$booking_id     = isset( $row['booking_id'] ) ? (int) $row['booking_id'] : (int) ( $row['id'] ?? 0 );
			$resource_ref   = isset( $row['resource_id'] ) ? (int) $row['resource_id'] : 0;

			$events[] = array(
				'id'          => isset( $row['id'] ) ? (int) $row['id'] : $booking_id,
				'title'       => $resource_title,
				'start'       => self::format_iso( $row['start_at'] ?? '' ),
				'end'         => self::format_iso( $row['end_at'] ?? '' ),
				'classNames'  => array( 'sbdp-status-' . sanitize_html_class( $status ) ),
				'extendedProps' => array(
					'bookingId' => $booking_id,
					'resourceId' => $resource_ref,
					'status'    => $status,
					'total'     => isset( $row['total'] ) ? (float) $row['total'] : 0.0,
					'currency'  => isset( $row['currency'] ) ? (string) $row['currency'] : '',
					'channel'   => $channel,
				),
			);
		}

		return $events;
	}

	private static function get_bookings_table(): ?string {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return null;
		}

		$table = $wpdb->prefix . 'sbdp_bookings';

		return self::table_exists( $table ) ? $table : null;
	}

	private static function get_assignments_table(): ?string {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return null;
		}

		$table = $wpdb->prefix . 'sbdp_assignments';

		return self::table_exists( $table ) ? $table : null;
	}

	private static function get_resources_table(): ?string {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return null;
		}

		$table = $wpdb->prefix . 'sbdp_resources';

		return self::table_exists( $table ) ? $table : null;
	}

	private static function table_exists( string $table ): bool {
		if ( isset( self::$table_exists_cache[ $table ] ) ) {
			return self::$table_exists_cache[ $table ];
		}

		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			self::$table_exists_cache[ $table ] = false;
			return false;
		}

		$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$exists = ( $result === $table );
		self::$table_exists_cache[ $table ] = $exists;

		return $exists;
	}

	/**
	 * Determine the first available column within a table from the provided candidates.
	 */
	private static function resolve_table_column( string $table, array $candidates ): ?string {
		$columns = self::get_table_columns( $table );

		foreach ( $candidates as $candidate ) {
			if ( is_string( $candidate ) && in_array( $candidate, $columns, true ) ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Retrieve and cache the column list for a table.
	 *
	 * @return array<int, string>
	 */
	private static function get_table_columns( string $table ): array {
		if ( isset( self::$table_columns_cache[ $table ] ) ) {
			return self::$table_columns_cache[ $table ];
		}

		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			self::$table_columns_cache[ $table ] = array();
			return self::$table_columns_cache[ $table ];
		}

		$results = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
		if ( ! is_array( $results ) ) {
			$results = array();
		}

		$columns = array();
		foreach ( $results as $column ) {
			if ( is_string( $column ) && '' !== $column ) {
				$columns[] = $column;
			}
		}

		self::$table_columns_cache[ $table ] = $columns;

		return $columns;
	}

	private static function decode_booking_meta( $raw ): array {
		if ( empty( $raw ) ) {
			return array();
		}

		$meta = maybe_unserialize( $raw );
		if ( is_string( $meta ) && '' !== $meta ) {
			$decoded = json_decode( $meta, true );
			if ( is_array( $decoded ) ) {
				$meta = $decoded;
			}
		}

		return is_array( $meta ) ? $meta : array();
	}

	private static function extract_channel_from_meta( array $meta ): string {
		$slug = '';
		if ( isset( $meta['channel_slug'] ) && '' !== $meta['channel_slug'] ) {
			$slug = sanitize_key( (string) $meta['channel_slug'] );
		} elseif ( isset( $meta['channel'] ) && '' !== $meta['channel'] ) {
			$slug = sanitize_key( (string) $meta['channel'] );
		} elseif ( isset( $meta['source'] ) && '' !== $meta['source'] ) {
			$slug = sanitize_key( (string) $meta['source'] );
		}

		if ( '' === $slug ) {
			$slug = 'direct';
		}

		$name = '';
		if ( isset( $meta['channel_name'] ) && '' !== $meta['channel_name'] ) {
			$name = (string) $meta['channel_name'];
		} elseif ( isset( $meta['channel_label'] ) && '' !== $meta['channel_label'] ) {
			$name = (string) $meta['channel_label'];
		} elseif ( isset( $meta['channel'] ) && '' !== $meta['channel'] ) {
			$name = (string) $meta['channel'];
		}

		if ( '' === $name ) {
			$name = __( 'Direct', 'sbdp' );
		}

		return $name;
	}

	private static function csv_value( string $value ): string {
		$value = str_replace( '"', '""', $value );

		return '"' . $value . '"';
	}

	private static function format_decimal( float $value ): string {
		return number_format( $value, 2, '.', '' );
	}

	private static function format_iso( $value ): string {
		if ( ! $value ) {
			return '';
		}

		$timestamp = strtotime( (string) $value );
		if ( false === $timestamp ) {
			return '';
		}

		return gmdate( 'c', $timestamp );
	}

	private static function check_rate_limit( WP_REST_Request $request, string $bucket ) {
		$result = apply_filters( 'sbdp_rest_rate_limit', true, $request, $bucket );

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		if ( false === $result ) {
			return new WP_Error(
				'sbdp_rate_limited',
				__( 'Te veel verzoeken. Probeer het over enkele seconden opnieuw.', 'sbdp' ),
				array( 'status' => 429 )
			);
		}

		$config       = self::resolve_rate_limit_config( $request, $bucket );
		$max_requests = $config['max_requests'];
		$window       = $config['window'];

		if ( $max_requests <= 0 ) {
			return true;
		}

		$key    = self::build_rate_limit_key( $request, $bucket );
		$record = self::get_rate_limit_record( $key );
		$now    = time();

		if ( ! is_array( $record ) || ! isset( $record['count'], $record['reset'] ) || $record['reset'] <= $now ) {
			self::store_rate_limit_record( $key, 1, $now + $window );

			return true;
		}

		if ( $record['count'] >= $max_requests ) {
			$retry_after = max( 1, $record['reset'] - $now );

			if ( ! headers_sent() ) {
				header( 'Retry-After: ' . $retry_after );
			}

			return new WP_Error(
				'sbdp_rate_limited',
				__( 'Te veel verzoeken. Probeer het over enkele seconden opnieuw.', 'sbdp' ),
				array(
					'status'      => 429,
					'retry_after' => $retry_after,
				)
			);
		}

		$record['count']++;
		self::store_rate_limit_record( $key, $record['count'], (int) $record['reset'] );

		return true;
	}

	/**
	 * Resolve the rate limit configuration for the given bucket.
	 *
	 * @return array{max_requests:int, window:int}
	 */
	private static function resolve_rate_limit_config( WP_REST_Request $request, string $bucket ): array {
		$defaults = array(
			'max_requests' => self::RATE_LIMIT_MAX_REQUESTS,
			'window'       => self::RATE_LIMIT_WINDOW,
		);

		if ( 'availability_slots' === $bucket ) {
			$defaults['max_requests'] = 120;
			$defaults['window']       = 60;
		} elseif ( 'availability_plan' === $bucket ) {
			$defaults['max_requests'] = 60;
			$defaults['window']       = 60;
		}

		$config = apply_filters( 'sbdp/rest_rate_limit/config', $defaults, $request, $bucket );

		$max_requests = isset( $config['max_requests'] ) ? (int) $config['max_requests'] : $defaults['max_requests'];
		$window       = isset( $config['window'] ) ? (int) $config['window'] : $defaults['window'];

		if ( $max_requests < 0 ) {
			$max_requests = 0;
		}

		if ( $window < 1 ) {
			$window = $defaults['window'];
		}

		return array(
			'max_requests' => $max_requests,
			'window'       => $window,
		);
	}

	/**
	 * Build a stable cache key for the current rate limit context.
	 */
	private static function build_rate_limit_key( WP_REST_Request $request, string $bucket ): string {
		$identity = self::determine_rate_limit_identity( $request );
		$method   = strtoupper( $request->get_method() );

		return self::RATE_LIMIT_TRANSIENT_PREFIX . md5( implode( '|', array( $bucket, $method, $identity ) ) );
	}

	/**
	 * Determine the rate limit identity fingerprint.
	 */
	private static function determine_rate_limit_identity( WP_REST_Request $request ): string {
		$nonce   = self::extract_public_nonce( $request ) ?? '';
		$user    = get_current_user_id();
		$user_id = $user ? (string) $user : '0';
		$ip      = self::get_remote_address();

		return implode( '|', array( $ip, $nonce, $user_id ) );
	}

	/**
	 * Resolve the best-effort remote address for the request.
	 */
	private static function get_remote_address(): string {
		$trusted_proxy = (bool) apply_filters( 'sbdp/rest/trust_forwarded_ip', false );
		$headers       = $trusted_proxy
			? array( 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR' )
			: array( 'REMOTE_ADDR' );

		foreach ( $headers as $header ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}

			$value = (string) $_SERVER[ $header ];
			if ( 'HTTP_X_FORWARDED_FOR' === $header ) {
				$forwarded = explode( ',', $value );
				$value     = trim( (string) $forwarded[0] );
			}

			$value = sanitize_text_field( $value );
			if ( '' !== $value && filter_var( $value, FILTER_VALIDATE_IP ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Retrieve the current rate limit bucket state.
	 */
	private static function get_rate_limit_record( string $key ): ?array {
		$record = get_transient( $key );

		if ( false === $record || ! is_array( $record ) ) {
			return null;
		}

		return array(
			'count' => isset( $record['count'] ) ? (int) $record['count'] : 0,
			'reset' => isset( $record['reset'] ) ? (int) $record['reset'] : 0,
		);
	}

	/**
	 * Persist the updated rate limit bucket state.
	 */
	private static function store_rate_limit_record( string $key, int $count, int $reset ): void {
		$ttl = max( 1, $reset - time() );

		set_transient(
			$key,
			array(
				'count' => $count,
				'reset' => $reset,
			),
			$ttl
		);
	}

private static function extract_public_nonce( WP_REST_Request $request ): ?string {
		$nonce = $request->get_header( 'x-sbdp-nonce' );

		if ( ! is_string( $nonce ) || '' === $nonce ) {
			$nonce = $request->get_header( 'X-WP-Nonce' );
		}

		if ( ! is_string( $nonce ) || '' === $nonce ) {
			return null;
		}

		return sanitize_text_field( $nonce );
	}

	private static function authorize_public_request( WP_REST_Request $request, string $bucket ) {
		$limit = self::check_rate_limit( $request, $bucket );
		if ( $limit instanceof WP_Error ) {
			return $limit;
		}

		$override = apply_filters( 'sbdp/public_rest/allow_request', null, $request, $bucket );
		if ( $override instanceof WP_Error ) {
			return $override;
		}

		if ( is_bool( $override ) ) {
			return $override;
		}

		$nonce = self::extract_public_nonce( $request );

		$nonce_override = apply_filters( 'sbdp/public_rest/validate_nonce', null, $nonce, $request, $bucket );
		if ( $nonce_override instanceof WP_Error ) {
			return $nonce_override;
		}

		if ( is_bool( $nonce_override ) ) {
			return $nonce_override;
		}

		$nonce_is_valid = false;
		if ( $nonce ) {
			$nonce_is_valid = wp_verify_nonce( $nonce, self::PUBLIC_NONCE_ACTION )
				|| wp_verify_nonce( $nonce, 'wp_rest' );
		}

		if ( ! $nonce_is_valid ) {
			return new WP_Error(
				'sbdp_invalid_nonce',
				__( 'Beveiligingscontrole mislukt. Vernieuw de planner en probeer opnieuw.', 'sbdp' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	public static function authorize_compose_booking( WP_REST_Request $request ) {
		return self::authorize_public_request( $request, 'compose_booking' );
	}

	public static function authorize_pricing_preview( WP_REST_Request $request ) {
		return self::authorize_public_request( $request, 'pricing_preview' );
	}

	public static function authorize_service_listing( WP_REST_Request $request ) {
		return self::authorize_public_request( $request, 'services' );
	}

	public static function authorize_public_availability( WP_REST_Request $request ) {
		return self::authorize_public_request( $request, 'availability' );
	}

	public static function get_services( WP_REST_Request $request ) {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$cached = self::get_cached_services( (string) $locale );

		if ( is_array( $cached ) ) {
			return rest_ensure_response( $cached );
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'posts_per_page' => -1,
				'tax_query'      => array(
					array(
						'taxonomy' => 'product_type',
						'field'    => 'slug',
						'terms'    => array( 'bookable_service' ),
					),
				),
			)
		);

		$services = array();
		while ( $query->have_posts() ) {
			$query->the_post();
			$pid        = get_the_ID();
			$product    = wc_get_product( $pid );
			$price      = 0.0;

			if ( $product instanceof WC_Product ) {
				$pricing = self::calculate_pricing_for_item( $product, 0, '', 1, array( 'channel' => 'services' ) );
				$price   = isset( $pricing['display_total'] ) ? (float) $pricing['display_total'] : ( function_exists( 'wc_get_price_including_tax' ) ? (float) wc_get_price_including_tax( $product, array( 'qty' => 1 ) ) : (float) $product->get_price() );
				if ( $price <= 0.0 ) {
					$price = function_exists( 'wc_get_price_including_tax' ) ? (float) wc_get_price_including_tax( $product, array( 'qty' => 1, 'price' => (float) $product->get_regular_price() ) ) : (float) $product->get_regular_price();
				}
			}

			$services[] = self::build_public_service_dto(
				array(
					'id'          => $pid,
					'name'        => get_the_title(),
					'price'       => $price,
					'duration'    => (int) ( get_post_meta( $pid, '_sbdp_duration', true ) ?: 90 ),
					'resource_id' => (int) get_post_meta( $pid, '_sbdp_resource_id', true ),
					'thumb'       => get_the_post_thumbnail_url( $pid, 'thumbnail' ),
					'excerpt'     => wp_strip_all_tags( get_the_excerpt( $pid ) ),
					'permalink'   => get_permalink( $pid ),
				)
			);
		}
		wp_reset_postdata();

		self::store_services_cache( (string) $locale, $services );

		return rest_ensure_response( $services );
	}

	private static function get_cached_services( string $locale ): ?array {
		$key = self::build_services_cache_key( $locale );

		$cached = wp_cache_get( $key, 'sbdp_core' );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : null;
		}

		$transient = get_transient( $key );
		if ( false === $transient || ! is_array( $transient ) ) {
			return null;
		}

		wp_cache_set( $key, $transient, 'sbdp_core', self::SERVICES_CACHE_TTL );

		return $transient;
	}

	private static function store_services_cache( string $locale, array $services ): void {
		$key = self::build_services_cache_key( $locale );

		wp_cache_set( $key, $services, 'sbdp_core', self::SERVICES_CACHE_TTL );
		set_transient( $key, $services, self::SERVICES_CACHE_TTL );
	}

	private static function build_services_cache_key( string $locale ): string {
		$locale = $locale !== '' ? $locale : 'default';

		return self::SERVICES_CACHE_KEY . '_' . md5( $locale );
	}

	/**
	 * @param array<string, mixed> $service
	 * @return array<string, mixed>
	 */
	private static function build_public_service_dto( array $service ): array {
		$service_id = isset( $service['id'] ) ? (int) $service['id'] : 0;

		return array(
			'id' => $service_id,
			'title' => sanitize_text_field( (string) ( $service['title'] ?? $service['name'] ?? '' ) ),
			'excerpt' => sanitize_text_field( (string) ( $service['excerpt'] ?? '' ) ),
			'duration' => isset( $service['duration'] ) ? (int) $service['duration'] : 90,
			'display_price' => isset( $service['price'] ) && is_numeric( $service['price'] ) ? round( (float) $service['price'], 2 ) : null,
			'booking_capability' => self::resolve_public_booking_capability( $service_id, $service ),
			'availability_label' => self::resolve_public_availability_label( $service_id, $service ),
			'category_public' => self::resolve_public_service_category( $service_id ),
			'image' => esc_url_raw( (string) ( $service['image'] ?? $service['thumb'] ?? '' ) ),
			'permalink' => esc_url_raw( (string) ( $service['permalink'] ?? '' ) ),
		);
	}

	/**
	 * @param array<string, mixed> $service
	 */
	private static function resolve_public_booking_capability( int $service_id, array $service ): string {
		$explicit = strtoupper( trim( (string) ( $service['booking_capability'] ?? '' ) ) );
		if ( in_array( $explicit, array( 'DIRECT', 'DIRECT_LIMITED', 'REQUEST', 'UNAVAILABLE' ), true ) ) {
			return $explicit;
		}

		if ( self::product_requires_confirmation( $service_id ) ) {
			return 'REQUEST';
		}

		return 'DIRECT_LIMITED';
	}

	/**
	 * @param array<string, mixed> $service
	 */
	private static function resolve_public_availability_label( int $service_id, array $service ): string {
		$capability = self::resolve_public_booking_capability( $service_id, $service );

		if ( 'REQUEST' === $capability ) {
			return 'Op aanvraag';
		}

		if ( 'UNAVAILABLE' === $capability ) {
			return 'Momenteel niet beschikbaar';
		}

		return 'Beschikbaarheid wordt bevestigd bij selectie';
	}

	/**
	 * @return array<string, string>|null
	 */
	private static function resolve_public_service_category( int $service_id ): ?array {
		if ( ! function_exists( 'wp_get_post_terms' ) ) {
			return null;
		}

		$terms = wp_get_post_terms( $service_id, 'product_cat' );
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return null;
		}

		foreach ( $terms as $term ) {
			if ( ! isset( $term->slug, $term->name ) ) {
				continue;
			}

			return array(
				'slug' => sanitize_title( (string) $term->slug ),
				'label' => sanitize_text_field( (string) $term->name ),
			);
		}

		return null;
	}

	private static function product_requires_confirmation( int $product_id ): bool {
		if ( $product_id <= 0 ) {
			return false;
		}

		$wc_flag = get_post_meta( $product_id, '_wc_booking_requires_confirmation', true );
		if ( 'yes' === $wc_flag || '1' === $wc_flag || 1 === $wc_flag || true === $wc_flag ) {
			return true;
		}

		$bookable = get_post_meta( $product_id, '_sbdp_bookable', true );
		if ( is_array( $bookable ) ) {
			$flag = $bookable['booking_requires_confirmation'] ?? null;
			return 'yes' === $flag || '1' === $flag || 1 === $flag || true === $flag;
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return int|WP_Error
	 */
	private static function resolve_compose_participants( array $payload ) {
		$raw = $payload['participants'] ?? null;
		if ( ! is_numeric( $raw ) ) {
			return new WP_Error( 'sbdp_missing_participants', __( 'Aantal personen ontbreekt.', 'sbdp' ), array( 'status' => 400 ) );
		}

		$participants = (int) $raw;
		if ( $participants <= 0 ) {
			return new WP_Error( 'sbdp_invalid_participants', __( 'Aantal personen is ongeldig.', 'sbdp' ), array( 'status' => 400 ) );
		}

		return $participants;
	}

	public static function compose_booking( WP_REST_Request $request ) {
		$payload      = $request->get_json_params();
		$mode         = sanitize_text_field( $payload['mode'] ?? 'pay' );
		$items        = self::sanitize_items( $payload['items'] ?? array() );
		$combi_id     = intval( $payload['combi'] ?? 0 );
		$customer     = self::normalize_compose_customer_payload( is_array( $payload ) ? $payload : array() );
		$participants = self::resolve_compose_participants( is_array( $payload ) ? $payload : array() );

		if ( is_wp_error( $participants ) ) {
			return $participants;
		}

		if ( empty( $items ) ) {
			return new WP_Error( 'sbdp_no_items', __( 'Geen geldige items ontvangen.', 'sbdp' ), array( 'status' => 400 ) );
		}

		$validation = self::validate_items( $items, $participants );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$truth_items = self::canonicalize_compose_items( $items, $participants, $mode );
		if ( is_wp_error( $truth_items ) ) {
			return $truth_items;
		}

		$pricing_context = array(
			'channel' => $mode === 'pay' ? 'compose_booking' : 'compose_request',
			'source'  => 'compose_booking',
			'mode'    => $mode,
		);

		if ( $mode === 'pay' ) {
			if ( ! function_exists( 'WC' ) ) {
				return new WP_Error( 'sbdp_no_wc', __( 'WooCommerce niet beschikbaar.', 'sbdp' ), array( 'status' => 500 ) );
			}

			self::ensure_cart_session();
			if ( ! WC()->cart ) {
				return new WP_Error( 'sbdp_no_cart', __( 'Winkelwagen kon niet worden geopend.', 'sbdp' ), array( 'status' => 500 ) );
			}

			WC()->cart->empty_cart();
			if ( function_exists( 'WC' ) && WC()->session ) {
				WC()->session->set( 'sbdp_mode', 'pay' );
				WC()->session->set( 'sbdp_itinerary', self::snapshot_itinerary( $items, $participants ) );
			}

			foreach ( $truth_items as $resolved ) {
				$item    = $resolved['item'];
				$profile = $resolved['profile'];
				$meta    = $resolved['meta'];
				$product = wc_get_product( $item['product_id'] );
				if ( ! $product ) {
					return new WP_Error( 'sbdp_invalid_product', __( 'Ongeldige productreferentie.', 'sbdp' ), array( 'status' => 400 ) );
				}

				$resource_id    = intval( $item['resource_id'] ?? get_post_meta( $item['product_id'], '_sbdp_resource_id', true ) );
				$resource_label = self::get_resource_label( $resource_id );
				$item_combi_id  = intval( $item['combi'] ?? $combi_id );
				$combi_items    = self::sanitize_compose_combi_items( $item );
				$pricing        = self::calculate_pricing_for_item( $product, $resource_id, $item['start'], $participants, $pricing_context );
				if ( $combi_items !== array() ) {
					foreach ( $combi_items as $combi_item ) {
						$pricing = self::apply_combi_adjustment( $pricing, (int) ( $combi_item['id'] ?? 0 ), $participants );
					}
				} else {
					$pricing = self::apply_combi_adjustment( $pricing, $item_combi_id, $participants );
				}
				$combi_label    = $pricing['combi']['label'] ?? '';
				if ( $combi_label === '' && $combi_items !== array() ) {
					$combi_label = implode(
						', ',
						array_values(
							array_filter(
								array_map(
									static fn( array $entry ): string => isset( $entry['label'] ) ? (string) $entry['label'] : '',
									$combi_items
								)
							)
						)
					);
				}
				$summary_date = substr( (string) $meta['sbdp_start'], 0, 10 );
				$summary_time = substr( (string) $meta['sbdp_start'], 11, 5 );
				$summary_end  = substr( (string) $meta['sbdp_end'], 11, 5 );

				$cart_key = WC()->cart->add_to_cart(
					$item['product_id'],
					$participants,
					0,
					array(),
					array(
						'sbdp_start'              => $meta['sbdp_start'],
						'sbdp_end'                => $meta['sbdp_end'],
						'sbdp_participants'       => $meta['sbdp_participants'],
						'sbdp_canonical_participants' => $meta['sbdp_canonical_participants'],
						'sbdp_resource_id'        => $meta['sbdp_resource_id'],
						'sbdp_route_intent'       => $meta['sbdp_route_intent'],
						'sbdp_booking_capability' => $meta['sbdp_booking_capability'],
						'sbdp_pricing'            => $pricing,
						'sbdp_summary'            => array(
							'date'         => $summary_date,
							'time'         => $summary_time,
							'participants' => $meta['sbdp_canonical_participants'],
							'resource_id'  => $meta['sbdp_resource_id'],
							'start'        => $meta['sbdp_start'],
							'end'          => $summary_end,
							'pricing'      => $pricing,
							'combi'        => $item_combi_id,
							'combi_label'  => $combi_label,
							'combi_multi'  => $combi_items,
						),
						'sbdp_meta' => array(
							'sbdp_start'          => $meta['sbdp_start'],
							'sbdp_end'            => $meta['sbdp_end'],
							'sbdp_participants'   => $meta['sbdp_participants'],
							'sbdp_canonical_participants' => $meta['sbdp_canonical_participants'],
							'sbdp_resource_id'    => $meta['sbdp_resource_id'],
							'sbdp_resource_label' => $resource_label,
							'sbdp_route_intent'   => $meta['sbdp_route_intent'],
							'sbdp_booking_capability' => $meta['sbdp_booking_capability'],
							'sbdp_combi'          => $item_combi_id,
							'sbdp_combi_label'    => $combi_label,
						),
					)
				);

				if ( $cart_key ) {
					if ( isset( WC()->cart->cart_contents[ $cart_key ] ) ) {
						$cart_item = WC()->cart->cart_contents[ $cart_key ];
						if ( isset( $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product ) {
							$final_price = isset( $pricing['display_unit_price'] )
								? (float) $pricing['display_unit_price']
								: (float) ( $pricing['unit_price'] ?? 0.0 );
							$cart_item['data']->set_price( $final_price );
						}
						$cart_item['sbdp_pricing']             = $pricing;
						WC()->cart->cart_contents[ $cart_key ] = $cart_item;
					}
				} else {
					$notice_message = self::first_cart_error_notice();
					return new WP_Error(
						'sbdp_cart_failed',
						'' !== $notice_message ? $notice_message : __( 'Kon geen items aan de winkelwagen toevoegen.', 'sbdp' ),
						array( 'status' => 500 )
					);
				}
			}

			if ( WC()->cart ) {
				WC()->cart->calculate_totals();
				if ( method_exists( WC()->cart, 'set_session' ) ) {
					WC()->cart->set_session();
				}
				if ( method_exists( WC()->cart, 'maybe_set_cart_cookies' ) ) {
					WC()->cart->maybe_set_cart_cookies();
				}
			}

			return array(
				'ok'       => true,
				'redirect' => wc_get_checkout_url(),
			);
		}
		$order = wc_create_order();
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$has_items = false;
		foreach ( $truth_items as $resolved ) {
			$item    = $resolved['item'];
			$profile = $resolved['profile'];
			$meta    = $resolved['meta'];
			$product = wc_get_product( $item['product_id'] );
			if ( ! $product ) {
				continue;
			}

			$qty     = $participants;
			$item_id = $order->add_product( $product, $qty );
			if ( $item_id ) {
				$has_items   = true;
				$resource_id = intval( $item['resource_id'] ?? get_post_meta( $item['product_id'], '_sbdp_resource_id', true ) );
				$resource_label = self::get_resource_label( $resource_id );
				$item_combi_id = intval( $item['combi'] ?? $combi_id );
				$pricing     = self::calculate_pricing_for_item( $product, $resource_id, $item['start'], $participants, $pricing_context );
				$pricing     = self::apply_combi_adjustment( $pricing, $item_combi_id, $participants );
				$combi_label = $pricing['combi']['label'] ?? '';
				wc_add_order_item_meta( $item_id, 'sbdp_start', $meta['sbdp_start'] );
				wc_add_order_item_meta( $item_id, 'sbdp_end', $meta['sbdp_end'] );
				wc_add_order_item_meta( $item_id, 'sbdp_participants', $meta['sbdp_participants'] );
				wc_add_order_item_meta( $item_id, 'sbdp_canonical_participants', $meta['sbdp_canonical_participants'] );
				wc_add_order_item_meta( $item_id, 'sbdp_resource_id', $meta['sbdp_resource_id'] );
				wc_add_order_item_meta( $item_id, 'sbdp_resource_label', $resource_label );
				wc_add_order_item_meta( $item_id, 'sbdp_route_intent', $meta['sbdp_route_intent'] );
				wc_add_order_item_meta( $item_id, 'sbdp_booking_capability', $meta['sbdp_booking_capability'] );
				if ( $item_combi_id ) {
					wc_add_order_item_meta( $item_id, 'sbdp_combi', $item_combi_id );
				}
				if ( $combi_label ) {
					wc_add_order_item_meta( $item_id, 'sbdp_combi_label', $combi_label );
				}
				wc_add_order_item_meta( $item_id, '_sbdp_pricing', $pricing );

				$order_item = $order->get_item( $item_id );
				if ( $order_item instanceof WC_Order_Item_Product ) {
					$line_total = round( $pricing['unit_price'] * $qty, 2 );
					$order_item->set_subtotal( $line_total );
					$order_item->set_total( $line_total );
					$order_item->save();
				}
			}
		}

		if ( ! $has_items ) {
			return new WP_Error( 'sbdp_order_failed', __( 'Kon geen items aan de order toevoegen.', 'sbdp' ), array( 'status' => 500 ) );
		}

		$order->calculate_totals();
		$order->update_status( 'on-hold', 'Concept programma via planner' );
		$order->update_meta_data( 'sbdp_mode', 'request' );
		self::apply_compose_customer_to_order( $order, $customer );
		$order->save();

		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->__unset( 'sbdp_mode' );
			WC()->session->__unset( 'sbdp_itinerary' );
		}

		$order_key    = $order->get_order_key();
		$received_url = method_exists( $order, 'get_checkout_order_received_url' ) ? $order->get_checkout_order_received_url() : '';
		$view_url     = $order->get_view_order_url();
		$redirect     = $received_url ? $received_url : $view_url;

		return array(
			'ok'        => true,
			'redirect'  => $redirect,
			'order_id'  => $order->get_id(),
			'order_key' => $order_key,
			'view_url'  => $view_url,
		);
	}

	public static function get_rules( WP_REST_Request $request ) {
		$product_id  = intval( $request->get_param( 'product_id' ) );
		$resource_id = intval( $request->get_param( 'resource_id' ) );

		if ( ! $product_id ) {
			return new WP_Error( 'bad_request', 'product_id required', array( 'status' => 400 ) );
		}

		$key   = $resource_id ? "_sbdp_av_rules_res_{$resource_id}" : '_sbdp_av_rules';
		$rules = get_post_meta( $product_id, $key, true );
		if ( ! is_array( $rules ) ) {
			$rules = AvailabilityRules::defaultRules();
		}

		$cap_key  = $resource_id ? "_sbdp_capacity_res_{$resource_id}" : '_sbdp_capacity_default';
		$capacity = (int) get_post_meta( $product_id, $cap_key, true );
		if ( $capacity < 0 ) {
			$capacity = 0;
		}

		if ( ! is_array( $rules ) ) {
			$rules = array(
				'default'          => 'open',
				'exclude_weekdays' => array(),
				'exclude_months'   => array(),
				'exclude_times'    => array(),
				'overrides'        => array(),
			);
		}

		return array(
			'rules'    => $rules,
			'capacity' => $capacity,
		);
	}

	public static function save_rules( WP_REST_Request $request ) {
		if ( ! self::can_manage_woocommerce() ) {
			return new WP_Error( 'forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
		}

		$payload     = $request->get_json_params();
		$product_id  = intval( $payload['product_id'] ?? 0 );
		$resource_id = intval( $payload['resource_id'] ?? 0 );
		$rules       = $payload['rules'] ?? null;
		$capacity    = intval( $payload['capacity'] ?? 1 );

		if ( ! $product_id || ! is_array( $rules ) ) {
			return new WP_Error( 'bad_request', 'product_id & rules required', array( 'status' => 400 ) );
		}

		$key = $resource_id ? "_sbdp_av_rules_res_{$resource_id}" : '_sbdp_av_rules';
		update_post_meta( $product_id, $key, $rules );

		$cap_key = $resource_id ? "_sbdp_capacity_res_{$resource_id}" : '_sbdp_capacity_default';
		update_post_meta( $product_id, $cap_key, $capacity );

		return array( 'ok' => true );
	}

	public static function preview_availability( WP_REST_Request $request ) {
		$payload     = $request->get_json_params();
		$product_id  = intval( $payload['product_id'] ?? 0 );
		$resource_id = intval( $payload['resource_id'] ?? 0 );
		$date        = sanitize_text_field( $payload['date'] ?? '' );

		if ( ! $product_id || ! $date ) {
			return new WP_Error( 'bad_request', 'product_id & date required', array( 'status' => 400 ) );
		}

		$key   = $resource_id ? "_sbdp_av_rules_res_{$resource_id}" : '_sbdp_av_rules';
		$rules = get_post_meta( $product_id, $key, true );
		if ( ! is_array( $rules ) ) {
			$rules = AvailabilityRules::defaultRules();
		}

		$blocks = self::blocks_for_date( $date, $rules );

		$cap_key  = $resource_id ? "_sbdp_capacity_res_{$resource_id}" : '_sbdp_capacity_default';
		$capacity = (int) get_post_meta( $product_id, $cap_key, true );
		if ( $capacity < 0 ) {
			$capacity = 0;
		}

		return array(
			'blocks'   => $blocks,
			'capacity' => $capacity,
		);
	}

	public static function plan_availability( WP_REST_Request $request ) {
		$limit = self::check_rate_limit( $request, 'availability_plan' );
		if ( $limit instanceof WP_Error ) {
			return $limit;
		}

		$product_id  = intval( $request->get_param( 'product_id' ) );
		$resource_id = intval( $request->get_param( 'resource_id' ) );
		$date        = sanitize_text_field( $request->get_param( 'date' ) );

		if ( ! $product_id || ! $date ) {
			return new WP_Error( 'bad_request', 'product_id & date required', array( 'status' => 400 ) );
		}

		return self::build_availability_payload( $product_id, $resource_id, $date );
	}

	public static function availability_slots( WP_REST_Request $request ) {
		$limit = self::check_rate_limit( $request, 'availability_slots' );
		if ( $limit instanceof WP_Error ) {
			return $limit;
		}

		return AvailabilityProjectionService::availabilitySlots( $request );
	}

	public static function get_resources( WP_REST_Request $request ) {
		$resources = get_posts(
			array(
				'post_type'      => 'bookable_resource',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$out = array();
		foreach ( $resources as $resource ) {
			$out[] = array(
				'id'    => (int) $resource->ID,
				'title' => get_the_title( $resource ),
			);
		}

		return rest_ensure_response( $out );
	}

	public static function get_pricing_rules( WP_REST_Request $request ) {
		$product_id  = intval( $request->get_param( 'product_id' ) );
		$resource_id = intval( $request->get_param( 'resource_id' ) );

		if ( ! $product_id ) {
			return new WP_Error( 'bad_request', 'product_id required', array( 'status' => 400 ) );
		}

		$key   = $resource_id ? "_sbdp_price_rules_res_{$resource_id}" : '_sbdp_price_rules';
		$rules = get_post_meta( $product_id, $key, true );

		return array( 'rules' => $rules );
	}

	public static function save_pricing_rules( WP_REST_Request $request ) {
		if ( ! self::can_manage_woocommerce() ) {
			return new WP_Error( 'forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
		}

		$payload     = $request->get_json_params();
		$product_id  = intval( $payload['product_id'] ?? 0 );
		$resource_id = intval( $payload['resource_id'] ?? 0 );
		$rules_raw   = $payload['rules'] ?? array();

		if ( ! $product_id || ! is_array( $rules_raw ) ) {
			return new WP_Error( 'bad_request', 'product_id & rules required', array( 'status' => 400 ) );
		}

		$rules = self::sanitize_price_rules( $rules_raw );

		$key = $resource_id ? "_sbdp_price_rules_res_{$resource_id}" : '_sbdp_price_rules';
		update_post_meta( $product_id, $key, $rules );

		return array( 'ok' => true );
	}

	public static function preview_pricing( WP_REST_Request $request ) {
		$payload      = $request->get_json_params();
		$product_id   = intval( $payload['product_id'] ?? 0 );
		$resource_id  = intval( $payload['resource_id'] ?? 0 );
		$participants = max( 1, intval( $payload['participants'] ?? 1 ) );
		$start        = sanitize_text_field( $payload['start'] ?? '' );
		$channel      = sanitize_text_field( $payload['channel'] ?? 'pricing_preview' );
		$combi_id     = intval( $payload['combi'] ?? 0 );

		if ( ! $product_id || ! $start ) {
			return new WP_Error( 'bad_request', 'product_id & start required', array( 'status' => 400 ) );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'sbdp_invalid_product', __( 'Ongeldige productreferentie.', 'sbdp' ), array( 'status' => 400 ) );
		}

		$pricing = self::calculate_pricing_for_item(
			$product,
			$resource_id,
			$start,
			$participants,
			array(
				'channel' => $channel,
				'source'  => 'preview_pricing',
			)
		);
		$pricing = self::apply_combi_adjustment( $pricing, $combi_id, $participants );

		return rest_ensure_response( $pricing );
	}

	private static function apply_combi_adjustment( array $pricing, int $combi_id, int $participants ): array {
		if ( ! $combi_id || ! function_exists( 'wc_get_product' ) ) {
			return $pricing;
		}

		$combi_product = wc_get_product( $combi_id );
		if ( ! $combi_product ) {
			return $pricing;
		}
		$unit_price = 0.0;
		$total      = 0.0;
		$supports_persons = false;

		if ( class_exists( '\\SBDP\\Pricing\\PricingService' ) ) {
			try {
				$quote = \SBDP\Pricing\PricingService::instance()->quote(
					$combi_product->get_id(),
					max( 1, $participants ),
					array(
						'channel'    => 'preview_pricing_combi',
						'source'     => 'rest_service',
						'price_mode' => 'gross',
					)
				);
				if ( is_array( $quote ) ) {
					$total = isset( $quote['total'] ) ? (float) $quote['total'] : 0.0;
					if ( $total > 0.0 && $participants > 0 ) {
						$unit_price = round( $total / max( 1, $participants ), 2 );
					}
					if ( $unit_price <= 0.0 && isset( $quote['unit_price'] ) ) {
						$unit_price = (float) $quote['unit_price'];
					}
					if ( isset( $quote['line_item']['pricing'] ) && is_array( $quote['line_item']['pricing'] ) ) {
						$supports_persons = ! empty( $quote['line_item']['pricing']['supports_persons'] );
					}
				}
			} catch ( \Throwable $exception ) {
				$unit_price = 0.0;
				$total      = 0.0;
			}
		}

		if ( $unit_price <= 0.0 && function_exists( 'wc_get_price_including_tax' ) ) {
			$unit_price = (float) wc_get_price_including_tax( $combi_product, array( 'qty' => 1 ) );
		}
		if ( $unit_price <= 0.0 ) {
			$unit_price = function_exists( 'wc_get_price_including_tax' ) ? (float) wc_get_price_including_tax( $combi_product, array( 'qty' => 1 ) ) : (float) $combi_product->get_price();
		}
		if ( $unit_price <= 0.0 ) {
			return $pricing;
		}
		if ( $total <= 0.0 ) {
			$total = $supports_persons ? ( $unit_price * max( 1, $participants ) ) : $unit_price;
		}

		$pricing['combi'] = array(
			'id'               => $combi_id,
			'label'            => $combi_product->get_name(),
			'unit_price'       => $unit_price,
			'total'            => $total,
			'supports_persons' => $supports_persons,
		);

		$pricing['combi']['display_unit_price'] = round( self::display_price_for_product( $combi_product, $unit_price ), 2 );
		$pricing['combi']['display_total'] = round( self::display_price_for_product( $combi_product, $total ), 2 );

		if ( isset( $pricing['total'] ) ) {
			$pricing['total'] = (float) $pricing['total'] + $total;
		}

		if ( isset( $pricing['unit_price'] ) ) {
			$pricing['unit_price'] = (float) $pricing['unit_price'] + $unit_price;
		}

		if ( isset( $pricing['display_total'] ) ) {
			$pricing['display_total'] = round( (float) $pricing['display_total'] + (float) $pricing['combi']['display_total'], 2 );
		} else {
			$pricing['display_total'] = round( self::display_price_for_product( $combi_product, (float) ( $pricing['total'] ?? $total ) ), 2 );
		}

		if ( isset( $pricing['display_unit_price'] ) ) {
			$display_unit = (float) $pricing['display_unit_price'] + (float) $pricing['combi']['display_unit_price'];
			$pricing['display_unit_price'] = round( $display_unit, 2 );
			$pricing['display_per_person']  = round( $display_unit, 2 );
		}

		return $pricing;
	}

	public static function get_schedule_overview( WP_REST_Request $request ) {
		$view_raw = strtolower( (string) $request->get_param( 'view' ) );
		$view     = in_array( $view_raw, array( 'week', 'month', 'day' ), true ) ? $view_raw : 'day';

		$date_raw = sanitize_text_field( $request->get_param( 'date' ) );
		$date     = $date_raw && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_raw ) ? $date_raw : current_time( 'Y-m-d' );

		$start_raw = sanitize_text_field( $request->get_param( 'start' ) );
		$end_raw   = sanitize_text_field( $request->get_param( 'end' ) );

		$range = self::determine_schedule_range( $view, $date, $start_raw, $end_raw );
		if ( ! $range ) {
			return new WP_Error( 'bad_request', __( 'Ongeldige datumbereik voor plannervertoning.', 'sbdp' ), array( 'status' => 400 ) );
		}

		$range_start = $range['start'];
		$range_end   = $range['end'];

		$resource_post_types = apply_filters(
			'sbdp_schedule_resource_post_types',
			array( 'bookable_resource', 'bsp_city_guide' )
		);

		$resource_posts = get_posts(
			array(
				'post_type'      => $resource_post_types,
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$resources       = array();
		$resource_lookup = array(
			0 => array(
				'name'     => __( 'Niet toegewezen', 'sbdp' ),
				'color'    => '#94a3b8',
				'capacity' => 0,
				'order'    => 0,
				'type'     => 'unassigned',
			),
		);

		foreach ( $resource_posts as $resource_post ) {
			$title          = get_the_title( $resource_post );
			$is_city_guide  = $resource_post->post_type === 'bsp_city_guide';
			$capacity_meta  = $is_city_guide ? '_bsp_cityguide_capacity' : '_sbdp_resource_capacity';
			$color_meta     = $is_city_guide ? '_bsp_cityguide_color' : '_sbdp_resource_color';
			$order_meta     = $is_city_guide ? '_bsp_cityguide_order' : '_sbdp_resource_order';

			$capacity = (int) get_post_meta( $resource_post->ID, $capacity_meta, true );
			if ( $capacity <= 0 ) {
				$capacity = (int) get_post_meta( $resource_post->ID, '_sbdp_resource_capacity', true );
			}

			$color = get_post_meta( $resource_post->ID, $color_meta, true );
			if ( ! is_string( $color ) || '' === trim( $color ) ) {
				$color = get_post_meta( $resource_post->ID, '_sbdp_resource_color', true );
			}
			$color = is_string( $color ) && '' !== trim( $color ) ? $color : '#2563eb';

			$order = (int) get_post_meta( $resource_post->ID, $order_meta, true );
			if ( $order === 0 && $is_city_guide ) {
				$order = (int) get_post_meta( $resource_post->ID, '_sbdp_resource_order', true );
			}

			$resource_meta = array(
				'id'       => (int) $resource_post->ID,
				'name'     => $title,
				'capacity' => $capacity,
				'color'    => $color,
				'order'    => $order,
				'type'     => $resource_post->post_type,
			);

			if ( $is_city_guide ) {
				$resource_meta['timezone'] = get_post_meta( $resource_post->ID, '_bsp_cityguide_timezone', true ) ?: 'UTC';
				$resource_meta['status']   = get_post_meta( $resource_post->ID, '_bsp_cityguide_status', true ) ?: 'idle';
				$resource_meta['ical']     = get_post_meta( $resource_post->ID, '_bsp_cityguide_ical', true );
			}

			$resources[]                            = $resource_meta;
			$resource_lookup[ $resource_post->ID ] = array(
				'name'     => $title,
				'capacity' => $capacity,
				'color'    => $color,
				'order'    => $order,
				'type'     => $resource_post->post_type,
			);
		}

		usort(
			$resources,
			static function ( $left, $right ) {
				$left_order  = $left['order'] ?? 0;
				$right_order = $right['order'] ?? 0;

				if ( $left_order === $right_order ) {
					return strcasecmp( (string) ( $left['name'] ?? '' ), (string) ( $right['name'] ?? '' ) );
				}

				return $left_order <=> $right_order;
			}
		);

		$sorted_lookup = array(
			0 => $resource_lookup[0],
		);

		foreach ( $resources as $resource ) {
			$resource_id               = (int) ( $resource['id'] ?? 0 );
			$sorted_lookup[ $resource_id ] = array(
				'name'     => $resource['name'],
				'capacity' => $resource['capacity'],
				'color'    => $resource['color'],
				'order'    => $resource['order'],
			);
		}

		$resource_lookup = $sorted_lookup;

		array_unshift(
			$resources,
			array(
				'id'       => 0,
				'name'     => $resource_lookup[0]['name'],
				'capacity' => $resource_lookup[0]['capacity'],
				'color'    => $resource_lookup[0]['color'],
				'order'    => $resource_lookup[0]['order'],
				'type'     => 'unassigned',
			)
		);

		$status_filter = apply_filters( 'sbdp_schedule_order_statuses', array( 'processing', 'on-hold', 'completed', 'pending' ) );
		$order_limit   = apply_filters( 'sbdp_schedule_order_limit', 'month' === $view ? 500 : ( 'week' === $view ? 350 : 200 ) );
		$orders        = wc_get_orders(
			array(
				'status'  => $status_filter,
				'limit'   => $order_limit,
				'orderby' => 'date',
				'order'   => 'DESC',
			)
		);

		$events = array();
		if ( $orders ) {
			foreach ( $orders as $order ) {
				foreach ( $order->get_items() as $item ) {
					$start_iso = wc_get_order_item_meta( $item->get_id(), 'sbdp_start' );
					if ( ! $start_iso ) {
						continue;
					}

					$start_dt = self::parse_datetime( $start_iso );
					if ( ! $start_dt ) {
						continue;
					}

					if ( $start_dt < $range_start || $start_dt > $range_end->setTime( 23, 59, 59 ) ) {
						continue;
					}

					$end_iso = wc_get_order_item_meta( $item->get_id(), 'sbdp_end' );
					$end_dt  = self::parse_datetime( $end_iso );

					$participants = (int) wc_get_order_item_meta( $item->get_id(), 'sbdp_participants' );
					if ( $participants < 1 ) {
						$participants = 1;
					}

					$product_id  = $item->get_product_id();
					$resource_id = 0;
					if ( $product_id ) {
						$resource_id = (int) get_post_meta( $product_id, '_sbdp_resource_id', true );
					}

					$item_resource_id = wc_get_order_item_meta( $item->get_id(), 'sbdp_resource_id' );
					if ( '' !== $item_resource_id && null !== $item_resource_id ) {
						$resource_id = (int) $item_resource_id;
					}

					$resource_meta = $resource_lookup[ $resource_id ] ?? $resource_lookup[0];

					$events[] = array(
						'order_id'     => $order->get_id(),
						'order_status' => $order->get_status(),
						'product_id'   => $product_id,
						'product_name' => $item->get_name(),
						'start'        => self::format_iso( $start_iso ),
						'end'          => $end_dt ? self::format_iso( $end_iso ) : null,
						'participants' => $participants,
						'customer'     => $order->get_formatted_billing_full_name(),
						'resource'     => array(
							'id'       => $resource_id,
							'name'     => $resource_meta['name'],
							'color'    => $resource_meta['color'],
							'capacity' => $resource_meta['capacity'],
							'order'    => $resource_meta['order'] ?? 0,
						),
						'link'         => $order->get_edit_order_url(),
					);
				}
			}
		}

		$events = array_merge(
			$events,
			self::get_calendar_block_events( $resource_lookup, $range_start, $range_end )
		);

		$timeline = array();
		if ( 'day' === $view ) {
			$day_events = array_values(
				array_filter(
					$events,
					static function ( $event ) use ( $date ) {
						return isset( $event['start'] ) && 0 === strpos( $event['start'], $date );
					}
				)
			);
			$timeline = self::build_day_timeline( $resource_lookup, $day_events, $date );
		}

		$days = self::group_events_by_day( $events, $range_start, $range_end, $resource_lookup );

		return rest_ensure_response(
			array(
				'view'      => $view,
				'date'      => $date,
				'range'     => array(
					'start' => $range_start->format( 'Y-m-d' ),
					'end'   => $range_end->format( 'Y-m-d' ),
				),
				'resources' => $resources,
				'events'    => $events,
				'timeline'  => $timeline,
				'days'      => $days,
			)
		);
	}

	private static function sanitize_price_rules( $rules ) {
		$out = array();

		foreach ( $rules as $rule ) {
			$clean = array(
				'label'     => sanitize_text_field( $rule['label'] ?? '' ),
				'type'      => sanitize_text_field( $rule['type'] ?? 'fixed' ),
				'amount'    => (float) ( $rule['amount'] ?? 0 ),
				'apply_to'  => sanitize_text_field( $rule['apply_to'] ?? 'booking' ),
				'weekdays'  => array(),
				'time_from' => '',
				'time_to'   => '',
				'date_from' => '',
				'date_to'   => '',
			);

			if ( ! in_array( $clean['type'], array( 'fixed', 'percent' ), true ) ) {
				$clean['type'] = 'fixed';
			}

			if ( ! in_array( $clean['apply_to'], array( 'booking', 'participant' ), true ) ) {
				$clean['apply_to'] = 'booking';
			}

			if ( isset( $rule['weekdays'] ) && is_array( $rule['weekdays'] ) ) {
				foreach ( $rule['weekdays'] as $weekday ) {
					$wd = (int) $weekday;
					if ( $wd >= 0 && $wd <= 6 ) {
						$clean['weekdays'][] = $wd;
					}
				}
			}

			if ( ! empty( $rule['time_from'] ) ) {
				$clean['time_from'] = preg_replace( '/[^0-9:]/', '', substr( $rule['time_from'], 0, 5 ) );
			}

			if ( ! empty( $rule['time_to'] ) ) {
				$clean['time_to'] = preg_replace( '/[^0-9:]/', '', substr( $rule['time_to'], 0, 5 ) );
			}

			if ( ! empty( $rule['date_from'] ) ) {
				$clean['date_from'] = sanitize_text_field( $rule['date_from'] );
			}

			if ( ! empty( $rule['date_to'] ) ) {
				$clean['date_to'] = sanitize_text_field( $rule['date_to'] );
			}

			$out[] = $clean;
		}

		return $out;
	}

	private static function get_local_datetime( $iso ) {
		try {
			$dt = new DateTimeImmutable( $iso );
		} catch ( Exception $e ) {
			return null;
		}

		try {
			$timezone = wp_timezone();
			return $dt->setTimezone( $timezone );
		} catch ( Exception $e ) {
			return $dt;
		}
	}

	private static function check_item_rules( $product_id, $resource_id, $start, $end, $participants ) {
		$filtered = apply_filters(
			'sbdp_planservice_execution_check',
			null,
			array(
				'product_id'   => (int) $product_id,
				'resource_id'  => (int) $resource_id,
				'start'        => (string) $start,
				'end'          => (string) $end,
				'participants' => (int) $participants,
			)
		);
		if ( null !== $filtered ) {
			return $filtered;
		}

		return AvailabilityExecutionService::checkItemRules( (int) $product_id, (int) $resource_id, (string) $start, (string) $end, (int) $participants );
	}
	private static function find_overlapping_bookings( $product_id, $resource_id, $start, $end ) {
		return AvailabilityExecutionService::findOverlappingBookings( (int) $product_id, (int) $resource_id, (string) $start, (string) $end );
	}
	private static function get_resource_label( int $resource_id ): string {
		if ( $resource_id <= 0 ) {
			return '';
		}

		$label = get_the_title( $resource_id );
		if ( ! $label ) {
			return '';
		}

		return sanitize_text_field( $label );
	}

	private static function ranges_overlap( $start, $end, $block_start, $block_end ) {
		$start_ts       = strtotime( $start );
		$end_ts         = strtotime( $end );
		$block_start_ts = strtotime( $block_start );
		$block_end_ts   = strtotime( $block_end );

		if ( ! $start_ts || ! $end_ts || ! $block_start_ts || ! $block_end_ts ) {
			return false;
		}

		return ( $block_end_ts > $start_ts ) && ( $block_start_ts < $end_ts );
	}

	private static function get_price_rules_for( $product_id, $resource_id ) {
		$rules        = array();
		$global_rules = get_post_meta( $product_id, '_sbdp_price_rules', true );
		if ( is_array( $global_rules ) ) {
			$rules = array_merge( $rules, $global_rules );
		}

		if ( $resource_id ) {
			$resource_rules = get_post_meta( $product_id, "_sbdp_price_rules_res_{$resource_id}", true );
			if ( is_array( $resource_rules ) ) {
				$rules = array_merge( $rules, $resource_rules );
			}
		}

		return $rules;
	}

	private static function price_rule_applies( $rule, DateTimeImmutable $moment ) {
		$weekday = (int) $moment->format( 'w' );
		$date    = $moment->format( 'Y-m-d' );
		$time    = $moment->format( 'H:i' );

		if ( ! empty( $rule['weekdays'] ) && is_array( $rule['weekdays'] ) ) {
			if ( ! in_array( $weekday, array_map( 'intval', $rule['weekdays'] ), true ) ) {
				return false;
			}
		}

		if ( ! empty( $rule['date_from'] ) && $date < $rule['date_from'] ) {
			return false;
		}
		if ( ! empty( $rule['date_to'] ) && $date > $rule['date_to'] ) {
			return false;
		}

		if ( ! empty( $rule['time_from'] ) && $time < $rule['time_from'] ) {
			return false;
		}
		if ( ! empty( $rule['time_to'] ) && $time > $rule['time_to'] ) {
			return false;
		}

		return true;
	}

	private static function bool_meta( $post_id, $key ) {
		$value = get_post_meta( $post_id, $key, true );

		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( $value ), array( '1', 'yes', 'true' ), true );
		}

		return (bool) $value;
	}

	public static function calculate_pricing_for_item( $product, $resource_id, $start, $participants, array $context = array() ) {
		$resolved = $product instanceof WC_Product ? $product : null;
		if ( ! $resolved && function_exists( 'wc_get_product' ) ) {
			$product_id = is_object( $product ) ? 0 : (int) $product;
			$resolved   = wc_get_product( $product_id );
		}

		if ( ! $resolved instanceof WC_Product ) {
			return self::empty_pricing_payload( (int) $participants );
		}

		$participants = max( 1, (int) $participants );
		$resource_id  = (int) $resource_id;
		$start        = is_string( $start ) ? $start : '';

		$quote_context = array_merge(
			array(
				'channel'     => 'planner_ui',
				'resource_id' => $resource_id,
				'start'       => $start,
				'time'        => $start,
				'price_mode'  => 'gross',
			),
			$context
		);

		if ( class_exists( PricingService::class ) ) {
			try {
				$quote = PricingService::instance()->quote(
					$resolved->get_id(),
					$participants,
					$quote_context
				);

				if ( is_array( $quote ) ) {
					return self::project_display_pricing( $resolved, self::format_pricing_quote( $quote, $participants ), $participants );
				}
			} catch ( \Throwable $exception ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Fallback to the legacy calculation below.
			}
		}

		return self::project_display_pricing( $resolved, self::legacy_pricing_for_item( $resolved, $resource_id, $start, $participants ), $participants );
	}

	private static function empty_pricing_payload( int $participants ): array {
		return array(
			'base_price'         => 0.0,
			'unit_price'         => 0.0,
			'booking_adjustment' => 0.0,
			'applied_rules'      => array(),
			'participants'       => max( 1, $participants ),
			'total'              => 0.0,
			'currency'           => self::get_default_currency(),
		);
	}

	/**
	 * @param array<string, mixed> $quote
	 */
	private static function format_pricing_quote( array $quote, int $participants ): array {
		$line_item = ( isset( $quote['line_item'] ) && is_array( $quote['line_item'] ) ) ? $quote['line_item'] : array();
		$pricing   = ( isset( $line_item['pricing'] ) && is_array( $line_item['pricing'] ) ) ? $line_item['pricing'] : array();

		$base_price = isset( $pricing['base_price'] ) ? (float) $pricing['base_price'] : 0.0;
		$unit_price = 0.0;
		if ( isset( $quote['unit_total'] ) ) {
                        $unit_price = (float) $quote['unit_total'];
                } elseif ( isset( $quote['unit_price'] ) ) {
                        $unit_price = (float) $quote['unit_price'];
                } elseif ( isset( $quote['unit'] ) && is_array( $quote['unit'] ) ) {
                        $unit_price = (float) ( $quote['unit']['total'] ?? 0.0 );
                }

                if ( $unit_price <= 0.0 && $participants > 0 ) {
                        $line_subtotal = (float) ( $line_item['line_subtotal'] ?? 0.0 );
                        if ( $line_subtotal > 0.0 ) {
                                $unit_price = $line_subtotal / $participants;
                        }
                }

                $booking_adjustment = self::sum_monetary_rows( $quote['adjustments'] ?? array() );

		$total = isset( $quote['total'] ) ? (float) $quote['total'] : 0.0;
		if ( $total <= 0.0 && $unit_price > 0.0 ) {
			$total = $unit_price * $participants;
		}

		$result = array(
			'base_price'         => round( $base_price, 2 ),
			'unit_price'         => round( $unit_price, 2 ),
			'booking_adjustment' => round( $booking_adjustment, 2 ),
			'applied_rules'      => isset( $quote['meta']['applied_rules'] ) ? (array) $quote['meta']['applied_rules'] : array(),
			'participants'       => $participants,
			'total'              => round( $total, 2 ),
			'currency'           => isset( $quote['currency'] ) ? (string) $quote['currency'] : self::get_default_currency(),
		);

		if ( $pricing !== array() ) {
			$result['pricing'] = $pricing;
		}

		if ( ! empty( $quote['adjustments'] ) ) {
			$result['adjustments'] = $quote['adjustments'];
		}
		if ( ! empty( $quote['discounts'] ) ) {
			$result['discounts'] = $quote['discounts'];
		}
		if ( ! empty( $quote['taxes'] ) ) {
			$result['taxes'] = $quote['taxes'];
		}

		return $result;
	}

	/**
	 * Project gross display values onto a pricing payload.
	 *
	 * @param array<string, mixed> $pricing
	 * @return array<string, mixed>
	 */
	private static function project_display_pricing( WC_Product $product, array $pricing, int $participants ): array {
		$participants = max( 1, $participants );

		$base_price = isset( $pricing['base_price'] ) ? (float) $pricing['base_price'] : 0.0;
		$unit_price = isset( $pricing['unit_price'] ) ? (float) $pricing['unit_price'] : 0.0;
		$total      = isset( $pricing['total'] ) ? (float) $pricing['total'] : 0.0;

		$display_base = self::display_price_for_product( $product, $base_price );
		$display_unit = self::display_price_for_product( $product, $unit_price > 0.0 ? $unit_price : $base_price );
		$display_total = self::display_price_for_product( $product, $total > 0.0 ? $total : ( $display_unit * $participants ) );

		$pricing['display_base_price'] = round( $display_base, 2 );
		$pricing['display_unit_price'] = round( $display_unit, 2 );
		$pricing['display_per_person']  = round( $display_unit, 2 );
		$pricing['display_total']       = round( $display_total, 2 );

		if ( isset( $pricing['line_item']['pricing'] ) && is_array( $pricing['line_item']['pricing'] ) ) {
			$pricing['line_item']['pricing']['display_base_price'] = $pricing['display_base_price'];
			$pricing['line_item']['pricing']['display_per_person'] = $pricing['display_per_person'];
			$pricing['line_item']['pricing']['display_unit_price'] = $pricing['display_unit_price'];
		}

		if ( isset( $pricing['combi'] ) && is_array( $pricing['combi'] ) ) {
			$combiId = isset( $pricing['combi']['id'] ) ? (int) $pricing['combi']['id'] : 0;
			if ( $combiId > 0 && function_exists( 'wc_get_product' ) ) {
				$combiProduct = wc_get_product( $combiId );
				if ( $combiProduct instanceof WC_Product ) {
					$combiUnit = isset( $pricing['combi']['unit_price'] ) ? (float) $pricing['combi']['unit_price'] : 0.0;
					$combiTotal = isset( $pricing['combi']['total'] ) ? (float) $pricing['combi']['total'] : 0.0;
					$pricing['combi']['display_unit_price'] = round( self::display_price_for_product( $combiProduct, $combiUnit ), 2 );
					$pricing['combi']['display_total'] = round( self::display_price_for_product( $combiProduct, $combiTotal ), 2 );
					if ( empty( $pricing['combi']['supports_persons'] ) ) {
						$pricing['combi']['display_total'] = round( $pricing['combi']['display_unit_price'], 2 );
					}
				}
			}
		}

		return $pricing;
	}

	private static function display_price_for_product( WC_Product $product, float $amount ): float {
		if ( $amount <= 0.0 ) {
			return 0.0;
		}

		if ( function_exists( 'wc_get_price_including_tax' ) ) {
			return round(
				(float) wc_get_price_including_tax(
					$product,
					array(
						'qty'   => 1,
						'price' => $amount,
					)
				),
				2
			);
		}

		return round( $amount, 2 );
	}

	private static function legacy_pricing_for_item( WC_Product $product, int $resource_id, string $start, int $participants ): array {
		$base_price      = function_exists( 'wc_get_price_including_tax' ) ? (float) wc_get_price_including_tax( $product, array( 'qty' => 1 ) ) : (float) $product->get_price();
		$base_price_meta = (float) get_post_meta( $product->get_id(), '_sbdp_base_price', true );
		$per_person_meta = (float) get_post_meta( $product->get_id(), '_sbdp_price_per_person', true );
		$fixed_fee_meta  = (float) get_post_meta( $product->get_id(), '_sbdp_base_fee', true );
		$people_enabled = self::bool_meta( $product->get_id(), '_sbdp_enable_people' );

		$unit_price_base = $base_price;
		if ( $unit_price_base <= 0.0 ) {
			if ( $people_enabled && $per_person_meta > 0.0 ) {
				$unit_price_base = $per_person_meta;
			} elseif ( $base_price_meta > 0.0 ) {
				$unit_price_base = $base_price_meta;
			}
		}

		$booking_adjustment_base = 0.0;
		if ( $fixed_fee_meta > 0.0 ) {
			$booking_adjustment_base += $fixed_fee_meta;
		}

		$moment = self::get_local_datetime( $start );

		$breakdown = array(
			'base_price'         => round( $unit_price_base, 2 ),
			'unit_price'         => round( $unit_price_base, 2 ),
			'booking_adjustment' => round( $booking_adjustment_base, 2 ),
			'applied_rules'      => array(),
			'participants'       => $participants,
			'total'              => round( ( $unit_price_base * $participants ) + $booking_adjustment_base, 2 ),
		);

		if ( ! $moment ) {
			$breakdown['currency'] = self::get_default_currency();

			return $breakdown;
		}

		$rules = self::get_price_rules_for( $product->get_id(), $resource_id );
		if ( empty( $rules ) ) {
			$breakdown['currency'] = self::get_default_currency();

			return $breakdown;
		}

		$base_price_for_rules = $unit_price_base;
		$unit_price           = $unit_price_base;
		$booking_adjustment   = $booking_adjustment_base;

		foreach ( $rules as $rule ) {
			if ( ! self::price_rule_applies( $rule, $moment ) ) {
				continue;
			}

			$type    = $rule['type'] ?? 'fixed';
			$scope   = $rule['apply_to'] ?? 'booking';
			$amount  = (float) ( $rule['amount'] ?? 0 );
			$applied = 0.0;

			if ( 'percent' === $type ) {
				if ( 'participant' === $scope ) {
					$applied     = $base_price_for_rules * ( $amount / 100 );
					$unit_price += $applied;
				} else {
					$applied             = ( $base_price_for_rules * $participants ) * ( $amount / 100 );
					$booking_adjustment += $applied;
				}
			} elseif ( 'participant' === $scope ) {
				$applied     = $amount;
				$unit_price += $applied;
			} else {
				$applied             = $amount;
				$booking_adjustment += $applied;
			}

			$breakdown['applied_rules'][] = array(
				'label'  => $rule['label'],
				'scope'  => $scope,
				'type'   => $type,
				'amount' => round( $applied, 2 ),
			);
		}

		if ( $booking_adjustment !== 0 && $participants > 0 ) {
			$unit_price += ( $booking_adjustment / $participants );
		}

		$unit_price                      = max( 0, $unit_price );
		$breakdown['unit_price']         = round( $unit_price, 2 );
		$breakdown['booking_adjustment'] = round( $booking_adjustment, 2 );
		$breakdown['total']              = round( $breakdown['unit_price'] * $participants, 2 );
		$breakdown['currency']           = self::get_default_currency();

		return $breakdown;
	}

	/**
	 * @param array<int, array<string, mixed>>|mixed $rows
	 */
	private static function sum_monetary_rows( $rows ): float {
		if ( ! is_array( $rows ) ) {
			return 0.0;
		}

		$total = 0.0;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$total += (float) ( $row['amount'] ?? 0.0 );
		}

		return round( $total, 2 );
	}

	private static function get_default_currency(): string {
		if ( function_exists( 'get_option' ) ) {
			$currency = (string) get_option( 'woocommerce_currency', 'EUR' );
			if ( $currency !== '' ) {
				return $currency;
			}
		}

		return 'EUR';
	}

	private static function validate_items( $items, $participants ) {
		if ( empty( $items ) ) {
			return true;
		}

		foreach ( $items as $item ) {
			$start = strtotime( $item['start'] );
			$end   = strtotime( $item['end'] );
			if ( ! $start || ! $end ) {
				return new WP_Error( 'sbdp_bad_time', __( 'Ongeldige datum of tijd ontvangen.', 'sbdp' ), array( 'status' => 400 ) );
			}
			if ( $end <= $start ) {
				return new WP_Error( 'sbdp_bad_range', __( 'Eindtijd moet later zijn dan starttijd.', 'sbdp' ), array( 'status' => 400 ) );
			}
			if ( $start < current_time( 'timestamp' ) ) {
				return new WP_Error( 'sbdp_past_time', __( 'De geselecteerde tijd mag niet in het verleden liggen.', 'sbdp' ), array( 'status' => 400 ) );
			}

			$check = self::check_item_rules(
				intval( $item['product_id'] ),
				intval( $item['resource_id'] ?? 0 ),
				$item['start'],
				$item['end'],
				$participants
			);
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}

		$sorted = $items;
		usort(
			$sorted,
			static function ( $a, $b ) {
				return strcmp( (string) ( $a['start'] ?? '' ), (string) ( $b['start'] ?? '' ) );
			}
		);

		for ( $i = 1, $count = count( $sorted ); $i < $count; $i++ ) {
			$prev    = $sorted[ $i - 1 ];
			$current = $sorted[ $i ];
			if ( self::ranges_overlap( $prev['start'], $prev['end'], $current['start'], $current['end'] ) ) {
				return new WP_Error( 'sbdp_overlap', __( 'Activiteiten overlappen elkaar; pas de planning aan.', 'sbdp' ), array( 'status' => 400 ) );
			}
		}

		return true;
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @return array<int, array{item:array<string, mixed>,profile:array<string, mixed>,meta:array<string, mixed>}>|WP_Error
	 */
	private static function canonicalize_compose_items( array $items, int $participants, string $mode ) {
		$runtime  = new BookingTruthRuntimeService();
		$resolved = array();

		foreach ( $items as $item ) {
			$canonical_item = array(
				'product_id'  => (int) ( $item['product_id'] ?? 0 ),
				'resource_id' => (int) ( $item['resource_id'] ?? 0 ),
				'participants'=> max( 1, $participants ),
				'date'        => isset( $item['start'] ) ? substr( (string) $item['start'], 0, 10 ) : '',
				'start'       => (string) ( $item['start'] ?? '' ),
				'end'         => (string) ( $item['end'] ?? '' ),
				'combi'       => isset( $item['combi'] ) ? absint( $item['combi'] ) : 0,
				'options'     => isset( $item['options'] ) && is_array( $item['options'] ) ? $item['options'] : array(),
			);

			$profile = $runtime->resolveBookingCapabilityProfile( $canonical_item );
			$status  = (string) ( $profile['status'] ?? BookingTruthRuntimeService::CAPABILITY_STATUS_UNAVAILABLE );
			$route   = (string) ( $profile['route_intent'] ?? BookingTruthRuntimeService::ROUTE_INTENT_BLOCKED );

			if ( $status === BookingTruthRuntimeService::CAPABILITY_STATUS_UNAVAILABLE ) {
				return new WP_Error(
					'sbdp_booking_truth_unavailable',
					self::compose_truth_error_message( (string) ( $profile['reason_code'] ?? 'availability_rejected' ) ),
					array( 'status' => 409, 'booking_capability' => $status, 'route_intent' => $route )
				);
			}

			if ( 'pay' === $mode && $route !== BookingTruthRuntimeService::ROUTE_INTENT_CHECKOUT ) {
				return new WP_Error(
					'sbdp_direct_checkout_blocked',
					self::compose_truth_error_message( (string) ( $profile['reason_code'] ?? 'direct_checkout_blocked' ) ),
					array( 'status' => 409, 'booking_capability' => $status, 'route_intent' => $route )
				);
			}

			$resolved[] = array(
				'item'    => $canonical_item,
				'profile' => $profile,
				'meta'    => $runtime->buildCanonicalMeta( $canonical_item, $profile ),
			);
		}

		return $resolved;
	}

	private static function compose_truth_error_message( string $reason_code ): string {
		switch ( $reason_code ) {
			case 'invalid_resource':
				return __( 'De gekozen resource is niet meer geldig.', 'sbdp' );
			case 'capacity_exceeded':
				return __( 'De gekozen capaciteit is niet meer beschikbaar.', 'sbdp' );
			case 'selected_time_invalid':
			case 'time_unavailable':
				return __( 'Het gekozen tijdslot is niet meer beschikbaar.', 'sbdp' );
			case 'requires_confirmation':
			case 'booking_resolution_incomplete':
			case 'direct_checkout_blocked':
				return __( 'Deze selectie kan niet direct afrekenen en moet als aanvraag worden verwerkt.', 'sbdp' );
			default:
				return __( 'De gekozen boeking voldoet niet meer aan de actuele beschikbaarheid.', 'sbdp' );
		}
	}

	private static function determine_schedule_range( string $view, string $date, string $start = '', string $end = '' ): ?array {
		$date_object = self::parse_date( $start ) ?: self::parse_date( $date );
		if ( ! $date_object ) {
			return null;
		}

		if ( 'day' === $view ) {
			$day = self::parse_date( $date ) ?: $date_object;

			return array(
				'start' => $day->setTime( 0, 0, 0 ),
				'end'   => $day->setTime( 23, 59, 59 ),
			);
		}

		$start_object = self::parse_date( $start ) ?: $date_object;
		$end_object   = self::parse_date( $end );

		if ( 'week' === $view ) {
			$monday = $start_object->modify( 'monday this week' )->setTime( 0, 0, 0 );
			$sunday = $end_object ? $end_object->setTime( 23, 59, 59 ) : $monday->modify( '+6 days' )->setTime( 23, 59, 59 );

			return array(
				'start' => $monday,
				'end'   => $sunday,
			);
		}

		// Month view.
		$month_start = $start_object->modify( 'first day of this month' )->setTime( 0, 0, 0 );
		$month_end   = $end_object ? $end_object->setTime( 23, 59, 59 ) : $month_start->modify( 'last day of this month' )->setTime( 23, 59, 59 );

		return array(
			'start' => $month_start,
			'end'   => $month_end,
		);
	}

	private static function build_day_timeline( array $resource_lookup, array $events, string $date ): array {
		$result      = array();
		$slot_length = (int) apply_filters( 'sbdp_planboard_slot_minutes', 30 );
		if ( $slot_length < 5 ) {
			$slot_length = 5;
		}

		$start_of_day = self::parse_datetime( $date . 'T06:00:00' ) ?: self::parse_datetime( $date . 'T00:00:00' );
		$end_of_day   = self::parse_datetime( $date . 'T22:00:00' ) ?: $start_of_day->modify( '+16 hours' );

		$events_by_resource = array();
		foreach ( $events as $event ) {
			$resource_id = (int) ( $event['resource']['id'] ?? 0 );
			$events_by_resource[ $resource_id ][] = $event;
		}

		foreach ( $resource_lookup as $resource_id => $resource_meta ) {
			if ( ! is_array( $resource_meta ) ) {
				$resource_meta = array(
					'name'     => (string) $resource_meta,
					'color'    => '#2563eb',
					'capacity' => 0,
					'order'    => 0,
				);
			}

			$resource_meta = array_merge(
				array(
					'name'     => __( 'Niet toegewezen', 'sbdp' ),
					'color'    => '#2563eb',
					'capacity' => 0,
					'order'    => 0,
				),
				$resource_meta
			);

			$resource_events = $events_by_resource[ $resource_id ] ?? array();

			usort(
				$resource_events,
				static function ( $left, $right ) {
					return strcmp( (string) ( $left['start'] ?? '' ), (string) ( $right['start'] ?? '' ) );
				}
			);

			$current            = clone $start_of_day;
			$segments           = array();
			$slots              = array();
			$booking_count      = 0;
			$total_participants = 0;

			foreach ( $resource_events as $event ) {
				$event_start = self::parse_datetime( $event['start'] ?? '' );
				$event_end   = self::parse_datetime( $event['end'] ?? '' );

				if ( ! $event_start ) {
					continue;
				}

				if ( $event_start > $current ) {
					$segments[] = self::build_available_segment( $current, $event_start, $resource_meta );
					$slots      = array_merge( $slots, self::segment_to_slots( $current, $event_start, $slot_length ) );
				}

				$segments[] = array(
					'type'  => 'booking',
					'start' => self::format_iso( $event['start'] ),
					'end'   => $event_end ? self::format_iso( $event['end'] ) : null,
					'event' => $event,
					'resource_color' => $resource_meta['color'],
				);

				$booking_count++;
				$total_participants += (int) ( $event['participants'] ?? 0 );

				if ( $event_end && $event_end > $current ) {
					$current = clone $event_end;
				}
			}

			if ( $current < $end_of_day ) {
				$segments[] = self::build_available_segment( $current, $end_of_day, $resource_meta );
				$slots      = array_merge( $slots, self::segment_to_slots( $current, $end_of_day, $slot_length ) );
			}

			$result[] = array(
				'resource'        => array(
					'id'       => (int) $resource_id,
					'name'     => $resource_meta['name'],
					'color'    => $resource_meta['color'],
					'capacity' => $resource_meta['capacity'],
					'order'    => $resource_meta['order'],
				),
				'segments'        => $segments,
				'available_slots' => $slots,
				'stats'           => array(
					'bookings'     => $booking_count,
					'participants' => $total_participants,
				),
			);
		}

		return $result;
	}

	private static function build_available_segment( DateTimeImmutable $start, DateTimeImmutable $end, array $resource_meta ): array {
		return array(
			'type'            => 'available',
			'start'           => $start->format( DATE_ATOM ),
			'end'             => $end->format( DATE_ATOM ),
			'label'           => sprintf(
				'%s - %s',
				$start->format( 'H:i' ),
				$end->format( 'H:i' )
			),
			'resource_color'   => $resource_meta['color'] ?? '#2563eb',
			'resource_capacity' => $resource_meta['capacity'] ?? 0,
		);
	}

	private static function segment_to_slots( DateTimeImmutable $start, DateTimeImmutable $end, int $slot_length ): array {
		if ( $end <= $start ) {
			return array();
		}

		$cursor = clone $start;
		$slots  = array();

		while ( $cursor < $end ) {
			$next = $cursor->modify( sprintf( '+%d minutes', $slot_length ) );
			if ( $next > $end ) {
				break;
			}

			$slots[] = array(
				'start' => $cursor->format( 'H:i' ),
				'end'   => $next->format( 'H:i' ),
			);
			$cursor = $next;
		}

		return $slots;
	}

	private static function group_events_by_day( array $events, DateTimeImmutable $start, DateTimeImmutable $end, array $resource_lookup ): array {
		$days    = array();
		$current = clone $start;
		while ( $current <= $end ) {
			$key          = $current->format( 'Y-m-d' );
			$days[ $key ] = array(
				'date'   => $key,
				'events' => array(),
				'total_events' => 0,
				'total_participants' => 0,
			);
			$current      = $current->modify( '+1 day' );
		}

		foreach ( $events as $event ) {
			$event_day = isset( $event['start'] ) ? substr( $event['start'], 0, 10 ) : null;
			if ( ! $event_day ) {
				continue;
			}

			if ( ! isset( $days[ $event_day ] ) ) {
				$days[ $event_day ] = array(
					'date'   => $event_day,
					'events' => array(),
					'total_events' => 0,
					'total_participants' => 0,
				);
			}

			$days[ $event_day ]['events'][] = $event;
			$days[ $event_day ]['total_events']++;
			$days[ $event_day ]['total_participants'] += (int) ( $event['participants'] ?? 0 );
		}

		return array_values( $days );
	}

	private static function parse_datetime( ?string $value ): ?DateTimeImmutable {
		if ( ! $value ) {
			return null;
		}

		try {
			return new DateTimeImmutable( $value, wp_timezone() );
		} catch ( Exception $exception ) {
			return null;
		}
	}

	private static function get_calendar_block_events( array $resource_lookup, DateTimeImmutable $range_start, DateTimeImmutable $range_end ): array {
		$blocks = array();

		foreach ( $resource_lookup as $resource_id => $resource_meta ) {
			$resource_blocks = ResourceCalendar::get_calendar_blocks( (int) $resource_id );
			if ( empty( $resource_blocks ) ) {
				continue;
			}

			foreach ( $resource_blocks as $block ) {
				$start = self::parse_datetime( $block['start'] ?? '' );
				$end   = self::parse_datetime( $block['end'] ?? '' );
				if ( ! $start || ! $end ) {
					continue;
				}

				if ( $end < $range_start || $start > $range_end ) {
					continue;
				}

				$blocks[] = array(
					'resource'     => array( 'id' => (int) $resource_id ),
					'start'        => self::format_iso( $block['start'] ),
					'end'          => self::format_iso( $block['end'] ),
					'participants' => 0,
					'product_name' => ( '' !== trim( (string) ( $block['summary'] ?? '' ) ) )
						? (string) $block['summary']
						: __( 'Beschikbaarheidsblok', 'sbdp' ),
					'event_type'   => 'calendar_block',
					'description'  => (string) ( $block['description'] ?? '' ),
				);
			}
		}

		return $blocks;
	}

	private static function parse_date( ?string $value ): ?DateTimeImmutable {
		if ( ! $value || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return null;
		}

		try {
			return new DateTimeImmutable( $value . ' 00:00:00', wp_timezone() );
		} catch ( Exception $exception ) {
			return null;
		}
	}
	private static function sanitize_items( $items ) {
		$out = array();
		if ( ! is_array( $items ) ) {
			return $out;
		}

		foreach ( $items as $entry ) {
			$pid   = intval( $entry['product_id'] ?? 0 );
			$start = sanitize_text_field( $entry['start'] ?? '' );
			$end   = sanitize_text_field( $entry['end'] ?? '' );
			if ( ! $pid || ! $start || ! $end ) {
				continue;
			}

			$resource_id = isset( $entry['resource_id'] ) ? (int) $entry['resource_id'] : (int) get_post_meta( $pid, '_sbdp_resource_id', true );
			if ( $resource_id < 0 ) {
				$resource_id = 0;
			}

			$out[] = array(
				'product_id'  => $pid,
				'start'       => $start,
				'end'         => $end,
				'resource_id' => $resource_id,
				'combi'       => isset( $entry['combi'] ) ? absint( $entry['combi'] ) : 0,
				'options'     => array(
					'combiItems' => self::sanitize_compose_combi_items( $entry ),
				),
			);
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $item
	 * @return array<int, array<string, mixed>>
	 */
	private static function sanitize_compose_combi_items( array $item ): array {
		$source_items = array();
		if ( isset( $item['options']['combiItems'] ) && is_array( $item['options']['combiItems'] ) ) {
			$source_items = $item['options']['combiItems'];
		} elseif ( isset( $item['combiItems'] ) && is_array( $item['combiItems'] ) ) {
			$source_items = $item['combiItems'];
		} elseif ( isset( $item['combi_ids'] ) && is_array( $item['combi_ids'] ) ) {
			$timing_map = isset( $item['combi_timing_map'] ) && is_array( $item['combi_timing_map'] ) ? $item['combi_timing_map'] : array();
			$label_map = isset( $item['combi_label_map'] ) && is_array( $item['combi_label_map'] ) ? $item['combi_label_map'] : array();
			foreach ( $item['combi_ids'] as $combi_id ) {
				$id = absint( $combi_id );
				if ( $id <= 0 ) {
					continue;
				}

				$label = isset( $label_map[ $id ] ) ? (string) $label_map[ $id ] : (string) ( $label_map[ (string) $id ] ?? '' );
				if ( $label === '' && function_exists( 'wc_get_product' ) ) {
					$product = wc_get_product( $id );
					$label = $product instanceof WC_Product ? $product->get_name() : '';
				}

				$source_items[] = array(
					'id' => $id,
					'label' => $label,
					'timing' => $timing_map[ $id ] ?? ( $timing_map[ (string) $id ] ?? 'before' ),
				);
			}
		}

		$items = array();
		foreach ( $source_items as $index => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$id = absint( $entry['id'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}

			$timing = (string) ( $entry['timing'] ?? ( $entry['role'] ?? 'before' ) );
			$timing = $timing === 'after' || $timing === 'post' ? 'after' : 'before';
			$label = sanitize_text_field( (string) ( $entry['label'] ?? '' ) );
			if ( $label === '' && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $id );
				$label = $product instanceof WC_Product ? $product->get_name() : '';
			}

			$duration = isset( $entry['durationMinutes'] )
				? (int) $entry['durationMinutes']
				: ( isset( $entry['duration'] ) ? (int) $entry['duration'] : 0 );

			$items[] = array(
				'id' => $id,
				'label' => $label,
				'timing' => $timing,
				'role' => $timing === 'after' ? 'post' : 'pre',
				'order' => isset( $entry['order'] ) ? max( 0, (int) $entry['order'] ) : count( $items ),
				'duration' => $duration,
				'durationMinutes' => $duration,
			);
		}

		return $items;
	}

	/**
	 * Build a normalized customer payload from compose-booking request fields.
	 *
	 * Accepts both flat legacy keys (`customer_name`, `customer_email`, ...)
	 * and structured payloads (`customer`, `customer_billing`, `customer_shipping`).
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private static function normalize_compose_customer_payload( array $payload ): array {
		$structured_customer = isset( $payload['customer'] ) && is_array( $payload['customer'] ) ? $payload['customer'] : array();
		$billing_input       = isset( $payload['customer_billing'] ) && is_array( $payload['customer_billing'] )
			? $payload['customer_billing']
			: ( isset( $structured_customer['billing'] ) && is_array( $structured_customer['billing'] ) ? $structured_customer['billing'] : array() );
		$shipping_input      = isset( $payload['customer_shipping'] ) && is_array( $payload['customer_shipping'] )
			? $payload['customer_shipping']
			: ( isset( $structured_customer['shipping'] ) && is_array( $structured_customer['shipping'] ) ? $structured_customer['shipping'] : array() );

		$customer = array_filter(
			array(
				'id'      => isset( $payload['customer_id'] ) ? max( 0, (int) $payload['customer_id'] ) : ( isset( $structured_customer['id'] ) ? max( 0, (int) $structured_customer['id'] ) : 0 ),
				'name'    => self::compose_scalar( $payload['customer_name'] ?? ( $structured_customer['name'] ?? '' ) ),
				'email'   => self::compose_email( $payload['customer_email'] ?? ( $structured_customer['email'] ?? '' ) ),
				'phone'   => self::compose_scalar( $payload['customer_phone'] ?? ( $structured_customer['phone'] ?? '' ) ),
				'company' => self::compose_scalar( $payload['customer_company'] ?? ( $structured_customer['company'] ?? '' ) ),
			),
			static function ( $value ) {
				return ! ( $value === '' || $value === 0 || $value === null );
			}
		);

		$billing = self::normalize_compose_address_payload( $billing_input );
		if ( $billing !== array() ) {
			$customer['billing'] = $billing;
		}

		$shipping = self::normalize_compose_address_payload( $shipping_input );
		if ( $shipping !== array() ) {
			$customer['shipping'] = $shipping;
		}

		return $customer;
	}

	/**
	 * @param array<string, mixed> $address
	 * @return array<string, string>
	 */
	private static function normalize_compose_address_payload( array $address ): array {
		return array_filter(
			array(
				'company'   => self::compose_scalar( $address['company'] ?? '' ),
				'address_1' => self::compose_scalar( $address['address_1'] ?? '' ),
				'address_2' => self::compose_scalar( $address['address_2'] ?? '' ),
				'postcode'  => self::compose_scalar( $address['postcode'] ?? '' ),
				'city'      => self::compose_scalar( $address['city'] ?? '' ),
				'state'     => self::compose_scalar( $address['state'] ?? '' ),
				'country'   => self::compose_scalar( $address['country'] ?? '' ),
			),
			static fn( $value ) => $value !== ''
		);
	}

	/**
	 * @param mixed $value
	 */
	private static function compose_scalar( $value ): string {
		return is_scalar( $value ) ? trim( wp_strip_all_tags( (string) $value ) ) : '';
	}

	/**
	 * @param mixed $value
	 */
	private static function compose_email( $value ): string {
		$email = is_scalar( $value ) ? trim( (string) $value ) : '';
		return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : '';
	}

	/**
	 * @param array<string, mixed> $customer
	 */
	private static function apply_compose_customer_to_order( WC_Order $order, array $customer ): void {
		if ( $customer === array() ) {
			return;
		}

		if ( ! empty( $customer['id'] ) && method_exists( $order, 'set_customer_id' ) ) {
			$order->set_customer_id( (int) $customer['id'] );
		}

		$name = isset( $customer['name'] ) ? (string) $customer['name'] : '';
		if ( $name !== '' ) {
			$parts = preg_split( '/\s+/', $name, 2 ) ?: array();
			$first = $parts[0] ?? $name;
			$last  = $parts[1] ?? '';

			if ( method_exists( $order, 'set_billing_first_name' ) ) {
				$order->set_billing_first_name( $first );
			}
			if ( method_exists( $order, 'set_billing_last_name' ) ) {
				$order->set_billing_last_name( $last );
			}
		}

		if ( isset( $customer['email'] ) && method_exists( $order, 'set_billing_email' ) ) {
			$order->set_billing_email( (string) $customer['email'] );
		}
		if ( isset( $customer['phone'] ) && method_exists( $order, 'set_billing_phone' ) ) {
			$order->set_billing_phone( (string) $customer['phone'] );
		}
		if ( isset( $customer['company'] ) && method_exists( $order, 'set_billing_company' ) ) {
			$order->set_billing_company( (string) $customer['company'] );
		}

		foreach ( array( 'billing', 'shipping' ) as $type ) {
			$address = isset( $customer[ $type ] ) && is_array( $customer[ $type ] ) ? $customer[ $type ] : array();
			if ( $address === array() ) {
				continue;
			}

			foreach ( array( 'company', 'address_1', 'address_2', 'postcode', 'city', 'state', 'country' ) as $field ) {
				$method = sprintf( 'set_%s_%s', $type, $field );
				if ( method_exists( $order, $method ) ) {
					$order->{$method}( (string) ( $address[ $field ] ?? '' ) );
				}
			}
		}
	}

	private static function first_cart_error_notice(): string {
		if ( ! function_exists( 'wc_get_notices' ) ) {
			return '';
		}

		$notices = wc_get_notices( 'error' );

		if ( empty( $notices ) || ! is_array( $notices ) ) {
			return '';
		}

		$first = reset( $notices );

		if ( function_exists( 'wc_clear_notices' ) ) {
			wc_clear_notices();
		}

		if ( is_array( $first ) && isset( $first['notice'] ) ) {
			return wp_strip_all_tags( (string) $first['notice'] );
		}

		if ( is_string( $first ) ) {
			return wp_strip_all_tags( $first );
		}

		return '';
	}
	private static function snapshot_itinerary( array $items, int $participants ): array {
		$snapshot = array(
			'participants' => max( 0, (int) $participants ),
			'items'        => array(),
		);

		foreach ( $items as $entry ) {
			$snapshot['items'][] = array(
				'product_id'  => intval( $entry['product_id'] ?? 0 ),
				'resource_id' => intval( $entry['resource_id'] ?? 0 ),
				'start'       => sanitize_text_field( $entry['start'] ?? '' ),
				'end'         => sanitize_text_field( $entry['end'] ?? '' ),
			);
		}

		return $snapshot;
	}

	private static function ensure_cart_session() {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		if ( null === WC()->session && method_exists( WC(), 'initialize_session' ) ) {
			WC()->initialize_session();
		}

		if ( function_exists( 'wc_load_cart' ) ) {
			if ( null === WC()->cart || ! WC()->cart ) {
				wc_load_cart();
			}
		} elseif ( null === WC()->cart && class_exists( 'WC_Cart' ) ) {
			WC()->cart = new WC_Cart();
		}
	}

	private static function build_availability_payload( int $product_id, int $resource_id, string $date ): array {
		$key   = $resource_id ? "_sbdp_av_rules_res_{$resource_id}" : '_sbdp_av_rules';
		$rules = get_post_meta( $product_id, $key, true );
		if ( ! is_array( $rules ) ) {
			$rules = AvailabilityRules::defaultRules();
		}

		$blocks = self::blocks_for_date( $date, $rules );

		$cap_key  = $resource_id ? "_sbdp_capacity_res_{$resource_id}" : '_sbdp_capacity_default';
		$capacity = (int) get_post_meta( $product_id, $cap_key, true );
		if ( $capacity < 0 ) {
			$capacity = 0;
		}

		return array(
			'blocks'   => $blocks,
			'capacity' => $capacity,
		);
	}

	private static function blocks_for_date( $date, $rules ) {
		$blocks = array();
		$start  = $date . 'T10:00:00';
		$end    = $date . 'T24:00:00';

		$default = $rules['default'] ?? 'open';
		if ( 'closed' === $default ) {
			$blocks[] = array(
				'start'   => $start,
				'end'     => $end,
				'display' => 'background',
				'color'   => '#fee2e2',
			);
		}

		if ( ! empty( $rules['exclude_weekdays'] ) ) {
			$dow = (int) date( 'w', strtotime( $date ) );
			if ( in_array( $dow, array_map( 'intval', $rules['exclude_weekdays'] ), true ) ) {
				$blocks[] = array(
					'start'   => $start,
					'end'     => $end,
					'display' => 'background',
					'color'   => '#fecaca',
				);
			}
		}

		if ( ! empty( $rules['exclude_months'] ) ) {
			$month = (int) date( 'n', strtotime( $date ) );
			if ( in_array( $month, array_map( 'intval', $rules['exclude_months'] ), true ) ) {
				$blocks[] = array(
					'start'   => $start,
					'end'     => $end,
					'display' => 'background',
					'color'   => '#fecaca',
				);
			}
		}

		if ( ! empty( $rules['exclude_times'] ) && is_array( $rules['exclude_times'] ) ) {
			foreach ( $rules['exclude_times'] as $time ) {
				$s        = $date . 'T' . sanitize_text_field( $time['start'] ?? '00:00' ) . ':00';
				$e        = $date . 'T' . sanitize_text_field( $time['end'] ?? '00:00' ) . ':00';
				$blocks[] = array(
					'start'   => $s,
					'end'     => $e,
					'display' => 'background',
					'color'   => '#fca5a5',
				);
			}
		}

		if ( ! empty( $rules['overrides'] ) && is_array( $rules['overrides'] ) ) {
			$midday = strtotime( $date . ' 12:00' );
			foreach ( $rules['overrides'] as $override ) {
				$from = strtotime( ( $override['from'] ?? '' ) . ' 00:00' );
				$to   = strtotime( ( $override['to'] ?? '' ) . ' 23:59' );
				if ( ! $from || ! $to ) {
					continue;
				}
				if ( $midday >= $from && $midday <= $to ) {
					$mode = $override['mode'] ?? 'closed';
					if ( 'closed' === $mode ) {
						$blocks[] = array(
							'start'   => $start,
							'end'     => $end,
							'display' => 'background',
							'color'   => '#f87171',
						);
					} else {
						$blocks = array();
					}
				}
			}
		}

		return $blocks;
	}


    public static function handle_product_saved( int $post_id, ?WP_Post $post = null ): void {
        $post_type = $post instanceof WP_Post ? $post->post_type : get_post_type( $post_id );

        if ( 'product' !== $post_type ) {
            return;
        }

        self::clear_services_cache();
    }

    public static function handle_deleted_post( int $post_id ): void {
        if ( 'product' !== get_post_type( $post_id ) ) {
            return;
        }

        self::clear_services_cache();
    }

    public static function handle_terms_set( int $object_id, $terms, $tt_ids, string $taxonomy, $append, $old_tt_ids ): void {
        if ( 'product' === get_post_type( $object_id ) || 'product_type' === $taxonomy ) {
            self::clear_services_cache();
        }
    }

    private static function clear_services_cache(): void {
        $locales = array();

        if ( function_exists( 'determine_locale' ) ) {
            $locales[] = determine_locale();
        }

        if ( function_exists( 'get_locale' ) ) {
            $locales[] = get_locale();
        }

        $locales[] = 'default';

        foreach ( array_unique( array_filter( $locales, static fn( $value ) => '' !== (string) $value ) ) as $locale ) {
            $key = self::build_services_cache_key( (string) $locale );
            wp_cache_delete( $key, 'sbdp_core' );
            delete_transient( $key );
        }
    }

}
