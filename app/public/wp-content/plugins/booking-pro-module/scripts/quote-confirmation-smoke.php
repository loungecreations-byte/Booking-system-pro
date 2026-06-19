<?php

if (! function_exists('add_action')) {
    $wpLoad = dirname(__DIR__, 4) . '/wp-load.php';
    if (is_readable($wpLoad)) {
        require_once $wpLoad;
    }
}

use BSP\Quotes\Repository\QuoteRepository;
use BSP\Quotes\Service\QuoteConfirmationService;
use BSP\Quotes\Service\QuoteEventLogger;
use BSP\Quotes\Service\QuotePaymentSyncService;

function sbdp_quote_confirmation_smoke_fail(string $message): void
{
    if (class_exists('WP_CLI')) {
        WP_CLI::error($message);
    }

    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function sbdp_quote_confirmation_smoke_ok(bool $condition, string $message): void
{
    if (! $condition) {
        sbdp_quote_confirmation_smoke_fail($message);
    }
}

if (! class_exists(QuoteRepository::class) || ! class_exists(QuoteConfirmationService::class)) {
    sbdp_quote_confirmation_smoke_fail('Quote confirmation services are not loaded.');
}
if (! function_exists('wc_create_order') || ! function_exists('wc_get_order')) {
    sbdp_quote_confirmation_smoke_fail('WooCommerce is not loaded.');
}

global $wpdb;
sbdp_quote_confirmation_smoke_ok(isset($wpdb) && $wpdb instanceof wpdb, 'WordPress database is not available.');

$repository = new QuoteRepository();
$events = new QuoteEventLogger($repository);
$service = new QuoteConfirmationService($repository, $events);
$created = array(
    'request_id' => 0,
    'quote_id' => 0,
    'approved_version_id' => 0,
    'order_id' => 0,
);

try {
    $reference = 'Q-CONF-SMOKE-' . gmdate('YmdHis');
    $request = $repository->createQuoteRequest(array(
        'request_reference' => 'QR-CONF-SMOKE-' . gmdate('YmdHis'),
        'status' => 'converted_to_quote',
        'request_summary' => 'Quote confirmation smoke',
        'requester_name' => 'Smoke Test',
        'requester_email' => 'smoke@example.test',
        'group_size' => 4,
        'source_type' => 'quote_confirmation_smoke',
    ));
    $created['request_id'] = (int) ($request['id'] ?? 0);

    $quote = $repository->createQuote(array(
        'quote_request_id' => $created['request_id'],
        'quote_reference' => $reference,
        'status' => 'accepted',
        'review_status' => 'approved',
        'send_status' => 'sent_manual',
        'handoff_status' => QuotePaymentSyncService::COMPLETED_STATUS,
    ));
    $created['quote_id'] = (int) ($quote['id'] ?? 0);

    $version = $repository->createQuoteVersion(array(
        'quote_id' => $created['quote_id'],
        'version_number' => 1,
        'status' => 'draft',
        'proposal_title' => 'Quote confirmation smoke',
        'snapshot_type' => 'execution_resnapshot',
    ));
    $created['approved_version_id'] = (int) ($version['id'] ?? 0);

    $order = wc_create_order(array('status' => 'pending'));
    sbdp_quote_confirmation_smoke_ok($order instanceof WC_Order, 'Woo order could not be created.');
    $created['order_id'] = (int) $order->get_id();
    $order->update_meta_data('_sbdp_quote_id', $created['quote_id']);
    $order->update_meta_data('_sbdp_quote_version_id', $created['approved_version_id']);
    $order->save();

    $repository->updateQuote($created['quote_id'], array(
        'approved_version_id' => $created['approved_version_id'],
        'woo_order_id' => $created['order_id'],
    ));

    $events->log(
        QuotePaymentSyncService::COMPLETED_EVENT,
        $created['request_id'],
        $created['quote_id'],
        $created['approved_version_id'],
        null,
        'Smoke payment complete event.',
        array(
            'quote_id' => $created['quote_id'],
            'order_id' => $created['order_id'],
            'approved_version_id' => $created['approved_version_id'],
            'quote_reference' => $reference,
            'transaction_id' => '',
            'invoice_available' => false,
            'invoice_number' => '',
            'invoice_generated_at' => '',
        )
    );

    $initialQuote = $repository->findQuote($created['quote_id']);
    sbdp_quote_confirmation_smoke_ok(is_array($initialQuote), 'Initial quote was not found.');
    sbdp_quote_confirmation_smoke_ok((string) ($initialQuote['status'] ?? '') === 'accepted', 'Quote should start accepted.');
    sbdp_quote_confirmation_smoke_ok((string) ($initialQuote['handoff_status'] ?? '') === QuotePaymentSyncService::COMPLETED_STATUS, 'Quote should start payment completed.');
    sbdp_quote_confirmation_smoke_ok((int) ($initialQuote['approved_version_id'] ?? 0) === $created['approved_version_id'], 'Approved version is not pinned.');
    sbdp_quote_confirmation_smoke_ok((int) ($initialQuote['woo_order_id'] ?? 0) === $created['order_id'], 'Quote woo_order_id mismatch.');
    sbdp_quote_confirmation_smoke_ok((int) $order->get_meta('_sbdp_quote_id') === $created['quote_id'], 'Order quote id meta mismatch.');
    sbdp_quote_confirmation_smoke_ok((int) $order->get_meta('_sbdp_quote_version_id') === $created['approved_version_id'], 'Order quote version meta mismatch.');

    $service->handlePaymentComplete($created['order_id']);
    $service->handlePaymentComplete($created['order_id']);

    $finalQuote = $repository->findQuote($created['quote_id']);
    sbdp_quote_confirmation_smoke_ok(is_array($finalQuote), 'Final quote was not found.');

    $paymentEvents = array_values(array_filter(
        $repository->listQuoteEvents($created['quote_id']),
        static function (array $event) use ($created): bool {
            $payload = is_array($event['payload_json'] ?? null) ? $event['payload_json'] : array();
            return (string) ($event['event_type'] ?? '') === QuotePaymentSyncService::COMPLETED_EVENT
                && (int) ($payload['order_id'] ?? 0) === $created['order_id'];
        }
    ));
    $confirmationEvents = array_values(array_filter(
        $repository->listQuoteEvents($created['quote_id']),
        static function (array $event) use ($created): bool {
            $payload = is_array($event['payload_json'] ?? null) ? $event['payload_json'] : array();
            return (string) ($event['event_type'] ?? '') === QuoteConfirmationService::CONFIRMED_EVENT
                && (int) ($payload['order_id'] ?? 0) === $created['order_id'];
        }
    ));

    sbdp_quote_confirmation_smoke_ok((string) ($finalQuote['status'] ?? '') === 'confirmed', 'Quote status was not confirmed.');
    sbdp_quote_confirmation_smoke_ok((string) ($finalQuote['handoff_status'] ?? '') === QuotePaymentSyncService::COMPLETED_STATUS, 'Handoff status changed unexpectedly.');
    sbdp_quote_confirmation_smoke_ok(count($paymentEvents) === 1, 'Payment event count should be exactly one.');
    sbdp_quote_confirmation_smoke_ok(count($confirmationEvents) === 1, 'Confirmation event count should be exactly one.');

    echo wp_json_encode(array(
        'ok' => true,
        'initial_quote_status' => (string) ($initialQuote['status'] ?? ''),
        'initial_handoff_status' => (string) ($initialQuote['handoff_status'] ?? ''),
        'final_quote_status' => (string) ($finalQuote['status'] ?? ''),
        'final_handoff_status' => (string) ($finalQuote['handoff_status'] ?? ''),
        'payment_event_count' => count($paymentEvents),
        'confirmation_event_count' => count($confirmationEvents),
        'order_id_match' => (int) ($finalQuote['woo_order_id'] ?? 0) === $created['order_id'],
        'version_match' => (int) $order->get_meta('_sbdp_quote_version_id') === (int) ($finalQuote['approved_version_id'] ?? 0),
        'real_payment_executed' => false,
        'provider_booking_created' => false,
        'email_sent' => false,
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
        $wpdb->delete($prefix . 'bsp_quote_versions', array('quote_id' => $created['quote_id']));
        $wpdb->delete($prefix . 'bsp_quotes', array('id' => $created['quote_id']));
    }
    if (isset($wpdb) && $wpdb instanceof wpdb && $created['request_id'] > 0) {
        $wpdb->delete($wpdb->prefix . 'bsp_quote_requests', array('id' => $created['request_id']));
    }
}
