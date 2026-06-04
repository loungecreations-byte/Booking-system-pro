<?php

if (! function_exists('add_action')) {
    $wpLoad = dirname(__DIR__, 4) . '/wp-load.php';
    if (is_readable($wpLoad)) {
        require_once $wpLoad;
    }
}

use BSP\Quotes\Repository\QuoteRepository;
use BSP\Quotes\Service\QuoteAdminStatusSummaryService;
use BSP\Quotes\Service\QuoteConfirmationReadinessService;
use BSP\Quotes\Service\QuoteEventLogger;
use BSP\Quotes\Service\QuotePaymentSyncService;

if (! class_exists(QuoteAdminStatusSummaryService::class)) {
    require_once __DIR__ . '/../modules/quotes/Service/QuoteAdminStatusSummaryService.php';
}

function sbdp_quote_admin_status_summary_smoke_fail(string $message): void
{
    if (class_exists('WP_CLI')) {
        WP_CLI::error($message);
    }

    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function sbdp_quote_admin_status_summary_smoke_ok(bool $condition, string $message): void
{
    if (! $condition) {
        sbdp_quote_admin_status_summary_smoke_fail($message);
    }
}

function sbdp_quote_admin_status_summary_smoke_make_quote(
    QuoteRepository $repository,
    QuoteEventLogger $events,
    array &$created,
    string $suffix,
    string $status,
    string $handoffStatus,
    int $productId,
    string $lineType,
    array $availability,
    bool $withOrder,
    bool $withPaymentEvent,
    bool $withConfirmedEvent,
    bool $withCartEvent,
    array $handoffPayload = array(),
    string $reviewStatus = 'approved',
    string $sendStatus = ''
): array {
    $request = $repository->createQuoteRequest(array(
        'request_reference' => 'QR-ADMIN-STATUS-' . $suffix . '-' . gmdate('YmdHis'),
        'status' => 'converted_to_quote',
        'request_summary' => 'Quote admin status summary smoke ' . $suffix,
        'requester_name' => 'Smoke Test',
        'requester_email' => 'smoke@example.test',
        'group_size' => 2,
        'preferred_date' => '2026-06-26',
        'source_type' => 'quote_admin_status_summary_smoke',
    ));
    $quote = $repository->createQuote(array(
        'quote_request_id' => (int) ($request['id'] ?? 0),
        'quote_reference' => 'Q-ADMIN-STATUS-' . $suffix . '-' . gmdate('YmdHis'),
        'status' => $status,
        'handoff_status' => $handoffStatus,
        'review_status' => $reviewStatus,
        'send_status' => $sendStatus !== '' ? $sendStatus : ($status === 'sent' ? 'sent_manual' : 'ready_to_send'),
    ));
    $version = $repository->createQuoteVersion(array(
        'quote_id' => (int) ($quote['id'] ?? 0),
        'version_number' => 1,
        'status' => 'approved',
        'proposal_title' => 'Quote admin status summary smoke ' . $suffix,
        'snapshot_type' => 'execution_resnapshot',
        'pricing_confidence' => 'execution_verified',
        'availability_confidence' => 'confirmed',
        'pricing_snapshot_json' => array('source' => 'quote_admin_status_summary_smoke'),
        'handoff_payload_json' => $handoffPayload,
    ));

    $quoteId = (int) ($quote['id'] ?? 0);
    $requestId = (int) ($request['id'] ?? 0);
    $versionId = (int) ($version['id'] ?? 0);
    $orderId = 0;
    if ($withOrder) {
        $order = wc_create_order(array('status' => 'processing'));
        sbdp_quote_admin_status_summary_smoke_ok($order instanceof WC_Order, 'Woo order could not be created.');
        $orderId = (int) $order->get_id();
        $order->update_meta_data('_sbdp_quote_id', $quoteId);
        $order->update_meta_data('_sbdp_quote_version_id', $versionId);
        $order->update_meta_data('_wcpdf_invoice_number', 'INV-' . $suffix);
        $order->save();
    }

    $repository->replaceQuoteLines($versionId, array(array(
        'line_number' => 1,
        'line_type' => $lineType,
        'line_status' => 'mapped',
        'title' => $lineType === 'manual' ? 'Manual smoke line' : 'Smoke product line',
        'product_id' => $productId > 0 ? $productId : null,
        'quantity' => 2,
        'participants' => 2,
        'service_date' => '2026-06-26',
        'start_time' => '11:00',
        'end_time' => '12:30',
        'pricing_confidence' => 'execution_verified',
        'availability_confidence' => 'confirmed',
        'unit_amount_snapshot' => 10,
        'line_total_snapshot' => 20,
        'currency' => get_woocommerce_currency(),
        'availability_snapshot_json' => $availability,
    )));

    $repository->updateQuote($quoteId, array(
        'approved_version_id' => $versionId,
        'current_version_id' => $versionId,
        'woo_order_id' => $orderId,
    ));

    if ($withPaymentEvent) {
        $events->log(
            QuotePaymentSyncService::COMPLETED_EVENT,
            $requestId,
            $quoteId,
            $versionId,
            null,
            'Smoke payment event.',
            array(
                'quote_id' => $quoteId,
                'order_id' => $orderId,
                'approved_version_id' => $versionId,
                'invoice_available' => true,
                'invoice_number' => 'INV-' . $suffix,
            )
        );
    }
    if (in_array($handoffStatus, array(
        QuoteConfirmationReadinessService::READY_TO_CONFIRM,
        QuoteConfirmationReadinessService::AWAITING_SUPPLIER_CONFIRMATION,
        QuoteConfirmationReadinessService::REQUIRES_ADMIN_CONFIRMATION,
        QuoteConfirmationReadinessService::CONFIRMATION_BLOCKED,
    ), true)) {
        $events->log(
            QuoteConfirmationReadinessService::EVENT_EVALUATED,
            $requestId,
            $quoteId,
            $versionId,
            null,
            'Smoke readiness event.',
            array('outcome' => $handoffStatus)
        );
    }
    if ($withConfirmedEvent) {
        $events->log('quote_confirmed', $requestId, $quoteId, $versionId, null, 'Smoke confirmed event.');
    }
    if ($withCartEvent) {
        $events->log('quote_woo_cart_hydrated', $requestId, $quoteId, $versionId, null, 'Smoke cart event.');
    }

    $created[] = array(
        'request_id' => $requestId,
        'quote_id' => $quoteId,
        'version_id' => $versionId,
        'order_id' => $orderId,
    );

    return array('quote_id' => $quoteId, 'version_id' => $versionId, 'order_id' => $orderId);
}

function sbdp_quote_admin_status_summary_smoke_summarize(
    QuoteRepository $repository,
    QuoteAdminStatusSummaryService $service,
    int $quoteId,
    bool $sendAllowed = false
): array {
    $eventsBefore = count($repository->listQuoteEvents($quoteId));
    $quoteBefore = $repository->findQuote($quoteId);
    $summary = $service->summarize($quoteId, array('send_allowed' => $sendAllowed));
    $eventsAfter = count($repository->listQuoteEvents($quoteId));
    $quoteAfter = $repository->findQuote($quoteId);
    sbdp_quote_admin_status_summary_smoke_ok($eventsAfter === $eventsBefore, 'Summary created or removed quote events.');
    sbdp_quote_admin_status_summary_smoke_ok($quoteAfter === $quoteBefore, 'Summary mutated quote fields.');

    return $summary;
}

function sbdp_quote_admin_status_summary_smoke_create_master(int $orderId): int
{
    global $wpdb;
    sbdp_quote_admin_status_summary_smoke_ok($wpdb instanceof wpdb, 'WordPress database is not available.');
    $reference = 'booking:admin-status-smoke-' . gmdate('YmdHis');
    $masterTable = $wpdb->prefix . 'bsp_booking_masters';
    $legTable = $wpdb->prefix . 'bsp_booking_legs';
    $wpdb->insert($masterTable, array(
        'booking_reference' => $reference,
        'woo_order_id' => $orderId,
        'legacy_booking_id' => 0,
        'status' => 'paid',
        'legacy_status' => 'paid',
        'booking_type' => 'quote',
        'commercial_status' => 'paid',
        'commercial_currency' => get_woocommerce_currency(),
        'commercial_total' => 20,
        'participants' => 2,
        'customer_name' => 'Smoke Test',
        'customer_email' => 'smoke@example.test',
        'booking_date' => '2026-06-26',
        'booking_time' => '11:00',
        'booking_end_date' => '2026-06-26',
        'booking_end_time' => '12:30',
        'channel' => 'quote_admin_status_summary_smoke',
        'resource_ref' => '',
        'payload' => wp_json_encode(array('source' => 'quote_admin_status_summary_smoke')),
    ));
    $masterId = (int) $wpdb->insert_id;
    sbdp_quote_admin_status_summary_smoke_ok($masterId > 0, 'Booking master fixture was not created.');
    $wpdb->insert($legTable, array(
        'master_id' => $masterId,
        'booking_reference' => $reference,
        'woo_order_id' => $orderId,
        'legacy_booking_id' => 0,
        'leg_key' => 'smoke-leg-1',
        'leg_index' => 1,
        'status' => 'paid',
        'legacy_status' => 'paid',
        'leg_type' => 'activity',
        'title' => 'Smoke leg',
        'product_id' => 352,
        'scheduled_date' => '2026-06-26',
        'scheduled_time' => '11:00',
        'scheduled_end_date' => '2026-06-26',
        'scheduled_end_time' => '12:30',
        'participants' => 2,
        'payload' => wp_json_encode(array('source' => 'quote_admin_status_summary_smoke')),
    ));

    return $masterId;
}

function sbdp_quote_admin_status_summary_smoke_assert_view_model(array $summary, string $label): void
{
    $steps = is_array($summary['chain_steps'] ?? null) ? $summary['chain_steps'] : array();
    $nextAction = trim((string) ($summary['next_action'] ?? ''));
    sbdp_quote_admin_status_summary_smoke_ok(count($steps) === 8, $label . ': chain step count mismatch.');
    sbdp_quote_admin_status_summary_smoke_ok($nextAction !== '', $label . ': next_action missing.');
    sbdp_quote_admin_status_summary_smoke_ok(str_contains($nextAction, '|') === false, $label . ': next_action contains conflicting values.');
    $labels = array_map(static fn (array $step): string => (string) ($step['label'] ?? ''), $steps);
    foreach (array('Intake', 'Proposal', 'Accepted', 'Paid', 'Confirmed', 'Cart', 'Booking', 'Operations') as $expected) {
        sbdp_quote_admin_status_summary_smoke_ok(in_array($expected, $labels, true), $label . ': missing chain step ' . $expected);
    }
}

function sbdp_quote_admin_status_summary_smoke_has_chip(array $summary, string $group, string $label): bool
{
    foreach ((array) ($summary[$group] ?? array()) as $chip) {
        if (is_array($chip) && (string) ($chip['label'] ?? '') === $label) {
            return true;
        }
    }

    return false;
}

if (! class_exists(QuoteRepository::class) || ! class_exists(QuoteAdminStatusSummaryService::class)) {
    sbdp_quote_admin_status_summary_smoke_fail('Quote admin status summary services are not loaded.');
}
if (! function_exists('wc_create_order') || ! function_exists('wc_get_order')) {
    sbdp_quote_admin_status_summary_smoke_fail('WooCommerce is not loaded.');
}

global $wpdb;
sbdp_quote_admin_status_summary_smoke_ok(isset($wpdb) && $wpdb instanceof wpdb, 'WordPress database is not available.');

$repository = new QuoteRepository();
$events = new QuoteEventLogger($repository);
$service = new QuoteAdminStatusSummaryService($repository);
$created = array();
$createdMasterIds = array();
$prefix = $wpdb->prefix;

try {
    $directAvailability = array('bookingMode' => 'direct_internal', 'supplierStatus' => 'not_required');
    $accepted = sbdp_quote_admin_status_summary_smoke_make_quote($repository, $events, $created, 'ACCEPTED', 'accepted', 'not_ready', 352, 'product', $directAvailability, true, false, false, false, array(), 'not_started', 'not_ready');
    $acceptedSummary = sbdp_quote_admin_status_summary_smoke_summarize($repository, $service, (int) $accepted['quote_id']);
    sbdp_quote_admin_status_summary_smoke_assert_view_model($acceptedSummary, 'accepted');
    sbdp_quote_admin_status_summary_smoke_ok((string) ($acceptedSummary['next_action'] ?? '') === 'Wacht op betaling', 'Accepted quote next_action mismatch.');
    sbdp_quote_admin_status_summary_smoke_ok((string) ($acceptedSummary['communication_status'] ?? '') === 'control_complete_available', 'Accepted communication status mismatch.');
    sbdp_quote_admin_status_summary_smoke_ok((string) ($acceptedSummary['proposal_next_action'] ?? '') === 'Controle afronden', 'Accepted proposal next_action mismatch.');
    sbdp_quote_admin_status_summary_smoke_ok(! sbdp_quote_admin_status_summary_smoke_has_chip($acceptedSummary, 'communication_chips', 'Interne review ontbreekt'), 'Accepted communication should not show review missing.');
    sbdp_quote_admin_status_summary_smoke_ok(empty($acceptedSummary['cta_visibility']['confirm_quote']), 'Accepted quote should not show confirm CTA.');
    sbdp_quote_admin_status_summary_smoke_ok(empty($acceptedSummary['cta_visibility']['create_booking_bridge']), 'Accepted quote should not show booking CTA.');
    sbdp_quote_admin_status_summary_smoke_ok(sbdp_quote_admin_status_summary_smoke_has_chip($acceptedSummary, 'meta_chips', 'Approved version'), 'Accepted summary missing approved version chip.');
    sbdp_quote_admin_status_summary_smoke_ok(sbdp_quote_admin_status_summary_smoke_has_chip($acceptedSummary, 'meta_chips', 'Woo order'), 'Accepted summary missing Woo order chip.');

    $ready = sbdp_quote_admin_status_summary_smoke_make_quote($repository, $events, $created, 'READY', 'accepted', QuoteConfirmationReadinessService::READY_TO_CONFIRM, 352, 'product', $directAvailability, true, true, false, false);
    $readySummary = sbdp_quote_admin_status_summary_smoke_summarize($repository, $service, (int) $ready['quote_id'], true);
    sbdp_quote_admin_status_summary_smoke_assert_view_model($readySummary, 'ready');
    sbdp_quote_admin_status_summary_smoke_ok((string) ($readySummary['next_action'] ?? '') === 'Bevestig quote', 'Ready quote next_action mismatch.');
    sbdp_quote_admin_status_summary_smoke_ok((string) ($readySummary['readiness_outcome'] ?? '') === QuoteConfirmationReadinessService::READY_TO_CONFIRM, 'Ready quote readiness mismatch.');
    sbdp_quote_admin_status_summary_smoke_ok((string) ($readySummary['communication_status'] ?? '') === 'send_ready', 'Ready communication status mismatch.');
    sbdp_quote_admin_status_summary_smoke_ok(! sbdp_quote_admin_status_summary_smoke_has_chip($readySummary, 'communication_chips', 'Interne review ontbreekt'), 'Ready communication should not show review missing.');
    sbdp_quote_admin_status_summary_smoke_ok(! empty($readySummary['cta_visibility']['confirm_quote']), 'Ready quote should show confirm CTA.');

    $pending = sbdp_quote_admin_status_summary_smoke_make_quote($repository, $events, $created, 'PENDING', 'draft', 'not_ready', 352, 'product', $directAvailability, true, false, false, false, array(), 'pending_review', 'not_ready');
    $pendingSummary = sbdp_quote_admin_status_summary_smoke_summarize($repository, $service, (int) $pending['quote_id']);
    sbdp_quote_admin_status_summary_smoke_assert_view_model($pendingSummary, 'pending');
    sbdp_quote_admin_status_summary_smoke_ok((string) ($pendingSummary['communication_status'] ?? '') === 'control_complete_available', 'Pending communication status mismatch.');
    sbdp_quote_admin_status_summary_smoke_ok((string) ($pendingSummary['proposal_next_action'] ?? '') === 'Controle afronden', 'Pending proposal next_action mismatch.');

    $supplierAvailability = array(
        'bookingMode' => 'supplier_confirmation',
        'supplierProvider' => 'eliio',
        'supplierStatus' => 'supplier_confirmation_required',
    );
    $supplier = sbdp_quote_admin_status_summary_smoke_make_quote($repository, $events, $created, 'SUPPLIER', 'accepted', QuoteConfirmationReadinessService::AWAITING_SUPPLIER_CONFIRMATION, 115, 'product', $supplierAvailability, true, true, false, false, array(), 'not_started', 'not_ready');
    $supplierSummary = sbdp_quote_admin_status_summary_smoke_summarize($repository, $service, (int) $supplier['quote_id']);
    sbdp_quote_admin_status_summary_smoke_assert_view_model($supplierSummary, 'supplier');
    sbdp_quote_admin_status_summary_smoke_ok((string) ($supplierSummary['next_action'] ?? '') === 'Wacht op supplier confirmation', 'Supplier quote next_action mismatch.');
    sbdp_quote_admin_status_summary_smoke_ok(in_array('missing supplier_booking_confirmed', (array) ($supplierSummary['supplier_manual_blockers'] ?? array()), true), 'Supplier blocker missing.');
    sbdp_quote_admin_status_summary_smoke_ok(sbdp_quote_admin_status_summary_smoke_has_chip($supplierSummary, 'blocker_chips', 'missing supplier_booking_confirmed'), 'Supplier blocker chip missing.');
    sbdp_quote_admin_status_summary_smoke_ok(! sbdp_quote_admin_status_summary_smoke_has_chip($supplierSummary, 'blocker_chips', 'Interne review ontbreekt'), 'Communication blocker leaked into supplier blocker chips.');
    sbdp_quote_admin_status_summary_smoke_ok(sbdp_quote_admin_status_summary_smoke_has_chip($supplierSummary, 'communication_chips', 'Supplier confirmation ontbreekt'), 'Supplier communication blocker missing.');
    sbdp_quote_admin_status_summary_smoke_ok(empty($supplierSummary['cta_visibility']['confirm_quote']), 'Supplier quote should not show confirm CTA.');
    sbdp_quote_admin_status_summary_smoke_ok(empty($supplierSummary['cta_visibility']['create_booking_bridge']), 'Supplier quote should not show booking CTA.');

    $manual = sbdp_quote_admin_status_summary_smoke_make_quote($repository, $events, $created, 'MANUAL', 'accepted', QuoteConfirmationReadinessService::REQUIRES_ADMIN_CONFIRMATION, 0, 'manual', array(), true, true, false, false);
    $manualSummary = sbdp_quote_admin_status_summary_smoke_summarize($repository, $service, (int) $manual['quote_id']);
    sbdp_quote_admin_status_summary_smoke_assert_view_model($manualSummary, 'manual');
    sbdp_quote_admin_status_summary_smoke_ok((string) ($manualSummary['next_action'] ?? '') === 'Admin bevestiging nodig', 'Manual quote next_action mismatch.');
    sbdp_quote_admin_status_summary_smoke_ok(in_array('manual/custom', (array) ($manualSummary['supplier_manual_blockers'] ?? array()), true), 'Manual blocker missing.');
    sbdp_quote_admin_status_summary_smoke_ok(sbdp_quote_admin_status_summary_smoke_has_chip($manualSummary, 'blocker_chips', 'manual/custom'), 'Manual blocker chip missing.');

    $hydrationPayload = array(
        'hydration_result' => array(
            'result' => array(
                'cart_url' => 'https://example.test/cart',
                'checkout_url' => 'https://example.test/checkout',
            ),
        ),
    );
    $cart = sbdp_quote_admin_status_summary_smoke_make_quote($repository, $events, $created, 'CART', 'confirmed', 'woo_cart_hydrated', 352, 'product', $directAvailability, true, true, true, true, $hydrationPayload);
    $cartSummary = sbdp_quote_admin_status_summary_smoke_summarize($repository, $service, (int) $cart['quote_id']);
    sbdp_quote_admin_status_summary_smoke_assert_view_model($cartSummary, 'cart');
    sbdp_quote_admin_status_summary_smoke_ok((string) ($cartSummary['next_action'] ?? '') === 'Maak operationele boeking', 'Cart hydrated quote next_action mismatch.');
    sbdp_quote_admin_status_summary_smoke_ok((string) ($cartSummary['cart_url'] ?? '') !== '', 'Cart URL missing.');
    sbdp_quote_admin_status_summary_smoke_ok((string) ($cartSummary['checkout_url'] ?? '') !== '', 'Checkout URL missing.');
    sbdp_quote_admin_status_summary_smoke_ok(! empty($cartSummary['cta_visibility']['open_woo_cart']), 'Cart CTA visibility missing.');
    sbdp_quote_admin_status_summary_smoke_ok(! empty($cartSummary['cta_visibility']['create_booking_bridge']), 'Booking bridge CTA visibility missing.');

    $ops = sbdp_quote_admin_status_summary_smoke_make_quote($repository, $events, $created, 'OPS', 'confirmed', 'operations_ready', 352, 'product', $directAvailability, true, true, true, true, $hydrationPayload, 'not_started', 'not_ready');
    $masterId = sbdp_quote_admin_status_summary_smoke_create_master((int) $ops['order_id']);
    $createdMasterIds[] = $masterId;
    $repository->updateQuote((int) $ops['quote_id'], array('booking_master_id' => $masterId));
    $events->log('quote_booking_bridge_created', null, (int) $ops['quote_id'], (int) $ops['version_id'], null, 'Smoke bridge event.', array('booking_master_id' => $masterId));
    $opsSummary = sbdp_quote_admin_status_summary_smoke_summarize($repository, $service, (int) $ops['quote_id']);
    sbdp_quote_admin_status_summary_smoke_assert_view_model($opsSummary, 'operations');
    sbdp_quote_admin_status_summary_smoke_ok((string) ($opsSummary['next_action'] ?? '') === 'Geen actie nodig / operations ready', 'Operations ready next_action mismatch.');
    sbdp_quote_admin_status_summary_smoke_ok((string) ($opsSummary['operations_status'] ?? '') === 'operations_ready', 'Operations status mismatch.');
    sbdp_quote_admin_status_summary_smoke_ok((string) ($opsSummary['communication_status'] ?? '') === 'control_complete_available', 'Operations communication status mismatch.');
    sbdp_quote_admin_status_summary_smoke_ok(! sbdp_quote_admin_status_summary_smoke_has_chip($opsSummary, 'communication_chips', 'Interne review ontbreekt'), 'Operations communication should not show review missing.');
    sbdp_quote_admin_status_summary_smoke_ok((int) ($opsSummary['booking_master_id'] ?? 0) === $masterId, 'Booking master ID missing.');
    sbdp_quote_admin_status_summary_smoke_ok((int) ($opsSummary['booking_legs_count'] ?? 0) > 0, 'Booking legs count missing.');
    sbdp_quote_admin_status_summary_smoke_ok(sbdp_quote_admin_status_summary_smoke_has_chip($opsSummary, 'meta_chips', 'Booking master'), 'Operations summary missing booking master chip.');
    sbdp_quote_admin_status_summary_smoke_ok(sbdp_quote_admin_status_summary_smoke_has_chip($opsSummary, 'meta_chips', 'Legs'), 'Operations summary missing legs chip.');

    echo wp_json_encode(array(
        'ok' => true,
        'accepted_next_action' => (string) ($acceptedSummary['next_action'] ?? ''),
        'ready_next_action' => (string) ($readySummary['next_action'] ?? ''),
        'pending_communication_status' => (string) ($pendingSummary['communication_status'] ?? ''),
        'supplier_next_action' => (string) ($supplierSummary['next_action'] ?? ''),
        'manual_next_action' => (string) ($manualSummary['next_action'] ?? ''),
        'cart_next_action' => (string) ($cartSummary['next_action'] ?? ''),
        'operations_next_action' => (string) ($opsSummary['next_action'] ?? ''),
        'ready_confirm_cta' => (bool) ($readySummary['cta_visibility']['confirm_quote'] ?? false),
        'cart_open_cta' => (bool) ($cartSummary['cta_visibility']['open_woo_cart'] ?? false),
        'cart_booking_cta' => (bool) ($cartSummary['cta_visibility']['create_booking_bridge'] ?? false),
        'supplier_booking_cta' => (bool) ($supplierSummary['cta_visibility']['create_booking_bridge'] ?? false),
        'manual_blockers' => (array) ($manualSummary['supplier_manual_blockers'] ?? array()),
        'supplier_blockers' => (array) ($supplierSummary['supplier_manual_blockers'] ?? array()),
        'accepted_communication_status' => (string) ($acceptedSummary['communication_status'] ?? ''),
        'ready_communication_status' => (string) ($readySummary['communication_status'] ?? ''),
        'operations_communication_status' => (string) ($opsSummary['communication_status'] ?? ''),
        'operations_communication_blockers' => (array) ($opsSummary['communication_blockers'] ?? array()),
        'booking_master_id' => (int) ($opsSummary['booking_master_id'] ?? 0),
        'booking_legs_count' => (int) ($opsSummary['booking_legs_count'] ?? 0),
        'chain_steps_count' => count((array) ($opsSummary['chain_steps'] ?? array())),
        'meta_chips_count' => count((array) ($opsSummary['meta_chips'] ?? array())),
        'supplier_blocker_chips_count' => count((array) ($supplierSummary['blocker_chips'] ?? array())),
        'summary_mutated_quote_or_events' => false,
        'provider_call_executed' => false,
        'eliio_call_executed' => false,
        'supplier_confirmation_executed' => false,
        'email_sent' => false,
    ), JSON_PRETTY_PRINT) . PHP_EOL;
} finally {
    foreach (array_unique(array_filter(array_map('intval', $createdMasterIds))) as $masterId) {
        foreach (array('bsp_booking_events', 'bsp_booking_legs') as $tableSuffix) {
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
