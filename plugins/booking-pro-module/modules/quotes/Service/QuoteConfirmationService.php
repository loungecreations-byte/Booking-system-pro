<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Quotes\Repository\QuoteRepository;
use BSP\Quotes\Repository\QuoteRepositoryInterface;

final class QuoteConfirmationService
{
    public const CONFIRMED_EVENT = 'quote_confirmed';
    private const PAYMENT_EVENT = 'quote_woo_payment_completed';
    private static ?self $hookService = null;

    public function __construct(
        private QuoteRepositoryInterface $repository,
        private QuoteEventLogger $events
    ) {
    }

    public static function registerHooks(): void
    {
        if (! \function_exists('add_action')) {
            return;
        }

        if (! self::$hookService instanceof self) {
            $repository = new QuoteRepository();
            self::$hookService = new self($repository, new QuoteEventLogger($repository));
        }

        \add_action('woocommerce_payment_complete', array(self::$hookService, 'handlePaymentComplete'), 20, 1);
    }

    public function handlePaymentComplete(int $orderId): void
    {
        $this->confirmPaidQuoteOrder($orderId);
    }

    /**
     * @return array<string, mixed>
     */
    public function confirmPaidQuoteOrder(int $orderId): array
    {
        if ($orderId <= 0 || ! \function_exists('wc_get_order')) {
            return array('ok' => false, 'code' => 'invalid_order_id', 'updated' => false);
        }

        $order = \wc_get_order($orderId);
        if (! $order instanceof \WC_Order) {
            return array('ok' => false, 'code' => 'order_not_found', 'updated' => false);
        }

        $quoteId = (int) $order->get_meta('_sbdp_quote_id');
        if ($quoteId <= 0) {
            return array('ok' => true, 'code' => 'not_quote_order', 'updated' => false);
        }

        $quote = $this->repository->findQuote($quoteId);
        if (! is_array($quote)) {
            $this->logGuard('quote_not_found', $quoteId, $orderId, array());
            return array('ok' => false, 'code' => 'quote_not_found', 'updated' => false);
        }

        $confirmedEvents = $this->eventsForOrder($quoteId, self::CONFIRMED_EVENT, $orderId);
        if ((string) ($quote['status'] ?? '') === 'confirmed' && count($confirmedEvents) === 1) {
            return array('ok' => true, 'code' => 'already_confirmed', 'updated' => false);
        }
        if (count($confirmedEvents) > 0) {
            return $this->reject('confirmation_event_already_exists', $quote, $order);
        }

        if ((string) ($quote['status'] ?? '') !== 'accepted') {
            return $this->reject('quote_not_accepted', $quote, $order);
        }

        if ((string) ($quote['handoff_status'] ?? '') !== QuotePaymentSyncService::COMPLETED_STATUS) {
            return $this->reject('payment_handoff_not_completed', $quote, $order);
        }

        $approvedVersionId = (int) ($quote['approved_version_id'] ?? 0);
        if ($approvedVersionId <= 0) {
            return $this->reject('missing_approved_version', $quote, $order);
        }

        $version = $this->repository->findQuoteVersion($approvedVersionId);
        if (! is_array($version) || (int) ($version['quote_id'] ?? 0) !== $quoteId) {
            return $this->reject('approved_version_not_found', $quote, $order, array(
                'approved_version_id' => $approvedVersionId,
            ));
        }

        $quoteOrderId = (int) ($quote['woo_order_id'] ?? 0);
        if ($quoteOrderId !== $orderId) {
            return $this->reject('quote_order_mismatch', $quote, $order, array(
                'quote_woo_order_id' => $quoteOrderId,
            ));
        }

        if ((int) $order->get_meta('_sbdp_quote_id') !== $quoteId) {
            return $this->reject('order_quote_mismatch', $quote, $order);
        }

        $orderVersionId = (int) $order->get_meta('_sbdp_quote_version_id');
        if ($orderVersionId !== $approvedVersionId) {
            return $this->reject('order_quote_version_mismatch', $quote, $order, array(
                'order_quote_version_id' => $orderVersionId,
                'approved_version_id' => $approvedVersionId,
            ));
        }

        $paymentEvents = $this->eventsForOrder($quoteId, self::PAYMENT_EVENT, $orderId);
        if (count($paymentEvents) !== 1) {
            return $this->reject('payment_event_count_invalid', $quote, $order, array(
                'payment_event_count' => count($paymentEvents),
            ));
        }

        $confirmedAt = $this->now();
        $payload = array(
            'quote_id' => $quoteId,
            'order_id' => $orderId,
            'approved_version_id' => $approvedVersionId,
            'quote_reference' => (string) ($quote['quote_reference'] ?? ''),
            'previous_status' => 'accepted',
            'new_status' => 'confirmed',
            'handoff_status' => QuotePaymentSyncService::COMPLETED_STATUS,
            'confirmed_at' => $confirmedAt,
            'source' => 'quote_confirmation_service',
        );

        $this->repository->updateQuote($quoteId, array(
            'status' => 'confirmed',
            'handoff_status' => QuotePaymentSyncService::COMPLETED_STATUS,
            'updated_at' => $confirmedAt,
        ));

        $this->events->log(
            self::CONFIRMED_EVENT,
            (int) ($quote['quote_request_id'] ?? 0) ?: null,
            $quoteId,
            $approvedVersionId,
            null,
            'Betaalde quote-order bevestigd voor operationele opvolging.',
            $payload
        );

        return array('ok' => true, 'code' => 'quote_confirmed', 'updated' => true, 'payload' => $payload);
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function reject(string $code, array $quote, \WC_Order $order, array $extra = array()): array
    {
        $quoteId = (int) ($quote['id'] ?? 0);
        $orderId = (int) $order->get_id();
        $payload = array_merge(array(
            'quote_id' => $quoteId,
            'order_id' => $orderId,
            'approved_version_id' => (int) ($quote['approved_version_id'] ?? 0),
            'quote_reference' => (string) ($quote['quote_reference'] ?? ''),
            'status' => (string) ($quote['status'] ?? ''),
            'handoff_status' => (string) ($quote['handoff_status'] ?? ''),
            'reason' => $code,
        ), $extra);

        $this->logGuard($code, $quoteId, $orderId, $payload);

        return array('ok' => false, 'code' => $code, 'updated' => false, 'payload' => $payload);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function eventsForOrder(int $quoteId, string $eventType, int $orderId): array
    {
        $matches = array();
        foreach ($this->repository->listQuoteEvents($quoteId) as $event) {
            if ((string) ($event['event_type'] ?? '') !== $eventType) {
                continue;
            }

            $payload = is_array($event['payload_json'] ?? null) ? $event['payload_json'] : array();
            if ((int) ($payload['order_id'] ?? 0) === $orderId) {
                $matches[] = $event;
            }
        }

        return $matches;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function logGuard(string $code, int $quoteId, int $orderId, array $payload): void
    {
        if (! \defined('WP_DEBUG') || ! \WP_DEBUG || ! \function_exists('error_log')) {
            return;
        }

        \error_log('[SBDP QuoteConfirmation] ' . $code . ' ' . \wp_json_encode(array(
            'quote_id' => $quoteId,
            'order_id' => $orderId,
            'payload' => $payload,
        )));
    }

    private function now(): string
    {
        return \function_exists('current_time')
            ? (string) \current_time('mysql', true)
            : gmdate('Y-m-d H:i:s');
    }
}
