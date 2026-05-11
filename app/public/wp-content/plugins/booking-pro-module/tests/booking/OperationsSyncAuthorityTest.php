<?php

declare(strict_types=1);

namespace SBDP\Modules\Arrangements\Domain {
    if (! class_exists(ArrangementRepository::class, false)) {
        final class ArrangementRepository
        {
            public function find(int $id): ?array
            {
                return $id > 0 ? ['id' => $id] : null;
            }

            public function query(): array
            {
                return [];
            }
        }
    }

    if (! class_exists(ArrangementAvailabilityService::class, false)) {
        final class ArrangementAvailabilityService
        {
            public static array $lastContext = [];

            public function resolve(array $arrangement, array $context): array
            {
                unset($arrangement);
                self::$lastContext = $context;

                return [
                    'segments' => [
                        [
                            'product_id' => 10,
                            'title' => 'Segment',
                            'start' => '10:00',
                            'end' => '11:00',
                        ],
                    ],
                ];
            }
        }
    }
}

namespace BSP\Tests\BookingTruth {

use BSP\Bookings\Service\OperationsSyncService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use SBDP\Modules\Arrangements\Domain\ArrangementAvailabilityService;

require_once dirname(__DIR__, 2) . '/modules/bookings/Service/OperationsSyncService.php';

final class OperationsSyncAuthorityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ArrangementAvailabilityService::$lastContext = [];
    }

    public function testCanonicalParticipantsResolverIgnoresQuantityFallback(): void
    {
        $service = $this->newServiceWithoutConstructor();
        $method = new ReflectionMethod(OperationsSyncService::class, 'resolveCanonicalParticipants');
        $method->setAccessible(true);

        $participants = $method->invoke(
            $service,
            [],
            [
                'quantity' => 7,
                'meta' => [
                    'sbdp_canonical_participants' => 5,
                ],
            ]
        );

        $this->assertSame(5, $participants);
    }

    public function testCanonicalParticipantsResolverDoesNotInventParticipantsFromQuantity(): void
    {
        $service = $this->newServiceWithoutConstructor();
        $method = new ReflectionMethod(OperationsSyncService::class, 'resolveCanonicalParticipants');
        $method->setAccessible(true);

        $participants = $method->invoke(
            $service,
            [],
            [
                'quantity' => 7,
                'meta' => [],
            ]
        );

        $this->assertSame(0, $participants);
    }

    public function testArrangementTemplateLegsFailClosedWithoutCanonicalParticipants(): void
    {
        $service = $this->newServiceWithoutConstructor();

        $repositoryProperty = new \ReflectionProperty(OperationsSyncService::class, 'arrangementRepository');
        $repositoryProperty->setAccessible(true);
        $repositoryProperty->setValue($service, new \SBDP\Modules\Arrangements\Domain\ArrangementRepository());

        $availabilityProperty = new \ReflectionProperty(OperationsSyncService::class, 'arrangementAvailability');
        $availabilityProperty->setAccessible(true);
        $availabilityProperty->setValue($service, new ArrangementAvailabilityService());

        $method = new ReflectionMethod(OperationsSyncService::class, 'resolveArrangementTemplateLegs');
        $method->setAccessible(true);

        $legs = $method->invoke(
            $service,
            [
                'product_id' => 10,
                'quantity' => 9,
                'meta' => [],
            ],
            [
                'date' => '2026-05-10',
                'time' => '10:00',
            ]
        );

        $this->assertSame([], $legs);
        $this->assertSame([], ArrangementAvailabilityService::$lastContext);
    }

    private function newServiceWithoutConstructor(): OperationsSyncService
    {
        $reflection = new ReflectionClass(OperationsSyncService::class);

        return $reflection->newInstanceWithoutConstructor();
    }
}
}
