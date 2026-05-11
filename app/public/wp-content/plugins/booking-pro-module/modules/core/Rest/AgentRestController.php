<?php

declare(strict_types=1);

namespace BSPModule\Core\Rest;

use BSP\DayPlanner\Service\PlanService;
use BSP\DayPlanner\Service\PlannerEventLogger;
use BSPModule\Core\Admin\AdminMenu;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class AgentRestController {
	private const RATE_LIMIT_WINDOW = 60;
	private const RATE_LIMIT_READ_MAX = 60;
	private const RATE_LIMIT_WRITE_MAX = 20;
	private const RATE_LIMIT_PREFIX = 'bsp_agent_rest_';

	private ?PlanService $dayPlannerService = null;

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
				'permission_callback' => array( $this, 'allow_public_read' ),
			)
		);
		register_rest_route(
			'bsp/v1',
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_health' ),
				'permission_callback' => array( $this, 'allow_public_read' ),
			)
		);

		register_rest_route(
			'bsp/v1',
			'/core/governance',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_governance' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			'bsp/v1',
			'/core/governance/cockpit',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_governance_cockpit' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'window' => array(
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
					'tab'    => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			'bsp/v1',
			'/core/metrics',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_prometheus_metrics' ),
				'permission_callback' => array( $this, 'allow_public_read' ),
				'args'                => array(
					'token'  => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'window' => array(
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'bsp/v1',
			'/chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_chat' ),
				'permission_callback' => array( $this, 'allow_public_write' ),
			)
		);

		register_rest_route(
			'bsp/v1',
			'/plan',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_plan' ),
				'permission_callback' => array( $this, 'allow_public_write' ),
			)
		);

		register_rest_route(
			'bsp/v1',
			'/suggestions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_suggestions' ),
				'permission_callback' => array( $this, 'allow_public_read' ),
			)
		);

		register_rest_route(
			'bsp/v1',
			'/events',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_events' ),
				'permission_callback' => array( $this, 'allow_public_write' ),
				'args'                => array(
					'event'    => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
					'severity' => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	public function check_permissions(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' );
	}

	/**
	 * @return true|WP_Error
	 */
	public function allow_public_read( WP_REST_Request $request ) {
		return $this->check_rate_limit( $request, self::RATE_LIMIT_READ_MAX );
	}

	/**
	 * @return true|WP_Error
	 */
	public function allow_public_write( WP_REST_Request $request ) {
		return $this->check_rate_limit( $request, self::RATE_LIMIT_WRITE_MAX );
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

	public function rest_governance( WP_REST_Request $request ): WP_REST_Response {
		$window = (int) $request->get_param( 'window' );
		if ( $window <= 0 ) {
			$window = 14;
		}

		$payload = AdminMenu::get_governance_bootstrap( $window );

		return new WP_REST_Response(
			array(
				'governance' => $payload,
			)
		);
	}

	public function rest_governance_cockpit( WP_REST_Request $request ): WP_REST_Response {
		$window = (int) $request->get_param( 'window' );
		if ( $window <= 0 ) {
			$window = 14;
		}

		$tab = sanitize_key( (string) $request->get_param( 'tab' ) );
		$payload = AdminMenu::get_governance_cockpit_snapshot( $window, $tab !== '' ? $tab : 'strategy' );

		return new WP_REST_Response(
			array(
				'cockpit' => $payload,
			)
		);
	}

	public function rest_prometheus_metrics( WP_REST_Request $request ) {
		$window = (int) $request->get_param( 'window' );
		if ( $window <= 0 ) {
			$window = 14;
		}

		$settings = get_option( 'sbdp_governance_alert_settings', array() );
		$requiredToken = is_array( $settings ) && isset( $settings['metrics_token'] )
			? trim( (string) $settings['metrics_token'] )
			: '';
		$providedToken = trim( (string) $request->get_param( 'token' ) );

		if ( $requiredToken !== '' && ! hash_equals( $requiredToken, $providedToken ) ) {
			return new WP_Error(
				'sbdp_forbidden',
				'Invalid metrics token.',
				array( 'status' => 403 )
			);
		}

		$body = AdminMenu::governance_prometheus_metrics( $window );

		return new WP_REST_Response(
			$body,
			200,
			array(
				'Content-Type'  => 'text/plain; version=0.0.4; charset=utf-8',
				'Cache-Control' => 'no-store',
			)
		);
	}

	public function rest_chat( WP_REST_Request $request ): WP_REST_Response {
		$payload = $this->request_payload( $request );
		$response = $this->planner_service()->suggestActivities( $payload );

		return new WP_REST_Response(
			array(
				'assistant_response' => $response['assistant_response'] ?? array(),
				'summary'            => $response['summary'] ?? '',
				'meta'               => $response['meta'] ?? array(),
			)
		);
	}

	public function rest_plan( WP_REST_Request $request ): WP_REST_Response {
		$payload = $this->request_payload( $request );
		$response = $this->planner_service()->suggestActivities( $payload );
		$assistant = is_array( $response['assistant_response'] ?? null ) ? $response['assistant_response'] : array();

		return new WP_REST_Response(
			array(
				'type'           => 'plan_response',
				'primary'        => $assistant['primary'] ?? array(),
				'alternatives'   => $assistant['alternatives'] ?? array(),
				'plan'           => $assistant['plan'] ?? array( 'timeline' => array() ),
				'questions'      => $assistant['questions'] ?? array(),
				'decision_trace' => $assistant['decision_trace'] ?? array(),
				'meta'           => $response['meta'] ?? array(),
			)
		);
	}

	public function rest_suggestions( WP_REST_Request $request ): WP_REST_Response {
		$payload = $request->get_params();
		$response = $this->planner_service()->suggestActivities( is_array( $payload ) ? $payload : array() );

		return new WP_REST_Response(
			array(
				'summary'            => $response['summary'] ?? '',
				'activities'         => $response['activities'] ?? array(),
				'assistant_response' => $response['assistant_response'] ?? array(),
				'meta'               => $response['meta'] ?? array(),
			)
		);
	}

	public function rest_events( WP_REST_Request $request ): WP_REST_Response {
		$payload = $this->request_payload( $request );
		$eventName = isset( $payload['event'] ) ? sanitize_key( (string) $payload['event'] ) : '';
		if ( $eventName === '' ) {
			$eventName = 'custom_event';
		}
		$severity = isset( $payload['severity'] ) ? sanitize_key( (string) $payload['severity'] ) : 'info';
		if ( ! in_array( $severity, array( 'info', 'warning', 'error', 'success' ), true ) ) {
			$severity = 'info';
		}

		$loggerPayload = is_array( $payload['payload'] ?? null ) ? $payload['payload'] : $payload;
		( new PlannerEventLogger() )->log( $eventName, $loggerPayload, $severity );

		return new WP_REST_Response(
			array(
				'accepted' => true,
				'event'    => $eventName,
				'severity' => $severity,
				'logged_at'=> gmdate( 'c' ),
			),
			202
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function request_payload( WP_REST_Request $request ): array {
		$params = $request->get_json_params();
		if ( is_array( $params ) ) {
			return $params;
		}

		$params = $request->get_body_params();
		if ( is_array( $params ) ) {
			return $params;
		}

		$params = $request->get_params();
		return is_array( $params ) ? $params : array();
	}

	private function planner_service(): PlanService {
		if ( $this->dayPlannerService === null ) {
			$this->dayPlannerService = new PlanService();
		}

		return $this->dayPlannerService;
	}

	/**
	 * @return true|WP_Error
	 */
	private function check_rate_limit( WP_REST_Request $request, int $maxAttempts ) {
		$bucket = $this->rate_limit_key( $request );
		$state = get_transient( $bucket );
		$state = is_array( $state ) ? $state : array();
		$attempts = (int) ( $state['attempts'] ?? 0 ) + 1;

		if ( $attempts > $maxAttempts ) {
			return new WP_Error(
				'bsp_rate_limited',
				'Too many requests.',
				array( 'status' => 429 )
			);
		}

		set_transient(
			$bucket,
			array( 'attempts' => $attempts ),
			self::RATE_LIMIT_WINDOW
		);

		return true;
	}

	private function rate_limit_key( WP_REST_Request $request ): string {
		$route = trim( $request->get_route(), '/' );
		$ip = $this->request_ip();
		$nonce = $this->request_nonce( $request );
		$user_id = (string) get_current_user_id();

		return self::RATE_LIMIT_PREFIX . md5( $route . '|' . $ip . '|' . $nonce . '|' . $user_id );
	}

	private function request_ip(): string {
		$trusted_proxy = (bool) apply_filters( 'sbdp/rest/trust_forwarded_ip', false );
		$candidates = $trusted_proxy
			? array(
				$_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
				$_SERVER['REMOTE_ADDR'] ?? '',
			)
			: array(
				$_SERVER['REMOTE_ADDR'] ?? '',
			);

		foreach ( $candidates as $candidate ) {
			if ( ! is_string( $candidate ) || $candidate === '' ) {
				continue;
			}

			$ip = trim( explode( ',', $candidate )[0] );
			if ( $ip !== '' && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}

		return 'unknown';
	}

	private function request_nonce( WP_REST_Request $request ): string {
		$nonce = $request->get_header( 'x-sbdp-nonce' );
		if ( ! is_string( $nonce ) || $nonce === '' ) {
			$nonce = $request->get_header( 'X-WP-Nonce' );
		}

		return is_string( $nonce ) ? sanitize_text_field( $nonce ) : '';
	}
}
