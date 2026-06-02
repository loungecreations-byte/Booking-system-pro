<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Quotes\Repository\QuoteRepositoryInterface;
use InvalidArgumentException;

final class QuoteAdminConfirmationService
{
    public const CONFIRMED_EVENT = 'quote_confirmed';

    public function __construct(
        private QuoteRepositoryInterface $repository,
        private QuoteEventLogger $events,
        private QuoteConfirmationReadinessService $readiness
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function confirmReadyQuote(int $quoteId, ?int $actorId = null): array
    {
        $quote = $this->repository->findQuote($quoteId);
        if ($quote === null) {
            throw new InvalidArgumentException('Quote not found.');
        }

        $orderId = (int) ($quote['woo_order_id'] ?? 0);
        $confirmedEvents = $this->eventsForOrder($quoteId, self::CONFIRMED_EVENT, $orderId);
        if ((string) ($quote['status'] ?? '') === 'confirmed' && count($confirmedEvents) === 1) {
            return array('ok' => true, 'code' => 'already_confirmed', 'updated' => false);
        }
        if (count($confirmedEvents) > 0) {
            throw new InvalidArgumentException('Quote is al bevestigd of heeft een inconsistente confirmation event-status.');
        }

        if ((string) ($quote['status'] ?? '') !== 'accepted') {
            throw new InvalidArgumentException('Alleen accepted quotes kunnen via deze admin-actie worden bevestigd.');
        }

        $handoffStatus = (string) ($quote['handoff_status'] ?? '');
        $knownReadinessOutcomes = array(
            QuoteConfirmationReadinessService::READY_TO_CONFIRM,
            QuoteConfirmationReadinessService::AWAITING_SUPPLIER_CONFIRMATION,
            QuoteConfirmationReadinessService::REQUIRES_ADMIN_CONFIRMATION,
            QuoteConfirmationReadinessService::CONFIRMATION_BLOCKED,
        );
        if ($handoffStatus !== QuotePaymentSyncService::COMPLETED_STATUS && ! in_array($handoffStatus, $knownReadinessOutcomes, true)) {
            throw new InvalidArgumentException('Quote betaling is nog niet als Woo payment completed of readiness beoordeeld vastgelegd.');
        }

        $approvedVersionId = (int) ($quote['approved_version_id'] ?? 0);
        if ($approvedVersionId <= 0) {
            throw new InvalidArgumentException('Quote heeft geen geaccepteerde versie.');
        }

        $version = $this->repository->findQuoteVersion($approvedVersionId);
        if (! is_array($version) || (int) ($version['quote_id'] ?? 0) !== $quoteId) {
            throw new InvalidArgumentException('Geaccepteerde quote-versie ontbreekt of hoort niet bij deze quote.');
        }

        if ($orderId <= 0 || ! \function_exists('wc_get_order')) {
            throw new InvalidArgumentException('Quote heeft geen gekoppelde Woo order.');
        }

        $order = \wc_get_order($orderId);
        if (! $order instanceof \WC_Order) {
            throw new InvalidArgumentException('Gekoppelde Woo order niet gevonden.');
        }

        if ((int) $order->get_meta('_sbdp_quote_id') !== $quoteId) {
            throw new InvalidArgumentException('Woo order quote-meta matcht deze quote niet.');
        }

        if ((int) $order->get_meta('_sbdp_quote_version_id') !== $approvedVersionId) {
            throw new InvalidArgumentException('Woo order quote-versie matcht de geaccepteerde versie niet.');
        }

        $paymentEvents = $this->eventsForOrder($quoteId, QuotePaymentSyncService::COMPLETED_EVENT, $orderId);
        if (count($paymentEvents) !== 1) {
            throw new InvalidArgumentException('Quote confirmation vereist exact een Woo payment completed event.');
        }

        $readiness = array('outcome' => $handoffStatus);
        if ($handoffStatus === QuotePaymentSyncService::COMPLETED_STATUS) {
            $readiness = $this->readiness->evaluate($quoteId);
        }
        if ((string) ($readiness['outcome'] ?? '') !== QuoteConfirmationReadinessService::READY_TO_CONFIRM) {
            throw new InvalidArgumentException('Quote is niet ready_to_confirm: ' . (string) ($readiness['outcome'] ?? 'unknown'));
        }

        $confirmedAt = $this->now();
        $payload = array(
            'quote_id' => $quoteId,
            'approved_version_id' => $approvedVersionId,
            'woo_order_id' => $orderId,
            'confirmed_by' => $actorId,
            'readiness' => QuoteConfirmationReadinessService::READY_TO_CONFIRM,
            'source' => 'admin_cta',
            'confirmed_at' => $confirmedAt,
        );

        $this->repository->updateQuote($quoteId, array(
            'status' => 'confirmed',
            'updated_at' => $confirmedAt,
        ));

        $this->events->log(
            self::CONFIRMED_EVENT,
            isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
            $quoteId,
            $approvedVersionId,
            $actorId,
            'Quote bevestigd via admin CTA na readiness-check.',
            $payload
        );

        return array('ok' => true, 'code' => 'quote_confirmed', 'updated' => true, 'payload' => $payload);
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
            $eventOrderId = (int) ($payload['order_id'] ?? ($payload['woo_order_id'] ?? 0));
            if ($orderId <= 0 || $eventOrderId === $orderId) {
                $matches[] = $event;
            }
        }

        return $matches;
    }

    private function now(): string
    {
        return \function_exists('current_time')
            ? (string) \current_time('mysql', true)
            : gmdate('Y-m-d H:i:s');
    }
}
