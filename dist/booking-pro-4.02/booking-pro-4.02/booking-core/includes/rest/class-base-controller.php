<?php

/**
 * Base REST Controller.
 *
 * @package Booking_Core
 */

namespace Booking_Core\Rest;

use WP_REST_Controller;
use WP_Error;

abstract class Base_Controller extends WP_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'sbdp/v1';

	/**
	 * Check permissions for read requests.
	 *
	 * @return true|WP_Error
	 */
	public function check_read_permissions() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error( 'booking_core_forbidden', __( 'You are not allowed to access this resource.', 'booking-core' ), array( 'status' => 403 ) );
	}

	/**
	 * Check permissions for write requests.
	 *
	 * @return true|WP_Error
	 */
	public function check_write_permissions() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error( 'booking_core_forbidden', __( 'You are not allowed to modify this resource.', 'booking-core' ), array( 'status' => 403 ) );
	}
}

