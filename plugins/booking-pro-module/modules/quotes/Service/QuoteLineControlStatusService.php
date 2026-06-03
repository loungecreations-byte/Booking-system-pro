<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Quotes\Repository\QuoteRepositoryInterface;
use InvalidArgumentException;

final class QuoteLineControlStatusService
{
    private const PRICING_STATUSES = array('needs_check', 'confirmed', 'under_reservation');
    private const AVAILABILITY_STATUSES = array('needs_check', 'confirmed', 'under_reservation', 'unavailable');

    public function __construct(
        private QuoteRepositoryInterface $repository,
        private QuoteEventLogger $events
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function updateStatus(int $quoteId, int $lineId, string $dimension, string $status, ?int $actorId = null): array
    {
        $dimension = $this->normalizeDimension($dimension);
        $this->assertAllowedStatus($dimension, $status);

        $quote = $this->repository->findQuote($quoteId);
        if ($quote === null) {
            throw new InvalidArgumentException('Quote niet gevonden.');
        }

        $versionId = (int) ($quote['current_version_id'] ?? 0);
        if ($versionId <= 0) {
            throw new InvalidArgumentException('Quote heeft geen actieve versie.');
        }

        (new QuoteImmutabilityGuard($this->repository))->assertVersionCommerciallyEditable($quoteId, $versionId);

        $version = $this->repository->findQuoteVersion($versionId);
        if ($version === null || (int) ($version['quote_id'] ?? 0) !== $quoteId) {
            throw new InvalidArgumentException('Actieve quote-versie niet gevonden.');
        }

        $line = $this->repository->findQuoteLine($lineId);
        if ($line === null || (int) ($line['quote_version_id'] ?? 0) !== $versionId) {
            throw new InvalidArgumentException('Programmaregel niet gevonden in de actieve quote-versie.');
        }
        if ($dimension === 'availability' && $status === 'confirmed' && $this->requiresSupplierConfirmation($line)) {
            throw new InvalidArgumentException('Supplierregels kunnen alleen beschikbaar worden gezet na supplier_booking_confirmed.');
        }

        $oldStatus = $this->resolveControlStatus($line, $dimension);
        $oldConfidence = (string) ($line[$dimension === 'pricing' ? 'pricing_confidence' : 'availability_confidence'] ?? 'unknown');
        if ($oldStatus === $status) {
            return array(
                'quote' => $quote,
                'version' => $version,
                'line' => $line,
            );
        }

        $changes = $this->buildLineChanges($line, $dimension, $status, $actorId);
        $updatedLine = $this->repository->updateQuoteLine($lineId, $changes);

        $lines = $this->repository->listQuoteLines($versionId);
        $this->repository->updateQuoteVersion($versionId, array(
            'pricing_confidence' => $this->resolveVersionConfidence($lines, 'pricing_confidence'),
            'availability_confidence' => $this->resolveVersionConfidence($lines, 'availability_confidence'),
        ));

        $newConfidence = (string) ($updatedLine[$dimension === 'pricing' ? 'pricing_confidence' : 'availability_confidence'] ?? 'unknown');
        $changedAt = function_exists('current_time') ? (string) current_time('mysql', true) : gmdate('Y-m-d H:i:s');
        $this->events->log(
            $dimension === 'availability' ? 'quote_line_availability_updated' : 'quote_program_line_updated',
            isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
            $quoteId,
            $versionId,
            $actorId,
            $dimension === 'pricing' ? 'Prijsstatus programmaregel bijgewerkt.' : 'Beschikbaarheidsstatus programmaregel bijgewerkt.',
            array(
                'quote_id' => $quoteId,
                'quote_version_id' => $versionId,
                'line_id' => $lineId,
                'dimension' => $dimension,
                'old_status' => $oldStatus,
                'new_status' => $status,
                'old_confidence' => $oldConfidence,
                'new_confidence' => $newConfidence,
                'admin_user_id' => $actorId,
                'changed_at' => $changedAt,
            )
        );

        return array(
            'quote' => $quote,
            'version' => $this->repository->findQuoteVersion($versionId),
            'line' => $updatedLine,
        );
    }

    private function normalizeDimension(string $dimension): string
    {
        $dimension = strtolower(trim($dimension));
        if ($dimension !== 'pricing' && $dimension !== 'availability') {
            throw new InvalidArgumentException('Onbekende controlestatus.');
        }

        return $dimension;
    }

    private function assertAllowedStatus(string $dimension, string $status): void
    {
        $allowed = $dimension === 'pricing' ? self::PRICING_STATUSES : self::AVAILABILITY_STATUSES;
        if (! in_array($status, $allowed, true)) {
            throw new InvalidArgumentException('Deze status is niet toegestaan voor deze programmaregel.');
        }
    }

    /**
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    private function buildLineChanges(array $line, string $dimension, string $status, ?int $actorId): array
    {
        $changedAt = function_exists('current_time') ? (string) current_time('mysql', true) : gmdate('Y-m-d H:i:s');
        $snapshotField = $dimension === 'pricing' ? 'pricing_snapshot_json' : 'availability_snapshot_json';
        $snapshot = is_array($line[$snapshotField] ?? null) ? $line[$snapshotField] : array();
        $snapshot['control_status'] = $status;
        $snapshot['control_updated_at'] = $changedAt;
        $snapshot['control_updated_by'] = $actorId;

        if ($dimension === 'pricing') {
            return array(
                'pricing_confidence' => $this->pricingConfidenceForStatus($status),
                'pricing_snapshot_json' => $snapshot,
            );
        }

        $changes = array(
            'availability_confidence' => $this->availabilityConfidenceForStatus($status),
            'availability_snapshot_json' => $snapshot,
        );
        if ($status === 'unavailable') {
            $changes['line_status'] = 'unavailable';
        } elseif ((string) ($line['line_status'] ?? '') === 'unavailable') {
            $changes['line_status'] = (int) ($line['product_id'] ?? 0) > 0 ? 'mapped' : 'directional';
        }

        return $changes;
    }

    /**
     * @param array<string, mixed> $line
     */
    private function resolveControlStatus(array $line, string $dimension): string
    {
        $snapshotField = $dimension === 'pricing' ? 'pricing_snapshot_json' : 'availability_snapshot_json';
        $snapshot = is_array($line[$snapshotField] ?? null) ? $line[$snapshotField] : array();
        $status = (string) ($snapshot['control_status'] ?? '');
        $allowed = $dimension === 'pricing' ? self::PRICING_STATUSES : self::AVAILABILITY_STATUSES;
        if (in_array($status, $allowed, true)) {
            return $status;
        }

        if ($dimension === 'pricing') {
            return (string) ($line['pricing_confidence'] ?? 'unknown') === 'execution_verified'
                ? 'confirmed'
                : (((string) ($line['pricing_confidence'] ?? 'unknown') === 'snapshot') ? 'under_reservation' : 'needs_check');
        }

        if ((string) ($line['line_status'] ?? '') === 'unavailable') {
            return 'unavailable';
        }

        return (string) ($line['availability_confidence'] ?? 'unknown') === 'confirmed'
            ? 'confirmed'
            : (in_array((string) ($line['availability_confidence'] ?? 'unknown'), array('snapshot', 'projected'), true) ? 'under_reservation' : 'needs_check');
    }

    private function pricingConfidenceForStatus(string $status): string
    {
        return match ($status) {
            'confirmed' => 'execution_verified',
            'under_reservation' => 'snapshot',
            default => 'unknown',
        };
    }

    private function availabilityConfidenceForStatus(string $status): string
    {
        return match ($status) {
            'confirmed' => 'confirmed',
            'under_reservation' => 'projected',
            default => 'unknown',
        };
    }

    /**
     * @param array<string, mixed> $line
     */
    private function requiresSupplierConfirmation(array $line): bool
    {
        $productId = (int) ($line['product_id'] ?? 0);
        $snapshot = is_array($line['availability_snapshot_json'] ?? null) ? $line['availability_snapshot_json'] : array();
        $bookingMode = strtolower(trim((string) ($snapshot['bookingMode'] ?? $snapshot['booking_mode'] ?? '')));
        $supplierProvider = strtolower(trim((string) ($snapshot['supplierProvider'] ?? $snapshot['provider'] ?? '')));
        $supplierStatus = strtolower(trim((string) ($snapshot['supplierStatus'] ?? $snapshot['supplier_status'] ?? '')));

        if ($supplierStatus === 'supplier_booking_confirmed') {
            return false;
        }
        if ($productId === 115 || $bookingMode === 'supplier_confirmation' || $supplierProvider === 'eliio') {
            return true;
        }
        if (in_array($supplierStatus, array('supplier_confirmation_required', 'supplier_option_requested'), true)) {
            return true;
        }
        if (\function_exists('get_post_meta') && $productId > 0) {
            $provider = strtolower(trim((string) \get_post_meta($productId, '_ddb_supplier_provider', true)));
            $required = strtolower(trim((string) \get_post_meta($productId, '_ddb_supplier_confirmation_required', true)));
            return $provider === 'eliio' || $required === 'yes';
        }

        return false;
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
}
