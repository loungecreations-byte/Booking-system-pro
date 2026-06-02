<?php

use BSP\Quotes\Repository\QuoteRepository;
use BSP\Quotes\Service\QuoteEventLogger;
use BSP\Quotes\Service\QuoteRequestOrderBridgeService;

function sbdp_quote_smoke_fail(string $message): void
{
    if (class_exists('WP_CLI')) {
        WP_CLI::error($message);
    }

    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function sbdp_quote_smoke_ok(bool $condition, string $message): void
{
    if (! $condition) {
        sbdp_quote_smoke_fail($message);
    }
}

if (! class_exists(QuoteRepository::class) || ! class_exists(QuoteRequestOrderBridgeService::class)) {
    sbdp_quote_smoke_fail('Quote services are not loaded.');
}
if (! function_exists('wc_get_product') || ! function_exists('wc_get_order')) {
    sbdp_quote_smoke_fail('WooCommerce is not loaded.');
}
if (! function_exists('WPO_WCPDF') && ! class_exists('\\WPO\\IPS\\Documents\\Invoice')) {
    sbdp_quote_smoke_fail('PDF Invoices & Packing Slips runtime is not loaded.');
}

global $wpdb;
sbdp_quote_smoke_ok(isset($wpdb) && $wpdb instanceof wpdb, 'WordPress database is not available.');

$productId = (int) (getenv('SBDP_QUOTE_SMOKE_PRODUCT_ID') ?: 352);
$product = wc_get_product($productId);
if (! $product) {
    $products = function_exists('wc_get_products') ? wc_get_products(array('limit' => 1, 'status' => 'publish')) : array();
    $product = is_array($products) && isset($products[0]) ? $products[0] : null;
    $productId = $product && method_exists($product, 'get_id') ? (int) $product->get_id() : 0;
}
sbdp_quote_smoke_ok($productId > 0 && $product, 'No WooCommerce product available for smoke.');

$repository = new QuoteRepository();
$events = new QuoteEventLogger($repository);
$bridge = new QuoteRequestOrderBridgeService($repository, $events);
$created = array(
    'request_id' => 0,
    'quote_id' => 0,
    'approved_version_id' => 0,
    'drift_version_id' => 0,
    'order_id' => 0,
);

try {
    $participants = 4;
    $start = gmdate('Y-m-d', strtotime('+30 days')) . 'T14:00:00+00:00';
    $end = gmdate('Y-m-d', strtotime('+30 days')) . 'T16:00:00+00:00';
    $reference = 'Q-SMOKE-' . gmdate('YmdHis');

    $request = $repository->createQuoteRequest(array(
        'request_reference' => 'QR-SMOKE-' . gmdate('YmdHis'),
        'status' => 'converted_to_quote',
        'request_summary' => 'Quote order invoice smoke',
        'requester_name' => 'Smoke Test',
        'requester_email' => 'smoke@example.test',
        'group_size' => $participants,
        'preferred_date' => substr($start, 0, 10),
        'preferred_start_time' => '14:00',
        'preferred_end_time' => '16:00',
        'source_type' => 'quote_order_invoice_smoke',
    ));
    $created['request_id'] = (int) ($request['id'] ?? 0);

    $quote = $repository->createQuote(array(
        'quote_request_id' => $created['request_id'],
        'quote_reference' => $reference,
        'status' => 'draft',
        'review_status' => 'approved',
        'send_status' => 'sent_manual',
        'handoff_status' => 'execution_payload_ready',
    ));
    $created['quote_id'] = (int) ($quote['id'] ?? 0);

    $approvedVersion = $repository->createQuoteVersion(array(
        'quote_id' => $created['quote_id'],
        'version_number' => 1,
        'status' => 'draft',
        'proposal_title' => 'Quote order invoice smoke',
        'snapshot_type' => 'execution_resnapshot',
        'handoff_payload_json' => array(
            'execution_adapter' => array(
                'adapter_type' => 'cart_order_prep',
                'request_context' => array('group_size' => $participants),
                'items' => array(
                    array(
                        'product_id' => $productId,
                        'start' => $start,
                        'end' => $end,
                        'participants' => $participants,
                    ),
                ),
            ),
        ),
    ));
    $created['approved_version_id'] = (int) ($approvedVersion['id'] ?? 0);

    $driftVersion = $repository->createQuoteVersion(array(
        'quote_id' => $created['quote_id'],
        'version_number' => 2,
        'status' => 'draft',
        'proposal_title' => 'Quote order invoice smoke draft',
        'snapshot_type' => 'admin_draft',
        'handoff_payload_json' => array(
            'execution_adapter' => array(
                'adapter_type' => 'current_version_must_not_be_used',
                'items' => array(),
            ),
        ),
    ));
    $created['drift_version_id'] = (int) ($driftVersion['id'] ?? 0);

    $quote = $repository->updateQuote($created['quote_id'], array(
        'status' => 'accepted',
        'current_version_id' => $created['drift_version_id'],
        'approved_version_id' => $created['approved_version_id'],
        'handoff_status' => 'execution_payload_ready',
    ));

    $result = $bridge->createWooRequestOrder($created['quote_id']);
    $created['order_id'] = (int) ($result['woo_order_id'] ?? 0);
    sbdp_quote_smoke_ok($created['order_id'] > 0, 'Bridge did not return a Woo order ID.');
    sbdp_quote_smoke_ok((int) ($result['quote_version_id'] ?? 0) === $created['approved_version_id'], 'Bridge did not use approved_version_id.');

    $updatedQuote = $repository->findQuote($created['quote_id']);
    sbdp_quote_smoke_ok(is_array($updatedQuote), 'Updated quote was not found.');
    sbdp_quote_smoke_ok((string) ($updatedQuote['status'] ?? '') === 'accepted', 'Quote status is not accepted.');
    sbdp_quote_smoke_ok((int) ($updatedQuote['approved_version_id'] ?? 0) === $created['approved_version_id'], 'Quote approved_version_id is not pinned.');
    sbdp_quote_smoke_ok((int) ($updatedQuote['woo_order_id'] ?? 0) === $created['order_id'], 'Quote woo_order_id was not stored.');

    $order = wc_get_order($created['order_id']);
    sbdp_quote_smoke_ok($order instanceof WC_Order, 'Woo order was not found.');
    sbdp_quote_smoke_ok((int) $order->get_meta('_sbdp_quote_id') === $created['quote_id'], 'Woo order is missing _sbdp_quote_id.');
    sbdp_quote_smoke_ok((int) $order->get_meta('_sbdp_quote_version_id') === $created['approved_version_id'], 'Woo order is missing approved quote version meta.');
    sbdp_quote_smoke_ok($order->get_status() === 'on-hold', 'Smoke should not mark payment complete.');

    $document = null;
    if (function_exists('wpo_wcpdf_get_document')) {
        $document = wpo_wcpdf_get_document('invoice', $order);
    }
    if (! is_object($document) && function_exists('WPO_WCPDF')) {
        $document = WPO_WCPDF()->documents->get_document('invoice', $order);
    }
    if (! is_object($document) && class_exists('\\WPO\\IPS\\Documents\\Invoice')) {
        $document = new \WPO\IPS\Documents\Invoice($order);
    }
    sbdp_quote_smoke_ok(is_object($document), 'Invoice document could not be created.');
    if (method_exists($document, 'init')) {
        $document->init();
    }

    $invoiceNumber = method_exists($document, 'get_number') ? (string) $document->get_number('', null, 'view', true) : '';
    $pdf = method_exists($document, 'get_pdf') ? (string) $document->get_pdf() : '';
    sbdp_quote_smoke_ok($invoiceNumber !== '' || method_exists($document, 'get_number'), 'Invoice number metadata is not available.');
    sbdp_quote_smoke_ok($pdf !== '', 'Invoice PDF data is not available.');

    $payload = array(
        'ok' => true,
        'quote_id' => $created['quote_id'],
        'approved_version_id' => $created['approved_version_id'],
        'current_version_id' => $created['drift_version_id'],
        'woo_order_id' => $created['order_id'],
        'order_quote_id' => (int) $order->get_meta('_sbdp_quote_id'),
        'order_quote_version_id' => (int) $order->get_meta('_sbdp_quote_version_id'),
        'order_status' => $order->get_status(),
        'invoice_number' => $invoiceNumber,
        'pdf_bytes' => strlen($pdf),
        'payment_completed' => false,
    );

    echo wp_json_encode($payload, JSON_PRETTY_PRINT) . PHP_EOL;
} finally {
    if ($created['order_id'] > 0) {
        $order = wc_get_order($created['order_id']);
        if ($order instanceof WC_Order) {
            $order->delete(true);
        }
    }
    if (isset($wpdb) && $wpdb instanceof wpdb && $created['quote_id'] > 0) {
        $prefix = $wpdb->prefix;
        $wpdb->delete($prefix . 'bsp_quote_events', array('quote_id' => $created['quote_id']));
        $wpdb->delete($prefix . 'bsp_quote_messages', array('quote_id' => $created['quote_id']));
        $wpdb->delete($prefix . 'bsp_quote_followups', array('quote_id' => $created['quote_id']));
        $wpdb->delete($prefix . 'bsp_quote_assumptions', array('quote_id' => $created['quote_id']));
        if ($created['approved_version_id'] > 0) {
            $wpdb->delete($prefix . 'bsp_quote_lines', array('quote_version_id' => $created['approved_version_id']));
        }
        if ($created['drift_version_id'] > 0) {
            $wpdb->delete($prefix . 'bsp_quote_lines', array('quote_version_id' => $created['drift_version_id']));
        }
        $wpdb->delete($prefix . 'bsp_quote_versions', array('quote_id' => $created['quote_id']));
        $wpdb->delete($prefix . 'bsp_quotes', array('id' => $created['quote_id']));
    }
    if (isset($wpdb) && $wpdb instanceof wpdb && $created['request_id'] > 0) {
        $wpdb->delete($wpdb->prefix . 'bsp_quote_requests', array('id' => $created['request_id']));
    }
}
