<?php

declare(strict_types=1);

namespace SBDP\Core;

use SBDP\Core\LegacyLoader;
use WP_Error;

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main plugin bootstrapper responsible for environment checks and hook wiring.
 */
final class Plugin
{
    public const MIN_WP_VERSION = '5.8';
    public const MIN_PHP_VERSION = '7.4';
    public const MIN_WC_VERSION = '7.0';

    private static bool $booted = false;

    private static string $notice = '';

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        if (!\class_exists('SBDP_Private_Tours', false)) {
            require_once SBDP_DIR . 'includes/class-sbdp-private-tours.php';
        }

        if (\class_exists('SBDP_Private_Tours')) {
            \SBDP_Private_Tours::init();
        }

        \add_action('init', [self::class, 'init']);
        \add_action('admin_notices', [self::class, 'maybeRenderNotice']);
        \add_filter(
            'plugin_action_links_' . \plugin_basename(SBDP_FILE),
            [self::class, 'registerPluginLinks']
        );
    }

    public static function init(): void
    {
        self::loadTextdomain();

        if (!self::isEnvironmentCompatible()) {
            return;
        }

        if (\class_exists('BSP_Core_Agent')) {
            \BSP_Core_Agent::instance();
        }

        LegacyLoader::init();

        \add_filter(
            'rest_authentication_errors',
            [self::class, 'maybeAllowPublicRest'],
            999
        );
    }

    private static function loadTextdomain(): void
    {
        \load_plugin_textdomain(
            'sbdp',
            false,
            \dirname(\plugin_basename(SBDP_FILE)) . '/languages'
        );
    }

    private static function isEnvironmentCompatible(): bool
    {
        global $wp_version;

        if (\version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '<')) {
            self::$notice = \sprintf(
                /* translators: 1: Minimum PHP version, 2: Current PHP version. */
                \__('Booking Pro Module vereist minimaal PHP %1$s. Huidige versie: %2$s.', 'sbdp'),
                self::MIN_PHP_VERSION,
                PHP_VERSION
            );

            return false;
        }

        if (\version_compare($wp_version, self::MIN_WP_VERSION, '<')) {
            self::$notice = \sprintf(
                /* translators: 1: Minimum WordPress version, 2: Current WordPress version. */
                \__('Booking Pro Module vereist minimaal WordPress %1$s. Huidige versie: %2$s.', 'sbdp'),
                self::MIN_WP_VERSION,
                $wp_version
            );

            return false;
        }

        if (!\class_exists('WooCommerce')) {
            // phpcs:ignore Generic.Files.LineLength.TooLong
            self::$notice = \__('Booking Pro Module vereist dat WooCommerce actief is. Activeer WooCommerce om verder te gaan.', 'sbdp');

            return false;
        }

        if (!\defined('WC_VERSION') || \version_compare(WC_VERSION, self::MIN_WC_VERSION, '<')) {
            // phpcs:ignore Generic.Files.LineLength.TooLong
            self::$notice = \sprintf(
                /* translators: 1: Minimum WooCommerce version, 2: Current WooCommerce version. */
                \__('Booking Pro Module vereist minimaal WooCommerce %1$s. Huidige versie: %2$s.', 'sbdp'),
                self::MIN_WC_VERSION,
                \defined('WC_VERSION') ? WC_VERSION : \__('onbekend', 'sbdp')
            );

            return false;
        }

        self::$notice = '';

        return true;
    }

    public static function maybeRenderNotice(): void
    {
        if (self::$notice === '') {
            return;
        }

        \printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            \esc_html(self::$notice)
        );
    }

    /**
     * @param array<int|string, string> $links
     *
     * @return array<int|string, string>
     */
    public static function registerPluginLinks(array $links): array
    {
        $links[] = \sprintf(
            '<a href="%s">%s</a>',
            \esc_url(\admin_url('admin.php?page=sbdp_bookings')),
            \esc_html__('Planner', 'sbdp')
        );

        $links[] = \sprintf(
            '<a href="%s" target="_blank" rel="noopener">%s</a>',
            \esc_url('https://owncreations.com'),
            \esc_html__('Ondersteuning', 'sbdp')
        );

        return $links;
    }

    /**
     * @param mixed $result
     *
     * @return WP_Error|mixed|null
     */
    public static function maybeAllowPublicRest($result)
    {
        if (empty($result) || !($result instanceof WP_Error)) {
            return $result;
        }

        $route = isset($_SERVER['REQUEST_URI'])
            ? \sanitize_text_field(\wp_unslash($_SERVER['REQUEST_URI']))
            : '';

        if ($route !== '' && \strpos($route, '/wp-json/sbdp/v1/') !== false) {
            return null;
        }

        return $result;
    }
}

if (!\class_exists('SBDP_Plugin', false)) {
    \class_alias(Plugin::class, 'SBDP_Plugin');
}

// phpcs:enable PSR1.Files.SideEffects.FoundWithSymbols
