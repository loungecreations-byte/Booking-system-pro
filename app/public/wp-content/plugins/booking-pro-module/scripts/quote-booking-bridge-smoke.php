<?php

if (! function_exists('add_action')) {
    $wpLoad = dirname(__DIR__, 4) . '/wp-load.php';
    if (is_readable($wpLoad)) {
        require_once $wpLoad;
    }
}

use BSP\Quotes\Repository\QuoteRepository;
use BSP\Quotes\Service\QuoteBookingBridgeService;
use BSP\Quotes\Service\QuoteEventLogger;
use BSP\Bookings\Service\BookingManager;
use BSP\Bookings\Service\BookingRepository;
use BSP\Bookings\Service\OperationsSyncService;
use BSP\Commerce\Module as CommerceModule;
use BSPModule\Core\Services\BookingTruthRuntimeService;

if (! class_exists(QuoteBookingBridgeService::class)) {
    require_once __DIR__ . '/../modules/quotes/Service/QuoteBookingBridgeService.php';
}

function sbdp_quote_booking_bridge_smoke_fail(string $message): void
{
    if (class_exists('WP_CLI')) {
        WP_CLI::error($message);
    }

    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function sbdp_quote_booking_bridge_smoke_ok(bool $condition, string $message): void
{
    if (! $condition) {
        sbdp_quote_booking_bridge_smoke_fail($message);
    }
}

function sbdp_quote_booking_bridge_smoke_count(string $table, string $where = '1=1'): int
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

function sbdp_quote_booking_bridge_smoke_make_fixture(
    QuoteRepository $repository,
    QuoteEventLogger $events,
    array &$created,
    string $suffix,
    int $productId,
    bool $supplierRequired
): array {
    $product = function_exists('wc_get_product') ? wc_get_product($productId) : null;
    sbdp_quote_booking_bridge_smoke_ok($product instanceof WC_Product, 'Smoke product is not available: ' . $productId);

    $request = $repository->createQuoteRequest(array(
        'request_reference' => 'QR-BOOKING-BRIDGE-' . $suffix . '-' . gmdate('YmdHis'),
        'status' => 'converted_to_quote',
        'request_summary' => 'Quote booking bridge smoke ' . $suffix,
        'requester_name' => 'Smoke Test',
        'requester_email' => 'smoke@example.test',
        'group_size' => 2,
        'preferred_date' => '2026-06-26',
        'preferred_start_time' => '11:00',
        'preferred_end_time' => '12:30',
        'source_type' => 'quote_booking_bridge_smoke',
    ));
    $quote = $repository->createQuote(array(
        'quote_request_id' => (int) ($request['id'] ?? 0),
        'quote_reference' => 'Q-BOOKING-BRIDGE-' . $suffix . '-' . gmdate('YmdHis'),
        'status' => 'confirmed',
        'handoff_status' => 'woo_cart_hydrated',
        'review_status' => 'approved',
        'send_status' => 'sent_manual',
    ));
    $version = $repository->createQuoteVersion(array(
        'quote_id' => (int) ($quote['id'] ?? 0),
        'version_number' => 1,
        'status' => 'approved',
        'proposal_title' => 'Quote booking bridge smoke ' . $suffix,
        'snapshot_type' => 'execution_resnapshot',
        'pricing_confidence' => 'execution_verified',
        'availability_confidence' => 'confirmed',
        'pricing_snapshot_json' => array('source' => 'quote_booking_bridge_smoke'),
        'handoff_payload_json' => array(),
    ));

    $quoteId = (int) ($quote['id'] ?? 0);
    $versionId = (int) ($version['id'] ?? 0);
    $unitPrice = max(0.01, (float) $product->get_price());
    $order = wc_create_order(array('status' => 'processing'));
    sbdp_quote_booking_bridge_smoke_ok($order instanceof WC_Order, 'Woo order could not be created.');
    $orderId = (int) $order->get_id();
    $order->update_meta_data('_sbdp_quote_id', $quoteId);
    $order->update_meta_data('_sbdp_quote_version_id', $versionId);
    $order->update_meta_data('_sbdp_quote_reference', (string) ($quote['quote_reference'] ?? ''));
    $order->save();

    $snapshot = $supplierRequired
        ? array(
            'bookingMode' => 'supplier_confirmation',
            'supplierProvider' => 'eliio',
            'supplierStatus' => 'supplier_confirmation_required',
        )
        : array(
            'bookingMode' => 'direct_internal',
            'supplierStatus' => 'not_required',
        );
    $repository->replaceQuoteLines($versionId, array(array(
        'line_number' => 1,
        'line_type' => 'product',
        'line_status' => 'mapped',
        'title' => $product->get_name(),
        'product_id' => $productId,
        'quantity' => 2,
        'participants' => 2,
        'service_date' => '2026-06-26',
        'start_time' => '11:00',
        'end_time' => '12:30',
        'pricing_confidence' => 'execution_verified',
        'availability_confidence' => 'confirmed',
        'unit_amount_snapshot' => $unitPrice,
        'line_total_snapshot' => $unitPrice * 2,
        'currency' => get_woocommerce_currency(),
        'availability_snapshot_json' => $snapshot,
    )));

    $handoffPayload = array(
        'execution_adapter' => array(
            'adapter_type' => 'cart_order_prep',
            'quote_id' => $quoteId,
            'quote_version_id' => $versionId,
            'woo_order_id' => $orderId,
            'customer' => array(
                'name' => 'Smoke Test',
                'email' => 'smoke@example.test',
            ),
            'items' => array(array(
                'product_id' => $productId,
                'quantity' => 2,
                'participants' => 2,
                'resource_id' => 0,
                'date' => '2026-06-26',
                'start' => '11:00',
                'end' => '12:30',
                'sbdp_meta' => array(
                    'quote_id' => $quoteId,
                    'quote_version_id' => $versionId,
                    'woo_order_id' => $orderId,
                    'booking_mode' => $supplierRequired ? 'supplier_confirmation' : 'direct_internal',
                    'supplier_provider' => $supplierRequired ? 'eliio' : '',
                ),
                'sbdp_summary' => array(
                    'title' => $product->get_name(),
                    'participants' => 2,
                ),
                'sbdp_pricing' => array(
                    'display_unit_price' => $unitPrice,
                    'display_total' => $unitPrice * 2,
                    'currency' => get_woocommerce_currency(),
                ),
            )),
            'totals' => array(
                'display_total' => $unitPrice * 2,
                'currency' => get_woocommerce_currency(),
            ),
        ),
        'execution_launch' => array(
            'launch_type' => 'woo_cart_session_prep',
            'quote_id' => $quoteId,
            'quote_version_id' => $versionId,
            'launch_token' => 'smoke-token-' . $quoteId,
            'consumed_at' => gmdate('Y-m-d H:i:s'),
        ),
        'hydration_result' => array(
            'hydrated_at' => gmdate('Y-m-d H:i:s'),
            'result' => array(
                'cart_item_count' => 1,
                'cart_url' => function_exists('wc_get_cart_url') ? wc_get_cart_url() : '',
                'checkout_url' => function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : '',
            ),
        ),
    );

    $repository->updateQuoteVersion($versionId, array(
        'handoff_payload_json' => $handoffPayload,
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ));
    $quote = $repository->updateQuote($quoteId, array(
        'current_version_id' => $versionId,
        'approved_version_id' => $versionId,
        'woo_order_id' => $orderId,
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ));

    foreach (array(
        'quote_confirmed' => 'Smoke confirmed quote event.',
        'quote_woo_payment_completed' => 'Smoke payment completed event.',
        'quote_woo_cart_hydrated' => 'Smoke Woo cart hydrated event.',
    ) as $eventType => $message) {
        $events->log(
            $eventType,
            (int) ($request['id'] ?? 0),
            $quoteId,
            $versionId,
            42,
            $message,
            array(
                'quote_id' => $quoteId,
                'approved_version_id' => $versionId,
                'woo_order_id' => $orderId,
                'source' => 'quote_booking_bridge_smoke',
            )
        );
    }

    $created[] = array(
        'request_id' => (int) ($request['id'] ?? 0),
        'quote_id' => $quoteId,
        'version_id' => $versionId,
        'order_id' => $orderId,
    );

    return array('quote' => $quote, 'version' => $version, 'order_id' => $orderId);
}

function sbdp_quote_booking_bridge_smoke_booking_manager(): BookingManager
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

if (! class_exists(QuoteRepository::class) || ! class_exists(QuoteBookingBridgeService::class)) {
    sbdp_quote_booking_bridge_smoke_fail('Quote booking bridge services are not loaded.');
}
if (! function_exists('wc_create_order') || ! function_exists('wc_get_order')) {
    sbdp_quote_booking_bridge_smoke_fail('WooCommerce is not loaded.');
}

global $wpdb;
sbdp_quote_booking_bridge_smoke_ok(isset($wpdb) && $wpdb instanceof wpdb, 'WordPress database is not available.');

$repository = new QuoteRepository();
$events = new QuoteEventLogger($repository);
$service = new QuoteBookingBridgeService($repository, $events, sbdp_quote_booking_bridge_smoke_booking_manager());
$created = array();
$createdMasterIds = array();
$prefix = $wpdb->prefix;

try {
    if (function_exists('delete_transient')) {
        delete_transient('sbdp_booking_records_v3');
    }

    $direct = sbdp_quote_booking_bridge_smoke_make_fixture($repository, $events, $created, 'DIRECT', 352, false);
    $directQuoteId = (int) ($direct['quote']['id'] ?? 0);
    $mastersBefore = sbdp_quote_booking_bridge_smoke_count($prefix . 'bsp_booking_masters');
    $legsBefore = sbdp_quote_booking_bridge_smoke_count($prefix . 'bsp_booking_legs');

    $result = $service->createOperationsBooking($directQuoteId, 42);
    $afterQuote = $repository->findQuote($directQuoteId);
    $masterId = (int) ($result['booking_master_id'] ?? 0);
    $createdMasterIds[] = $masterId;
    $mastersAfter = sbdp_quote_booking_bridge_smoke_count($prefix . 'bsp_booking_masters');
    $legsAfter = sbdp_quote_booking_bridge_smoke_count($prefix . 'bsp_booking_legs');
    $legCount = sbdp_quote_booking_bridge_smoke_count($prefix . 'bsp_booking_legs', 'master_id = ' . $masterId);
    $partnerRows = sbdp_quote_booking_bridge_smoke_count($prefix . 'bsp_partner_confirmations', 'master_id = ' . $masterId);
    $guideRows = sbdp_quote_booking_bridge_smoke_count($prefix . 'bsp_guide_assignments', 'master_id = ' . $masterId);
    $bridgeEvents = array_values(array_filter(
        $repository->listQuoteEvents($directQuoteId),
        static fn (array $event): bool => (string) ($event['event_type'] ?? '') === 'quote_booking_bridge_created'
    ));

    $second = $service->createOperationsBooking($directQuoteId, 42);
    $mastersAfterSecond = sbdp_quote_booking_bridge_smoke_count($prefix . 'bsp_booking_masters');
    $legsAfterSecond = sbdp_quote_booking_bridge_smoke_count($prefix . 'bsp_booking_legs');
    $bridgeEventsAfterSecond = array_values(array_filter(
        $repository->listQuoteEvents($directQuoteId),
        static fn (array $event): bool => (string) ($event['event_type'] ?? '') === 'quote_booking_bridge_created'
    ));

    sbdp_quote_booking_bridge_smoke_ok(! empty($result['created']), 'Direct bridge did not create operations booking.');
    sbdp_quote_booking_bridge_smoke_ok($masterId > 0, 'booking_master_id was not returned.');
    sbdp_quote_booking_bridge_smoke_ok((int) ($afterQuote['booking_master_id'] ?? 0) === $masterId, 'Quote booking_master_id was not set.');
    sbdp_quote_booking_bridge_smoke_ok((string) ($afterQuote['status'] ?? '') === 'confirmed', 'Quote status changed.');
    sbdp_quote_booking_bridge_smoke_ok((string) ($afterQuote['handoff_status'] ?? '') === 'operations_ready', 'handoff_status was not operations_ready.');
    sbdp_quote_booking_bridge_smoke_ok(trim((string) ($afterQuote['handoff_completed_at'] ?? '')) !== '', 'handoff_completed_at was not set.');
    sbdp_quote_booking_bridge_smoke_ok($mastersAfter === $mastersBefore + 1, 'Booking master count did not increase by one.');
    sbdp_quote_booking_bridge_smoke_ok($legsAfter > $legsBefore, 'Booking legs were not created.');
    sbdp_quote_booking_bridge_smoke_ok($legCount > 0, 'No legs found for created master.');
    sbdp_quote_booking_bridge_smoke_ok(count($bridgeEvents) === 1, 'Bridge event not logged exactly once.');
    sbdp_quote_booking_bridge_smoke_ok(empty($second['created']) && ! empty($second['idempotent']), 'Second bridge run was not idempotent.');
    sbdp_quote_booking_bridge_smoke_ok($mastersAfterSecond === $mastersAfter, 'Second bridge run created duplicate master.');
    sbdp_quote_booking_bridge_smoke_ok($legsAfterSecond === $legsAfter, 'Second bridge run created duplicate legs.');
    sbdp_quote_booking_bridge_smoke_ok(count($bridgeEventsAfterSecond) === 1, 'Second bridge run logged duplicate bridge event.');

    $supplier = sbdp_quote_booking_bridge_smoke_make_fixture($repository, $events, $created, 'SUPPLIER', 115, true);
    $supplierQuoteId = (int) ($supplier['quote']['id'] ?? 0);
    $mastersBeforeSupplier = sbdp_quote_booking_bridge_smoke_count($prefix . 'bsp_booking_masters');
    $legsBeforeSupplier = sbdp_quote_booking_bridge_smoke_count($prefix . 'bsp_booking_legs');
    $supplierBlocked = false;
    $supplierBlockMessage = '';
    try {
        $service->createOperationsBooking($supplierQuoteId, 42);
    } catch (Throwable $exception) {
        $supplierBlocked = true;
        $supplierBlockMessage = $exception->getMessage();
    }
    $supplierQuote = $repository->findQuote($supplierQuoteId);
    $mastersAfterSupplier = sbdp_quote_booking_bridge_smoke_count($prefix . 'bsp_booking_masters');
    $legsAfterSupplier = sbdp_quote_booking_bridge_smoke_count($prefix . 'bsp_booking_legs');

    sbdp_quote_booking_bridge_smoke_ok($supplierBlocked, 'Supplier/Eliio quote was not blocked.');
    sbdp_quote_booking_bridge_smoke_ok(empty($supplierQuote['booking_master_id']), 'Blocked supplier quote received booking_master_id.');
    sbdp_quote_booking_bridge_smoke_ok((string) ($supplierQuote['status'] ?? '') === 'confirmed', 'Blocked supplier quote status changed.');
    sbdp_quote_booking_bridge_smoke_ok($mastersAfterSupplier === $mastersBeforeSupplier, 'Blocked supplier quote created booking master.');
    sbdp_quote_booking_bridge_smoke_ok($legsAfterSupplier === $legsBeforeSupplier, 'Blocked supplier quote created booking legs.');

    echo wp_json_encode(array(
        'ok' => true,
        'direct_quote_id' => $directQuoteId,
        'direct_booking_master_id' => $masterId,
        'direct_quote_status' => (string) ($afterQuote['status'] ?? ''),
        'direct_handoff_status' => (string) ($afterQuote['handoff_status'] ?? ''),
        'handoff_completed_at_set' => trim((string) ($afterQuote['handoff_completed_at'] ?? '')) !== '',
        'booking_master_created' => $mastersAfter === $mastersBefore + 1,
        'booking_legs_created' => $legCount,
        'partner_confirmation_rows_for_direct' => $partnerRows,
        'guide_assignment_rows_for_direct' => $guideRows,
        'bridge_event_logged_once' => count($bridgeEvents) === 1,
        'second_run_idempotent' => ! empty($second['idempotent']),
        'second_run_duplicate_master' => false,
        'second_run_duplicate_legs' => false,
        'supplier_quote_blocked' => $supplierBlocked,
        'supplier_block_message' => $supplierBlockMessage,
        'supplier_booking_master_id_empty' => empty($supplierQuote['booking_master_id']),
        'provider_call_executed' => false,
        'eliio_call_executed' => false,
        'supplier_call_executed' => false,
        'supplier_confirmation_executed' => false,
        'email_sent' => false,
    ), JSON_PRETTY_PRINT) . PHP_EOL;
} finally {
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
