<?php

/**
 * REST API bootstrap.
 *
 * @package Booking_Core
 */

namespace Booking_Core;

use Booking_Core\Rest\Bookings_Controller;
use Booking_Core\Rest\Resources_Controller;
use Booking_Core\Rest\Availability_Controller;

final class Rest_Api {

	/**
	 * Register REST API controllers.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Instantiate controllers and register routes.
	 */
	public static function register_routes(): void {
		( new Bookings_Controller() )->register_routes();
		( new Resources_Controller() )->register_routes();
		( new Availability_Controller() )->register_routes();
	}
}
