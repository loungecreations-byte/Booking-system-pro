<?php

if (! function_exists('add_action')) {
    $wpLoad = dirname(__DIR__, 4) . '/wp-load.php';
    if (is_readable($wpLoad)) {
        require_once $wpLoad;
    }
}

use BSP\Quotes\Repository\QuoteRepository;
use BSP\Quotes\Service\QuoteEventLogger;
use BSP\Quotes\Service\QuoteFollowupService;
use BSP\Quotes\Service\QuoteLineControlStatusService;
use BSP\Quotes\Service\QuoteReviewService;
use BSP\Quotes\Service\QuoteSendReadinessValidator;

function sbdp_quote_inline_review_controls_smoke_fail(string $message): void
{
    if (class_exists('WP_CLI')) {
        WP_CLI::error($message);
    }

    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function sbdp_quote_inline_review_controls_smoke_ok(bool $condition, string $message): void
{
    if (! $condition) {
        sbdp_quote_inline_review_controls_smoke_fail($message);
    }
}

function sbdp_quote_inline_review_controls_smoke_make_quote(
    QuoteRepository $repository,
    array &$created,
    string $suffix,
    int $productId,
    array $availability,
    bool $withProposalText = true
): array {
    $request = $repository->createQuoteRequest(array(
        'request_reference' => 'QR-INLINE-REVIEW-' . $suffix . '-' . gmdate('YmdHis'),
        'status' => 'converted_to_quote',
        'request_summary' => 'Inline review controls smoke ' . $suffix,
        'requester_name' => 'Smoke Test',
        'requester_email' => 'smoke@example.test',
        'group_size' => 2,
        'preferred_date' => '2026-06-26',
        'source_type' => 'quote_inline_review_controls_smoke',
    ));
    $quote = $repository->createQuote(array(
        'quote_request_id' => (int) ($request['id'] ?? 0),
        'quote_reference' => 'Q-INLINE-REVIEW-' . $suffix . '-' . gmdate('YmdHis'),
        'status' => 'draft',
        'handoff_status' => 'not_ready',
        'review_status' => 'not_started',
        'send_status' => 'not_ready',
    ));
    $version = $repository->createQuoteVersion(array(
        'quote_id' => (int) ($quote['id'] ?? 0),
        'version_number' => 1,
        'status' => 'draft',
        'proposal_title' => $withProposalText ? 'Inline review smoke ' . $suffix : '',
        'proposal_summary' => $withProposalText ? 'Klantgerichte voorsteltekst voor inline review smoke.' : '',
        'snapshot_type' => 'operator_build',
        'pricing_confidence' => 'unknown',
        'availability_confidence' => 'unknown',
        'pricing_snapshot_json' => array('source' => 'quote_inline_review_controls_smoke'),
    ));

    $quoteId = (int) ($quote['id'] ?? 0);
    $requestId = (int) ($request['id'] ?? 0);
    $versionId = (int) ($version['id'] ?? 0);
    $lines = $repository->replaceQuoteLines($versionId, array(array(
        'line_number' => 1,
        'line_type' => 'product',
        'line_status' => 'mapped',
        'title' => 'Inline review smoke line',
        'product_id' => $productId,
        'quantity' => 2,
        'participants' => 2,
        'service_date' => '2026-06-26',
        'start_time' => '11:00',
        'end_time' => '12:30',
        'pricing_confidence' => 'unknown',
        'availability_confidence' => 'unknown',
        'unit_amount_snapshot' => 10,
        'line_total_snapshot' => 20,
        'currency' => get_woocommerce_currency(),
        'availability_snapshot_json' => $availability,
    )));
    $line = $lines[0] ?? array();

    $repository->updateQuote($quoteId, array(
        'current_version_id' => $versionId,
    ));
    if ($withProposalText) {
        $repository->createQuoteMessage(array(
            'quote_id' => $quoteId,
            'quote_version_id' => $versionId,
            'direction' => 'outbound',
            'message_type' => 'proposal',
            'channel' => 'email',
            'status' => 'draft',
            'subject' => 'Inline review smoke ' . $suffix,
            'body' => 'Klantgerichte voorsteltekst voor inline review smoke.',
            'body_summary' => 'Klantgerichte voorsteltekst voor inline review smoke.',
            'to_name' => 'Smoke Test',
            'to_email' => 'smoke@example.test',
            'thread_token' => 'Q-INLINE-REVIEW-' . $suffix,
        ));
    }

    $created[] = array(
        'request_id' => $requestId,
        'quote_id' => $quoteId,
    );

    return array('quote_id' => $quoteId, 'version_id' => $versionId, 'line_id' => (int) ($line['id'] ?? 0));
}

function sbdp_quote_inline_review_controls_smoke_event_count(QuoteRepository $repository, int $quoteId, string $eventType): int
{
    return count(array_filter(
        $repository->listQuoteEvents($quoteId),
        static fn (array $event): bool => (string) ($event['event_type'] ?? '') === $eventType
    ));
}

if (! class_exists(QuoteRepository::class)) {
    sbdp_quote_inline_review_controls_smoke_fail('Quote services are not loaded.');
}

global $wpdb;
sbdp_quote_inline_review_controls_smoke_ok(isset($wpdb) && $wpdb instanceof wpdb, 'WordPress database is not available.');

$repository = new QuoteRepository();
$events = new QuoteEventLogger($repository);
$lineControls = new QuoteLineControlStatusService($repository, $events);
$review = new QuoteReviewService($repository, $events, new QuoteFollowupService($repository, $events));
$validator = new QuoteSendReadinessValidator($repository);
$created = array();
$prefix = $wpdb->prefix;

try {
    $directAvailability = array('bookingMode' => 'direct_internal', 'supplierStatus' => 'not_required');
    $direct = sbdp_quote_inline_review_controls_smoke_make_quote($repository, $created, 'DIRECT', 352, $directAvailability, true);
    $lineControls->updateStatus((int) $direct['quote_id'], (int) $direct['line_id'], 'availability', 'confirmed');
    $lineControls->updateStatus((int) $direct['quote_id'], (int) $direct['line_id'], 'pricing', 'confirmed');
    $directInspection = $validator->inspect((int) $direct['quote_id']);
    sbdp_quote_inline_review_controls_smoke_ok(! empty($directInspection['ready']), 'Direct quote should be send-ready after inline controls.');
    $review->approve((int) $direct['quote_id']);
    $approved = $repository->findQuote((int) $direct['quote_id']);
    sbdp_quote_inline_review_controls_smoke_ok((string) ($approved['review_status'] ?? '') === 'approved', 'Review status was not approved.');
    sbdp_quote_inline_review_controls_smoke_ok((string) ($approved['send_status'] ?? '') === 'ready_to_send', 'Send status was not ready_to_send.');
    sbdp_quote_inline_review_controls_smoke_ok((string) ($approved['status'] ?? '') === 'reviewed', 'Quote status was not reviewed.');
    $reviewEventCount = sbdp_quote_inline_review_controls_smoke_event_count($repository, (int) $direct['quote_id'], 'quote_review_approved');
    sbdp_quote_inline_review_controls_smoke_ok($reviewEventCount === 1, 'Review approval event missing.');
    $review->approve((int) $direct['quote_id']);
    sbdp_quote_inline_review_controls_smoke_ok(
        sbdp_quote_inline_review_controls_smoke_event_count($repository, (int) $direct['quote_id'], 'quote_review_approved') === $reviewEventCount,
        'Second review approval created a duplicate event.'
    );

    $supplierAvailability = array(
        'bookingMode' => 'supplier_confirmation',
        'supplierProvider' => 'eliio',
        'supplierStatus' => 'supplier_confirmation_required',
    );
    $supplier = sbdp_quote_inline_review_controls_smoke_make_quote($repository, $created, 'SUPPLIER', 115, $supplierAvailability, true);
    $supplierBlocked = false;
    try {
        $lineControls->updateStatus((int) $supplier['quote_id'], (int) $supplier['line_id'], 'availability', 'confirmed');
    } catch (Throwable $exception) {
        $supplierBlocked = str_contains($exception->getMessage(), 'supplier_booking_confirmed');
    }
    sbdp_quote_inline_review_controls_smoke_ok($supplierBlocked, 'Supplier/Eliio line was allowed to use simple availability confirmation.');
    $supplierLine = $repository->findQuoteLine((int) $supplier['line_id']);
    sbdp_quote_inline_review_controls_smoke_ok((string) ($supplierLine['availability_confidence'] ?? '') !== 'confirmed', 'Supplier line became availability confirmed.');
    $supplierApproveBlocked = false;
    try {
        $review->approve((int) $supplier['quote_id']);
    } catch (Throwable $exception) {
        $supplierApproveBlocked = true;
    }
    sbdp_quote_inline_review_controls_smoke_ok($supplierApproveBlocked, 'Supplier quote was approved without supplier confirmation.');

    $missingText = sbdp_quote_inline_review_controls_smoke_make_quote($repository, $created, 'MISSING-TEXT', 352, $directAvailability, false);
    $lineControls->updateStatus((int) $missingText['quote_id'], (int) $missingText['line_id'], 'availability', 'confirmed');
    $lineControls->updateStatus((int) $missingText['quote_id'], (int) $missingText['line_id'], 'pricing', 'confirmed');
    $missingTextBlocked = false;
    try {
        $review->approve((int) $missingText['quote_id']);
    } catch (Throwable $exception) {
        $missingTextBlocked = str_contains($exception->getMessage(), 'voorsteltekst');
    }
    $missingTextQuote = $repository->findQuote((int) $missingText['quote_id']);
    sbdp_quote_inline_review_controls_smoke_ok($missingTextBlocked, 'Missing proposal text did not block review approval.');
    sbdp_quote_inline_review_controls_smoke_ok((string) ($missingTextQuote['review_status'] ?? '') !== 'approved', 'Missing proposal text quote was approved.');

    echo wp_json_encode(array(
        'ok' => true,
        'direct_review_status' => (string) ($approved['review_status'] ?? ''),
        'direct_send_status' => (string) ($approved['send_status'] ?? ''),
        'direct_quote_status' => (string) ($approved['status'] ?? ''),
        'direct_review_events' => $reviewEventCount,
        'supplier_simple_availability_blocked' => $supplierBlocked,
        'supplier_review_blocked' => $supplierApproveBlocked,
        'missing_proposal_text_blocked' => $missingTextBlocked,
        'provider_call_executed' => false,
        'eliio_call_executed' => false,
        'supplier_confirmation_executed' => false,
        'email_sent' => false,
        'payment_booking_execution_changed' => false,
    ), JSON_PRETTY_PRINT) . PHP_EOL;
} finally {
    foreach (array_reverse($created) as $row) {
        $quoteId = (int) ($row['quote_id'] ?? 0);
        $requestId = (int) ($row['request_id'] ?? 0);
        if ($quoteId > 0) {
            $wpdb->delete($prefix . 'bsp_quote_events', array('quote_id' => $quoteId));
            $wpdb->delete($prefix . 'bsp_quote_messages', array('quote_id' => $quoteId));
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
