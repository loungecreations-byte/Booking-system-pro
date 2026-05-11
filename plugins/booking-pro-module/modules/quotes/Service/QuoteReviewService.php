<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Quotes\Repository\QuoteRepositoryInterface;
use InvalidArgumentException;

final class QuoteReviewService
{
    public function __construct(
        private QuoteRepositoryInterface $repository,
        private QuoteEventLogger $events,
        private QuoteFollowupService $followups
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function requestReview(int $quoteId, ?int $actorId = null): array
    {
        $quote = $this->repository->findQuote($quoteId);
        if ($quote === null) {
            throw new InvalidArgumentException('Quote not found.');
        }

        (new QuoteImmutabilityGuard($this->repository))->assertQuoteCommercialContextEditable($quoteId);

        $updated = $this->repository->updateQuote($quoteId, array(
            'review_status' => 'pending_review',
            'status'        => 'in_review',
        ));

        $this->events->log(
            'quote_review_requested',
            isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
            $quoteId,
            isset($quote['current_version_id']) ? (int) $quote['current_version_id'] : null,
            $actorId,
            'Quote ter review aangeboden.',
            array('review_status' => 'pending_review')
        );

        if (! $this->hasOpenFollowup($quoteId)) {
            $this->followups->create(array(
                'quote_id'         => $quoteId,
                'followup_type'    => 'review',
                'priority'         => 'high',
                'title'            => 'Voer commerciële review uit',
                'note'             => 'Beoordeel assumptions, ontbrekende gegevens en line mapping voordat de quote wordt vrijgegeven.',
                'assigned_user_id' => isset($updated['owner_user_id']) ? (int) $updated['owner_user_id'] : null,
                'due_at'           => gmdate('Y-m-d H:i:s', strtotime('+1 day') ?: time()),
                'actor_id'         => $actorId,
            ));
        }

        return $updated;
    }

    /**
     * @return array<string, mixed>
     */
    public function approve(int $quoteId, ?int $actorId = null): array
    {
        $quote = $this->repository->findQuote($quoteId);
        if ($quote === null) {
            throw new InvalidArgumentException('Quote not found.');
        }

        (new QuoteImmutabilityGuard($this->repository))->assertQuoteCommercialContextEditable($quoteId);

        foreach ($this->repository->listQuoteAssumptions($quoteId) as $assumption) {
            if ((string) ($assumption['status'] ?? 'open') !== 'open') {
                continue;
            }

            if (! empty($assumption['blocks_review'])) {
                throw new InvalidArgumentException('Quote review kan niet worden goedgekeurd zolang blokkerende assumptions open staan.');
            }
        }

        (new QuoteSendReadinessValidator($this->repository))->assertReadyToSend($quoteId);

        $updated = $this->repository->updateQuote($quoteId, array(
            'review_status' => 'approved',
            'send_status'   => 'ready_to_send',
            'status'        => 'reviewed',
            'approved_at'   => $this->now(),
            'approved_by'   => $actorId,
        ));

        $this->events->log(
            'quote_review_approved',
            isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
            $quoteId,
            isset($quote['current_version_id']) ? (int) $quote['current_version_id'] : null,
            $actorId,
            'Quote review goedgekeurd.',
            array('send_status' => 'ready_to_send')
        );

        return $updated;
    }

    /**
     * @return array<string, mixed>
     */
    public function returnToDraft(int $quoteId, string $note = '', ?int $actorId = null): array
    {
        $quote = $this->repository->findQuote($quoteId);
        if ($quote === null) {
            throw new InvalidArgumentException('Quote not found.');
        }

        // Block if quote is in a content-frozen status (sent, accepted, cancelled, etc.).
        // A sent/accepted quote cannot be reset to draft; create a new revision via saveDraft instead.
        (new QuoteImmutabilityGuard($this->repository))->assertQuoteCommercialContextEditable($quoteId);

        $internalNotes = trim((string) ($quote['internal_notes'] ?? ''));
        $note = trim($note);
        if ($note !== '') {
            $internalNotes = trim($internalNotes . "\n" . '[Review teruggezet] ' . $note);
        }

        $updated = $this->repository->updateQuote($quoteId, array(
            'review_status' => 'changes_requested',
            'send_status'   => 'not_ready',
            'status'        => 'draft',
            'internal_notes'=> $internalNotes,
        ));

        $this->events->log(
            'quote_review_returned_to_draft',
            isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
            $quoteId,
            isset($quote['current_version_id']) ? (int) $quote['current_version_id'] : null,
            $actorId,
            'Quote teruggezet naar draft na review.',
            array('note' => $note)
        );

        return $updated;
    }

    private function hasOpenFollowup(int $quoteId): bool
    {
        foreach ($this->repository->listQuoteFollowups($quoteId) as $followup) {
            if ((string) ($followup['status'] ?? 'open') === 'open') {
                return true;
            }
        }

        return false;
    }

    private function now(): string
    {
        return \function_exists('current_time')
            ? (string) \current_time('mysql', true)
            : gmdate('Y-m-d H:i:s');
    }
}
