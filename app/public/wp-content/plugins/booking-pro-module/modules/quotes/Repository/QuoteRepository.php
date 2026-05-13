<?php

declare(strict_types=1);

namespace BSP\Quotes\Repository;

use InvalidArgumentException;
use wpdb;

use function current_time;
use function is_array;
use function wp_json_encode;

final class QuoteRepository implements QuoteRepositoryInterface
{
    private ?wpdb $db;
    /**
     * @var array<string, array<int, string>>
     */
    private array $tableColumns = array();

    public function __construct(?wpdb $db = null)
    {
        if ($db instanceof wpdb) {
            $this->db = $db;
            return;
        }

        global $wpdb;
        $this->db = $wpdb instanceof wpdb ? $wpdb : null;
    }

    public function createQuoteRequest(array $data): array
    {
        return $this->createRecord('quote_requests', $data, array($this, 'findQuoteRequest'));
    }

    public function listQuoteRequests(): array
    {
        return $this->listRecords('quote_requests');
    }

    public function findQuoteRequest(int $id): ?array
    {
        return $this->findRecord('quote_requests', $id);
    }

    public function updateQuoteRequest(int $id, array $changes): array
    {
        return $this->updateRecord('quote_requests', $id, $changes, array($this, 'findQuoteRequest'));
    }

    public function createQuote(array $data): array
    {
        return $this->createRecord('quotes', $data, array($this, 'findQuote'));
    }

    public function listQuotes(): array
    {
        return $this->listRecords('quotes');
    }

    public function findQuote(int $id): ?array
    {
        return $this->findRecord('quotes', $id);
    }

    public function findQuoteByReference(string $quoteReference): ?array
    {
        return $this->findByColumn('quotes', 'quote_reference', $quoteReference);
    }

    public function updateQuote(int $id, array $changes): array
    {
        // approved_version_id is pinned at customer acceptance and must not be overwritten
        // by normal edit/revise operations. Only QuoteAcceptanceService may set it.
        if (array_key_exists('approved_version_id', $changes)) {
            $existing = $this->findQuote($id);
            $existingApproved = (int) ($existing['approved_version_id'] ?? 0);
            $incomingApproved = (int) ($changes['approved_version_id'] ?? 0);
            if ($existingApproved > 0 && $incomingApproved !== $existingApproved) {
                throw new \InvalidArgumentException(
                    "approved_version_id is al vastgezet op versie {$existingApproved} "
                    . 'en kan niet worden overschreven via een reguliere update.'
                );
            }
        }

        return $this->updateRecord('quotes', $id, $changes, array($this, 'findQuote'));
    }

    public function createQuoteVersion(array $data): array
    {
        return $this->createRecord('quote_versions', $data, array($this, 'findQuoteVersion'));
    }

    public function listQuoteVersions(int $quoteId): array
    {
        return $this->listByColumn('quote_versions', 'quote_id', $quoteId, 'version_number');
    }

    public function findQuoteVersion(int $id): ?array
    {
        return $this->findRecord('quote_versions', $id);
    }

    public function updateQuoteVersion(int $id, array $changes): array
    {
        return $this->updateRecord('quote_versions', $id, $changes, array($this, 'findQuoteVersion'));
    }

    public function replaceQuoteLines(int $quoteVersionId, array $lines): array
    {
        $this->assertDb();
        $this->db->delete($this->table('quote_lines'), array('quote_version_id' => $quoteVersionId));

        $created = array();
        $lineNumber = 1;
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $created[] = $this->createRecord('quote_lines', array_merge($line, array(
                'quote_version_id' => $quoteVersionId,
                'line_number'      => $line['line_number'] ?? $lineNumber,
            )), null);
            $lineNumber++;
        }

        return $created;
    }

    public function listQuoteLines(int $quoteVersionId): array
    {
        if ($this->tableHasColumn('quote_lines', 'sort_order')) {
            return $this->listByColumn('quote_lines', 'quote_version_id', $quoteVersionId, 'sort_order');
        }

        return $this->listByColumn('quote_lines', 'quote_version_id', $quoteVersionId, 'line_number');
    }

    public function findQuoteLine(int $id): ?array
    {
        return $this->findRecord('quote_lines', $id);
    }

    public function updateQuoteLine(int $id, array $changes): array
    {
        return $this->updateRecord('quote_lines', $id, $changes, array($this, 'findQuoteLine'));
    }

    public function createQuoteAssumption(array $data): array
    {
        return $this->createRecord('quote_assumptions', $data, null);
    }

    public function listQuoteAssumptions(int $quoteId): array
    {
        return $this->listByColumn('quote_assumptions', 'quote_id', $quoteId);
    }

    public function updateQuoteAssumption(int $id, array $changes): array
    {
        return $this->updateRecord('quote_assumptions', $id, $changes, null);
    }

    public function createQuoteFollowup(array $data): array
    {
        return $this->createRecord('quote_followups', $data, array($this, 'findQuoteFollowup'));
    }

    public function listQuoteFollowups(int $quoteId): array
    {
        return $this->listByColumn('quote_followups', 'quote_id', $quoteId, 'due_at');
    }

    public function updateQuoteFollowup(int $id, array $changes): array
    {
        return $this->updateRecord('quote_followups', $id, $changes, array($this, 'findQuoteFollowup'));
    }

    public function findQuoteFollowup(int $id): ?array
    {
        return $this->findRecord('quote_followups', $id);
    }

    public function createQuoteEvent(array $data): array
    {
        return $this->createRecord('quote_events', $data, null);
    }

    public function listQuoteEvents(int $quoteId): array
    {
        return $this->listByColumn('quote_events', 'quote_id', $quoteId);
    }

    public function createQuoteMessage(array $data): array
    {
        return $this->createRecord('quote_messages', $data, array($this, 'findQuoteMessage'));
    }

    public function listQuoteMessages(int $quoteId): array
    {
        $this->assertDb();

        $rows = $this->db->get_results(
            $this->db->prepare(
                'SELECT * FROM ' . $this->table('quote_messages') . ' WHERE quote_id = %d ORDER BY created_at ASC, id ASC',
                $quoteId
            ),
            ARRAY_A
        );

        return $this->hydrateRows($rows);
    }

    public function findQuoteMessage(int $id): ?array
    {
        return $this->findRecord('quote_messages', $id);
    }

    public function findQuoteMessageByProviderMessageId(string $providerMessageId): ?array
    {
        return $this->findByColumn('quote_messages', 'provider_message_id', $providerMessageId);
    }

    public function updateQuoteMessage(int $id, array $changes): array
    {
        return $this->updateRecord('quote_messages', $id, $changes, array($this, 'findQuoteMessage'));
    }

    public function createQuoteMessageFailure(array $data): array
    {
        return $this->createRecord('quote_message_failures', $data, array($this, 'findQuoteMessageFailure'));
    }

    public function listQuoteMessageFailures(): array
    {
        return $this->listRecords('quote_message_failures');
    }

    public function findQuoteMessageFailure(int $id): ?array
    {
        return $this->findRecord('quote_message_failures', $id);
    }

    public function updateQuoteMessageFailure(int $id, array $changes): array
    {
        return $this->updateRecord('quote_message_failures', $id, $changes, array($this, 'findQuoteMessageFailure'));
    }

    /**
     * @param callable(int): (?array<string, mixed>)|null $finder
     * @return array<string, mixed>
     */
    private function createRecord(string $tableSuffix, array $data, ?callable $finder): array
    {
        $this->assertDb();

        $inserted = $this->db->insert($this->table($tableSuffix), $this->preparePayload($tableSuffix, $data));
        if (! $inserted) {
            throw new InvalidArgumentException(sprintf('Unable to create %s record.', $tableSuffix));
        }

        if ($finder !== null) {
            $record = $finder((int) $this->db->insert_id);
            return is_array($record) ? $record : array();
        }

        $row = $this->db->get_row(
            $this->db->prepare('SELECT * FROM ' . $this->table($tableSuffix) . ' WHERE id = %d', (int) $this->db->insert_id),
            ARRAY_A
        );

        return is_array($row) ? $this->hydrate($row) : array();
    }

    /**
     * @param callable(int): (?array<string, mixed>)|null $finder
     * @return array<string, mixed>
     */
    private function updateRecord(string $tableSuffix, int $id, array $changes, ?callable $finder): array
    {
        $this->assertDb();

        $updated = $this->db->update(
            $this->table($tableSuffix),
            $this->preparePayload($tableSuffix, $changes),
            array('id' => $id)
        );

        if ($updated === false) {
            throw new InvalidArgumentException(sprintf('Unable to update %s record.', $tableSuffix));
        }

        if ($finder !== null) {
            $record = $finder($id);
            return is_array($record) ? $record : array();
        }

        $row = $this->db->get_row(
            $this->db->prepare('SELECT * FROM ' . $this->table($tableSuffix) . ' WHERE id = %d', $id),
            ARRAY_A
        );

        return is_array($row) ? $this->hydrate($row) : array();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listRecords(string $tableSuffix): array
    {
        $this->assertDb();

        $rows = $this->db->get_results(
            'SELECT * FROM ' . $this->table($tableSuffix) . ' ORDER BY id DESC',
            ARRAY_A
        );

        return $this->hydrateRows($rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listByColumn(string $tableSuffix, string $column, int $value, string $orderBy = 'id'): array
    {
        $this->assertDb();

        $rows = $this->db->get_results(
            $this->db->prepare(
                'SELECT * FROM ' . $this->table($tableSuffix) . ' WHERE ' . $column . ' = %d ORDER BY ' . $orderBy . ' DESC, id DESC',
                $value
            ),
            ARRAY_A
        );

        return $this->hydrateRows($rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRecord(string $tableSuffix, int $id): ?array
    {
        $this->assertDb();

        $row = $this->db->get_row(
            $this->db->prepare('SELECT * FROM ' . $this->table($tableSuffix) . ' WHERE id = %d', $id),
            ARRAY_A
        );

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByColumn(string $tableSuffix, string $column, string $value): ?array
    {
        $this->assertDb();

        $row = $this->db->get_row(
            $this->db->prepare(
                'SELECT * FROM ' . $this->table($tableSuffix) . ' WHERE ' . $column . ' = %s ORDER BY id DESC',
                $value
            ),
            ARRAY_A
        );

        return is_array($row) ? $this->hydrate($row) : null;
    }

    private function table(string $suffix): string
    {
        return $this->db->prefix . 'bsp_' . $suffix;
    }

    private function assertDb(): void
    {
        if (! $this->db instanceof wpdb) {
            throw new InvalidArgumentException('Database connection unavailable.');
        }
    }

    /**
     * @param array<int, array<string, mixed>>|null $rows
     * @return array<int, array<string, mixed>>
     */
    private function hydrateRows(?array $rows): array
    {
        if (! is_array($rows)) {
            return array();
        }

        return array_map(array($this, 'hydrate'), $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        foreach ($row as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed === '') {
                continue;
            }

            if ($trimmed[0] === '{' || $trimmed[0] === '[') {
                $decoded = json_decode($trimmed, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $row[$key] = $decoded;
                }
            }
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function preparePayload(string $tableSuffix, array $payload): array
    {
        $prepared = array();
        foreach ($payload as $key => $value) {
            $prepared[$key] = is_array($value) ? wp_json_encode($value) : $value;
        }

        if (! isset($prepared['updated_at']) && $this->tableHasColumn($tableSuffix, 'updated_at')) {
            $prepared['updated_at'] = current_time('mysql', true);
        }

        if (! isset($prepared['created_at']) && $this->tableHasColumn($tableSuffix, 'created_at')) {
            $prepared['created_at'] = current_time('mysql', true);
        }

        return $prepared;
    }

    private function tableHasColumn(string $tableSuffix, string $column): bool
    {
        if (isset($this->tableColumns[$tableSuffix])) {
            return in_array($column, $this->tableColumns[$tableSuffix], true);
        }

        $this->assertDb();

        $table = $this->table($tableSuffix);
        $rows = $this->db->get_results('SHOW COLUMNS FROM ' . $table, ARRAY_A);

        $columns = array();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (! is_array($row) || ! isset($row['Field'])) {
                    continue;
                }
                $columns[] = (string) $row['Field'];
            }
        }

        $this->tableColumns[$tableSuffix] = $columns;

        return in_array($column, $columns, true);
    }
}
