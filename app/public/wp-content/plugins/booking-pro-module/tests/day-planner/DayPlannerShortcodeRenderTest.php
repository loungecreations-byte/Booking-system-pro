<?php

declare(strict_types=1);

namespace BSP\Tests\DayPlanner;

use BSP\DayPlanner\Module;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/core/src/Interfaces/ModuleInterface.php';
require_once dirname(__DIR__, 2) . '/modules/day-planner/Module.php';

final class DayPlannerShortcodeRenderTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__test_shortcodes'] = array();
    }

    public function testCanonicalShortcodeRendersPlannerMountWithoutRawLeakage(): void
    {
        $module = new Module();
        $module->registerShortcodes();

        $output = do_shortcode('[sbdp_dayplanner]');

        $this->assertStringNotContainsString('[sbdp_dayplanner]', $output);
        $this->assertStringContainsString('id="sbdp-day-planner-root"', $output);
        $this->assertStringContainsString('data-component="sbdp-day-planner"', $output);
    }

    public function testLegacyAliasRendersPlannerMountWithoutRawLeakage(): void
    {
        $module = new Module();
        $module->registerShortcodes();

        $output = do_shortcode('[sbdp_day_planner]');

        $this->assertStringNotContainsString('[sbdp_day_planner]', $output);
        $this->assertStringContainsString('id="sbdp-day-planner-root"', $output);
        $this->assertStringContainsString('data-component="sbdp-day-planner"', $output);
    }
}
