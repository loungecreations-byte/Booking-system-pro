<?php
/**
 * Plugin Name: DDB Activiteiten Fixes
 * Description: Fixes for WooCommerce buttons, price suffix, and elementor overrides.
 * Author: Fix Automation
 * Version: 1.0
 */

// 1. Rename 'Read more' to 'Bekijk activiteit'
add_filter( 'woocommerce_product_add_to_cart_text', function( $text, $product ) {
    if ( ! $product->is_purchasable() || ! $product->is_in_stock() || $product->get_type() === 'bookable_service' || $text === 'Read more' ) {
        return 'Bekijk activiteit';
    }
    return $text;
}, 10, 2 );

// 2. Styling moved into the canonical route-scoped CSS layer.
