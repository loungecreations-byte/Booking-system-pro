<?php

declare(strict_types=1);

namespace SBDP\Services;

use BSPModule\Core\Admin\SetupWizard;
use BSPModule\Core\WooCommerce\ProductType\BookableServiceProductType;
use WP_Error;
use WP_Post;

/**
 * Lightweight demo data seeder hook.
 *
 * The heavy lifting is delegated to third parties via actions so that
 * environments without seeders still boot without fatals.
 */
final class DemoDataSeeder
{
    public static function seed(): void
    {
        if (! self::isEnabled()) {
            self::log('Demo seed disabled via configuration.');
            return;
        }

        if (! class_exists(SetupWizard::class)) {
            self::log('SetupWizard registry unavailable.');
            return;
        }

        if (! function_exists('wp_insert_post') || ! function_exists('get_page_by_path')) {
            self::log('WP post helpers unavailable.');
            return;
        }

        if (! function_exists('wc_get_product')) {
            self::log('WooCommerce unavailable during demo seed.');
            return;
        }

        $presets = SetupWizard::presets();
        if ($presets === array()) {
            self::log('No presets defined.');
            return;
        }

        foreach ($presets as $key => $preset) {
            $slug = 'sbdp-demo-' . sanitize_title($key);
            $productId = self::findExistingProduct($slug);
            if ($productId <= 0) {
                $productId = self::createProduct($slug, (string) ($preset['label'] ?? $key));
                if ($productId <= 0) {
                    continue;
                }
            }

            $result = SetupWizard::apply_preset_to_product(
                $productId,
                $key,
                array(
                    'create_resources' => true,
                    'enable_sync'      => false,
                )
            );

            if ($result instanceof WP_Error) {
                self::log(sprintf('Failed to apply preset "%s" to product %d: %s', $key, $productId, $result->get_error_message()));
                continue;
            }

            self::markAsDemo($productId);
        }
    }

    public static function isEnabled(): bool
    {
        $enabled = true;

        if (defined('SBDP_DISABLE_DEMO_SEEDS') && SBDP_DISABLE_DEMO_SEEDS) {
            $enabled = false;
        }

        if ($enabled) {
            $env = getenv('SBDP_DISABLE_DEMO_SEEDS');
            if ($env !== false && $env !== null && self::isTruthy($env)) {
                $enabled = false;
            }
        }

        if ($enabled && function_exists('get_option')) {
            $option = get_option('sbdp_disable_demo_seeds', null);
            if ($option !== null && self::isTruthy($option)) {
                $enabled = false;
            }
        }

        if (function_exists('apply_filters')) {
            $enabled = (bool) apply_filters('sbdp/demo_data/enable_seeding', $enabled);
        }

        return $enabled;
    }

    private static function findExistingProduct(string $slug): int
    {
        $post = get_page_by_path($slug, OBJECT, 'product');
        if ($post instanceof WP_Post) {
            return (int) $post->ID;
        }

        return 0;
    }

    private static function createProduct(string $slug, string $title): int
    {
        $postId = wp_insert_post(
            array(
                'post_type'   => 'product',
                'post_status' => 'publish',
                'post_title'  => $title,
                'post_name'   => $slug,
                'post_excerpt' => '',
            )
        );

        if ($postId === 0 || is_wp_error($postId)) {
            self::log(sprintf('Unable to create demo product "%s".', $slug));
            return 0;
        }

        wp_set_object_terms((int) $postId, BookableServiceProductType::PRODUCT_TYPE, 'product_type');

        return (int) $postId;
    }

    private static function markAsDemo(int $productId): void
    {
        update_post_meta($productId, '_sbdp_demo_seed', '1');
    }

    private static function log(string $message): void
    {
        if (function_exists('error_log')) {
            error_log('[SBDP][DemoDataSeeder] ' . $message);
        }
    }

    /**
     * @param mixed $value
     */
    private static function isTruthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float) $value !== 0.0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return false;
            }

            return in_array($normalized, array('1', 'true', 'yes', 'on', 'enabled'), true);
        }

        return false;
    }
}
