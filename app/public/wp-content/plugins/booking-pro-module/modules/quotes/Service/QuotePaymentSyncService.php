<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Quotes\Repository\QuoteRepository;
use BSP\Quotes\Repository\QuoteRepositoryInterface;
use Throwable;

final class QuotePaymentSyncService
{
    public const COMPLETED_STATUS = 'woo_payment_completed';
    public const COMPLETED_EVENT = 'quote_woo_payment_completed';
    private const REJECTED_EVENT = 'quote_woo_payment_complete_rejected';
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

        \add_action('woocommerce_payment_complete', array(self::$hookService, 'handlePaymentComplete'), 15, 1);
    }

    public function handlePaymentComplete(int $orderId): void
    {
        $this->syncOrderPayment($orderId);
    }

    /**
     * @return array<string, mixed>
     */
    public function syncOrderPayment(int $orderId): array
    {
        if ($orderId <= 0 || ! \function_exists('wc_get_order')) {
            return array('ok' => false, 'code' => 'invalid_order_id');
        }

        $order = \wc_get_order($orderId);
        if (! $order instanceof \WC_Order) {
            return array('ok' => false, 'code' => 'order_not_found');
        }

        $quoteId = (int) $order->get_meta('_sbdp_quote_id');
        if ($quoteId <= 0) {
            return array('ok' => true, 'code' => 'not_quote_order', 'updated' => false);
        }

        $quote = $this->repository->findQuote($quoteId);
        if (! is_array($quote)) {
            $this->logRuntimeGuard('quote_not_found', $quoteId, $orderId, array());
            return array('ok' => false, 'code' => 'quote_not_found', 'updated' => false);
        }

        $quoteReference = trim((string) ($quote['quote_reference'] ?? ''));
        $requestId = (int) ($quote['quote_request_id'] ?? 0);
        $approvedVersionId = (int) ($quote['approved_version_id'] ?? 0);
        $orderVersionId = (int) $order->get_meta('_sbdp_quote_version_id');

        if ((string) ($quote['status'] ?? '') !== 'accepted') {
            return $this->reject(
                'quote_not_accepted',
                $quote,
                $order,
                $orderVersionId,
                array('status' => (string) ($quote['status'] ?? ''))
            );
        }

        if ($approvedVersionId <= 0) {
            return $this->reject('missing_approved_version', $quote, $order, $orderVersionId);
        }

        if ($orderVersionId !== $approvedVersionId) {
            return $this->reject(
                'quote_version_mismatch',
                $quote,
                $order,
                $orderVersionId,
                array('approved_version_id' => $approvedVersionId)
            );
        }

        $quoteOrderId = (int) ($quote['woo_order_id'] ?? 0);
        if ($quoteOrderId > 0 && $quoteOrderId !== $orderId) {
            return $this->reject(
                'quote_order_mismatch',
                $quote,
                $order,
                $orderVersionId,
                array('quote_woo_order_id' => $quoteOrderId)
            );
        }

        $invoice = $this->invoiceMetadata($order);
        $payload = array(
            'quote_id' => $quoteId,
            'order_id' => $orderId,
            'transaction_id' => method_exists($order, 'get_transaction_id') ? (string) $order->get_transaction_id() : '',
            'approved_version_id' => $approvedVersionId,
            'quote_version_id' => $orderVersionId,
            'quote_reference' => $quoteReference,
            'invoice_available' => (bool) ($invoice['invoice_available'] ?? false),
            'invoice_number' => (string) ($invoice['invoice_number'] ?? ''),
            'invoice_generated_at' => (string) ($invoice['invoice_generated_at'] ?? ''),
        );

        $eventExists = $this->paymentEventExists($quoteId, $orderId);
        $updated = false;
        if ((string) ($quote['handoff_status'] ?? '') !== self::COMPLETED_STATUS) {
            $this->repository->updateQuote($quoteId, array(
                'handoff_status' => self::COMPLETED_STATUS,
                'updated_at' => $this->now(),
            ));
            $updated = true;
        }

        if (! $eventExists) {
            $this->events->log(
                self::COMPLETED_EVENT,
                $requestId > 0 ? $requestId : null,
                $quoteId,
                $approvedVersionId,
                null,
                'Woo betaling voltooid voor geaccepteerde quote.',
                $payload
            );
        }

        return array(
            'ok' => true,
            'code' => 'quote_payment_completed',
            'updated' => $updated,
            'event_created' => ! $eventExists,
            'payload' => $payload,
        );
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function reject(string $code, array $quote, \WC_Order $order, int $orderVersionId, array $extra = array()): array
    {
        $quoteId = (int) ($quote['id'] ?? 0);
        $orderId = (int) $order->get_id();
        $payload = array_merge(array(
            'quote_id' => $quoteId,
            'order_id' => $orderId,
            'order_quote_version_id' => $orderVersionId,
            'approved_version_id' => (int) ($quote['approved_version_id'] ?? 0),
            'quote_reference' => (string) ($quote['quote_reference'] ?? ''),
            'reason' => $code,
        ), $extra);

        $this->events->log(
            self::REJECTED_EVENT,
            (int) ($quote['quote_request_id'] ?? 0) ?: null,
            $quoteId > 0 ? $quoteId : null,
            (int) ($quote['approved_version_id'] ?? 0) ?: null,
            null,
            'Woo payment_complete voor quote geweigerd door guard.',
            $payload
        );

        $this->logRuntimeGuard($code, $quoteId, $orderId, $payload);

        return array('ok' => false, 'code' => $code, 'updated' => false, 'payload' => $payload);
    }

    /**
     * @return array{invoice_available: bool, invoice_number: string, invoice_generated_at: string}
     */
    private function invoiceMetadata(\WC_Order $order): array
    {
        $document = null;
        try {
            if (\function_exists('wpo_wcpdf_get_document')) {
                $document = \wpo_wcpdf_get_document('invoice', $order);
            }
            if (! is_object($document) && \function_exists('WPO_WCPDF')) {
                $document = \WPO_WCPDF()->documents->get_document('invoice', $order);
            }
        } catch (Throwable) {
            $document = null;
        }

        $invoiceNumber = '';
        if (is_object($document) && method_exists($document, 'get_number')) {
            try {
                $invoiceNumber = trim((string) $document->get_number('', null, 'view', true));
            } catch (Throwable) {
                $invoiceNumber = '';
            }
        }
        if ($invoiceNumber === '') {
            $invoiceNumber = trim((string) $order->get_meta('_wcpdf_invoice_number'));
        }

        $generatedAt = trim((string) $order->get_meta('_wcpdf_invoice_date'));
        if ($generatedAt === '') {
            $generatedAt = trim((string) $order->get_meta('_wcpdf_invoice_date_formatted'));
        }
        return array(
            'invoice_available' => $invoiceNumber !== '',
            'invoice_number' => $invoiceNumber,
            'invoice_generated_at' => $generatedAt,
        );
    }

    private function paymentEventExists(int $quoteId, int $orderId): bool
    {
        foreach ($this->repository->listQuoteEvents($quoteId) as $event) {
            if ((string) ($event['event_type'] ?? '') !== self::COMPLETED_EVENT) {
                continue;
            }

            $payload = is_array($event['payload_json'] ?? null) ? $event['payload_json'] : array();
            if ((int) ($payload['order_id'] ?? 0) === $orderId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function logRuntimeGuard(string $code, int $quoteId, int $orderId, array $payload): void
    {
        if (! \function_exists('error_log')) {
            return;
        }

        \error_log('[SBDP QuotePaymentSync] ' . $code . ' ' . \wp_json_encode(array(
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
