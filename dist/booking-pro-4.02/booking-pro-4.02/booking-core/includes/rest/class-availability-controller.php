<?php

/**
 * Availability REST controller.
 *
 * @package Booking_Core
 */

namespace Booking_Core\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

final class Availability_Controller extends Base_Controller {

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'availability';

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
	 * Fetch availability placeholder.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_items( $request ) {
		$params = array(
			'from' => sanitize_text_field( $request->get_param( 'from' ) ),
			'to'   => sanitize_text_field( $request->get_param( 'to' ) ),
		);

		if ( empty( $params['from'] ) || empty( $params['to'] ) ) {
			return new WP_Error( 'booking_core_missing_params', __( 'Required parameters from/to.', 'booking-core' ), array( 'status' => 400 ) );
		}

		$data = apply_filters( 'booking_core/rest/availability', array(), $params );

		return new WP_REST_Response( $data, 200 );
	}
}






