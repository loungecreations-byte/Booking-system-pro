<?php
declare(strict_types=1);

namespace BSP\Tests\Integration\Planner;

use BSP\Bookings\Service\BookingManager;
use BSP\Planner\Rest\Controller;
use PHPUnit\Framework\TestCase;
use DateTimeImmutable;

final class PlannerBoardRestTest extends TestCase
{
    public function testBoardEndpointReturnsSampleData(): void
    {
        $manager = BookingManager::createDefault();
        $today   = (new DateTimeImmutable('today'))->format('Y-m-d');

        $manager->createBooking([
            'customer' => [
                'name'  => 'Integration Tester',
                'email' => 'tester@example.com',
            ],
            'date'         => $today,
            'time'         => '09:00',
            'participants' => 4,
            'items'        => [
                [
                    'product_id' => 101,
                    'quantity'   => 1,
                    'unit_price' => 75.0,
                    'label'      => 'Stadswandeling Integration',
                ],
            ],
            'pricing_rules' => [],
            'notes'         => 'Integration test booking',
            'currency'      => 'EUR',
            'channel'       => 'Website',
            'vendor_id'     => null,
        ]);

        $request = new class {
            public function get_param($key)
            {
                if ('days' === $key) {
                    return 3;
                }

                if ('outlet' === $key) {
                    return 'bsp-test';
                }

                return null;
            }
        };

        $response = Controller::board($request);

        $this->assertIsArray($response);
        $this->assertArrayHasKey('meta', $response);
        $this->assertSame(3, $response['meta']['days']);
        $this->assertSame('bsp-test', $response['meta']['outlet']);
        $this->assertArrayHasKey('resources', $response);
        $this->assertNotEmpty($response['resources']);
        $this->assertArrayHasKey('bookings', $response);
        $this->assertNotEmpty($response['bookings']);
        $this->assertArrayHasKey('timeslots', $response);
        $this->assertNotEmpty($response['timeslots']);
        $services = array_column($response['bookings'], 'service');
        $this->assertContains('Stadswandeling Integration', $services);
    }
}
