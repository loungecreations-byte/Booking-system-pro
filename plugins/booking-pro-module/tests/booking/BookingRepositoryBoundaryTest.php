<?php

declare(strict_types=1);

namespace {
    if (! function_exists('__')) {
        function __(string $text, string $domain = ''): string
        {
            unset($domain);
            return $text;
        }
    }

    if (! function_exists('get_post_meta')) {
        function get_post_meta(int $postId, string $key, bool $single = true)
        {
            unset($single);
            return $GLOBALS['__booking_truth_meta'][$postId][$key] ?? null;
        }
    }

    if (! function_exists('apply_filters')) {
        function apply_filters(string $tag, $value, ...$args)
        {
            if (empty($GLOBALS['__test_filters'][$tag]) || ! is_array($GLOBALS['__test_filters'][$tag])) {
                return $value;
            }

            ksort($GLOBALS['__test_filters'][$tag]);
            foreach ($GLOBALS['__test_filters'][$tag] as $callbacks) {
                foreach ($callbacks as $entry) {
                    $acceptedArgs = max(1, (int) ($entry['accepted_args'] ?? 1));
                    $callArgs = array_slice(array_merge(array($value), $args), 0, $acceptedArgs);
                    $value = ($entry['callback'])(...$callArgs);
                }
            }

            return $value;
        }
    }

    if (! function_exists('add_filter')) {
        function add_filter(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
        {
            $GLOBALS['__test_filters'][$tag][$priority][] = array(
                'callback' => $callback,
                'accepted_args' => $acceptedArgs,
            );

            return true;
        }
    }

    if (! function_exists('remove_all_filters')) {
        function remove_all_filters(string $tag): bool
        {
            unset($GLOBALS['__test_filters'][$tag]);
            return true;
        }
    }
}

namespace BSP\Core {
    if (! class_exists(CoreServiceProvider::class, false)) {
        final class CoreServiceProvider
        {
            public static function logger(): object
            {
                return new class {
                    public function log(string $message): void
                    {
                        $GLOBALS['__booking_boundary_logs'][] = $message;
                    }
                };
            }
        }
    }
}

namespace BSP\Commerce {
    if (! class_exists(Module::class, false)) {
        final class Module
        {
            public function reserveInventory(array $items): bool
            {
                return $items !== array();
            }
        }
    }
}

namespace BSP\Planner {
    if (! class_exists(Module::class, false)) {
        final class Module
        {
            public function assignResource(array $booking, array $resources): array
            {
                if ($resources !== array()) {
                    $booking['resource'] = (string) ($resources[0]['id'] ?? 'guide-1');
                }

                return $booking;
            }

            public function generateSchedule(array $bookings): array
            {
                unset($bookings);
                return array();
            }

            public function hasOverlap(array $bookings): bool
            {
                unset($bookings);
                return false;
            }
        }
    }
}

namespace BSP\Planner\Vendor {
    if (! class_exists(CityGuideProfileStore::class, false)) {
        final class CityGuideProfileStore
        {
            public function all(): array
            {
                return array();
            }
        }
    }
}

namespace BSP\Bookings\Service {
    if (! class_exists(PaymentRequestDispatcher::class, false)) {
        final class PaymentRequestDispatcher
        {
            public function prepare(array $booking, bool $sendInvoiceEmail = true): ?array
            {
                unset($booking, $sendInvoiceEmail);
                return null;
            }
        }
    }

    if (! class_exists(OperationsSyncService::class, false)) {
        final class OperationsSyncService
        {
            public function sync(array $booking): void
            {
                $GLOBALS['__booking_boundary_sync'][] = $booking['id'] ?? null;
            }
        }
    }
}

namespace BSPModule\Core\Rest {
    if (! class_exists(RestService::class, false)) {
        final class RestService
        {
            public static $availabilitySlotsResponse = array();

            public static function availability_slots($request)
            {
                unset($request);
                return self::$availabilitySlotsResponse;
            }
        }
    }
}

namespace BSPModule\Core\Services {
    if (! class_exists(AvailabilityExecutionService::class, false)) {
        final class AvailabilityExecutionService
        {
            public static $result = true;

            public static function checkItemRules(int $productId, int $resourceId, string $start, string $end, int $participants)
            {
                unset($productId, $resourceId, $start, $end, $participants);
                return self::$result;
            }
        }
    }
}

namespace BSP\Tests\BookingTruth {

use BSP\Bookings\Service\BookingManager;
use BSP\Bookings\Service\BookingRepository;
use BSP\Bookings\Service\BookingRepositoryWriteGuard;
use BSPModule\Core\Services\BookingTruthRuntimeService;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/modules/bookings/Service/BookingRepositoryInterface.php';
require_once dirname(__DIR__, 2) . '/modules/bookings/Service/BookingRepositoryWriteGuard.php';
require_once dirname(__DIR__, 2) . '/modules/bookings/Service/BookingRepository.php';
require_once dirname(__DIR__, 2) . '/modules/core/Services/BookingTruthRuntimeService.php';
require_once dirname(__DIR__, 2) . '/modules/bookings/Service/BookingManager.php';

final class BookingRepositoryBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__booking_truth_meta'] = array();
        $GLOBALS['__booking_boundary_logs'] = array();
        $GLOBALS['__booking_boundary_sync'] = array();
        $GLOBALS['__test_filters'] = array();

        add_filter('sbdp_planservice_availability_slots_payload', static function ($value) {
            unset($value);

            return array(
                'resource_valid' => true,
                'slots' => array(
                    array('start' => '10:00', 'end' => '11:00'),
                ),
                'capacity' => 20,
            );
        });
        add_filter('sbdp_planservice_execution_check', static function ($value) {
            unset($value);
            return true;
        });
    }

    public function testDirectRepositoryCreateIsRejected(): void
    {
        $repository = new BookingRepository();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Direct booking repository write bypass is blocked');

        $repository->create(array('status' => 'created'));
    }

    public function testDirectRepositoryUpdateIsRejected(): void
    {
        $repository = new BookingRepository();
        $manager = $this->newManager($repository);
        $payload = $this->basePayload();
        $booking = $manager->createBooking($payload, $this->buildWriteContext($payload, 'boundary_seed'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Direct booking repository write bypass is blocked');

        $repository->update((int) $booking['id'], array('status' => 'cancelled'));
    }

    public function testManagerControlledRepositoryWriteStillSucceeds(): void
    {
        $repository = new BookingRepository();
        $manager = $this->newManager($repository);
        $payload = $this->basePayload();

        $booking = $manager->createBooking($payload, $this->buildWriteContext($payload, 'boundary_create'));

        $this->assertSame(1, $booking['id']);
        $this->assertSame('DIRECT', $booking['booking_truth']['booking_capability']);
        $this->assertCount(1, $repository->all());
    }

    public function testRepositoryResetRequiresExplicitMaintenanceScope(): void
    {
        $repository = new BookingRepository();

        try {
            $repository->reset();
            $this->fail('Expected direct reset to be blocked.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('Direct booking repository reset is blocked', $exception->getMessage());
        }

        BookingRepositoryWriteGuard::allowMaintenanceReset(
            static function () use ($repository): void {
                $repository->reset();
            }
        );

        $this->assertSame(array(), $repository->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function basePayload(): array
    {
        return array(
            'customer' => array(
                'name' => 'Boundary Customer',
                'email' => 'boundary@example.com',
            ),
            'date' => '2026-06-12',
            'time' => '10:00',
            'date_end' => '2026-06-12',
            'time_end' => '11:00',
            'participants' => 4,
            'items' => array(
                array(
                    'product_id' => 10,
                    'quantity' => 1,
                    'unit_price' => 35.0,
                    'resource_id' => 8,
                    'label' => 'City Walk',
                ),
            ),
            'pricing_rules' => array(),
            'currency' => 'EUR',
            'channel' => 'manual',
        );
    }

    private function buildWriteContext(array $payload, string $source): array
    {
        return (new BookingTruthRuntimeService())->resolveBookingWriteContext(
            $payload,
            array(
                'resource_id' => (int) (($payload['resource_id'] ?? 0) ?: ($payload['items'][0]['resource_id'] ?? 0)),
                'validation_source' => $source,
            )
        );
    }

    private function newManager(BookingRepository $repository): BookingManager
    {
        return new BookingManager(
            $repository,
            new \BSP\Commerce\Module(),
            new \BSP\Planner\Module(),
            new \BSP\Planner\Vendor\CityGuideProfileStore(),
            new \BSP\Bookings\Service\PaymentRequestDispatcher(),
            new \BSP\Bookings\Service\OperationsSyncService(),
            new BookingTruthRuntimeService()
        );
    }
}
}
