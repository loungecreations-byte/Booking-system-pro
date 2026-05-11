<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Quotes\Repository\QuoteRepositoryInterface;
use InvalidArgumentException;

final class QuoteOperationsDraftService
{
    public function __construct(
        private QuoteRepositoryInterface $repository,
        private QuoteEventLogger $events
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveDraft(int $quoteId, array $payload, ?int $actorId = null): array
    {
        $quote = $this->repository->findQuote($quoteId);
        if ($quote === null) {
            throw new InvalidArgumentException('Quote not found.');
        }

        // Block TERMINAL statuses — no revisions allowed once a quote is fully closed.
        // 'sent' is allowed here: saveDraft will clone into a new draft revision below.
        (new QuoteImmutabilityGuard($this->repository))->assertQuoteAcceptsRevision($quoteId);

        $currentVersionId = (int) ($quote['current_version_id'] ?? 0);
        if ($currentVersionId <= 0) {
            throw new InvalidArgumentException('Quote heeft geen actieve versie.');
        }

        $currentVersion = $this->repository->findQuoteVersion($currentVersionId);
        if ($currentVersion === null) {
            throw new InvalidArgumentException('Actieve quote-versie niet gevonden.');
        }

        $existingLines = $this->repository->listQuoteLines($currentVersionId);
        $normalizedLines = $this->normalizeDraftLines($payload['lines'] ?? array(), $existingLines);
        if ($normalizedLines === array()) {
            throw new InvalidArgumentException('Voeg minimaal één programmaregel toe voordat je de draft opslaat.');
        }
        $versionPricingSnapshot = $this->buildVersionPricingSnapshot($payload['commercial_adjustments'] ?? null, $currentVersion);

        $forceNewVersion = ! empty($payload['create_new_version']);
        $mustClone = $forceNewVersion || $this->isFrozenForEditing($quote, $currentVersion);

        $targetVersion = $currentVersion;
        if ($mustClone) {
            $targetVersion = $this->createDraftVersion($quote, $currentVersion, $normalizedLines, $versionPricingSnapshot, $actorId);
            $quote = $this->repository->updateQuote((int) $quote['id'], array(
                'current_version_id' => (int) $targetVersion['id'],
                'status' => 'draft',
                'review_status' => 'not_started',
                'send_status' => 'not_ready',
                'handoff_status' => 'not_ready',
            ));
        } else {
            // Belt-and-suspenders: assert version is not the approved/pinned version
            // even though isFrozenForEditing() already blocks that path.
            (new QuoteImmutabilityGuard($this->repository))->assertVersionCommerciallyEditable($quoteId, $currentVersionId);
            $targetVersion = $this->repository->updateQuoteVersion((int) $currentVersion['id'], array(
                'snapshot_type' => 'operator_build',
                'pricing_confidence' => $this->resolveVersionConfidence($normalizedLines, 'pricing_confidence'),
                'availability_confidence' => $this->resolveVersionConfidence($normalizedLines, 'availability_confidence'),
                'pricing_snapshot_json' => $versionPricingSnapshot,
            ));
        }

        $savedLines = $this->repository->replaceQuoteLines((int) $targetVersion['id'], $normalizedLines);

        $this->events->log(
            'quote_operations_draft_saved',
            isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
            $quoteId,
            (int) $targetVersion['id'],
            $actorId,
            $mustClone ? 'Nieuwe quote-draftversie opgeslagen vanuit operations builder.' : 'Quote-draft bijgewerkt in operations builder.',
            array(
                'created_new_version' => $mustClone,
                'line_count' => count($savedLines),
            )
        );

        return array(
            'quote' => $quote,
            'version' => $targetVersion,
            'lines' => $savedLines,
            'created_new_version' => $mustClone,
        );
    }

    /**
     * @param mixed $rawLines
     * @param array<int, array<string, mixed>> $existingLines
     * @return array<int, array<string, mixed>>
     */
    private function normalizeDraftLines($rawLines, array $existingLines): array
    {
        if (! is_array($rawLines)) {
            return array();
        }

        $existingByLineNumber = array();
        foreach ($existingLines as $line) {
            $existingByLineNumber[(int) ($line['line_number'] ?? 0)] = $line;
        }

        $normalized = array();
        foreach ($rawLines as $index => $rawLine) {
            if (! is_array($rawLine) || ! empty($rawLine['remove'])) {
                continue;
            }

            $existing = $existingByLineNumber[(int) ($rawLine['source_line_number'] ?? 0)] ?? array();
            $sortOrder = max(1, (int) ($rawLine['sort_order'] ?? ($index + 1)));
            $productId = $this->normalizeInt($rawLine['product_id'] ?? ($existing['product_id'] ?? null));
            $title = trim((string) ($rawLine['title'] ?? ($existing['title'] ?? '')));
            if ($title === '') {
                $title = sprintf('Programmaregel %d', $sortOrder);
            }

            $proposedStartTime = $this->normalizeTime($rawLine['proposed_start_time'] ?? ($rawLine['start_time'] ?? ($existing['proposed_start_time'] ?? ($existing['start_time'] ?? null))));
            $proposedEndTime = $this->normalizeTime($rawLine['proposed_end_time'] ?? ($rawLine['end_time'] ?? ($existing['proposed_end_time'] ?? ($existing['end_time'] ?? null))));
            $durationMinutes = $this->normalizeDuration(
                $rawLine['duration_minutes'] ?? ($existing['duration_minutes'] ?? null),
                $proposedStartTime,
                $proposedEndTime
            );

            $normalized[] = array(
                'line_number' => $sortOrder,
                'sort_order' => $sortOrder,
                'line_type' => (string) ($rawLine['line_type'] ?? ($existing['line_type'] ?? 'product')),
                'line_status' => $productId !== null ? 'mapped' : (string) ($rawLine['line_status'] ?? ($existing['line_status'] ?? 'directional')),
                'title' => $title,
                'product_id' => $productId,
                'vendor_id' => $this->normalizeInt($rawLine['vendor_id'] ?? ($existing['vendor_id'] ?? null)),
                'resource_id' => $this->normalizeInt($rawLine['resource_id'] ?? ($existing['resource_id'] ?? null)),
                'quantity' => max(1, (int) ($rawLine['quantity'] ?? ($existing['quantity'] ?? 1))),
                'participants' => max(0, (int) ($rawLine['participants'] ?? ($existing['participants'] ?? 0))),
                'service_date' => $this->normalizeDate($rawLine['service_date'] ?? ($existing['service_date'] ?? null)),
                'proposed_start_time' => $proposedStartTime,
                'proposed_end_time' => $proposedEndTime,
                'start_time' => $proposedStartTime,
                'end_time' => $proposedEndTime,
                'duration_minutes' => $durationMinutes,
                'pricing_mode' => (string) ($rawLine['pricing_mode'] ?? ($existing['pricing_mode'] ?? 'directional')),
                'pricing_confidence' => $this->normalizeConfidence(
                    $rawLine['pricing_confidence'] ?? ($existing['pricing_confidence'] ?? ($productId !== null ? 'snapshot' : 'unknown')),
                    array('unknown', 'directional', 'snapshot', 'execution_verified')
                ),
                'availability_confidence' => $this->normalizeConfidence(
                    $rawLine['availability_confidence'] ?? ($existing['availability_confidence'] ?? ($productId !== null ? 'projected' : 'unknown')),
                    array('unknown', 'projected', 'snapshot', 'confirmed')
                ),
                'unit_amount_snapshot' => $this->normalizeFloat($rawLine['unit_amount_snapshot'] ?? ($existing['unit_amount_snapshot'] ?? null)),
                'line_total_snapshot' => $this->normalizeFloat($rawLine['line_total_snapshot'] ?? ($existing['line_total_snapshot'] ?? null)),
                'currency' => $this->normalizeCurrency($rawLine['currency'] ?? ($existing['currency'] ?? 'EUR')),
                'tax_class' => $this->normalizeString($rawLine['tax_class'] ?? ($existing['tax_class'] ?? null)),
                'pricing_snapshot_json' => $this->normalizeArrayField($rawLine['pricing_snapshot_json'] ?? ($existing['pricing_snapshot_json'] ?? array())),
                'availability_snapshot_json' => $this->normalizeArrayField($rawLine['availability_snapshot_json'] ?? ($existing['availability_snapshot_json'] ?? array())),
                'selected_option_labels_json' => $this->normalizeOptionLabels($rawLine['selected_option_labels'] ?? ($existing['selected_option_labels_json'] ?? array())),
                'validated_slot_label' => $this->normalizeString($rawLine['validated_slot_label'] ?? ($existing['validated_slot_label'] ?? null)),
                'mapping_notes' => $this->normalizeString($rawLine['mapping_notes'] ?? ($existing['mapping_notes'] ?? null)),
                'external_label' => $this->normalizeString($rawLine['external_label'] ?? ($existing['external_label'] ?? null)),
                'is_optional' => ! empty($rawLine['is_optional']) || ! empty($existing['is_optional']) ? 1 : 0,
                'position_group' => $this->normalizeString($rawLine['position_group'] ?? ($existing['position_group'] ?? null)),
            );
        }

        usort($normalized, static function (array $left, array $right): int {
            return ((int) ($left['sort_order'] ?? 0)) <=> ((int) ($right['sort_order'] ?? 0));
        });

        foreach ($normalized as $index => $line) {
            $normalized[$index]['line_number'] = $index + 1;
            $normalized[$index]['sort_order'] = $index + 1;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $currentVersion
     * @param array<int, array<string, mixed>> $lines
     * @param array<int|string, mixed> $versionPricingSnapshot
     * @return array<string, mixed>
     */
    private function createDraftVersion(array $quote, array $currentVersion, array $lines, array $versionPricingSnapshot, ?int $actorId): array
    {
        $versions = $this->repository->listQuoteVersions((int) $quote['id']);
        $lastVersionNumber = 0;
        foreach ($versions as $version) {
            $lastVersionNumber = max($lastVersionNumber, (int) ($version['version_number'] ?? 0));
        }

        return $this->repository->createQuoteVersion(array(
            'quote_id' => (int) $quote['id'],
            'version_number' => $lastVersionNumber + 1,
            'status' => 'draft',
            'proposal_title' => (string) ($currentVersion['proposal_title'] ?? ''),
            'proposal_summary' => (string) ($currentVersion['proposal_summary'] ?? ''),
            'snapshot_type' => 'operator_build',
            'pricing_confidence' => $this->resolveVersionConfidence($lines, 'pricing_confidence'),
            'availability_confidence' => $this->resolveVersionConfidence($lines, 'availability_confidence'),
            'proposal_direction_a_json' => $currentVersion['proposal_direction_a_json'] ?? array(),
            'proposal_direction_b_json' => $currentVersion['proposal_direction_b_json'] ?? array(),
            'premium_upsell_json' => $currentVersion['premium_upsell_json'] ?? array(),
            'pricing_snapshot_json' => $versionPricingSnapshot,
            'availability_snapshot_json' => array(),
            'missing_info_json' => $currentVersion['missing_info_json'] ?? array(),
            'render_payload_json' => array(),
            'review_notes' => '',
            'supersedes_version_id' => (int) ($currentVersion['id'] ?? 0),
            'created_by' => $actorId,
        ));
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $version
     */
    private function isFrozenForEditing(array $quote, array $version): bool
    {
        if ((string) ($quote['review_status'] ?? 'not_started') === 'pending_review') {
            return true;
        }
        if ((string) ($quote['review_status'] ?? 'not_started') === 'approved') {
            return true;
        }
        if ((string) ($quote['send_status'] ?? 'not_ready') !== 'not_ready') {
            return true;
        }
        if ((int) ($quote['approved_version_id'] ?? 0) === (int) ($version['id'] ?? 0)) {
            return true;
        }

        foreach ($this->repository->listQuoteMessages((int) ($quote['id'] ?? 0)) as $message) {
            if ((int) ($message['quote_version_id'] ?? 0) !== (int) ($version['id'] ?? 0)) {
                continue;
            }
            if ((string) ($message['direction'] ?? '') !== 'outbound') {
                continue;
            }
            if ((string) ($message['status'] ?? '') === 'sent') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $rawAdjustments
     * @param array<string, mixed> $currentVersion
     * @return array<int|string, mixed>
     */
    private function buildVersionPricingSnapshot($rawAdjustments, array $currentVersion): array
    {
        $snapshot = is_array($currentVersion['pricing_snapshot_json'] ?? null)
            ? $currentVersion['pricing_snapshot_json']
            : array();

        if ($rawAdjustments !== null) {
            $snapshot['commercial_adjustments'] = $this->normalizeCommercialAdjustments(
                $rawAdjustments,
                is_array($snapshot['commercial_adjustments'] ?? null) ? $snapshot['commercial_adjustments'] : array()
            );
        }

        return $snapshot;
    }

    /**
     * @param mixed $rawAdjustments
     * @param array<int|string, mixed> $existing
     * @return array<string, mixed>
     */
    private function normalizeCommercialAdjustments($rawAdjustments, array $existing): array
    {
        $raw = is_array($rawAdjustments) ? $rawAdjustments : array();
        $discountAmount = $this->normalizeFloat($raw['discount_amount'] ?? ($existing['discount_amount'] ?? 0.0));
        $discountLabel = $this->normalizeString($raw['discount_label'] ?? ($existing['discount_label'] ?? 'Korting')) ?? 'Korting';

        return array(
            'type' => 'fixed_amount',
            'discount_amount' => round(max(0.0, $discountAmount ?? 0.0), 2),
            'discount_label' => $discountLabel,
            'currency' => $this->normalizeCurrency($raw['currency'] ?? ($existing['currency'] ?? 'EUR')),
        );
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

    private function normalizeInt($value): ?int
    {
        $int = (int) $value;
        return $int > 0 ? $int : null;
    }

    private function normalizeFloat($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function normalizeDate($value): ?string
    {
        $date = trim((string) $value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null;
    }

    private function normalizeTime($value): ?string
    {
        $time = trim((string) $value);
        if ($time === '') {
            return null;
        }

        return preg_match('/^\d{2}:\d{2}$/', $time) === 1 ? $time : null;
    }

    private function normalizeDuration($value, ?string $startTime, ?string $endTime): ?int
    {
        $duration = (int) $value;
        if ($duration > 0) {
            return $duration;
        }

        if ($startTime === null || $endTime === null) {
            return null;
        }

        if (! preg_match('/^(\d{2}):(\d{2})$/', $startTime, $startMatches) || ! preg_match('/^(\d{2}):(\d{2})$/', $endTime, $endMatches)) {
            return null;
        }

        $startMinutes = (((int) $startMatches[1]) * 60) + (int) $startMatches[2];
        $endMinutes = (((int) $endMatches[1]) * 60) + (int) $endMatches[2];

        return $endMinutes > $startMinutes ? $endMinutes - $startMinutes : null;
    }

    /**
     * @param mixed $value
     * @param array<int, string> $allowed
     */
    private function normalizeConfidence($value, array $allowed): string
    {
        $candidate = trim((string) $value);
        return in_array($candidate, $allowed, true) ? $candidate : 'unknown';
    }

    private function normalizeCurrency($value): string
    {
        $currency = strtoupper(trim((string) $value));
        return $currency !== '' ? $currency : 'EUR';
    }

    private function normalizeString($value): ?string
    {
        $string = trim((string) $value);
        return $string !== '' ? $string : null;
    }

    /**
     * @param mixed $value
     * @return array<int|string, mixed>
     */
    private function normalizeArrayField($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return array();
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeOptionLabels($value): array
    {
        $labels = array();

        if (is_array($value)) {
            foreach ($value as $candidate) {
                $label = trim((string) $candidate);
                if ($label !== '') {
                    $labels[] = $label;
                }
            }
        } else {
            $raw = preg_split('/[\r\n,]+/', (string) $value) ?: array();
            foreach ($raw as $candidate) {
                $label = trim((string) $candidate);
                if ($label !== '') {
                    $labels[] = $label;
                }
            }
        }

        return array_values(array_unique($labels));
    }
}
