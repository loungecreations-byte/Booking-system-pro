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

    if (! class_exists('WP_Error')) {
        class WP_Error
        {
            public function __construct(
                public string $code = '',
                public string $message = '',
                public array $data = array()
            ) {
            }
        }
    }

    if (! class_exists('WP_REST_Request')) {
        class WP_REST_Request
        {
            private array $params = array();

            public function __construct(string $method = 'GET', string $route = '/')
            {
                unset($method, $route);
            }

            public function set_param(string $key, $value): void
            {
                $this->params[$key] = $value;
            }
        }
    }

    if (! class_exists('WP_REST_Response')) {
        class WP_REST_Response
        {
            public function __construct(private $data = null)
            {
            }

            public function get_data()
            {
                return $this->data;
            }
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
                        $GLOBALS['__booking_manager_logs'][] = $message;
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

            public function processOrder(int $orderId): string
            {
                return 'Processing order #' . $orderId;
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
                    return $booking;
                }

                $booking['resource'] = $booking['resource'] ?? 'unassigned';
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
                $GLOBALS['__booking_manager_sync'][] = $booking['id'] ?? null;
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
use BSP\Bookings\Service\BookingRepositoryInterface;
use BSPModule\Core\Services\BookingTruthRuntimeService;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/modules/bookings/Service/BookingRepositoryInterface.php';
require_once dirname(__DIR__, 2) . '/modules/bookings/Service/BookingRepositoryWriteGuard.php';
require_once dirname(__DIR__, 2) . '/modules/core/Services/BookingTruthRuntimeService.php';
require_once dirname(__DIR__, 2) . '/modules/bookings/Service/BookingManager.php';

final class BookingManagerExecutionGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__booking_truth_meta'] = array();
        $GLOBALS['__booking_manager_logs'] = array();
        $GLOBALS['__booking_manager_sync'] = array();
        $GLOBALS['__test_filters'] = array();
        add_filter('sbdp_planservice_availability_slots_payload', static function ($value) {
            unset($value);

            return array(
                'resource_valid' => true,
                'slots' => array(
                    array('start' => '10:00', 'end' => '11:00'),
                    array('start' => '12:00', 'end' => '13:00'),
                ),
                'capacity' => 20,
            );
        });
        add_filter('sbdp_planservice_execution_check', static function ($value) {
            unset($value);
            return true;
        });
    }

    public function testDirectCreateWithoutCanonicalTruthContextIsRejected(): void
    {
        $manager = $this->newManager();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Canonical booking truth context is required');

        $manager->createBooking($this->basePayload());
    }

    public function testCanonicalCreateBookingSucceeds(): void
    {
        $manager = $this->newManager();
        $payload = $this->basePayload();
        $context = $this->buildWriteContext($payload, 'test_create');

        $booking = $manager->createBooking($payload, $context);

        $this->assertSame(1, $booking['id']);
        $this->assertSame('checkout', $booking['booking_truth']['route_intent']);
        $this->assertSame('DIRECT', $booking['booking_truth']['booking_capability']);
        $this->assertSame(6, $booking['booking_truth']['participants']);
    }

    public function testStaleCreateContextIsRejected(): void
    {
        $manager = $this->newManager();
        $payload = $this->basePayload();
        $context = $this->buildWriteContext($payload, 'test_create');
        $context['participants'] = 3;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stale or incomplete');

        $manager->createBooking($payload, $context);
    }

    public function testRescheduleRejectsUnavailableMutationEvenWithContext(): void
    {
        $manager = $this->newManager();
        $payload = $this->basePayload();
        $created = $manager->createBooking($payload, $this->buildWriteContext($payload, 'seed_create'));

        remove_all_filters('sbdp_planservice_execution_check');
        add_filter('sbdp_planservice_execution_check', static function ($value) {
            unset($value);
            return new \WP_Error('sbdp_capacity', 'full', array('status' => 400));
        });

        $mutationPayload = array(
            'date' => '2026-05-10',
            'time' => '10:00',
            'date_end' => '2026-05-10',
            'time_end' => '11:00',
            'participants' => 6,
            'items' => $created['items'],
        );
        $context = $this->buildWriteContext($mutationPayload, 'reschedule_attempt');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('rejected the requested booking mutation');

        $manager->rescheduleBooking($created['id'], '2026-05-10', '10:00', '2026-05-10', '11:00', $context);
    }

    public function testUpdateParticipantsCannotBypassRequestOnlyTruth(): void
    {
        $manager = $this->newManager();
        $payload = $this->basePayload();
        $created = $manager->createBooking($payload, $this->buildWriteContext($payload, 'seed_create'));

        $GLOBALS['__booking_truth_meta'][10]['_wc_booking_requires_confirmation'] = 'yes';
        $mutationPayload = array(
            'date' => '2026-05-10',
            'time' => '10:00',
            'date_end' => '2026-05-10',
            'time_end' => '11:00',
            'participants' => 8,
            'items' => $created['items'],
        );
        $context = $this->buildWriteContext($mutationPayload, 'update_attempt');
        $context['route_intent'] = 'checkout';
        $context['booking_capability'] = 'DIRECT';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stale or incomplete');

        $manager->updateBookingDetails($created['id'], array('participants' => 8), $context);
    }

    /**
     * @return array<string, mixed>
     */
    private function basePayload(): array
    {
        return array(
            'customer' => array(
                'name' => 'Test Customer',
                'email' => 'test@example.com',
            ),
            'date' => '2026-05-10',
            'time' => '10:00',
            'date_end' => '2026-05-10',
            'time_end' => '11:00',
            'participants' => 6,
            'items' => array(
                array(
                    'product_id' => 10,
                    'quantity' => 1,
                    'unit_price' => 25.0,
                    'resource_id' => 9,
                    'label' => 'Canal Tour',
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

    private function newManager(): BookingManager
    {
        return new BookingManager(
            new class implements BookingRepositoryInterface {
                /** @var array<int, array<string, mixed>> */
                private array $storage = array();
                private int $nextId = 1;

                public function create(array $booking): array
                {
                    $booking['id'] = $this->nextId++;
                    $this->storage[$booking['id']] = $booking;
                    return $booking;
                }

                public function find(int $id): ?array
                {
                    return $this->storage[$id] ?? null;
                }

                public function update(int $id, array $changes): array
                {
                    $existing = $this->storage[$id] ?? null;
                    if (! is_array($existing)) {
                        throw new \InvalidArgumentException('Unknown booking identifier.');
                    }

                    $this->storage[$id] = array_merge($existing, $changes);
                    return $this->storage[$id];
                }

                public function all(): array
                {
                    return array_values($this->storage);
                }

                public function reset(): void
                {
                    $this->storage = array();
                    $this->nextId = 1;
                }
            },
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
