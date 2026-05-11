<?php

declare(strict_types=1);

namespace BSP\Quotes\Repository;

interface QuoteRepositoryInterface
{
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createQuoteRequest(array $data): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listQuoteRequests(): array;

    /**
     * @return array<string, mixed>|null
     */
    public function findQuoteRequest(int $id): ?array;

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function updateQuoteRequest(int $id, array $changes): array;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createQuote(array $data): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listQuotes(): array;

    /**
     * @return array<string, mixed>|null
     */
    public function findQuote(int $id): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function findQuoteByReference(string $quoteReference): ?array;

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function updateQuote(int $id, array $changes): array;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createQuoteVersion(array $data): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listQuoteVersions(int $quoteId): array;

    /**
     * @return array<string, mixed>|null
     */
    public function findQuoteVersion(int $id): ?array;

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function updateQuoteVersion(int $id, array $changes): array;

    /**
     * @param array<int, array<string, mixed>> $lines
     * @return array<int, array<string, mixed>>
     */
    public function replaceQuoteLines(int $quoteVersionId, array $lines): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listQuoteLines(int $quoteVersionId): array;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createQuoteAssumption(array $data): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listQuoteAssumptions(int $quoteId): array;

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function updateQuoteAssumption(int $id, array $changes): array;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createQuoteFollowup(array $data): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listQuoteFollowups(int $quoteId): array;

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function updateQuoteFollowup(int $id, array $changes): array;

    /**
     * @return array<string, mixed>|null
     */
    public function findQuoteFollowup(int $id): ?array;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createQuoteEvent(array $data): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listQuoteEvents(int $quoteId): array;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createQuoteMessage(array $data): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listQuoteMessages(int $quoteId): array;

    /**
     * @return array<string, mixed>|null
     */
    public function findQuoteMessage(int $id): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function findQuoteMessageByProviderMessageId(string $providerMessageId): ?array;

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function updateQuoteMessage(int $id, array $changes): array;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createQuoteMessageFailure(array $data): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listQuoteMessageFailures(): array;

    /**
     * @return array<string, mixed>|null
     */
    public function findQuoteMessageFailure(int $id): ?array;

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function updateQuoteMessageFailure(int $id, array $changes): array;
}
