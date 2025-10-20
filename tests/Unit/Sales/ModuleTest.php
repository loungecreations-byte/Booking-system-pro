<?php
declare(strict_types=1);

namespace BSP\Tests\Unit\Sales;

use BSP\Sales\Module;
use PHPUnit\Framework\TestCase;

final class ModuleTest extends TestCase
{
    private Module $module;

    protected function setUp(): void
    {
        parent::setUp();
        $this->module = new Module();
    }

    public function testCalculateRevenueSumsAmounts(): void
    {
        $revenue = $this->module->calculateRevenue([
            ['amount' => 10.0],
            ['amount' => 5.25],
        ]);

        $this->assertSame(15.25, $revenue);
    }

    public function testTopProductsReturnsHighestQuantities(): void
    {
        $top = $this->module->topProducts([
            ['sku' => 'A', 'qty' => 1],
            ['sku' => 'B', 'qty' => 5],
            ['sku' => 'A', 'qty' => 2],
        ], 2);

        $this->assertSame(['B' => 5, 'A' => 3], $top);
    }

    public function testConversionRatePercentages(): void
    {
        $this->assertSame(0.0, $this->module->conversionRate(0, 10));
        $this->assertSame(50.0, $this->module->conversionRate(200, 100));
    }

    public function testBuildSalesFeedNormalisesOrders(): void
    {
        $feed = $this->module->buildSalesFeed([
            ['id' => 1, 'amount' => 12.3, 'timestamp' => '2024-01-01'],
        ]);

        $this->assertSame([
            ['id' => 1, 'amount' => 12.3, 'ts' => '2024-01-01'],
        ], $feed);
    }

    public function testRunPromotionEngineAppliesRules(): void
    {
        $result = $this->module->runPromotionEngine([
            ['price' => 10.0, 'qty' => 2],
        ], [
            ['type' => 'percent', 'value' => 10],
            ['type' => 'fixed', 'value' => 5],
        ]);

        $this->assertSame(['total' => 13.0], $result);
    }

    public function testCohortRevenueAggregatesPerMonth(): void
    {
        $cohorts = $this->module->cohortRevenue([
            ['timestamp' => '2024-01-15', 'amount' => 100.0],
            ['timestamp' => '2024-01-30', 'amount' => 50.0],
            ['timestamp' => '2024-02-02', 'amount' => 25.5],
        ]);

        $this->assertSame([
            '2024-01' => 150.0,
            '2024-02' => 25.5,
        ], $cohorts);
    }
}
