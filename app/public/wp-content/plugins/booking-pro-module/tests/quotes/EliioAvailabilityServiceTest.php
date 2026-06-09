<?php

declare(strict_types=1);

use BSP\Integrations\Eliio\EliioAvailabilityClient;
use BSP\Integrations\Eliio\EliioAvailabilityService;
use BSP\Integrations\Rest\EliioAvailabilityController;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/modules/integrations/Eliio/EliioAvailabilityClient.php';
require_once dirname(__DIR__, 2) . '/modules/integrations/Eliio/EliioAvailabilityService.php';
require_once dirname(__DIR__, 2) . '/modules/integrations/Rest/EliioAvailabilityController.php';

if (! function_exists('get_post')) {
    function get_post($postId)
    {
        return $GLOBALS['__eliio_test_posts'][(int) $postId] ?? null;
    }
}

if (! function_exists('get_post_meta')) {
    function get_post_meta(int $postId, string $key, bool $single = true)
    {
        unset($single);
        return $GLOBALS['__eliio_test_meta'][$postId][$key] ?? '';
    }
}

if (! function_exists('get_transient')) {
    function get_transient(string $key)
    {
        return $GLOBALS['__eliio_test_transients'][$key] ?? false;
    }
}

if (! function_exists('set_transient')) {
    function set_transient(string $key, $value, int $ttl): bool
    {
        $GLOBALS['__eliio_test_transients'][$key] = $value;
        $GLOBALS['__eliio_test_transient_ttl'][$key] = $ttl;
        return true;
    }
}

if (! function_exists('current_time')) {
    function current_time($type = 'mysql', $gmt = false): string
    {
        unset($type, $gmt);
        return '2026-05-19 10:00:00';
    }
}

if (! function_exists('wp_timezone')) {
    function wp_timezone(): DateTimeZone
    {
        return new DateTimeZone('Europe/Amsterdam');
    }
}

final class EliioAvailabilityServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['__eliio_test_posts'] = array(
            115 => (object) array('ID' => 115, 'post_type' => 'product'),
        );
        $GLOBALS['__eliio_test_meta'] = array();
        $GLOBALS['__booking_truth_meta'] = array();
        $GLOBALS['__test_post_meta'] = array();
        $GLOBALS['__ddb_supplier_confirmation_meta'] = array();
        $GLOBALS['__ddb_supplier_request_draft_meta'] = array();
        $GLOBALS['__ddb_booking_mode_meta'] = array();
        $GLOBALS['__public_read_meta'] = array();
        $GLOBALS['__eliio_test_transients'] = array();
        $GLOBALS['__eliio_test_transient_ttl'] = array();
    }

    public function testMissingMappingReturnsUnknownWithoutCallingClient(): void
    {
        $client = new EliioMockClient(array());
        $service = new EliioAvailabilityService($client);

        $response = $service->check(115, '2026-05-23', 10);

        self::assertIsArray($response);
        self::assertSame('unknown', $response['status']);
        self::assertFalse($response['directBookable']);
        self::assertTrue($response['supplierConfirmationRequired']);
        self::assertSame(0, $client->calls);
    }

    public function testInvalidDateReturnsRestError(): void
    {
        $controller = new EliioAvailabilityController(new EliioAvailabilityService(new EliioMockClient(array())));
        $response = $controller->handleAvailabilityRequest($this->request(array(
            'product_id' => 115,
            'date' => 'bad-date',
            'participants' => 10,
        )));

        self::assertInstanceOf(WP_Error::class, $response);
        self::assertSame(400, $response->data['status'] ?? null);
    }

    public function testPastDateReturnsRestError(): void
    {
        $controller = new EliioAvailabilityController(new EliioAvailabilityService(new EliioMockClient(array())));
        $response = $controller->handleAvailabilityRequest($this->request(array(
            'product_id' => 115,
            'date' => '2026-04-12',
            'participants' => 10,
        )));

        self::assertInstanceOf(WP_Error::class, $response);
        self::assertSame(400, $response->data['status'] ?? null);
    }

    /**
     * @dataProvider invalidParticipantsProvider
     * @param mixed $participants
     */
    public function testInvalidParticipantsReturnRestError($participants): void
    {
        $controller = new EliioAvailabilityController(new EliioAvailabilityService(new EliioMockClient(array())));
        $response = $controller->handleAvailabilityRequest($this->request(array(
            'product_id' => 115,
            'date' => '2026-05-23',
            'participants' => $participants,
        )));

        self::assertInstanceOf(WP_Error::class, $response);
        self::assertSame(400, $response->data['status'] ?? null);
    }

    public function invalidParticipantsProvider(): array
    {
        return array(
            'zero' => array(0),
            'negative' => array(-1),
            'text' => array('abc'),
        );
    }

    public function testAvailableResponseKeepsRequestOnlyFlags(): void
    {
        $this->setEliioMapping();
        $service = new EliioAvailabilityService(new EliioMockClient(array(
            'data' => array(
                array('startTime' => '10:00', 'endTime' => '12:00', 'available' => true),
            ),
        )));

        $response = $service->check(115, '2026-05-23', 10, '10:00');

        self::assertIsArray($response);
        self::assertSame('available', $response['status']);
        self::assertFalse($response['directBookable']);
        self::assertTrue($response['supplierConfirmationRequired']);
        self::assertSame(10, $response['participants']);
    }

    public function testUnavailableResponseWhenRelevantSlotIsFalse(): void
    {
        $this->setEliioMapping();
        $service = new EliioAvailabilityService(new EliioMockClient(array(
            'data' => array(
                array('startTime' => '10:00', 'endTime' => '12:00', 'available' => false),
            ),
        )));

        $response = $service->check(115, '2026-05-23', 10, '10:00');

        self::assertIsArray($response);
        self::assertSame('unavailable', $response['status']);
    }

    public function testEmptySlotsAreUnavailable(): void
    {
        $this->setEliioMapping();
        $service = new EliioAvailabilityService(new EliioMockClient(array('data' => array())));

        $response = $service->check(115, '2026-05-23', 10);

        self::assertIsArray($response);
        self::assertSame('unavailable', $response['status']);
    }

    public function testApiErrorReturnsErrorAndRequestOnlyFlags(): void
    {
        $this->setEliioMapping();
        $service = new EliioAvailabilityService(new EliioMockClient(new WP_Error('timeout', 'timeout')));

        $response = $service->check(115, '2026-05-23', 10);

        self::assertIsArray($response);
        self::assertSame('error', $response['status']);
        self::assertFalse($response['directBookable']);
        self::assertTrue($response['supplierConfirmationRequired']);
    }

    public function testStartTimeFilterOnlyEvaluatesSelectedSlot(): void
    {
        $this->setEliioMapping();
        $service = new EliioAvailabilityService(new EliioMockClient(array(
            'data' => array(
                array('startTime' => '09:00', 'endTime' => '11:00', 'available' => true),
                array('startTime' => '10:00', 'endTime' => '12:00', 'available' => false),
            ),
        )));

        $response = $service->check(115, '2026-05-23', 10, '10:00');

        self::assertIsArray($response);
        self::assertSame('unavailable', $response['status']);
        self::assertCount(1, $response['slots']);
        self::assertSame('10:00', $response['slots'][0]['startTime']);
    }

    public function testClientReceivesExactParticipantsAndCacheIsUsed(): void
    {
        $this->setEliioMapping();
        $client = new EliioMockClient(array(
            'data' => array(
                array('startTime' => '10:00', 'endTime' => '12:00', 'available' => true),
            ),
        ));
        $service = new EliioAvailabilityService($client);

        $first = $service->check(115, '2026-05-23', 10, '10:00');
        $second = $service->check(115, '2026-05-23', 10, '10:00');

        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertSame(1, $client->calls);
        self::assertSame(10, $client->lastQuery['participants'] ?? null);
        self::assertSame(60, array_values($GLOBALS['__eliio_test_transient_ttl'])[0] ?? null);
    }

    public function testNoBookingWidgetLiteralInIntegrationSources(): void
    {
        $base = dirname(__DIR__, 2) . '/modules/integrations';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));
        $forbiddenEndpoint = 'booking' . '-widget';

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            self::assertStringNotContainsString($forbiddenEndpoint, (string) file_get_contents($file->getPathname()));
        }
    }

    private function setEliioMapping(): void
    {
        $meta = array(
            '_ddb_supplier_provider' => 'eliio',
            '_ddb_eliio_company_id' => 'company-1',
            '_ddb_eliio_product_id' => 'product-1',
            '_ddb_eliio_branch_id' => 'branch-1',
            '_ddb_eliio_resource_id' => 'resource-1',
            '_ddb_eliio_duration_id' => '',
            '_ddb_supplier_direct_booking' => 'no',
            '_ddb_supplier_confirmation_required' => 'yes',
            '_ddb_supplier_availability_mode' => 'widget',
        );

        foreach (
            array(
                '__eliio_test_meta',
                '__booking_truth_meta',
                '__test_post_meta',
                '__ddb_supplier_confirmation_meta',
                '__ddb_supplier_request_draft_meta',
                '__ddb_booking_mode_meta',
                '__public_read_meta',
            ) as $globalKey
        ) {
            $GLOBALS[$globalKey][115] = $meta;
        }
    }

    private function request(array $params): WP_REST_Request
    {
        $request = new WP_REST_Request('GET', '/ddb/v1/supplier/eliio/availability');
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return $request;
    }
}

final class EliioMockClient extends EliioAvailabilityClient
{
    /** @var array<string, mixed>|WP_Error */
    private $payload;

    public int $calls = 0;

    /** @var array<string, mixed> */
    public array $lastQuery = array();

    /**
     * @param array<string, mixed>|WP_Error $payload
     */
    public function __construct($payload)
    {
        $this->payload = $payload;
    }

    public function fetchAvailability(array $query)
    {
        $this->calls++;
        $this->lastQuery = $query;

        return $this->payload;
    }
}
