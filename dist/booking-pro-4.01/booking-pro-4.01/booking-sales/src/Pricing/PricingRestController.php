<?php

declare(strict_types=1);

namespace BSP\Sales\Pricing;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use wpdb;

use function absint;
use function add_action;
use function current_time;
use function current_user_can;
use function is_array;
use function is_wp_error;
use function json_decode;
use function register_rest_route;
use function rest_ensure_response;
use function sanitize_text_field;
use function wp_json_encode;

use const ARRAY_A;

final class PricingRestController {

	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'registerRoutes' ) );
	}

	public static function registerRoutes(): void {
		register_rest_route(
			'bsp/v1',
			'/pricing/quote',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'quote' ),
					'permission_callback' => array( self::class, 'canManagePricing' ),
					'args'                => array(
						'product_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'quantity'   => array(
							'required'          => false,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'default'           => 1,
						),
						'channel'    => array(
							'required' => false,
							'type'     => 'string',
						),
					),
				),
			)
		);

		register_rest_route(
			'bsp/v1',
			'/yield/rules',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'listRules' ),
					'permission_callback' => array( self::class, 'canManagePricing' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'createRule' ),
					'permission_callback' => array( self::class, 'canManagePricing' ),
				),
			)
		);

		register_rest_route(
			'bsp/v1',
			'/yield/log',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'getLog' ),
					'permission_callback' => array( self::class, 'canManagePricing' ),
					'args'                => array(
						'product_id' => array(
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	public static function canManagePricing(): bool {
		return current_user_can( 'manage_bsp_sales' ) || current_user_can( 'manage_woocommerce' );
	}

	public static function quote( WP_REST_Request $request ) {
		$productId = absint( $request->get_param( 'product_id' ) );
		$quantity  = max( 1, absint( $request->get_param( 'quantity' ) ?? 1 ) );
		$channel   = $request->get_param( 'channel' );

		$result = PricingService::quote(
			$productId,
			$quantity,
			array(
				'channel' => $channel ? sanitize_text_field( (string) $channel ) : 'web',
			)
		);

		if ( ! $result['success'] ) {
			return $result['error'] instanceof WP_Error
				? $result['error']
				: new WP_Error( 'bsp_sales_quote_failed', __( 'Unable to produce quote.', 'sbdp' ) );
		}

		return rest_ensure_response( $result );
	}

	public static function listRules(): WP_REST_Response {
		global $wpdb;
		$rows = array();
		if ( $wpdb instanceof wpdb ) {
			$table = $wpdb->prefix . 'bsp_yield_rules';
			$rows  = $wpdb->get_results( "SELECT id, name, priority, active, created_at, updated_at FROM {$table} ORDER BY priority DESC, id ASC", ARRAY_A ) ?: array();
		}

		return rest_ensure_response( array( 'rules' => $rows ) );
	}

	public static function createRule( WP_REST_Request $request ) {
		$body = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'bsp_sales_invalid_payload', __( 'Invalid payload.', 'sbdp' ), array( 'status' => 400 ) );
		}

		$name       = isset( $body['name'] ) ? sanitize_text_field( (string) $body['name'] ) : '';
		$conditions = $body['conditions'] ?? array();
		$adjustment = $body['adjustment'] ?? array();
		$priority   = isset( $body['priority'] ) ? absint( (int) $body['priority'] ) : 0;

		if ( $name === '' ) {
			return new WP_Error( 'bsp_sales_missing_name', __( 'Rule name is required.', 'sbdp' ), array( 'status' => 422 ) );
		}

		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return new WP_Error( 'bsp_sales_db_unavailable', __( 'Database unavailable.', 'sbdp' ), array( 'status' => 500 ) );
		}

		$table = $wpdb->prefix . 'bsp_yield_rules';
		$wpdb->insert(
			$table,
			array(
				'name'            => $name,
				'condition_json'  => wp_json_encode( $conditions ),
				'adjustment_json' => wp_json_encode( $adjustment ),
				'priority'        => $priority,
				'active'          => isset( $body['active'] ) ? (int) (bool) $body['active'] : 1,
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s' )
		);

		YieldEngine::flushRuleCache();

		return rest_ensure_response(
			array(
				'id'   => (int) $wpdb->insert_id,
				'name' => $name,
			)
		);
	}

	public static function getLog( WP_REST_Request $request ): WP_REST_Response {
		$productId = absint( $request->get_param( 'product_id' ) );

		global $wpdb;
		$entries = array();
		if ( $wpdb instanceof wpdb ) {
			$table = $wpdb->prefix . 'bsp_price_log';
			$sql   = "SELECT id, product_id, rule_id, base_price, adjusted_price, channel, logged_at FROM {$table}";
			if ( $productId > 0 ) {
				$sql .= $wpdb->prepare( ' WHERE product_id = %d', $productId );
			}
			$sql    .= ' ORDER BY logged_at DESC LIMIT 100';
			$entries = $wpdb->get_results( $sql, ARRAY_A ) ?: array();
		}

		return rest_ensure_response( array( 'log' => $entries ) );
	}
}

if ( ! class_exists( 'BSPModule\\Sales\\Pricing\\RestController' ) ) {
	\class_alias( PricingRestController::class, 'BSPModule\\Sales\\Pricing\\RestController' );
}
