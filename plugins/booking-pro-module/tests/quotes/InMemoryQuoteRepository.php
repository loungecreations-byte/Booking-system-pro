<?php

declare(strict_types=1);

namespace BSP\Tests\Quotes;

use BSP\Quotes\Repository\QuoteRepositoryInterface;

final class InMemoryQuoteRepository implements QuoteRepositoryInterface
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $storage = array(
        'requests'     => array(),
        'quotes'       => array(),
        'versions'     => array(),
        'lines'        => array(),
        'assumptions'  => array(),
        'followups'    => array(),
        'events'       => array(),
        'messages'     => array(),
        'message_failures' => array(),
    );

    /** @var array<string, int> */
    private array $increments = array(
        'requests'     => 1,
        'quotes'       => 1,
        'versions'     => 1,
        'lines'        => 1,
        'assumptions'  => 1,
        'followups'    => 1,
        'events'       => 1,
        'messages'     => 1,
        'message_failures' => 1,
    );

    public function createQuoteRequest(array $data): array { return $this->insert('requests', $data); }
    public function listQuoteRequests(): array { return array_values(array_reverse($this->storage['requests'])); }
    public function findQuoteRequest(int $id): ?array { return $this->find('requests', $id); }
    public function updateQuoteRequest(int $id, array $changes): array { return $this->update('requests', $id, $changes); }
    public function createQuote(array $data): array { return $this->insert('quotes', $data); }
    public function listQuotes(): array { return array_values(array_reverse($this->storage['quotes'])); }
    public function findQuote(int $id): ?array { return $this->find('quotes', $id); }
    public function findQuoteByReference(string $quoteReference): ?array
    {
        foreach ($this->storage['quotes'] as $row) {
            if ((string) ($row['quote_reference'] ?? '') === $quoteReference) {
                return $row;
            }
        }

        return null;
    }
    public function updateQuote(int $id, array $changes): array
    {
        // approved_version_id is pinned at acceptance and must not be overwritten via normal updates.
        if (array_key_exists('approved_version_id', $changes)) {
            $existing = $this->find('quotes', $id);
            $existingApproved = (int) ($existing['approved_version_id'] ?? 0);
            $incomingApproved = (int) ($changes['approved_version_id'] ?? 0);
            if ($existingApproved > 0 && $incomingApproved !== $existingApproved) {
                throw new \InvalidArgumentException(
                    "approved_version_id is al vastgezet op versie {$existingApproved} "
                    . 'en kan niet worden overschreven via een reguliere update.'
                );
            }
        }

        return $this->update('quotes', $id, $changes);
    }
    public function createQuoteVersion(array $data): array { return $this->insert('versions', $data); }
    public function listQuoteVersions(int $quoteId): array
    {
        $versions = array_values(array_filter($this->storage['versions'], static fn (array $row): bool => (int) ($row['quote_id'] ?? 0) === $quoteId));
        usort($versions, static function (array $left, array $right): int {
            return ((int) ($left['version_number'] ?? 0)) <=> ((int) ($right['version_number'] ?? 0));
        });

        return $versions;
    }
    public function findQuoteVersion(int $id): ?array { return $this->find('versions', $id); }
    public function updateQuoteVersion(int $id, array $changes): array { return $this->update('versions', $id, $changes); }
    public function createQuoteAssumption(array $data): array { return $this->insert('assumptions', $data); }
    public function listQuoteAssumptions(int $quoteId): array { return array_values(array_filter($this->storage['assumptions'], static fn (array $row): bool => (int) ($row['quote_id'] ?? 0) === $quoteId)); }
    public function updateQuoteAssumption(int $id, array $changes): array { return $this->update('assumptions', $id, $changes); }
    public function createQuoteFollowup(array $data): array { return $this->insert('followups', $data); }
    public function listQuoteFollowups(int $quoteId): array { return array_values(array_filter($this->storage['followups'], static fn (array $row): bool => (int) ($row['quote_id'] ?? 0) === $quoteId)); }
    public function updateQuoteFollowup(int $id, array $changes): array { return $this->update('followups', $id, $changes); }
    public function findQuoteFollowup(int $id): ?array { return $this->find('followups', $id); }
    public function createQuoteEvent(array $data): array { return $this->insert('events', $data); }
    public function listQuoteEvents(int $quoteId): array { return array_values(array_filter($this->storage['events'], static fn (array $row): bool => (int) ($row['quote_id'] ?? 0) === $quoteId)); }
    public function createQuoteMessage(array $data): array { return $this->insert('messages', $data); }
    public function listQuoteMessages(int $quoteId): array { return array_values(array_filter($this->storage['messages'], static fn (array $row): bool => (int) ($row['quote_id'] ?? 0) === $quoteId)); }
    public function findQuoteMessage(int $id): ?array { return $this->find('messages', $id); }
    public function findQuoteMessageByProviderMessageId(string $providerMessageId): ?array
    {
        foreach ($this->storage['messages'] as $row) {
            if ((string) ($row['provider_message_id'] ?? '') === $providerMessageId) {
                return $row;
            }
        }

        return null;
    }
    public function updateQuoteMessage(int $id, array $changes): array { return $this->update('messages', $id, $changes); }
    public function createQuoteMessageFailure(array $data): array { return $this->insert('message_failures', $data); }
    public function listQuoteMessageFailures(): array { return array_values(array_reverse($this->storage['message_failures'])); }
    public function findQuoteMessageFailure(int $id): ?array { return $this->find('message_failures', $id); }
    public function updateQuoteMessageFailure(int $id, array $changes): array { return $this->update('message_failures', $id, $changes); }

    public function replaceQuoteLines(int $quoteVersionId, array $lines): array
    {
        $this->storage['lines'] = array_values(array_filter(
            $this->storage['lines'],
            static fn (array $row): bool => (int) ($row['quote_version_id'] ?? 0) !== $quoteVersionId
        ));

        $created = array();
        foreach ($lines as $line) {
            $created[] = $this->insert('lines', array_merge($line, array('quote_version_id' => $quoteVersionId)));
        }

        return $created;
    }

    public function listQuoteLines(int $quoteVersionId): array
    {
        $lines = array_values(array_filter($this->storage['lines'], static fn (array $row): bool => (int) ($row['quote_version_id'] ?? 0) === $quoteVersionId));
        usort($lines, static function (array $left, array $right): int {
            $leftOrder = isset($left['sort_order']) ? (int) $left['sort_order'] : (int) ($left['line_number'] ?? 0);
            $rightOrder = isset($right['sort_order']) ? (int) $right['sort_order'] : (int) ($right['line_number'] ?? 0);
            if ($leftOrder !== $rightOrder) {
                return $leftOrder <=> $rightOrder;
            }

            return ((int) ($left['line_number'] ?? 0)) <=> ((int) ($right['line_number'] ?? 0));
        });

        return $lines;
    }

    public function findQuoteLine(int $id): ?array
    {
        return $this->find('lines', $id);
    }

    public function updateQuoteLine(int $id, array $changes): array
    {
        return $this->update('lines', $id, $changes);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function insert(string $bucket, array $data): array
    {
        $data['id'] = $this->increments[$bucket]++;
        $this->storage[$bucket][] = $data;
        return $data;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(string $bucket, int $id): ?array
    {
        foreach ($this->storage[$bucket] as $row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private function update(string $bucket, int $id, array $changes): array
    {
        foreach ($this->storage[$bucket] as $index => $row) {
            if ((int) ($row['id'] ?? 0) !== $id) {
                continue;
            }

            $this->storage[$bucket][$index] = array_merge($row, $changes);
            return $this->storage[$bucket][$index];
        }

        throw new \InvalidArgumentException('Record not found.');
    }
}
