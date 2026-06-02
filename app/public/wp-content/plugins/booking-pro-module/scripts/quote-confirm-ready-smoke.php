<?php

if (! function_exists('add_action')) {
    $wpLoad = dirname(__DIR__, 4) . '/wp-load.php';
    if (is_readable($wpLoad)) {
        require_once $wpLoad;
    }
}

use BSP\Quotes\Repository\QuoteRepository;
use BSP\Quotes\Service\QuoteAdminConfirmationService;
use BSP\Quotes\Service\QuoteConfirmationReadinessService;
use BSP\Quotes\Service\QuoteEventLogger;
use BSP\Quotes\Service\QuotePaymentSyncService;

function sbdp_quote_confirm_ready_smoke_fail(string $message): void
{
    if (class_exists('WP_CLI')) {
        WP_CLI::error($message);
    }

    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function sbdp_quote_confirm_ready_smoke_ok(bool $condition, string $message): void
{
    if (! $condition) {
        sbdp_quote_confirm_ready_smoke_fail($message);
    }
}

if (
    ! class_exists(QuoteRepository::class)
    || ! class_exists(QuoteConfirmationReadinessService::class)
    || ! class_exists(QuoteAdminConfirmationService::class)
) {
    sbdp_quote_confirm_ready_smoke_fail('Quote confirm-ready services are not loaded.');
}
if (! function_exists('wc_create_order') || ! function_exists('wc_get_order')) {
    sbdp_quote_confirm_ready_smoke_fail('WooCommerce is not loaded.');
}

global $wpdb;
sbdp_quote_confirm_ready_smoke_ok(isset($wpdb) && $wpdb instanceof wpdb, 'WordPress database is not available.');

$repository = new QuoteRepository();
$events = new QuoteEventLogger($repository);
$readiness = new QuoteConfirmationReadinessService($repository, $events);
$confirmation = new QuoteAdminConfirmationService($repository, $events, $readiness);
$created = array();

function sbdp_quote_confirm_ready_create_fixture(
    QuoteRepository $repository,
    QuoteEventLogger $events,
    array &$created,
    string $suffix,
    array $line
): array {
    $request = $repository->createQuoteRequest(array(
        'request_reference' => 'QR-CONFIRM-' . $suffix . '-' . gmdate('YmdHis'),
        'status' => 'converted_to_quote',
        'request_summary' => 'Quote confirm ready smoke ' . $suffix,
        'requester_name' => 'Smoke Test',
        'requester_email' => 'smoke@example.test',
        'group_size' => 4,
        'source_type' => 'quote_confirm_ready_smoke',
    ));
    $quote = $repository->createQuote(array(
        'quote_request_id' => (int) ($request['id'] ?? 0),
        'quote_reference' => 'Q-CONFIRM-' . $suffix . '-' . gmdate('YmdHis'),
        'status' => 'accepted',
        'handoff_status' => QuotePaymentSyncService::COMPLETED_STATUS,
        'review_status' => 'approved',
        'send_status' => 'sent_manual',
    ));
    $version = $repository->createQuoteVersion(array(
        'quote_id' => (int) ($quote['id'] ?? 0),
        'version_number' => 1,
        'status' => 'draft',
        'proposal_title' => 'Quote confirm ready smoke ' . $suffix,
        'snapshot_type' => 'execution_resnapshot',
        'pricing_confidence' => (string) ($line['pricing_confidence'] ?? 'unknown'),
        'availability_confidence' => (string) ($line['availability_confidence'] ?? 'unknown'),
    ));
    $line['quote_version_id'] = (int) ($version['id'] ?? 0);
    $repository->replaceQuoteLines((int) ($version['id'] ?? 0), array($line));

    $order = wc_create_order(array('status' => 'pending'));
    sbdp_quote_confirm_ready_smoke_ok($order instanceof WC_Order, 'Woo order could not be created.');
    $orderId = (int) $order->get_id();
    $order->update_meta_data('_sbdp_quote_id', (int) ($quote['id'] ?? 0));
    $order->update_meta_data('_sbdp_quote_version_id', (int) ($version['id'] ?? 0));
    $order->update_meta_data('_sbdp_quote_reference', (string) ($quote['quote_reference'] ?? ''));
    $order->save();

    $quote = $repository->updateQuote((int) ($quote['id'] ?? 0), array(
        'approved_version_id' => (int) ($version['id'] ?? 0),
        'woo_order_id' => $orderId,
        'handoff_status' => QuotePaymentSyncService::COMPLETED_STATUS,
    ));
    $events->log(
        QuotePaymentSyncService::COMPLETED_EVENT,
        (int) ($request['id'] ?? 0),
        (int) ($quote['id'] ?? 0),
        (int) ($version['id'] ?? 0),
        null,
        'Smoke payment complete event.',
        array(
            'quote_id' => (int) ($quote['id'] ?? 0),
            'order_id' => $orderId,
            'approved_version_id' => (int) ($version['id'] ?? 0),
            'quote_reference' => (string) ($quote['quote_reference'] ?? ''),
        )
    );

    $created[] = array(
        'request_id' => (int) ($request['id'] ?? 0),
        'quote_id' => (int) ($quote['id'] ?? 0),
        'version_id' => (int) ($version['id'] ?? 0),
        'order_id' => $orderId,
    );

    return $quote;
}

function sbdp_quote_confirm_ready_event_count(QuoteRepository $repository, int $quoteId, string $eventType): int
{
    $count = 0;
    foreach ($repository->listQuoteEvents($quoteId) as $event) {
        if ((string) ($event['event_type'] ?? '') === $eventType) {
            $count++;
        }
    }

    return $count;
}

try {
    $readyQuote = sbdp_quote_confirm_ready_create_fixture($repository, $events, $created, 'READY', array(
        'line_number' => 1,
        'line_type' => 'product',
        'line_status' => 'mapped',
        'title' => 'Eigen activiteit',
        'product_id' => 352,
        'quantity' => 4,
        'participants' => 4,
        'pricing_confidence' => 'execution_verified',
        'availability_confidence' => 'confirmed',
        'pricing_snapshot_json' => array('control_status' => 'confirmed'),
        'availability_snapshot_json' => array('control_status' => 'confirmed'),
    ));
    $supplierQuote = sbdp_quote_confirm_ready_create_fixture($repository, $events, $created, 'SUPPLIER', array(
        'line_number' => 1,
        'line_type' => 'product',
        'line_status' => 'mapped',
        'title' => 'Supplier activiteit',
        'product_id' => 115,
        'quantity' => 4,
        'participants' => 4,
        'pricing_confidence' => 'execution_verified',
        'availability_confidence' => 'confirmed',
        'pricing_snapshot_json' => array('control_status' => 'confirmed'),
        'availability_snapshot_json' => array(
            'control_status' => 'confirmed',
            'bookingMode' => 'supplier_confirmation',
            'supplierStatus' => 'supplier_confirmation_required',
        ),
    ));
    $manualQuote = sbdp_quote_confirm_ready_create_fixture($repository, $events, $created, 'MANUAL', array(
        'line_number' => 1,
        'line_type' => 'manual',
        'line_status' => 'directional',
        'title' => 'Maatwerk onderdeel',
        'product_id' => null,
        'quantity' => 1,
        'participants' => 4,
        'pricing_confidence' => 'execution_verified',
        'availability_confidence' => 'confirmed',
    ));

    $readyOutcome = $readiness->evaluate((int) ($readyQuote['id'] ?? 0));
    $supplierOutcome = $readiness->evaluate((int) ($supplierQuote['id'] ?? 0));
    $manualOutcome = $readiness->evaluate((int) ($manualQuote['id'] ?? 0));

    sbdp_quote_confirm_ready_smoke_ok((string) ($readyOutcome['outcome'] ?? '') === QuoteConfirmationReadinessService::READY_TO_CONFIRM, 'Ready quote was not ready_to_confirm.');
    sbdp_quote_confirm_ready_smoke_ok((string) ($supplierOutcome['outcome'] ?? '') === QuoteConfirmationReadinessService::AWAITING_SUPPLIER_CONFIRMATION, 'Supplier quote was not awaiting_supplier_confirmation.');
    sbdp_quote_confirm_ready_smoke_ok((string) ($manualOutcome['outcome'] ?? '') === QuoteConfirmationReadinessService::REQUIRES_ADMIN_CONFIRMATION, 'Manual quote was not requires_admin_confirmation.');

    $confirmation->confirmReadyQuote((int) ($readyQuote['id'] ?? 0), 42);
    $confirmation->confirmReadyQuote((int) ($readyQuote['id'] ?? 0), 42);

    $supplierBlocked = false;
    try {
        $confirmation->confirmReadyQuote((int) ($supplierQuote['id'] ?? 0), 42);
    } catch (Throwable) {
        $supplierBlocked = true;
    }
    $manualBlocked = false;
    try {
        $confirmation->confirmReadyQuote((int) ($manualQuote['id'] ?? 0), 42);
    } catch (Throwable) {
        $manualBlocked = true;
    }

    $readyFinal = $repository->findQuote((int) ($readyQuote['id'] ?? 0));
    $supplierFinal = $repository->findQuote((int) ($supplierQuote['id'] ?? 0));
    $manualFinal = $repository->findQuote((int) ($manualQuote['id'] ?? 0));

    $readyConfirmCount = sbdp_quote_confirm_ready_event_count($repository, (int) ($readyQuote['id'] ?? 0), QuoteAdminConfirmationService::CONFIRMED_EVENT);
    $supplierConfirmCount = sbdp_quote_confirm_ready_event_count($repository, (int) ($supplierQuote['id'] ?? 0), QuoteAdminConfirmationService::CONFIRMED_EVENT);
    $manualConfirmCount = sbdp_quote_confirm_ready_event_count($repository, (int) ($manualQuote['id'] ?? 0), QuoteAdminConfirmationService::CONFIRMED_EVENT);

    sbdp_quote_confirm_ready_smoke_ok((string) ($readyFinal['status'] ?? '') === 'confirmed', 'Ready quote was not confirmed.');
    sbdp_quote_confirm_ready_smoke_ok($readyConfirmCount === 1, 'Ready quote confirmation event count should be one.');
    sbdp_quote_confirm_ready_smoke_ok($supplierBlocked, 'Supplier quote confirmation should be blocked.');
    sbdp_quote_confirm_ready_smoke_ok($manualBlocked, 'Manual quote confirmation should be blocked.');
    sbdp_quote_confirm_ready_smoke_ok((string) ($supplierFinal['status'] ?? '') === 'accepted', 'Supplier quote status changed.');
    sbdp_quote_confirm_ready_smoke_ok((string) ($manualFinal['status'] ?? '') === 'accepted', 'Manual quote status changed.');
    sbdp_quote_confirm_ready_smoke_ok($supplierConfirmCount === 0, 'Supplier quote should not have confirmation event.');
    sbdp_quote_confirm_ready_smoke_ok($manualConfirmCount === 0, 'Manual quote should not have confirmation event.');

    echo wp_json_encode(array(
        'ok' => true,
        'ready_outcome' => (string) ($readyOutcome['outcome'] ?? ''),
        'supplier_outcome' => (string) ($supplierOutcome['outcome'] ?? ''),
        'manual_outcome' => (string) ($manualOutcome['outcome'] ?? ''),
        'ready_quote_status' => (string) ($readyFinal['status'] ?? ''),
        'supplier_quote_status' => (string) ($supplierFinal['status'] ?? ''),
        'manual_quote_status' => (string) ($manualFinal['status'] ?? ''),
        'ready_confirmation_event_count' => $readyConfirmCount,
        'supplier_confirmation_event_count' => $supplierConfirmCount,
        'manual_confirmation_event_count' => $manualConfirmCount,
        'booking_created' => false,
        'execution_created' => false,
        'provider_call_executed' => false,
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
            if ($versionId > 0) {
                $wpdb->delete($prefix . 'bsp_quote_lines', array('quote_version_id' => $versionId));
            }
            if ($quoteId > 0) {
                $wpdb->delete($prefix . 'bsp_quote_versions', array('quote_id' => $quoteId));
                $wpdb->delete($prefix . 'bsp_quotes', array('id' => $quoteId));
            }
            if ($requestId > 0) {
                $wpdb->delete($prefix . 'bsp_quote_requests', array('id' => $requestId));
            }
        }
    }
}
