<?php

namespace {
    if (!class_exists('WP_REST_Request')) {
        class WP_REST_Request
        {
            public function __construct(private array $params = array(), private array $headers = array())
            {
            }

            public function get_json_params(): array
            {
                return $this->params;
            }

            public function get_param(string $key)
            {
                return $this->params[$key] ?? null;
            }

            public function get_header(string $key)
            {
                $lookup = strtoupper($key);
                foreach ($this->headers as $header => $value) {
                    if (strtoupper($header) === $lookup) {
                        return $value;
                    }
                }

                return null;
            }
        }
    }
}

namespace BSP\Tests\Integration\VendorPortal {

use BSP\Bookings\Rest\Controller as BookingController;
use BSP\Bookings\Service\BookingService;
use BSP\Commerce\Module as CommerceModule;
use BSP\Core\Modules;
use BSP\Planner\Module as PlannerModule;
use BSP\VendorPortal\Module as VendorPortalModule;
use BSP\VendorPortal\Rest\PortalController;
use PHPUnit\Framework\TestCase;

final class VendorPortalRestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BookingService::reset();

        Modules::register('commerce', CommerceModule::class);
        Modules::register('planner', PlannerModule::class);
        Modules::register('bookings', \BSP\Bookings\Module::class);
        Modules::register('vendor_portal', VendorPortalModule::class);
        Modules::loadAll();
    }

    public function testLoginDashboardAndLogoutFlow(): void
    {
        BookingController::create(new class extends \WP_REST_Request {
            public function get_json_params(): array
            {
                return [
                    'customer'     => ['name' => 'Vendor Tester', 'email' => 'vendor@example.com'],
                    'date'         => '2030-02-02',
                    'time'         => '10:30',
                    'participants' => 6,
                    'items'        => [
                        ['product_id' => 99, 'quantity' => 3, 'unit_price' => 15.0],
                    ],
                    'vendor_id'     => 77,
                    'pricing_rules' => [],
                ];
            }
        });

        $loginRequest = new class extends \WP_REST_Request {
            public function get_json_params(): array
            {
                return [
                    'vendor_id'  => 77,
                    'access_key' => 'demo',
                ];
            }
        };

        $loginResponse = PortalController::login($loginRequest);
        $this->assertArrayHasKey('token', $loginResponse);

        $token = $loginResponse['token'];

        $dashboardRequest = new class($token) extends \WP_REST_Request {
            public function __construct(private string $token)
            {
                parent::__construct(['token' => $token]);
            }
        };

        $dashboardResponse = PortalController::dashboard($dashboardRequest);
        $this->assertArrayHasKey('dashboard', $dashboardResponse);
        $this->assertSame(1, $dashboardResponse['dashboard']['financial']['total_bookings']);

        $logoutRequest = new class($token) extends \WP_REST_Request {
            public function __construct(private string $token)
            {
                parent::__construct(['token' => $token]);
            }

            public function get_json_params(): array
            {
                return ['token' => $this->token];
            }
        };

        $logoutResponse = PortalController::logout($logoutRequest);
        $this->assertTrue($logoutResponse['success']);
    }
}

}
