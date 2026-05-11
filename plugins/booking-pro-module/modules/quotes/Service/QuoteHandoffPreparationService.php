<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Quotes\Repository\QuoteRepositoryInterface;
use InvalidArgumentException;

final class QuoteHandoffPreparationService
{
    public function __construct(
        private QuoteRepositoryInterface $repository,
        private QuoteAssumptionService $assumptions,
        private QuoteEventLogger $events,
        private QuoteExecutionLookupService $lookup
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function prepareResnapshot(int $quoteId, ?int $actorId = null): array
    {
        $quote = $this->repository->findQuote($quoteId);
        if ($quote === null) {
            throw new InvalidArgumentException('Quote not found.');
        }

        if ((string) ($quote['handoff_status'] ?? 'not_ready') !== 'ready_for_resnapshot') {
            throw new InvalidArgumentException('Quote staat nog niet klaar voor resnapshot.');
        }

        // current_version_id is intentionally used here: resnapshot preparation is a
        // DRAFT/ADMIN operation that creates a new version from the working draft.
        // The Woo handoff boundary (approved_version_id) is set later at acceptance.
        $currentVersionId = (int) ($quote['current_version_id'] ?? 0);
        if ($currentVersionId <= 0) {
            throw new InvalidArgumentException('Quote heeft geen actieve werkversie voor resnapshot.');
        }

        $currentVersion = $this->repository->findQuoteVersion($currentVersionId);
        if ($currentVersion === null) {
            throw new InvalidArgumentException('Actieve quote-versie niet gevonden.');
        }

        $nextVersionNumber = count($this->repository->listQuoteVersions($quoteId)) + 1;
        $currentLines = $this->repository->listQuoteLines($currentVersionId);
        $preparedLines = array();
        $pricingSnapshots = array();
        $availabilitySnapshots = array();
        $hasBlockingIssues = false;

        foreach ($currentLines as $line) {
            $pricing = $this->lookup->lookupPricing($line);
            $availability = $this->lookup->lookupAvailability($line);
            $preparedLines[] = $this->buildPreparedLine($line, $pricing, $availability);

            $pricingSnapshots[] = array(
                'line_number' => $line['line_number'] ?? null,
                'product_id' => $line['product_id'] ?? null,
                'confidence' => $pricing['confidence'] ?? 'unknown',
                'payload' => $pricing['payload'] ?? array(),
            );
            $availabilitySnapshots[] = array(
                'line_number' => $line['line_number'] ?? null,
                'product_id' => $line['product_id'] ?? null,
                'confidence' => $availability['confidence'] ?? 'unknown',
                'available' => $availability['available'] ?? false,
                'payload' => $availability['payload'] ?? array(),
            );

            if (($availability['confidence'] ?? 'unknown') !== 'confirmed' || ($pricing['confidence'] ?? 'unknown') === 'unknown') {
                $hasBlockingIssues = true;
            }
        }

        $newVersion = $this->repository->createQuoteVersion(array(
            'quote_id' => $quoteId,
            'version_number' => $nextVersionNumber,
            'status' => 'draft',
            'proposal_title' => (string) ($currentVersion['proposal_title'] ?? ''),
            'proposal_summary' => (string) ($currentVersion['proposal_summary'] ?? ''),
            'snapshot_type' => 'execution_resnapshot',
            'pricing_confidence' => $this->resolveVersionConfidence($preparedLines, 'pricing_confidence'),
            'availability_confidence' => $this->resolveVersionConfidence($preparedLines, 'availability_confidence'),
            'proposal_direction_a_json' => $currentVersion['proposal_direction_a_json'] ?? array(),
            'proposal_direction_b_json' => $currentVersion['proposal_direction_b_json'] ?? array(),
            'premium_upsell_json' => $currentVersion['premium_upsell_json'] ?? array(),
            'pricing_snapshot_json' => $pricingSnapshots,
            'availability_snapshot_json' => $availabilitySnapshots,
            'missing_info_json' => $currentVersion['missing_info_json'] ?? array(),
            'supersedes_version_id' => $currentVersionId,
            'created_by' => $actorId,
        ));

        $savedLines = $this->repository->replaceQuoteLines((int) $newVersion['id'], $preparedLines);

        $updatedQuote = $this->repository->updateQuote($quoteId, array(
            'current_version_id' => (int) $newVersion['id'],
            'handoff_status' => $hasBlockingIssues ? 'resnapshot_blocked' : 'resnapshot_prepared',
            'updated_at' => $this->now(),
        ));

        $this->createResnapshotAssumptions($quote, $newVersion, $savedLines);

        $this->events->log(
            'quote_resnapshot_prepared',
            isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
            $quoteId,
            (int) $newVersion['id'],
            $actorId,
            'Execution resnapshot voorbereid voor handoff.',
            array(
                'handoff_status' => $updatedQuote['handoff_status'] ?? 'resnapshot_prepared',
                'version_number' => $nextVersionNumber,
            )
        );

        return array(
            'quote' => $updatedQuote,
            'version' => $newVersion,
            'lines' => $savedLines,
        );
    }

    /**
     * @param array<string, mixed> $line
     * @param array<string, mixed> $pricing
     * @param array<string, mixed> $availability
     * @return array<string, mixed>
     */
    private function buildPreparedLine(array $line, array $pricing, array $availability): array
    {
        $selectedOptionLabels = $this->resolveSelectedOptionLabels($line, $pricing, $availability);
        $validatedSlotLabel = $this->resolveValidatedSlotLabel($line, $pricing, $availability);

        return array(
            'line_number' => (int) ($line['line_number'] ?? 1),
            'sort_order' => (int) ($line['sort_order'] ?? ($line['line_number'] ?? 1)),
            'line_type' => (string) ($line['line_type'] ?? 'product'),
            'line_status' => (int) ($line['product_id'] ?? 0) > 0 ? 'mapped' : 'directional',
            'title' => (string) ($line['title'] ?? ''),
            'product_id' => $line['product_id'] ?? null,
            'vendor_id' => $line['vendor_id'] ?? null,
            'resource_id' => $line['resource_id'] ?? null,
            'quantity' => (int) ($line['quantity'] ?? 1),
            'participants' => (int) ($line['participants'] ?? 0),
            'service_date' => $line['service_date'] ?? null,
            'proposed_start_time' => $line['proposed_start_time'] ?? ($line['start_time'] ?? null),
            'proposed_end_time' => $line['proposed_end_time'] ?? ($line['end_time'] ?? null),
            'start_time' => $line['start_time'] ?? null,
            'end_time' => $line['end_time'] ?? null,
            'duration_minutes' => $line['duration_minutes'] ?? null,
            'pricing_mode' => ($pricing['confidence'] ?? 'unknown') === 'execution_verified' ? 'execution_snapshot' : (string) ($line['pricing_mode'] ?? 'directional'),
            'pricing_confidence' => (string) ($pricing['confidence'] ?? 'unknown'),
            'availability_confidence' => (string) ($availability['confidence'] ?? 'unknown'),
            'unit_amount_snapshot' => $pricing['unit_amount_snapshot'] ?? null,
            'line_total_snapshot' => $pricing['line_total_snapshot'] ?? null,
            'currency' => (string) ($pricing['currency'] ?? ($line['currency'] ?? 'EUR')),
            'tax_class' => $line['tax_class'] ?? null,
            'pricing_snapshot_json' => $pricing['payload'] ?? array(),
            'availability_snapshot_json' => $availability['payload'] ?? array(),
            'selected_option_labels_json' => $selectedOptionLabels,
            'validated_slot_label' => $validatedSlotLabel,
            'mapping_notes' => $this->buildMappingNotes($pricing, $availability),
            'external_label' => $line['external_label'] ?? null,
            'is_optional' => $line['is_optional'] ?? 0,
            'position_group' => $line['position_group'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $version
     * @param array<int, array<string, mixed>> $lines
     */
    private function createResnapshotAssumptions(array $quote, array $version, array $lines): void
    {
        foreach ($lines as $line) {
            if ((int) ($line['product_id'] ?? 0) <= 0) {
                $this->assumptions->create(
                    (int) $quote['id'],
                    (int) $version['id'],
                    'resnapshot_missing_mapping',
                    'warning',
                    'internal',
                    'Execution resnapshot kon geen canonieke mapping voorbereiden voor een quote-regel.',
                    false,
                    false,
                    true,
                    isset($line['id']) ? (int) $line['id'] : null
                );
            }

            if ((string) ($line['pricing_confidence'] ?? 'unknown') === 'unknown') {
                $this->assumptions->create(
                    (int) $quote['id'],
                    (int) $version['id'],
                    'resnapshot_pricing_unverified',
                    'warning',
                    'internal',
                    'Execution resnapshot kon geen verifieerbare prijs ophalen voor een quote-regel.',
                    false,
                    false,
                    true,
                    isset($line['id']) ? (int) $line['id'] : null
                );
            }

            if ((string) ($line['availability_confidence'] ?? 'unknown') !== 'confirmed') {
                $this->assumptions->create(
                    (int) $quote['id'],
                    (int) $version['id'],
                    'resnapshot_availability_unconfirmed',
                    'warning',
                    'internal',
                    'Execution resnapshot kon beschikbaarheid nog niet bevestigen voor een quote-regel.',
                    false,
                    false,
                    true,
                    isset($line['id']) ? (int) $line['id'] : null
                );
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     */
    private function resolveVersionConfidence(array $lines, string $field): string
    {
        $values = array();
        foreach ($lines as $line) {
            $values[] = (string) ($line[$field] ?? 'unknown');
        }

        if ($values !== array() && count(array_unique($values)) === 1) {
            return $values[0];
        }

        if (in_array('unknown', $values, true)) {
            return 'unknown';
        }

        if (in_array('projected', $values, true)) {
            return 'projected';
        }

        if (in_array('snapshot', $values, true)) {
            return 'snapshot';
        }

        return $values[0] ?? 'unknown';
    }

    /**
     * @param array<string, mixed> $pricing
     * @param array<string, mixed> $availability
     */
    private function buildMappingNotes(array $pricing, array $availability): string
    {
        return sprintf(
            'pricing=%s; availability=%s',
            (string) ($pricing['confidence'] ?? 'unknown'),
            (string) ($availability['confidence'] ?? 'unknown')
        );
    }

    /**
     * @param array<string, mixed> $line
     * @param array<string, mixed> $pricing
     * @param array<string, mixed> $availability
     * @return array<int, string>
     */
    private function resolveSelectedOptionLabels(array $line, array $pricing, array $availability): array
    {
        $labels = array();
        $existingLabels = isset($line['selected_option_labels_json']) && is_array($line['selected_option_labels_json'])
            ? $line['selected_option_labels_json']
            : array();
        $pricingPayload = isset($pricing['payload']) && is_array($pricing['payload']) ? $pricing['payload'] : array();
        $availabilityPayload = isset($availability['payload']) && is_array($availability['payload']) ? $availability['payload'] : array();

        foreach ($existingLabels as $candidate) {
            if (! is_scalar($candidate) || $candidate === null) {
                continue;
            }

            $label = trim((string) $candidate);
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        foreach (array($pricingPayload, $availabilityPayload) as $payload) {
            foreach (array('selection_label', 'selection_name', 'option_label', 'option_name', 'resource_label', 'resource_name', 'variant_label', 'variant_name') as $key) {
                if (! isset($payload[$key]) || (! is_scalar($payload[$key]) && $payload[$key] !== null)) {
                    continue;
                }

                $label = trim((string) $payload[$key]);
                if ($label !== '') {
                    $labels[] = $label;
                }
            }
        }

        return array_values(array_unique($labels));
    }

    /**
     * @param array<string, mixed> $line
     * @param array<string, mixed> $pricing
     * @param array<string, mixed> $availability
     */
    private function resolveValidatedSlotLabel(array $line, array $pricing, array $availability): ?string
    {
        $pricingPayload = isset($pricing['payload']) && is_array($pricing['payload']) ? $pricing['payload'] : array();
        $availabilityPayload = isset($availability['payload']) && is_array($availability['payload']) ? $availability['payload'] : array();

        foreach (array(
            $availabilityPayload['slot_label'] ?? null,
            $availabilityPayload['slotLabel'] ?? null,
            $availabilityPayload['validated_slot_label'] ?? null,
            $pricingPayload['slot_label'] ?? null,
            $pricingPayload['slotLabel'] ?? null,
            $line['validated_slot_label'] ?? null,
        ) as $candidate) {
            if (! is_scalar($candidate) || $candidate === null) {
                continue;
            }

            $label = trim((string) $candidate);
            if ($label !== '') {
                return $label;
            }
        }

        $serviceDate = trim((string) ($line['service_date'] ?? ''));
        $startTime = trim((string) ($line['start_time'] ?? ''));
        $endTime = trim((string) ($line['end_time'] ?? ''));
        $timeRange = $this->buildTimeRangeLabel($startTime, $endTime);

        if ($serviceDate !== '' && $timeRange !== '') {
            return sprintf('%s %s', $serviceDate, $timeRange);
        }

        if ($timeRange !== '') {
            return $timeRange;
        }

        return $serviceDate !== '' ? $serviceDate : null;
    }

    private function buildTimeRangeLabel(string $startTime, string $endTime): string
    {
        if ($startTime !== '' && $endTime !== '') {
            return sprintf('%s-%s', $startTime, $endTime);
        }

        return $startTime !== '' ? $startTime : $endTime;
    }

    private function now(): string
    {
        return \function_exists('current_time')
            ? (string) \current_time('mysql', true)
            : gmdate('Y-m-d H:i:s');
    }
}
