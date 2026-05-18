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

    if (! function_exists('wp_create_nonce')) {
        function wp_create_nonce(string $action): string
        {
            return 'valid-nonce-' . $action;
        }
    }

    if (! function_exists('wp_verify_nonce')) {
        function wp_verify_nonce(string $nonce, string $action): bool
        {
            return $nonce === 'valid-nonce-' . $action;
        }
    }

    if (! function_exists('wp_salt')) {
        function wp_salt(string $scheme = 'auth'): string
        {
            return 'public-booking-test-salt-' . $scheme;
        }
    }

    if (! function_exists('get_transient')) {
        function get_transient(string $key)
        {
            return $GLOBALS['__public_booking_transients'][$key]['value'] ?? false;
        }
    }

    if (! function_exists('set_transient')) {
        function set_transient(string $key, $value, int $ttl): bool
        {
            $GLOBALS['__public_booking_transients'][$key] = array(
                'value' => $value,
                'ttl'   => $ttl,
            );

            return true;
        }
    }

    if (! function_exists('delete_transient')) {
        function delete_transient(string $key): bool
        {
            unset($GLOBALS['__public_booking_transients'][$key]);

            return true;
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
        $GLOBALS['__public_booking_transients'] = array();
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

    public function testMissingTokenRejected(): void
    {
        $request = $this->baseRequest('/bsp/v1/booking/create', false);

        $result = Controller::create($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('bsp_booking_intent_required', $result->code);
    }

    public function testExpiredTokenRejected(): void
    {
        $request = $this->baseRequest('/bsp/v1/booking/create');
        foreach ($GLOBALS['__public_booking_transients'] as &$entry) {
            $entry['value']['expires_at'] = 1;
        }
        unset($entry);

        $result = Controller::create($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('bsp_booking_intent_expired', $result->code);
    }

    public function testInvalidTokenRejected(): void
    {
        $request = $this->baseRequest('/bsp/v1/booking/create', false);
        $request->set_header('X-BSP-Booking-Intent', 'not-a-valid-token');

        $result = Controller::create($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('bsp_booking_intent_invalid', $result->code);
    }

    public function testValidTokenAccepted(): void
    {
        $request = $this->baseRequest('/bsp/v1/booking/create');

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
        $this->assertSame(148.0, $result['pricing_snapshot']['line_total'] ?? null);
    }

    public function testPublicRequestWithValidTokenAccepted(): void
    {
        $request = $this->baseRequest('/bsp/v1/booking/request');
        $request->set_param('participants', 2);

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

    public function testPriceSpoofAttemptRejected(): void
    {
        $request = $this->baseRequest('/bsp/v1/booking/create');
        $items = $request->get_param('items');
        $items[0]['unit_price'] = 0;
        $request->set_param('items', $items);

        $result = Controller::create($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('bsp_booking_payload_commerce_fields', $result->code);
    }

    public function testOversizedRequestRejected(): void
    {
        $request = $this->baseRequest('/bsp/v1/booking/request');
        $item = $request->get_param('items')[0];
        $request->set_param('items', array_fill(0, 21, $item));

        $result = Controller::request($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('bsp_booking_items_invalid', $result->code);
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

    private function baseRequest(string $route, bool $withIntent = true): WP_REST_Request
    {
        $request = new WP_REST_Request('POST', $route);
        $request->set_header('x-sbdp-nonce', wp_create_nonce('sbdp_public_rest'));
        if ($withIntent) {
            $intent = Controller::createBookingIntent(array('source' => 'test'));
            $request->set_header('X-BSP-Booking-Intent', $intent['token']);
        }
        $request->set_param('customer', array(
            'name'  => 'Eva Example',
            'email' => 'eva@example.test',
        ));
        $request->set_param('participants', 4);
        $request->set_param('note', 'Customer note only');
        $request->set_param('items', array(
            array(
                'product_id' => 101,
                'date'       => gmdate('Y-m-d', strtotime('+14 days')),
                'start'      => '10:00',
                'end'        => '11:00',
                'combi_ids'  => array(7),
                'addons'     => array(array('id' => 'lunch')),
            ),
        ));

        return $request;
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
