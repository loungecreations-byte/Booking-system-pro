<?php

declare(strict_types=1);

namespace BSP\Tests\Quotes;

use BSP\Bookings\Rest\Controller as BookingRestController;
use BSP\Commerce\Rest\Controller as CommerceRestController;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

final class PublicPaymentRouteBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['__test_rest_routes'] = array();
        $GLOBALS['__test_current_user_can'] = false;
    }

    public function testBookingPayPermissionIsNoLongerPublic(): void
    {
        BookingRestController::register();

        $route = $this->findRoute('bsp/v1', '/booking/pay');

        $this->assertNotNull($route);
        $this->assertSame([BookingRestController::class, 'canMutatePaymentState'], $route['permission_callback'] ?? null);
    }

    public function testUnauthenticatedBookingPayIsForbidden(): void
    {
        $request = new WP_REST_Request('POST', '/bsp/v1/booking/pay');
        $request->set_param('booking_id', 42);
        $request->set_param('method', 'manual');

        $permission = BookingRestController::canMutatePaymentState($request);
        $result = BookingRestController::pay($request);

        $this->assertInstanceOf(WP_Error::class, $permission);
        $this->assertSame('bsp_booking_payment_forbidden', $permission->code);
        $this->assertSame(403, $permission->data['status'] ?? null);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('bsp_booking_payment_forbidden', $result->code);
        $this->assertSame(403, $result->data['status'] ?? null);
    }

    public function testCommerceProcessOrderPermissionIsNoLongerPublic(): void
    {
        CommerceRestController::register();

        $route = $this->findRoute('bsp/v1', '/commerce/process-order');

        $this->assertNotNull($route);
        $this->assertSame([CommerceRestController::class, 'canProcessOrders'], $route['permission_callback'] ?? null);
    }

    public function testUnauthenticatedCommerceProcessOrderIsForbidden(): void
    {
        $request = new WP_REST_Request('POST', '/bsp/v1/commerce/process-order');
        $request->set_param('orderId', 99);

        $permission = CommerceRestController::canProcessOrders($request);
        $result = CommerceRestController::processOrder($request);

        $this->assertInstanceOf(WP_Error::class, $permission);
        $this->assertSame('bsp_commerce_process_order_forbidden', $permission->code);
        $this->assertSame(403, $permission->data['status'] ?? null);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('bsp_commerce_process_order_forbidden', $result->code);
        $this->assertSame(403, $result->data['status'] ?? null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRoute(string $namespace, string $route): ?array
    {
        foreach ($GLOBALS['__test_rest_routes'] as $registered) {
            if (($registered[0] ?? null) === $namespace && ($registered[1] ?? null) === $route) {
                return $registered[2] ?? null;
            }
        }

        return null;
    }
}
