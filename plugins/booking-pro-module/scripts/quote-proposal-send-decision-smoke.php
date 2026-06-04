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
use BSP\Quotes\Service\QuoteProposalSendDecisionService;
use BSP\Quotes\Service\QuoteReviewService;
use BSP\Quotes\Service\QuoteSendReadinessValidator;

function sbdp_quote_proposal_send_decision_smoke_fail(string $message): void
{
    if (class_exists('WP_CLI')) {
        WP_CLI::error($message);
    }

    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function sbdp_quote_proposal_send_decision_smoke_ok(bool $condition, string $message): void
{
    if (! $condition) {
        sbdp_quote_proposal_send_decision_smoke_fail($message);
    }
}

function sbdp_quote_proposal_send_decision_smoke_event_count(QuoteRepository $repository, int $quoteId, string $eventType): int
{
    return count(array_filter(
        $repository->listQuoteEvents($quoteId),
        static fn (array $event): bool => (string) ($event['event_type'] ?? '') === $eventType
    ));
}

function sbdp_quote_proposal_send_decision_smoke_make_quote(
    QuoteRepository $repository,
    array &$created,
    string $suffix,
    string $reviewStatus,
    string $sendStatus,
    string $email,
    bool $withProposalText,
    int $productId = 0,
    array $availability = array()
): array {
    $request = $repository->createQuoteRequest(array(
        'request_reference' => 'QR-PROPOSAL-SEND-' . $suffix . '-' . gmdate('YmdHis'),
        'status' => 'converted_to_quote',
        'request_summary' => 'Quote proposal send decision smoke ' . $suffix,
        'requester_name' => 'Smoke Test',
        'requester_email' => $email,
        'group_size' => 2,
        'preferred_date' => '2026-06-26',
        'source_type' => 'quote_proposal_send_decision_smoke',
    ));
    $quote = $repository->createQuote(array(
        'quote_request_id' => (int) ($request['id'] ?? 0),
        'quote_reference' => 'Q-PROPOSAL-SEND-' . $suffix . '-' . gmdate('YmdHis'),
        'status' => $reviewStatus === 'approved' ? 'reviewed' : 'draft',
        'handoff_status' => 'not_ready',
        'review_status' => $reviewStatus,
        'send_status' => $sendStatus,
    ));
    $version = $repository->createQuoteVersion(array(
        'quote_id' => (int) ($quote['id'] ?? 0),
        'version_number' => 1,
        'status' => $reviewStatus === 'approved' ? 'approved' : 'draft',
        'proposal_title' => $withProposalText ? 'Voorstel smoke ' . $suffix : '',
        'proposal_summary' => $withProposalText ? 'Klantgerichte voorsteltekst voor proposal send decision smoke.' : '',
        'snapshot_type' => 'operator_build',
        'pricing_confidence' => 'unknown',
        'availability_confidence' => 'unknown',
        'pricing_snapshot_json' => array('source' => 'quote_proposal_send_decision_smoke'),
    ));

    $quoteId = (int) ($quote['id'] ?? 0);
    $requestId = (int) ($request['id'] ?? 0);
    $versionId = (int) ($version['id'] ?? 0);
    $lines = $repository->replaceQuoteLines($versionId, array(array(
        'line_number' => 1,
        'line_type' => 'product',
        'line_status' => 'mapped',
        'title' => 'Proposal send decision smoke line',
        'product_id' => $productId > 0 ? $productId : null,
        'quantity' => 2,
        'participants' => 2,
        'service_date' => '2026-06-26',
        'start_time' => '11:00',
        'end_time' => '12:30',
        'pricing_confidence' => 'unknown',
        'availability_confidence' => 'unknown',
        'unit_amount_snapshot' => 10,
        'line_total_snapshot' => 20,
        'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR',
        'availability_snapshot_json' => $availability,
    )));
    $line = $lines[0] ?? array();

    $repository->updateQuote($quoteId, array('current_version_id' => $versionId));
    if ($withProposalText) {
        $repository->createQuoteMessage(array(
            'quote_id' => $quoteId,
            'quote_version_id' => $versionId,
            'direction' => 'outbound',
            'message_type' => 'proposal',
            'channel' => 'email',
            'status' => 'draft',
            'subject' => 'Voorstel smoke ' . $suffix,
            'body' => 'Klantgerichte voorsteltekst voor proposal send decision smoke.',
            'body_summary' => 'Klantgerichte voorsteltekst voor proposal send decision smoke.',
            'to_name' => 'Smoke Test',
            'to_email' => $email,
            'thread_token' => 'Q-PROPOSAL-SEND-' . $suffix,
        ));
    }

    $created[] = array('request_id' => $requestId, 'quote_id' => $quoteId);

    return array('quote_id' => $quoteId, 'version_id' => $versionId, 'line_id' => (int) ($line['id'] ?? 0));
}

function sbdp_quote_proposal_send_decision_smoke_confirm_line(QuoteLineControlStatusService $lineControls, array $fixture): void
{
    $lineControls->updateStatus((int) $fixture['quote_id'], (int) $fixture['line_id'], 'availability', 'confirmed');
    $lineControls->updateStatus((int) $fixture['quote_id'], (int) $fixture['line_id'], 'pricing', 'confirmed');
}

if (! class_exists(QuoteRepository::class) || ! class_exists(QuoteProposalSendDecisionService::class)) {
    sbdp_quote_proposal_send_decision_smoke_fail('Quote proposal send decision services are not loaded.');
}

global $wpdb;
sbdp_quote_proposal_send_decision_smoke_ok(isset($wpdb) && $wpdb instanceof wpdb, 'WordPress database is not available.');

$repository = new QuoteRepository();
$events = new QuoteEventLogger($repository);
$lineControls = new QuoteLineControlStatusService($repository, $events);
$review = new QuoteReviewService($repository, $events, new QuoteFollowupService($repository, $events));
$validator = new QuoteSendReadinessValidator($repository);
$decision = new QuoteProposalSendDecisionService($repository);
$created = array();
$prefix = $wpdb->prefix;

try {
    $directAvailability = array('bookingMode' => 'direct_internal', 'supplierStatus' => 'not_required');

    $scenarioA = sbdp_quote_proposal_send_decision_smoke_make_quote($repository, $created, 'A', 'not_started', 'not_ready', 'smoke@example.test', true, 0, $directAvailability);
    sbdp_quote_proposal_send_decision_smoke_confirm_line($lineControls, $scenarioA);
    $inspectionA = $validator->inspect((int) $scenarioA['quote_id']);
    $decisionA = $decision->decide((int) $scenarioA['quote_id']);
    sbdp_quote_proposal_send_decision_smoke_ok(! empty($inspectionA['ready']), 'Scenario A: validator should be green.');
    sbdp_quote_proposal_send_decision_smoke_ok(! empty($decisionA['can_complete_control']), 'Scenario A: can_complete_control should be true.');
    sbdp_quote_proposal_send_decision_smoke_ok((string) ($decisionA['next_action'] ?? '') === 'Controle afronden', 'Scenario A: next_action mismatch.');
    $review->approve((int) $scenarioA['quote_id']);
    $approvedA = $repository->findQuote((int) $scenarioA['quote_id']);
    $decisionAAfter = $decision->decide((int) $scenarioA['quote_id']);
    sbdp_quote_proposal_send_decision_smoke_ok((string) ($approvedA['review_status'] ?? '') === 'approved', 'Scenario A: review_status not approved.');
    sbdp_quote_proposal_send_decision_smoke_ok((string) ($approvedA['send_status'] ?? '') === 'ready_to_send', 'Scenario A: send_status not ready_to_send.');
    sbdp_quote_proposal_send_decision_smoke_ok(sbdp_quote_proposal_send_decision_smoke_event_count($repository, (int) $scenarioA['quote_id'], 'quote_review_approved') === 1, 'Scenario A: approval event count mismatch.');
    sbdp_quote_proposal_send_decision_smoke_ok(! empty($decisionAAfter['can_send']), 'Scenario A: can_send should be true after control complete.');

    $scenarioB = sbdp_quote_proposal_send_decision_smoke_make_quote($repository, $created, 'B', 'approved', 'ready_to_send', 'smoke@example.test', true, 0, $directAvailability);
    sbdp_quote_proposal_send_decision_smoke_confirm_line($lineControls, $scenarioB);
    $decisionB = $decision->decide((int) $scenarioB['quote_id']);
    sbdp_quote_proposal_send_decision_smoke_ok(! empty($decisionB['can_send']), 'Scenario B: can_send should be true.');
    sbdp_quote_proposal_send_decision_smoke_ok((string) ($decisionB['next_action'] ?? '') === 'Voorstel versturen', 'Scenario B: next_action mismatch.');
    sbdp_quote_proposal_send_decision_smoke_ok(! in_array('review_not_approved', array_column((array) ($decisionB['blockers'] ?? array()), 'code'), true), 'Scenario B: review blocker should not be present.');

    $scenarioC = sbdp_quote_proposal_send_decision_smoke_make_quote($repository, $created, 'C', 'not_started', 'not_ready', 'smoke@example.test', false, 0, $directAvailability);
    sbdp_quote_proposal_send_decision_smoke_confirm_line($lineControls, $scenarioC);
    $decisionC = $decision->decide((int) $scenarioC['quote_id']);
    sbdp_quote_proposal_send_decision_smoke_ok(empty($decisionC['can_send']), 'Scenario C: can_send should be false.');
    sbdp_quote_proposal_send_decision_smoke_ok(empty($decisionC['can_complete_control']), 'Scenario C: can_complete_control should be false.');
    sbdp_quote_proposal_send_decision_smoke_ok(in_array('proposal_text_missing', array_column((array) ($decisionC['blockers'] ?? array()), 'code'), true), 'Scenario C: proposal_text_missing blocker missing.');
    sbdp_quote_proposal_send_decision_smoke_ok((string) (($repository->findQuote((int) $scenarioC['quote_id'])['review_status'] ?? '')) !== 'approved', 'Scenario C: quote should not be approved.');

    $scenarioD = sbdp_quote_proposal_send_decision_smoke_make_quote($repository, $created, 'D', 'not_started', 'not_ready', '', true, 0, $directAvailability);
    sbdp_quote_proposal_send_decision_smoke_confirm_line($lineControls, $scenarioD);
    $decisionD = $decision->decide((int) $scenarioD['quote_id']);
    sbdp_quote_proposal_send_decision_smoke_ok(empty($decisionD['can_send']), 'Scenario D: can_send should be false.');
    sbdp_quote_proposal_send_decision_smoke_ok(in_array('customer_email_missing', array_column((array) ($decisionD['blockers'] ?? array()), 'code'), true), 'Scenario D: customer_email_missing blocker missing.');

    $supplierAvailability = array(
        'bookingMode' => 'supplier_confirmation',
        'supplierProvider' => 'eliio',
        'supplierStatus' => 'supplier_confirmation_required',
    );
    $scenarioE = sbdp_quote_proposal_send_decision_smoke_make_quote($repository, $created, 'E', 'not_started', 'not_ready', 'smoke@example.test', true, 115, $supplierAvailability);
    $repository->updateQuoteLine((int) $scenarioE['line_id'], array(
        'pricing_confidence' => 'execution_verified',
        'availability_confidence' => 'confirmed',
        'pricing_snapshot_json' => array('control_status' => 'confirmed'),
        'availability_snapshot_json' => array_merge($supplierAvailability, array('control_status' => 'confirmed')),
    ));
    $repository->updateQuoteVersion((int) $scenarioE['version_id'], array(
        'pricing_confidence' => 'execution_verified',
        'availability_confidence' => 'confirmed',
    ));
    $decisionE = $decision->decide((int) $scenarioE['quote_id']);
    sbdp_quote_proposal_send_decision_smoke_ok(empty($decisionE['can_send']), 'Scenario E: can_send should be false.');
    sbdp_quote_proposal_send_decision_smoke_ok(empty($decisionE['can_complete_control']), 'Scenario E: can_complete_control should be false.');
    sbdp_quote_proposal_send_decision_smoke_ok(in_array('supplier_confirmation_missing', array_column((array) ($decisionE['blockers'] ?? array()), 'code'), true), 'Scenario E: supplier blocker missing.');
    sbdp_quote_proposal_send_decision_smoke_ok((string) (($repository->findQuote((int) $scenarioE['quote_id'])['review_status'] ?? '')) !== 'approved', 'Scenario E: quote should not be approved.');

    $beforeF = sbdp_quote_proposal_send_decision_smoke_event_count($repository, (int) $scenarioA['quote_id'], 'quote_review_approved');
    $review->approve((int) $scenarioA['quote_id']);
    $afterF = sbdp_quote_proposal_send_decision_smoke_event_count($repository, (int) $scenarioA['quote_id'], 'quote_review_approved');
    sbdp_quote_proposal_send_decision_smoke_ok($beforeF === 1 && $afterF === 1, 'Scenario F: duplicate approval event created.');

    echo wp_json_encode(array(
        'ok' => true,
        'scenario_a' => array('can_complete_control' => (bool) ($decisionA['can_complete_control'] ?? false), 'after_can_send' => (bool) ($decisionAAfter['can_send'] ?? false)),
        'scenario_b' => array('can_send' => (bool) ($decisionB['can_send'] ?? false), 'next_action' => (string) ($decisionB['next_action'] ?? '')),
        'scenario_c' => array('blockers' => array_column((array) ($decisionC['blockers'] ?? array()), 'code')),
        'scenario_d' => array('blockers' => array_column((array) ($decisionD['blockers'] ?? array()), 'code')),
        'scenario_e' => array('blockers' => array_column((array) ($decisionE['blockers'] ?? array()), 'code')),
        'scenario_f_review_events' => $afterF,
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
