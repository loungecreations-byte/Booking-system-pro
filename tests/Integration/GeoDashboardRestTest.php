<?php

declare(strict_types=1);

namespace BSP\Tests\Integration\GeoDashboard;

use BSP\Bookings\Rest\Controller as BookingController;
use BSP\Bookings\Service\BookingService;
use BSP\Commerce\Module as CommerceModule;
use BSP\Core\Modules;
use BSP\GeoDashboard\Module as GeoDashboardModule;
use BSP\GeoDashboard\Rest\Controller as GeoController;
use BSP\Planner\Module as PlannerModule;
use PHPUnit\Framework\TestCase;

final class GeoDashboardRestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BookingService::reset();

        Modules::register('commerce', CommerceModule::class);
        Modules::register('planner', PlannerModule::class);
        Modules::register('bookings', \BSP\Bookings\Module::class);
        Modules::register('geo_dashboard', GeoDashboardModule::class);
        Modules::loadAll();
    }

    public function testRestEndpointReturnsData(): void
    {
        BookingController::create(new class extends \WP_REST_Request {
            public function get_json_params(): array
            {
                return [
                    'customer'     => ['name' => 'Geo Tester', 'email' => 'geo@example.com'],
                    'date'         => '2030-03-03',
                    'time'         => '09:45',
                    'participants' => 4,
                    'items'        => [
                        ['product_id' => 5, 'quantity' => 2, 'unit_price' => 50.0],
                    ],
                    'vendor_id'     => 12,
                    'location'      => ['lat' => 52.37, 'lng' => 4.89],
                ];
            }
        });

        $response = GeoController::index(new \WP_REST_Request());

        $this->assertArrayHasKey('vendors', $response);
        $this->assertArrayHasKey('bookings', $response);
        $this->assertIsArray($response['vendors']);
        $this->assertIsArray($response['bookings']);
    }
}
