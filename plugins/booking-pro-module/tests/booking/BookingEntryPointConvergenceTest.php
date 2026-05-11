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

    if (! function_exists('sanitize_text_field')) {
        function sanitize_text_field($value): string
        {
            return trim((string) $value);
        }
    }

    if (! function_exists('get_post_meta')) {
        function get_post_meta(int $postId, string $key, bool $single = true)
        {
            unset($single);
            return $GLOBALS['__booking_truth_meta'][$postId][$key] ?? null;
        }
    }

    if (! function_exists('get_the_title')) {
        function get_the_title(int $postId): string
        {
            return $GLOBALS['__booking_truth_titles'][$postId] ?? '';
        }
    }

    if (! function_exists('is_wp_error')) {
        function is_wp_error($thing): bool
        {
            return $thing instanceof \WP_Error;
        }
    }

    if (! function_exists('wc_get_product')) {
        function wc_get_product(int $productId)
        {
            return $GLOBALS['__test_wc_products'][$productId] ?? null;
        }
    }

    if (! function_exists('wc_add_notice')) {
        function wc_add_notice(string $message, string $type = 'success'): void
        {
            $GLOBALS['__test_wc_notices'][] = array(
                'message' => $message,
                'type'    => $type,
            );
        }
    }

    if (! function_exists('wp_unslash')) {
        function wp_unslash($value)
        {
            return $value;
        }
    }

    if (! function_exists('wp_verify_nonce')) {
        function wp_verify_nonce(string $nonce, string $action): bool
        {
            unset($nonce, $action);
            return false;
        }
    }

    if (! class_exists('WC_Product')) {
        class WC_Product
        {
            public function __construct(
                private int $id = 0,
                private string $type = 'simple'
            ) {
            }

            public function get_id(): int
            {
                return $this->id;
            }

            public function get_type(): string
            {
                return $this->type;
            }

            public function get_name(): string
            {
                return 'Product ' . $this->id;
            }

            public function set_price(float $price): void
            {
                unset($price);
            }
        }
    }
}

namespace BSP\Core\Interfaces {
    if (! interface_exists(ModuleInterface::class)) {
        interface ModuleInterface
        {
            public function init(): void;
        }
    }
}

namespace BSPModule\Core\WooCommerce\ProductType {
    if (! class_exists(BookableServiceProductType::class)) {
        final class BookableServiceProductType
        {
            public const PRODUCT_TYPE = 'bookable_service';
        }
    }
}

namespace BSPModule\Core\WooCommerce {
    if (! class_exists(ProductPageContext::class)) {
        final class ProductPageContext
        {
            public static function getCurrentProduct()
            {
                return null;
            }
        }
    }
}

namespace BSPModule\Core\WooCommerce\Display {
    if (! class_exists(ProductForm::class)) {
        final class ProductForm
        {
        }
    }
}

namespace BSPModule\Core\Product {
    if (! class_exists(ProductMeta::class)) {
        final class ProductMeta
        {
            public static function get_resource_ids(int $productId): array
            {
                unset($productId);
                return array();
            }
        }
    }
}

namespace BPM\Core {
    if (! class_exists(ProductSettings::class)) {
        final class ProductSettings
        {
        }
    }
}

namespace BSP\DayPlanner {
    if (! class_exists(Module::class)) {
        final class Module
        {
        }
    }
}

namespace SBDP\Pricing {
    if (! class_exists(SelectionPricing::class)) {
        final class SelectionPricing
        {
            public static function normaliseCombiItems($items): array
            {
                return is_array($items) ? $items : array();
            }
        }
    }
}

namespace BSP\Tests\BookingTruth {

use BSP\BookingBoard\Service\BoardService;
use BSP\Planner\Services\Planboard\PlanboardBookingService;
use BSPModule\Core\Rest\RestService;
use BSPModule\Core\Services\BookingTruthRuntimeService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use SBDP\ProductPageRefresh\Module;

require_once dirname(__DIR__, 2) . '/modules/core/Services/BookingTruthRuntimeService.php';
require_once dirname(__DIR__, 2) . '/modules/core/Rest/RestService.php';
require_once dirname(__DIR__, 2) . '/modules/product-page-refresh/Module.php';
require_once dirname(__DIR__, 2) . '/modules/planner/Services/Planboard/PlanboardBookingService.php';
require_once dirname(__DIR__, 2) . '/modules/booking-board/Service/BoardService.php';

final class BookingEntryPointConvergenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__booking_truth_meta'] = array();
        $GLOBALS['__booking_truth_titles'] = array();
        $GLOBALS['__test_wc_products'] = array();
        $GLOBALS['__test_wc_notices'] = array();
        remove_all_filters('sbdp_planservice_availability_slots_payload');
        remove_all_filters('sbdp_planservice_execution_check');
    }

    public function testComposeBookingPayRejectsMixedDirectAndRequestItems(): void
    {
        $this->registerValidSlotFilters();
        $GLOBALS['__booking_truth_meta'][11]['_wc_booking_requires_confirmation'] = 'yes';

        $method = new ReflectionMethod(RestService::class, 'canonicalize_compose_items');
        $method->setAccessible(true);

        $result = $method->invoke(
            null,
            array(
                array(
                    'product_id' => 10,
                    'resource_id' => 9,
                    'start' => '2026-05-10T10:00:00',
                    'end' => '2026-05-10T11:00:00',
                ),
                array(
                    'product_id' => 11,
                    'resource_id' => 9,
                    'start' => '2026-05-10T12:00:00',
                    'end' => '2026-05-10T13:00:00',
                ),
            ),
            4,
            'pay'
        );

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('sbdp_direct_checkout_blocked', $result->code);
    }

    public function testComposeBookingRequestModePreservesCanonicalMetadata(): void
    {
        $this->registerValidSlotFilters();
        $GLOBALS['__booking_truth_meta'][11]['_wc_booking_requires_confirmation'] = 'yes';

        $method = new ReflectionMethod(RestService::class, 'canonicalize_compose_items');
        $method->setAccessible(true);

        $result = $method->invoke(
            null,
            array(
                array(
                    'product_id' => 10,
                    'resource_id' => 9,
                    'start' => '2026-05-10T10:00:00',
                    'end' => '2026-05-10T11:00:00',
                ),
                array(
                    'product_id' => 11,
                    'resource_id' => 9,
                    'start' => '2026-05-10T12:00:00',
                    'end' => '2026-05-10T13:00:00',
                ),
            ),
            4,
            'request'
        );

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertSame('checkout', $result[0]['meta']['sbdp_route_intent']);
        $this->assertSame('DIRECT', $result[0]['meta']['sbdp_booking_capability']);
        $this->assertSame('quote', $result[1]['meta']['sbdp_route_intent']);
        $this->assertSame('REQUEST', $result[1]['meta']['sbdp_booking_capability']);
        $this->assertSame(4, $result[1]['meta']['sbdp_canonical_participants']);
    }

    public function testComposeBookingRejectsStaleUnavailablePayload(): void
    {
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

            return new \WP_Error('sbdp_capacity', 'full', array('status' => 400));
        });

        $method = new ReflectionMethod(RestService::class, 'canonicalize_compose_items');
        $method->setAccessible(true);

        $result = $method->invoke(
            null,
            array(
                array(
                    'product_id' => 10,
                    'resource_id' => 9,
                    'start' => '2026-05-10T10:00:00',
                    'end' => '2026-05-10T11:00:00',
                ),
            ),
            8,
            'pay'
        );

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('sbdp_booking_truth_unavailable', $result->code);
    }

    public function testProductPageDirectPathBlocksRequestOnlySelectionAndPersistsCanonicalMeta(): void
    {
        $this->registerValidSlotFilters();
        $GLOBALS['__booking_truth_meta'][12]['_wc_booking_requires_confirmation'] = 'yes';
        $GLOBALS['__test_wc_products'][12] = new \WC_Product(12, 'bookable_service');

        $module = new Module();
        $cartItemData = array(
            'data' => $GLOBALS['__test_wc_products'][12],
            'sbdp_summary' => array(
                'date' => '2026-05-10',
                'time' => '10:00',
                'participants' => 5,
                'resource_id' => 9,
                'start' => '2026-05-10T10:00:00',
                'end' => '11:00',
            ),
        );

        $allowed = $module->validateCanonicalBookingTruth(true, 12, 5, 0, array(), $cartItemData);
        $this->assertFalse($allowed);
        $this->assertNotEmpty($GLOBALS['__test_wc_notices']);

        $method = new ReflectionMethod(Module::class, 'finalizeCartPayload');
        $method->setAccessible(true);
        $finalized = $method->invoke($module, $cartItemData, 12);

        $this->assertSame('quote', $finalized['sbdp_route_intent']);
        $this->assertSame('REQUEST', $finalized['sbdp_booking_capability']);
        $this->assertSame(5, $finalized['sbdp_meta']['sbdp_canonical_participants']);
    }

    public function testPlanboardMoveUsesCanonicalTruthAndRejectsUnavailableSlot(): void
    {
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

            return new \WP_Error('sbdp_capacity', 'full', array('status' => 400));
        });

        $service = $this->newPlanboardServiceWithoutConstructor();
        $method = new ReflectionMethod(PlanboardBookingService::class, 'assertCanonicalBookingTruth');
        $method->setAccessible(true);

        $result = $method->invoke(
            $service,
            array(array('product_id' => 10, 'resource_id' => 9)),
            '2026-05-10T10:00:00',
            '2026-05-10T11:00:00',
            8,
            9,
            false
        );

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('sbdp_planboard_slot_unavailable', $result->code);
    }

    public function testBoardServiceHasNoPrivilegedOverrideForUnavailableTruth(): void
    {
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

            return new \WP_Error('sbdp_capacity', 'full', array('status' => 400));
        });

        $service = $this->newBoardServiceWithoutConstructor();
        $method = new ReflectionMethod(BoardService::class, 'assertCanonicalBookingTruth');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $method->invoke(
            $service,
            array(array('product_id' => 10, 'resource_id' => 9)),
            '2026-05-10',
            '10:00',
            '2026-05-10',
            '11:00',
            8,
            9
        );
    }

    private function registerValidSlotFilters(): void
    {
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

    private function newPlanboardServiceWithoutConstructor(): PlanboardBookingService
    {
        $reflection = new ReflectionClass(PlanboardBookingService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        $property = new ReflectionProperty(PlanboardBookingService::class, 'truthRuntime');
        $property->setAccessible(true);
        $property->setValue($service, new BookingTruthRuntimeService());

        return $service;
    }

    private function newBoardServiceWithoutConstructor(): BoardService
    {
        $reflection = new ReflectionClass(BoardService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        $property = new ReflectionProperty(BoardService::class, 'truthRuntime');
        $property->setAccessible(true);
        $property->setValue($service, new BookingTruthRuntimeService());

        return $service;
    }
}
}
