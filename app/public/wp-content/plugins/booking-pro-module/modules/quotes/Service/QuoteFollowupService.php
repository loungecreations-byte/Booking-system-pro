<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Quotes\Repository\QuoteRepositoryInterface;
use InvalidArgumentException;

final class QuoteFollowupService
{
    public function __construct(
        private QuoteRepositoryInterface $repository,
        private QuoteEventLogger $events
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $quoteId = (int) ($input['quote_id'] ?? 0);
        if ($quoteId <= 0) {
            throw new InvalidArgumentException('Quote id is required.');
        }

        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Follow-up title is required.');
        }

        $quote = $this->repository->findQuote($quoteId);
        if ($quote === null) {
            throw new InvalidArgumentException('Quote not found.');
        }

        $followup = $this->repository->createQuoteFollowup(array(
            'quote_request_id' => isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
            'quote_id'         => $quoteId,
            'followup_type'    => $this->normalizeString($input['followup_type'] ?? 'manual_review', 'manual_review'),
            'status'           => 'open',
            'priority'         => $this->normalizeString($input['priority'] ?? 'normal', 'normal'),
            'title'            => $title,
            'note'             => trim((string) ($input['note'] ?? '')),
            'due_at'           => $this->normalizeDateTime($input['due_at'] ?? null),
            'assigned_user_id' => $this->normalizeInt($input['assigned_user_id'] ?? null),
            'created_by'       => $this->normalizeInt($input['actor_id'] ?? null),
        ));

        $this->events->log(
            'quote_followup_created',
            isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
            $quoteId,
            isset($quote['current_version_id']) ? (int) $quote['current_version_id'] : null,
            $this->normalizeInt($input['actor_id'] ?? null),
            'Quote follow-up aangemaakt.',
            array(
                'followup_id' => $followup['id'] ?? null,
                'followup_type' => $followup['followup_type'] ?? 'manual_review',
            )
        );

        return $followup;
    }

    /**
     * @param array<string, mixed> $quote
     * @return array<string, mixed>
     */
    public function createInitialReviewFollowup(array $quote, ?int $actorId = null): array
    {
        return $this->create(array(
            'quote_id'         => (int) ($quote['id'] ?? 0),
            'followup_type'    => 'manual_review',
            'priority'         => 'high',
            'title'            => 'Controleer intake en eerste quote-opzet',
            'note'             => 'Verifieer ontbrekende gegevens, aannames en mapping voordat de quote verder gaat.',
            'due_at'           => $this->defaultDueAt(),
            'assigned_user_id' => isset($quote['owner_user_id']) ? (int) $quote['owner_user_id'] : null,
            'actor_id'         => $actorId,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function complete(int $followupId, ?int $actorId = null): array
    {
        $followup = $this->repository->findQuoteFollowup($followupId);
        if ($followup === null) {
            throw new InvalidArgumentException('Follow-up not found.');
        }

        $updated = $this->repository->updateQuoteFollowup($followupId, array(
            'status'       => 'completed',
            'completed_at' => $this->now(),
            'completed_by' => $actorId,
        ));

        $this->events->log(
            'quote_followup_completed',
            isset($followup['quote_request_id']) ? (int) $followup['quote_request_id'] : null,
            (int) ($followup['quote_id'] ?? 0),
            null,
            $actorId,
            'Quote follow-up afgerond.',
            array('followup_id' => $followupId)
        );

        return $updated;
    }

    private function defaultDueAt(): string
    {
        $timestamp = strtotime('+1 day');
        return gmdate('Y-m-d H:i:s', $timestamp !== false ? $timestamp : time());
    }

    private function normalizeDateTime($value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $timestamp = strtotime($raw);
        if ($timestamp === false) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function normalizeInt($value): ?int
    {
        $int = (int) $value;
        return $int > 0 ? $int : null;
    }

    private function normalizeString($value, string $fallback): string
    {
        $normalized = trim((string) $value);
        return $normalized !== '' ? $normalized : $fallback;
    }

    private function now(): string
    {
        return \function_exists('current_time')
            ? (string) \current_time('mysql', true)
            : gmdate('Y-m-d H:i:s');
    }
}
