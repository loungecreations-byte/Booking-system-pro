<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Quotes\Repository\QuoteRepositoryInterface;
use InvalidArgumentException;

final class QuoteSendService
{
    public function __construct(
        private QuoteRepositoryInterface $repository,
        private QuoteEventLogger $events
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function markSentManual(int $quoteId, string $channel = 'manual', string $note = '', ?int $actorId = null): array
    {
        $quote = $this->repository->findQuote($quoteId);
        if ($quote === null) {
            throw new InvalidArgumentException('Quote not found.');
        }

        if ((string) ($quote['review_status'] ?? 'not_started') !== 'approved') {
            throw new InvalidArgumentException('Quote kan pas als verzonden worden gemarkeerd na goedgekeurde review.');
        }

        if ((string) ($quote['send_status'] ?? 'not_ready') !== 'ready_to_send') {
            throw new InvalidArgumentException('Quote staat niet klaar om te worden verzonden.');
        }

        (new QuoteSendReadinessValidator($this->repository))->assertReadyToSend($quoteId);

        foreach ($this->repository->listQuoteAssumptions($quoteId) as $assumption) {
            if ((string) ($assumption['status'] ?? 'open') !== 'open') {
                continue;
            }

            if (! empty($assumption['blocks_send'])) {
                throw new InvalidArgumentException('Quote kan niet als verzonden worden gemarkeerd zolang blokkerende send-assumptions open staan.');
            }
        }

        $internalNotes = trim((string) ($quote['internal_notes'] ?? ''));
        $note = trim($note);
        if ($note !== '') {
            $internalNotes = trim($internalNotes . "\n" . '[Verzonden] ' . $note);
        }

        $updated = $this->repository->updateQuote($quoteId, array(
            'send_status' => 'sent_manual',
            'status' => 'sent',
            'sent_at' => $this->now(),
            'sent_by' => $actorId,
            'internal_notes' => $internalNotes,
        ));

        $this->events->log(
            'quote_marked_sent_manual',
            isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
            $quoteId,
            isset($quote['current_version_id']) ? (int) $quote['current_version_id'] : null,
            $actorId,
            'Quote handmatig als verzonden gemarkeerd.',
            array(
                'channel' => $channel,
                'note' => $note,
            )
        );

        return $updated;
    }

    /**
     * @return array<string, mixed>
     */
    public function reopenSend(int $quoteId, string $note = '', ?int $actorId = null): array
    {
        $quote = $this->repository->findQuote($quoteId);
        if ($quote === null) {
            throw new InvalidArgumentException('Quote not found.');
        }

        // Block TERMINAL statuses — an accepted/cancelled/declined quote cannot be re-opened.
        // 'sent' is intentionally allowed: reopenSend() is designed to undo a sent-manual mark.
        (new QuoteImmutabilityGuard($this->repository))->assertQuoteAcceptsRevision($quoteId);

        if ((string) ($quote['review_status'] ?? 'not_started') !== 'approved') {
            throw new InvalidArgumentException('Alleen een review-goedgekeurde quote kan terug naar ready_to_send.');
        }

        $internalNotes = trim((string) ($quote['internal_notes'] ?? ''));
        $note = trim($note);
        if ($note !== '') {
            $internalNotes = trim($internalNotes . "\n" . '[Send heropend] ' . $note);
        }

        $updated = $this->repository->updateQuote($quoteId, array(
            'send_status' => 'ready_to_send',
            'status' => 'reviewed',
            'internal_notes' => $internalNotes,
        ));

        $this->events->log(
            'quote_send_reopened',
            isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
            $quoteId,
            isset($quote['current_version_id']) ? (int) $quote['current_version_id'] : null,
            $actorId,
            'Quote teruggezet naar ready_to_send.',
            array('note' => $note)
        );

        return $updated;
    }

    private function now(): string
    {
        return \function_exists('current_time')
            ? (string) \current_time('mysql', true)
            : gmdate('Y-m-d H:i:s');
    }
}
