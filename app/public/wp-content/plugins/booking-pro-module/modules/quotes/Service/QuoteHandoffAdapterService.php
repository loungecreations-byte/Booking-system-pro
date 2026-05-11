<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Quotes\Repository\QuoteRepositoryInterface;
use InvalidArgumentException;

final class QuoteHandoffAdapterService
{
    public function __construct(
        private QuoteRepositoryInterface $repository,
        private QuoteEventLogger $events
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildControlledPackage(int $quoteId, ?int $actorId = null): array
    {
        $quote = $this->repository->findQuote($quoteId);
        if ($quote === null) {
            throw new InvalidArgumentException('Quote not found.');
        }

        if ((string) ($quote['handoff_status'] ?? 'not_ready') !== 'resnapshot_prepared') {
            throw new InvalidArgumentException('Controlled handoff package kan pas worden opgebouwd na een geslaagde execution resnapshot.');
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

        if ((string) ($version['snapshot_type'] ?? '') !== 'execution_resnapshot') {
            throw new InvalidArgumentException('Controlled handoff package vereist een execution_resnapshot-versie.');
        }

        foreach ($this->repository->listQuoteAssumptions($quoteId) as $assumption) {
            if ((string) ($assumption['status'] ?? 'open') !== 'open') {
                continue;
            }

            if (! empty($assumption['blocks_handoff'])) {
                throw new InvalidArgumentException('Controlled handoff package kan niet worden opgebouwd zolang blokkerende handoff-assumptions open staan.');
            }
        }

        $request = isset($quote['quote_request_id']) ? $this->repository->findQuoteRequest((int) $quote['quote_request_id']) : null;
        $requester = isset($request['normalized_payload']['requester']) && is_array($request['normalized_payload']['requester'])
            ? $request['normalized_payload']['requester']
            : array();
        $lines = $this->repository->listQuoteLines($versionId);
        $items = array();
        $warnings = array();

        foreach ($lines as $line) {
            $items[] = array(
                'line_number' => (int) ($line['line_number'] ?? 0),
                'title' => (string) ($line['title'] ?? ''),
                'product_id' => isset($line['product_id']) ? (int) $line['product_id'] : 0,
                'vendor_id' => isset($line['vendor_id']) ? (int) $line['vendor_id'] : 0,
                'resource_id' => isset($line['resource_id']) ? (int) $line['resource_id'] : 0,
                'participants' => isset($line['participants']) ? (int) $line['participants'] : 0,
                'quantity' => isset($line['quantity']) ? (int) $line['quantity'] : 1,
                'service_date' => (string) ($line['service_date'] ?? ''),
                'start_time' => (string) ($line['start_time'] ?? ''),
                'end_time' => (string) ($line['end_time'] ?? ''),
                'unit_amount_snapshot' => isset($line['unit_amount_snapshot']) ? (float) $line['unit_amount_snapshot'] : null,
                'line_total_snapshot' => isset($line['line_total_snapshot']) ? (float) $line['line_total_snapshot'] : null,
                'currency' => (string) ($line['currency'] ?? 'EUR'),
                'pricing_confidence' => (string) ($line['pricing_confidence'] ?? 'unknown'),
                'availability_confidence' => (string) ($line['availability_confidence'] ?? 'unknown'),
                'pricing_snapshot' => is_array($line['pricing_snapshot_json'] ?? null) ? $line['pricing_snapshot_json'] : array(),
                'availability_snapshot' => is_array($line['availability_snapshot_json'] ?? null) ? $line['availability_snapshot_json'] : array(),
            );

            if ((int) ($line['product_id'] ?? 0) <= 0) {
                $warnings[] = sprintf('Line %d heeft geen product mapping.', (int) ($line['line_number'] ?? 0));
            }
        }

        $currency = $this->resolveCurrency($items);
        $subtotal = $this->sumTotals($items);
        $commercialAdjustments = $this->resolveCommercialAdjustments($version, $currency, $subtotal);

        $package = array(
            'package_type' => 'controlled_handoff',
            'quote_id' => $quoteId,
            'quote_reference' => (string) ($quote['quote_reference'] ?? ''),
            'quote_request_id' => isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : 0,
            'quote_version_id' => $versionId,
            'version_number' => isset($version['version_number']) ? (int) $version['version_number'] : 0,
            'generated_at' => $this->now(),
            'customer' => array(
                'name' => (string) (($requester['name'] ?? ($request['requester_name'] ?? ''))),
                'email' => (string) (($requester['email'] ?? ($request['requester_email'] ?? ''))),
                'phone' => (string) (($requester['phone'] ?? ($request['requester_phone'] ?? ''))),
                'company' => (string) (($requester['company'] ?? ($request['requester_company'] ?? ''))),
                'billing' => isset($requester['address']) && is_array($requester['address']) ? $requester['address'] : array(),
            ),
            'request_context' => array(
                'summary' => (string) (($request['request_summary'] ?? '')),
                'group_size' => isset($request['group_size']) ? (int) $request['group_size'] : 0,
                'preferred_date' => (string) (($request['preferred_date'] ?? '')),
                'preferred_start_time' => (string) (($request['preferred_start_time'] ?? '')),
                'preferred_end_time' => (string) (($request['preferred_end_time'] ?? '')),
                'notes' => isset($request['normalized_payload']['notes']) && is_string($request['normalized_payload']['notes'])
                    ? (string) $request['normalized_payload']['notes']
                    : '',
                'planner_plan_id' => isset($request['planner_plan_id']) ? (int) $request['planner_plan_id'] : 0,
            ),
            'items' => $items,
            'totals' => array(
                'currency' => $currency,
                'line_total_snapshot_sum' => $subtotal,
                'discount_amount' => (float) ($commercialAdjustments['discount_amount'] ?? 0.0),
                'discount_label' => (string) ($commercialAdjustments['discount_label'] ?? 'Korting'),
                'grand_total_snapshot' => (float) ($commercialAdjustments['grand_total_snapshot'] ?? $subtotal),
                'commercial_adjustments' => $commercialAdjustments,
            ),
            'warnings' => $warnings,
            'ready_for_execution' => $warnings === array(),
            'execution_note' => 'Dit pakket is een gecontroleerde adapter-input. Downstream Woo/booking execution moet pricing en availability opnieuw als runtime truth behandelen.',
        );

        $this->repository->updateQuote($quoteId, array(
            'handoff_status' => 'handoff_package_ready',
            'updated_at' => $this->now(),
        ));
        $this->repository->updateQuoteVersion($versionId, array(
            'handoff_payload_json' => $package,
            'updated_at' => $this->now(),
        ));

        $this->events->log(
            'quote_handoff_package_built',
            isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
            $quoteId,
            $versionId,
            $actorId,
            'Controlled handoff package opgebouwd.',
            array(
                'ready_for_execution' => $package['ready_for_execution'],
                'item_count' => count($items),
            )
        );

        return $package;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function sumTotals(array $items): float
    {
        $sum = 0.0;
        foreach ($items as $item) {
            $sum += isset($item['line_total_snapshot']) ? (float) $item['line_total_snapshot'] : 0.0;
        }

        return round($sum, 2);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function resolveCurrency(array $items): string
    {
        foreach ($items as $item) {
            $currency = trim((string) ($item['currency'] ?? ''));
            if ($currency !== '') {
                return $currency;
            }
        }

        return 'EUR';
    }

    /**
     * @param array<string, mixed> $version
     * @return array<string, mixed>
     */
    private function resolveCommercialAdjustments(array $version, string $currency, float $subtotal): array
    {
        $pricingSnapshot = is_array($version['pricing_snapshot_json'] ?? null)
            ? $version['pricing_snapshot_json']
            : array();
        $adjustments = is_array($pricingSnapshot['commercial_adjustments'] ?? null)
            ? $pricingSnapshot['commercial_adjustments']
            : array();
        $discountAmount = isset($adjustments['discount_amount']) && is_numeric($adjustments['discount_amount'])
            ? max(0.0, round((float) $adjustments['discount_amount'], 2))
            : 0.0;
        if ($discountAmount > $subtotal) {
            throw new InvalidArgumentException('Controlled handoff package kan niet worden opgebouwd omdat de korting hoger is dan het offerte-subtotaal.');
        }
        $discountLabel = trim((string) ($adjustments['discount_label'] ?? 'Korting'));

        return array(
            'type' => 'fixed_amount',
            'currency' => (string) (($adjustments['currency'] ?? '') ?: $currency),
            'discount_amount' => $discountAmount,
            'discount_label' => $discountLabel !== '' ? $discountLabel : 'Korting',
            'grand_total_snapshot' => round(max(0.0, $subtotal - $discountAmount), 2),
        );
    }

    private function now(): string
    {
        return \function_exists('current_time')
            ? (string) \current_time('mysql', true)
            : gmdate('Y-m-d H:i:s');
    }
}
