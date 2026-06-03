<?php

if (! function_exists('add_action')) {
    $wpLoad = dirname(__DIR__, 4) . '/wp-load.php';
    if (is_readable($wpLoad)) {
        require_once $wpLoad;
    }
}

use BSP\Quotes\Repository\QuoteRepository;
use BSP\Quotes\Admin\QuoteBuilderRenderer;
use BSP\Quotes\Service\QuoteEventLogger;
use BSP\Quotes\Service\QuoteLineControlStatusService;
use BSP\Quotes\Service\QuoteSendReadinessValidator;

function sbdp_quote_inline_line_status_controls_smoke_fail(string $message): void
{
    if (class_exists('WP_CLI')) {
        WP_CLI::error($message);
    }

    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function sbdp_quote_inline_line_status_controls_smoke_ok(bool $condition, string $message): void
{
    if (! $condition) {
        sbdp_quote_inline_line_status_controls_smoke_fail($message);
    }
}

function sbdp_quote_inline_line_status_controls_smoke_make_quote(
    QuoteRepository $repository,
    array &$created,
    string $suffix,
    int $productId,
    array $availability
): array {
    $request = $repository->createQuoteRequest(array(
        'request_reference' => 'QR-LINE-CTRL-' . $suffix . '-' . gmdate('YmdHis'),
        'status' => 'converted_to_quote',
        'request_summary' => 'Inline line status controls smoke ' . $suffix,
        'requester_name' => 'Smoke Test',
        'requester_email' => 'smoke@example.test',
        'group_size' => 2,
        'preferred_date' => '2026-06-26',
        'source_type' => 'quote_inline_line_status_controls_smoke',
    ));
    $quote = $repository->createQuote(array(
        'quote_request_id' => (int) ($request['id'] ?? 0),
        'quote_reference' => 'Q-LINE-CTRL-' . $suffix . '-' . gmdate('YmdHis'),
        'status' => 'draft',
        'handoff_status' => 'not_ready',
        'review_status' => 'not_started',
        'send_status' => 'not_ready',
    ));
    $version = $repository->createQuoteVersion(array(
        'quote_id' => (int) ($quote['id'] ?? 0),
        'version_number' => 1,
        'status' => 'draft',
        'proposal_title' => 'Inline line control smoke ' . $suffix,
        'proposal_summary' => 'Klantgerichte voorsteltekst voor inline line control smoke.',
        'snapshot_type' => 'operator_build',
        'pricing_confidence' => 'unknown',
        'availability_confidence' => 'unknown',
        'pricing_snapshot_json' => array('source' => 'quote_inline_line_status_controls_smoke'),
    ));

    $quoteId = (int) ($quote['id'] ?? 0);
    $requestId = (int) ($request['id'] ?? 0);
    $versionId = (int) ($version['id'] ?? 0);
    $lines = $repository->replaceQuoteLines($versionId, array(array(
        'line_number' => 1,
        'line_type' => 'product',
        'line_status' => 'mapped',
        'title' => 'Inline line control smoke line',
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

    $repository->updateQuote($quoteId, array('current_version_id' => $versionId));
    $repository->createQuoteMessage(array(
        'quote_id' => $quoteId,
        'quote_version_id' => $versionId,
        'direction' => 'outbound',
        'message_type' => 'proposal',
        'channel' => 'email',
        'status' => 'draft',
        'subject' => 'Inline line control smoke ' . $suffix,
        'body' => 'Klantgerichte voorsteltekst voor inline line control smoke.',
        'body_summary' => 'Klantgerichte voorsteltekst voor inline line control smoke.',
        'to_name' => 'Smoke Test',
        'to_email' => 'smoke@example.test',
        'thread_token' => 'Q-LINE-CTRL-' . $suffix,
    ));

    $created[] = array('request_id' => $requestId, 'quote_id' => $quoteId);

    return array('quote_id' => $quoteId, 'version_id' => $versionId, 'line_id' => (int) ($lines[0]['id'] ?? 0));
}

function sbdp_quote_inline_line_status_controls_smoke_event_count(QuoteRepository $repository, int $quoteId, string $eventType): int
{
    return count(array_filter(
        $repository->listQuoteEvents($quoteId),
        static fn (array $event): bool => (string) ($event['event_type'] ?? '') === $eventType
    ));
}

if (! class_exists(QuoteRepository::class)) {
    sbdp_quote_inline_line_status_controls_smoke_fail('Quote services are not loaded.');
}

global $wpdb;
sbdp_quote_inline_line_status_controls_smoke_ok(isset($wpdb) && $wpdb instanceof wpdb, 'WordPress database is not available.');

$repository = new QuoteRepository();
$events = new QuoteEventLogger($repository);
$lineControls = new QuoteLineControlStatusService($repository, $events);
$validator = new QuoteSendReadinessValidator($repository);
$created = array();
$prefix = $wpdb->prefix;

try {
    $directAvailability = array('bookingMode' => 'direct_internal', 'supplierStatus' => 'not_required');

    $available = sbdp_quote_inline_line_status_controls_smoke_make_quote($repository, $created, 'AVAILABLE', 352, $directAvailability);
    $lineControls->updateStatus((int) $available['quote_id'], (int) $available['line_id'], 'availability', 'confirmed');
    $availableLine = $repository->findQuoteLine((int) $available['line_id']);
    sbdp_quote_inline_line_status_controls_smoke_ok((string) ($availableLine['availability_confidence'] ?? '') === 'confirmed', 'Availability was not confirmed.');
    sbdp_quote_inline_line_status_controls_smoke_ok(sbdp_quote_inline_line_status_controls_smoke_event_count($repository, (int) $available['quote_id'], 'quote_line_availability_updated') === 1, 'Availability event missing.');

    $sameStatusEvents = sbdp_quote_inline_line_status_controls_smoke_event_count($repository, (int) $available['quote_id'], 'quote_line_availability_updated');
    $lineControls->updateStatus((int) $available['quote_id'], (int) $available['line_id'], 'availability', 'confirmed');
    sbdp_quote_inline_line_status_controls_smoke_ok(
        sbdp_quote_inline_line_status_controls_smoke_event_count($repository, (int) $available['quote_id'], 'quote_line_availability_updated') === $sameStatusEvents,
        'Second availability click created a duplicate event.'
    );

    $rejected = sbdp_quote_inline_line_status_controls_smoke_make_quote($repository, $created, 'REJECTED', 352, $directAvailability);
    $lineControls->updateStatus((int) $rejected['quote_id'], (int) $rejected['line_id'], 'availability', 'unavailable');
    $rejectedLine = $repository->findQuoteLine((int) $rejected['line_id']);
    $rejectedInspection = $validator->inspect((int) $rejected['quote_id']);
    sbdp_quote_inline_line_status_controls_smoke_ok((string) ($rejectedLine['line_status'] ?? '') === 'unavailable', 'Rejected line was not marked unavailable.');
    sbdp_quote_inline_line_status_controls_smoke_ok(empty($rejectedInspection['ready']), 'Rejected line did not block send readiness.');
    sbdp_quote_inline_line_status_controls_smoke_ok(sbdp_quote_inline_line_status_controls_smoke_event_count($repository, (int) $rejected['quote_id'], 'quote_line_availability_updated') === 1, 'Rejected availability event missing.');

    $priced = sbdp_quote_inline_line_status_controls_smoke_make_quote($repository, $created, 'PRICED', 352, $directAvailability);
    $lineControls->updateStatus((int) $priced['quote_id'], (int) $priced['line_id'], 'pricing', 'confirmed');
    $pricedLine = $repository->findQuoteLine((int) $priced['line_id']);
    sbdp_quote_inline_line_status_controls_smoke_ok((string) ($pricedLine['pricing_confidence'] ?? '') === 'execution_verified', 'Pricing was not execution_verified.');
    sbdp_quote_inline_line_status_controls_smoke_ok(sbdp_quote_inline_line_status_controls_smoke_event_count($repository, (int) $priced['quote_id'], 'quote_program_line_updated') === 1, 'Pricing event missing.');

    $supplierAvailability = array(
        'bookingMode' => 'supplier_confirmation',
        'supplierProvider' => 'eliio',
        'supplierStatus' => 'supplier_confirmation_required',
    );
    $supplier = sbdp_quote_inline_line_status_controls_smoke_make_quote($repository, $created, 'SUPPLIER', 115, $supplierAvailability);
    $supplierBlocked = false;
    try {
        $lineControls->updateStatus((int) $supplier['quote_id'], (int) $supplier['line_id'], 'availability', 'confirmed');
    } catch (Throwable $exception) {
        $supplierBlocked = str_contains($exception->getMessage(), 'supplier_booking_confirmed');
    }
    $supplierLine = $repository->findQuoteLine((int) $supplier['line_id']);
    sbdp_quote_inline_line_status_controls_smoke_ok($supplierBlocked, 'Supplier/Eliio simple availability confirmation was not blocked.');
    sbdp_quote_inline_line_status_controls_smoke_ok((string) ($supplierLine['availability_confidence'] ?? '') !== 'confirmed', 'Supplier/Eliio line became confirmed.');

    $directRowHtml = QuoteBuilderRenderer::renderQuoteBuildRow(0, $availableLine, array(array('id' => 352, 'title' => 'Smoke product')), (int) $available['quote_id']);
    sbdp_quote_inline_line_status_controls_smoke_ok(str_contains($directRowHtml, 'sbdp-qcd-line-row'), 'Compact line row class missing.');
    sbdp_quote_inline_line_status_controls_smoke_ok(str_contains($directRowHtml, 'sbdp-qcd-line-status-group--availability'), 'Availability segmented control missing.');
    sbdp_quote_inline_line_status_controls_smoke_ok(str_contains($directRowHtml, 'sbdp-qcd-line-status-group--pricing'), 'Pricing chip control missing.');
    sbdp_quote_inline_line_status_controls_smoke_ok(str_contains($directRowHtml, 'sbdp-qcd-line-actions'), 'Secondary action menu missing.');
    sbdp_quote_inline_line_status_controls_smoke_ok(str_contains($directRowHtml, '✓ Beschikbaar'), 'Direct line available chip missing.');
    sbdp_quote_inline_line_status_controls_smoke_ok(str_contains($directRowHtml, '✓ Prijs akkoord'), 'Direct line price chip missing.');

    $supplierRowHtml = QuoteBuilderRenderer::renderQuoteBuildRow(0, $supplierLine, array(array('id' => 115, 'title' => 'Supplier smoke product')), (int) $supplier['quote_id']);
    sbdp_quote_inline_line_status_controls_smoke_ok(str_contains($supplierRowHtml, 'Supplier nodig'), 'Supplier route chip missing.');
    sbdp_quote_inline_line_status_controls_smoke_ok(! str_contains($supplierRowHtml, '✓ Beschikbaar'), 'Supplier line rendered simple availability confirmation.');

    echo wp_json_encode(array(
        'ok' => true,
        'direct_available_confidence' => (string) ($availableLine['availability_confidence'] ?? ''),
        'rejected_line_status' => (string) ($rejectedLine['line_status'] ?? ''),
        'pricing_confidence' => (string) ($pricedLine['pricing_confidence'] ?? ''),
        'supplier_simple_available_blocked' => $supplierBlocked,
        'idempotent_same_status' => true,
        'compact_line_controls_rendered' => true,
        'supplier_line_uses_supplier_route' => true,
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
