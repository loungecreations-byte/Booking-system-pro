<?php

if (! function_exists('add_action')) {
    $wpLoad = dirname(__DIR__, 4) . '/wp-load.php';
    if (is_readable($wpLoad)) {
        require_once $wpLoad;
    }
}

use BSP\Quotes\Repository\QuoteRepository;
use BSP\Quotes\Service\QuoteConfirmationReadinessService;
use BSP\Quotes\Service\QuoteEventLogger;
use BSP\Quotes\Service\QuotePaymentSyncService;

function sbdp_quote_readiness_smoke_fail(string $message): void
{
    if (class_exists('WP_CLI')) {
        WP_CLI::error($message);
    }

    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function sbdp_quote_readiness_smoke_ok(bool $condition, string $message): void
{
    if (! $condition) {
        sbdp_quote_readiness_smoke_fail($message);
    }
}

if (! class_exists(QuoteRepository::class) || ! class_exists(QuoteConfirmationReadinessService::class)) {
    sbdp_quote_readiness_smoke_fail('Quote readiness services are not loaded.');
}
if (! function_exists('wc_create_order') || ! function_exists('wc_get_order')) {
    sbdp_quote_readiness_smoke_fail('WooCommerce is not loaded.');
}

global $wpdb;
sbdp_quote_readiness_smoke_ok(isset($wpdb) && $wpdb instanceof wpdb, 'WordPress database is not available.');

$repository = new QuoteRepository();
$events = new QuoteEventLogger($repository);
$service = new QuoteConfirmationReadinessService($repository, $events);
$created = array();

function sbdp_quote_readiness_create_fixture(
    QuoteRepository $repository,
    QuoteEventLogger $events,
    array &$created,
    string $suffix,
    array $line
): array {
    $request = $repository->createQuoteRequest(array(
        'request_reference' => 'QR-READY-' . $suffix . '-' . gmdate('YmdHis'),
        'status' => 'converted_to_quote',
        'request_summary' => 'Quote readiness smoke ' . $suffix,
        'requester_name' => 'Smoke Test',
        'requester_email' => 'smoke@example.test',
        'group_size' => 4,
        'source_type' => 'quote_confirmation_readiness_smoke',
    ));
    $quote = $repository->createQuote(array(
        'quote_request_id' => (int) ($request['id'] ?? 0),
        'quote_reference' => 'Q-READY-' . $suffix . '-' . gmdate('YmdHis'),
        'status' => 'accepted',
        'handoff_status' => QuotePaymentSyncService::COMPLETED_STATUS,
        'review_status' => 'approved',
        'send_status' => 'sent_manual',
    ));
    $version = $repository->createQuoteVersion(array(
        'quote_id' => (int) ($quote['id'] ?? 0),
        'version_number' => 1,
        'status' => 'draft',
        'proposal_title' => 'Quote readiness smoke ' . $suffix,
        'snapshot_type' => 'execution_resnapshot',
        'pricing_confidence' => (string) ($line['pricing_confidence'] ?? 'unknown'),
        'availability_confidence' => (string) ($line['availability_confidence'] ?? 'unknown'),
    ));
    $line['quote_version_id'] = (int) ($version['id'] ?? 0);
    $repository->replaceQuoteLines((int) ($version['id'] ?? 0), array($line));

    $order = wc_create_order(array('status' => 'pending'));
    sbdp_quote_readiness_smoke_ok($order instanceof WC_Order, 'Woo order could not be created.');
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

try {
    $directQuote = sbdp_quote_readiness_create_fixture($repository, $events, $created, 'DIRECT', array(
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
    $supplierQuote = sbdp_quote_readiness_create_fixture($repository, $events, $created, 'SUPPLIER', array(
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
    $manualQuote = sbdp_quote_readiness_create_fixture($repository, $events, $created, 'MANUAL', array(
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

    $direct = $service->evaluate((int) ($directQuote['id'] ?? 0));
    $supplier = $service->evaluate((int) ($supplierQuote['id'] ?? 0));
    $manual = $service->evaluate((int) ($manualQuote['id'] ?? 0));

    $directFinal = $repository->findQuote((int) ($directQuote['id'] ?? 0));
    $supplierFinal = $repository->findQuote((int) ($supplierQuote['id'] ?? 0));
    $manualFinal = $repository->findQuote((int) ($manualQuote['id'] ?? 0));

    sbdp_quote_readiness_smoke_ok((string) ($direct['outcome'] ?? '') === QuoteConfirmationReadinessService::READY_TO_CONFIRM, 'Direct scenario was not ready_to_confirm.');
    sbdp_quote_readiness_smoke_ok((string) ($supplier['outcome'] ?? '') === QuoteConfirmationReadinessService::AWAITING_SUPPLIER_CONFIRMATION, 'Supplier scenario was not awaiting_supplier_confirmation.');
    sbdp_quote_readiness_smoke_ok((string) ($manual['outcome'] ?? '') === QuoteConfirmationReadinessService::REQUIRES_ADMIN_CONFIRMATION, 'Manual scenario was not requires_admin_confirmation.');
    sbdp_quote_readiness_smoke_ok((string) ($directFinal['status'] ?? '') === 'accepted', 'Direct quote status changed.');
    sbdp_quote_readiness_smoke_ok((string) ($supplierFinal['status'] ?? '') === 'accepted', 'Supplier quote status changed.');
    sbdp_quote_readiness_smoke_ok((string) ($manualFinal['status'] ?? '') === 'accepted', 'Manual quote status changed.');

    echo wp_json_encode(array(
        'ok' => true,
        'direct_outcome' => (string) ($direct['outcome'] ?? ''),
        'supplier_outcome' => (string) ($supplier['outcome'] ?? ''),
        'manual_outcome' => (string) ($manual['outcome'] ?? ''),
        'direct_quote_status' => (string) ($directFinal['status'] ?? ''),
        'supplier_quote_status' => (string) ($supplierFinal['status'] ?? ''),
        'manual_quote_status' => (string) ($manualFinal['status'] ?? ''),
        'confirmed_executed' => false,
        'booking_created' => false,
        'provider_call_executed' => false,
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
