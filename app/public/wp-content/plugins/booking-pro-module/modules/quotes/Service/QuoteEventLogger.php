<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Quotes\Repository\QuoteRepositoryInterface;

final class QuoteEventLogger
{
    public function __construct(private QuoteRepositoryInterface $repository)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function log(
        string $eventType,
        ?int $quoteRequestId = null,
        ?int $quoteId = null,
        ?int $quoteVersionId = null,
        ?int $actorId = null,
        string $message = '',
        array $payload = array()
    ): array {
        return $this->repository->createQuoteEvent(array(
            'quote_request_id' => $quoteRequestId,
            'quote_id'         => $quoteId,
            'quote_version_id' => $quoteVersionId,
            'event_type'       => $eventType,
            'actor_type'       => $actorId !== null && $actorId > 0 ? 'user' : 'system',
            'actor_id'         => $actorId,
            'message'          => $message,
            'payload_json'     => $payload,
            'occurred_at'      => $this->now(),
        ));
    }

    private function now(): string
    {
        return \function_exists('current_time')
            ? (string) \current_time('mysql', true)
            : gmdate('Y-m-d H:i:s');
    }
}
