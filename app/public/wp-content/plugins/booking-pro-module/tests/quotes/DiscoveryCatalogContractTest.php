<?php

declare(strict_types=1);

namespace BSP\DayPlanner\Rest {
    function sanitize_text_field(string $value): string
    {
        return trim($value);
    }
}

namespace BSP\Tests\Quotes {

use BSP\DayPlanner\Rest\PlansController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

require_once dirname(__DIR__, 2) . '/modules/day-planner/Rest/PlansController.php';

final class DiscoveryCatalogContractTest extends TestCase
{
    public function testPublicCatalogItemKeepsDiscoveryTruthFields(): void
    {
        $reflection = new ReflectionClass(PlansController::class);
        /** @var PlansController $controller */
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(PlansController::class, 'sanitizePublicCatalogItem');
        $method->setAccessible(true);

        $raw = [
            'id' => 42,
            'availability' => [
                'summary' => 'Vandaag beschikbaar',
            ],
            'availability_windows' => [
                ['start' => '09:00', 'end' => '11:00'],
            ],
            'booking_capability' => 'direct',
            'route_intent' => 'checkout',
            'discovery' => [
                'status' => 'direct',
            ],
            'resources' => [
                ['id' => '7', 'title' => ' Main room ', 'primary' => true],
            ],
        ];

        /** @var array<string, mixed> $sanitized */
        $sanitized = $method->invoke($controller, $raw);

        $this->assertArrayHasKey('availability', $sanitized);
        $this->assertSame('Vandaag beschikbaar', $sanitized['availability']['summary']);
        $this->assertArrayNotHasKey('availability_windows', $sanitized);
        $this->assertSame('direct', $sanitized['booking_capability']);
        $this->assertSame('checkout', $sanitized['route_intent']);
        $this->assertSame('direct', $sanitized['discovery']['status']);
        $this->assertSame(
            [
                [
                    'id' => 7,
                    'title' => 'Main room',
                    'primary' => true,
                ],
            ],
            $sanitized['resources']
        );
    }
}
}
