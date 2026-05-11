<?php

declare(strict_types=1);

namespace BSPModule\Core\WooCommerce;

use BSPModule\Core\WooCommerce\ProductType\BookableServiceProductType;
use WC_Product;
use WP_Post;

final class ProductPageContext
{
    public static function getCurrentProduct(): ?WC_Product
    {
        if (! \function_exists('wc_get_product')) {
            return null;
        }

        global $product;

        if ($product instanceof WC_Product) {
            return $product;
        }

        if (\function_exists('get_queried_object_id')) {
            $queriedObjectId = (int) \get_queried_object_id();
            if ($queriedObjectId > 0) {
                $queriedProduct = \wc_get_product($queriedObjectId);
                if ($queriedProduct instanceof WC_Product) {
                    return $queriedProduct;
                }
            }
        }

        if (\function_exists('get_post')) {
            $post = \get_post();
            if ($post instanceof WP_Post && $post->post_type === 'product') {
                $currentProduct = \wc_get_product($post->ID);
                if ($currentProduct instanceof WC_Product) {
                    return $currentProduct;
                }
            }
        }

        if (\function_exists('get_the_ID')) {
            $currentPostId = (int) \get_the_ID();
            if ($currentPostId > 0) {
                $currentProduct = \wc_get_product($currentPostId);
                if ($currentProduct instanceof WC_Product) {
                    return $currentProduct;
                }
            }
        }

        return null;
    }

    public static function isFrontendProductRequest(): bool
    {
        if (! self::isFrontendRequest()) {
            return false;
        }

        if (\function_exists('is_product') && \is_product()) {
            return true;
        }

        if (\function_exists('is_singular') && \is_singular('product')) {
            return true;
        }

        $product = self::getCurrentProduct();
        if ($product instanceof WC_Product) {
            return true;
        }

        if (! empty($_SERVER['REQUEST_URI']) && \function_exists('wp_parse_url') && \function_exists('trailingslashit')) {
            $requestPath = \wp_parse_url((string) $_SERVER['REQUEST_URI'], \PHP_URL_PATH);
            if (\is_string($requestPath) && \strpos(\trailingslashit($requestPath), '/product/') === 0) {
                return true;
            }
        }

        return false;
    }

    public static function isBookableServiceProductRequest(): bool
    {
        if (! self::isFrontendProductRequest()) {
            return false;
        }

        $product = self::getCurrentProduct();

        return $product instanceof WC_Product
            && $product->get_type() === BookableServiceProductType::PRODUCT_TYPE;
    }

    public static function shouldUseLegacyPlannerOverrides(): bool
    {
        if (! self::isBookableServiceProductRequest()) {
            return false;
        }

        $product = self::getCurrentProduct();
        $useLegacyPlanner = true;

        if (\function_exists('apply_filters')) {
            $useLegacyPlanner = (bool) \apply_filters('sbdp_use_legacy_bookable_product_panel', true, $product);
            $useLegacyPlanner = (bool) \apply_filters('sbdp/product_page/use_legacy_planner', $useLegacyPlanner, $product);
        }

        return $useLegacyPlanner;
    }

    public static function isElementorSingleContext(): bool
    {
        if (! \defined('ELEMENTOR_VERSION')) {
            return false;
        }

        if (isset($_GET['elementor-preview']) || \did_action('elementor/theme/register_locations')) {
            return true;
        }

        if (\defined('ELEMENTOR_PRO_VERSION') && isset($_REQUEST['action']) && \strpos((string) $_REQUEST['action'], 'elementor') !== false) {
            return true;
        }

        return \doing_filter('the_content');
    }

    private static function isFrontendRequest(): bool
    {
        if (\function_exists('is_admin') && \is_admin()) {
            return false;
        }

        if (\function_exists('wp_doing_ajax') && \wp_doing_ajax()) {
            return false;
        }

        if (\defined('REST_REQUEST') && \REST_REQUEST) {
            return false;
        }

        return true;
    }
}
