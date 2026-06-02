<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Quotes\Repository\QuoteRepositoryInterface;

final class QuoteConfirmationReadinessService
{
    public const READY_TO_CONFIRM = 'ready_to_confirm';
    public const AWAITING_SUPPLIER_CONFIRMATION = 'awaiting_supplier_confirmation';
    public const REQUIRES_ADMIN_CONFIRMATION = 'requires_admin_confirmation';
    public const CONFIRMATION_BLOCKED = 'confirmation_blocked';
    public const EVENT_EVALUATED = 'quote_confirmation_readiness_evaluated';

    public function __construct(
        private QuoteRepositoryInterface $repository,
        private QuoteEventLogger $events
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function evaluate(int $quoteId): array
    {
        $quote = $this->repository->findQuote($quoteId);
        if (! is_array($quote)) {
            return $this->blocked($quoteId, 0, 0, array('quote_not_found'));
        }

        $orderId = (int) ($quote['woo_order_id'] ?? 0);
        $approvedVersionId = (int) ($quote['approved_version_id'] ?? 0);
        $issues = $this->validatePaymentContext($quote, $orderId, $approvedVersionId);
        if ($issues !== array()) {
            return $this->persist($quote, self::CONFIRMATION_BLOCKED, $issues, array());
        }

        $lines = $this->repository->listQuoteLines($approvedVersionId);
        if ($lines === array()) {
            return $this->persist($quote, self::CONFIRMATION_BLOCKED, array('approved_version_has_no_lines'), array());
        }

        $lineResults = array();
        $hasSupplierBlock = false;
        $hasAdminBlock = false;
        $hasHardBlock = false;

        foreach ($lines as $line) {
            $lineResult = $this->evaluateLine($line);
            $lineResults[] = $lineResult;
            if (($lineResult['outcome'] ?? '') === self::AWAITING_SUPPLIER_CONFIRMATION) {
                $hasSupplierBlock = true;
            } elseif (($lineResult['outcome'] ?? '') === self::REQUIRES_ADMIN_CONFIRMATION) {
                $hasAdminBlock = true;
            } elseif (($lineResult['outcome'] ?? '') === self::CONFIRMATION_BLOCKED) {
                $hasHardBlock = true;
            }
        }

        $outcome = self::READY_TO_CONFIRM;
        if ($hasHardBlock) {
            $outcome = self::CONFIRMATION_BLOCKED;
        } elseif ($hasSupplierBlock) {
            $outcome = self::AWAITING_SUPPLIER_CONFIRMATION;
        } elseif ($hasAdminBlock) {
            $outcome = self::REQUIRES_ADMIN_CONFIRMATION;
        }

        return $this->persist($quote, $outcome, array(), $lineResults);
    }

    /**
     * @param array<string, mixed> $quote
     * @return array<int, string>
     */
    private function validatePaymentContext(array $quote, int $orderId, int $approvedVersionId): array
    {
        $issues = array();
        $quoteId = (int) ($quote['id'] ?? 0);

        if ((string) ($quote['status'] ?? '') !== 'accepted') {
            $issues[] = 'quote_status_not_accepted';
        }
        if ((string) ($quote['handoff_status'] ?? '') !== QuotePaymentSyncService::COMPLETED_STATUS) {
            $issues[] = 'handoff_status_not_woo_payment_completed';
        }
        if ($approvedVersionId <= 0) {
            $issues[] = 'missing_approved_version_id';
        } elseif (! is_array($this->repository->findQuoteVersion($approvedVersionId))) {
            $issues[] = 'approved_version_not_found';
        }
        if ($orderId <= 0) {
            $issues[] = 'missing_woo_order_id';
        }

        if ($orderId > 0 && \function_exists('wc_get_order')) {
            $order = \wc_get_order($orderId);
            if (! $order instanceof \WC_Order) {
                $issues[] = 'woo_order_not_found';
            } else {
                if ((int) $order->get_meta('_sbdp_quote_id') !== $quoteId) {
                    $issues[] = 'order_quote_id_mismatch';
                }
                if ((int) $order->get_meta('_sbdp_quote_version_id') !== $approvedVersionId) {
                    $issues[] = 'order_quote_version_mismatch';
                }
            }
        }

        if ($quoteId > 0 && $orderId > 0) {
            $paymentEvents = $this->eventsForOrder($quoteId, QuotePaymentSyncService::COMPLETED_EVENT, $orderId);
            if (count($paymentEvents) !== 1) {
                $issues[] = 'payment_event_count_invalid';
            }
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    private function evaluateLine(array $line): array
    {
        $lineId = (int) ($line['id'] ?? 0);
        $productId = (int) ($line['product_id'] ?? 0);
        $lineType = strtolower(trim((string) ($line['line_type'] ?? 'product')));
        $pricingConfidence = (string) ($line['pricing_confidence'] ?? 'unknown');
        $availabilityConfidence = (string) ($line['availability_confidence'] ?? 'unknown');
        $availability = is_array($line['availability_snapshot_json'] ?? null) ? $line['availability_snapshot_json'] : array();

        if ($this->isManualLine($lineType, $productId)) {
            return $this->lineResult($lineId, self::REQUIRES_ADMIN_CONFIRMATION, 'manual_or_custom_line');
        }

        if ($this->requiresSupplierConfirmation($line, $availability)) {
            $supplierStatus = (string) ($availability['supplierStatus'] ?? $availability['supplier_status'] ?? '');
            if ($supplierStatus !== 'supplier_booking_confirmed') {
                return $this->lineResult($lineId, self::AWAITING_SUPPLIER_CONFIRMATION, 'supplier_confirmation_missing');
            }
        }

        if ($pricingConfidence !== 'execution_verified') {
            return $this->lineResult($lineId, self::CONFIRMATION_BLOCKED, 'pricing_not_execution_verified');
        }
        if ($availabilityConfidence !== 'confirmed') {
            return $this->lineResult($lineId, self::CONFIRMATION_BLOCKED, 'availability_not_confirmed');
        }
        if ((string) ($line['line_status'] ?? '') === 'unavailable') {
            return $this->lineResult($lineId, self::CONFIRMATION_BLOCKED, 'line_unavailable');
        }

        return $this->lineResult($lineId, self::READY_TO_CONFIRM, 'line_operationally_green');
    }

    private function isManualLine(string $lineType, int $productId): bool
    {
        if ($productId <= 0) {
            return true;
        }

        return in_array($lineType, array('manual', 'custom', 'note', 'directional'), true);
    }

    /**
     * @param array<string, mixed> $line
     * @param array<string, mixed> $availability
     */
    private function requiresSupplierConfirmation(array $line, array $availability): bool
    {
        $productId = (int) ($line['product_id'] ?? 0);
        $bookingMode = strtolower(trim((string) ($availability['bookingMode'] ?? $availability['booking_mode'] ?? '')));
        $supplierStatus = strtolower(trim((string) ($availability['supplierStatus'] ?? $availability['supplier_status'] ?? '')));

        if ($productId === 115 || $bookingMode === 'supplier_confirmation') {
            return true;
        }
        if (in_array($supplierStatus, array('supplier_confirmation_required', 'supplier_option_requested'), true)) {
            return true;
        }
        if (\function_exists('get_post_meta') && $productId > 0) {
            $provider = strtolower(trim((string) \get_post_meta($productId, '_ddb_supplier_provider', true)));
            $required = strtolower(trim((string) \get_post_meta($productId, '_ddb_supplier_confirmation_required', true)));
            if ($provider === 'eliio' || $required === 'yes') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function lineResult(int $lineId, string $outcome, string $reason): array
    {
        return array('line_id' => $lineId, 'outcome' => $outcome, 'reason' => $reason);
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<int, string> $issues
     * @param array<int, array<string, mixed>> $lineResults
     * @return array<string, mixed>
     */
    private function persist(array $quote, string $outcome, array $issues, array $lineResults): array
    {
        $quoteId = (int) ($quote['id'] ?? 0);
        $versionId = (int) ($quote['approved_version_id'] ?? 0);
        $evaluatedAt = $this->now();
        if ($quoteId > 0 && (string) ($quote['handoff_status'] ?? '') !== $outcome) {
            $this->repository->updateQuote($quoteId, array(
                'handoff_status' => $outcome,
                'updated_at' => $evaluatedAt,
            ));
        }

        $payload = array(
            'quote_id' => $quoteId,
            'order_id' => (int) ($quote['woo_order_id'] ?? 0),
            'approved_version_id' => $versionId,
            'quote_reference' => (string) ($quote['quote_reference'] ?? ''),
            'outcome' => $outcome,
            'issues' => $issues,
            'line_results' => $lineResults,
            'evaluated_at' => $evaluatedAt,
            'source' => 'quote_confirmation_readiness_service',
        );

        if ($quoteId > 0) {
            $this->events->log(
                self::EVENT_EVALUATED,
                (int) ($quote['quote_request_id'] ?? 0) ?: null,
                $quoteId,
                $versionId > 0 ? $versionId : null,
                null,
                'Quote confirmation readiness beoordeeld.',
                $payload
            );
        }

        return array('outcome' => $outcome, 'payload' => $payload);
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

    private function blocked(int $quoteId, int $orderId, int $approvedVersionId, array $issues): array
    {
        return array(
            'outcome' => self::CONFIRMATION_BLOCKED,
            'payload' => array(
                'quote_id' => $quoteId,
                'order_id' => $orderId,
                'approved_version_id' => $approvedVersionId,
                'issues' => $issues,
                'source' => 'quote_confirmation_readiness_service',
            ),
        );
    }

    private function now(): string
    {
        return \function_exists('current_time')
            ? (string) \current_time('mysql', true)
            : gmdate('Y-m-d H:i:s');
    }
}
