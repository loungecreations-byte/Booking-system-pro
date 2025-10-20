<?php

declare(strict_types=1);

namespace BSPModule\Core\Rest;

use WP_REST_Request;
use WP_REST_Response;

final class AgentRestController {

	public function register_routes(): void {
		register_rest_route(
			'bsp/v1',
			'/agents',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_agents' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			'bsp/v1',
			'/core/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_health' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function check_permissions(): bool {
		return current_user_can( 'manage_options' );
	}

	public function get_agents( WP_REST_Request $request ): WP_REST_Response {
		$report = \BSP_Core_Agent::instance()->diagnostics();

		return new WP_REST_Response(
			array(
				'agents' => $report,
			)
		);
	}

	public function rest_health( $request ): WP_REST_Response {
		global $wpdb;

		$table_count = 0;

		if ( isset( $wpdb ) && $wpdb instanceof \wpdb ) {
			$like        = $wpdb->esc_like( $wpdb->prefix . 'bsp_' ) . '%';
			$results     = (array) $wpdb->get_results( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
			$table_count = count( $results );
		}

		return new WP_REST_Response(
			array(
				'status'      => 'ok',
				'php_version' => PHP_VERSION,
				'memory'      => ini_get( 'memory_limit' ),
				'table_count' => $table_count,
				'timestamp'   => gmdate( 'c' ),
			)
		);
	}
}
