<?php

/**
 * Bookings REST controller.
 *
 * @package Booking_Core
 */

namespace Booking_Core\Rest;

use WP_REST_Request;
use WP_REST_Response;

final class Bookings_Controller extends Base_Controller {

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'bookings';

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
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'check_write_permissions' ),
				),
			),
			false
		);
	}

	/**
	 * Fetch bookings (stub).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_items( $request ): WP_REST_Response {  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$data = apply_filters( 'booking_core/rest/bookings', array() );

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Create booking placeholder.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function create_item( $request ): WP_REST_Response {
		do_action( 'booking_core/rest/create_booking', $request );

		return new WP_REST_Response(
			array(
				'message' => __( 'Booking creation endpoint not yet implemented.', 'booking-core' ),
			),
			202
		);
	}
}
