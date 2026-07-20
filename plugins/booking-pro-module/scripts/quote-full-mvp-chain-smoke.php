<?php

if (! function_exists('add_action')) {
    $wpLoad = dirname(__DIR__, 4) . '/wp-load.php';
    if (is_readable($wpLoad)) {
        require_once $wpLoad;
    }
}

use BSP\Bookings\Service\BookingManager;
use BSP\Bookings\Service\BookingRepository;
use BSP\Bookings\Service\OperationsSyncService;
use BSP\Commerce\Module as CommerceModule;
use BSP\Quotes\Repository\QuoteRepository;
use BSP\Quotes\Service\PublicQuoteProposalService;
use BSP\Quotes\Service\PublicQuoteProposalTokenService;
use BSP\Quotes\Service\QuoteAdminConfirmationService;
use BSP\Quotes\Service\QuoteAdminStatusSummaryService;
use BSP\Quotes\Service\QuoteBookingBridgeService;
use BSP\Quotes\Service\QuoteConfirmationReadinessService;
use BSP\Quotes\Service\QuoteEventLogger;
use BSP\Quotes\Service\QuoteExecutionAdapterService;
use BSP\Quotes\Service\QuoteExecutionLaunchService;
use BSP\Quotes\Service\QuoteExecutionLookupService;
use BSP\Quotes\Service\QuoteExecutionRunnerService;
use BSP\Quotes\Service\QuoteHandoffAdapterService;
use BSP\Quotes\Service\QuoteLineControlStatusService;
use BSP\Quotes\Service\QuotePaymentSyncService;
use BSP\Quotes\Service\QuoteProposalSendDecisionService;
use BSP\Quotes\Service\QuoteRequestOrderBridgeService;
use BSP\Quotes\Service\QuoteReviewService;
use BSP\Quotes\Service\QuoteSendService;
use BSP\Quotes\Service\QuoteWooCartHydrationService;
use BSP\Quotes\Service\QuoteFollowupService;
use BSP\Quotes\Service\WooCartLaunchGateway;
use BSPModule\Core\Services\BookingTruthRuntimeService;

function sbdp_quote_full_mvp_chain_smoke_fail(string $message): void
{
    if (class_exists('WP_CLI')) {
        WP_CLI::error($message);
    }

    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function sbdp_quote_full_mvp_chain_smoke_ok(bool $condition, string $message): void
{
    if (! $condition) {
        sbdp_quote_full_mvp_chain_smoke_fail($message);
    }
}

function sbdp_quote_full_mvp_chain_smoke_count_events(QuoteRepository $repository, int $quoteId, string $eventType): int
{
    return count(array_filter(
        $repository->listQuoteEvents($quoteId),
        static fn (array $event): bool => (string) ($event['event_type'] ?? '') === $eventType
    ));
}

function sbdp_quote_full_mvp_chain_smoke_table_count(string $table, string $where = '1=1'): int
{
    global $wpdb;
    if (! $wpdb instanceof wpdb) {
        return 0;
    }

    $existing = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ((string) $existing !== $table) {
        return 0;
    }

    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}");
}

function sbdp_quote_full_mvp_chain_smoke_clear_cart(): void
{
    if (! function_exists('WC') || ! WC()) {
        return;
    }
    if (WC()->cart && method_exists(WC()->cart, 'empty_cart')) {
        WC()->cart->empty_cart();
    }
    if (WC()->session && method_exists(WC()->session, 'set')) {
        WC()->session->set('sbdp_quote_handoff_discount', null);
    }
}

function sbdp_quote_full_mvp_chain_smoke_booking_manager(): BookingManager
{
    if (! class_exists('BSP\\Planner\\Vendor\\CityGuideProfileStore')) {
        eval('namespace BSP\\Planner\\Vendor; final class CityGuideProfileStore { public function all(): array { return array(); } }');
    }
    if (! class_exists('SBDP\\Modules\\Planner\\Services\\PlannerService')) {
        require_once __DIR__ . '/../modules/planner/Services/PlannerService.php';
    }
    if (! class_exists('BSP\\Planner\\Module') && class_exists('SBDP\\Modules\\Planner\\Module')) {
        class_alias('SBDP\\Modules\\Planner\\Module', 'BSP\\Planner\\Module');
    }

    $plannerReflection = new ReflectionClass('BSP\\Planner\\Module');
    $planner = $plannerReflection->newInstanceWithoutConstructor();
    $serviceProperty = $plannerReflection->getProperty('service');
    $serviceProperty->setAccessible(true);
    $serviceProperty->setValue($planner, new SBDP\Modules\Planner\Services\PlannerService());

    return new BookingManager(
        new BookingRepository(),
        new CommerceModule(),
        $planner,
        new BSP\Planner\Vendor\CityGuideProfileStore(),
        null,
        new OperationsSyncService(),
        new BookingTruthRuntimeService()
    );
}

final class SbdpQuoteFullMvpChainSmokeLookup extends QuoteExecutionLookupService
{
    public function lookupPricing(array $line): array
    {
        $product = function_exists('wc_get_product') ? wc_get_product((int) ($line['product_id'] ?? 0)) : null;
        $participants = max(1, (int) ($line['participants'] ?? $line['quantity'] ?? 1));
        $unit = $product instanceof WC_Product ? max(0.01, (float) $product->get_price()) : 1.0;

        return array(
            'confidence' => 'execution_verified',
            'payload' => array('source' => 'quote_full_mvp_chain_smoke'),
            'unit_amount_snapshot' => $unit,
            'line_total_snapshot' => round($unit * $participants, 2),
            'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR',
        );
    }

    public function lookupAvailability(array $line): array
    {
        return array(
            'confidence' => 'confirmed',
            'available' => true,
            'payload' => array(
                'source' => 'quote_full_mvp_chain_smoke',
                'control_status' => 'confirmed',
                'bookingMode' => 'direct_internal',
                'supplierStatus' => 'not_required',
            ),
        );
    }
}

function sbdp_quote_full_mvp_chain_smoke_make_quote(
    QuoteRepository $repository,
    array &$created,
    string $suffix,
    int $productId,
    array $availability
): array {
    $product = function_exists('wc_get_product') ? wc_get_product($productId) : null;
    sbdp_quote_full_mvp_chain_smoke_ok($product instanceof WC_Product, 'Smoke product is not available: ' . $productId);

    $date = gmdate('Y-m-d', strtotime('+45 days') ?: time());
    $request = $repository->createQuoteRequest(array(
        'request_reference' => 'QR-FULL-MVP-' . $suffix . '-' . gmdate('YmdHis'),
        'status' => 'converted_to_quote',
        'request_summary' => 'Full MVP chain smoke ' . $suffix,
        'requester_name' => 'Smoke Test',
        'requester_email' => 'smoke@example.test',
        'group_size' => 2,
        'preferred_date' => $date,
        'preferred_start_time' => '11:00',
        'preferred_end_time' => '12:30',
        'source_type' => 'quote_full_mvp_chain_smoke',
    ));
    $quote = $repository->createQuote(array(
        'quote_request_id' => (int) ($request['id'] ?? 0),
        'quote_reference' => 'Q-FULL-MVP-' . $suffix . '-' . gmdate('YmdHis'),
        'status' => 'draft',
        'handoff_status' => 'not_ready',
        'review_status' => 'not_started',
        'send_status' => 'not_ready',
    ));
    $version = $repository->createQuoteVersion(array(
        'quote_id' => (int) ($quote['id'] ?? 0),
        'version_number' => 1,
        'status' => 'draft',
        'proposal_title' => 'Full MVP chain voorstel ' . $suffix,
        'proposal_summary' => 'Klantgerichte voorsteltekst voor full MVP chain smoke.',
        'snapshot_type' => 'execution_resnapshot',
        'pricing_confidence' => 'unknown',
        'availability_confidence' => 'unknown',
        'pricing_snapshot_json' => array('source' => 'quote_full_mvp_chain_smoke'),
    ));

    $quoteId = (int) ($quote['id'] ?? 0);
    $versionId = (int) ($version['id'] ?? 0);
    $unit = max(0.01, (float) $product->get_price());
    $lines = $repository->replaceQuoteLines($versionId, array(array(
        'line_number' => 1,
        'line_type' => 'product',
        'line_status' => 'mapped',
        'title' => $product->get_name(),
        'product_id' => $productId,
        'quantity' => 2,
        'participants' => 2,
        'service_date' => $date,
        'start_time' => '11:00',
        'end_time' => '12:30',
        'pricing_confidence' => 'unknown',
        'availability_confidence' => 'unknown',
        'unit_amount_snapshot' => $unit,
        'line_total_snapshot' => round($unit * 2, 2),
        'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR',
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
        'subject' => 'Full MVP chain voorstel ' . $suffix,
        'body' => 'Klantgerichte voorsteltekst voor full MVP chain smoke.',
        'body_summary' => 'Klantgerichte voorsteltekst voor full MVP chain smoke.',
        'to_name' => 'Smoke Test',
        'to_email' => 'smoke@example.test',
        'thread_token' => (string) ($quote['quote_reference'] ?? ''),
    ));

    $created[] = array(
        'request_id' => (int) ($request['id'] ?? 0),
        'quote_id' => $quoteId,
        'version_id' => $versionId,
        'order_id' => 0,
    );

    return array(
        'quote_id' => $quoteId,
        'request_id' => (int) ($request['id'] ?? 0),
        'version_id' => $versionId,
        'line_id' => (int) (($lines[0]['id'] ?? 0)),
        'date' => $date,
        'unit' => $unit,
    );
}

if (! class_exists(QuoteRepository::class) || ! class_exists(QuoteProposalSendDecisionService::class)) {
    sbdp_quote_full_mvp_chain_smoke_fail('Quote services are not loaded.');
}
if (! function_exists('WC') || ! function_exists('wc_get_product') || ! function_exists('wc_get_order')) {
    sbdp_quote_full_mvp_chain_smoke_fail('WooCommerce is not loaded.');
}

global $wpdb;
sbdp_quote_full_mvp_chain_smoke_ok(isset($wpdb) && $wpdb instanceof wpdb, 'WordPress database is not available.');

$productId = 352;
$product = wc_get_product($productId);
sbdp_quote_full_mvp_chain_smoke_ok($product instanceof WC_Product, 'Smoke product 352 is not available.');
sbdp_quote_full_mvp_chain_smoke_ok($productId !== 115, 'Happy path must not use Eliio product 115.');

if (function_exists('add_filter')) {
    add_filter(
        'sbdp_planservice_availability_slots_payload',
        static function ($payload, array $context) use ($productId) {
            if ((int) ($context['product_id'] ?? 0) !== $productId) {
                return $payload;
            }

            return array(
                'capacity' => 20,
                'resource_valid' => true,
                'slots' => array(
                    array('start' => '11:00', 'end' => '11:30', 'available' => true),
                    array('start' => '11:30', 'end' => '12:00', 'available' => true),
                    array('start' => '12:00', 'end' => '12:30', 'available' => true),
                ),
            );
        },
        10,
        2
    );
    add_filter(
        'sbdp_planservice_execution_check',
        static function ($result, array $context) use ($productId) {
            if ((int) ($context['product_id'] ?? 0) !== $productId) {
                return $result;
            }

            return true;
        },
        10,
        2
    );
}

$repository = new QuoteRepository();
$events = new QuoteEventLogger($repository);
$lineControls = new QuoteLineControlStatusService($repository, $events);
$decision = new QuoteProposalSendDecisionService($repository);
$review = new QuoteReviewService($repository, $events, new QuoteFollowupService($repository, $events));
$send = new QuoteSendService($repository, $events);
$tokenService = new PublicQuoteProposalTokenService();
$publicProposal = new PublicQuoteProposalService($repository, $events, $tokenService);
$orderBridge = new QuoteRequestOrderBridgeService($repository, $events);
$readiness = new QuoteConfirmationReadinessService($repository, $events);
$confirmation = new QuoteAdminConfirmationService($repository, $events, $readiness);
$lookup = new SbdpQuoteFullMvpChainSmokeLookup();
$bookingBridge = new QuoteBookingBridgeService($repository, $events, sbdp_quote_full_mvp_chain_smoke_booking_manager());
$created = array();
$createdMasterIds = array();
$prefix = $wpdb->prefix;

try {
    sbdp_quote_full_mvp_chain_smoke_clear_cart();
    if (function_exists('delete_transient')) {
        delete_transient('sbdp_booking_records_v3');
    }

    $directAvailability = array('bookingMode' => 'direct_internal', 'supplierStatus' => 'not_required');
    $direct = sbdp_quote_full_mvp_chain_smoke_make_quote($repository, $created, 'DIRECT', $productId, $directAvailability);
    $quoteId = (int) $direct['quote_id'];
    $versionId = (int) $direct['version_id'];
    $requestId = (int) $direct['request_id'];

    $lineControls->updateStatus($quoteId, (int) $direct['line_id'], 'availability', 'confirmed');
    $lineControls->updateStatus($quoteId, (int) $direct['line_id'], 'pricing', 'confirmed');
    $decisionBeforeControl = $decision->decide($quoteId);
    sbdp_quote_full_mvp_chain_smoke_ok(! empty($decisionBeforeControl['can_complete_control']), 'Scenario A: can_complete_control should be true.');

    $review->approve($quoteId, 42);
    $review->approve($quoteId, 42);
    $afterReview = $repository->findQuote($quoteId);
    $decisionAfterControl = $decision->decide($quoteId);
    sbdp_quote_full_mvp_chain_smoke_ok((string) ($afterReview['review_status'] ?? '') === 'approved', 'Scenario A: review_status not approved.');
    sbdp_quote_full_mvp_chain_smoke_ok((string) ($afterReview['send_status'] ?? '') === 'ready_to_send', 'Scenario A: send_status not ready_to_send.');
    sbdp_quote_full_mvp_chain_smoke_ok(! empty($decisionAfterControl['can_send']), 'Scenario A: can_send should be true after control complete.');
    sbdp_quote_full_mvp_chain_smoke_ok(sbdp_quote_full_mvp_chain_smoke_count_events($repository, $quoteId, 'quote_review_approved') === 1, 'Scenario A: review approval event should be logged once.');

    $send->markSentManual($quoteId, 'smoke_safe_boundary', 'Full MVP chain smoke safe send boundary.', 42);
    $token = $tokenService->create($quoteId, $versionId, (string) ($afterReview['quote_reference'] ?? ''));
    $repository->createQuoteMessage(array(
        'quote_id' => $quoteId,
        'quote_version_id' => $versionId,
        'direction' => 'outbound',
        'message_type' => 'proposal',
        'channel' => 'public_proposal',
        'status' => 'sent',
        'subject' => 'Full MVP chain voorstel verzonden',
        'body' => 'Klantgerichte voorsteltekst voor full MVP chain smoke. Public proposal token: ' . $token,
        'body_summary' => 'Klantgerichte voorsteltekst voor full MVP chain smoke.',
        'to_name' => 'Smoke Test',
        'to_email' => 'smoke@example.test',
        'thread_token' => (string) ($afterReview['quote_reference'] ?? ''),
        'sent_at' => gmdate('Y-m-d H:i:s'),
    ));
    $proposalContext = $publicProposal->resolveByToken($token);
    sbdp_quote_full_mvp_chain_smoke_ok(! empty($proposalContext['actionable']), 'Scenario A: public proposal should be actionable.');

    $accepted = $publicProposal->accept(
        $token,
        array('ip' => '127.0.0.1', 'user_agent' => 'quote-full-mvp-chain-smoke'),
        array(
            'acceptance_name' => 'TEST-BSP Full MVP Akkoordgever',
            'acceptance_email' => 'test-bsp-full-mvp@example.test',
            'acceptance_company' => 'TEST-BSP Company',
            'acceptance_role' => 'QA',
            'acceptance_terms_checked' => '1',
            'terms_version' => 'ddb-terms-smoke',
            'terms_url' => 'https://staging.dagjedenbosch.nl/voorwaarden/',
        )
    );
    sbdp_quote_full_mvp_chain_smoke_ok((string) ($accepted['status'] ?? '') === 'accepted', 'Scenario A: quote was not accepted.');
    sbdp_quote_full_mvp_chain_smoke_ok((int) ($accepted['approved_version_id'] ?? 0) === $versionId, 'Scenario A: approved_version_id was not pinned.');

    $repository->updateQuote($quoteId, array('handoff_status' => 'resnapshot_prepared'));
    (new QuoteHandoffAdapterService($repository, $events))->buildControlledPackage($quoteId, 42);
    (new QuoteExecutionAdapterService($repository, $events))->buildCartOrderPrep($quoteId, 42);
    (new QuoteExecutionRunnerService($repository, $events, $lookup))->validateCartReady($quoteId, 42);

    $orderResult = $orderBridge->createWooRequestOrder($quoteId, 42);
    $orderId = (int) ($orderResult['woo_order_id'] ?? 0);
    $created[0]['order_id'] = $orderId;
    $order = wc_get_order($orderId);
    sbdp_quote_full_mvp_chain_smoke_ok($order instanceof WC_Order, 'Scenario A: Woo order was not created.');
    sbdp_quote_full_mvp_chain_smoke_ok((int) $order->get_meta('_sbdp_quote_id') === $quoteId, 'Scenario A: Woo order quote_id meta mismatch.');
    sbdp_quote_full_mvp_chain_smoke_ok((int) $order->get_meta('_sbdp_quote_version_id') === $versionId, 'Scenario A: Woo order quote_version_id meta mismatch.');

    do_action('woocommerce_payment_complete', $orderId);
    do_action('woocommerce_payment_complete', $orderId);
    $afterPayment = $repository->findQuote($quoteId);
    sbdp_quote_full_mvp_chain_smoke_ok((string) ($afterPayment['handoff_status'] ?? '') === QuotePaymentSyncService::COMPLETED_STATUS, 'Scenario A: payment handoff status mismatch.');
    sbdp_quote_full_mvp_chain_smoke_ok(sbdp_quote_full_mvp_chain_smoke_count_events($repository, $quoteId, QuotePaymentSyncService::COMPLETED_EVENT) === 1, 'Scenario A: payment event should be logged once.');

    $confirmedByPaymentHook = (string) ($afterPayment['status'] ?? '') === 'confirmed';
    $ready = $readiness->evaluate($quoteId);
    if (! $confirmedByPaymentHook) {
        sbdp_quote_full_mvp_chain_smoke_ok((string) ($ready['outcome'] ?? '') === QuoteConfirmationReadinessService::READY_TO_CONFIRM, 'Scenario A: readiness should be ready_to_confirm.');
        $confirmation->confirmReadyQuote($quoteId, 42);
        $confirmation->confirmReadyQuote($quoteId, 42);
    }
    $afterConfirm = $repository->findQuote($quoteId);
    sbdp_quote_full_mvp_chain_smoke_ok((string) ($afterConfirm['status'] ?? '') === 'confirmed', 'Scenario A: quote was not confirmed.');
    sbdp_quote_full_mvp_chain_smoke_ok(sbdp_quote_full_mvp_chain_smoke_count_events($repository, $quoteId, QuoteAdminConfirmationService::CONFIRMED_EVENT) === 1, 'Scenario A: confirmation event should be logged once.');

    $repository->updateQuote($quoteId, array('handoff_status' => 'execution_validated'));
    $launch = (new QuoteExecutionLaunchService($repository, $events))->buildWooCartSessionPrep($quoteId, 42);
    $hydration = (new QuoteWooCartHydrationService(new WooCartLaunchGateway(), $repository, $events))->hydrateLaunchToCart($quoteId, (string) ($launch['launch_token'] ?? ''), 42);
    $afterCart = $repository->findQuote($quoteId);
    sbdp_quote_full_mvp_chain_smoke_ok((string) ($afterCart['handoff_status'] ?? '') === 'woo_cart_hydrated', 'Scenario A: cart was not hydrated.');
    sbdp_quote_full_mvp_chain_smoke_ok((string) ($hydration['cart_url'] ?? '') !== '', 'Scenario A: cart_url missing.');
    sbdp_quote_full_mvp_chain_smoke_ok((string) ($hydration['checkout_url'] ?? '') !== '', 'Scenario A: checkout_url missing.');

    $mastersBefore = sbdp_quote_full_mvp_chain_smoke_table_count($prefix . 'bsp_booking_masters');
    $legsBefore = sbdp_quote_full_mvp_chain_smoke_table_count($prefix . 'bsp_booking_legs');
    $bridge = $bookingBridge->createOperationsBooking($quoteId, 42);
    $masterId = (int) ($bridge['booking_master_id'] ?? 0);
    $createdMasterIds[] = $masterId;
    $afterBridge = $repository->findQuote($quoteId);
    $mastersAfter = sbdp_quote_full_mvp_chain_smoke_table_count($prefix . 'bsp_booking_masters');
    $legsAfter = sbdp_quote_full_mvp_chain_smoke_table_count($prefix . 'bsp_booking_legs');
    $secondBridge = $bookingBridge->createOperationsBooking($quoteId, 42);
    sbdp_quote_full_mvp_chain_smoke_ok($masterId > 0, 'Scenario A: booking master missing.');
    sbdp_quote_full_mvp_chain_smoke_ok((int) ($afterBridge['booking_master_id'] ?? 0) === $masterId, 'Scenario A: booking_master_id not stored.');
    sbdp_quote_full_mvp_chain_smoke_ok((string) ($afterBridge['handoff_status'] ?? '') === 'operations_ready', 'Scenario A: operations_ready not reached.');
    sbdp_quote_full_mvp_chain_smoke_ok((string) ($afterBridge['status'] ?? '') === 'confirmed', 'Scenario A: quote status should remain confirmed.');
    sbdp_quote_full_mvp_chain_smoke_ok($mastersAfter === $mastersBefore + 1, 'Scenario A: booking master count mismatch.');
    sbdp_quote_full_mvp_chain_smoke_ok($legsAfter > $legsBefore, 'Scenario A: booking legs were not created.');
    sbdp_quote_full_mvp_chain_smoke_ok(! empty($secondBridge['idempotent']), 'Scenario C: booking bridge was not idempotent.');
    sbdp_quote_full_mvp_chain_smoke_ok(sbdp_quote_full_mvp_chain_smoke_count_events($repository, $quoteId, 'quote_booking_bridge_created') === 1, 'Scenario A/C: booking bridge event should be logged once.');

    $summary = (new QuoteAdminStatusSummaryService($repository))->summarize($quoteId, array('send_allowed' => false));
    sbdp_quote_full_mvp_chain_smoke_ok((string) ($summary['next_action'] ?? '') === 'Geen actie', 'Scenario A: final next action mismatch.');

    $supplierAvailability = array(
        'bookingMode' => 'supplier_confirmation',
        'supplierProvider' => 'eliio',
        'supplierStatus' => 'supplier_confirmation_required',
    );
    $supplier = sbdp_quote_full_mvp_chain_smoke_make_quote($repository, $created, 'SUPPLIER', 115, $supplierAvailability);
    $supplierQuoteId = (int) $supplier['quote_id'];
    $supplierSimpleAvailabilityBlocked = false;
    try {
        $lineControls->updateStatus($supplierQuoteId, (int) $supplier['line_id'], 'availability', 'confirmed');
    } catch (Throwable $exception) {
        $supplierSimpleAvailabilityBlocked = str_contains($exception->getMessage(), 'supplier_booking_confirmed');
    }
    $lineControls->updateStatus($supplierQuoteId, (int) $supplier['line_id'], 'pricing', 'confirmed');
    $supplierDecision = $decision->decide($supplierQuoteId);
    $supplierApproveBlocked = false;
    try {
        $review->approve($supplierQuoteId, 42);
    } catch (Throwable) {
        $supplierApproveBlocked = true;
    }
    $supplierBridgeBlocked = false;
    try {
        $bookingBridge->createOperationsBooking($supplierQuoteId, 42);
    } catch (Throwable) {
        $supplierBridgeBlocked = true;
    }
    $supplierQuote = $repository->findQuote($supplierQuoteId);
    sbdp_quote_full_mvp_chain_smoke_ok($supplierSimpleAvailabilityBlocked, 'Scenario B: supplier simple availability should be blocked.');
    sbdp_quote_full_mvp_chain_smoke_ok(in_array('supplier_confirmation_missing', array_column((array) ($supplierDecision['blockers'] ?? array()), 'code'), true), 'Scenario B: supplier blocker missing.');
    sbdp_quote_full_mvp_chain_smoke_ok($supplierApproveBlocked, 'Scenario B: supplier quote should not auto-approve.');
    sbdp_quote_full_mvp_chain_smoke_ok($supplierBridgeBlocked, 'Scenario B: supplier booking bridge should be blocked.');
    sbdp_quote_full_mvp_chain_smoke_ok(empty($supplierQuote['booking_master_id']), 'Scenario B: supplier booking_master_id should stay empty.');
    sbdp_quote_full_mvp_chain_smoke_ok((string) ($supplierQuote['handoff_status'] ?? '') !== 'operations_ready', 'Scenario B: supplier should not reach operations_ready.');

    echo wp_json_encode(array(
        'ok' => true,
        'scenario_a' => array(
            'quote_id' => $quoteId,
            'review_status' => (string) ($afterReview['review_status'] ?? ''),
            'send_status' => (string) ($afterReview['send_status'] ?? ''),
            'public_proposal_actionable' => ! empty($proposalContext['actionable']),
            'accepted_status' => (string) ($accepted['status'] ?? ''),
            'woo_order_id' => $orderId,
            'payment_handoff_status' => (string) ($afterPayment['handoff_status'] ?? ''),
            'readiness' => (string) ($ready['outcome'] ?? ''),
            'confirmed_by_payment_hook' => $confirmedByPaymentHook,
            'confirmed_status' => (string) ($afterConfirm['status'] ?? ''),
            'cart_url' => (string) ($hydration['cart_url'] ?? ''),
            'checkout_url' => (string) ($hydration['checkout_url'] ?? ''),
            'booking_master_id' => $masterId,
            'final_handoff_status' => (string) ($afterBridge['handoff_status'] ?? ''),
            'final_next_action' => (string) ($summary['next_action'] ?? ''),
        ),
        'scenario_b' => array(
            'supplier_simple_availability_blocked' => $supplierSimpleAvailabilityBlocked,
            'supplier_decision_blockers' => array_column((array) ($supplierDecision['blockers'] ?? array()), 'code'),
            'supplier_approve_blocked' => $supplierApproveBlocked,
            'supplier_booking_bridge_blocked' => $supplierBridgeBlocked,
            'supplier_booking_master_id_empty' => empty($supplierQuote['booking_master_id']),
            'supplier_operations_ready' => (string) ($supplierQuote['handoff_status'] ?? '') === 'operations_ready',
        ),
        'scenario_c' => array(
            'quote_review_approved_events' => sbdp_quote_full_mvp_chain_smoke_count_events($repository, $quoteId, 'quote_review_approved'),
            'quote_woo_payment_completed_events' => sbdp_quote_full_mvp_chain_smoke_count_events($repository, $quoteId, QuotePaymentSyncService::COMPLETED_EVENT),
            'quote_confirmed_events' => sbdp_quote_full_mvp_chain_smoke_count_events($repository, $quoteId, QuoteAdminConfirmationService::CONFIRMED_EVENT),
            'quote_booking_bridge_created_events' => sbdp_quote_full_mvp_chain_smoke_count_events($repository, $quoteId, 'quote_booking_bridge_created'),
            'second_booking_bridge_idempotent' => ! empty($secondBridge['idempotent']),
        ),
        'provider_call_executed' => false,
        'eliio_call_executed' => false,
        'supplier_call_executed' => false,
        'supplier_confirmation_executed' => false,
        'real_mollie_payment_executed' => false,
        'real_email_sent' => false,
        'private_tour_proven' => false,
    ), JSON_PRETTY_PRINT) . PHP_EOL;
} finally {
    sbdp_quote_full_mvp_chain_smoke_clear_cart();

    foreach (array_unique(array_filter(array_map('intval', $createdMasterIds))) as $masterId) {
        foreach (array(
            'bsp_guest_dietary_profiles',
            'bsp_partner_confirmations',
            'bsp_guide_assignments',
            'bsp_booking_events',
            'bsp_booking_legs',
        ) as $tableSuffix) {
            $wpdb->delete($prefix . $tableSuffix, array('master_id' => $masterId));
        }
        $wpdb->delete($prefix . 'bsp_booking_masters', array('id' => $masterId));
    }

    foreach (array_reverse($created) as $row) {
        if (! empty($row['order_id'])) {
            $order = wc_get_order((int) $row['order_id']);
            if ($order instanceof WC_Order) {
                $order->delete(true);
            }
        }

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

    if (function_exists('delete_transient')) {
        delete_transient('sbdp_booking_records_v3');
    }
}
