<?php

declare(strict_types=1);

namespace BSP\Tests\Unit\VendorPortal;

use BSP\Bookings\Service\BookingService;
use BSP\VendorPortal\Service\VendorDashboardService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class VendorDashboardServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BookingService::reset();
    }

    public function testBuildDashboardAggregatesData(): void
    {
        $first = BookingService::create([
            'customer'     => ['name' => 'Alice', 'email' => 'alice@example.com'],
            'date'         => '2030-01-10',
            'time'         => '09:00',
            'participants' => 4,
            'items'        => [
                ['product_id' => 1, 'quantity' => 2, 'unit_price' => 25.0],
            ],
            'vendor_id'     => 42,
            'pricing_rules' => [],
        ]);

        BookingService::request([
            'customer'     => ['name' => 'Bob', 'email' => 'bob@example.com'],
            'date'         => '2030-01-11',
            'time'         => '11:30',
            'participants' => 2,
            'items'        => [
                ['product_id' => 2, 'quantity' => 1, 'unit_price' => 40.0],
            ],
            'vendor_id'     => 42,
            'pricing_rules' => [],
        ]);

        $paid = BookingService::create([
            'customer'     => ['name' => 'Carol', 'email' => 'carol@example.com'],
            'date'         => '2030-01-12',
            'time'         => '14:00',
            'participants' => 3,
            'items'        => [
                ['product_id' => 3, 'quantity' => 3, 'unit_price' => 10.0],
            ],
            'vendor_id'     => 42,
            'pricing_rules' => [],
        ]);

        BookingService::pay([
            'booking_id' => $paid['id'],
            'method'     => 'credit_card',
        ]);

        // Booking for a different vendor that should be ignored.
        BookingService::create([
            'customer'     => ['name' => 'Dia', 'email' => 'dia@example.com'],
            'date'         => '2030-01-13',
            'time'         => '10:00',
            'participants' => 1,
            'items'        => [
                ['product_id' => 4, 'quantity' => 1, 'unit_price' => 99.0],
            ],
            'vendor_id'     => 7,
            'pricing_rules' => [],
        ]);

        $dashboard = (new VendorDashboardService())->buildDashboard(42);

        $this->assertCount(3, $dashboard['bookings']);
        $this->assertSame(3, $dashboard['financial']['total_bookings']);
        $this->assertSame(120.0, $dashboard['financial']['total_revenue']);
        $this->assertSame(30.0, $dashboard['financial']['paid_revenue']);
        $this->assertSame(90.0, $dashboard['financial']['pending_revenue']);
        $this->assertSame(3, count($dashboard['upcoming']));
        $counts = $dashboard['financial']['booking_counts'];
        $this->assertSame(1, $counts['paid']);
        $this->assertSame(1, $counts['created']);
        if (isset($counts['captured'])) {
            $this->assertSame(1, $counts['captured']);
        } else {
            $this->assertSame(1, $counts['requested']);
        }
    }

    public function testBuildDashboardRequiresValidVendor(): void
    {
        $service = new VendorDashboardService();

        $this->expectException(InvalidArgumentException::class);
        $service->buildDashboard(0);
    }
}
