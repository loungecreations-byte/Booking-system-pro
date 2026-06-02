<?php

if (! function_exists('add_action')) {
    $wpLoad = dirname(__DIR__, 4) . '/wp-load.php';
    if (is_readable($wpLoad)) {
        require_once $wpLoad;
    }
}

use BSP\Quotes\Repository\QuoteRepository;
use BSP\Quotes\Service\QuoteAssumptionService;
use BSP\Quotes\Service\QuoteEventLogger;
use BSP\Quotes\Service\QuoteExecutionAdapterService;
use BSP\Quotes\Service\QuoteExecutionLookupService;
use BSP\Quotes\Service\QuoteExecutionRunnerService;
use BSP\Quotes\Service\QuoteHandoffAdapterService;
use BSP\Quotes\Service\QuoteHandoffPreparationService;

function sbdp_quote_execution_prep_smoke_fail(string $message): void
{
    if (class_exists('WP_CLI')) {
        WP_CLI::error($message);
    }

    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function sbdp_quote_execution_prep_smoke_ok(bool $condition, string $message): void
{
    if (! $condition) {
        sbdp_quote_execution_prep_smoke_fail($message);
    }
}

if (! class_exists(QuoteRepository::class) || ! class_exists(QuoteHandoffPreparationService::class)) {
    sbdp_quote_execution_prep_smoke_fail('Quote execution preparation services are not loaded.');
}
if (! function_exists('wc_create_order') || ! function_exists('wc_get_order')) {
    sbdp_quote_execution_prep_smoke_fail('WooCommerce is not loaded.');
}

global $wpdb;
sbdp_quote_execution_prep_smoke_ok(isset($wpdb) && $wpdb instanceof wpdb, 'WordPress database is not available.');

final class SbdpQuoteExecutionPrepSmokeLookup extends QuoteExecutionLookupService
{
    public function lookupPricing(array $line): array
    {
        $participants = max(1, (int) ($line['participants'] ?? $line['quantity'] ?? 1));
        $unit = 25.0;

        return array(
            'confidence' => 'execution_verified',
            'payload' => array('source' => 'quote_execution_prep_smoke'),
            'unit_amount_snapshot' => $unit,
            'line_total_snapshot' => $unit * $participants,
            'currency' => 'EUR',
        );
    }

    public function lookupAvailability(array $line): array
    {
        return array(
            'confidence' => 'confirmed',
            'available' => true,
            'payload' => array(
                'source' => 'quote_execution_prep_smoke',
                'slots' => array(array(
                    'start' => (string) ($line['start_time'] ?? '10:00'),
                    'end' => (string) ($line['end_time'] ?? '11:00'),
                    'available' => true,
                )),
            ),
        );
    }
}

$repository = new QuoteRepository();
$events = new QuoteEventLogger($repository);
$lookup = new SbdpQuoteExecutionPrepSmokeLookup();
$created = array();

function sbdp_quote_execution_prep_create_fixture(
    QuoteRepository $repository,
    QuoteEventLogger $events,
    array &$created,
    string $suffix,
    string $handoffStatus,
    string $snapshotType
): array {
    $request = $repository->createQuoteRequest(array(
        'request_reference' => 'QR-EXEC-PREP-' . $suffix . '-' . gmdate('YmdHis'),
        'status' => 'converted_to_quote',
        'request_summary' => 'Quote execution prep smoke ' . $suffix,
        'requester_name' => 'Smoke Test',
        'requester_email' => 'smoke@example.test',
        'group_size' => 4,
        'preferred_date' => '2026-06-20',
        'preferred_start_time' => '10:00',
        'preferred_end_time' => '11:00',
        'source_type' => 'quote_confirmed_execution_prep_smoke',
    ));
    $quote = $repository->createQuote(array(
        'quote_request_id' => (int) ($request['id'] ?? 0),
        'quote_reference' => 'Q-EXEC-PREP-' . $suffix . '-' . gmdate('YmdHis'),
        'status' => 'confirmed',
        'handoff_status' => $handoffStatus,
        'review_status' => 'approved',
        'send_status' => 'sent_manual',
    ));
    $version = $repository->createQuoteVersion(array(
        'quote_id' => (int) ($quote['id'] ?? 0),
        'version_number' => 1,
        'status' => 'draft',
        'proposal_title' => 'Quote execution prep smoke ' . $suffix,
        'snapshot_type' => $snapshotType,
        'pricing_confidence' => 'execution_verified',
        'availability_confidence' => 'confirmed',
        'pricing_snapshot_json' => array(),
    ));
    $repository->replaceQuoteLines((int) ($version['id'] ?? 0), array(array(
        'line_number' => 1,
        'line_type' => 'product',
        'line_status' => 'mapped',
        'title' => 'Eigen activiteit',
        'product_id' => 352,
        'quantity' => 4,
        'participants' => 4,
        'service_date' => '2026-06-20',
        'start_time' => '10:00',
        'end_time' => '11:00',
        'pricing_confidence' => 'execution_verified',
        'availability_confidence' => 'confirmed',
        'unit_amount_snapshot' => 25.0,
        'line_total_snapshot' => 100.0,
        'currency' => 'EUR',
        'pricing_snapshot_json' => array('source' => 'quote_execution_prep_smoke'),
        'availability_snapshot_json' => array('source' => 'quote_execution_prep_smoke', 'control_status' => 'confirmed'),
    )));

    $order = wc_create_order(array('status' => 'pending'));
    sbdp_quote_execution_prep_smoke_ok($order instanceof WC_Order, 'Woo order could not be created.');
    $orderId = (int) $order->get_id();
    $order->update_meta_data('_sbdp_quote_id', (int) ($quote['id'] ?? 0));
    $order->update_meta_data('_sbdp_quote_version_id', (int) ($version['id'] ?? 0));
    $order->update_meta_data('_sbdp_quote_reference', (string) ($quote['quote_reference'] ?? ''));
    $order->save();

    $quote = $repository->updateQuote((int) ($quote['id'] ?? 0), array(
        'current_version_id' => (int) ($version['id'] ?? 0),
        'approved_version_id' => (int) ($version['id'] ?? 0),
        'woo_order_id' => $orderId,
        'handoff_status' => $handoffStatus,
    ));
    $events->log(
        'quote_confirmed',
        (int) ($request['id'] ?? 0),
        (int) ($quote['id'] ?? 0),
        (int) ($version['id'] ?? 0),
        42,
        'Smoke confirmed quote event.',
        array(
            'quote_id' => (int) ($quote['id'] ?? 0),
            'approved_version_id' => (int) ($version['id'] ?? 0),
            'woo_order_id' => $orderId,
            'source' => 'quote_confirmed_execution_prep_smoke',
        )
    );

    $created[] = array(
        'request_id' => (int) ($request['id'] ?? 0),
        'quote_id' => (int) ($quote['id'] ?? 0),
        'version_id' => (int) ($version['id'] ?? 0),
        'order_id' => $orderId,
    );

    return array('quote' => $quote, 'version' => $version, 'order_id' => $orderId);
}

try {
    $prepFixture = sbdp_quote_execution_prep_create_fixture(
        $repository,
        $events,
        $created,
        'PREP-BOUNDARY',
        'ready_for_resnapshot',
        'initial'
    );

    $prep = (new QuoteHandoffPreparationService(
        $repository,
        new QuoteAssumptionService($repository, $events),
        $events,
        $lookup
    ))->prepareResnapshot((int) ($prepFixture['quote']['id'] ?? 0), 42);
    $afterPrepQuote = $repository->findQuote((int) ($prepFixture['quote']['id'] ?? 0));
    $adapterBlockedAtApprovedVersion = false;
    try {
        (new QuoteHandoffAdapterService($repository, $events))->buildControlledPackage((int) ($prepFixture['quote']['id'] ?? 0), 42);
    } catch (Throwable $exception) {
        $adapterBlockedAtApprovedVersion = str_contains($exception->getMessage(), 'execution_resnapshot');
    }

    sbdp_quote_execution_prep_smoke_ok(is_array($prep['version'] ?? null), 'Preparation did not create an execution resnapshot.');
    sbdp_quote_execution_prep_smoke_ok((string) ($afterPrepQuote['status'] ?? '') === 'confirmed', 'Preparation changed quote status.');
    sbdp_quote_execution_prep_smoke_ok((string) ($afterPrepQuote['handoff_status'] ?? '') === 'resnapshot_prepared', 'Preparation did not set resnapshot_prepared.');
    sbdp_quote_execution_prep_smoke_ok($adapterBlockedAtApprovedVersion, 'Adapter should fail when approved_version_id still points at the pre-resnapshot version.');

    $adapterFixture = sbdp_quote_execution_prep_create_fixture(
        $repository,
        $events,
        $created,
        'ADAPTER',
        'resnapshot_prepared',
        'execution_resnapshot'
    );
    $quoteId = (int) ($adapterFixture['quote']['id'] ?? 0);
    $approvedVersionId = (int) ($adapterFixture['version']['id'] ?? 0);

    $package = (new QuoteHandoffAdapterService($repository, $events))->buildControlledPackage($quoteId, 42);
    $adapterPayload = (new QuoteExecutionAdapterService($repository, $events))->buildCartOrderPrep($quoteId, 42);
    $validation = (new QuoteExecutionRunnerService($repository, $events, $lookup))->validateCartReady($quoteId, 42);
    $finalQuote = $repository->findQuote($quoteId);
    $finalVersion = $repository->findQuoteVersion($approvedVersionId);
    $handoffPayload = is_array($finalVersion['handoff_payload_json'] ?? null) ? $finalVersion['handoff_payload_json'] : array();

    sbdp_quote_execution_prep_smoke_ok((int) ($package['quote_version_id'] ?? 0) === $approvedVersionId, 'Handoff package did not use approved_version_id.');
    sbdp_quote_execution_prep_smoke_ok((int) ($adapterPayload['quote_version_id'] ?? 0) === $approvedVersionId, 'Execution adapter did not use approved_version_id.');
    sbdp_quote_execution_prep_smoke_ok((int) ($validation['quote_version_id'] ?? 0) === $approvedVersionId, 'Execution validation did not use approved_version_id.');
    sbdp_quote_execution_prep_smoke_ok((string) ($finalQuote['status'] ?? '') === 'confirmed', 'Execution prep changed quote status.');
    sbdp_quote_execution_prep_smoke_ok((string) ($finalQuote['handoff_status'] ?? '') === 'execution_validated', 'Execution prep did not stop at execution_validated.');
    sbdp_quote_execution_prep_smoke_ok(empty($finalQuote['booking_master_id']), 'booking_master_id should stay empty.');
    sbdp_quote_execution_prep_smoke_ok(isset($handoffPayload['execution_adapter']), 'Execution adapter payload missing.');
    sbdp_quote_execution_prep_smoke_ok(isset($handoffPayload['execution_validation']), 'Execution validation payload missing.');
    sbdp_quote_execution_prep_smoke_ok(! isset($handoffPayload['execution_launch']), 'Execution launch payload should not be created.');

    echo wp_json_encode(array(
        'ok' => true,
        'prep_boundary_status' => (string) ($afterPrepQuote['handoff_status'] ?? ''),
        'prep_created_snapshot_type' => (string) (($prep['version']['snapshot_type'] ?? '')),
        'adapter_blocked_without_repinning_approved_version' => $adapterBlockedAtApprovedVersion,
        'adapter_fixture_initial_status' => 'confirmed',
        'final_quote_status' => (string) ($finalQuote['status'] ?? ''),
        'final_handoff_status' => (string) ($finalQuote['handoff_status'] ?? ''),
        'approved_version_id' => $approvedVersionId,
        'package_version_id' => (int) ($package['quote_version_id'] ?? 0),
        'adapter_version_id' => (int) ($adapterPayload['quote_version_id'] ?? 0),
        'validation_version_id' => (int) ($validation['quote_version_id'] ?? 0),
        'ready_for_runtime_execution' => ! empty($validation['ready_for_runtime_execution']),
        'booking_master_id_empty' => empty($finalQuote['booking_master_id']),
        'execution_launch_created' => isset($handoffPayload['execution_launch']),
        'cart_hydration_executed' => false,
        'booking_created' => false,
        'provider_call_executed' => false,
        'supplier_confirmation_executed' => false,
        'email_sent' => false,
    ), JSON_PRETTY_PRINT) . PHP_EOL;
} finally {
    foreach (array_reverse($created) as $row) {
        if (! empty($row['order_id'])) {
            $order = wc_get_order((int) $row['order_id']);
            if ($order instanceof WC_Order) {
                $order->delete(true);
            }
        }
        if (isset($wpdb) && $wpdb instanceof wpdb) {
            $prefix = $wpdb->prefix;
            $quoteId = (int) ($row['quote_id'] ?? 0);
            $versionId = (int) ($row['version_id'] ?? 0);
            $requestId = (int) ($row['request_id'] ?? 0);
            if ($quoteId > 0) {
                $wpdb->delete($prefix . 'bsp_quote_events', array('quote_id' => $quoteId));
                $wpdb->delete($prefix . 'bsp_quote_messages', array('quote_id' => $quoteId));
                $wpdb->delete($prefix . 'bsp_quote_followups', array('quote_id' => $quoteId));
                $wpdb->delete($prefix . 'bsp_quote_assumptions', array('quote_id' => $quoteId));
            }
            if ($quoteId > 0) {
                $versionIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$prefix}bsp_quote_versions WHERE quote_id = %d", $quoteId));
                foreach ((array) $versionIds as $id) {
                    $wpdb->delete($prefix . 'bsp_quote_lines', array('quote_version_id' => (int) $id));
                }
                $wpdb->delete($prefix . 'bsp_quote_versions', array('quote_id' => $quoteId));
                $wpdb->delete($prefix . 'bsp_quotes', array('id' => $quoteId));
            } elseif ($versionId > 0) {
                $wpdb->delete($prefix . 'bsp_quote_lines', array('quote_version_id' => $versionId));
                $wpdb->delete($prefix . 'bsp_quote_versions', array('id' => $versionId));
            }
            if ($requestId > 0) {
                $wpdb->delete($prefix . 'bsp_quote_requests', array('id' => $requestId));
            }
        }
    }
}
