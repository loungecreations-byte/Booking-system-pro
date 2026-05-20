<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Quotes\Repository\QuoteRepositoryInterface;
use BSPModule\Core\Services\BookingModeService;
use InvalidArgumentException;

final class QuoteSupplierConfirmationService
{
    private const ASSUMPTION_TYPE = 'supplier_confirmation_required';
    private const FOLLOWUP_TYPE = 'supplier_confirmation';
    private const SUPPLIER_STATUSES = array(
        'supplier_confirmation_required',
        'supplier_option_requested',
        'supplier_option_held',
        'supplier_declined',
        'supplier_booking_confirmed',
        'supplier_unavailable',
    );

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return self::SUPPLIER_STATUSES;
    }

    public function __construct(
        private QuoteRepositoryInterface $repository,
        private QuoteEventLogger $events,
        private ?BookingModeService $bookingModeService = null
    ) {
    }

    /**
     * Synchronize supplier-confirmation state for every relevant line in a quote.
     *
     * @return array<string, mixed>
     */
    public function syncQuote(int $quoteId, ?int $actorId = null): array
    {
        $quote = $this->repository->findQuote($quoteId);
        if ($quote === null) {
            throw new InvalidArgumentException('Quote not found.');
        }

        $versionId = (int) ($quote['current_version_id'] ?? 0);
        if ($versionId <= 0) {
            throw new InvalidArgumentException('Quote heeft geen actieve versie.');
        }

        $version = $this->repository->findQuoteVersion($versionId);
        if ($version === null || (int) ($version['quote_id'] ?? 0) !== $quoteId) {
            throw new InvalidArgumentException('Actieve quote-versie niet gevonden.');
        }

        $changes = array();
        foreach ($this->repository->listQuoteLines($versionId) as $line) {
            if (! is_array($line)) {
                continue;
            }

            $result = $this->syncLine($quote, $version, $line, null, array(), $actorId, false);
            if (! empty($result['changed'])) {
                $changes[] = $result;
            }
        }

        $this->refreshVersionConfidence($versionId);

        if ($changes !== array()) {
            $this->events->log(
                'quote_supplier_confirmation_synced',
                isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
                $quoteId,
                $versionId,
                $actorId,
                'Supplier confirmation state gesynchroniseerd voor relevante quote-regels.',
                array(
                    'line_changes' => count($changes),
                )
            );
        }

        return array(
            'quote' => $this->repository->findQuote($quoteId) ?? $quote,
            'version' => $this->repository->findQuoteVersion($versionId) ?? $version,
            'changes' => $changes,
        );
    }

    /**
     * Update the supplier-confirmation status for a single line.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateStatus(int $quoteId, int $lineId, string $status, array $payload = array(), ?int $actorId = null): array
    {
        $status = $this->normalizeStatus($status);

        $quote = $this->repository->findQuote($quoteId);
        if ($quote === null) {
            throw new InvalidArgumentException('Quote not found.');
        }

        $versionId = (int) ($quote['current_version_id'] ?? 0);
        if ($versionId <= 0) {
            throw new InvalidArgumentException('Quote heeft geen actieve versie.');
        }

        $version = $this->repository->findQuoteVersion($versionId);
        if ($version === null || (int) ($version['quote_id'] ?? 0) !== $quoteId) {
            throw new InvalidArgumentException('Actieve quote-versie niet gevonden.');
        }

        $line = $this->repository->findQuoteLine($lineId);
        if ($line === null || (int) ($line['quote_version_id'] ?? 0) !== $versionId) {
            throw new InvalidArgumentException('Programmaregel niet gevonden in de actieve quote-versie.');
        }

        $result = $this->syncLine($quote, $version, $line, $status, $payload, $actorId, true);
        $this->refreshVersionConfidence($versionId);

        return array(
            'quote' => $this->repository->findQuote($quoteId) ?? $quote,
            'version' => $this->repository->findQuoteVersion($versionId) ?? $version,
            'line' => $this->repository->findQuoteLine($lineId) ?? $line,
            'result' => $result,
        );
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $version
     * @param array<string, mixed> $line
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function syncLine(array $quote, array $version, array $line, ?string $forcedStatus, array $payload, ?int $actorId, bool $emitEvent): array
    {
        $productId = (int) ($line['product_id'] ?? 0);
        if ($productId <= 0) {
            return array('changed' => false, 'skipped' => true);
        }

        $bookingMode = $this->resolveBookingMode($productId);
        if (($bookingMode['bookingMode'] ?? '') !== BookingModeService::MODE_SUPPLIER_CONFIRMATION) {
            return array('changed' => false, 'skipped' => true);
        }

        $snapshot = $this->normalizeSnapshot($line['availability_snapshot_json'] ?? array());
        $taskKey = $this->resolveTaskKey($quote, $version, $line, $snapshot);
        $currentStatus = $this->normalizeStatus((string) ($snapshot['supplierStatus'] ?? ''));
        $status = $forcedStatus !== null ? $forcedStatus : ($currentStatus !== '' ? $currentStatus : self::SUPPLIER_STATUSES[0]);
        $status = $this->normalizeStatus($status);
        $supplierName = trim((string) ($bookingMode['supplierName'] ?? ''));
        $supplierName = $supplierName !== '' ? $supplierName : 'Eropuitje';
        $snapshotBefore = $snapshot;

        $snapshot['bookingMode'] = BookingModeService::MODE_SUPPLIER_CONFIRMATION;
        $snapshot['supplierTaskKey'] = $taskKey;
        $snapshot['supplierStatus'] = $status;
        $snapshot['supplierName'] = $supplierName;
        $snapshot['supplierSpotId'] = $snapshot['supplierSpotId'] ?? null;
        $snapshot['supplierOptionDays'] = (int) ($bookingMode['supplierOptionDays'] ?? 3) > 0 ? (int) ($bookingMode['supplierOptionDays'] ?? 3) : 3;
        $snapshot['supplierCancelMode'] = (string) ($bookingMode['supplierCancelMode'] ?? 'manual') !== '' ? (string) ($bookingMode['supplierCancelMode'] ?? 'manual') : 'manual';
        $snapshot['availabilityStatus'] = $this->resolveAvailabilityStatus($snapshot, $status);
        $snapshot['availabilityCheckedAt'] = $this->resolveAvailabilityCheckedAt($snapshot);
        $snapshot['participants'] = $this->resolveParticipants($line, $snapshot);
        $snapshot['date'] = $this->resolveDate($line, $snapshot);
        $snapshot['startTime'] = $this->resolveStartTime($line, $snapshot);
        $snapshot['endTime'] = $this->resolveEndTime($line, $snapshot);
        $snapshot['optionExpiresAt'] = $this->resolveOptionExpiresAt($snapshot, $line, $status, $payload);
        $snapshot['supplierBookingReference'] = $this->resolveSupplierBookingReference($snapshot, $payload);
        $snapshot['supplierInternalNote'] = $this->resolveSupplierInternalNote($snapshot, $payload);

        $changes = array(
            'availability_snapshot_json' => $snapshot,
        );

        if ($status === 'supplier_booking_confirmed') {
            $changes['availability_confidence'] = 'confirmed';
            if ((string) ($line['line_status'] ?? '') === 'unavailable') {
                $changes['line_status'] = (int) ($line['product_id'] ?? 0) > 0 ? 'mapped' : 'directional';
            }
        } elseif (in_array($status, array('supplier_option_requested', 'supplier_option_held'), true)) {
            $changes['availability_confidence'] = 'projected';
        } else {
            $changes['availability_confidence'] = 'unknown';
            if (in_array($status, array('supplier_declined', 'supplier_unavailable'), true)) {
                $changes['line_status'] = 'unavailable';
            }
        }

        $updatedLine = $this->repository->updateQuoteLine((int) $line['id'], $changes);

        $assumption = $this->upsertAssumption($quote, $version, $updatedLine, $status, $snapshot, $payload, $actorId);
        $followup = $this->upsertFollowup($quote, $version, $updatedLine, $status, $snapshot, $payload, $actorId);

        if ($emitEvent && $snapshotBefore !== $snapshot) {
            $this->events->log(
                'quote_supplier_status_updated',
                isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
                (int) $quote['id'],
                (int) $version['id'],
                $actorId,
                'Supplierstatus bijgewerkt voor een quote-regel.',
                array(
                    'line_id' => (int) ($updatedLine['id'] ?? 0),
                    'line_number' => (int) ($updatedLine['line_number'] ?? 0),
                    'booking_mode' => BookingModeService::MODE_SUPPLIER_CONFIRMATION,
                    'supplier_status' => $status,
                    'supplier_task_key' => $taskKey,
                    'option_expires_at' => $snapshot['optionExpiresAt'] ?? null,
                )
            );
        }

        return array(
            'changed' => true,
            'line' => $updatedLine,
            'assumption' => $assumption,
            'followup' => $followup,
            'snapshot' => $snapshot,
        );
    }

    /**
     * @param array<string, mixed>|string $snapshot
     * @return array<string, mixed>
     */
    private function normalizeSnapshot($snapshot): array
    {
        if (! is_array($snapshot)) {
            return array();
        }

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $version
     * @param array<string, mixed> $line
     * @param array<string, mixed> $snapshot
     */
    private function resolveTaskKey(array $quote, array $version, array $line, array $snapshot): string
    {
        $existing = trim((string) ($snapshot['supplierTaskKey'] ?? ''));
        if ($existing !== '') {
            return $existing;
        }

        $seed = array(
            (int) ($quote['id'] ?? 0),
            (int) ($version['id'] ?? 0),
            (int) ($line['line_number'] ?? 0),
            (int) ($line['product_id'] ?? 0),
            trim((string) ($line['service_date'] ?? '')),
            trim((string) ($line['start_time'] ?? ($line['proposed_start_time'] ?? ''))),
            trim((string) ($line['title'] ?? '')),
            (int) ($line['participants'] ?? 0),
        );

        return substr(hash('sha256', implode('|', $seed)), 0, 24);
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if (in_array($status, self::SUPPLIER_STATUSES, true)) {
            return $status;
        }

        return self::SUPPLIER_STATUSES[0];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function resolveAvailabilityStatus(array $snapshot, string $status): string
    {
        $existing = trim((string) ($snapshot['availabilityStatus'] ?? ''));
        if ($status === 'supplier_booking_confirmed') {
            return 'available';
        }
        if (in_array($status, array('supplier_declined', 'supplier_unavailable'), true)) {
            return 'unavailable';
        }

        return $existing !== '' ? $existing : 'unknown';
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function resolveAvailabilityCheckedAt(array $snapshot): string
    {
        $existing = trim((string) ($snapshot['availabilityCheckedAt'] ?? ($snapshot['checkedAt'] ?? '')));
        if ($existing !== '') {
            return $existing;
        }

        return function_exists('current_time')
            ? gmdate('c', strtotime((string) current_time('mysql', true)) ?: time())
            : gmdate('c');
    }

    /**
     * @param array<string, mixed> $line
     * @param array<string, mixed> $snapshot
     */
    private function resolveParticipants(array $line, array $snapshot): int
    {
        $participantCount = (int) ($line['participants'] ?? ($snapshot['participants'] ?? 0));
        return max(0, $participantCount);
    }

    /**
     * @param array<string, mixed> $line
     * @param array<string, mixed> $snapshot
     */
    private function resolveDate(array $line, array $snapshot): string
    {
        $date = trim((string) ($line['service_date'] ?? ($snapshot['date'] ?? '')));
        return $date;
    }

    /**
     * @param array<string, mixed> $line
     * @param array<string, mixed> $snapshot
     */
    private function resolveStartTime(array $line, array $snapshot): string
    {
        $startTime = trim((string) ($line['start_time'] ?? ($line['proposed_start_time'] ?? ($snapshot['startTime'] ?? ''))));
        return $startTime;
    }

    /**
     * @param array<string, mixed> $line
     * @param array<string, mixed> $snapshot
     */
    private function resolveEndTime(array $line, array $snapshot): string
    {
        $endTime = trim((string) ($line['end_time'] ?? ($line['proposed_end_time'] ?? ($snapshot['endTime'] ?? ''))));
        return $endTime;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $line
     * @param array<string, mixed> $payload
     */
    private function resolveOptionExpiresAt(array $snapshot, array $line, string $status, array $payload): ?string
    {
        $existing = trim((string) ($snapshot['optionExpiresAt'] ?? ''));
        $optionDays = max(1, (int) ($snapshot['supplierOptionDays'] ?? 3));
        $expiryTimestamp = null;

        $raw = trim((string) ($payload['option_expires_at'] ?? ''));
        if ($raw !== '') {
            $parsed = strtotime($raw);
            if ($parsed !== false) {
                $expiryTimestamp = $parsed;
            }
        }

        if ($status !== 'supplier_option_held') {
            return $existing !== '' ? $existing : null;
        }

        if ($expiryTimestamp === null) {
            $expiryTimestamp = function_exists('current_time')
                ? strtotime((string) current_time('mysql', true)) ?: time()
                : time();
            $expiryTimestamp += ($optionDays * 86400);
        }

        $activityStart = $this->resolveActivityStartTimestamp($line);
        if ($activityStart !== null) {
            $limitTimestamp = $activityStart - (48 * 3600);
            if ($limitTimestamp > 0) {
                $expiryTimestamp = min($expiryTimestamp, $limitTimestamp);
            }
        }

        return gmdate('c', $expiryTimestamp);
    }

    /**
     * @param array<string, mixed> $line
     */
    private function resolveActivityStartTimestamp(array $line): ?int
    {
        $date = trim((string) ($line['service_date'] ?? ''));
        $time = trim((string) ($line['start_time'] ?? ($line['proposed_start_time'] ?? '')));
        if ($date === '' || $time === '') {
            return null;
        }

        $dt = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i',
            $date . ' ' . substr($time, 0, 5),
            new \DateTimeZone('UTC')
        );
        return $dt !== false ? $dt->getTimestamp() : null;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function resolveSupplierBookingReference(array $snapshot, array $payload): ?string
    {
        $raw = trim((string) ($payload['supplier_booking_reference'] ?? ''));
        if ($raw !== '') {
            return $raw;
        }

        $existing = trim((string) ($snapshot['supplierBookingReference'] ?? ''));
        return $existing !== '' ? $existing : null;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function resolveSupplierInternalNote(array $snapshot, array $payload): ?string
    {
        $raw = trim((string) ($payload['internal_note'] ?? ''));
        if ($raw !== '') {
            return $raw;
        }

        $existing = trim((string) ($snapshot['supplierInternalNote'] ?? ''));
        return $existing !== '' ? $existing : null;
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $version
     * @param array<string, mixed> $line
     * @param array<string, mixed> $snapshot
     */
    private function upsertAssumption(array $quote, array $version, array $line, string $status, array $snapshot, array $payload, ?int $actorId): array
    {
        $marker = '[supplier-task:' . (string) ($snapshot['supplierTaskKey'] ?? '') . ']';
        $message = $this->buildAssumptionMessage($marker, $snapshot, $status, $payload);
        $existing = $this->findAssumption((int) $quote['id'], (int) ($line['id'] ?? 0), $marker);

        if ($status === 'supplier_booking_confirmed') {
            if (is_array($existing)) {
                return $this->repository->updateQuoteAssumption((int) $existing['id'], array(
                    'status' => 'resolved',
                    'message' => $message,
                    'resolution_note' => 'Partnerbevestiging ontvangen.',
                    'blocks_review' => 0,
                    'blocks_send' => 0,
                    'blocks_handoff' => 0,
                    'resolved_at' => $this->now(),
                    'resolved_by' => $actorId,
                ));
            }

            return $this->repository->createQuoteAssumption(array(
                'quote_id' => (int) $quote['id'],
                'quote_version_id' => (int) $version['id'],
                'quote_line_id' => (int) ($line['id'] ?? 0),
                'assumption_type' => self::ASSUMPTION_TYPE,
                'severity' => 'warning',
                'visibility' => 'internal',
                'status' => 'resolved',
                'message' => $message,
                'resolution_note' => 'Partnerbevestiging ontvangen.',
                'blocks_review' => 0,
                'blocks_send' => 0,
                'blocks_handoff' => 0,
                'resolved_at' => $this->now(),
                'resolved_by' => $actorId,
                'created_by' => $actorId,
            ));
        }

        if (is_array($existing)) {
            return $this->repository->updateQuoteAssumption((int) $existing['id'], array(
                'quote_line_id' => (int) ($line['id'] ?? 0),
                'status' => 'open',
                'message' => $message,
                'blocks_review' => 1,
                'blocks_send' => 1,
                'blocks_handoff' => 1,
                'resolved_at' => null,
                'resolved_by' => null,
            ));
        }

        return $this->repository->createQuoteAssumption(array(
            'quote_id' => (int) $quote['id'],
            'quote_version_id' => (int) $version['id'],
            'quote_line_id' => (int) ($line['id'] ?? 0),
            'assumption_type' => self::ASSUMPTION_TYPE,
            'severity' => 'warning',
            'visibility' => 'internal',
            'status' => 'open',
            'message' => $message,
            'blocks_review' => 1,
            'blocks_send' => 1,
            'blocks_handoff' => 1,
            'created_by' => $actorId,
        ));
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $version
     * @param array<string, mixed> $line
     * @param array<string, mixed> $snapshot
     */
    private function upsertFollowup(array $quote, array $version, array $line, string $status, array $snapshot, array $payload, ?int $actorId): array
    {
        $marker = '[supplier-task:' . (string) ($snapshot['supplierTaskKey'] ?? '') . ']';
        $title = $this->buildFollowupTitle($snapshot, $status);
        $note = $this->buildFollowupNote($marker, $snapshot, $status, $payload);
        $dueAt = $this->resolveFollowupDueAt($snapshot, $status);
        $existing = $this->findFollowup((int) $quote['id'], $marker);

        if ($status === 'supplier_booking_confirmed') {
            if (is_array($existing)) {
                return $this->repository->updateQuoteFollowup((int) $existing['id'], array(
                    'status' => 'completed',
                    'title' => $title,
                    'note' => $note,
                    'due_at' => $dueAt,
                    'completed_at' => $this->now(),
                    'completed_by' => $actorId,
                ));
            }

            return $this->repository->createQuoteFollowup(array(
                'quote_request_id' => isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
                'quote_id' => (int) $quote['id'],
                'followup_type' => self::FOLLOWUP_TYPE,
                'status' => 'completed',
                'priority' => 'high',
                'title' => $title,
                'note' => $note,
                'due_at' => $dueAt,
                'completed_at' => $this->now(),
                'completed_by' => $actorId,
                'created_by' => $actorId,
            ));
        }

        if (is_array($existing)) {
            return $this->repository->updateQuoteFollowup((int) $existing['id'], array(
                'status' => 'open',
                'title' => $title,
                'note' => $note,
                'due_at' => $dueAt,
            ));
        }

        return $this->repository->createQuoteFollowup(array(
            'quote_request_id' => isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
            'quote_id' => (int) $quote['id'],
            'followup_type' => self::FOLLOWUP_TYPE,
            'status' => 'open',
            'priority' => 'high',
            'title' => $title,
            'note' => $note,
            'due_at' => $dueAt,
            'assigned_user_id' => isset($quote['owner_user_id']) ? (int) $quote['owner_user_id'] : null,
            'created_by' => $actorId,
        ));
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function buildAssumptionMessage(string $marker, array $snapshot, string $status, array $payload): string
    {
        $supplierName = trim((string) ($snapshot['supplierName'] ?? 'Eropuitje'));
        $supplierName = $supplierName !== '' ? $supplierName : 'Eropuitje';
        $expiresAt = trim((string) ($snapshot['optionExpiresAt'] ?? ''));
        $suffix = match ($status) {
            'supplier_option_requested' => sprintf('Optie aangevraagd bij %s.', $supplierName),
            'supplier_option_held' => sprintf('Optie bevestigd tot %s.', $expiresAt !== '' ? $expiresAt : 'n.v.t.'),
            'supplier_declined' => sprintf('Partner heeft de aanvraag geweigerd voor %s.', $supplierName),
            'supplier_booking_confirmed' => sprintf('Partnerbevestiging ontvangen van %s.', $supplierName),
            'supplier_unavailable' => sprintf('Partner geeft aan dat er geen capaciteit is bij %s.', $supplierName),
            default => sprintf('Partnerbevestiging vereist voor %s.', $supplierName),
        };

        $note = trim((string) ($payload['internal_note'] ?? ''));
        if ($note !== '') {
            $suffix .= ' Notitie: ' . $note;
        }

        return $marker . ' ' . $suffix;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function buildFollowupTitle(array $snapshot, string $status): string
    {
        $supplierName = trim((string) ($snapshot['supplierName'] ?? 'Eropuitje'));
        $supplierName = $supplierName !== '' ? $supplierName : 'Eropuitje';

        return match ($status) {
            'supplier_option_requested' => sprintf('Optie aanvragen bij %s', $supplierName),
            'supplier_option_held' => sprintf('Optie bewaken bij %s', $supplierName),
            'supplier_declined' => sprintf('Alternatief regelen voor %s', $supplierName),
            'supplier_booking_confirmed' => sprintf('Bevestiging verwerken voor %s', $supplierName),
            'supplier_unavailable' => sprintf('Beschikbaarheid herplannen voor %s', $supplierName),
            default => sprintf('Vraag bevestiging aan bij %s', $supplierName),
        };
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function buildFollowupNote(string $marker, array $snapshot, string $status, array $payload): string
    {
        $parts = array(
            $marker,
            'bookingMode=' . (string) ($snapshot['bookingMode'] ?? BookingModeService::MODE_SUPPLIER_CONFIRMATION),
            'supplierStatus=' . $status,
            'supplierName=' . (string) ($snapshot['supplierName'] ?? 'Eropuitje'),
        );

        if (! empty($snapshot['availabilityStatus'])) {
            $parts[] = 'availabilityStatus=' . (string) $snapshot['availabilityStatus'];
        }
        if (! empty($snapshot['availabilityCheckedAt'])) {
            $parts[] = 'availabilityCheckedAt=' . (string) $snapshot['availabilityCheckedAt'];
        }
        if (! empty($snapshot['participants'])) {
            $parts[] = 'participants=' . (string) (int) $snapshot['participants'];
        }
        if (! empty($snapshot['date'])) {
            $parts[] = 'date=' . (string) $snapshot['date'];
        }
        if (! empty($snapshot['startTime'])) {
            $parts[] = 'startTime=' . (string) $snapshot['startTime'];
        }
        if (! empty($snapshot['endTime'])) {
            $parts[] = 'endTime=' . (string) $snapshot['endTime'];
        }
        if (! empty($snapshot['optionExpiresAt'])) {
            $parts[] = 'optionExpiresAt=' . (string) $snapshot['optionExpiresAt'];
        }

        $internalNote = trim((string) ($payload['internal_note'] ?? ($snapshot['supplierInternalNote'] ?? '')));
        if ($internalNote !== '') {
            $parts[] = 'note=' . $internalNote;
        }

        return implode("\n", $parts);
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function resolveFollowupDueAt(array $snapshot, string $status): string
    {
        if ($status === 'supplier_option_held' && ! empty($snapshot['optionExpiresAt'])) {
            $timestamp = strtotime((string) $snapshot['optionExpiresAt']);
            if ($timestamp !== false) {
                return gmdate('Y-m-d H:i:s', $timestamp);
            }
        }

        if (function_exists('current_time')) {
            $now = strtotime((string) current_time('mysql', true));
            if ($now !== false) {
                return gmdate('Y-m-d H:i:s', $now + 86400);
            }
        }

        return gmdate('Y-m-d H:i:s', time() + 86400);
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $line
     */
    private function findAssumption(int $quoteId, int $lineId, string $marker): ?array
    {
        foreach ($this->repository->listQuoteAssumptions($quoteId) as $assumption) {
            if ((int) ($assumption['quote_line_id'] ?? 0) === $lineId
                && (string) ($assumption['assumption_type'] ?? '') === self::ASSUMPTION_TYPE
                && (string) ($assumption['status'] ?? 'open') === 'open') {
                return $assumption;
            }

            if ((string) ($assumption['assumption_type'] ?? '') !== self::ASSUMPTION_TYPE) {
                continue;
            }

            if (str_starts_with((string) ($assumption['message'] ?? ''), $marker)) {
                return $assumption;
            }
        }

        return null;
    }

    private function findFollowup(int $quoteId, string $marker): ?array
    {
        foreach ($this->repository->listQuoteFollowups($quoteId) as $followup) {
            if ((string) ($followup['followup_type'] ?? '') !== self::FOLLOWUP_TYPE) {
                continue;
            }

            if (str_contains((string) ($followup['note'] ?? ''), $marker)) {
                return $followup;
            }
        }

        return null;
    }

    /**
     * Generate (or update) a supplier request draft message for a specific quote line.
     *
     * @return array{message:array<string,mixed>,status_changed:bool,supplier_email_missing:bool}
     */
    public function generateSupplierRequestDraft(int $quoteId, int $lineId, ?int $actorId = null): array
    {
        $quote = $this->repository->findQuote($quoteId);
        if ($quote === null) {
            throw new \InvalidArgumentException('Quote not found.');
        }

        $versionId = (int) ($quote['current_version_id'] ?? 0);
        if ($versionId <= 0) {
            throw new \InvalidArgumentException('Quote heeft geen actieve versie.');
        }

        $version = $this->repository->findQuoteVersion($versionId);
        if ($version === null || (int) ($version['quote_id'] ?? 0) !== $quoteId) {
            throw new \InvalidArgumentException('Actieve quote-versie niet gevonden.');
        }

        $line = $this->repository->findQuoteLine($lineId);
        if ($line === null || (int) ($line['quote_version_id'] ?? 0) !== $versionId) {
            throw new \InvalidArgumentException('Programmaregel niet gevonden in de actieve quote-versie.');
        }

        $productId = (int) ($line['product_id'] ?? 0);
        $bookingMode = $this->resolveBookingMode($productId);
        if (($bookingMode['bookingMode'] ?? '') !== BookingModeService::MODE_SUPPLIER_CONFIRMATION) {
            throw new \InvalidArgumentException('Deze regel vereist geen partnerbevestiging.');
        }

        $supplierEmail = trim((string) ($bookingMode['supplierEmail'] ?? ''));
        $supplierEmailMissing = $supplierEmail === '';
        $supplierName = trim((string) ($bookingMode['supplierName'] ?? ''));
        $supplierName = $supplierName !== '' ? $supplierName : 'Eropuitje';

        $snapshot = $this->normalizeSnapshot($line['availability_snapshot_json'] ?? array());
        $currentStatus = $this->normalizeStatus((string) ($snapshot['supplierStatus'] ?? ''));

        $quoteReference = trim((string) ($quote['quote_reference'] ?? ''));
        $threadToken = $quoteReference !== ''
            ? $quoteReference . '-supplier-line-' . $lineId
            : 'quote-' . $quoteId . '-supplier-line-' . $lineId;

        $title = trim((string) ($line['title'] ?? ''));
        $date = trim((string) ($line['service_date'] ?? ($snapshot['date'] ?? '')));
        $startTime = trim((string) ($line['start_time'] ?? ($line['proposed_start_time'] ?? ($snapshot['startTime'] ?? ''))));
        $participants = max(0, (int) ($line['participants'] ?? ($snapshot['participants'] ?? 0)));
        $availabilityStatus = trim((string) ($snapshot['availabilityStatus'] ?? ''));
        $availabilityCheckedAt = trim((string) ($snapshot['availabilityCheckedAt'] ?? ''));

        $subject = sprintf(
            'Optie-/bevestigingsverzoek DagjeDenBosch \u2013 %s \u2013 %s \u2013 %d personen',
            $title !== '' ? $title : $supplierName,
            $date !== '' ? $date : 'datum n.b.',
            $participants
        );

        $bodyLines = array(
            sprintf('Beste %s,', $supplierName),
            '',
            'Wij ontvingen een offerteverzoek voor de onderstaande activiteit.',
            'Zou u vriendelijk een optie of bevestiging kunnen doorgeven?',
            '',
            'Activiteit : ' . ($title !== '' ? $title : $supplierName),
            'Datum      : ' . ($date !== '' ? $date : 'n.b.'),
            'Starttijd  : ' . ($startTime !== '' ? $startTime : 'n.b.'),
            'Personen   : ' . ($participants > 0 ? $participants : 'n.b.'),
            'Status     : ' . ($availabilityStatus !== '' ? $availabilityStatus : 'n.b.'),
        );
        if ($availabilityCheckedAt !== '') {
            $bodyLines[] = 'Gecheckt op: ' . $availabilityCheckedAt;
        }
        $bodyLines[] = 'Referentie : ' . ($quoteReference !== '' ? $quoteReference : 'n.b.');
        $bodyLines[] = '';
        $bodyLines[] = 'Met vriendelijke groet,';
        $bodyLines[] = 'DagjeDenBosch';
        $body = implode("\n", $bodyLines);

        $existingDraft = $this->findLatestSupplierDraft($quoteId, $threadToken);
        $messagePayload = array(
            'quote_version_id' => $versionId,
            'direction'        => 'outbound',
            'message_type'     => 'supplier_confirmation_request',
            'channel'          => 'email',
            'status'           => 'draft',
            'subject'          => $subject,
            'body'             => $body,
            'to_name'          => $supplierName,
            'to_email'         => $supplierEmail,
            'thread_token'     => $threadToken,
            'created_by'       => $actorId,
        );

        if ($existingDraft !== null) {
            $message = $this->repository->updateQuoteMessage(
                (int) $existingDraft['id'],
                $messagePayload
            );
        } else {
            $message = $this->repository->createQuoteMessage(
                array_merge(array('quote_id' => $quoteId), $messagePayload)
            );
        }

        // Status transition: only supplier_confirmation_required → supplier_option_requested.
        // Higher statuses (supplier_option_held, supplier_booking_confirmed, etc.) are never downgraded.
        $statusChanged = false;
        if ($currentStatus === 'supplier_confirmation_required') {
            $this->updateStatus($quoteId, $lineId, 'supplier_option_requested', array(), $actorId);
            $statusChanged = true;
        }

        $this->events->log(
            'quote_supplier_request_draft_generated',
            isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
            $quoteId,
            $versionId,
            $actorId,
            'Partnerverzoek draft aangemaakt.',
            array(
                'line_id'               => $lineId,
                'message_id'            => (int) ($message['id'] ?? 0),
                'thread_token'          => $threadToken,
                'supplier_email_missing' => $supplierEmailMissing,
                'status_changed'        => $statusChanged,
            )
        );

        return array(
            'message'               => $message,
            'status_changed'        => $statusChanged,
            'supplier_email_missing' => $supplierEmailMissing,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findLatestSupplierDraft(int $quoteId, string $threadToken): ?array
    {
        $messages = $this->repository->listQuoteMessages($quoteId);
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $msg = $messages[$i];
            if ((string) ($msg['message_type'] ?? '') !== 'supplier_confirmation_request') {
                continue;
            }
            if ((string) ($msg['status'] ?? '') !== 'draft') {
                continue;
            }
            if ((string) ($msg['thread_token'] ?? '') !== $threadToken) {
                continue;
            }
            return $msg;
        }
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveBookingMode(int $productId): array
    {
        if ($this->bookingModeService instanceof BookingModeService) {
            return $this->bookingModeService->resolve($productId);
        }

        $this->bookingModeService = new BookingModeService();
        return $this->bookingModeService->resolve($productId);
    }

    private function refreshVersionConfidence(int $versionId): void
    {
        $lines = $this->repository->listQuoteLines($versionId);
        $this->repository->updateQuoteVersion($versionId, array(
            'pricing_confidence' => $this->resolveVersionConfidence($lines, 'pricing_confidence'),
            'availability_confidence' => $this->resolveVersionConfidence($lines, 'availability_confidence'),
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     */
    private function resolveVersionConfidence(array $lines, string $field): string
    {
        $rank = $field === 'availability_confidence'
            ? array('unknown' => 0, 'projected' => 1, 'snapshot' => 2, 'confirmed' => 3)
            : array('unknown' => 0, 'directional' => 1, 'snapshot' => 2, 'execution_verified' => 3);

        $lowest = null;
        foreach ($lines as $line) {
            $value = (string) ($line[$field] ?? 'unknown');
            if (! isset($rank[$value])) {
                $value = 'unknown';
            }
            if ($lowest === null || $rank[$value] < $rank[$lowest]) {
                $lowest = $value;
            }
        }

        return $lowest ?? 'unknown';
    }

    private function now(): string
    {
        return function_exists('current_time')
            ? (string) current_time('mysql', true)
            : gmdate('Y-m-d H:i:s');
    }
}
