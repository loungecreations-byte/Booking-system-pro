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

            public static function get_resource_ids(int $productId): array
            {
                return self::$resourceIds[$productId] ?? array();
            }
        }
    }
}

namespace SBDP\Core {
    if (! class_exists(ProductSettings::class)) {
        final class ProductSettings
        {
            public static array $slots = array();

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

use BSP\DayPlanner\Service\PlanService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use SBDP\ProductPageRefresh\Module;

require_once dirname(__DIR__, 2) . '/modules/day-planner/Service/PlanService.php';
require_once dirname(__DIR__, 2) . '/modules/core/Services/BookingTruthRuntimeService.php';
require_once dirname(__DIR__, 2) . '/modules/core/Services/AvailabilityProjectionService.php';
require_once dirname(__DIR__, 2) . '/modules/product-page-refresh/Module.php';

final class BookingTruthRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__booking_truth_meta'] = array();
        $GLOBALS['__booking_truth_titles'] = array();
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

    private function newPlanServiceWithoutConstructor(): PlanService
    {
        $reflection = new ReflectionClass(PlanService::class);

        return $reflection->newInstanceWithoutConstructor();
    }
}
}
