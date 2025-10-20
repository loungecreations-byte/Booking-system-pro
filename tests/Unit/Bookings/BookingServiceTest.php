<?php

declare(strict_types=1);

namespace BSP\Tests\Unit\Bookings;

use BSP\Bookings\Service\BookingService;
use BSP\Tests\Support\WooCommerceStubRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Support/WooCommerceStubs.php';

final class BookingServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BookingService::reset();
        WooCommerceStubRegistry::reset();
        WooCommerceStubRegistry::disable();
    }

    public function testCreateBookingCalculatesTotal(): void
    {
        $booking = BookingService::create([
            'customer' => ['name' => 'Alice', 'email' => 'alice@example.com'],
            'date' => '2025-01-10',
            'participants' => 4,
            'items' => [
                ['product_id' => 1, 'quantity' => 2, 'unit_price' => 25.0],
                ['product_id' => 2, 'quantity' => 1, 'unit_price' => 60.0],
            ],
            'pricing_rules' => [
                ['type' => 'fixed', 'value' => -10],
            ],
        ]);

        $this->assertSame('created', $booking['status']);
        $this->assertSame(100.0, $booking['total']);
        $this->assertCount(1, BookingService::all());
    }

    public function testRequestBookingMarksStatusAndPersists(): void
    {
        $booking = BookingService::request([
            'customer' => ['name' => 'Bob', 'email' => 'bob@example.com'],
            'date' => '2025-02-01',
            'participants' => 2,
            'items' => [
                ['product_id' => 5, 'quantity' => 1, 'unit_price' => 40.0],
            ],
        ]);

        $this->assertSame('requested', $booking['status']);
        $this->assertCount(1, BookingService::getBookings());
    }

    public function testPayUpdatesStatusAndLinksOrder(): void
    {
        $booking = BookingService::create([
            'customer' => ['name' => 'Carol', 'email' => 'carol@example.com'],
            'date' => '2025-03-05',
            'participants' => 3,
            'items' => [
                ['product_id' => 8, 'quantity' => 3, 'unit_price' => 30.0],
            ],
        ]);

        $paid = BookingService::pay([
            'booking_id' => $booking['id'],
            'method'     => 'credit_card',
            'reference'  => 'TX123',
        ]);

        $this->assertSame('paid', $paid['status']);
        $this->assertSame('credit_card', $paid['payment']['method']);
        $this->assertArrayHasKey('order', $paid);
        $this->assertSame('Processing order #' . $booking['id'], $paid['order']['status_message']);
    }

    public function testCreateThrowsForInvalidItems(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BookingService::create([
            'customer' => ['name' => 'Dave', 'email' => 'dave@example.com'],
            'date' => '2025-04-01',
            'participants' => 1,
            'items' => [
                ['product_id' => 0],
            ],
        ]);
    }
}
