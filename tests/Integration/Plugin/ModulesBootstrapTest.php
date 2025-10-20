<?php
declare(strict_types=1);

namespace BSP\Tests\Integration\Plugin;

use BSP\Planner\Rest\Controller as PlannerRestController;
use BSP\VendorPortal\Rest\PortalController;
use PHPUnit\Framework\TestCase;

final class ModulesBootstrapTest extends TestCase
{
    private static bool $bootstrapped = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $pluginDir = realpath(__DIR__ . '/../../dist/booking-pro-module-3.0-fixed1/booking-pro-module');
        if (false === $pluginDir) {
            self::markTestSkipped('Booking Pro module directory not found.');
        }

        if (! defined('ABSPATH')) {
            define('ABSPATH', __DIR__);
        }

        if (! function_exists('plugin_dir_path')) {
            function plugin_dir_path($file)
            {
                return dirname($file) . DIRECTORY_SEPARATOR;
            }
        }

        if (! function_exists('plugin_dir_url')) {
            function plugin_dir_url($file)
            {
                return 'http://example.com/wp-content/plugins/booking-pro-module/';
            }
        }

        if (! defined('SBDP_DIR')) {
            define('SBDP_DIR', $pluginDir . DIRECTORY_SEPARATOR);
        }

        if (! defined('SBDP_FILE')) {
            define('SBDP_FILE', SBDP_DIR . 'booking-pro-module.php');
        }

        if (! defined('SBDP_URL')) {
            define('SBDP_URL', 'http://example.com/wp-content/plugins/booking-pro-module/');
        }

        if (! defined('SBDP_VER')) {
            define('SBDP_VER', 'test');
        }

        require_once SBDP_DIR . 'includes/class-modules-autoloader.php';
        \SBDP_Modules_Autoloader::register();

        require_once SBDP_DIR . 'includes/class-core-agent.php';
        require_once SBDP_DIR . 'includes/class-modules-manager.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        global $bsp_test_actions, $bsp_test_rest_routes;
        $bsp_test_actions     = [];
        $bsp_test_rest_routes = [];
    }

    public function test_bootstrap_registers_core_module_hooks(): void
    {
        if (! self::$bootstrapped) {
            add_filter('sbdp/legacy_module_classes', '__return_empty_array');
            add_filter(
                'sbdp/core_module_classes',
                static function (): array {
                    return [
                        'planner'  => \BSP\Planner\Module::class,
                        'bookings' => \BSP\Bookings\Module::class,
                    ];
                }
            );

            \SBDP_Modules_Manager::bootstrap();
            self::$bootstrapped = true;
        }

        global $bsp_test_actions;
        $restHooks = array_filter(
            $bsp_test_actions,
            static function (array $hook): bool {
                return 'rest_api_init' === $hook['hook']
                    && false !== strpos(is_string($hook['callback']) ? $hook['callback'] : (is_array($hook['callback']) ? $hook['callback'][0] : ''), 'Planner');
            }
        );

        $this->assertNotEmpty($restHooks, 'Planner module should register REST API hooks.');
    }

    /**
     * @depends test_bootstrap_registers_core_module_hooks
     */
    public function test_planner_rest_routes_are_registered(): void
    {
        global $bsp_test_rest_routes;

        PlannerRestController::register();

        $routes = array_column($bsp_test_rest_routes, 'route');
        $this->assertContains('/planner/schedule', $routes);
        $this->assertContains('/planner/availability', $routes);
    }

    /**
     * @depends test_bootstrap_registers_core_module_hooks
     */
    public function test_vendor_portal_rest_routes_are_registered(): void
    {
        global $bsp_test_rest_routes;

        PortalController::register();

        $routes = array_column($bsp_test_rest_routes, 'route');
        $this->assertContains('/vendor-portal/login', $routes);
        $this->assertContains('/vendor-portal/dashboard', $routes);
        $this->assertContains('/vendor-portal/logout', $routes);
    }
}
