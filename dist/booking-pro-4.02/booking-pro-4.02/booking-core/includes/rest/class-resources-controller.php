<?php

/**
 * Resources REST controller.
 *
 * @package Booking_Core
 */

namespace Booking_Core\Rest;

use WP_REST_Request;
use WP_REST_Response;

final class Resources_Controller extends Base_Controller {

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'resources';

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'check_read_permissions' ),
				),
			),
			false
		);
	}

	/**
	 * Fetch resources (stub).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_items( $request ): WP_REST_Response {  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$data = apply_filters( 'booking_core/rest/resources', array() );

		return new WP_REST_Response( $data, 200 );
	}
}

