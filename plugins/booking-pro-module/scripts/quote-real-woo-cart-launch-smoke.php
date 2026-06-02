<?php

if (! function_exists('add_action')) {
    $wpLoad = dirname(__DIR__, 4) . '/wp-load.php';
    if (is_readable($wpLoad)) {
        require_once $wpLoad;
    }
}

use BSP\Quotes\Repository\QuoteRepository;
use BSP\Quotes\Service\QuoteEventLogger;
use BSP\Quotes\Service\QuoteExecutionLaunchService;
use BSP\Quotes\Service\QuoteWooCartHydrationService;
use BSP\Quotes\Service\WooCartLaunchGateway;

function sbdp_quote_real_woo_cart_launch_smoke_fail(string $message): void
{
    if (class_exists('WP_CLI')) {
        WP_CLI::error($message);
    }

    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function sbdp_quote_real_woo_cart_launch_smoke_ok(bool $condition, string $message): void
{
    if (! $condition) {
        sbdp_quote_real_woo_cart_launch_smoke_fail($message);
    }
}

function sbdp_quote_real_woo_cart_launch_smoke_table_count(string $table): ?int
{
    global $wpdb;
    if (! isset($wpdb) || ! $wpdb instanceof wpdb) {
        return null;
    }

    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ((string) $exists !== $table) {
        return null;
    }

    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
}

function sbdp_quote_real_woo_cart_launch_smoke_clear_cart(): void
{
    if (! function_exists('WC') || ! WC()) {
        return;
    }

    if (WC()->cart && method_exists(WC()->cart, 'empty_cart')) {
        WC()->cart->empty_cart();
    }
    if (WC()->session) {
        if (method_exists(WC()->session, '__unset')) {
            WC()->session->__unset('sbdp_quote_handoff_discount');
        } elseif (method_exists(WC()->session, 'set')) {
            WC()->session->set('sbdp_quote_handoff_discount', null);
        }
    }
}

if (
    ! class_exists(QuoteRepository::class)
    || ! class_exists(QuoteExecutionLaunchService::class)
    || ! class_exists(QuoteWooCartHydrationService::class)
    || ! class_exists(WooCartLaunchGateway::class)
) {
    sbdp_quote_real_woo_cart_launch_smoke_fail('Quote real Woo cart launch services are not loaded.');
}
if (! function_exists('WC') || ! function_exists('wc_get_product') || ! function_exists('wc_create_order')) {
    sbdp_quote_real_woo_cart_launch_smoke_fail('WooCommerce is not loaded.');
}

global $wpdb;
sbdp_quote_real_woo_cart_launch_smoke_ok(isset($wpdb) && $wpdb instanceof wpdb, 'WordPress database is not available.');

$productId = 352;
$product = wc_get_product($productId);
sbdp_quote_real_woo_cart_launch_smoke_ok($product instanceof WC_Product, 'Smoke product 352 is not available.');
sbdp_quote_real_woo_cart_launch_smoke_ok($productId !== 115, 'Smoke must not use Eliio product 115.');
sbdp_quote_real_woo_cart_launch_smoke_ok($product->is_purchasable(), 'Smoke product 352 is not purchasable.');

$repository = new QuoteRepository();
$events = new QuoteEventLogger($repository);
$created = array();
$actorId = 42;
$masterTable = $wpdb->prefix . 'bsp_booking_masters';
$legTable = $wpdb->prefix . 'bsp_booking_legs';
$bookingMastersBefore = sbdp_quote_real_woo_cart_launch_smoke_table_count($masterTable);
$bookingLegsBefore = sbdp_quote_real_woo_cart_launch_smoke_table_count($legTable);

try {
    sbdp_quote_real_woo_cart_launch_smoke_clear_cart();

    $request = $repository->createQuoteRequest(array(
        'request_reference' => 'QR-REAL-WOO-LAUNCH-' . gmdate('YmdHis'),
        'status' => 'converted_to_quote',
        'request_summary' => 'Quote real Woo cart launch smoke',
        'requester_name' => 'Smoke Test',
        'requester_email' => 'smoke@example.test',
        'group_size' => 2,
        'preferred_date' => '2026-06-25',
        'preferred_start_time' => '11:00',
        'preferred_end_time' => '12:30',
        'source_type' => 'quote_real_woo_cart_launch_smoke',
    ));
    $quote = $repository->createQuote(array(
        'quote_request_id' => (int) ($request['id'] ?? 0),
        'quote_reference' => 'Q-REAL-WOO-LAUNCH-' . gmdate('YmdHis'),
        'status' => 'confirmed',
        'handoff_status' => 'execution_validated',
        'review_status' => 'approved',
        'send_status' => 'sent_manual',
    ));
    $version = $repository->createQuoteVersion(array(
        'quote_id' => (int) ($quote['id'] ?? 0),
        'version_number' => 1,
        'status' => 'approved',
        'proposal_title' => 'Quote real Woo cart launch smoke',
        'snapshot_type' => 'execution_resnapshot',
        'pricing_confidence' => 'execution_verified',
        'availability_confidence' => 'confirmed',
        'pricing_snapshot_json' => array('source' => 'quote_real_woo_cart_launch_smoke'),
        'handoff_payload_json' => array(),
    ));

    $quoteId = (int) ($quote['id'] ?? 0);
    $versionId = (int) ($version['id'] ?? 0);
    $unitPrice = max(0.01, (float) $product->get_price());
    $created[] = array(
        'request_id' => (int) ($request['id'] ?? 0),
        'quote_id' => $quoteId,
        'version_id' => $versionId,
        'order_id' => 0,
    );

    $order = wc_create_order(array('status' => 'pending'));
    sbdp_quote_real_woo_cart_launch_smoke_ok($order instanceof WC_Order, 'Woo order could not be created.');
    $orderId = (int) $order->get_id();
    $order->update_meta_data('_sbdp_quote_id', $quoteId);
    $order->update_meta_data('_sbdp_quote_version_id', $versionId);
    $order->update_meta_data('_sbdp_quote_reference', (string) ($quote['quote_reference'] ?? ''));
    $order->save();
    $created[0]['order_id'] = $orderId;

    $handoffPayload = array(
        'handoff_package' => array(
            'quote_id' => $quoteId,
            'quote_version_id' => $versionId,
            'woo_order_id' => $orderId,
            'boundary' => 'real_woo_cart_launch_smoke',
        ),
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
                'date' => '2026-06-25',
                'start' => '11:00',
                'end' => '12:30',
                'sbdp_meta' => array(
                    'quote_id' => $quoteId,
                    'quote_version_id' => $versionId,
                    'quote_reference' => (string) ($quote['quote_reference'] ?? ''),
                    'woo_order_id' => $orderId,
                    'sbdp_pricing_source' => 'quote_execution_resnapshot',
                    'booking_mode' => 'direct_internal',
                ),
                'sbdp_summary' => array(
                    'title' => $product->get_name(),
                    'participants' => 2,
                    'service_date' => '2026-06-25',
                    'start_time' => '11:00',
                    'end_time' => '12:30',
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
        'execution_validation' => array(
            'ready_for_runtime_execution' => true,
            'validated_at' => gmdate('Y-m-d H:i:s'),
            'source' => 'quote_real_woo_cart_launch_smoke',
        ),
    );

    $repository->updateQuoteVersion($versionId, array(
        'handoff_payload_json' => $handoffPayload,
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ));
    $repository->updateQuote($quoteId, array(
        'current_version_id' => $versionId,
        'approved_version_id' => $versionId,
        'woo_order_id' => $orderId,
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ));
    $events->log(
        'quote_confirmed',
        (int) ($request['id'] ?? 0),
        $quoteId,
        $versionId,
        $actorId,
        'Smoke confirmed quote event.',
        array(
            'quote_id' => $quoteId,
            'approved_version_id' => $versionId,
            'woo_order_id' => $orderId,
            'source' => 'quote_real_woo_cart_launch_smoke',
        )
    );

    $launch = (new QuoteExecutionLaunchService($repository, $events))->buildWooCartSessionPrep($quoteId, $actorId);
    $afterLaunchQuote = $repository->findQuote($quoteId);
    $hydration = (new QuoteWooCartHydrationService(new WooCartLaunchGateway(), $repository, $events))
        ->hydrateLaunchToCart($quoteId, (string) ($launch['launch_token'] ?? ''), $actorId);
    $afterHydrationQuote = $repository->findQuote($quoteId);
    $afterHydrationVersion = $repository->findQuoteVersion($versionId);
    $afterHydrationPayload = is_array($afterHydrationVersion['handoff_payload_json'] ?? null)
        ? $afterHydrationVersion['handoff_payload_json']
        : array();
    $cart = WC()->cart;
    $cartItems = $cart && method_exists($cart, 'get_cart') ? $cart->get_cart() : array();
    $firstCartItem = is_array($cartItems) ? reset($cartItems) : false;
    $cartMeta = is_array($firstCartItem) && is_array($firstCartItem['sbdp_meta'] ?? null)
        ? $firstCartItem['sbdp_meta']
        : array();
    $orderAfterHydration = wc_get_order($orderId);
    $eventTypes = array_column($repository->listQuoteEvents($quoteId), 'event_type');

    $secondRunBlocked = false;
    $secondRunMessage = '';
    try {
        (new QuoteWooCartHydrationService(new WooCartLaunchGateway(), $repository, $events))
            ->hydrateLaunchToCart($quoteId, (string) ($launch['launch_token'] ?? ''), $actorId);
    } catch (Throwable $exception) {
        $secondRunBlocked = true;
        $secondRunMessage = $exception->getMessage();
    }

    $bookingMastersAfter = sbdp_quote_real_woo_cart_launch_smoke_table_count($masterTable);
    $bookingLegsAfter = sbdp_quote_real_woo_cart_launch_smoke_table_count($legTable);

    sbdp_quote_real_woo_cart_launch_smoke_ok((int) ($launch['quote_version_id'] ?? 0) === $versionId, 'Execution launch did not use approved_version_id.');
    sbdp_quote_real_woo_cart_launch_smoke_ok((string) ($afterLaunchQuote['handoff_status'] ?? '') === 'execution_launch_ready', 'Execution launch did not set execution_launch_ready.');
    sbdp_quote_real_woo_cart_launch_smoke_ok((string) ($afterHydrationQuote['status'] ?? '') === 'confirmed', 'Woo cart hydration changed quote status.');
    sbdp_quote_real_woo_cart_launch_smoke_ok((string) ($afterHydrationQuote['handoff_status'] ?? '') === 'woo_cart_hydrated', 'Woo cart hydration did not set woo_cart_hydrated.');
    sbdp_quote_real_woo_cart_launch_smoke_ok(empty($afterHydrationQuote['booking_master_id']), 'Woo cart hydration should not set booking_master_id.');
    sbdp_quote_real_woo_cart_launch_smoke_ok((string) ($hydration['cart_url'] ?? '') !== '', 'Woo cart hydration did not return cart_url.');
    sbdp_quote_real_woo_cart_launch_smoke_ok((string) ($hydration['checkout_url'] ?? '') !== '', 'Woo cart hydration did not return checkout_url.');
    sbdp_quote_real_woo_cart_launch_smoke_ok(in_array('quote_woo_cart_hydrated', $eventTypes, true), 'quote_woo_cart_hydrated event missing.');
    sbdp_quote_real_woo_cart_launch_smoke_ok((int) ($cartMeta['quote_id'] ?? 0) === $quoteId, 'Cart item lost quote_id meta.');
    sbdp_quote_real_woo_cart_launch_smoke_ok((int) ($cartMeta['quote_version_id'] ?? 0) === $versionId, 'Cart item lost quote_version_id meta.');
    sbdp_quote_real_woo_cart_launch_smoke_ok($orderAfterHydration instanceof WC_Order, 'Woo order disappeared.');
    sbdp_quote_real_woo_cart_launch_smoke_ok((int) $orderAfterHydration->get_meta('_sbdp_quote_id') === $quoteId, 'Woo order lost quote_id meta.');
    sbdp_quote_real_woo_cart_launch_smoke_ok((int) $orderAfterHydration->get_meta('_sbdp_quote_version_id') === $versionId, 'Woo order lost quote_version_id meta.');
    sbdp_quote_real_woo_cart_launch_smoke_ok($bookingMastersBefore === $bookingMastersAfter, 'Booking masters count changed.');
    sbdp_quote_real_woo_cart_launch_smoke_ok($bookingLegsBefore === $bookingLegsAfter, 'Booking legs count changed.');
    sbdp_quote_real_woo_cart_launch_smoke_ok($secondRunBlocked, 'Second launch token use should fail closed.');

    echo wp_json_encode(array(
        'ok' => true,
        'product_id' => $productId,
        'initial_handoff_status' => 'execution_validated',
        'launch_handoff_status' => (string) ($afterLaunchQuote['handoff_status'] ?? ''),
        'final_handoff_status' => (string) ($afterHydrationQuote['handoff_status'] ?? ''),
        'final_quote_status' => (string) ($afterHydrationQuote['status'] ?? ''),
        'approved_version_id' => $versionId,
        'launch_version_id' => (int) ($launch['quote_version_id'] ?? 0),
        'woo_order_id' => $orderId,
        'order_quote_meta_present' => true,
        'cart_item_count' => (int) ($hydration['cart_item_count'] ?? 0),
        'cart_url' => (string) ($hydration['cart_url'] ?? ''),
        'checkout_url' => (string) ($hydration['checkout_url'] ?? ''),
        'cart_quote_meta_present' => true,
        'launch_token_consumed' => ! empty($afterHydrationPayload['execution_launch']['consumed_at']),
        'hydration_result_stored' => isset($afterHydrationPayload['hydration_result']['result']),
        'quote_woo_cart_hydrated_event_logged' => in_array('quote_woo_cart_hydrated', $eventTypes, true),
        'second_run_blocked' => $secondRunBlocked,
        'second_run_message' => $secondRunMessage,
        'booking_master_id_empty' => empty($afterHydrationQuote['booking_master_id']),
        'booking_masters_unchanged' => $bookingMastersBefore === $bookingMastersAfter,
        'booking_legs_unchanged' => $bookingLegsBefore === $bookingLegsAfter,
        'real_woo_cart_hydration_executed' => true,
        'booking_created' => false,
        'provider_call_executed' => false,
        'eliio_call_executed' => false,
        'supplier_call_executed' => false,
        'supplier_confirmation_executed' => false,
        'email_sent' => false,
    ), JSON_PRETTY_PRINT) . PHP_EOL;
} finally {
    sbdp_quote_real_woo_cart_launch_smoke_clear_cart();

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
    }
}
