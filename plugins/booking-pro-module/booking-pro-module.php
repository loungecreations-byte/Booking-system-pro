<?php

/**
 * Plugin Name:       Booking Pro 4.23.19
 * Plugin URI:        https://owncreations.com
 * Description:       WooCommerce plannings- en boekingsmodule met resources, capaciteiten,
 *                    prijsregels en verbeterde e-mailflows.
 * Version:           4.23.20
 * Author:            Own Creations
 * Text Domain:       sbdp
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
    $conflictLogMessage = sprintf(
        'Booking Pro 4.23.19 cannot run because another Booking Pro variant (%s) is already active. Deactivate the existing module before activating this build.',
        $conflictPlugin
    );

    $renderConflictNotice = static function () use ($conflictPlugin): void {
        $message = sprintf(
            /* translators: %s: conflicting plugin file path. */
            __('Booking Pro 4.23.19 cannot run because another Booking Pro variant (%s) is already active. Deactivate the existing module before activating this build.', 'sbdp'), // phpcs:ignore Generic.Files.LineLength.TooLong
            $conflictPlugin
        );

        printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($message));
    };

    if (function_exists('add_action')) {
        add_action('admin_notices', $renderConflictNotice);
        add_action('network_admin_notices', $renderConflictNotice);
    }

    if (function_exists('error_log')) {
        error_log('[SBDP] ' . $conflictLogMessage); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    }

    return;
}

$pluginDir = plugin_dir_path(__FILE__);

$existingCommerceModule = class_exists('\\BSP\\Commerce\\Module', false);
if ($existingCommerceModule) {
    $conflictMessage = __('Booking Pro 4.23.19 detected another active Booking module variant (commerce services already loaded). Deactivate the existing variant before activating this build.', 'sbdp');
    $renderClassConflictNotice = static function () use ($conflictMessage): void {
        printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($conflictMessage));
    };

    if (function_exists('add_action')) {
        add_action('admin_notices', $renderClassConflictNotice);
        add_action('network_admin_notices', $renderClassConflictNotice);
    }

    if (function_exists('error_log')) {
        error_log('[SBDP] ' . $conflictMessage); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    }

    return;
}

$requiredFiles = array(
    'includes/class-core-agent.php',
    'includes/class-sbdp-plugin.php',
    'includes/class-sbdp-legacy-loader.php',
    'includes/class-sbdp-cache-primer.php',
    'includes/class-sbdp-activation.php',
    'includes/Services/DemoDataSeeder.php',
    'includes/Core/ProductSettings.php',
    'includes/Pricing/PricingService.php',
    'includes/class-sbdp-pricing-service.php',
    'includes/planning-sessions.php',
    'includes/module-loader.php',
    'includes/BookingDispatcher.php',
    'includes/BookingEngine.php',
    'includes/SalesHub/autoload.php',
    'includes/legacy/class-sales-legacy-service.php',
);

$missingFiles = array();

foreach ($requiredFiles as $relativeFile) {
    $filePath = $pluginDir . $relativeFile;

    if (! is_readable($filePath)) {
        $missingFiles[] = $relativeFile;
    }
}

if ($missingFiles !== array()) {
    $missingLogMessage = sprintf(
        'Booking Pro 4.23.9 is missing required bootstrap files: %s. Reinstall the plugin package to continue.',
        implode(', ', $missingFiles)
    );

    $renderMissingNotice = static function () use ($missingFiles): void {
        $message = sprintf(
            /* translators: %s: comma-separated list of missing file paths. */
            __('Booking Pro 4.23.9 is missing required bootstrap files: %s. Reinstall the plugin package to continue.', 'sbdp'), // phpcs:ignore Generic.Files.LineLength.TooLong
            implode(', ', $missingFiles)
        );

        printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($message));
    };

    if (function_exists('add_action')) {
        add_action('admin_notices', $renderMissingNotice);
        add_action('network_admin_notices', $renderMissingNotice);
    }

    if (function_exists('error_log')) {
        error_log('[SBDP] ' . $missingLogMessage); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    }

    return;
}

define('SBDP_FILE', __FILE__);
define('SBDP_DIR', rtrim($pluginDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
define('SBDP_URL', plugin_dir_url(__FILE__));
define('SBDP_VER', '4.23.22');
if (! defined('SBDP_DISABLE_DEMO_SEEDS')) {
    define('SBDP_DISABLE_DEMO_SEEDS', true);
}

// Hard-disable demo seeding unless explicitly overridden.
if (function_exists('add_filter')) {
    add_filter('sbdp/demo_data/enable_seeding', '__return_false', 99);
    add_filter('sbdp_use_legacy_bookable_product_panel', '__return_false', 20);
}

if (function_exists('add_action')) {
    add_action(
        'send_headers',
        static function (): void {
            if (! headers_sent() && function_exists('header_remove')) {
                header_remove('X-Powered-By');
            }
        },
        0
    );
}

require_once SBDP_DIR . 'includes/Services/DemoDataSeeder.php';
require_once SBDP_DIR . 'includes/Core/ProductSettings.php';
require_once SBDP_DIR . 'includes/Pricing/PricingService.php';
require_once SBDP_DIR . 'includes/Pricing/SelectionPricing.php';
require_once SBDP_DIR . 'includes/class-sbdp-pricing-service.php';
require_once SBDP_DIR . 'includes/planning-sessions.php';
require_once SBDP_DIR . 'includes/bootstrap/unified-design-system-loader.php';
require_once SBDP_DIR . 'includes/module-loader.php';
require_once SBDP_DIR . 'includes/BookingDispatcher.php';
require_once SBDP_DIR . 'includes/BookingEngine.php';
require_once SBDP_DIR . 'includes/SalesHub/autoload.php';
require_once SBDP_DIR . 'includes/legacy/class-sales-legacy-service.php';

if (! defined('SBDP_BOOKING_BOARD_V2')) {
    define('SBDP_BOOKING_BOARD_V2', true);
}

$sbdp_modules_helpers_loaded = function_exists('sbdp_module_directories');

if (! $sbdp_modules_helpers_loaded) {
    /**
     * Retrieve a list of module directories keyed by their folder name.
     *
     * @return array<string, string>
     */
    function sbdp_module_directories(): array
    {
        if (! defined('SBDP_DIR')) {
            return array();
        }

        $modulesDir = SBDP_DIR . 'modules';
        if (! is_dir($modulesDir)) {
            return array();
        }

        $entries = scandir($modulesDir);
        if ($entries === false) {
            return array();
        }

        $directories = array();

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $modulesDir . DIRECTORY_SEPARATOR . $entry;
            if (! is_dir($path)) {
                continue;
            }

            $directories[ $entry ] = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        }

        return $directories;
    }

    /**
     * Attempt to read the declared namespace from a Module.php file.
     */
    function sbdp_detect_module_namespace(string $file): ?string
    {
        if (! is_readable($file)) {
            return null;
        }

        $handle = fopen($file, 'r');
        if ($handle === false) {
            return null;
        }

        while (($line = fgets($handle)) !== false) {
            $trimmed = trim($line);

            if ($trimmed === '' || strpos($trimmed, '<?php') === 0) {
                continue;
            }

            if (strpos($trimmed, 'declare(') === 0) {
                continue;
            }

            if (strpos($trimmed, '//') === 0 || strpos($trimmed, '/*') === 0 || strpos($trimmed, '*') === 0) {
                continue;
            }

            if (strpos($trimmed, 'namespace ') === 0) {
                $parts     = explode(' ', $trimmed);
                $namespace = $parts[1] ?? '';
                $namespace = rtrim($namespace, ';');
                fclose($handle);

                return $namespace !== '' ? $namespace : null;
            }
        }

        fclose($handle);

        return null;
    }

    /**
     * Append discovered module namespaces to the PSR-4 autoload map.
     *
     * @param array<string, string> $map
     * @return array<string, string>
     */
    function sbdp_extend_psr4_map_with_modules(array $map): array
    {
        foreach (sbdp_module_directories() as $folder => $path) {
            $moduleFile = $path . 'Module.php';
            if (! is_readable($moduleFile)) {
                continue;
            }

            $namespace = sbdp_detect_module_namespace($moduleFile);
            if ($namespace === null) {
                continue;
            }

            $namespace = trim($namespace, '\\');
            if ($namespace === '') {
                continue;
            }

            $psrPrefix = $namespace . '\\';
            if (! isset($map[ $psrPrefix ])) {
                $map[ $psrPrefix ] = $path;
            }

            if (strpos($namespace, 'BSP\\') === 0) {
                $legacyPrefix = 'BSPModule\\' . substr($namespace, strlen('BSP\\')) . '\\';
                if (! isset($map[ $legacyPrefix ])) {
                    $map[ $legacyPrefix ] = $path;
                }
            }
        }

        return $map;
    }

    /**
     * Normalize a module directory to a registration slug.
     */
    function sbdp_normalize_module_slug(string $directory): string
    {
        $slug = strtolower($directory);
        $slug = str_replace(array(' ', '-'), '_', $slug);
        $slug = preg_replace('/[^a-z0-9_]/', '', $slug) ?? $slug;

        if ($slug === '') {
            return strtolower($directory);
        }

        return $slug;
    }

    /**
     * Discover module classes that implement the BSP module interface.
     *
     * @return array<string, string> Map of slug to fully-qualified class name.
     */
    function sbdp_discover_backend_modules(): array
    {
        $modules = array();

        foreach (sbdp_module_directories() as $folder => $path) {
            $moduleFile = $path . 'Module.php';
            if (! is_readable($moduleFile)) {
                continue;
            }

            $namespace = sbdp_detect_module_namespace($moduleFile);
            if ($namespace === null) {
                continue;
            }

            $namespace = trim($namespace, '\\');
            if ($namespace === '') {
                continue;
            }

            $class = $namespace . '\\Module';
            $class = '\\' . ltrim($class, '\\');

            if (! class_exists($class, false)) {
                require_once $moduleFile;
            }

            if (! class_exists($class)) {
                continue;
            }

            if (! is_subclass_of($class, '\BSP\Core\Interfaces\ModuleInterface')) {
                continue;
            }

            $slug = sbdp_normalize_module_slug($folder);
            if (! isset($modules[ $slug ])) {
                $modules[ $slug ] = $class;
            }
        }

        return $modules;
    }
}

$autoloadFile = SBDP_DIR . 'vendor/autoload.php';

if (file_exists($autoloadFile)) {
    require_once $autoloadFile;
} else {
    $renderMissingComposerNotice = static function (): void {
        if (! current_user_can('manage_options')) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__('Booking Pro: Composer dependencies missing. Run "composer install" in the plugin directory to enable all features.', 'sbdp')
        );
    };

    if (function_exists('add_action')) {
        add_action('admin_notices', $renderMissingComposerNotice);
        add_action('network_admin_notices', $renderMissingComposerNotice);
    }

    if (function_exists('error_log')) {
        error_log('[SBDP] Composer autoload file missing. Run "composer install" to enable dependencies.');
    }
}

if (! defined('SBDP_AUTOLOAD_REGISTERED')) {
    define('SBDP_AUTOLOAD_REGISTERED', true);

    $bootstrapDir = SBDP_DIR . 'includes/bootstrap';
    if (is_dir($bootstrapDir)) {
        $bootstrapFiles = glob($bootstrapDir . DIRECTORY_SEPARATOR . '*.php') ?: array();

        foreach ($bootstrapFiles as $bootstrapFile) {
            if (is_readable($bootstrapFile)) {
                require_once $bootstrapFile;
            }
        }
    }

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
        'BSP\\Gamification\\'     => SBDP_DIR . 'modules/gamification/',
        'BSP\\Experience\\'       => SBDP_DIR . 'modules/experience/',
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
        'BSPModule\\Shared\\Modules\\' => array(
            SBDP_DIR . 'modules/shared/Modules/',
            SBDP_DIR . 'modules/core/Compat/BSPModule/Shared/Modules/',
            SBDP_DIR . 'core/src/Compat/BSPModule/Shared/Modules/',
        ),
        'BSPModule\\Shared\\'        => SBDP_DIR . 'modules/shared/',
        'SBDP\\Support\\'            => SBDP_DIR . 'includes/Support/',
        'SBDP\\Contracts\\'          => SBDP_DIR . 'includes/Contracts/',
        'BPM\\Modules\\'             => SBDP_DIR . 'includes/Modules/',
        'BPM\\'                      => SBDP_DIR . 'includes/',
        'SBDP\\Modules\\Booking\\'   => SBDP_DIR . 'includes/Modules/booking/',
        'SBDP\\Modules\\Planner\\'   => SBDP_DIR . 'modules/planner/',
        'SBDP\\Modules\\Pricing\\'   => SBDP_DIR . 'modules/pricing/',
        'SBDP\\Modules\\Arrangements\\' => SBDP_DIR . 'modules/arrangements/',
        'SBDP\\'                     => SBDP_DIR . 'includes/',
    );

    $psr4Map = sbdp_extend_psr4_map_with_modules($psr4Map);

    spl_autoload_register(
        static function (string $class) use ($psr4Map): void {
            foreach ($psr4Map as $prefix => $baseDir) {
                $prefixLength = strlen($prefix);
                if (strncmp($class, $prefix, $prefixLength) !== 0) {
                    continue;
                }

                $relativeClass = substr($class, $prefixLength);
                $relativePath  = str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';
                $baseDirs      = (array) $baseDir;

                foreach ($baseDirs as $dir) {
                    $file = $dir . $relativePath;

                    if (is_readable($file)) {
                        require_once $file;
                        return;
                    }
                }

                // File not found under this prefix — try the next prefix.
                continue;
            }
        }
    );
}

require_once SBDP_DIR . 'includes/module-loader.php';

require_once SBDP_DIR . 'core/src/ModuleInterface.php';
require_once SBDP_DIR . 'core/src/Interfaces/ModuleInterface.php';

require_once SBDP_DIR . 'includes/class-core-agent.php';
require_once SBDP_DIR . 'includes/class-sbdp-plugin.php';
require_once SBDP_DIR . 'includes/class-sbdp-pricing-service.php';
require_once SBDP_DIR . 'includes/class-sbdp-cache-primer.php';
require_once SBDP_DIR . 'includes/class-sbdp-activation.php';
require_once SBDP_DIR . 'includes/SalesHub/autoload.php';

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
            'data'          => '\BSP\Data\Module',
            'geo_dashboard' => '\BSP\GeoDashboard\Module',
            'support'       => '\BSP\Support\Module',
            'activity_cta'  => '\BSP\ActivityCtaBlock\Module',
            'gamification'  => '\BSP\Gamification\Module',
        );

        foreach (sbdp_discover_backend_modules() as $slug => $class) {
            if (! isset($defaultModules[ $slug ])) {
                $defaultModules[ $slug ] = $class;
            }
        }

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

add_action(
    'plugins_loaded',
    static function (): void {
        \SBDP\SalesHub\SalesHubBootstrap::instance()->boot();
    },
    25
);

add_action(
    'plugins_loaded',
    static function (): void {
        if (class_exists('\BPM\Modules\Vendor\GoogleCalendarSync')) {
            \BPM\Modules\Vendor\GoogleCalendarSync::boot();
        }
    },
    30
);

add_action(
    'plugins_loaded',
    static function (): void {
        if (class_exists('\BPM\Modules\Sales\SalesModule')) {
            \BPM\Modules\Sales\SalesModule::boot();
        }
    },
    35
);

add_action(
    'plugins_loaded',
    static function (): void {
        static $engine_booted = false;

        if ($engine_booted || ! class_exists('\SBDP\BookingEngine')) {
            return;
        }

        $modules = array();

        if (function_exists('SBDP\\Loader\\load_modules')) {
            $modules = \SBDP\Loader\load_modules(SBDP_DIR . 'modules');

            $excludedClasses = array(
                'BSP\\Finance\\Module',
                'BSP\\Data\\Module',
                'BSP\\Insights\\Module',
                'BSP\\GeoDashboard\\Module',
                'BSP\\Intelligence\\Module',
                'BSP\\Support\\Module',
            );

            $modules = array_values(
                array_filter(
                    $modules,
                    static function ($module) use ($excludedClasses): bool {
                        if (! is_object($module)) {
                            return false;
                        }

                        if (in_array(get_class($module), $excludedClasses, true)) {
                            return false;
                        }

                        if ($module instanceof \BSP\Core\Interfaces\ModuleInterface) {
                            return false;
                        }

                        return true;
                    }
                )
            );
        }

        $engine = new \SBDP\BookingEngine($modules);
        $engine->bootstrap();

        $GLOBALS['sbdp_booking_engine'] = $engine;
        $engine_booted = true;

        if (function_exists('do_action')) {
            do_action('sbdp/engine/bootstrapped', $engine);
        }
    },
    40
);

// Always disable demo seeding in this build.
$demoSeedsEnabled = false;

// Hook registration is skipped entirely.

if (! function_exists('sbdp_booking_engine')) {
    function sbdp_booking_engine(): ?\SBDP\BookingEngine
    {
        return $GLOBALS['sbdp_booking_engine'] ?? null;
    }
}

add_action(
    'init',
    static function (): void {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }

        $requestPath = isset($_SERVER['REQUEST_URI']) ? (string) wp_parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
        $normalizedPath = untrailingslashit($requestPath);
        if ($normalizedPath === '') {
            return;
        }

        $legacyPaths = array(
            '/activiteiten-overzicht',
            '/activititen-overzicht',
            '/activiteitenoverzicht',
        );

        if (! in_array($normalizedPath, $legacyPaths, true)) {
            return;
        }

        wp_safe_redirect(home_url('/activiteiten/'), 301);
        exit;
    },
    1
);

add_action(
    'wp_enqueue_scripts',
    static function (): void {
        // Defensive fallback for environments where Elementor omits its config bootstrap.
        if (! wp_script_is('elementor-frontend', 'registered') && ! wp_script_is('elementor-frontend', 'enqueued')) {
            return;
        }

        $settings = array();
        if (class_exists('\\Elementor\\Plugin')) {
            $elementor = \Elementor\Plugin::$instance ?? null;
            if ($elementor && isset($elementor->frontend) && is_object($elementor->frontend) && method_exists($elementor->frontend, 'get_settings')) {
                $settings = (array) $elementor->frontend->get_settings();
            }
        }

        if (empty($settings)) {
            $settings = array(
                'environmentMode' => array(),
                'is_rtl' => is_rtl(),
                'urls' => array(
                    'assets' => trailingslashit(content_url('plugins/elementor/assets')),
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'uploadUrl' => wp_upload_dir()['baseurl'] ?? '',
                ),
                'settings' => array(),
                'kit' => array(),
            );
        }

        wp_add_inline_script(
            'elementor-frontend',
            'var elementorFrontendConfig = ' . wp_json_encode($settings) . ';',
            'before'
        );
    },
    100
);

// phpcs:enable PSR1.Files.SideEffects.FoundWithSymbols
