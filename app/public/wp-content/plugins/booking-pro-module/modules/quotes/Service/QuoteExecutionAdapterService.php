<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Quotes\Repository\QuoteRepositoryInterface;
use InvalidArgumentException;

final class QuoteExecutionAdapterService
{
    public function __construct(
        private QuoteRepositoryInterface $repository,
        private QuoteEventLogger $events
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildCartOrderPrep(int $quoteId, ?int $actorId = null): array
    {
        $quote = $this->repository->findQuote($quoteId);
        if ($quote === null) {
            throw new InvalidArgumentException('Quote not found.');
        }

        if ((string) ($quote['handoff_status'] ?? 'not_ready') !== 'handoff_package_ready') {
            throw new InvalidArgumentException('Execution adapter payload vereist eerst een controlled handoff package.');
        }

        // Handoff MUST read approved_version_id (pinned at acceptance), never current_version_id.
        $versionId = (int) ($quote['approved_version_id'] ?? 0);
        if ($versionId <= 0) {
            throw new InvalidArgumentException('Quote heeft geen geaccepteerde versie (approved_version_id ontbreekt). Accepteer de quote eerst.');
        }

        $version = $this->repository->findQuoteVersion($versionId);
        if ($version === null) {
            throw new InvalidArgumentException('Actieve quote-versie niet gevonden.');
        }

        $handoffPayload = is_array($version['handoff_payload_json'] ?? null)
            ? $version['handoff_payload_json']
            : array();

        if (($handoffPayload['package_type'] ?? '') !== 'controlled_handoff') {
            throw new InvalidArgumentException('Geen controlled handoff package gevonden op de actieve versie.');
        }

        if (empty($handoffPayload['ready_for_execution'])) {
            throw new InvalidArgumentException('Controlled handoff package is nog niet ready_for_execution.');
        }

        $items = isset($handoffPayload['items']) && is_array($handoffPayload['items'])
            ? $handoffPayload['items']
            : array();
        if ($items === array()) {
            throw new InvalidArgumentException('Controlled handoff package bevat geen uitvoerbare items.');
        }
        $handoffTotals = isset($handoffPayload['totals']) && is_array($handoffPayload['totals'])
            ? $handoffPayload['totals']
            : array();

        $prepItems = array();
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $productId = (int) ($item['product_id'] ?? 0);
            $serviceDate = trim((string) ($item['service_date'] ?? ''));
            $startTime = trim((string) ($item['start_time'] ?? ''));
            $endTime = trim((string) ($item['end_time'] ?? ''));

            if ($productId <= 0 || $serviceDate === '' || $startTime === '' || $endTime === '') {
                throw new InvalidArgumentException('Execution adapter payload vereist complete product- en planningsdata per regel.');
            }

            $participants = max(1, (int) ($item['participants'] ?? $item['quantity'] ?? 1));
            $startIso = $this->composeIso($serviceDate, $startTime);
            $endIso = $this->composeIso($serviceDate, $endTime);

            $pricingSource = (string) (($item['pricing_confidence'] ?? 'unknown') === 'execution_verified'
                ? 'quote_execution_resnapshot'
                : 'quote_controlled_handoff');

            $prepItems[] = array(
                'product_id' => $productId,
                'quantity' => $participants,
                'resource_id' => (int) ($item['resource_id'] ?? 0),
                'vendor_id' => (int) ($item['vendor_id'] ?? 0),
                'date' => $serviceDate,
                'start' => $startIso,
                'end' => $endIso,
                'participants' => $participants,
                'line_number' => (int) ($item['line_number'] ?? 0),
                'title' => (string) ($item['title'] ?? ''),
                'unit_amount_snapshot' => isset($item['unit_amount_snapshot']) ? (float) $item['unit_amount_snapshot'] : null,
                'line_total_snapshot' => isset($item['line_total_snapshot']) ? (float) $item['line_total_snapshot'] : null,
                'currency' => (string) ($item['currency'] ?? 'EUR'),
                'pricing_confidence' => (string) ($item['pricing_confidence'] ?? 'unknown'),
                'availability_confidence' => (string) ($item['availability_confidence'] ?? 'unknown'),
                'sbdp_meta' => array(
                    'quote_id' => $quoteId,
                    'quote_version_id' => $versionId,
                    'quote_reference' => (string) ($quote['quote_reference'] ?? ''),
                    'line_number' => (int) ($item['line_number'] ?? 0),
                    'sbdp_plan_date' => $serviceDate,
                    'sbdp_start' => $startIso,
                    'sbdp_end' => $endIso,
                    'sbdp_participants' => $participants,
                    'sbdp_resource_id' => (int) ($item['resource_id'] ?? 0),
                    'sbdp_pricing_source' => $pricingSource,
                ),
                'sbdp_summary' => array(
                    'date' => $serviceDate,
                    'time' => substr($startTime, 0, 5),
                    'participants' => $participants,
                    'resource_id' => (int) ($item['resource_id'] ?? 0),
                    'start' => $startIso,
                    'end' => substr($endTime, 0, 5),
                    'pricing' => is_array($item['pricing_snapshot'] ?? null) ? $item['pricing_snapshot'] : array(),
                    'combi_multi' => array(),
                ),
                'sbdp_pricing' => is_array($item['pricing_snapshot'] ?? null) ? $item['pricing_snapshot'] : array(),
            );
        }

        $payload = array(
            'adapter_type' => 'cart_order_prep',
            'quote_id' => $quoteId,
            'quote_reference' => (string) ($quote['quote_reference'] ?? ''),
            'quote_version_id' => $versionId,
            'generated_at' => $this->now(),
            'customer' => is_array($handoffPayload['customer'] ?? null) ? $handoffPayload['customer'] : array(),
            'request_context' => is_array($handoffPayload['request_context'] ?? null) ? $handoffPayload['request_context'] : array(),
            'items' => $prepItems,
            'totals' => array(
                'currency' => (string) (($handoffTotals['currency'] ?? 'EUR')),
                'line_total_snapshot_sum' => (float) ($handoffTotals['line_total_snapshot_sum'] ?? 0.0),
                'discount_amount' => (float) ($handoffTotals['discount_amount'] ?? 0.0),
                'discount_label' => (string) (($handoffTotals['discount_label'] ?? 'Korting')),
                'grand_total_snapshot' => (float) ($handoffTotals['grand_total_snapshot'] ?? ($handoffTotals['line_total_snapshot_sum'] ?? 0.0)),
                'commercial_adjustments' => is_array($handoffTotals['commercial_adjustments'] ?? null) ? $handoffTotals['commercial_adjustments'] : array(),
            ),
            'execution_boundary' => 'Dit payload is alleen adapter-input voor latere Woo/booking execution. Runtime pricing, taxes, VAT en availability moeten opnieuw gevalideerd worden.',
        );

        $handoffPayload['execution_adapter'] = $payload;
        $this->repository->updateQuoteVersion($versionId, array(
            'handoff_payload_json' => $handoffPayload,
            'updated_at' => $this->now(),
        ));
        $this->repository->updateQuote($quoteId, array(
            'handoff_status' => 'execution_payload_ready',
            'updated_at' => $this->now(),
        ));

        $this->events->log(
            'quote_execution_adapter_built',
            isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
            $quoteId,
            $versionId,
            $actorId,
            'Execution adapter payload opgebouwd.',
            array(
                'adapter_type' => 'cart_order_prep',
                'item_count' => count($prepItems),
            )
        );

        return $payload;
    }

    private function composeIso(string $date, string $time): string
    {
        return $date . 'T' . substr($time, 0, 5) . ':00';
    }

    private function now(): string
    {
        return \function_exists('current_time')
            ? (string) \current_time('mysql', true)
            : gmdate('Y-m-d H:i:s');
    }
}
