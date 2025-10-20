<?php

/**
 * Plugin Name: Booking Pro 4.05
 * Plugin URI: https://owncreations.com
 * Description: WooCommerce dagplanner en boekingsmodule met resources, capaciteiten,
 * prijsregels en verbeterde e-mailflows.
 * Version: 4.06
 * Author: Own Creations
 * Text Domain: sbdp
 * License: GPLv2 or later
 *
 * @package Booking_Pro_Module
 */

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

if (! defined('ABSPATH')) {
    exit;
}

if (defined('SBDP_FILE') && realpath((string) SBDP_FILE) !== __FILE__) {
    $conflictPlugin = defined('SBDP_FILE')
        ? plugin_basename((string) SBDP_FILE)
        : 'booking-pro-module/booking-pro-module.php';
    $conflictMessage = sprintf(
        /* translators: %s: conflicting plugin file path. */
        __('Booking Pro 4.05 cannot run because another Booking Pro variant (%s) is already active. Deactivate the existing module before activating this build.', 'sbdp'), // phpcs:ignore Generic.Files.LineLength.TooLong
        $conflictPlugin
    );

    $renderConflictNotice = static function () use ($conflictMessage): void {
        printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($conflictMessage));
    };

    if (function_exists('add_action')) {
        add_action('admin_notices', $renderConflictNotice);
        add_action('network_admin_notices', $renderConflictNotice);
    }

    if (function_exists('error_log')) {
        error_log('[SBDP] ' . $conflictMessage); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    }

    return;
}

$pluginDir = plugin_dir_path(__FILE__);

$requiredFiles = array(
    'includes/class-core-agent.php',
    'includes/class-sbdp-plugin.php',
    'includes/class-sbdp-legacy-loader.php',
    'includes/class-sbdp-activation.php',
);

$missingFiles = array();

foreach ($requiredFiles as $relativeFile) {
    $filePath = $pluginDir . $relativeFile;

    if (! is_readable($filePath)) {
        $missingFiles[] = $relativeFile;
    }
}

if ($missingFiles !== array()) {
    $missingMessage = sprintf(
        /* translators: %s: comma-separated list of missing file paths. */
        __('Booking Pro 4.05 is missing required bootstrap files: %s. Reinstall the plugin package to continue.', 'sbdp'), // phpcs:ignore Generic.Files.LineLength.TooLong
        implode(', ', $missingFiles)
    );

    $renderMissingNotice = static function () use ($missingMessage): void {
        printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($missingMessage));
    };

    if (function_exists('add_action')) {
        add_action('admin_notices', $renderMissingNotice);
        add_action('network_admin_notices', $renderMissingNotice);
    }

    if (function_exists('error_log')) {
        error_log('[SBDP] ' . $missingMessage); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    }

    return;
}

define('SBDP_FILE', __FILE__);
define('SBDP_DIR', rtrim($pluginDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
define('SBDP_URL', plugin_dir_url(__FILE__));
define('SBDP_VER', '4.06');

$autoloadFile = SBDP_DIR . 'vendor/autoload.php';

if (file_exists($autoloadFile)) {
    require_once $autoloadFile;
} else {
    add_action(
        'admin_notices',
        static function (): void {
            if (! current_user_can('manage_options')) {
                return;
            }

            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html__('Composer autoload file is missing. Run "composer install" to enable Booking System Pro modules.', 'sbdp') // phpcs:ignore Generic.Files.LineLength.TooLong
            );
        }
    );
}

if (! defined('SBDP_AUTOLOAD_REGISTERED')) {
    define('SBDP_AUTOLOAD_REGISTERED', true);

    $psr4Map = array(
        'BSP\\Core\\'             => SBDP_DIR . 'core/src/',
        'BSP\\Commerce\\'         => SBDP_DIR . 'modules/commerce/',
        'BSP\\Planner\\'          => SBDP_DIR . 'modules/planner/',
        'BSP\\Bookings\\'         => SBDP_DIR . 'modules/bookings/',
        'BSP\\Settings\\'         => SBDP_DIR . 'modules/settings/',
        'BSP\\Sales\\'            => SBDP_DIR . 'modules/sales/',
        'BSP\\DayPlanner\\'       => SBDP_DIR . 'modules/day-planner/',
        'BSP\\BookingBoard\\'     => SBDP_DIR . 'modules/booking-board/',
        'BSP\\Intelligence\\'     => SBDP_DIR . 'modules/intelligence/',
        'BSP\\Notifications\\'    => SBDP_DIR . 'modules/notifications/',
        'BSP\\Vendors\\'          => SBDP_DIR . 'modules/vendors/',
        'BSP\\ActivityCtaBlock\\' => SBDP_DIR . 'modules/activity-cta-block/',
        'BSP\\VendorPortal\\'     => SBDP_DIR . 'modules/vendor-portal/',
        'BSP\\GeoDashboard\\'     => SBDP_DIR . 'modules/geo-dashboard/',
        'BSP\\Finance\\'          => SBDP_DIR . 'modules/finance/',
        'BSP\\Support\\'          => SBDP_DIR . 'modules/support/',
        'BSP\\Data\\'             => SBDP_DIR . 'modules/data/',
        'BSP\\Insights\\'         => SBDP_DIR . 'modules/insights/',
        'BSPModule\\Core\\'       => SBDP_DIR . 'modules/core/',
        'BSPModule\\Commerce\\'   => SBDP_DIR . 'modules/commerce/',
        'BSPModule\\Planner\\'    => SBDP_DIR . 'modules/planner/',
        'BSPModule\\Bookings\\'   => SBDP_DIR . 'modules/bookings/',
        'BSPModule\\Settings\\'   => SBDP_DIR . 'modules/settings/',
        'BSPModule\\Sales\\'      => SBDP_DIR . 'modules/sales/',
        'BSPModule\\DayPlanner\\' => SBDP_DIR . 'modules/day-planner/',
        'BSPModule\\BookingBoard\\' => SBDP_DIR . 'modules/booking-board/',
        'BSPModule\\Intelligence\\' => SBDP_DIR . 'modules/intelligence/',
        'BSPModule\\Notifications\\' => SBDP_DIR . 'modules/notifications/',
        'BSPModule\\Vendors\\'       => SBDP_DIR . 'modules/vendors/',
        'BSPModule\\ActivityCtaBlock\\' => SBDP_DIR . 'modules/activity-cta-block/',
        'BSPModule\\VendorPortal\\'  => SBDP_DIR . 'modules/vendor-portal/',
        'BSPModule\\GeoDashboard\\'  => SBDP_DIR . 'modules/geo-dashboard/',
        'BSPModule\\Finance\\'       => SBDP_DIR . 'modules/finance/',
        'BSPModule\\Support\\'       => SBDP_DIR . 'modules/support/',
        'BSPModule\\Data\\'          => SBDP_DIR . 'modules/data/',
        'BSPModule\\Insights\\'      => SBDP_DIR . 'modules/insights/',
        'BSPModule\\Shared\\'        => SBDP_DIR . 'modules/shared/',
    );

    spl_autoload_register(
        static function (string $class) use ($psr4Map): void {
            foreach ($psr4Map as $prefix => $baseDir) {
                $prefixLength = strlen($prefix);
                if (strncmp($class, $prefix, $prefixLength) !== 0) {
                    continue;
                }

                $relativeClass = substr($class, $prefixLength);
                $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass);
                $file = $baseDir . $relativePath . '.php';

                if (is_readable($file)) {
                    require_once $file;
                }

                return;
            }
        }
    );
}

require_once SBDP_DIR . 'core/src/ModuleInterface.php';
require_once SBDP_DIR . 'core/src/Interfaces/ModuleInterface.php';

require_once SBDP_DIR . 'includes/class-core-agent.php';
require_once SBDP_DIR . 'includes/class-sbdp-plugin.php';
require_once SBDP_DIR . 'includes/class-sbdp-activation.php';

SBDP_Activation::bootstrap();

register_activation_hook(SBDP_FILE, array('SBDP_Activation', 'activate'));
register_deactivation_hook(SBDP_FILE, array('SBDP_Activation', 'deactivate'));
register_uninstall_hook(SBDP_FILE, array('SBDP_Activation', 'uninstall'));

SBDP_Plugin::boot();

add_action(
    'plugins_loaded',
    static function (): void {
        $defaultModules = array(
            'core'          => '\BSP\Core\Module',
            'commerce'      => '\BSP\Commerce\Module',
            'planner'       => '\BSP\Planner\Module',
            'bookings'      => '\BSP\Bookings\Module',
            'settings'      => '\BSP\Settings\Module',
            'sales'         => '\BSP\Sales\Module',
            'vendors'       => '\BSP\Vendors\Module',
            'vendor_portal' => '\BSP\VendorPortal\Module',
            'booking_board' => '\BSP\BookingBoard\Module',
            'day_planner'   => '\BSP\DayPlanner\Module',
            'notifications' => '\BSP\Notifications\Module',
            'intelligence'  => '\BSP\Intelligence\Module',
            'insights'      => '\BSP\Insights\Module',
            'finance'       => '\BSP\Finance\Module',
            'data'          => '\BSP\Data\Module',
            'geo_dashboard' => '\BSP\GeoDashboard\Module',
            'support'       => '\BSP\Support\Module',
            'activity_cta'  => '\BSP\ActivityCtaBlock\Module',
        );

        if (function_exists('apply_filters')) {
            $defaultModules = (array) apply_filters(
                'bsp/modules/default_map',
                $defaultModules
            ); // phpcs:ignore WordPress.NamingConventions.ValidHookName

            $legacyClasses = (array) apply_filters(
                'bsp/modules/default_classes',
                array_values($defaultModules)
            ); // phpcs:ignore WordPress.NamingConventions.ValidHookName
            foreach ($legacyClasses as $class) {
                if (! is_string($class) || $class === '') {
                    continue;
                }

                $modernMirror = '\\' . ltrim($class, '\\');
                $normalized = strtolower(str_replace('\\', '_', ltrim($class, '\\')));

                if (array_key_exists($normalized, $defaultModules)) {
                    continue;
                }

                if (strpos($modernMirror, '\\BSPModule\\') === 0) {
                    $converted = '\\BSP\\' . substr($modernMirror, strlen('\\BSPModule\\'));
                    if (in_array($converted, $defaultModules, true)) {
                        continue;
                    }
                }

                if (in_array($modernMirror, $defaultModules, true)) {
                    continue;
                }

                if (! isset($defaultModules[ $normalized ])) {
                    $defaultModules[ $normalized ] = $class;
                }
            }
        }

        foreach ($defaultModules as $slug => $class) {
            if (! is_string($class) || $class === '') {
                continue;
            }

            if (! class_exists($class)) {
                continue;
            }

            if (! is_subclass_of($class, '\BSP\Core\Interfaces\ModuleInterface')) {
                continue;
            }

            $key = $slug !== '' ? $slug : strtolower(str_replace('\\', '_', $class));
            \BSP\Core\Modules::register($key, $class);
        }

        if (function_exists('do_action')) {
            do_action(
                'bsp/core/modules/register',
                \BSP\Core\Modules::class,
                $defaultModules
            ); // phpcs:ignore WordPress.NamingConventions.ValidHookName
        }

        \BSP\Core\Modules::loadAll();

        if (function_exists('do_action')) {
            do_action(
                'bsp/core/modules/booted',
                \BSP\Core\Modules::class,
                $defaultModules
            ); // phpcs:ignore WordPress.NamingConventions.ValidHookName
        }

        if (class_exists('BSP_Core_Agent')) {
            \BSP_Core_Agent::instance()->boot();
        }
    },
    20
);

// phpcs:enable PSR1.Files.SideEffects.FoundWithSymbols
