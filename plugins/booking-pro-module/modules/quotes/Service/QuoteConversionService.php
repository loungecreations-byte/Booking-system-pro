<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Quotes\Repository\QuoteRepositoryInterface;
use BSP\Quotes\Service\QuoteSupplierConfirmationService;
use InvalidArgumentException;

final class QuoteConversionService
{
    public function __construct(
        private QuoteRepositoryInterface $repository,
        private QuoteAssumptionService $assumptions,
        private QuoteEventLogger $events
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function convertRequestToQuote(int $quoteRequestId, ?int $actorId = null): array
    {
        $request = $this->repository->findQuoteRequest($quoteRequestId);
        if ($request === null) {
            throw new InvalidArgumentException('Quote request not found.');
        }

        $existing = $this->findQuoteByRequest($quoteRequestId);
        if ($existing !== null) {
            return $existing;
        }

        $quote = $this->repository->createQuote(array(
            'quote_reference'    => $this->buildReference('Q'),
            'quote_request_id'   => $quoteRequestId,
            'status'             => 'draft',
            'review_status'      => 'not_started',
            'send_status'        => 'not_ready',
            'handoff_status'     => 'not_ready',
            'owner_user_id'      => $actorId,
            'customer_id'        => $request['customer_id'] ?? null,
            'planner_plan_id'    => $request['planner_plan_id'] ?? null,
            'internal_notes'     => '',
        ));

        $version = $this->repository->createQuoteVersion(array(
            'quote_id'                  => (int) $quote['id'],
            'version_number'            => 1,
            'status'                    => 'draft',
            'proposal_title'            => $this->buildProposalTitle($request),
            'proposal_summary'          => (string) ($request['request_summary'] ?? ''),
            'snapshot_type'             => 'initial',
            'pricing_confidence'        => (string) ($request['pricing_confidence'] ?? 'unknown'),
            'availability_confidence'   => (string) ($request['availability_confidence'] ?? 'unknown'),
            'missing_info_json'         => $this->buildMissingInfo($request),
            'created_by'                => $actorId,
        ));

        $savedLines = $this->repository->replaceQuoteLines((int) $version['id'], $this->buildQuoteLines($request));
        $quote = $this->repository->updateQuote((int) $quote['id'], array(
            'current_version_id' => (int) $version['id'],
            'updated_at'         => $this->now(),
        ));

        $this->assumptions->createAutomaticAssumptions($request, $quote, $version, $savedLines);
        (new QuoteSupplierConfirmationService($this->repository, $this->events))->syncQuote((int) $quote['id'], $actorId);
        $this->repository->updateQuoteRequest($quoteRequestId, array('status' => 'converted_to_quote'));

        $this->events->log(
            'quote_created_from_request',
            $quoteRequestId,
            (int) $quote['id'],
            (int) $version['id'],
            $actorId,
            'Quote aangemaakt vanuit quote request.',
            array('quote_reference' => $quote['quote_reference'] ?? '')
        );

        return $this->repository->findQuote((int) $quote['id']) ?? $quote;
    }

    /**
     * @return array<string, mixed>
     */
    public function markReadyForResnapshot(int $quoteId, ?int $actorId = null): array
    {
        $quote = $this->repository->findQuote($quoteId);
        if ($quote === null) {
            throw new InvalidArgumentException('Quote not found.');
        }

        if ((string) ($quote['review_status'] ?? 'not_started') !== 'approved') {
            throw new InvalidArgumentException('Quote kan pas naar ready_for_resnapshot na goedgekeurde review.');
        }

        foreach ($this->repository->listQuoteAssumptions($quoteId) as $assumption) {
            if ((string) ($assumption['status'] ?? 'open') !== 'open') {
                continue;
            }

            if (! empty($assumption['blocks_handoff'])) {
                throw new InvalidArgumentException('Quote kan niet naar ready_for_resnapshot zolang blokkerende handoff-assumptions open staan.');
            }
        }

        $updated = $this->repository->updateQuote($quoteId, array(
            'handoff_status'    => 'ready_for_resnapshot',
            'handoff_ready_at'  => $this->now(),
            'updated_at'        => $this->now(),
        ));

        $this->events->log(
            'quote_handoff_ready_for_resnapshot',
            isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
            $quoteId,
            isset($quote['current_version_id']) ? (int) $quote['current_version_id'] : null,
            $actorId,
            'Quote gemarkeerd als klaar voor resnapshot.',
            array('handoff_status' => 'ready_for_resnapshot')
        );

        return $updated;
    }

    /**
     * @param array<string, mixed> $request
     * @return array<int, array<string, mixed>>
     */
    private function buildQuoteLines(array $request): array
    {
        $normalized = isset($request['normalized_payload']) && is_array($request['normalized_payload'])
            ? $request['normalized_payload']
            : array();
        $items = isset($normalized['items']) && is_array($normalized['items'])
            ? $normalized['items']
            : array();

        $lines = array();
        $lineNumber = 1;
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                $title = sprintf('Voorstelregel %d', $lineNumber);
            }

            $lines[] = array(
                'line_number'              => $lineNumber,
                'sort_order'               => $lineNumber,
                'line_type'                => 'product',
                'line_status'              => (int) ($item['product_id'] ?? 0) > 0 ? 'mapped' : 'directional',
                'title'                    => $title,
                'product_id'               => $this->normalizeInt($item['product_id'] ?? null),
                'vendor_id'                => $this->normalizeInt($item['vendor_id'] ?? null),
                'resource_id'              => $this->normalizeInt($item['resource_id'] ?? null),
                'quantity'                 => max(1, (int) ($item['quantity'] ?? 1)),
                'participants'             => max(0, (int) ($item['participants'] ?? ($request['group_size'] ?? 0))),
                'service_date'             => $item['service_date'] ?? ($request['preferred_date'] ?? null),
                'proposed_start_time'      => $item['start_time'] ?? ($request['preferred_start_time'] ?? null),
                'proposed_end_time'        => $item['end_time'] ?? ($request['preferred_end_time'] ?? null),
                'start_time'               => $item['start_time'] ?? ($request['preferred_start_time'] ?? null),
                'end_time'                 => $item['end_time'] ?? ($request['preferred_end_time'] ?? null),
                'pricing_mode'             => (float) ($item['line_total_snapshot'] ?? 0.0) > 0 ? 'snapshot' : 'directional',
                'pricing_confidence'       => (string) ($item['pricing_confidence'] ?? ($request['pricing_confidence'] ?? 'unknown')),
                'availability_confidence'  => (string) ($item['availability_confidence'] ?? ($request['availability_confidence'] ?? 'unknown')),
                'unit_amount_snapshot'     => isset($item['unit_amount_snapshot']) ? (float) $item['unit_amount_snapshot'] : null,
                'line_total_snapshot'      => isset($item['line_total_snapshot']) ? (float) $item['line_total_snapshot'] : null,
                'currency'                 => 'EUR',
                'selected_option_labels_json' => $this->normalizeOptionLabels($item['selected_option_labels_json'] ?? ($item['selected_option_labels'] ?? array())),
                'validated_slot_label'     => $this->normalizeSlotLabel($item['validated_slot_label'] ?? null),
                'availability_snapshot_json' => isset($item['availability_snapshot_json']) && is_array($item['availability_snapshot_json'])
                    ? $item['availability_snapshot_json']
                    : array(),
            );
            $lineNumber++;
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $request
     * @return array<int, string>
     */
    private function buildMissingInfo(array $request): array
    {
        $missing = array();
        if (empty($request['preferred_date'])) {
            $missing[] = 'preferred_date';
        }

        if ((int) ($request['group_size'] ?? 0) <= 0) {
            $missing[] = 'group_size';
        }

        return $missing;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findQuoteByRequest(int $quoteRequestId): ?array
    {
        foreach ($this->repository->listQuotes() as $quote) {
            if ((int) ($quote['quote_request_id'] ?? 0) === $quoteRequestId) {
                return $quote;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $request
     */
    private function buildProposalTitle(array $request): string
    {
        $company = trim((string) ($request['requester_company'] ?? ''));
        if ($company !== '') {
            return sprintf('Voorstel voor %s', $company);
        }

        $name = trim((string) ($request['requester_name'] ?? ''));
        if ($name !== '') {
            return sprintf('Voorstel voor %s', $name);
        }

        return 'Commercieel voorstel';
    }

    private function buildReference(string $prefix): string
    {
        $timestamp = gmdate('YmdHis');
        $entropy = strtoupper(substr(str_replace('.', '', uniqid('', true)), -8));

        return sprintf('%s-%s-%s', $prefix, $timestamp, $entropy);
    }

    private function normalizeInt($value): ?int
    {
        $int = (int) $value;
        return $int > 0 ? $int : null;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeOptionLabels($value): array
    {
        if (! is_array($value)) {
            return array();
        }

        $labels = array();
        foreach ($value as $candidate) {
            if (! is_scalar($candidate) || $candidate === null) {
                continue;
            }

            $label = trim((string) $candidate);
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return array_values(array_unique($labels));
    }

    /**
     * @param mixed $value
     */
    private function normalizeSlotLabel($value): ?string
    {
        if (! is_scalar($value) || $value === null) {
            return null;
        }

        $label = trim((string) $value);
        return $label !== '' ? $label : null;
    }

    private function now(): string
    {
        return \function_exists('current_time')
            ? (string) \current_time('mysql', true)
            : gmdate('Y-m-d H:i:s');
    }
}
