<?php
declare(strict_types=1);

namespace BSP\Tests\Unit\Intelligence;

use BSP\Intelligence\Module;
use PHPUnit\Framework\TestCase;

final class ModuleTest extends TestCase
{
    private Module $module;

    protected function setUp(): void
    {
        parent::setUp();
        $this->module = new Module();
    }

    public function testAnalyzeTrendsReturnsTopKEntries(): void
    {
        $trends = $this->module->analyzeTrends([
            'A' => 5,
            'B' => 10,
            'C' => 2,
        ], 2);

        $this->assertSame(['B' => 10, 'A' => 5], $trends);
    }

    public function testDetectAnomaliesFiltersByThreshold(): void
    {
        $anomalies = $this->module->detectAnomalies([
            'A' => 5,
            'B' => 15,
        ], 10);

        $this->assertSame(['B' => 15.0], $anomalies);
    }

    public function testForecastDemandCalculatesMovingAverage(): void
    {
        $forecast = $this->module->forecastDemand([
            '2024-01-01' => 10,
            '2024-01-02' => 20,
            '2024-01-03' => 30,
            '2024-01-04' => 40,
        ], 3);

        $this->assertSame([
            '2024-01-01' => 10.0,
            '2024-01-02' => 15.0,
            '2024-01-03' => 20.0,
            '2024-01-04' => 30.0,
        ], $forecast);
    }

    public function testRecommendUpsellSuggestsMissingSkus(): void
    {
        $suggestions = $this->module->recommendUpsell(
            [['sku' => 'A']],
            [
                ['related' => ['B', 'A']],
                ['related' => ['C']],
            ]
        );

        sort($suggestions);
        $this->assertSame(['B', 'C'], $suggestions);
    }

    public function testComputeKpisReturnsRoundedValues(): void
    {
        $kpis = $this->module->computeKPIs([
            'orders' => 4,
            'revenue' => 123.456,
        ]);

        $this->assertSame([
            'orders' => 4,
            'revenue' => 123.46,
            'aov' => 30.86,
        ], $kpis);
    }

    public function testSegmentCustomersSplitsBuckets(): void
    {
        $segments = $this->module->segmentCustomers([
            ['id' => 'vip', 'total' => 1500],
            ['id' => 'regular', 'total' => 200],
            ['id' => 'new', 'total' => 10],
        ]);

        $this->assertSame([
            'VIP' => ['vip'],
            'REGULAR' => ['regular'],
            'NEW' => ['new'],
        ], $segments);
    }
}
