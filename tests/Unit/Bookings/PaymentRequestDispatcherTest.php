<?php
declare(strict_types=1);

namespace BSP\Tests\Unit\Bookings;

use BSP\Bookings\Service\PaymentRequestDispatcher;
use BSP\Tests\Support\WooCommerceStubRegistry;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Support/WooCommerceStubs.php';

final class PaymentRequestDispatcherTest extends TestCase
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

    public function testPrepareReturnsNullWhenWooCommerceDisabled(): void
    {
        WooCommerceStubRegistry::disable();

        $dispatcher = new PaymentRequestDispatcher();
        $result     = $dispatcher->prepare($this->bookingPayload());

        $this->assertNull($result);
    }

    public function testPrepareCreatesWooCommercePaymentRequest(): void
    {
        WooCommerceStubRegistry::enable();
        WooCommerceStubRegistry::setMollieMode('none');

        $dispatcher = new PaymentRequestDispatcher();
        $result     = $dispatcher->prepare($this->bookingPayload());

        $this->assertIsArray($result);
        $this->assertArrayHasKey('order', $result);
        $this->assertArrayHasKey('payment_request', $result);

        $this->assertSame('woocommerce', $result['payment_request']['provider']);
        $this->assertSame('checkout_link', $result['payment_request']['transport']);
        $this->assertStringContainsString('woocommerce.test/pay/', $result['payment_request']['url']);

        $invoices = WooCommerceStubRegistry::getInvoices();
        $this->assertCount(1, $invoices);
        $this->assertSame($result['order']['id'], $invoices[0]);
    }

    public function testPreparePrefersMollieLinkWhenAvailable(): void
    {
        WooCommerceStubRegistry::enable();
        WooCommerceStubRegistry::setMollieMode('link');

        $dispatcher = new PaymentRequestDispatcher();
        $result     = $dispatcher->prepare($this->bookingPayload());

        $this->assertIsArray($result);
        $this->assertArrayHasKey('payment_request', $result);
        $this->assertSame('mollie', $result['payment_request']['provider']);
        $this->assertStringContainsString('mollie.test/pay/', $result['payment_request']['url']);
        $this->assertSame('sent', $result['payment_request']['status']);
    }

    public function testPrepareAddsOrderItemsAndBookingMeta(): void
    {
        WooCommerceStubRegistry::enable();
        WooCommerceStubRegistry::setMollieMode('none');

        $dispatcher = new PaymentRequestDispatcher();
        $result     = $dispatcher->prepare($this->bookingPayload());

        $this->assertIsArray($result);

        $orderId = (int) $result['order']['id'];
        $this->assertGreaterThan(0, $orderId);

        $order = WooCommerceStubRegistry::getOrder($orderId);
        $this->assertNotNull($order);

        $items = $order->get_items();
        $this->assertCount(1, $items);
        $item = reset($items);
        $this->assertSame(11, $item->get_product_id());
        $this->assertSame(2, $item->get_quantity());
        $this->assertSame('Test Walk', $item->get_name());

        $this->assertSame(42, $order->get_meta('_sbdp_booking_id'));

        $payload = $this->bookingPayload();
        $payload['items'][] = [
            'product_id' => 13,
            'quantity'   => 1,
            'unit_price' => 10.0,
            'label'      => 'Second item',
        ];
        $payload['notes'] = 'Please be on time';

        $dispatcher->prepare($payload);
        $order = WooCommerceStubRegistry::getOrder($orderId);
        $this->assertCount(2, $order->get_items());
        $notesMeta = $order->get_meta('_sbdp_notes');
        if (is_array($notesMeta)) {
            $this->assertContains('Please be on time', $notesMeta);
        } else {
            $this->assertSame('Please be on time', $notesMeta);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function bookingPayload(): array
    {
        return [
            'id'           => 42,
            'customer'     => [
                'name'  => 'Dispatcher Tester',
                'email' => 'dispatcher@example.com',
            ],
            'items'        => [
                [
                    'product_id' => 11,
                    'quantity'   => 2,
                    'unit_price' => 15.0,
                    'label'      => 'Test Walk',
                ],
            ],
            'participants' => 2,
            'currency'     => 'EUR',
            'notes'        => 'Unit test booking',
        ];
    }
}
