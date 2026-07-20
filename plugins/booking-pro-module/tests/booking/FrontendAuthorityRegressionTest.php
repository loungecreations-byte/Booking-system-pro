<?php

declare(strict_types=1);

namespace BSP\Tests\BookingTruth;

use PHPUnit\Framework\TestCase;

final class FrontendAuthorityRegressionTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../..';

    public function testHomeOnboardingWidgetDoesNotInventRouteFallbacks(): void
    {
        $contents = $this->readRepoFile(
            'plugins/booking-pro-module/components/HomeOnboardingWidget.tsx'
        );

        $this->assertStringContainsString('const target = resolveOnboardingTarget(runtime);', $contents);
        $this->assertStringNotContainsString('?? "checkout"', $contents);
        $this->assertStringNotContainsString('?? "/plan-je-dag/"', $contents);
        $this->assertStringNotContainsString('?? "/offerte/"', $contents);
    }

    public function testLiveHomepageOnboardingPublishesRuntimeInsteadOfFabricatingPlannerRoute(): void
    {
        $contents = $this->readRepoFile(
            'plugins/booking-pro-module/modules/core/Shortcodes/Shortcodes.php'
        );

        $this->assertStringContainsString('window.SBDP_HomeOnboardingRuntime =', $contents);
        $this->assertStringContainsString('const runtimeTarget = resolveRuntimeTarget();', $contents);
        $this->assertStringNotContainsString("const url = buildUrl('/plan-je-dag');", $contents);
    }

    public function testActiveDesignRuntimeContainsNoLegacyImportantOverrides(): void
    {
        $contents = $this->readRepoFile(
            'plugins/ddb-core-ui/assets/css/design-system.css'
        );

        $this->assertStringNotContainsString('!important', $contents);
        $this->assertStringNotContainsString('DDB ULTIMATE DESIGN SYSTEM - PURE BLACK OLED EN PLANNER LAYOUT', $contents);
        $this->assertStringNotContainsString('THE PERFECT OLED CARD SYSTEM', $contents);
    }

    public function testCommerceRuntimeEnhancesCartWithoutReplacingWooTemplateTruth(): void
    {
        $contents = $this->readRepoFile(
            'plugins/booking-pro-module/modules/core/WooCommerce/CommercialFlowService.php'
        );

        $this->assertStringContainsString("add_action('woocommerce_after_cart'", $contents);
        $this->assertStringNotContainsString("remove_action('woocommerce_before_cart_table'", $contents);
        $this->assertStringNotContainsString("remove_action('woocommerce_cart_collaterals'", $contents);
        $this->assertStringNotContainsString("remove_action('woocommerce_after_cart'", $contents);
    }

    private function readRepoFile(string $relativePath): string
    {
        $path = self::ROOT . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $contents = file_get_contents($path);

        $this->assertIsString($contents, 'Failed to read fixture file: ' . $path);

        return $contents;
    }
}
