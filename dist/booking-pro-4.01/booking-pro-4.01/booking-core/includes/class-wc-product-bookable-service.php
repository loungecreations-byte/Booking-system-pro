<?php

/**
 * WooCommerce product class for bookable services.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'WC_Product_Bookable_Service', false ) ) {
	return;
}

class WC_Product_Bookable_Service extends WC_Product {

	/**
	 * Product type identifier.
	 */
	public function get_type() {
		return \Booking_Core\Product_Type::TYPE;
	}
}
