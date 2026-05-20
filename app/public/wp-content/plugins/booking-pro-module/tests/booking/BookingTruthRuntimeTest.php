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

    if (! function_exists('wp_strip_all_tags')) {
        function wp_strip_all_tags(string $text): string
        {
            return strip_tags($text);
        }
    }

    if (! function_exists('home_url')) {
        function home_url(string $path = ''): string
        {
            return 'https://dagjedenbosch.test' . $path;
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

    if (! function_exists('get_page_by_path')) {
        function get_page_by_path(string $pagePath)
        {
            unset($pagePath);
            return null;
        }
    }

    if (! function_exists('wp_timezone')) {
        function wp_timezone(): \DateTimeZone
        {
            return new \DateTimeZone('UTC');
        }
    }

    if (! function_exists('is_wp_error')) {
        function is_wp_error($thing): bool
        {
            return $thing instanceof \WP_Error;
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

    if (! class_exists('WC_Product')) {
        class WC_Product
        {
            public function __construct(private int $id = 0)
            {
            }

            public function get_id(): int
            {
                return $this->id;
            }

            public function get_name(): string
            {
                return '';
            }

            public function get_price(): float
            {
                return 0.0;
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

namespace BSPModule\Core\Product {
    if (! class_exists(ProductMeta::class)) {
        final class ProductMeta
        {
            public static array $resourceIds = array();
            public static array $resourcesPayload = array();

            public static function get_resource_ids(int $productId): array
            {
                return self::$resourceIds[$productId] ?? array();
            }

            public static function get_resources_payload(int $productId): array
            {
                return self::$resourcesPayload[$productId] ?? array();
            }
        }
    }
}

namespace SBDP\Core {
    if (! class_exists(ProductSettings::class)) {
        final class ProductSettings
        {
            public static array $slots = array();
            public static array $settings = array();

            public static function get(int $productId): array
            {
                return self::$settings[$productId] ?? array();
            }

            public static function slotsForDate(int $productId, string $date): array
            {
                return self::$slots[$productId][$date] ?? array();
            }
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

namespace BSPModule\Core\Rest {
    if (! class_exists(RestService::class)) {
        final class RestService
        {
            public static $availabilitySlotsResponse = array();
            public static $scheduleOverview = array();

            public static function availability_slots($request)
            {
                unset($request);
                return self::$availabilitySlotsResponse;
            }

            public static function plan_availability($request)
            {
                unset($request);
                return self::$availabilitySlotsResponse;
            }

            public static function get_schedule_overview($request)
            {
                unset($request);
                return self::$scheduleOverview;
            }
        }
    }
}

namespace BSPModule\Core\Services {
    if (! class_exists(AvailabilityExecutionService::class)) {
        final class AvailabilityExecutionService
        {
            /** @var array<string, mixed> */
            public static array $results = array();

            public static function checkItemRules(int $productId, int $resourceId, string $start, string $end, int $participants)
            {
                unset($productId, $resourceId, $end, $participants);
                return self::$results[$start] ?? true;
            }
        }
    }
}

namespace BSP\Tests\BookingTruth {

use BSP\DayPlanner\Service\ActivityService;
use BSP\DayPlanner\Service\PlanService;
use BSPModule\Core\Services\BookingTruthRuntimeService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use SBDP\Modules\Planner\Rest\PlannerRoutes;
use SBDP\ProductPageRefresh\Module;

require_once dirname(__DIR__, 2) . '/modules/day-planner/Service/PlanService.php';
require_once dirname(__DIR__, 2) . '/modules/day-planner/Service/ActivityService.php';
require_once dirname(__DIR__, 2) . '/modules/core/Services/BookingModeService.php';
require_once dirname(__DIR__, 2) . '/modules/core/Services/BookingTruthRuntimeService.php';
require_once dirname(__DIR__, 2) . '/modules/core/Services/AvailabilityProjectionService.php';
require_once dirname(__DIR__, 2) . '/modules/product-page-refresh/Module.php';
require_once dirname(__DIR__, 2) . '/modules/planner/Rest/PlannerRoutes.php';

final class BookingTruthRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__booking_truth_meta'] = array();
        $GLOBALS['__booking_truth_titles'] = array();
        if (property_exists(\BSPModule\Core\Product\ProductMeta::class, 'resourcesPayload')) {
            \BSPModule\Core\Product\ProductMeta::$resourcesPayload = array();
        }
        if (property_exists(\SBDP\Core\ProductSettings::class, 'settings')) {
            \SBDP\Core\ProductSettings::$settings = array();
        }
        remove_all_filters('sbdp_schedule_resource_post_types');
        add_filter('sbdp_schedule_resource_post_types', static function ($value) {
            unset($value);
            return array();
        });
        remove_all_filters('sbdp_availability_projection_schedule_overview');
        remove_all_filters('sbdp_availability_projection_execution_check');
        remove_all_filters('sbdp_planservice_availability_slots_payload');
        remove_all_filters('sbdp_planservice_execution_check');
    }

    public function testCanonicalParticipantsPreferPlannerFormParticipants(): void
    {
        $service = $this->newPlanServiceWithoutConstructor();
        $method = new ReflectionMethod(PlanService::class, 'resolveCanonicalParticipantsContext');
        $method->setAccessible(true);

        $context = $method->invoke(
            $service,
            array(
                'meta' => array(
                    'form' => array('participants' => 8),
                    'participant_count' => 3,
                ),
                'participants' => array(array('name' => 'A')),
            ),
            array(),
            array()
        );

        $this->assertSame(8, $context['participants']);
        $this->assertSame('planner.form.participants', $context['source']);
        $this->assertNull($context['fallback_warning']);
    }

    public function testCanonicalParticipantsRemainUnresolvedWhenTruthIsMissing(): void
    {
        $service = $this->newPlanServiceWithoutConstructor();
        $method = new ReflectionMethod(PlanService::class, 'resolveCanonicalParticipantsContext');
        $method->setAccessible(true);

        $context = $method->invoke($service, array(), array(), array());

        $this->assertSame(0, $context['participants']);
        $this->assertSame('unresolved', $context['source']);
        $this->assertSame('missing_canonical_participants', $context['fallback_warning']);
    }

    public function testExtractCartItemsDoesNotLetSlotPeopleOverrideCanonicalParticipants(): void
    {
        $service = $this->newPlanServiceWithoutConstructor();
        $method = new ReflectionMethod(PlanService::class, 'extractCartItems');
        $method->setAccessible(true);

        $items = $method->invoke(
            $service,
            array(
                'meta' => array(
                    'form' => array('participants' => 8),
                    'planner_items' => array(
                        array(
                            'productId' => 10,
                            'dayIndex' => 0,
                            'startTime' => '10:00',
                            'endTime' => '11:00',
                            'resourceId' => 9,
                        ),
                    ),
                ),
                'days' => array(
                    array(
                        'date' => '2026-05-10',
                        'slots' => array(
                            array(
                                'product_id' => 10,
                                'start' => '10:00',
                                'end' => '11:00',
                                'people' => 2,
                                'resource_id' => 9,
                            ),
                        ),
                    ),
                ),
            )
        );

        $this->assertCount(1, $items);
        $this->assertSame(8, $items[0]['participants']);
    }

    public function testAvailabilitySlotsBecomeParticipantAware(): void
    {
        $GLOBALS['__booking_truth_meta'][10]['_sbdp_resource_ids'] = array(9);
        $GLOBALS['__booking_truth_meta'][10]['_sbdp_av_rules_res_9'] = array();
        $GLOBALS['__booking_truth_meta'][10]['_sbdp_capacity_res_9'] = 20;
        add_filter('sbdp_availability_projection_schedule_overview', static function ($value) {
            unset($value);

            return array(
                'timeline' => array(
                    array(
                        'resource' => array('id' => 9),
                        'available_slots' => array(
                            array('start' => '10:00', 'end' => '11:00'),
                            array('start' => '11:00', 'end' => '12:00'),
                        ),
                    ),
                ),
            );
        });
        add_filter('sbdp_availability_projection_execution_check', static function ($value, array $context) {
            unset($value);

            return $context['start'] === '2026-05-10T10:00:00'
                ? new \WP_Error('sbdp_capacity', 'full', array('status' => 400))
                : true;
        }, 10, 2);

        $request = new \WP_REST_Request('GET');
        $request->set_param('product_id', 10);
        $request->set_param('resource_id', 9);
        $request->set_param('date', '2026-05-10');
        $request->set_param('participants', 6);

        $payload = \BSPModule\Core\Services\AvailabilityProjectionService::availabilitySlots($request);

        $this->assertTrue($payload['resource_valid']);
        $this->assertSame(6, $payload['requested_participants']);
        $this->assertCount(1, $payload['slots']);
        $this->assertSame('11:00', $payload['slots'][0]['start']);
    }

    public function testCapabilityAndRouteIntentComeFromSameCanonicalDecision(): void
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
        add_filter('sbdp_planservice_execution_check', static function ($value, array $context) {
            unset($value);

            return $context['start'] === '2026-05-10T10:00:00'
                ? new \WP_Error('sbdp_capacity', 'full', array('status' => 400))
                : true;
        }, 10, 2);

        $service = $this->newPlanServiceWithoutConstructor();
        $method = new ReflectionMethod(PlanService::class, 'resolveItemBookingCapabilityProfile');
        $method->setAccessible(true);

        $profile = $method->invoke(
            $service,
            array(
                'product_id' => 10,
                'resource_id' => 9,
                'participants' => 12,
                'date' => '2026-05-10',
                'start' => '2026-05-10T10:00:00',
                'end' => '2026-05-10T11:00:00',
            )
        );

        $this->assertSame('UNAVAILABLE', $profile['status']);
        $this->assertSame('blocked', $profile['route_intent']);
        $this->assertSame('capacity_exceeded', $profile['reason_code']);
    }

    public function testSelectedTimeInvalidRoutesToQuoteNotBlocked(): void
    {
        add_filter('sbdp_planservice_availability_slots_payload', static function ($value) {
            unset($value);

            return array(
                'resource_valid' => true,
                'slots' => array(
                    array('start' => '08:00', 'end' => '08:30'),
                    array('start' => '08:30', 'end' => '09:00'),
                    array('start' => '09:00', 'end' => '09:30'),
                    array('start' => '09:30', 'end' => '10:00'),
                ),
                'capacity' => 20,
            );
        });
        add_filter('sbdp_planservice_execution_check', static function ($value) {
            unset($value);

            return true;
        });

        $service = $this->newPlanServiceWithoutConstructor();
        $method = new ReflectionMethod(PlanService::class, 'resolveItemBookingCapabilityProfile');
        $method->setAccessible(true);

        $profile = $method->invoke(
            $service,
            array(
                'product_id' => 10,
                'resource_id' => 9,
                'participants' => 12,
                'date' => '2026-05-10',
                'start' => '2026-05-10T16:00:00',
                'end' => '2026-05-10T17:00:00',
            )
        );

        $this->assertSame('REQUEST', $profile['status']);
        $this->assertSame('quote', $profile['route_intent']);
        $this->assertSame('selected_time_invalid', $profile['reason_code']);
    }

    public function testMissingParticipantsTruthBlocksCapabilityResolution(): void
    {
        $service = $this->newPlanServiceWithoutConstructor();
        $method = new ReflectionMethod(PlanService::class, 'resolveItemBookingCapabilityProfile');
        $method->setAccessible(true);

        $profile = $method->invoke(
            $service,
            array(
                'product_id' => 10,
                'resource_id' => 9,
                'participants' => 0,
                'date' => '2026-05-10',
                'start' => '2026-05-10T10:00:00',
                'end' => '2026-05-10T11:00:00',
            )
        );

        $this->assertSame('UNAVAILABLE', $profile['status']);
        $this->assertSame('blocked', $profile['route_intent']);
        $this->assertSame('missing_canonical_participants', $profile['reason_code']);
    }

    public function testQueueBookingGuardRejectsInvalidDirectCheckoutAttempt(): void
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
        add_filter('sbdp_planservice_execution_check', static function ($value, array $context) {
            unset($value);

            return $context['start'] === '2026-05-10T10:00:00'
                ? new \WP_Error('sbdp_capacity', 'full', array('status' => 400))
                : true;
        }, 10, 2);

        $service = $this->newPlanServiceWithoutConstructor();
        $method = new ReflectionMethod(PlanService::class, 'assertDirectCheckoutEligible');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $method->invoke(
            $service,
            array(
                array(
                    'product_id' => 10,
                    'resource_id' => 9,
                    'participants' => 12,
                    'date' => '2026-05-10',
                    'start' => '2026-05-10T10:00:00',
                    'end' => '2026-05-10T11:00:00',
                ),
            )
        );
    }

    public function testRequestOnlyEntityNeverEmitsDirectBookCta(): void
    {
        $service = $this->newPlanServiceWithoutConstructor();
        $method = new ReflectionMethod(PlanService::class, 'buildEntityCtas');
        $method->setAccessible(true);

        $ctas = $method->invoke(
            $service,
            array(
                'route_intent' => 'quote',
                'quote_url' => 'https://dagjedenbosch.test/offerte/custom',
                'url' => 'https://dagjedenbosch.test/activiteiten/custom',
            ),
            'A',
            true
        );

        $this->assertNotEmpty($ctas);
        $this->assertContains('quote', array_column($ctas, 'kind'));
        $this->assertNotContains('book', array_column($ctas, 'kind'));
    }

    public function testBlockedEntityNeverEmitsDirectBookCta(): void
    {
        $service = $this->newPlanServiceWithoutConstructor();
        $method = new ReflectionMethod(PlanService::class, 'buildEntityCtas');
        $method->setAccessible(true);

        $ctas = $method->invoke(
            $service,
            array(
                'route_intent' => 'blocked',
                'url' => 'https://dagjedenbosch.test/activiteiten/custom',
            ),
            'B',
            true
        );

        $this->assertNotEmpty($ctas);
        $this->assertNotContains('book', array_column($ctas, 'kind'));
        $this->assertNotContains('quote', array_column($ctas, 'kind'));
    }

    public function testOrderMetaPreservesCanonicalParticipantsAndCapabilityMetadata(): void
    {
        $GLOBALS['__booking_truth_titles'][9] = 'Boot 9';

        $module = new Module();
        $item = new class {
            /** @var array<string, mixed> */
            public array $meta = array();

            public function get_product_id(): int
            {
                return 10;
            }

            public function add_meta_data(string $key, $value, bool $unique = false): void
            {
                unset($unique);
                $this->meta[$key] = $value;
            }
        };

        $module->persistOrderItemMeta(
            $item,
            'abc',
            array(
                'sbdp_summary' => array(
                    'date' => '2026-05-10',
                    'time' => '10:00',
                    'participants' => 6,
                    'resource_id' => 9,
                    'start' => '2026-05-10T10:00:00',
                    'end' => '11:00',
                ),
                'sbdp_meta' => array(
                    'sbdp_canonical_participants' => 6,
                    'sbdp_participants' => 6,
                    'sbdp_route_intent' => 'checkout',
                    'sbdp_booking_capability' => 'DIRECT',
                ),
            ),
            null
        );

        $this->assertSame(6, $item->meta['sbdp_participants']);
        $this->assertSame(6, $item->meta['sbdp_canonical_participants']);
        $this->assertSame('checkout', $item->meta['sbdp_route_intent']);
        $this->assertSame('DIRECT', $item->meta['sbdp_booking_capability']);
    }

    public function testProduct115BookingModeDowngradesRuntimeToRequest(): void
    {
        $this->configureValidAvailability();

        $profile = (new BookingTruthRuntimeService())->resolveBookingCapabilityProfile(array(
            'product_id' => 115,
            'resource_id' => 9,
            'participants' => 10,
            'date' => '2026-05-10',
            'start' => '2026-05-10T10:00:00',
            'end' => '2026-05-10T11:00:00',
        ));

        $this->assertSame('REQUEST', $profile['status']);
        $this->assertSame('quote', $profile['route_intent']);
        $this->assertSame('supplier_confirmation', $profile['bookingMode']);
        $this->assertFalse($profile['directBookable']);
        $this->assertTrue($profile['supplierConfirmationRequired']);
    }

    public function testQuoteBookingModeDowngradesDirectRuntimeToRequest(): void
    {
        $this->configureValidAvailability();
        $GLOBALS['__booking_truth_meta'][301] = array(
            '_ddb_booking_mode' => 'quote',
            '_ddb_direct_booking_enabled' => 'yes',
        );

        $profile = (new BookingTruthRuntimeService())->resolveBookingCapabilityProfile(array(
            'product_id' => 301,
            'resource_id' => 9,
            'participants' => 4,
            'date' => '2026-05-10',
            'start' => '2026-05-10T10:00:00',
            'end' => '2026-05-10T11:00:00',
        ));

        $this->assertSame('REQUEST', $profile['status']);
        $this->assertSame('quote', $profile['route_intent']);
        $this->assertFalse($profile['directBookable']);
    }

    public function testSupplierConfirmationBookingModeDowngradesDirectRuntimeToRequest(): void
    {
        $this->configureValidAvailability();
        $GLOBALS['__booking_truth_meta'][302] = array(
            '_ddb_booking_mode' => 'supplier_confirmation',
            '_ddb_direct_booking_enabled' => 'yes',
        );

        $profile = (new BookingTruthRuntimeService())->resolveBookingCapabilityProfile(array(
            'product_id' => 302,
            'resource_id' => 9,
            'participants' => 4,
            'date' => '2026-05-10',
            'start' => '2026-05-10T10:00:00',
            'end' => '2026-05-10T11:00:00',
        ));

        $this->assertSame('REQUEST', $profile['status']);
        $this->assertSame('quote', $profile['route_intent']);
        $this->assertTrue($profile['supplierConfirmationRequired']);
    }

    public function testBlockedBookingModeDowngradesRuntimeToUnavailable(): void
    {
        $this->configureValidAvailability();
        $GLOBALS['__booking_truth_meta'][303] = array(
            '_ddb_booking_mode' => 'blocked',
            '_ddb_direct_booking_enabled' => 'yes',
            '_ddb_quote_os_enabled' => 'yes',
        );

        $profile = (new BookingTruthRuntimeService())->resolveBookingCapabilityProfile(array(
            'product_id' => 303,
            'resource_id' => 9,
            'participants' => 4,
            'date' => '2026-05-10',
            'start' => '2026-05-10T10:00:00',
            'end' => '2026-05-10T11:00:00',
        ));

        $this->assertSame('UNAVAILABLE', $profile['status']);
        $this->assertSame('blocked', $profile['route_intent']);
        $this->assertFalse($profile['directBookable']);
    }

    public function testDirectBookingModeKeepsDirectOnlyWhenEnabled(): void
    {
        $this->configureValidAvailability();
        $GLOBALS['__booking_truth_meta'][304] = array(
            '_ddb_booking_mode' => 'direct',
            '_ddb_direct_booking_enabled' => 'yes',
        );

        $profile = (new BookingTruthRuntimeService())->resolveBookingCapabilityProfile(array(
            'product_id' => 304,
            'resource_id' => 9,
            'participants' => 4,
            'date' => '2026-05-10',
            'start' => '2026-05-10T10:00:00',
            'end' => '2026-05-10T11:00:00',
        ));

        $this->assertSame('DIRECT', $profile['status']);
        $this->assertSame('checkout', $profile['route_intent']);
        $this->assertTrue($profile['directBookable']);
    }

    public function testProductPageSummaryPublishesProduct115SupplierConfirmationMetadata(): void
    {
        $GLOBALS['__booking_truth_meta'][115] = array(
            '_ddb_supplier_provider' => 'eliio',
            '_ddb_supplier_availability_mode' => 'widget',
            '_sbdp_booking_min_duration' => 120,
            '_sbdp_booking_duration_type' => 'minutes',
            '_sbdp_capacity' => 20,
            '_sbdp_time_slots' => array(array('start' => '10:00', 'end' => '12:00')),
        );

        $module = new Module();
        $method = new ReflectionMethod(Module::class, 'buildCardConfig');
        $method->setAccessible(true);

        $config = $method->invoke($module, new \WC_Product(115));

        $this->assertSame('supplier_confirmation', $config['supplier']['bookingMode']);
        $this->assertSame('quote', $config['supplier']['routeIntent']);
        $this->assertFalse($config['supplier']['directBookable']);
        $this->assertTrue($config['supplier']['requestOnly']);
        $this->assertTrue($config['supplier']['supplierConfirmationRequired']);
    }

    public function testRequestOnlyDiscoveryItemCanBeAddedToPlannerButNotCart(): void
    {
        $item = $this->applyDiscoveryEnvelope(array(
            'id' => 115,
            'product_id' => 115,
            'name' => 'E-Chopper tour',
            'duration' => array('minutes' => 120),
            'duration_minutes' => 120,
            'booking_capability' => 'request',
        ));

        $this->assertSame('quote', $item['route_intent']);
        $this->assertTrue($item['requestOnly']);
        $this->assertFalse($item['is_bookable']);
        $this->assertFalse($item['can_add_to_cart']);
        $this->assertTrue($item['can_add_to_planner']);
    }

    public function testCheckoutDiscoveryItemCanBeAddedToPlannerAndCart(): void
    {
        $item = $this->applyDiscoveryEnvelope(array(
            'id' => 352,
            'product_id' => 352,
            'name' => 'Direct bookable product',
            'duration' => array('minutes' => 60),
            'duration_minutes' => 60,
            'booking_capability' => 'direct',
        ));

        $this->assertSame('checkout', $item['route_intent']);
        $this->assertFalse($item['requestOnly']);
        $this->assertTrue($item['is_bookable']);
        $this->assertTrue($item['can_add_to_cart']);
        $this->assertTrue($item['can_add_to_planner']);
    }

    public function testBlockedDiscoveryItemCanNotBeAddedToPlannerOrCart(): void
    {
        $item = $this->applyDiscoveryEnvelope(array(
            'id' => 999,
            'product_id' => 999,
            'name' => 'Blocked product',
            'duration' => array('minutes' => 60),
            'duration_minutes' => 60,
            'booking_capability' => 'blocked',
        ));

        $this->assertSame('blocked', $item['route_intent']);
        $this->assertFalse($item['requestOnly']);
        $this->assertFalse($item['is_bookable']);
        $this->assertFalse($item['can_add_to_cart']);
        $this->assertFalse($item['can_add_to_planner']);
    }

    public function testPlannerRoutesPublishesProduct115AsRequestNotDirectLimited(): void
    {
        $GLOBALS['__booking_truth_meta'][115] = array(
            '_ddb_supplier_provider' => 'eliio',
        );

        $reflection = new ReflectionClass(PlannerRoutes::class);
        $routes = $reflection->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(PlannerRoutes::class, 'resolveBookingCapability');
        $method->setAccessible(true);

        $capability = $method->invoke($routes, 115, array());

        $this->assertSame('REQUEST', $capability);
        $this->assertNotSame('DIRECT_LIMITED', $capability);
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private function applyDiscoveryEnvelope(array $item): array
    {
        $service = new ActivityService();
        $method = new ReflectionMethod(ActivityService::class, 'applyDiscoveryEnvelope');
        $method->setAccessible(true);

        return $method->invoke($service, $item, array(), 'EUR');
    }

    private function configureValidAvailability(): void
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

            return true;
        });
    }

    private function newPlanServiceWithoutConstructor(): PlanService
    {
        $reflection = new ReflectionClass(PlanService::class);

        return $reflection->newInstanceWithoutConstructor();
    }
}
}
