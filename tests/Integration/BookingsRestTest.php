<?php

declare(strict_types=1);

namespace BSP\Tests\Integration\Rest;

use BSP\Bookings\Rest\Controller as BookingController;
use BSP\Bookings\Service\BookingService;
use BSP\Commerce\Module as CommerceModule;
use BSP\Core\Modules;
use BSP\Planner\Module as PlannerModule;
use PHPUnit\Framework\TestCase;

final class BookingsRestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BookingService::reset();

        Modules::register('commerce', CommerceModule::class);
        Modules::register('planner', PlannerModule::class);
        Modules::register('bookings', \BSP\Bookings\Module::class);
        Modules::loadAll();
    }

    public function testCreateEndpoint(): void
    {
        $request = new class {
            public function get_json_params(): array
            {
                return [
                    'customer'     => ['name' => 'Eva', 'email' => 'eva@example.com'],
                    'date'         => '2025-05-01',
                    'participants' => 2,
                    'items'        => [
                        ['product_id' => 1, 'quantity' => 2, 'unit_price' => 15],
                    ],
                ];
            }
        };

        $response = BookingController::create($request);
        $this->assertSame('created', $response['status']);
    }

    public function testPayEndpoint(): void
    {
        $requestCreate = new class {
            public function get_json_params(): array
            {
                return [
                    'customer'     => ['name' => 'Frank', 'email' => 'frank@example.com'],
                    'date'         => '2025-06-10',
                    'participants' => 3,
                    'items'        => [
                        ['product_id' => 2, 'quantity' => 3, 'unit_price' => 20],
                    ],
                ];
            }
        };
        $booking = BookingController::create($requestCreate);

        $requestPay = new class($booking) {
            private array $booking;
            public function __construct(array $booking)
            {
                $this->booking = $booking;
            }

            public function get_json_params(): array
            {
                return [
                    'booking_id' => $this->booking['id'],
                    'method'     => 'card',
                    'reference'  => 'ABC123',
                ];
            }
        };

        $response = BookingController::pay($requestPay);
        $this->assertSame('paid', $response['status']);
        $this->assertSame('Processing order #' . $booking['id'], $response['order']['status_message']);
    }

    public function testListEndpointReturnsBookings(): void
    {
        $request = new class {
        };

        BookingController::create(new class {
            public function get_json_params(): array
            {
                return [
                    'customer'     => ['name' => 'Gina', 'email' => 'gina@example.com'],
                    'date'         => '2025-07-20',
                    'participants' => 5,
                    'items'        => [
                        ['product_id' => 3, 'quantity' => 5, 'unit_price' => 12],
                    ],
                ];
            }
        });

        $response = BookingController::list($request);
        $this->assertNotEmpty($response);
        $this->assertSame('created', $response[0]['status']);
    }
}
