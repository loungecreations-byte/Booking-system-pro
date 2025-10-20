<?php

declare(strict_types=1);

namespace BSP\Tests\Unit\Notifications;

use function Brain\Monkey\Functions\expect;
use BSP\Notifications\Admin_Settings_Page;
use BSP\Notifications\Module;
use BSP\Notifications\Rest_Controller;
use PHPUnit\Framework\TestCase;

final class ModuleTest extends TestCase
{
    private array $actionsBackup = array();

    private array $shortcodesBackup = array();

    private array $restRoutesBackup = array();

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('Brain\\Monkey\\setUp')) {
            $this->markTestSkipped('Brain Monkey test helpers are not available.');
        }

        \Brain\Monkey\setUp();

        global $bsp_test_actions, $bsp_test_shortcodes, $bsp_test_rest_routes;

        $this->actionsBackup    = is_array($bsp_test_actions ?? null) ? $bsp_test_actions : array();
        $this->shortcodesBackup = is_array($bsp_test_shortcodes ?? null) ? $bsp_test_shortcodes : array();
        $this->restRoutesBackup = is_array($bsp_test_rest_routes ?? null) ? $bsp_test_rest_routes : array();

        $bsp_test_actions     = array();
        $bsp_test_shortcodes  = array();
        $bsp_test_rest_routes = array();
    }

    protected function tearDown(): void
    {
        if (function_exists('Brain\\Monkey\\tearDown')) {
            \Brain\Monkey\tearDown();
        }

        global $bsp_test_actions, $bsp_test_shortcodes, $bsp_test_rest_routes;

        $bsp_test_actions     = $this->actionsBackup;
        $bsp_test_shortcodes  = $this->shortcodesBackup;
        $bsp_test_rest_routes = $this->restRoutesBackup;

        parent::tearDown();
    }

    public function testInitRegistersHooksAndShortcode(): void
    {
        $restController = new Rest_Controller();
        $adminPage      = new Admin_Settings_Page();

        $module = new Module($restController, $adminPage);
        $module->init();

        global $bsp_test_actions, $bsp_test_shortcodes, $bsp_test_rest_routes;

        $this->assertArrayHasKey('booking_notifications', $bsp_test_shortcodes);
        $this->assertSame(array($module, 'render_shortcode'), $bsp_test_shortcodes['booking_notifications']);

        $hooksByName = array();
        foreach ($bsp_test_actions as $action) {
            $hooksByName[$action['hook']][] = $action;
        }

        $this->assertArrayHasKey('rest_api_init', $hooksByName, 'rest_api_init hook not registered.');
        $this->assertArrayHasKey('admin_menu', $hooksByName, 'admin_menu hook not registered.');
        $this->assertArrayHasKey('admin_init', $hooksByName, 'admin_init hook not registered.');

        expect('current_time')->zeroOrMoreTimes()->andReturn(time());

        foreach ($hooksByName['rest_api_init'] as $action) {
            if (is_callable($action['callback'])) {
                call_user_func($action['callback']);
            }
        }

        $this->assertNotEmpty($bsp_test_rest_routes, 'REST route not registered.');
        $restRoute = $bsp_test_rest_routes[0];
        $this->assertSame('bsp/v1', $restRoute['namespace']);
        $this->assertSame('/notifications', $restRoute['route']);

        $output = $module->render_shortcode();

        $this->assertSame(0, strpos($output, '<div class="bsp-notifications"'), 'Shortcode output not rendered.');

        $adminMenuCallbacks = array_map(
            static fn (array $action) => $action['callback'],
            $hooksByName['admin_menu']
        );
        $this->assertTrue($this->callbackExists($adminMenuCallbacks, $adminPage, 'register_menu'), 'Admin menu callback missing.');

        $adminInitCallbacks = array_map(
            static fn (array $action) => $action['callback'],
            $hooksByName['admin_init']
        );
        $this->assertTrue($this->callbackExists($adminInitCallbacks, $adminPage, 'register_settings'), 'Admin settings callback missing.');
    }

    /**
     * @param array<int, callable> $callbacks
     */
    private function callbackExists(array $callbacks, object $expectedInstance, string $method): bool
    {
        foreach ($callbacks as $callback) {
            if (is_array($callback) && $callback[0] === $expectedInstance && $callback[1] === $method) {
                return true;
            }
        }

        return false;
    }
}
