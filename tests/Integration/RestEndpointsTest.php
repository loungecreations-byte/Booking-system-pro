<?php
declare(strict_types=1);

namespace BSP\Tests\Integration\Rest;

use BSP\Commerce\Module as CommerceModule;
use BSP\Core\Modules;
use BSP\Intelligence\Module as IntelligenceModule;
use BSP\Planner\Module as PlannerModule;
use BSP\Planner\Vendor\CityGuideProfileStore;
use BSP\Sales\Module as SalesModule;
use PHPUnit\Framework\TestCase;

final class RestEndpointsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Modules::register('commerce', CommerceModule::class);
        Modules::register('planner', PlannerModule::class);
        Modules::register('sales', SalesModule::class);
        Modules::register('intelligence', IntelligenceModule::class);
        Modules::loadAll();
    }

    public function testCommerceCalcPriceEndpoint(): void
    {
        $request = new class {
            public function get_param($key)
            {
                if ('base' === $key) {
                    return 100;
                }

                if ('rules' === $key) {
                    return [
                        ['type' => 'percent', 'value' => 10],
                        ['type' => 'fixed', 'value' => 5],
                    ];
                }

                return null;
            }
        };

        $response = \BSP\Commerce\Rest\Controller::calcPrice($request);

        $this->assertSame(['price' => 115.0], $response);
    }

    public function testPlannerAvailabilityEndpoint(): void
    {
        $request = new class {
            public function get_param($key)
            {
                if ('all' === $key) {
                    return ['09:00', '10:00'];
                }

                if ('booked' === $key) {
                    return ['10:00'];
                }

                return null;
            }
        };

        $response = \BSP\Planner\Rest\Controller::availability($request);

        $this->assertSame(['available' => ['09:00']], $response);
    }

    public function testPlannerGuideAvailabilityWithProfile(): void
    {
        $store = new CityGuideProfileStore();
        $guideId = $store->save([
            'name'     => 'Integration Guide',
            'ical_url' => 'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
DTSTART:20250101T090000Z
DTEND:20250101T100000Z
SUMMARY:Integration Tour
END:VEVENT
END:VCALENDAR',
            'timezone' => 'UTC',
        ]);

        $request = new class($guideId) {
            public function __construct(private int $guideId) {}

            public function get_param($key)
            {
                if ('guide_id' === $key) {
                    return $this->guideId;
                }
                return null;
            }
        };

        $response = \BSP\Planner\Rest\Controller::guideAvailability($request);
        $this->assertArrayHasKey('windows', $response);
        $this->assertSame('Integration Tour', $response['windows'][0]['summary']);
    }

    public function testSalesRevenueEndpoint(): void
    {
        $request = new class {
            public function get_param($key)
            {
                if ('orders' === $key) {
                    return [
                        ['amount' => 10],
                        ['amount' => 5.5],
                    ];
                }

                return null;
            }
        };

        $response = \BSP\Sales\Rest\Controller::revenue($request);

        $this->assertSame(['revenue' => 15.5], $response);
    }

    public function testIntelligenceTrendsEndpoint(): void
    {
        $request = new class {
            public function get_param($key)
            {
                if ('kv' === $key) {
                    return ['A' => 5, 'B' => 10, 'C' => 1];
                }

                if ('k' === $key) {
                    return 2;
                }

                return null;
            }
        };

        $response = \BSP\Intelligence\Rest\Controller::trends($request);

        $this->assertSame(['B' => 10, 'A' => 5], $response);
    }
}

