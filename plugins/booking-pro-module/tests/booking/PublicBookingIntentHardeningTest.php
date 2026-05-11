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

    if (! function_exists('get_option')) {
        function get_option(string $key, $default = false)
        {
            return $GLOBALS['__public_booking_options'][$key] ?? $default;
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

    if (! function_exists('function_exists')) {
        function function_exists(string $name): bool
        {
            return \function_exists($name);
        }
    }

    if (! function_exists('rest_ensure_response')) {
        function rest_ensure_response($data)
        {
            return $data;
        }
    }

    if (! function_exists('get_post_meta')) {
        function get_post_meta(int $postId, string $key, bool $single = true)
        {
            unset($single);
            return $GLOBALS['__booking_truth_meta'][$postId][$key] ?? null;
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

            public function get_json_params(): array
            {
                return $this->params;
            }

            public function set_param(string $key, $value): void
            {
                $this->params[$key] = $value;
            }

            public function get_param(string $key)
            {
                return $this->params[$key] ?? null;
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

    if (! function_exists('wc_get_product')) {
        function wc_get_product(int $productId)
        {
            return $GLOBALS['__test_wc_products'][$productId] ?? null;
        }
    }

    if (! class_exists('WC_Product')) {
        class WC_Product
        {
            public function __construct(private float $price = 0.0)
            {
            }

            public function get_price(): float
            {
                return $this->price;
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
                        $GLOBALS['__public_booking_logs'][] = $message;
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
                unset($resources);
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
                $GLOBALS['__public_booking_sync'][] = $booking['id'] ?? null;
            }
        }
    }
}

namespace BSPModule\Core\Rest {
    if (! class_exists(RestService::class, false)) {
        final class RestService
        {
            public static function availability_slots($request)
            {
                unset($request);
                return array();
            }
        }
    }
}

namespace SBDP\Pricing {
    if (! class_exists(PricingService::class, false)) {
        final class PricingService
        {
            public static function instance(): self
            {
                return new self();
            }

            public function quote(int $productId, int $quantity = 1, array $context = array()): array
            {
                unset($quantity, $context);
                return $GLOBALS['__public_booking_quotes'][$productId] ?? array(
                    'unit_price' => 0.0,
                    'currency'   => 'EUR',
                );
            }
        }
    }
}

namespace BSP\Tests\BookingBoundary {

use BSP\Bookings\Rest\Controller;
use BSP\Bookings\Service\BookingManager;
use BSP\Bookings\Service\BookingRepositoryInterface;
use BSP\Bookings\Service\BookingService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use WP_REST_Request;

require_once dirname(__DIR__, 2) . '/modules/bookings/Service/BookingRepositoryInterface.php';
require_once dirname(__DIR__, 2) . '/modules/bookings/Service/BookingRepositoryWriteGuard.php';
require_once dirname(__DIR__, 2) . '/modules/core/Services/BookingTruthRuntimeService.php';
require_once dirname(__DIR__, 2) . '/modules/bookings/Service/BookingManager.php';
require_once dirname(__DIR__, 2) . '/modules/bookings/Service/BookingService.php';
require_once dirname(__DIR__, 2) . '/modules/bookings/Rest/Controller.php';

final class PublicBookingIntentHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['__booking_truth_meta'] = array();
        $GLOBALS['__public_booking_logs'] = array();
        $GLOBALS['__public_booking_sync'] = array();
        $GLOBALS['__public_booking_quotes'] = array(
            101 => array(
                'unit_price' => 37.0,
                'currency'   => 'EUR',
            ),
        );
        $GLOBALS['__public_booking_options'] = array(
            'woocommerce_currency' => 'EUR',
            'admin_email'          => '',
        );
        $GLOBALS['__test_filters'] = array();
        $GLOBALS['__test_wc_products'] = array(
            101 => new \WC_Product(12.5),
        );

        add_filter('sbdp_planservice_availability_slots_payload', static function ($value) {
            unset($value);

            return array(
                'resource_valid' => true,
                'capacity'       => 20,
                'slots'          => array(
                    array('start' => '10:00', 'end' => '11:00'),
                ),
            );
        });

        add_filter('sbdp_planservice_execution_check', static function ($value) {
            unset($value);
            return true;
        });

        $this->installManager(new BookingManager(
            new InMemoryBookingRepository(),
            new \BSP\Commerce\Module(),
            new \BSP\Planner\Module(),
            new \BSP\Planner\Vendor\CityGuideProfileStore(),
            new \BSP\Bookings\Service\PaymentRequestDispatcher(),
            new \BSP\Bookings\Service\OperationsSyncService()
        ));
    }

    protected function tearDown(): void
    {
        remove_all_filters('sbdp_planservice_availability_slots_payload');
        remove_all_filters('sbdp_planservice_execution_check');
        $this->resetBookingServiceStatics();

        parent::tearDown();
    }

    public function testPublicCreateIgnoresClientCommerceAndStatusFields(): void
    {
        $request = new WP_REST_Request('POST', '/bsp/v1/booking/create');
        $request->set_param('customer', array(
            'name'  => 'Eva Example',
            'email' => 'eva@example.test',
        ));
        $request->set_param('participants', 4);
        $request->set_param('status', 'confirmed');
        $request->set_param('bookingStatus', 'confirmed');
        $request->set_param('paymentStatus', 'paid');
        $request->set_param('processedStatus', 'done');
        $request->set_param('availabilityStatus', 'available');
        $request->set_param('currency', 'USD');
        $request->set_param('total', 0);
        $request->set_param('vendor_id', 55);
        $request->set_param('resource_id', 66);
        $request->set_param('pricing_rules', array(array('type' => 'fixed', 'value' => 999)));
        $request->set_param('booking_truth', array(
            'validated_by'       => 'client',
            'route_intent'       => 'blocked',
            'booking_capability' => 'UNAVAILABLE',
        ));
        $request->set_param('note', 'Customer note only');
        $request->set_param('items', array(
            array(
                'product_id' => 101,
                'date'       => '2026-06-01',
                'start'      => '10:00',
                'end'        => '11:00',
                'unit_price' => 0,
                'quantity'   => 1,
                'resource_id'=> 999,
                'capacity'   => 999,
                'combi_ids'  => array(7),
                'addons'     => array(array('id' => 'lunch')),
            ),
        ));

        $result = Controller::create($request);

        $this->assertSame('created', $result['status']);
        $this->assertSame('EUR', $result['currency']);
        $this->assertSame(148.0, $result['total']);
        $this->assertSame('Customer note only', $result['notes']);
        $this->assertNull($result['vendor']);
        $this->assertNull($result['payment']);
        $this->assertSame(array(), $result['pricing_rules']);
        $this->assertSame('booking_truth_runtime', $result['booking_truth']['validated_by'] ?? null);
        $this->assertSame('checkout', $result['booking_truth']['route_intent'] ?? null);
        $this->assertSame('DIRECT', $result['booking_truth']['booking_capability'] ?? null);
        $this->assertSame(37.0, $result['items'][0]['unit_price']);
        $this->assertSame(4, $result['items'][0]['quantity']);
        $this->assertSame(0, $result['items'][0]['resource_id'] ?? 0);
        $this->assertSame(148.0, $result['pricing_snapshot']['line_total'] ?? null);
    }

    public function testPublicRequestIgnoresClientTruthAndStoresServerDerivedStatusAndTotal(): void
    {
        $request = new WP_REST_Request('POST', '/bsp/v1/booking/request');
        $request->set_param('customer', array(
            'name'  => 'Noor Example',
            'email' => 'noor@example.test',
        ));
        $request->set_param('participants', 2);
        $request->set_param('status', 'paid');
        $request->set_param('paymentStatus', 'paid');
        $request->set_param('booking_truth', array(
            'validated_by'       => 'attacker',
            'route_intent'       => 'quote',
            'booking_capability' => 'REQUEST',
        ));
        $request->set_param('vendor_id', 123);
        $request->set_param('pricing_rules', array(array('type' => 'percent', 'value' => -100)));
        $request->set_param('total', 0);
        $request->set_param('items', array(
            array(
                'product_id' => 101,
                'date'       => '2026-06-02',
                'start'      => '2026-06-02T10:00:00',
                'end'        => '2026-06-02T11:00:00',
                'unit_price' => 0,
                'quantity'   => 999,
            ),
        ));

        $result = Controller::request($request);

        $this->assertSame('requested', $result['status']);
        $this->assertSame(74.0, $result['total']);
        $this->assertSame(array(), $result['pricing_rules']);
        $this->assertNull($result['vendor']);
        $this->assertNull($result['payment']);
        $this->assertSame('booking_truth_runtime', $result['booking_truth']['validated_by'] ?? null);
        $this->assertSame('DIRECT', $result['booking_truth']['booking_capability'] ?? null);
        $this->assertSame(37.0, $result['items'][0]['unit_price']);
        $this->assertSame(2, $result['items'][0]['quantity']);
    }

    private function installManager(BookingManager $manager): void
    {
        $reflection = new ReflectionClass(BookingService::class);

        $managerProperty = $reflection->getProperty('manager');
        $managerProperty->setAccessible(true);
        $managerProperty->setValue(null, $manager);
    }

    private function resetBookingServiceStatics(): void
    {
        $reflection = new ReflectionClass(BookingService::class);
        foreach (array('manager', 'repository') as $propertyName) {
            $property = $reflection->getProperty($propertyName);
            $property->setAccessible(true);
            $property->setValue(null, null);
        }
    }
}

final class InMemoryBookingRepository implements BookingRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $records = array();
    private int $nextId = 1;

    public function create(array $booking): array
    {
        $booking['id'] = $this->nextId++;
        $this->records[$booking['id']] = $booking;

        return $booking;
    }

    public function find(int $id): ?array
    {
        return $this->records[$id] ?? null;
    }

    public function update(int $id, array $changes): array
    {
        if (! isset($this->records[$id])) {
            throw new \InvalidArgumentException('Unknown booking identifier.');
        }

        $this->records[$id] = array_merge($this->records[$id], $changes);

        return $this->records[$id];
    }

    public function all(): array
    {
        return array_values($this->records);
    }

    public function reset(): void
    {
        $this->records = array();
        $this->nextId = 1;
    }
}

}
