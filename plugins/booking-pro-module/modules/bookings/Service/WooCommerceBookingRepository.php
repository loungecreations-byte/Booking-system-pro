<?php

declare(strict_types=1);

namespace BSP\Bookings\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

/**
 * Booking repository backed by WooCommerce orders.
 */
final class WooCommerceBookingRepository implements BookingRepositoryInterface
{
    private const META_BOOKING_DATE = '_sbdp_booking_date';
    private const META_BOOKING_TIME = '_sbdp_booking_time';
    private const META_BOOKING_END_DATE = '_sbdp_booking_end_date';
    private const META_BOOKING_END_TIME = '_sbdp_booking_end_time';
    private const META_PARTICIPANTS = '_sbdp_booking_participants';
    private const META_CHANNEL = '_sbdp_booking_channel';
    private const META_VENDOR_ID = '_sbdp_booking_vendor_id';
    private const META_VENDOR_NAME = '_sbdp_booking_vendor_name';
    private const META_RESOURCE = '_sbdp_booking_resource';
    private const META_PRICING_RULES = '_sbdp_booking_pricing_rules';
    private const META_PRICING_SNAPSHOT = '_sbdp_booking_pricing_snapshot';
    private const META_PAYMENT_REQUEST = '_sbdp_booking_payment_request';

    /**
     * Map WooCommerce order statuses to booking board statuses.
     *
     * @var array<string, string>
     */
    private const STATUS_FROM_ORDER = array(
        'pending'    => 'pending',
        'on-hold'    => 'requested',
        'processing' => 'paid',
        'completed'  => 'completed',
        'cancelled'  => 'cancelled',
        'refunded'   => 'cancelled',
        'failed'     => 'cancelled',
        'draft'      => 'created',
    );

    /**
     * Map booking board statuses to WooCommerce order statuses.
     *
     * @var array<string, string>
     */
    private const STATUS_TO_ORDER = array(
        'created'   => 'pending',
        'pending'   => 'pending',
        'requested' => 'on-hold',
        'captured'  => 'processing',
        'paid'      => 'processing',
        'completed' => 'completed',
        'cancelled' => 'cancelled',
        'conflict'  => 'on-hold',
    );

    public static function isSupported(): bool
    {
        return function_exists('wc_get_orders')
            && function_exists('wc_get_order_statuses')
            && function_exists('wc_create_order')
            && class_exists(WC_Order::class);
    }

    /**
     * @param array<string, mixed> $booking
     *
     * @return array<string, mixed>
     */
    public function create(array $booking): array
    {
        BookingRepositoryWriteGuard::assertWriteAllowed(__METHOD__);
        $this->assertSupported();

        $status = isset($booking['status']) ? (string) $booking['status'] : 'created';

        $order = wc_create_order(
            array(
                'status' => $this->mapBookingStatusToOrderStatus($status),
            )
        );

        if (function_exists('is_wp_error') && is_wp_error($order)) {
            throw new InvalidArgumentException($order->get_error_message());
        }

        if (! $order instanceof WC_Order) {
            throw new InvalidArgumentException('Unable to create WooCommerce order for booking.');
        }

        $this->syncBookingToOrder($order, $booking, true);
        $order->save();

        return $this->mapOrderToBooking($order);
    }

    public function find(int $id): ?array
    {
        $this->assertSupported();

        $order = wc_get_order($id);
        if (! $order instanceof WC_Order) {
            return null;
        }

        return $this->mapOrderToBooking($order);
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    public function update(int $id, array $changes): array
    {
        BookingRepositoryWriteGuard::assertWriteAllowed(__METHOD__);
        $this->assertSupported();

        $order = wc_get_order($id);
        if (! $order instanceof WC_Order) {
            throw new InvalidArgumentException('Unknown booking identifier.');
        }

        $current = $this->mapOrderToBooking($order);
        $updated = array_replace_recursive($current, $changes);

        $this->syncBookingToOrder($order, $updated, isset($changes['items']));
        $order->save();

        return $this->mapOrderToBooking($order);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $this->assertSupported();

        $args = array(
            'limit'   => -1,
            'orderby' => 'date',
            'order'   => 'DESC',
            'status'  => array_keys(wc_get_order_statuses()),
            'return'  => 'objects',
        );

        if (function_exists('apply_filters')) {
            /** @var array<string, mixed> $args */
            $args = apply_filters('sbdp/booking/woocommerce_repository_args', $args);
        }

        if (isset($args['max_records'])) {
            unset($args['max_records']);
        }

        $fetchAll = ! empty($args['fetch_all']);
        if ($fetchAll) {
            unset($args['fetch_all']);
        }

        $orders = $fetchAll
            ? $this->fetchAllOrders($args)
            : $this->runOrderQuery($args);

        if ($orders === array()) {
            return array();
        }

        $bookings = array();

        foreach ($orders as $order) {
            if ($order instanceof WC_Order) {
                $bookings[] = $this->mapOrderToBooking($order);
            }
        }

        return $bookings;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allWithConstraints(int $pageSize, int $maxRecords, ?DateTimeImmutable $cutoff): array
    {
        $this->assertSupported();

        $pageSize   = $pageSize > 0 ? $pageSize : 250;
        $maxRecords = $maxRecords > 0 ? $maxRecords : 0;

        $args = array(
            'limit'   => $pageSize,
            'paginate'=> true,
            'orderby' => 'date',
            'order'   => 'DESC',
            'status'  => array_keys(wc_get_order_statuses()),
        );

        $orders = $this->fetchAllOrders($args, $maxRecords, $cutoff);

        if ($orders === array()) {
            return array();
        }

        $bookings = array();

        foreach ($orders as $order) {
            if ($order instanceof WC_Order) {
                $bookings[] = $this->mapOrderToBooking($order);
            }
        }

        return $bookings;
    }

    public function reset(): void
    {
        BookingRepositoryWriteGuard::assertResetAllowed(__METHOD__);
        // WooCommerce orders are persistent; reset is a no-op.
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<int, WC_Order>
     */
    private function runOrderQuery(array $args): array
    {
        $result = $this->queryOrders($args);

        return $result['orders'];
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<int, WC_Order>
     */
    private function fetchAllOrders(array $args, int $maxRecords = 0, ?DateTimeImmutable $cutoff = null): array
    {
        $orders        = array();
        $limit         = isset($args['limit']) ? max(1, (int) $args['limit']) : 10;
        $page          = isset($args['page']) ? max(1, (int) $args['page']) : 1;
        $paginate      = ! empty($args['paginate']);
        $cutoffTs      = $cutoff instanceof DateTimeImmutable ? $cutoff->getTimestamp() : null;
        $shouldStop    = false;

        do {
            $args['page'] = $page;

            $result = $this->queryOrders($args);
            if ($result['orders'] === array()) {
                break;
            }

            $orders = array_merge($orders, $result['orders']);

            if ($maxRecords > 0 && count($orders) >= $maxRecords) {
                $orders = array_slice($orders, 0, $maxRecords);
                break;
            }

            if ($cutoffTs !== null) {
                $lastOrder = end($result['orders']);
                if ($lastOrder instanceof WC_Order) {
                    $created = $lastOrder->get_date_created();
                    if ($created instanceof \WC_DateTime && $created->getTimestamp() < $cutoffTs) {
                        $shouldStop = true;
                    }
                }
                reset($result['orders']);
            }

            if ($result['max_pages'] !== null) {
                if ($page >= $result['max_pages']) {
                    break;
                }
            } elseif (! $paginate || count($result['orders']) < $limit) {
                break;
            }

            if ($shouldStop) {
                break;
            }

            $page++;
        } while (true);

        return $orders;
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array{orders: array<int, WC_Order>, max_pages: int|null}
     */
    private function queryOrders(array $args): array
    {
        $orders = array();
        $max    = null;

        $result = wc_get_orders($args);

        if (is_array($result)) {
            if (isset($result['orders']) && is_array($result['orders'])) {
                foreach ($result['orders'] as $order) {
                    if ($order instanceof WC_Order) {
                        $orders[] = $order;
                    }
                }

                if (isset($result['max_num_pages'])) {
                    $max = (int) $result['max_num_pages'];
                }
            } else {
                foreach ($result as $order) {
                    if ($order instanceof WC_Order) {
                        $orders[] = $order;
                    }
                }
            }
        }

        return array(
            'orders'    => $orders,
            'max_pages' => $max,
        );
    }

    /**
     * @param array<string, mixed> $booking
     */
    private function syncBookingToOrder(WC_Order $order, array $booking, bool $replaceItems): void
    {
        if (isset($booking['status'])) {
            $order->set_status($this->mapBookingStatusToOrderStatus((string) $booking['status']));
        }

        if (isset($booking['currency']) && method_exists($order, 'set_currency')) {
            $currency = (string) $booking['currency'];
            if ($currency !== '') {
                $order->set_currency($currency);
            }
        }

        if ($replaceItems && isset($booking['items']) && is_array($booking['items'])) {
            foreach (array_keys($order->get_items()) as $itemId) {
                $order->remove_item($itemId, true);
            }

            foreach ($booking['items'] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $productId = (int) ($item['product_id'] ?? 0);
                $quantity  = (int) ($item['quantity'] ?? 1);
                $unitPrice = (float) ($item['unit_price'] ?? 0.0);
                $label     = isset($item['label']) ? (string) $item['label'] : '';
                $rowTotal  = round($unitPrice * max(1, $quantity), 2);

                $product = null;
                if ($productId > 0 && function_exists('wc_get_product')) {
                    $productCandidate = wc_get_product($productId);
                    if ($productCandidate instanceof WC_Product) {
                        $product = $productCandidate;
                    }
                }

                if ($product instanceof WC_Product) {
                    $order->add_product(
                        $product,
                        max(1, $quantity),
                        array(
                            'subtotal' => $rowTotal,
                            'total'    => $rowTotal,
                        )
                    );
                } else {
                    $fallbackLabel = $label !== '' ? $label : sprintf('Item %d', $productId > 0 ? $productId : count($order->get_items()) + 1);
                    $order->add_fee(
                        array(
                            'name'  => $fallbackLabel,
                            'total' => $rowTotal,
                        )
                    );
                }
            }
        }

        if (isset($booking['notes'])) {
            $order->set_customer_note((string) $booking['notes']);
        }

        if ($replaceItems) {
            $order->calculate_totals($this->shouldRecalculateOrderTaxes($booking));
        } elseif ($this->shouldApplyExplicitTotalOverride($booking) && isset($booking['total'])) {
            $order->set_total((float) $booking['total']);
        }

        if (isset($booking['customer']) && is_array($booking['customer'])) {
            $this->applyCustomer($order, $booking['customer']);
        }

        $this->updateMetaData($order, $booking);

        if (isset($booking['payment']) && is_array($booking['payment'])) {
            $method = isset($booking['payment']['method']) ? (string) $booking['payment']['method'] : '';
            if ($method !== '' && method_exists($order, 'set_payment_method')) {
                $order->set_payment_method($method);
            }

            $reference = isset($booking['payment']['reference']) ? (string) $booking['payment']['reference'] : '';
            if ($reference !== '' && method_exists($order, 'set_transaction_id')) {
                $order->set_transaction_id($reference);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function mapOrderToBooking(WC_Order $order): array
    {
        $date      = $this->getMetaString($order, self::META_BOOKING_DATE);
        $time      = $this->getMetaString($order, self::META_BOOKING_TIME);
        $dateEnd   = $this->getMetaString($order, self::META_BOOKING_END_DATE);
        $timeEnd   = $this->getMetaString($order, self::META_BOOKING_END_TIME);
        $channel   = $this->normalizeChannel($this->getMetaString($order, self::META_CHANNEL));
        $vendorId  = (int) $order->get_meta(self::META_VENDOR_ID, true);
        $vendor    = null;
        $vendorName = $this->getMetaString($order, self::META_VENDOR_NAME);
        if ($vendorId > 0) {
            $vendor = array(
                'id'   => $vendorId,
                'name' => $vendorName,
            );
        }

        $pricingRulesRaw = $order->get_meta(self::META_PRICING_RULES, true);
        $pricingRules    = is_string($pricingRulesRaw) && $pricingRulesRaw !== ''
            ? json_decode($pricingRulesRaw, true)
            : array();
        if (! is_array($pricingRules)) {
            $pricingRules = array();
        }

        $pricingSnapshotRaw = $order->get_meta(self::META_PRICING_SNAPSHOT, true);
        $pricingSnapshot = is_string($pricingSnapshotRaw) && $pricingSnapshotRaw !== ''
            ? json_decode($pricingSnapshotRaw, true)
            : null;
        if ($pricingSnapshot !== null && ! is_array($pricingSnapshot)) {
            $pricingSnapshot = null;
        }

        $paymentRequestRaw = $order->get_meta(self::META_PAYMENT_REQUEST, true);
        $paymentRequest    = is_string($paymentRequestRaw) && $paymentRequestRaw !== ''
            ? json_decode($paymentRequestRaw, true)
            : null;
        if ($paymentRequest !== null && ! is_array($paymentRequest)) {
            $paymentRequest = null;
        }

        $resourceMeta = $order->get_meta(self::META_RESOURCE, true);
        $resource     = is_array($resourceMeta) ? $resourceMeta : ($resourceMeta !== '' ? $resourceMeta : null);

        $participantsMeta = $order->get_meta(self::META_PARTICIPANTS, true);
        $participants     = (int) (is_numeric($participantsMeta) ? $participantsMeta : $this->deriveParticipants($order));
        if ($participants <= 0) {
            $participants = $this->deriveParticipants($order);
        }

        $dateCreated  = $order->get_date_created();
        $dateModified = $order->get_date_modified() ?: $dateCreated;

        if ($date === '') {
            $date = $this->formatDate($dateCreated, 'Y-m-d');
        }

        if ($time === '') {
            $time = $this->formatDate($dateCreated, 'H:i');
        }

        if ($dateEnd === '') {
            $dateEnd = $date;
        }

        if ($timeEnd === '') {
            $timeEnd = $time;
        }

        $orderStatus = $order->get_status();
        $bookingStatus = $this->mapOrderStatusToBooking($orderStatus);

        $orderDetails = array(
            'id'          => $order->get_id(),
            'number'      => $order->get_order_number(),
            'status'      => $orderStatus,
            'currency'    => $order->get_currency(),
            'total'       => (float) $order->get_total(),
            'created_at'  => $this->formatIso($dateCreated),
            'updated_at'  => $this->formatIso($dateModified),
        );

        if (function_exists('wc_get_order_status_name')) {
            $orderDetails['status_label'] = wc_get_order_status_name($orderStatus);
        }

        $payment = array(
            'method'    => $order->get_payment_method(),
            'reference' => $order->get_transaction_id(),
        );
        if ($payment['method'] === '' && $payment['reference'] === '') {
            $payment = null;
        }

        return array(
            'id'                 => $order->get_id(),
            'status'             => $bookingStatus,
            'customer'           => $this->mapCustomer($order),
            'date'               => $date,
            'time'               => $time,
            'date_end'           => $dateEnd,
            'time_end'           => $timeEnd,
            'duration_minutes'   => null,
            'participants'       => $participants,
            'items'              => $this->mapOrderItems($order),
            'notes'              => $order->get_customer_note(),
            'currency'           => $order->get_currency(),
            'total'              => (float) $order->get_total(),
            'created_at'         => $this->formatIso($dateCreated),
            'updated_at'         => $this->formatIso($dateModified),
            'pricing_rules'      => $pricingRules,
            'pricing_snapshot'   => $pricingSnapshot,
            'inventory_reserved' => array(),
            'channel'            => $channel,
            'vendor'             => $vendor,
            'resource'           => $resource,
            'order'              => $orderDetails,
            'payment'            => $payment,
            'payment_request'    => $paymentRequest,
            'captured_at'        => null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function mapCustomer(WC_Order $order): array
    {
        $name = trim($order->get_formatted_billing_full_name());
        if ($name === '') {
            $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        }

        return array(
            'id'       => $order->get_customer_id() > 0 ? (int) $order->get_customer_id() : null,
            'name'     => $name,
            'email'    => (string) $order->get_billing_email(),
            'phone'    => (string) $order->get_billing_phone(),
            'company'  => (string) $order->get_billing_company(),
            'billing'  => $this->mapAddress($order, 'billing'),
            'shipping' => $this->mapAddress($order, 'shipping'),
        );
    }

    /**
     * @return array<string, string>
     */
    private function mapAddress(WC_Order $order, string $context): array
    {
        $suffix = $context === 'shipping' ? 'shipping' : 'billing';

        $address = array(
            'company'   => (string) $order->{"get_{$suffix}_company"}(),
            'address_1' => (string) $order->{"get_{$suffix}_address_1"}(),
            'address_2' => (string) $order->{"get_{$suffix}_address_2"}(),
            'postcode'  => (string) $order->{"get_{$suffix}_postcode"}(),
            'city'      => (string) $order->{"get_{$suffix}_city"}(),
            'state'     => (string) $order->{"get_{$suffix}_state"}(),
            'country'   => (string) $order->{"get_{$suffix}_country"}(),
        );

        $address['formatted'] = $suffix === 'shipping'
            ? (string) $order->get_formatted_shipping_address()
            : (string) $order->get_formatted_billing_address();

        return $address;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mapOrderItems(WC_Order $order): array
    {
        $items = array();

        foreach ($order->get_items() as $item) {
            if (! $item instanceof WC_Order_Item_Product) {
                continue;
            }

            $quantity = max(1, (int) $item->get_quantity());
            $subtotal = (float) $item->get_subtotal();
            $unitPrice = $quantity > 0 ? round($subtotal / $quantity, 2) : $subtotal;

            $items[] = array(
                'product_id' => (int) $item->get_product_id(),
                'quantity'   => $quantity,
                'unit_price' => $unitPrice,
                'label'      => $item->get_name(),
                'meta'       => $this->mapOrderItemMeta($item),
            );
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapOrderItemMeta(WC_Order_Item_Product $item): array
    {
        $meta = array(
            'start'          => $this->sanitizeScalarString($item->get_meta('sbdp_start', true)),
            'end'            => $this->sanitizeScalarString($item->get_meta('sbdp_end', true)),
            'participants'   => (int) $item->get_meta('sbdp_participants', true),
            'resource_id'    => (int) $item->get_meta('sbdp_resource_id', true),
            'resource_label' => $this->sanitizeScalarString($item->get_meta('sbdp_resource_label', true)),
            'pricing_source' => $this->sanitizeScalarString($item->get_meta('sbdp_pricing_source', true)),
            'combi'          => (int) $item->get_meta('sbdp_combi', true),
            'pricing'        => $this->decodeMaybeJson($item->get_meta('_sbdp_pricing', true)),
            'plan_aggregate' => $this->decodeMaybeJson($item->get_meta('_sbdp_plan_aggregate', true)),
            'plan_item'      => $this->decodeMaybeJson($item->get_meta('_sbdp_plan_item', true)),
            'planner_input'  => $this->decodeMaybeJson($item->get_meta('_sbdp_planner_input', true)),
            'plan_item_key'  => $this->sanitizeScalarString($item->get_meta('_sbdp_plan_item_key', true)),
        );

        return $meta;
    }

    /**
     * @param array<string, mixed> $customer
     */
    private function applyCustomer(WC_Order $order, array $customer): void
    {
        if ($customer === array()) {
            return;
        }

        $name = isset($customer['name']) ? trim((string) $customer['name']) : '';
        if ($name !== '') {
            [$firstName, $lastName] = $this->splitName($name);
            if ($firstName !== '') {
                $order->set_billing_first_name($firstName);
                $order->set_shipping_first_name($firstName);
            }

            if ($lastName !== '') {
                $order->set_billing_last_name($lastName);
                $order->set_shipping_last_name($lastName);
            }
        }

        if (isset($customer['email'])) {
            $email = (string) $customer['email'];
            if ($email !== '') {
                $order->set_billing_email($email);
            }
        }

        if (isset($customer['phone'])) {
            $phone = (string) $customer['phone'];
            if ($phone !== '') {
                $order->set_billing_phone($phone);
            }
        }

        if (isset($customer['company'])) {
            $company = (string) $customer['company'];
            if ($company !== '') {
                $order->set_billing_company($company);
            }
        }

        if (isset($customer['billing']) && is_array($customer['billing'])) {
            $this->applyAddress($order, $customer['billing'], 'billing');
        }

        if (isset($customer['shipping']) && is_array($customer['shipping'])) {
            $this->applyAddress($order, $customer['shipping'], 'shipping');
        }
    }

    /**
     * @param array<string, mixed> $address
     */
    private function applyAddress(WC_Order $order, array $address, string $context): void
    {
        $fields = array(
            'company',
            'address_1',
            'address_2',
            'postcode',
            'city',
            'state',
            'country',
        );

        foreach ($fields as $field) {
            if (! array_key_exists($field, $address)) {
                continue;
            }

            $method = sprintf('set_%s_%s', $context, $field);
            if (method_exists($order, $method)) {
                $order->{$method}((string) $address[$field]);
            }
        }
    }

    /**
     * @param array<string, mixed> $booking
     */
    private function updateMetaData(WC_Order $order, array $booking): void
    {
        $this->setMetaString($order, self::META_BOOKING_DATE, $booking['date'] ?? '');
        $this->setMetaString($order, self::META_BOOKING_TIME, $booking['time'] ?? '');
        $this->setMetaString(
            $order,
            self::META_BOOKING_END_DATE,
            $booking['date_end'] ?? ($booking['date'] ?? '')
        );
        $this->setMetaString(
            $order,
            self::META_BOOKING_END_TIME,
            $booking['time_end'] ?? ($booking['time'] ?? '')
        );

        if (isset($booking['participants'])) {
            $order->update_meta_data(self::META_PARTICIPANTS, (int) $booking['participants']);
        }

        if (isset($booking['channel'])) {
            $order->update_meta_data(self::META_CHANNEL, $this->normalizeChannel((string) $booking['channel']));
        }

        if (isset($booking['vendor']) && is_array($booking['vendor'])) {
            $vendorId = isset($booking['vendor']['id']) ? (int) $booking['vendor']['id'] : 0;
            $vendorName = isset($booking['vendor']['name']) ? (string) $booking['vendor']['name'] : '';
            if ($vendorId > 0) {
                $order->update_meta_data(self::META_VENDOR_ID, $vendorId);
                $order->update_meta_data(self::META_VENDOR_NAME, $vendorName);
            }
        }

        if (isset($booking['resource'])) {
            $order->update_meta_data(self::META_RESOURCE, $booking['resource']);
        }

        if (isset($booking['pricing_rules'])) {
            $encoded = $this->encodeJson($booking['pricing_rules']);
            if ($encoded !== '') {
                $order->update_meta_data(self::META_PRICING_RULES, $encoded);
            }
        }

        if (isset($booking['pricing_snapshot'])) {
            $encodedSnapshot = $this->encodeJson($booking['pricing_snapshot']);
            if ($encodedSnapshot !== '') {
                $order->update_meta_data(self::META_PRICING_SNAPSHOT, $encodedSnapshot);
            }
        }

        if (isset($booking['payment_request'])) {
            $encodedRequest = $this->encodeJson($booking['payment_request']);
            if ($encodedRequest !== '') {
                $order->update_meta_data(self::META_PAYMENT_REQUEST, $encodedRequest);
            }
        }
    }

    private function deriveParticipants(WC_Order $order): int
    {
        $total = 0;

        foreach ($order->get_items() as $item) {
            if ($item instanceof WC_Order_Item_Product) {
                $total += max(1, (int) $item->get_quantity());
            }
        }

        return $total > 0 ? $total : 1;
    }

    /**
     * Preserve explicit total overrides only for legacy/manual records that do not
     * declare a line-item pricing snapshot. Rebuilt Woo order items should own the total.
     *
     * @param array<string, mixed> $booking
     */
    private function shouldApplyExplicitTotalOverride(array $booking): bool
    {
        $snapshot = isset($booking['pricing_snapshot']) && is_array($booking['pricing_snapshot'])
            ? $booking['pricing_snapshot']
            : array();

        $source = isset($snapshot['source']) ? (string) $snapshot['source'] : '';

        return $source === '' || $source !== 'line_items_gross_snapshot';
    }

    /**
     * Let Woo own tax recalculation whenever tax support is enabled, while keeping
     * a filterable escape hatch for deployments that still depend on legacy behavior.
     *
     * @param array<string, mixed> $booking
     */
    private function shouldRecalculateOrderTaxes(array $booking): bool
    {
        unset($booking);

        $recalculateTaxes = function_exists('wc_tax_enabled') && wc_tax_enabled();

        if (function_exists('apply_filters')) {
            $recalculateTaxes = (bool) apply_filters(
                'sbdp/booking/recalculate_order_taxes',
                $recalculateTaxes
            );
        }

        return $recalculateTaxes;
    }

    private function mapOrderStatusToBooking(string $status): string
    {
        $normalized = strtolower(preg_replace('/^wc-/', '', $status));

        return self::STATUS_FROM_ORDER[$normalized] ?? 'created';
    }

    private function mapBookingStatusToOrderStatus(string $status): string
    {
        $normalized = strtolower($status);

        return self::STATUS_TO_ORDER[$normalized] ?? 'pending';
    }

    private function formatDate(?DateTimeInterface $date, string $format): string
    {
        if (! $date instanceof DateTimeInterface) {
            return '';
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format($format);
    }

    private function formatIso(?DateTimeInterface $date): ?string
    {
        if (! $date instanceof DateTimeInterface) {
            return null;
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(string $name): array
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return array('', '');
        }

        $parts = preg_split('/\s+/', $trimmed) ?: array();
        $first = array_shift($parts) ?? '';
        $last  = implode(' ', $parts);

        return array($first, $last);
    }

    private function normalizeChannel(string $channel): string
    {
        $normalized = strtolower(trim($channel));

        return $normalized !== '' ? $normalized : 'web';
    }

    private function assertSupported(): void
    {
        if (! self::isSupported()) {
            throw new InvalidArgumentException('WooCommerce booking repository requires WooCommerce to be active.');
        }
    }

    private function getMetaString(WC_Order $order, string $key): string
    {
        $value = $order->get_meta($key, true);

        return is_string($value) ? trim($value) : '';
    }

    private function setMetaString(WC_Order $order, string $key, string $value): void
    {
        if ($value === '') {
            if (method_exists($order, 'delete_meta_data')) {
                $order->delete_meta_data($key);
            }

            return;
        }

        $order->update_meta_data($key, $value);
    }

    /**
     * @param mixed $value
     */
    private function encodeJson($value): string
    {
        if (function_exists('wp_json_encode')) {
            $encoded = wp_json_encode($value);
        } else {
            $encoded = json_encode($value);
        }

        return is_string($encoded) ? $encoded : '';
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>|array<int, mixed>|null
     */
    private function decodeMaybeJson($value)
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param mixed $value
     */
    private function sanitizeScalarString($value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
