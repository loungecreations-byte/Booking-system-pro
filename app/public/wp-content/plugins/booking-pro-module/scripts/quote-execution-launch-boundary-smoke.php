<?php

if (! function_exists('add_action')) {
    $wpLoad = dirname(__DIR__, 4) . '/wp-load.php';
    if (is_readable($wpLoad)) {
        require_once $wpLoad;
    }
}

use BSP\Quotes\Repository\QuoteRepository;
use BSP\Quotes\Service\QuoteEventLogger;
use BSP\Quotes\Service\QuoteExecutionLaunchService;
use BSP\Quotes\Service\QuoteWooCartHydrationService;
use BSP\Quotes\Service\WooCartLaunchGatewayInterface;

function sbdp_quote_execution_launch_boundary_smoke_fail(string $message): void
{
    if (class_exists('WP_CLI')) {
        WP_CLI::error($message);
    }

    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function sbdp_quote_execution_launch_boundary_smoke_ok(bool $condition, string $message): void
{
    if (! $condition) {
        sbdp_quote_execution_launch_boundary_smoke_fail($message);
    }
}

if (
    ! class_exists(QuoteRepository::class)
    || ! class_exists(QuoteExecutionLaunchService::class)
    || ! class_exists(QuoteWooCartHydrationService::class)
    || ! interface_exists(WooCartLaunchGatewayInterface::class)
) {
    sbdp_quote_execution_launch_boundary_smoke_fail('Quote execution launch services are not loaded.');
}

global $wpdb;
sbdp_quote_execution_launch_boundary_smoke_ok(isset($wpdb) && $wpdb instanceof wpdb, 'WordPress database is not available.');

$repository = new QuoteRepository();
$events = new QuoteEventLogger($repository);
$created = array();
$actorId = 42;

try {
    $request = $repository->createQuoteRequest(array(
        'request_reference' => 'QR-EXEC-LAUNCH-' . gmdate('YmdHis'),
        'status' => 'converted_to_quote',
        'request_summary' => 'Quote execution launch boundary smoke',
        'requester_name' => 'Smoke Test',
        'requester_email' => 'smoke@example.test',
        'group_size' => 3,
        'preferred_date' => '2026-06-24',
        'preferred_start_time' => '14:00',
        'preferred_end_time' => '15:30',
        'source_type' => 'quote_execution_launch_boundary_smoke',
    ));
    $quote = $repository->createQuote(array(
        'quote_request_id' => (int) ($request['id'] ?? 0),
        'quote_reference' => 'Q-EXEC-LAUNCH-' . gmdate('YmdHis'),
        'status' => 'confirmed',
        'handoff_status' => 'execution_validated',
        'review_status' => 'approved',
        'send_status' => 'sent_manual',
    ));
    $version = $repository->createQuoteVersion(array(
        'quote_id' => (int) ($quote['id'] ?? 0),
        'version_number' => 1,
        'status' => 'approved',
        'proposal_title' => 'Quote execution launch boundary smoke',
        'snapshot_type' => 'execution_resnapshot',
        'pricing_confidence' => 'execution_verified',
        'availability_confidence' => 'confirmed',
        'pricing_snapshot_json' => array('source' => 'quote_execution_launch_boundary_smoke'),
        'handoff_payload_json' => array(
            'handoff_package' => array(
                'quote_id' => (int) ($quote['id'] ?? 0),
                'quote_version_id' => 0,
                'boundary' => 'smoke_fixture',
            ),
            'execution_adapter' => array(
                'adapter_type' => 'cart_order_prep',
                'quote_id' => (int) ($quote['id'] ?? 0),
                'quote_version_id' => 0,
                'customer' => array(
                    'name' => 'Smoke Test',
                    'email' => 'smoke@example.test',
                ),
                'items' => array(array(
                    'product_id' => 352,
                    'quantity' => 3,
                    'participants' => 3,
                    'resource_id' => 0,
                    'date' => '2026-06-24',
                    'start' => '14:00',
                    'end' => '15:30',
                    'sbdp_meta' => array(
                        'quote_id' => (int) ($quote['id'] ?? 0),
                        'quote_version_id' => 0,
                        'sbdp_pricing_source' => 'quote_execution_resnapshot',
                    ),
                    'sbdp_summary' => array(
                        'title' => 'Eigen activiteit',
                        'participants' => 3,
                    ),
                    'sbdp_pricing' => array(
                        'display_unit_price' => 25.0,
                        'display_total' => 75.0,
                        'currency' => 'EUR',
                    ),
                )),
                'totals' => array(
                    'display_total' => 75.0,
                    'currency' => 'EUR',
                ),
            ),
            'execution_validation' => array(
                'ready_for_runtime_execution' => true,
                'validated_at' => gmdate('Y-m-d H:i:s'),
                'source' => 'quote_execution_launch_boundary_smoke',
            ),
        ),
    ));

    $quoteId = (int) ($quote['id'] ?? 0);
    $versionId = (int) ($version['id'] ?? 0);
    $created[] = array(
        'request_id' => (int) ($request['id'] ?? 0),
        'quote_id' => $quoteId,
        'version_id' => $versionId,
    );

    $versionPayload = is_array($version['handoff_payload_json'] ?? null) ? $version['handoff_payload_json'] : array();
    $versionPayload['handoff_package']['quote_version_id'] = $versionId;
    $versionPayload['execution_adapter']['quote_version_id'] = $versionId;
    $versionPayload['execution_adapter']['items'][0]['sbdp_meta']['quote_version_id'] = $versionId;
    $repository->updateQuoteVersion($versionId, array(
        'handoff_payload_json' => $versionPayload,
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ));
    $repository->updateQuote($quoteId, array(
        'current_version_id' => $versionId,
        'approved_version_id' => $versionId,
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ));

    $launch = (new QuoteExecutionLaunchService($repository, $events))->buildWooCartSessionPrep($quoteId, $actorId);
    $afterLaunchQuote = $repository->findQuote($quoteId);
    $afterLaunchVersion = $repository->findQuoteVersion($versionId);
    $afterLaunchPayload = is_array($afterLaunchVersion['handoff_payload_json'] ?? null)
        ? $afterLaunchVersion['handoff_payload_json']
        : array();

    sbdp_quote_execution_launch_boundary_smoke_ok((int) ($launch['quote_version_id'] ?? 0) === $versionId, 'Execution launch did not use approved_version_id.');
    sbdp_quote_execution_launch_boundary_smoke_ok((string) ($afterLaunchQuote['status'] ?? '') === 'confirmed', 'Execution launch changed quote status.');
    sbdp_quote_execution_launch_boundary_smoke_ok((string) ($afterLaunchQuote['handoff_status'] ?? '') === 'execution_launch_ready', 'Execution launch did not stop at execution_launch_ready.');
    sbdp_quote_execution_launch_boundary_smoke_ok(empty($afterLaunchQuote['booking_master_id']), 'Execution launch should not set booking_master_id.');
    sbdp_quote_execution_launch_boundary_smoke_ok(isset($afterLaunchPayload['execution_launch']), 'Execution launch payload missing.');
    sbdp_quote_execution_launch_boundary_smoke_ok(! isset($afterLaunchPayload['execution_launch']['consumed_at']), 'Execution launch token should not be consumed before fake hydration.');

    $fakeGateway = new class implements WooCartLaunchGatewayInterface {
        public int $calls = 0;
        /** @var array<string, mixed> */
        public array $lastPayload = array();

        public function hydrate(array $launchPayload): array
        {
            ++$this->calls;
            $this->lastPayload = $launchPayload;

            return array(
                'cart_item_count' => count((array) ($launchPayload['items'] ?? array())),
                'cart_url' => 'https://example.test/cart?quote_launch=fake',
                'checkout_url' => 'https://example.test/checkout?quote_launch=fake',
                'gateway' => 'fake_local_boundary',
            );
        }
    };
    $hydration = (new QuoteWooCartHydrationService($fakeGateway, $repository, $events))
        ->hydrateLaunchToCart($quoteId, (string) ($launch['launch_token'] ?? ''), $actorId);
    $finalQuote = $repository->findQuote($quoteId);
    $finalVersion = $repository->findQuoteVersion($versionId);
    $finalPayload = is_array($finalVersion['handoff_payload_json'] ?? null) ? $finalVersion['handoff_payload_json'] : array();
    $eventTypes = array_column($repository->listQuoteEvents($quoteId), 'event_type');

    sbdp_quote_execution_launch_boundary_smoke_ok($fakeGateway->calls === 1, 'Fake gateway was not called exactly once.');
    sbdp_quote_execution_launch_boundary_smoke_ok((int) ($fakeGateway->lastPayload['quote_version_id'] ?? 0) === $versionId, 'Fake gateway did not receive approved_version_id.');
    sbdp_quote_execution_launch_boundary_smoke_ok((string) ($finalQuote['status'] ?? '') === 'confirmed', 'Fake hydration changed quote status.');
    sbdp_quote_execution_launch_boundary_smoke_ok((string) ($finalQuote['handoff_status'] ?? '') === 'woo_cart_hydrated', 'Fake hydration did not set the controlled woo_cart_hydrated boundary status.');
    sbdp_quote_execution_launch_boundary_smoke_ok(empty($finalQuote['booking_master_id']), 'Fake hydration should not set booking_master_id.');
    sbdp_quote_execution_launch_boundary_smoke_ok(isset($finalPayload['hydration_result']['result']['checkout_url']), 'Fake hydration result did not store checkout_url.');
    sbdp_quote_execution_launch_boundary_smoke_ok(in_array('quote_execution_launch_built', $eventTypes, true), 'Launch-built event missing.');
    sbdp_quote_execution_launch_boundary_smoke_ok(in_array('quote_woo_cart_hydrated', $eventTypes, true), 'Fake hydration event missing.');

    echo wp_json_encode(array(
        'ok' => true,
        'initial_handoff_status' => 'execution_validated',
        'launch_handoff_status' => (string) ($afterLaunchQuote['handoff_status'] ?? ''),
        'final_handoff_status' => (string) ($finalQuote['handoff_status'] ?? ''),
        'final_quote_status' => (string) ($finalQuote['status'] ?? ''),
        'approved_version_id' => $versionId,
        'launch_version_id' => (int) ($launch['quote_version_id'] ?? 0),
        'fake_gateway_version_id' => (int) ($fakeGateway->lastPayload['quote_version_id'] ?? 0),
        'launch_type' => (string) ($launch['launch_type'] ?? ''),
        'launch_item_count' => count((array) ($launch['items'] ?? array())),
        'launch_context_available' => isset($afterLaunchPayload['execution_launch']),
        'fake_gateway_called' => $fakeGateway->calls,
        'fake_cart_url' => (string) ($hydration['cart_url'] ?? ''),
        'fake_checkout_url' => (string) ($hydration['checkout_url'] ?? ''),
        'launch_event_logged' => in_array('quote_execution_launch_built', $eventTypes, true),
        'hydration_event_logged' => in_array('quote_woo_cart_hydrated', $eventTypes, true),
        'booking_master_id_empty' => empty($finalQuote['booking_master_id']),
        'real_woo_cart_hydration_executed' => false,
        'fake_gateway_hydration_executed' => true,
        'booking_created' => false,
        'provider_call_executed' => false,
        'eliio_call_executed' => false,
        'supplier_call_executed' => false,
        'supplier_confirmation_executed' => false,
        'email_sent' => false,
    ), JSON_PRETTY_PRINT) . PHP_EOL;
} finally {
    foreach (array_reverse($created) as $row) {
        if (isset($wpdb) && $wpdb instanceof wpdb) {
            $prefix = $wpdb->prefix;
            $quoteId = (int) ($row['quote_id'] ?? 0);
            $requestId = (int) ($row['request_id'] ?? 0);
            if ($quoteId > 0) {
                $wpdb->delete($prefix . 'bsp_quote_events', array('quote_id' => $quoteId));
                $wpdb->delete($prefix . 'bsp_quote_messages', array('quote_id' => $quoteId));
                $wpdb->delete($prefix . 'bsp_quote_followups', array('quote_id' => $quoteId));
                $wpdb->delete($prefix . 'bsp_quote_assumptions', array('quote_id' => $quoteId));
                $versionIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$prefix}bsp_quote_versions WHERE quote_id = %d", $quoteId));
                foreach ((array) $versionIds as $id) {
                    $wpdb->delete($prefix . 'bsp_quote_lines', array('quote_version_id' => (int) $id));
                }
                $wpdb->delete($prefix . 'bsp_quote_versions', array('quote_id' => $quoteId));
                $wpdb->delete($prefix . 'bsp_quotes', array('id' => $quoteId));
            }
            if ($requestId > 0) {
                $wpdb->delete($prefix . 'bsp_quote_requests', array('id' => $requestId));
            }
        }
    }
}
