<?php
/**
 * Plugin Name: SBDP Price Auto-Sync
 * Description: Automatically syncs _sbdp_base_price to WooCommerce _price on product save
 * Version: 1.0.0
 * Author: Booking Pro Module
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('sbdp_price_sync_normalize_decimal')) {
    /**
     * Allow both commas and dots for decimal values.
     *
     * @param mixed $value
     * @return mixed
     */
    function sbdp_price_sync_normalize_decimal($value)
    {
        if (!is_string($value)) {
            return $value;
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return $normalized;
        }

        return str_replace(',', '.', $normalized);
    }
}

if (!function_exists('sbdp_price_sync_get_runtime_override')) {
    /**
     * Prefer a runtime cart/planner override above the persisted base price.
     *
     * @param mixed $product
     * @return float|null
     */
    function sbdp_price_sync_get_runtime_override($product)
    {
        if (!is_object($product) || !method_exists($product, 'get_meta') || !method_exists($product, 'get_price')) {
            return null;
        }

        $locked = $product->get_meta('_sbdp_runtime_price_locked', true);
        $override = sbdp_price_sync_normalize_decimal((string) $product->get_meta('_sbdp_runtime_price_override', true));

        if ($locked === 'yes' && $override !== '' && is_numeric($override) && (float) $override > 0.0) {
            return (float) $override;
        }

        return null;
    }
}

if (!function_exists('sbdp_price_sync_enable_legacy_display_overrides')) {
    /**
     * Legacy display overrides create a second price truth.
     * Keep them disabled unless explicitly re-enabled for recovery/debug.
     */
    function sbdp_price_sync_enable_legacy_display_overrides()
    {
        return (bool) apply_filters('sbdp/price_sync/enable_legacy_display_overrides', false);
    }
}

/**
 * Auto-sync _sbdp_base_price to WooCommerce prices on product save
 */
add_action('save_post_product', function($post_id, $post, $update) {
    // Skip revisions and autosaves
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }
    
    // Skip if not a published product
    if ($post->post_status !== 'publish') {
        return;
    }
    
    // Get product
    $product = wc_get_product($post_id);
    if (!$product) {
        return;
    }
    
    // Only sync for bookable_service products
    if ($product->get_type() !== 'bookable_service') {
        return;
    }
    
    // Get SBDP base price
    $base_price = sbdp_price_sync_normalize_decimal(get_post_meta($post_id, '_sbdp_base_price', true));
    
    // Skip if no valid base price
    if (empty($base_price) || !is_numeric($base_price) || $base_price <= 0) {
        return;
    }
    
    $base_price_float = floatval($base_price);
    
    // Get current WooCommerce price
    $current_price = get_post_meta($post_id, '_price', true);
    
    // Only update if different to prevent infinite loops
    if (floatval($current_price) !== $base_price_float) {
        // Update WooCommerce prices
        update_post_meta($post_id, '_price', $base_price_float);
        update_post_meta($post_id, '_regular_price', $base_price_float);
        update_post_meta($post_id, '_sale_price', '');
        
        // Clear product cache
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($post_id);
        }
        
        // Log sync
        error_log(sprintf(
            '[SBDP Price Sync] Product #%d "%s": _sbdp_base_price (€%.2f) → _price (€%.2f)',
            $post_id,
            $post->post_title,
            $base_price_float,
            $base_price_float
        ));
    }
}, 20, 3);

/**
 * Sync prices when _sbdp_base_price meta is updated directly
 */
add_action('updated_post_meta', function($meta_id, $post_id, $meta_key, $meta_value) {
    // Only trigger on _sbdp_base_price updates
    if ($meta_key !== '_sbdp_base_price') {
        return;
    }
    
    // Check if this is a product
    if (get_post_type($post_id) !== 'product') {
        return;
    }
    
    // Get product
    $product = wc_get_product($post_id);
    if (!$product || $product->get_type() !== 'bookable_service') {
        return;
    }
    
    // Validate meta value
    $base_price = sbdp_price_sync_normalize_decimal($meta_value);
    if (empty($base_price) || !is_numeric($base_price) || $base_price <= 0) {
        return;
    }
    
    $base_price_float = floatval($base_price);
    
    // Update WooCommerce prices
    update_post_meta($post_id, '_price', $base_price_float);
    update_post_meta($post_id, '_regular_price', $base_price_float);
    update_post_meta($post_id, '_sale_price', '');
    
    // Clear product cache
    if (function_exists('wc_delete_product_transients')) {
        wc_delete_product_transients($post_id);
    }
    
    // Log sync
    error_log(sprintf(
        '[SBDP Price Sync] Meta update for product #%d: _sbdp_base_price → €%.2f',
        $post_id,
        $base_price_float
    ));
}, 10, 4);

/**
 * Ensure PricingService always uses _sbdp_base_price as primary source
 */
add_filter('sbdp/pricing/base_price', function($price, $product_id) {
    $base_price = sbdp_price_sync_normalize_decimal(get_post_meta($product_id, '_sbdp_base_price', true));
    
    if (!empty($base_price) && is_numeric($base_price) && $base_price > 0) {
        return floatval($base_price);
    }
    
    return $price;
}, 10, 2);

/**
 * Override WooCommerce price display on product pages for bookable_service products
 * This ensures the frontend shows the same price as Plan je Dag (from _sbdp_base_price)
 */
if (sbdp_price_sync_enable_legacy_display_overrides()) {
    add_filter('woocommerce_product_get_price', function($price, $product) {
        if (!$product || $product->get_type() !== 'bookable_service') {
            return $price;
        }

        $runtimeOverride = sbdp_price_sync_get_runtime_override($product);
        if ($runtimeOverride !== null) {
            return $runtimeOverride;
        }
        
        $base_price = sbdp_price_sync_normalize_decimal(get_post_meta($product->get_id(), '_sbdp_base_price', true));
        
        if (!empty($base_price) && is_numeric($base_price) && $base_price > 0) {
            return floatval($base_price);
        }
        
        return $price;
    }, 10, 2);

    add_filter('woocommerce_product_get_regular_price', function($price, $product) {
        if (!$product || $product->get_type() !== 'bookable_service') {
            return $price;
        }

        $runtimeOverride = sbdp_price_sync_get_runtime_override($product);
        if ($runtimeOverride !== null) {
            return $runtimeOverride;
        }
        
        $base_price = sbdp_price_sync_normalize_decimal(get_post_meta($product->get_id(), '_sbdp_base_price', true));
        
        if (!empty($base_price) && is_numeric($base_price) && $base_price > 0) {
            return floatval($base_price);
        }
        
        return $price;
    }, 10, 2);

    add_filter('woocommerce_get_price_html', function($price_html, $product) {
        if (!$product || $product->get_type() !== 'bookable_service') {
            return $price_html;
        }
        
        $product_id = $product->get_id();
        $base_price = sbdp_price_sync_normalize_decimal(get_post_meta($product_id, '_sbdp_base_price', true));
        $enable_people = get_post_meta($product_id, '_sbdp_enable_people', true) === 'yes';
        $price_per_person = get_post_meta($product_id, '_sbdp_price_per_person', true);
        $people_min = get_post_meta($product_id, '_sbdp_people_min', true);
        $people_max = get_post_meta($product_id, '_sbdp_people_max', true);
        
        if (empty($base_price) || !is_numeric($base_price) || $base_price <= 0) {
            return $price_html;
        }
        
        $base_price_float = floatval($base_price);
        $formatted_base = wc_price($base_price_float);
        
        if ($enable_people && !empty($price_per_person) && $price_per_person > 0) {
            $formatted_per_person = wc_price(floatval($price_per_person));
            
            $price_html = sprintf(
                '<span class="price">%s <small class="woocommerce-price-suffix">+ %s per persoon</small></span>',
                $formatted_base,
                $formatted_per_person
            );
            
            if ($people_min && $people_max) {
                $price_html .= sprintf(
                    '<br><small class="sbdp-people-range">(%d-%d personen)</small>',
                    intval($people_min),
                    intval($people_max)
                );
            }
        } else {
            $price_html = $formatted_base;
        }
        
        return $price_html;
    }, 10, 2);

    add_action('admin_notices', function() {
        $screen = get_current_screen();
        
        if (!$screen || $screen->id !== 'product' || !isset($_GET['post'])) {
            return;
        }
        
        $post_id = intval($_GET['post']);
        $product = wc_get_product($post_id);
        
        if (!$product || $product->get_type() !== 'bookable_service') {
            return;
        }
        
        $base_price = sbdp_price_sync_normalize_decimal(get_post_meta($post_id, '_sbdp_base_price', true));
        $wc_price = get_post_meta($post_id, '_price', true);
        
        if (empty($base_price) || !is_numeric($base_price) || $base_price <= 0) {
            echo '<div class="notice notice-warning"><p>';
            echo '<strong>SBDP Price Sync:</strong> ';
            echo 'Geen geldige <code>_sbdp_base_price</code> ingesteld. ';
            echo 'Voeg een base price toe om automatische sync te activeren.';
            echo '</p></div>';
            return;
        }
        
        $base_price_float = floatval($base_price);
        $wc_price_float = floatval($wc_price);
        
        if (abs($base_price_float - $wc_price_float) > 0.01) {
            echo '<div class="notice notice-info"><p>';
            echo '<strong>SBDP Price Sync:</strong> ';
            echo sprintf(
                'Prices worden gesynchroniseerd bij volgende save: <code>_sbdp_base_price</code> (€%.2f) → <code>_price</code> (€%.2f)',
                $base_price_float,
                $wc_price_float
            );
            echo '</p></div>';
        } else {
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo '<strong>SBDP Price Sync:</strong> ';
            echo sprintf('✓ Prijzen zijn gesynchroniseerd (€%.2f)', $base_price_float);
            echo '</p></div>';
        }
    });
}


add_filter('woocommerce_is_purchasable', function($purchasable, $product) {
    // Priority 999 is crucial: other plugins or Woo core might hook later than 10 and override it back to false
    // if the base price is missing. We explicitly force true for our domain models.
    if ($product && in_array($product->get_type(), ['bookable_service', 'arrangement', 'resource'])) {
        return true;
    }
    return $purchasable;
}, 999, 2);

