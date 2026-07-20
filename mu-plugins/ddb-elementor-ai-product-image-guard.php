<?php
/**
 * Prevent Elementor AI product-image AJAX from fatalling on stale/non-product IDs.
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_elementor-ai-get-product-images', static function (): void {
    wp_send_json_success(array('product_images' => array()));
}, 0);
