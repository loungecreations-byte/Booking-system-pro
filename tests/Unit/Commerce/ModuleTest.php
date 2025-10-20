<?php
declare(strict_types=1);

namespace BSP\Tests\Unit\Commerce;

use BSP\Commerce\Module;
use PHPUnit\Framework\TestCase;

final class ModuleTest extends TestCase
{
    private Module $module;

    protected function setUp(): void
    {
        parent::setUp();
        $this->module = new Module();
    }

    public function testProcessOrderWithInvalidId(): void
    {
        $this->assertSame('Invalid order identifier provided', $this->module->processOrder(0));
    }

    public function testProcessOrderWithValidId(): void
    {
        $this->assertSame('Processing order #42', $this->module->processOrder(42));
    }

    public function testCalculatePriceAppliesPercentAndFixedRules(): void
    {
        $price = $this->module->calculatePrice(100.0, [
            ['type' => 'percent', 'value' => 10],
            ['type' => 'fixed', 'value' => 5],
        ]);

        $this->assertSame(115.0, $price);
    }

    public function testCalculatePriceNeverReturnsNegativeValues(): void
    {
        $price = $this->module->calculatePrice(10.0, [
            ['type' => 'fixed', 'value' => -50],
        ]);

        $this->assertSame(0.0, $price);
    }

    public function testApplyCouponsAppliesDiscountsPerItem(): void
    {
        $items = [
            ['price' => 50.0],
        ];
        $coupons = [
            ['type' => 'percent', 'value' => 10],
            ['type' => 'fixed', 'value' => 5],
        ];

        $updated = $this->module->applyCoupons($items, $coupons);

        $this->assertSame([['price' => 40.0]], $updated);
    }

    public function testReserveInventoryRequiresItems(): void
    {
        $this->assertFalse($this->module->reserveInventory([]));
        $this->assertTrue($this->module->reserveInventory([['id' => 1]]));
    }

    public function testSaveOrderMetaRequiresIdAndMeta(): void
    {
        $this->assertFalse($this->module->saveOrderMeta(0, ['foo' => 'bar']));
        $this->assertFalse($this->module->saveOrderMeta(10, []));
        $this->assertTrue($this->module->saveOrderMeta(10, ['foo' => 'bar']));
    }

    public function testGetOrderStatus(): void
    {
        $this->assertSame('unknown', $this->module->getOrderStatus(0));
        $this->assertSame('processing', $this->module->getOrderStatus(10));
    }
}
