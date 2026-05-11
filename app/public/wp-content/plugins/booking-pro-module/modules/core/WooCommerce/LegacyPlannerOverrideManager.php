<?php

declare(strict_types=1);

namespace BSPModule\Core\WooCommerce;

use BSPModule\Core\WooCommerce\Display\ProductForm;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use WP_Hook;

final class LegacyPlannerOverrideManager
{
    private static bool $booted = false;

    public static function init(): void
    {
        if (self::$booted || ! \function_exists('add_action')) {
            return;
        }

        self::$booted = true;

        \add_action('init', [__CLASS__, 'ensureProductLayoutOption'], 1);
        \add_action('wp', [__CLASS__, 'removeCompetingCallbacks'], 0);
        \add_action('wp', [__CLASS__, 'simplifyWooProductHooks'], 1);
        \add_action('template_redirect', [__CLASS__, 'startOutputBuffer'], 0);
        \add_action('wp_enqueue_scripts', [__CLASS__, 'dequeueCompetingAssets'], 999);
        \add_action('wp_print_styles', [__CLASS__, 'dequeueCompetingStyles'], \PHP_INT_MAX);
        \add_action('wp_print_scripts', [__CLASS__, 'dequeueCompetingScripts'], \PHP_INT_MAX);
        \add_action('after_setup_theme', [__CLASS__, 'disableCoreProductForm'], 999);

        \add_filter('style_loader_tag', [__CLASS__, 'filterStyleLoaderTag'], 10, 4);
        \add_filter('script_loader_tag', [__CLASS__, 'filterScriptLoaderTag'], 10, 3);
    }

    public static function ensureProductLayoutOption(): void
    {
        if (\get_option('sbdp_product_layout_enabled') !== '1') {
            \update_option('sbdp_product_layout_enabled', '1');
        }
    }

    public static function removeCompetingCallbacks(): void
    {
        if (! self::shouldUseLegacyPlannerOverrides()) {
            return;
        }

        self::removeCallbacksFromFile('wp_enqueue_scripts', 'modules/activity-cta-block/Module.php');
        self::removeCallbacksFromFile('woocommerce_single_product_summary', 'modules/activity-cta-block/Module.php');
        self::removeCallbacksFromFile('ddb_cta_block', 'modules/activity-cta-block/Module.php');
    }

    public static function simplifyWooProductHooks(): void
    {
        if (! self::shouldUseLegacyPlannerOverrides() || ProductPageContext::isElementorSingleContext()) {
            return;
        }

        \remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_title', 5);
        \remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_rating', 10);
        \remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_price', 10);
        \remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 20);
        \remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
        \remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40);
        \remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_sharing', 50);

        \remove_all_actions('woocommerce_before_add_to_cart_form');
        \remove_all_actions('woocommerce_after_add_to_cart_form');
        \remove_all_actions('woocommerce_before_add_to_cart_button');
        \remove_all_actions('woocommerce_after_add_to_cart_button');
    }

    public static function disableCoreProductForm(): void
    {
        \remove_action('wp_enqueue_scripts', [ProductForm::class, 'maybe_enqueue_assets']);
        \remove_action('woocommerce_before_single_product', [ProductForm::class, 'prepare_single_product']);
        \remove_action('woocommerce_single_product_summary', [ProductForm::class, 'render'], 25);
    }

    public static function startOutputBuffer(): void
    {
        if (! self::shouldFilterProductOutput()) {
            return;
        }

        \ob_start([__CLASS__, 'stripDuplicateProductCtaAssets']);
    }

    public static function stripDuplicateProductCtaAssets(string $html): string
    {
        if ($html === '') {
            return $html;
        }

        $patterns = [
            '/\s*<link[^>]+id=[\"\']ddb-cta-block-css[\"\'][^>]*>\s*/i',
            '/\s*<script[^>]+id=[\"\']ddb-cta-block-js-extra[\"\'][^>]*>.*?<\/script>\s*/is',
            '/\s*<script[^>]+id=[\"\']ddb-cta-block-js[\"\'][^>]*><\/script>\s*/is',
        ];

        return (string) \preg_replace($patterns, '', $html);
    }

    public static function dequeueCompetingAssets(): void
    {
        if (! self::shouldUseLegacyPlannerOverrides()) {
            return;
        }

        foreach (['ddb-cta-block'] as $handle) {
            if (\wp_script_is($handle, 'enqueued') || \wp_script_is($handle, 'registered')) {
                \wp_dequeue_script($handle);
                \wp_deregister_script($handle);
            }

            if (\wp_style_is($handle, 'enqueued') || \wp_style_is($handle, 'registered')) {
                \wp_dequeue_style($handle);
                \wp_deregister_style($handle);
            }
        }
    }

    public static function dequeueCompetingStyles(): void
    {
        if (! self::shouldUseLegacyPlannerOverrides()) {
            return;
        }

        if (\wp_style_is('ddb-cta-block', 'enqueued') || \wp_style_is('ddb-cta-block', 'registered')) {
            \wp_dequeue_style('ddb-cta-block');
            \wp_deregister_style('ddb-cta-block');
        }
    }

    public static function dequeueCompetingScripts(): void
    {
        if (! self::shouldUseLegacyPlannerOverrides()) {
            return;
        }

        if (\wp_script_is('ddb-cta-block', 'enqueued') || \wp_script_is('ddb-cta-block', 'registered')) {
            \wp_dequeue_script('ddb-cta-block');
            \wp_deregister_script('ddb-cta-block');
        }
    }

    public static function filterStyleLoaderTag(string $html, string $handle, string $href, string $media): string
    {
        unset($href, $media);

        if ($handle === 'ddb-cta-block' && self::shouldFilterProductOutput()) {
            return '';
        }

        return $html;
    }

    public static function filterScriptLoaderTag(string $tag, string $handle, string $src): string
    {
        unset($src);

        if ($handle === 'ddb-cta-block' && self::shouldFilterProductOutput()) {
            return '';
        }

        return $tag;
    }

    private static function shouldUseLegacyPlannerOverrides(): bool
    {
        return ProductPageContext::shouldUseLegacyPlannerOverrides();
    }

    private static function shouldFilterProductOutput(): bool
    {
        return self::shouldUseLegacyPlannerOverrides() || self::isProductRequestPath();
    }

    private static function isProductRequestPath(): bool
    {
        if (empty($_SERVER['REQUEST_URI']) || ! \function_exists('wp_parse_url') || ! \function_exists('trailingslashit')) {
            return false;
        }

        $requestPath = \wp_parse_url((string) $_SERVER['REQUEST_URI'], \PHP_URL_PATH);

        return \is_string($requestPath) && \strpos(\trailingslashit($requestPath), '/product/') === 0;
    }

    private static function removeCallbacksFromFile(string $hook, string $fileFragment): void
    {
        global $wp_filter;

        $hookCallbacks = $wp_filter[$hook] ?? null;
        if (! $hookCallbacks instanceof WP_Hook || ! \is_array($hookCallbacks->callbacks)) {
            return;
        }

        $needle = \wp_normalize_path($fileFragment);

        foreach ($hookCallbacks->callbacks as $priority => $group) {
            if (! \is_array($group)) {
                continue;
            }

            foreach ($group as $callback) {
                $callable = $callback['function'] ?? null;
                $file = self::resolveCallableFile($callable);

                if ($file === '' || \strpos(\wp_normalize_path($file), $needle) === false) {
                    continue;
                }

                \remove_filter($hook, $callable, (int) $priority);
            }
        }
    }

    private static function resolveCallableFile($callable): string
    {
        if (\is_array($callable) && isset($callable[0], $callable[1])) {
            try {
                $reflection = new ReflectionMethod($callable[0], (string) $callable[1]);
                return (string) $reflection->getFileName();
            } catch (ReflectionException $exception) {
                return '';
            }
        }

        if ($callable instanceof \Closure || \is_string($callable)) {
            try {
                $reflection = new ReflectionFunction($callable);
                return (string) $reflection->getFileName();
            } catch (ReflectionException $exception) {
                return '';
            }
        }

        return '';
    }
}

