<?php
declare(strict_types=1);

namespace BSP\Tests\Unit\Bookings;

use BSP\Bookings\Service\BookingManager;
use BSP\Tests\Support\WooCommerceStubRegistry;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Support/WooCommerceStubs.php';

final class BookingManagerCaptureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WooCommerceStubRegistry::reset();
    }

    protected function tearDown(): void
    {
        WooCommerceStubRegistry::reset();
        parent::tearDown();
    }

    public function testRequestBookingMarksCapturedWhenWooCommerceAvailable(): void
    {
        WooCommerceStubRegistry::enable();
        WooCommerceStubRegistry::setMollieMode('none');

        $manager = BookingManager::createDefault();
        $booking = $manager->requestBooking($this->payload());

        $this->assertSame('captured', $booking['status']);
        $this->assertNotEmpty($booking['captured_at']);
        $this->assertIsArray($booking['order']);
        $this->assertSame('woocommerce', $booking['payment_request']['provider']);
    }

    public function testRequestBookingRemainsRequestedWithoutWooCommerce(): void
    {
        WooCommerceStubRegistry::disable();

        $manager = BookingManager::createDefault();
        $booking = $manager->requestBooking($this->payload());

        $this->assertSame('requested', $booking['status']);
        $this->assertNull($booking['payment_request']);
        $this->assertNull($booking['captured_at']);
    }

    public function testDispatchInvoiceCapturesBooking(): void
    {
        WooCommerceStubRegistry::enable();
        WooCommerceStubRegistry::setMollieMode('none');

        $manager = BookingManager::createDefault();
        $booking = $manager->createBooking($this->payload());

        $updated = $manager->dispatchInvoice((int) $booking['id']);

        $this->assertSame('captured', $updated['status']);
        $this->assertNotNull($updated['captured_at']);
        $this->assertIsArray($updated['payment_request']);
    }

    public function testRequestBookingPopulatesPlannerDetails(): void
    {
        WooCommerceStubRegistry::disable();

        $manager = BookingManager::createDefault();
        $booking = $manager->requestBooking($this->payload());

        $this->assertSame('requested', $booking['status']);
        $this->assertArrayHasKey('planner', $booking);
        $planner = $booking['planner'];
        $this->assertSame('unassigned', $planner['resource']);
        $this->assertIsArray($planner['timeline']);
        $this->assertNotEmpty($planner['timeline']);
        $slot = $planner['timeline'][0];
        $this->assertSame('10:00', $slot['slot']);
        $this->assertSame('Capture Tester', $slot['label']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'customer' => [
                'name'  => 'Capture Tester',
                'email' => 'capture@example.com',
            ],
            'date'         => '2025-05-15',
            'time'         => '10:00',
            'participants' => 3,
            'items'        => [
                [
                    'product_id' => 21,
                    'quantity'   => 1,
                    'unit_price' => 45.0,
                    'label'      => 'Canal Tour',
                ],
            ],
            'pricing_rules' => [],
            'notes'         => 'Capture test booking',
            'currency'      => 'EUR',
            'channel'       => 'planner',
            'vendor_id'     => null,
        ];
    }
}
