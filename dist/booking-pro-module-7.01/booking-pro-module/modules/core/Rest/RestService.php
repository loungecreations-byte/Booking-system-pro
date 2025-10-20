<?php

declare(strict_types=1);

namespace BSPModule\Core\Rest;

use WC_Cart;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
use BSPModule\Core\Product\AvailabilityRules;
use BSPModule\Core\Admin\AdminMenu;
use WP_Error;
use WP_Query;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use DateTimeImmutable;
use Exception;

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
				'permission_callback' => '__return_true',
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

		register_rest_route(
			'sbdp/v1',
			'/availability/rules',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'get_rules' ),
			)
		);

		register_rest_route(
			'sbdp/v1',
			'/availability/rules',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'save_rules' ),
			)
		);

		register_rest_route(
			'sbdp/v1',
			'/availability/preview',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'preview_availability' ),
			)
		);

		register_rest_route(
			'sbdp/v1',
			'/availability/plan',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'plan_availability' ),
			)
		);

		register_rest_route(
			'sbdp/v1',
			'/resources',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'get_resources' ),
			)
		);

		register_rest_route(
			'sbdp/v1',
			'/pricing/rules',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'get_pricing_rules' ),
			)
		);

		register_rest_route(
			'sbdp/v1',
			'/pricing/rules',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
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
				'permission_callback' => '__return_true',
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
	}

	public static function can_manage_woocommerce(): bool {
		return current_user_can( 'manage_woocommerce' );
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
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, order_id, status, start_datetime, end_datetime, total, currency, meta FROM {$bookings_table} WHERE start_datetime BETWEEN %s AND %s ORDER BY start_datetime ASC",
				$start,
				$end
			),
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
				'start'    => self::format_iso( $row['start_datetime'] ?? '' ),
				'end'      => self::format_iso( $row['end_datetime'] ?? '' ),
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

		if ( $assignments_table ) {
			$sql    = "SELECT a.id, a.booking_id, a.resource_id, a.start_datetime, a.end_datetime, b.status, b.total, b.currency, b.meta, r.title AS resource_title FROM {$assignments_table} a INNER JOIN {$bookings_table} b ON b.id = a.booking_id LEFT JOIN {$resources_table} r ON r.id = a.resource_id WHERE a.start_datetime BETWEEN %s AND %s";
			$params = [ $start, $end ];
			if ( null !== $resource_id ) {
				$sql    .= ' AND a.resource_id = %d';
				$params[] = $resource_id;
			}
			$sql      .= ' ORDER BY a.start_datetime ASC';
			$prepared = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $params ) );
			$rows     = $prepared ? $wpdb->get_results( $prepared, ARRAY_A ) : array();
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, start_datetime, end_datetime, status, total, currency, meta FROM {$bookings_table} WHERE start_datetime BETWEEN %s AND %s ORDER BY start_datetime ASC",
					$start,
					$end
				),
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
				'start'       => self::format_iso( $row['start_datetime'] ?? '' ),
				'end'         => self::format_iso( $row['end_datetime'] ?? '' ),
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
		$headers = array( 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR' );

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
			if ( '' !== $value ) {
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
			$nonce = $request->get_param( '_sbdp_nonce' );
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

		if ( ! $nonce || ! wp_verify_nonce( $nonce, self::PUBLIC_NONCE_ACTION ) ) {
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

	public static function get_services( WP_REST_Request $request ) {
		$limit = self::check_rate_limit( $request, 'services' );
		if ( $limit instanceof WP_Error ) {
			return $limit;
		}

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
			$services[] = array(
				'id'          => $pid,
				'name'        => get_the_title(),
				'price'       => (float) get_post_meta( $pid, '_price', true ),
				'duration'    => (int) ( get_post_meta( $pid, '_sbdp_duration', true ) ?: 60 ),
				'resource_id' => (int) get_post_meta( $pid, '_sbdp_resource_id', true ),
				'thumb'       => get_the_post_thumbnail_url( $pid, 'thumbnail' ),
				'excerpt'     => wp_strip_all_tags( get_the_excerpt( $pid ) ),
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

	public static function compose_booking( WP_REST_Request $request ) {
		$payload      = $request->get_json_params();
		$mode         = sanitize_text_field( $payload['mode'] ?? 'pay' );
		$participants = max( 1, intval( $payload['participants'] ?? 1 ) );
		$items        = self::sanitize_items( $payload['items'] ?? array() );

		if ( empty( $items ) ) {
			return new WP_Error( 'sbdp_no_items', __( 'Geen geldige items ontvangen.', 'sbdp' ), array( 'status' => 400 ) );
		}

		$validation = self::validate_items( $items, $participants );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		if ( $mode === 'pay' ) {
			if ( ! function_exists( 'WC' ) ) {
				return new WP_Error( 'sbdp_no_wc', __( 'WooCommerce niet beschikbaar.', 'sbdp' ), array( 'status' => 500 ) );
			}

			self::ensure_cart_session();
			if ( ! WC()->cart ) {
				return new WP_Error( 'sbdp_no_cart', __( 'Winkelwagen kon niet worden geopend.', 'sbdp' ), array( 'status' => 500 ) );
			}

			WC()->cart->empty_cart();
			$added = false;

			if ( function_exists( 'WC' ) && WC()->session ) {
				WC()->session->set( 'sbdp_mode', 'pay' );
				WC()->session->set( 'sbdp_itinerary', self::snapshot_itinerary( $items, $participants ) );
			}

			foreach ( $items as $item ) {
				$product = wc_get_product( $item['product_id'] );
				if ( ! $product ) {
					return new WP_Error( 'sbdp_invalid_product', __( 'Ongeldige productreferentie.', 'sbdp' ), array( 'status' => 400 ) );
				}

				$resource_id    = intval( $item['resource_id'] ?? get_post_meta( $item['product_id'], '_sbdp_resource_id', true ) );
				$resource_label = self::get_resource_label( $resource_id );
				$pricing        = self::calculate_pricing_for_item( $product, $resource_id, $item['start'], $participants );

				$cart_key = WC()->cart->add_to_cart(
					$item['product_id'],
					$participants,
					0,
					array(),
					array(
						'sbdp_meta' => array(
							'sbdp_start'          => $item['start'],
							'sbdp_end'            => $item['end'],
							'sbdp_participants'   => $participants,
							'sbdp_resource_id'    => $resource_id,
							'sbdp_resource_label' => $resource_label,
						),
					)
				);

				if ( $cart_key ) {
					$added = true;
					if ( isset( WC()->cart->cart_contents[ $cart_key ] ) ) {
						$cart_item = WC()->cart->cart_contents[ $cart_key ];
						if ( isset( $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product ) {
							$cart_item['data']->set_price( $pricing['unit_price'] );
						}
						$cart_item['sbdp_pricing']             = $pricing;
						WC()->cart->cart_contents[ $cart_key ] = $cart_item;
					}
				}
			}

			if ( ! $added ) {
				return new WP_Error( 'sbdp_cart_failed', __( 'Kon geen items aan de winkelwagen toevoegen.', 'sbdp' ), array( 'status' => 500 ) );
			}

			if ( WC()->cart ) {
				WC()->cart->calculate_totals();
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
		foreach ( $items as $item ) {
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
				$pricing     = self::calculate_pricing_for_item( $product, $resource_id, $item['start'], $participants );
				wc_add_order_item_meta( $item_id, 'sbdp_start', $item['start'] );
				wc_add_order_item_meta( $item_id, 'sbdp_end', $item['end'] );
				wc_add_order_item_meta( $item_id, 'sbdp_participants', $qty );
				wc_add_order_item_meta( $item_id, 'sbdp_resource_id', $resource_id );
				wc_add_order_item_meta( $item_id, 'sbdp_resource_label', $resource_label );
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

		if ( ! $product_id || ! $start ) {
			return new WP_Error( 'bad_request', 'product_id & start required', array( 'status' => 400 ) );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'sbdp_invalid_product', __( 'Ongeldige productreferentie.', 'sbdp' ), array( 'status' => 400 ) );
		}

		$pricing = self::calculate_pricing_for_item( $product, $resource_id, $start, $participants );

		return rest_ensure_response( $pricing );
	}

	public static function get_schedule_overview( WP_REST_Request $request ) {
		$date_raw = sanitize_text_field( $request->get_param( 'date' ) );
		$date     = $date_raw && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_raw ) ? $date_raw : current_time( 'Y-m-d' );

		$resources = get_posts(
			array(
				'post_type'      => 'bookable_resource',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$resource_lookup = array();
		foreach ( $resources as $resource ) {
			$resource_lookup[ $resource->ID ] = get_the_title( $resource );
		}

		$status_filter = apply_filters( 'sbdp_schedule_order_statuses', array( 'processing', 'on-hold', 'completed', 'pending' ) );
		$orders        = wc_get_orders(
			array(
				'status'  => $status_filter,
				'limit'   => apply_filters( 'sbdp_schedule_order_limit', 200 ),
				'orderby' => 'date',
				'order'   => 'DESC',
			)
		);

		$events = array();
		if ( $orders ) {
			foreach ( $orders as $order ) {
				foreach ( $order->get_items() as $item ) {
					$start = wc_get_order_item_meta( $item->get_id(), 'sbdp_start' );
					if ( ! $start ) {
						continue;
					}
					$start_ts = strtotime( $start );
					if ( ! $start_ts ) {
						continue;
					}
					$start_date = gmdate( 'Y-m-d', $start_ts );
					if ( $start_date !== $date ) {
						continue;
					}

					$end          = wc_get_order_item_meta( $item->get_id(), 'sbdp_end' );
					$participants = (int) wc_get_order_item_meta( $item->get_id(), 'sbdp_participants' );
					if ( $participants < 1 ) {
						$participants = 1;
					}

					$product_id  = $item->get_product_id();
					$resource_id = 0;
					if ( $product_id ) {
							$resource_id = (int) get_post_meta( $product_id, '_sbdp_resource_id', true );
					}

					$events[] = array(
						'order_id'     => $order->get_id(),
						'order_status' => $order->get_status(),
						'product_id'   => $product_id,
						'product_name' => $item->get_name(),
						'start'        => $start,
						'end'          => $end,
						'participants' => $participants,
						'customer'     => $order->get_formatted_billing_full_name(),
						'resource'     => array(
							'id'   => $resource_id,
							'name' => $resource_id && isset( $resource_lookup[ $resource_id ] ) ? $resource_lookup[ $resource_id ] : '',
						),
						'link'         => $order->get_edit_order_url(),
					);
				}
			}
		}

		return rest_ensure_response(
			array(
				'date'      => $date,
				'resources' => array_map(
					function ( $ID ) use ( $resource_lookup ) {
						return array(
							'id'   => $ID,
							'name' => $resource_lookup[ $ID ],
						);
					},
					array_keys( $resource_lookup )
				),
				'events'    => $events,
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
		$start_dt = self::get_local_datetime( $start );
		$end_dt   = self::get_local_datetime( $end );

		if ( ! $start_dt || ! $end_dt ) {
			return new WP_Error( 'sbdp_bad_time', __( 'Ongeldige datum of tijd ontvangen.', 'sbdp' ), array( 'status' => 400 ) );
		}

		$date = $start_dt->format( 'Y-m-d' );

		$rules_key = $resource_id ? "_sbdp_av_rules_res_{$resource_id}" : '_sbdp_av_rules';
		$rules     = get_post_meta( $product_id, $rules_key, true );

		$blocks = self::blocks_for_date( $date, $rules );
		foreach ( $blocks as $block ) {
			$block_start = $block['start'] ?? '';
			$block_end   = $block['end'] ?? '';
			if ( self::ranges_overlap( $start, $end, $block_start, $block_end ) ) {
				return new WP_Error( 'sbdp_conflict', __( 'De geselecteerde tijd is niet beschikbaar.', 'sbdp' ), array( 'status' => 400 ) );
			}
		}

		$cap_key  = $resource_id ? "_sbdp_capacity_res_{$resource_id}" : '_sbdp_capacity_default';
		$capacity = (int) get_post_meta( $product_id, $cap_key, true );
		if ( $capacity < 0 ) {
			$capacity = 0;
		}

		$conflicts = self::find_overlapping_bookings( $product_id, $resource_id, $start, $end );
		$occupied  = 0;
		foreach ( $conflicts as $existing ) {
			$occupied += max( 1, (int) ( $existing['participants'] ?? 0 ) );
		}

		if ( $capacity > 0 && ( $occupied + $participants ) > $capacity ) {
			return new WP_Error(
				'sbdp_capacity',
				__( 'Er zijn onvoldoende plaatsen beschikbaar voor dit tijdslot.', 'sbdp' ),
				array(
					'status'    => 400,
					'available' => max( 0, $capacity - $occupied ),
					'conflicts' => wp_list_pluck( $conflicts, 'order_id' ),
				)
			);
		}

		$allow_parallel = apply_filters( 'sbdp_allow_parallel_bookings', false, $product_id, $resource_id );
		if ( ! $allow_parallel && ! empty( $conflicts ) ) {
			return new WP_Error(
				'sbdp_conflict',
				__( 'De geselecteerde tijd is niet beschikbaar.', 'sbdp' ),
				array(
					'status'    => 400,
					'conflicts' => wp_list_pluck( $conflicts, 'order_id' ),
				)
			);
		}

		return true;
	}
	private static function find_overlapping_bookings( $product_id, $resource_id, $start, $end ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! $wpdb ) {
			return array();
		}

		$status_filter = apply_filters( 'sbdp_booking_conflict_statuses', array( 'wc-processing', 'wc-on-hold', 'wc-completed', 'wc-pending' ) );
		if ( empty( $status_filter ) ) {
			return array();
		}

		$day = substr( $start, 0, 10 );
		$like = $day ? $wpdb->esc_like( $day ) . '%' : '%';

		$order_items_table     = $wpdb->prefix . 'woocommerce_order_items';
		$order_itemmeta_table  = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$posts_table           = $wpdb->posts;
		$status_placeholders   = implode( ',', array_fill( 0, count( $status_filter ), '%s' ) );

		$sql = "SELECT o.ID AS order_id,
					   o.post_status,
					   start_meta.meta_value AS start_time,
					   end_meta.meta_value AS end_time,
					   participants_meta.meta_value AS participants,
					   COALESCE(resource_meta.meta_value, '') AS resource_id
			FROM {$order_items_table} AS oi
			INNER JOIN {$posts_table} AS o ON o.ID = oi.order_id
			LEFT JOIN {$order_itemmeta_table} AS product_meta ON product_meta.order_item_id = oi.order_item_id AND product_meta.meta_key = '_product_id'
			LEFT JOIN {$order_itemmeta_table} AS start_meta ON start_meta.order_item_id = oi.order_item_id AND start_meta.meta_key = 'sbdp_start'
			LEFT JOIN {$order_itemmeta_table} AS end_meta ON end_meta.order_item_id = oi.order_item_id AND end_meta.meta_key = 'sbdp_end'
			LEFT JOIN {$order_itemmeta_table} AS participants_meta ON participants_meta.order_item_id = oi.order_item_id AND participants_meta.meta_key = 'sbdp_participants'
			LEFT JOIN {$order_itemmeta_table} AS resource_meta ON resource_meta.order_item_id = oi.order_item_id AND resource_meta.meta_key = 'sbdp_resource_id'
			WHERE oi.order_item_type = 'line_item'
			  AND o.post_type = 'shop_order'
			  AND product_meta.meta_value = %d
			  AND o.post_status IN ( {$status_placeholders} )
			  AND start_meta.meta_value IS NOT NULL
			  AND end_meta.meta_value IS NOT NULL
			  AND start_meta.meta_value LIKE %s";

		$params = array_merge( array( $product_id ), $status_filter, array( $like ) );
		if ( $resource_id > 0 ) {
			$sql    .= ' AND ( resource_meta.meta_value = %s )';
			$params[] = (string) $resource_id;
		}

		$prepared = $wpdb->prepare( $sql, $params );
		$rows     = $wpdb->get_results( $prepared, ARRAY_A );

		if ( empty( $rows ) ) {
			return array();
		}

		$conflicts = array();

		foreach ( $rows as $row ) {
			$row_start = $row['start_time'] ?? '';
			$row_end   = $row['end_time'] ?? '';
			if ( ! $row_start || ! $row_end ) {
				continue;
			}
			if ( ! self::ranges_overlap( $start, $end, $row_start, $row_end ) ) {
				continue;
			}

			$conflicts[] = array(
				'order_id'     => (int) $row['order_id'],
				'status'       => (string) $row['post_status'],
				'start'        => $row_start,
				'end'          => $row_end,
				'participants' => max( 1, (int) $row['participants'] ),
				'resource_id'  => (int) ( $row['resource_id'] !== '' ? $row['resource_id'] : 0 ),
			);
		}

		return $conflicts;
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

	private static function calculate_pricing_for_item( $product, $resource_id, $start, $participants ) {
		$base_price = (float) $product->get_price();
		$moment     = self::get_local_datetime( $start );

		$breakdown = array(
			'base_price'         => round( $base_price, 2 ),
			'unit_price'         => round( $base_price, 2 ),
			'booking_adjustment' => 0.0,
			'applied_rules'      => array(),
			'participants'       => $participants,
			'total'              => round( $base_price * $participants, 2 ),
		);

		if ( ! $moment ) {
			return $breakdown;
		}

		$rules = self::get_price_rules_for( $product->get_id(), $resource_id );
		if ( empty( $rules ) ) {
			return $breakdown;
		}

		$unit_price         = $base_price;
		$booking_adjustment = 0.0;

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
					$applied     = $base_price * ( $amount / 100 );
					$unit_price += $applied;
				} else {
					$applied             = ( $base_price * $participants ) * ( $amount / 100 );
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

		return $breakdown;
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
			);
		}
		return $out;
	}
	private static function snapshot_itinerary( array $items, int $participants ): array {
		$snapshot = array(
			'participants' => max( 1, (int) $participants ),
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


