<?php

declare(strict_types=1);

namespace {
    if (! class_exists('WP_REST_Request')) {
        class WP_REST_Request
        {
            private array $params = array();

            public function __construct(string $method = 'GET', string $route = '/')
            {
                unset($method, $route);
            }

            public function get_params(): array
            {
                return $this->params;
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

    if (! class_exists('WP_Query')) {
        class WP_Query
        {
            private int $index = 0;

            public function __construct(array $args = array())
            {
                unset($args);
                $this->index = 0;
            }

            public function have_posts(): bool
            {
                return $this->index < count($GLOBALS['__public_read_query_posts'] ?? array());
            }

            public function the_post(): void
            {
                $posts = $GLOBALS['__public_read_query_posts'] ?? array();
                $GLOBALS['__public_read_current_post'] = $posts[$this->index] ?? null;
                $this->index++;
            }
        }
    }

    if (! function_exists('get_the_excerpt')) {
        function get_the_excerpt(int $postId): string
        {
            return $GLOBALS['__public_read_excerpts'][$postId] ?? '';
        }
    }

    if (! function_exists('get_the_post_thumbnail_url')) {
        function get_the_post_thumbnail_url(int $postId, string $size = 'medium'): string
        {
            unset($size);
            return $GLOBALS['__public_read_images'][$postId] ?? '';
        }
    }

    if (! function_exists('wp_get_post_terms')) {
        function wp_get_post_terms(int $postId, string $taxonomy): array
        {
            unset($taxonomy);
            return $GLOBALS['__public_read_terms'][$postId] ?? array();
        }
    }

    if (! function_exists('get_post_meta')) {
        function get_post_meta(int $postId, string $key, bool $single = true)
        {
            unset($single);
            return $GLOBALS['__public_read_meta'][$postId][$key] ?? null;
        }
    }
}

namespace SBDP\Modules\Planner\Rest {
    function sanitize_text_field(string $value): string
    {
        return trim(strip_tags($value));
    }

    function sanitize_title(string $value): string
    {
        return strtolower(trim($value));
    }

    function sanitize_key(string $value): string
    {
        return strtolower(trim($value));
    }

    function esc_url_raw(string $value): string
    {
        return trim($value);
    }

    function wp_strip_all_tags(string $value): string
    {
        return strip_tags($value);
    }

    function get_the_excerpt(int $postId): string
    {
        return $GLOBALS['__public_read_excerpts'][$postId] ?? '';
    }

    function get_the_post_thumbnail_url(int $postId, string $size = 'medium'): string
    {
        unset($size);
        return $GLOBALS['__public_read_images'][$postId] ?? '';
    }

    function wp_get_post_terms(int $postId, string $taxonomy): array
    {
        unset($taxonomy);
        return $GLOBALS['__public_read_terms'][$postId] ?? array();
    }

    function is_wp_error($value): bool
    {
        return $value instanceof \WP_Error;
    }

    function get_post_meta(int $postId, string $key, bool $single = true)
    {
        unset($single);
        return $GLOBALS['__public_read_meta'][$postId][$key] ?? null;
    }
}

namespace BSPModule\Core\Rest {
    function sanitize_text_field(string $value): string
    {
        return trim(strip_tags($value));
    }

    function sanitize_title(string $value): string
    {
        return strtolower(trim($value));
    }

    function esc_url_raw(string $value): string
    {
        return trim($value);
    }

    function get_post_meta(int $postId, string $key, bool $single = true)
    {
        unset($single);
        return $GLOBALS['__public_read_meta'][$postId][$key] ?? null;
    }

    function wp_get_post_terms(int $postId, string $taxonomy): array
    {
        unset($taxonomy);
        return $GLOBALS['__public_read_terms'][$postId] ?? array();
    }

    function is_wp_error($value): bool
    {
        return $value instanceof \WP_Error;
    }

    function determine_locale(): string
    {
        return 'nl_NL';
    }

    function get_locale(): string
    {
        return 'nl_NL';
    }

    function wp_cache_get(string $key, string $group = '')
    {
        unset($key, $group);
        return false;
    }

    function get_transient(string $key)
    {
        unset($key);
        return false;
    }

    function wp_cache_set(string $key, $value, string $group = '', int $ttl = 0): bool
    {
        unset($key, $value, $group, $ttl);
        return true;
    }

    function set_transient(string $key, $value, int $ttl): bool
    {
        unset($key, $value, $ttl);
        return true;
    }

    function wc_get_product(int $productId)
    {
        unset($productId);
        return null;
    }

    function get_the_ID(): int
    {
        return (int) ($GLOBALS['__public_read_current_post']['id'] ?? 0);
    }

    function get_the_title(): string
    {
        return (string) ($GLOBALS['__public_read_current_post']['title'] ?? '');
    }

    function get_the_excerpt(int $postId): string
    {
        return $GLOBALS['__public_read_excerpts'][$postId] ?? '';
    }

    function get_the_post_thumbnail_url(int $postId, string $size = 'thumbnail'): string
    {
        unset($size);
        return $GLOBALS['__public_read_images'][$postId] ?? '';
    }

    function get_permalink(int $postId): string
    {
        return 'https://example.test/product/' . $postId;
    }

    function wp_strip_all_tags(string $value): string
    {
        return strip_tags($value);
    }

    function wp_reset_postdata(): void
    {
        $GLOBALS['__public_read_current_post'] = null;
    }
}

namespace BSP\Tests\Quotes {

use BSPModule\Core\Rest\RestService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SBDP\Modules\Planner\Rest\PlannerRoutes;
use SBDP\Modules\Planner\Services\PlannerService;
use WP_REST_Request;

require_once dirname(__DIR__, 2) . '/modules/planner/Services/PlannerService.php';
require_once dirname(__DIR__, 2) . '/modules/planner/Rest/PlannerRoutes.php';
require_once dirname(__DIR__, 2) . '/modules/core/Rest/RestService.php';

final class PublicReadDtoHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['__public_read_meta'] = array();
        $GLOBALS['__public_read_excerpts'] = array();
        $GLOBALS['__public_read_images'] = array();
        $GLOBALS['__public_read_terms'] = array();
        $GLOBALS['__public_read_query_posts'] = array();
        $GLOBALS['__public_read_current_post'] = null;
    }

    public function testPlannerProductsRouteReturnsAllowlistedPublicDto(): void
    {
        $GLOBALS['__public_read_meta'][101] = array(
            '_wc_booking_requires_confirmation' => 'yes',
        );
        $GLOBALS['__public_read_excerpts'][101] = ' Publieke samenvatting ';
        $GLOBALS['__public_read_images'][101] = 'https://example.test/image-101.jpg';
        $GLOBALS['__public_read_terms'][101] = array(
            (object) array('slug' => 'food', 'name' => 'Food'),
        );

        $service = new class extends PlannerService {
            public function listProducts(array $filters = array()): array
            {
                unset($filters);

                return array(
                    array(
                        'id' => 101,
                        'name' => 'Rondvaart Deluxe',
                        'slug' => 'rondvaart-deluxe',
                        'permalink' => 'https://example.test/rondvaart-deluxe',
                        'duration' => array(
                            'value' => 90,
                            'unit' => 'minutes',
                            'minutes' => 90,
                        ),
                        'pricing' => array(
                            'base' => 49.95,
                            'currency' => 'EUR',
                            'analysis' => array(
                                'margins' => array('cost' => 10, 'margin' => 39.95),
                            ),
                        ),
                        'resource_id' => 88,
                        'resources' => array(
                            'ids' => array(88),
                            'items' => array(array('id' => 88, 'label' => 'Boat A')),
                        ),
                        'capacity' => array('max' => 12),
                        'calendar_blocks' => array(array('start' => '09:00')),
                        'calendar_status' => 'synced',
                        'insights' => array(
                            'revenue' => array('dynamic' => 59.95),
                        ),
                        'outlets' => array(
                            array('name' => 'Binnenstad'),
                        ),
                    ),
                );
            }
        };

        $route = new PlannerRoutes($service);
        $response = $route->get_products(new WP_REST_Request('GET', '/booking/v1/planner/products'));
        $payload = $response->get_data();
        $product = $payload['products'][0];

        $this->assertSame(101, $product['id']);
        $this->assertSame('Rondvaart Deluxe', $product['title']);
        $this->assertSame(49.95, $product['display_price']);
        $this->assertSame('REQUEST', $product['booking_capability']);
        $this->assertSame('Op aanvraag', $product['availability_label']);
        $this->assertSame('Binnenstad', $product['location_label']);
        $this->assertSame('Publieke samenvatting', $product['excerpt']);
        $this->assertSame('https://example.test/image-101.jpg', $product['image']);
        $this->assertArrayHasKey('categories_public', $product);
        $this->assertArrayNotHasKey('resource_id', $product);
        $this->assertArrayNotHasKey('resources', $product);
        $this->assertArrayNotHasKey('capacity', $product);
        $this->assertArrayNotHasKey('calendar_blocks', $product);
        $this->assertArrayNotHasKey('calendar_status', $product);
        $this->assertArrayNotHasKey('pricing', $product);
        $this->assertArrayNotHasKey('insights', $product);
    }

    public function testServicesResponseReturnsAllowlistedPublicDto(): void
    {
        $GLOBALS['__public_read_meta'][202] = array(
            '_wc_booking_requires_confirmation' => 'no',
        );
        $GLOBALS['__public_read_excerpts'][202] = ' Service teaser ';
        $GLOBALS['__public_read_images'][202] = 'https://example.test/service-202.jpg';
        $GLOBALS['__public_read_terms'][202] = array(
            (object) array('slug' => 'tour', 'name' => 'Tour'),
        );
        $GLOBALS['__public_read_query_posts'] = array(
            array(
                'id' => 202,
                'title' => 'Stadswandeling',
            ),
        );

        $request = new WP_REST_Request('GET', '/sbdp/v1/services');
        $response = RestService::get_services($request);
        $service = $response[0];

        $this->assertSame(202, $service['id']);
        $this->assertSame('Stadswandeling', $service['title']);
        $this->assertArrayHasKey('display_price', $service);
        $this->assertSame('DIRECT_LIMITED', $service['booking_capability']);
        $this->assertSame('Beschikbaarheid wordt bevestigd bij selectie', $service['availability_label']);
        $this->assertArrayHasKey('category_public', $service);
        $this->assertArrayNotHasKey('resource_id', $service);
        $this->assertArrayNotHasKey('resources', $service);
        $this->assertArrayNotHasKey('vendor_id', $service);
        $this->assertArrayNotHasKey('capacity', $service);
        $this->assertArrayNotHasKey('pricing', $service);
    }
}
}
