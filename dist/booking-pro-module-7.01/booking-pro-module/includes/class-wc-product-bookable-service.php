<?php
/**
 * Custom WooCommerce product implementation for bookable services.
 *
 * @package SBDP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( class_exists( 'WC_Product_Bookable_Service', false ) ) {
    return;
}

if ( ! class_exists( 'WC_Product', false ) ) {
    return;
}

class WC_Product_Bookable_Service extends WC_Product {

    /**
     * Return the internal type identifier.
     */
    public function get_type() {
        return SBDP_Product_Type::PRODUCT_TYPE;
    }
}
