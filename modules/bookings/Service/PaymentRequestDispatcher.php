<?php

declare(strict_types=1);

namespace BSP\Bookings\Service;

use BSP\Core\CoreServiceProvider;
use DateTimeImmutable;
use Throwable;

/**
 * Creates matching WooCommerce orders for planner bookings and dispatches
 * payment requests (optionally leveraging Mollie if available).
 */
final class PaymentRequestDispatcher
{
    private const META_BOOKING_ID = '_sbdp_booking_id';
    private const META_SOURCE     = '_sbdp_source';

    /**
     * Attempt to create or update a WooCommerce order for the provided booking
     * and return order/payment_request metadata.
     *
     * @param array<string, mixed> $booking
     * @param bool                 $sendInvoiceEmail
     *
     * @return array<string, mixed>|null
     */
    public function prepare(array $booking, bool $sendInvoiceEmail = true): ?array
    {
        if (
            ! function_exists('wc_create_order')
            || ! function_exists('wc_get_order')
            || ! class_exists('WC_Order')
        ) {
            return null;
        }

        try {
            $order = $this->resolveOrderForBooking($booking);
            if (! is_object($order) || ! method_exists($order, 'get_id')) {
                return null;
            }

            $status     = 'pending';
            $provider   = 'woocommerce';
            $transport  = 'checkout_link';
            $reference  = null;
            $paymentUrl = $order->get_checkout_payment_url(true);

            $mollie = $this->attemptMolliePaymentRequest($order, $booking);
            if ($mollie !== null && isset($mollie['url']) && is_string($mollie['url']) && $mollie['url'] !== '') {
                $paymentUrl = $mollie['url'];
                $provider   = $mollie['provider'] ?? 'mollie';
                $transport  = $mollie['transport'] ?? 'api';
                $reference  = isset($mollie['reference']) ? (string) $mollie['reference'] : null;
                $status     = $mollie['status'] ?? 'sent';
            }

            if (function_exists('apply_filters')) {
                $filtered = apply_filters(
                    'sbdp/booking/payment_request_url',
                    [
                        'url'       => $paymentUrl,
                        'status'    => $status,
                        'provider'  => $provider,
                        'transport' => $transport,
                        'reference' => $reference,
                    ],
                    $order,
                    $booking
                );

                if (is_array($filtered) && isset($filtered['url']) && is_string($filtered['url']) && $filtered['url'] !== '') {
                    $paymentUrl = $filtered['url'];
                    $status     = isset($filtered['status']) ? (string) $filtered['status'] : $status;
                    $provider   = isset($filtered['provider']) ? (string) $filtered['provider'] : $provider;
                    $transport  = isset($filtered['transport']) ? (string) $filtered['transport'] : $transport;
                    $reference  = isset($filtered['reference']) ? (string) $filtered['reference'] : $reference;
                }
            }

            $this->applyOrderStatus($order, $booking);
            $this->applyBookingMeta($order, $booking);

            $sendInvoice = $sendInvoiceEmail;
            if (function_exists('apply_filters')) {
                $sendInvoice = (bool) apply_filters('sbdp/booking/send_invoice_email', $sendInvoice, $order, $booking);
            }

            if ($sendInvoice) {
                $this->dispatchInvoiceEmail($order);
            }

            return $this->buildPayload($order, $paymentUrl, $provider, $transport, $status, $reference);
        } catch (Throwable $exception) {
            CoreServiceProvider::logger()->log(
                sprintf(
                    'Payment request preparation failed for booking #%d: %s',
                    $booking['id'] ?? 0,
                    $exception->getMessage()
                )
            );
        }

        return null;
    }

    /**
     * @param array<string, mixed> $booking
     */
    private function resolveOrderForBooking(array $booking)
    {
        $existing = $this->findExistingOrder($booking);
        if (is_object($existing)) {
            $this->synchronizeExistingOrder($existing, $booking);

            return $existing;
        }

        return $this->createOrderForBooking($booking);
    }

    /**
     * @param array<string, mixed> $booking
     */
    private function findExistingOrder(array $booking)
    {
        $orderId = null;
        if (isset($booking['order']) && is_array($booking['order']) && isset($booking['order']['id'])) {
            $orderId = (int) $booking['order']['id'];
        }

        if ($orderId !== null && $orderId > 0) {
            $order = wc_get_order($orderId);
            if (is_object($order)) {
                return $order;
            }
        }

        if (! isset($booking['id']) || ! function_exists('wc_get_orders')) {
            return null;
        }

        $orders = wc_get_orders([
            'limit'        => 1,
            'type'         => 'shop_order',
            'meta_key'     => self::META_BOOKING_ID,
            'meta_value'   => $booking['id'],
            'return'       => 'objects',
        ]);

        if (is_array($orders) && isset($orders[0]) && is_object($orders[0])) {
            return $orders[0];
        }

        return null;
    }

    /**
     * @param object $order
     * @param array<string, mixed> $booking
     */
    private function synchronizeExistingOrder($order, array $booking): void
    {
        $this->tagOrderWithBooking($order, $booking);
        $this->applyCustomerDetails($order, $booking);
        $this->applyCurrency($order, $booking);
        $this->applyParticipantsMeta($order, $booking);

        if (
            method_exists($order, 'get_items')
            && method_exists($order, 'remove_item')
        ) {
            foreach ($order->get_items() as $itemId => $item) {
                unset($item);
                $order->remove_item($itemId);
            }
        }

        $this->addItemsToOrder($order, $booking);

        $this->applyOrderStatus($order, $booking);
        $this->applyBookingMeta($order, $booking);

        if (method_exists($order, 'calculate_totals')) {
            $order->calculate_totals(false);
        }

        if (method_exists($order, 'save')) {
            $order->save();
        }
    }

    /**
     * @param array<string, mixed> $booking
     */
    private function createOrderForBooking(array $booking)
    {
        $order = wc_create_order(['status' => 'pending']);
        if (! is_object($order)) {
            return null;
        }

        $this->tagOrderWithBooking($order, $booking);
        $this->applyCustomerDetails($order, $booking);
        $this->applyCurrency($order, $booking);
        $this->applyParticipantsMeta($order, $booking);

        $this->addItemsToOrder($order, $booking);

        if (! empty($booking['notes']) && method_exists($order, 'add_order_note')) {
            $order->add_order_note((string) $booking['notes']);
        }

        $this->applyOrderStatus($order, $booking);
        $this->applyBookingMeta($order, $booking);

        if (method_exists($order, 'calculate_totals')) {
            // Recalculate totals but skip taxes to avoid requiring tax setup.
            $order->calculate_totals(false);
        }

        if (method_exists($order, 'save')) {
            $order->save();
        }

        return $order;
    }

    /**
     * @param object $order
     * @param array<string, mixed> $booking
     */
    private function tagOrderWithBooking($order, array $booking): void
    {
        $bookingId = $booking['id'] ?? 0;

        if (method_exists($order, 'update_meta_data')) {
            $order->update_meta_data(self::META_BOOKING_ID, $bookingId);
            $order->update_meta_data(self::META_SOURCE, 'booking_pro_planner');
        } elseif (method_exists($order, 'add_meta_data')) {
            $order->add_meta_data(self::META_BOOKING_ID, $bookingId, true);
            $order->add_meta_data(self::META_SOURCE, 'booking_pro_planner', true);
        }
    }

    /**
     * @param object $order
     * @param array<string, mixed> $booking
     */
    private function applyCustomerDetails($order, array $booking): void
    {
        $customer = isset($booking['customer']) && is_array($booking['customer']) ? $booking['customer'] : [];
        $name     = (string) ($customer['name'] ?? '');
        [$firstName, $lastName] = $this->splitName($name);

        if (
            method_exists($order, 'set_customer_id')
            && isset($customer['id'])
            && (int) $customer['id'] > 0
        ) {
            $order->set_customer_id((int) $customer['id']);
        }

        if (method_exists($order, 'set_billing_first_name')) {
            $order->set_billing_first_name($firstName);
        }

        if (method_exists($order, 'set_billing_last_name')) {
            $order->set_billing_last_name($lastName);
        }

        if (method_exists($order, 'set_billing_email')) {
            $order->set_billing_email((string) ($customer['email'] ?? ''));
        }

        if (method_exists($order, 'set_billing_phone')) {
            $order->set_billing_phone((string) ($customer['phone'] ?? ''));
        }

        if (method_exists($order, 'set_billing_company')) {
            $order->set_billing_company((string) ($customer['company'] ?? ''));
        }

        $billing  = isset($customer['billing']) && is_array($customer['billing']) ? $customer['billing'] : [];
        $shipping = isset($customer['shipping']) && is_array($customer['shipping']) ? $customer['shipping'] : [];

        $this->applyAddressToOrder($order, $billing, 'billing');
        $this->applyAddressToOrder($order, $shipping, 'shipping');
    }

    /**
     * @param object $order
     * @param array<string, mixed> $booking
     */
    private function applyCurrency($order, array $booking): void
    {
        if (! isset($booking['currency'])) {
            return;
        }

        $currency = (string) $booking['currency'];
        if ($currency === '') {
            return;
        }

        if (method_exists($order, 'set_currency')) {
            $order->set_currency($currency);
        }
    }

    /**
     * @param object $order
     * @param array<string, mixed> $booking
     */
    private function applyParticipantsMeta($order, array $booking): void
    {
        if (! isset($booking['participants'])) {
            return;
        }

        $participants = (int) $booking['participants'];

        $this->setOrderMeta($order, '_sbdp_participants', $participants);
    }

    /**
     * @param object $order
     * @param array<string, mixed> $booking
     */
    private function applyBookingMeta($order, array $booking): void
    {
        $channel = isset($booking['channel']) ? (string) $booking['channel'] : '';
        if ($channel !== '') {
            $this->setOrderMeta($order, '_sbdp_channel', $channel);
        }

        $planner = isset($booking['planner']) && is_array($booking['planner']) ? $booking['planner'] : [];
        $resource = isset($planner['resource']) ? (string) $planner['resource'] : null;
        if ($resource === null && isset($booking['resource'])) {
            $resource = (string) $booking['resource'];
        }

        if ($resource !== null && $resource !== '') {
            $this->setOrderMeta($order, '_sbdp_planner_resource', $resource);
        }

        if (isset($planner['slot']) && $planner['slot'] !== '') {
            $this->setOrderMeta($order, '_sbdp_planner_slot', (string) $planner['slot']);
        }

        if (! empty($booking['notes'])) {
            $this->setOrderMeta($order, '_sbdp_notes', (string) $booking['notes']);
        }

        if (isset($booking['vendor']) && is_array($booking['vendor'])) {
            if (isset($booking['vendor']['id']) && (int) $booking['vendor']['id'] > 0) {
                $this->setOrderMeta($order, '_sbdp_vendor_id', (int) $booking['vendor']['id']);
            }

            if (! empty($booking['vendor']['name'])) {
                $this->setOrderMeta($order, '_sbdp_vendor_name', (string) $booking['vendor']['name']);
            }
        }
    }

    /**
     * @param object $order
     * @param array<string, mixed> $booking
     */
    private function applyOrderStatus($order, array $booking): void
    {
        if (! method_exists($order, 'get_status') || ! method_exists($order, 'set_status')) {
            return;
        }

        $status = strtolower((string) ($booking['status'] ?? ''));
        if ($status === '') {
            return;
        }

        $map = [
            'completed' => 'completed',
            'paid'      => 'processing',
            'pending'   => 'pending',
            'requested' => 'on-hold',
            'cancelled' => 'cancelled',
            'conflict'  => 'on-hold',
        ];

        if (! isset($map[$status])) {
            return;
        }

        $target = $map[$status];
        $current = $order->get_status();
        if ($current === $target) {
            return;
        }

        try {
            $order->set_status($target);
        } catch (Throwable $exception) {
            CoreServiceProvider::logger()->log(
                sprintf(
                    'Failed to update order status for booking #%d: %s',
                    $booking['id'] ?? 0,
                    $exception->getMessage()
                )
            );
        }
    }

    /**
     * @param object $order
     * @param mixed  $value
     */
    private function setOrderMeta($order, string $key, $value): void
    {
        if (method_exists($order, 'update_meta_data')) {
            $order->update_meta_data($key, $value);
        } elseif (method_exists($order, 'add_meta_data')) {
            $order->add_meta_data($key, $value, true);
        }
    }

    /**
     * @param object $order
     * @param array<string, mixed> $address
     */
    private function applyAddressToOrder($order, array $address, string $type): void
    {
        $map = [
            'company'   => "set_{$type}_company",
            'address_1' => "set_{$type}_address_1",
            'address_2' => "set_{$type}_address_2",
            'city'      => "set_{$type}_city",
            'postcode'  => "set_{$type}_postcode",
            'state'     => "set_{$type}_state",
            'country'   => "set_{$type}_country",
        ];

        foreach ($map as $key => $method) {
            if (method_exists($order, $method)) {
                $order->{$method}((string) ($address[$key] ?? ''));
            }
        }
    }

    /**
     * @param object $order
     * @param array<string, mixed> $booking
     */
    private function addItemsToOrder($order, array $booking): void
    {
        if (! isset($booking['items']) || ! is_array($booking['items'])) {
            return;
        }

        foreach ($booking['items'] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $productId = (int) ($item['product_id'] ?? 0);
            $quantity  = (int) ($item['quantity'] ?? 1);
            $unitPrice = (float) ($item['unit_price'] ?? 0.0);

            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }

            $lineTotal = round($unitPrice * $quantity, 2);

            if (function_exists('wc_get_product')) {
                $product = wc_get_product($productId);
                if ($product && method_exists($order, 'add_product')) {
                    $order->add_product(
                        $product,
                        $quantity,
                        [
                            'subtotal' => $lineTotal,
                            'total'    => $lineTotal,
                        ]
                    );
                    continue;
                }
            }

            if (class_exists('WC_Order_Item_Product')) {
                $orderItem = new \WC_Order_Item_Product();
                $orderItem->set_product_id($productId);
                $orderItem->set_name((string) ($item['label'] ?? sprintf('Item #%d', $productId)));
                $orderItem->set_quantity($quantity);
                $orderItem->set_total($lineTotal);
                $order->add_item($orderItem);
            }
        }
    }

    /**
     * @param object $order
     * @param array<string, mixed> $booking
     *
     * @return array<string, mixed>|null
     */
    private function attemptMolliePaymentRequest($order, array $booking): ?array
    {
        if (! function_exists('mollieWooCommerce')) {
            return null;
        }

        try {
            $plugin = mollieWooCommerce();
            if (! is_object($plugin)) {
                return null;
            }

            $service = null;
            foreach (['orderPaymentRequest', 'paymentRequest', 'getPaymentRequestService'] as $method) {
                if (method_exists($plugin, $method)) {
                    $service = $plugin->{$method}();
                    if (is_object($service)) {
                        break;
                    }
                }
            }

            if (! is_object($service)) {
                return null;
            }

            $candidates = [
                'createPaymentRequestForOrder',
                'createPaymentLinkForOrder',
                'createPaymentLink',
                'create',
                'sendPaymentRequest',
                'sendPaymentRequestEmail',
            ];

            foreach ($candidates as $method) {
                if (! method_exists($service, $method)) {
                    continue;
                }

                $result = $service->{$method}($order);

                if (is_array($result)) {
                    $url = $result['url']
                        ?? $result['payment_url']
                        ?? $result['checkout_url']
                        ?? null;

                    if (is_string($url) && $url !== '') {
                        return [
                            'url'       => $url,
                            'provider'  => 'mollie',
                            'transport' => 'api',
                            'reference' => isset($result['id']) ? (string) $result['id'] : null,
                            'status'    => $result['status'] ?? 'sent',
                        ];
                    }
                } elseif (is_string($result) && $result !== '') {
                    return [
                        'url'       => $result,
                        'provider'  => 'mollie',
                        'transport' => 'api',
                        'reference' => null,
                        'status'    => 'sent',
                    ];
                } elseif ($method === 'sendPaymentRequestEmail') {
                    return [
                        'url'       => $order->get_checkout_payment_url(true),
                        'provider'  => 'mollie',
                        'transport' => 'email',
                        'reference' => null,
                        'status'    => 'sent',
                    ];
                }
            }
        } catch (Throwable $exception) {
            CoreServiceProvider::logger()->log(
                sprintf(
                    'Mollie payment request failed for booking #%d: %s',
                    $booking['id'] ?? 0,
                    $exception->getMessage()
                )
            );
        }

        return null;
    }

    private function dispatchInvoiceEmail($order): void
    {
        if (! function_exists('WC')) {
            return;
        }

        try {
            $wc = WC();
            if (! is_object($wc) || ! method_exists($wc, 'mailer')) {
                return;
            }

            $mailer = $wc->mailer();
            if (! is_object($mailer) || ! isset($mailer->emails) || ! is_array($mailer->emails)) {
                return;
            }

            if (isset($mailer->emails['WC_Email_Customer_Invoice'])) {
                $mailer->emails['WC_Email_Customer_Invoice']->trigger($order->get_id(), $order);
            }
        } catch (Throwable $exception) {
            CoreServiceProvider::logger()->log(
                sprintf(
                    'Failed to send invoice email for order #%d: %s',
                    method_exists($order, 'get_id') ? $order->get_id() : 0,
                    $exception->getMessage()
                )
            );
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['Planner', 'Guest'];
        }

        $parts = preg_split('/\s+/', $name, 2) ?: [];
        $first = $parts[0] ?? $name;
        $last  = $parts[1] ?? $first;

        if ($last === $first) {
            $last = '';
        }

        return [$first, $last];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(
        $order,
        string $url,
        string $provider,
        string $transport,
        string $status,
        ?string $reference
    ): array {
        $timestamp = (new DateTimeImmutable())->format(DateTimeImmutable::ATOM);

        $statusMessage = 'Awaiting payment via payment request';
        if (function_exists('__')) {
            $statusMessage = __('Awaiting payment via payment request', 'sbdp');
        }

        return [
            'order' => [
                'id'             => method_exists($order, 'get_id') ? (int) $order->get_id() : 0,
                'number'         => method_exists($order, 'get_order_number') ? (string) $order->get_order_number() : '',
                'status'         => method_exists($order, 'get_status') ? (string) $order->get_status() : 'pending',
                'status_message' => $statusMessage,
                'processed_at'   => $timestamp,
            ],
            'payment_request' => [
                'url'          => $url,
                'provider'     => $provider,
                'transport'    => $transport,
                'status'       => $status,
                'dispatched_at'=> $timestamp,
                'reference'    => $reference,
            ],
        ];
    }
}
