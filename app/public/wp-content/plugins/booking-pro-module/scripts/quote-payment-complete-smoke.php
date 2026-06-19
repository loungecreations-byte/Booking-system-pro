<?php

if (! function_exists('add_action')) {
    $wpLoad = dirname(__DIR__, 4) . '/wp-load.php';
    if (is_readable($wpLoad)) {
        require_once $wpLoad;
    }
}

use BSP\Quotes\Repository\QuoteRepository;
use BSP\Quotes\Service\QuoteEventLogger;
use BSP\Quotes\Service\QuoteConfirmationService;
use BSP\Quotes\Service\QuotePaymentSyncService;

function sbdp_quote_payment_smoke_fail(string $message): void
{
    if (class_exists('WP_CLI')) {
        WP_CLI::error($message);
    }

    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function sbdp_quote_payment_smoke_ok(bool $condition, string $message): void
{
    if (! $condition) {
        sbdp_quote_payment_smoke_fail($message);
    }
}

if (! class_exists(QuoteRepository::class) || ! class_exists(QuotePaymentSyncService::class) || ! class_exists(QuoteConfirmationService::class)) {
    sbdp_quote_payment_smoke_fail('Quote payment services are not loaded.');
}
if (! function_exists('wc_create_order') || ! function_exists('wc_get_order')) {
    sbdp_quote_payment_smoke_fail('WooCommerce is not loaded.');
}

global $wpdb;
sbdp_quote_payment_smoke_ok(isset($wpdb) && $wpdb instanceof wpdb, 'WordPress database is not available.');

$repository = new QuoteRepository();
$events = new QuoteEventLogger($repository);
$created = array(
    'request_id' => 0,
    'quote_id' => 0,
    'approved_version_id' => 0,
    'drift_version_id' => 0,
    'order_id' => 0,
);

try {
    $participants = 4;
    $start = gmdate('Y-m-d', strtotime('+31 days')) . 'T14:00:00+00:00';
    $end = gmdate('Y-m-d', strtotime('+31 days')) . 'T16:00:00+00:00';
    $reference = 'Q-PAY-SMOKE-' . gmdate('YmdHis');

    $request = $repository->createQuoteRequest(array(
        'request_reference' => 'QR-PAY-SMOKE-' . gmdate('YmdHis'),
        'status' => 'converted_to_quote',
        'request_summary' => 'Quote payment complete smoke',
        'requester_name' => 'Smoke Test',
        'requester_email' => 'smoke@example.test',
        'group_size' => $participants,
        'preferred_date' => substr($start, 0, 10),
        'preferred_start_time' => '14:00',
        'preferred_end_time' => '16:00',
        'source_type' => 'quote_payment_complete_smoke',
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
        'proposal_title' => 'Quote payment complete smoke',
        'snapshot_type' => 'execution_resnapshot',
        'handoff_payload_json' => array(
            'execution_adapter' => array(
                'adapter_type' => 'cart_order_prep',
                'request_context' => array('group_size' => $participants),
                'items' => array(
                    array(
                        'product_id' => 352,
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
        'proposal_title' => 'Quote payment complete draft',
        'snapshot_type' => 'admin_draft',
        'handoff_payload_json' => array('execution_adapter' => array('adapter_type' => 'must_not_be_used')),
    ));
    $created['drift_version_id'] = (int) ($driftVersion['id'] ?? 0);

    $order = wc_create_order(array('status' => 'pending'));
    sbdp_quote_payment_smoke_ok($order instanceof WC_Order, 'Woo order could not be created.');
    $created['order_id'] = (int) $order->get_id();
    $order->update_meta_data('_sbdp_quote_id', $created['quote_id']);
    $order->update_meta_data('_sbdp_quote_version_id', $created['approved_version_id']);
    $order->update_meta_data('_sbdp_quote_reference', $reference);
    $order->save();

    $repository->updateQuote($created['quote_id'], array(
        'status' => 'accepted',
        'current_version_id' => $created['drift_version_id'],
        'approved_version_id' => $created['approved_version_id'],
        'handoff_status' => 'execution_payload_ready',
        'woo_order_id' => $created['order_id'],
    ));

    $order = wc_get_order($created['order_id']);
    sbdp_quote_payment_smoke_ok($order instanceof WC_Order, 'Woo order was not found.');
    sbdp_quote_payment_smoke_ok((int) $order->get_meta('_sbdp_quote_id') === $created['quote_id'], 'Woo order is missing quote meta.');
    sbdp_quote_payment_smoke_ok((int) $order->get_meta('_sbdp_quote_version_id') === $created['approved_version_id'], 'Woo order is missing approved quote version meta.');

    do_action('woocommerce_payment_complete', $created['order_id']);
    do_action('woocommerce_payment_complete', $created['order_id']);

    $updatedQuote = $repository->findQuote($created['quote_id']);
    sbdp_quote_payment_smoke_ok(is_array($updatedQuote), 'Updated quote was not found.');
    sbdp_quote_payment_smoke_ok((string) ($updatedQuote['status'] ?? '') === 'confirmed', 'Quote status was not confirmed.');
    sbdp_quote_payment_smoke_ok((string) ($updatedQuote['handoff_status'] ?? '') === QuotePaymentSyncService::COMPLETED_STATUS, 'Quote handoff_status was not updated.');
    sbdp_quote_payment_smoke_ok((int) ($updatedQuote['approved_version_id'] ?? 0) === $created['approved_version_id'], 'Quote approved_version_id changed.');
    sbdp_quote_payment_smoke_ok((int) ($updatedQuote['woo_order_id'] ?? 0) === $created['order_id'], 'Quote woo_order_id does not match order.');

    $paymentEvents = array_values(array_filter(
        $repository->listQuoteEvents($created['quote_id']),
        static fn (array $event): bool => (string) ($event['event_type'] ?? '') === QuotePaymentSyncService::COMPLETED_EVENT
    ));
    sbdp_quote_payment_smoke_ok(count($paymentEvents) === 1, 'Payment complete event should be logged exactly once.');
    $confirmationEvents = array_values(array_filter(
        $repository->listQuoteEvents($created['quote_id']),
        static fn (array $event): bool => (string) ($event['event_type'] ?? '') === QuoteConfirmationService::CONFIRMED_EVENT
    ));
    sbdp_quote_payment_smoke_ok(count($confirmationEvents) === 1, 'Quote confirmation event should be logged exactly once.');

    $payload = is_array($paymentEvents[0]['payload_json'] ?? null) ? $paymentEvents[0]['payload_json'] : array();
    sbdp_quote_payment_smoke_ok((int) ($payload['quote_id'] ?? 0) === $created['quote_id'], 'Event payload is missing quote_id.');
    sbdp_quote_payment_smoke_ok((int) ($payload['order_id'] ?? 0) === $created['order_id'], 'Event payload is missing order_id.');
    sbdp_quote_payment_smoke_ok((int) ($payload['approved_version_id'] ?? 0) === $created['approved_version_id'], 'Event payload is missing approved_version_id.');

    echo wp_json_encode(array(
        'ok' => true,
        'quote_id' => $created['quote_id'],
        'approved_version_id' => $created['approved_version_id'],
        'current_version_id' => $created['drift_version_id'],
        'woo_order_id' => $created['order_id'],
        'handoff_status' => (string) ($updatedQuote['handoff_status'] ?? ''),
        'quote_status' => (string) ($updatedQuote['status'] ?? ''),
        'payment_event_count' => count($paymentEvents),
        'confirmation_event_count' => count($confirmationEvents),
        'invoice_available' => (bool) ($payload['invoice_available'] ?? false),
        'invoice_number' => (string) ($payload['invoice_number'] ?? ''),
        'invoice_generated_at' => (string) ($payload['invoice_generated_at'] ?? ''),
        'order_status' => $order->get_status(),
        'real_payment_executed' => false,
    ), JSON_PRETTY_PRINT) . PHP_EOL;
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
        $wpdb->delete($prefix . 'bsp_quote_lines', array('quote_version_id' => $created['approved_version_id']));
        $wpdb->delete($prefix . 'bsp_quote_lines', array('quote_version_id' => $created['drift_version_id']));
        $wpdb->delete($prefix . 'bsp_quote_versions', array('quote_id' => $created['quote_id']));
        $wpdb->delete($prefix . 'bsp_quotes', array('id' => $created['quote_id']));
    }
    if (isset($wpdb) && $wpdb instanceof wpdb && $created['request_id'] > 0) {
        $wpdb->delete($wpdb->prefix . 'bsp_quote_requests', array('id' => $created['request_id']));
    }
}
