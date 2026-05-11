<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Quotes\Repository\QuoteRepositoryInterface;
use InvalidArgumentException;

final class QuoteExecutionRunnerService
{
    public function __construct(
        private QuoteRepositoryInterface $repository,
        private QuoteEventLogger $events,
        private QuoteExecutionLookupService $lookup
    ) {
    }

    /**
     * Perform a final runtime validation pass and produce a cart-ready validation payload.
     * This does not write to Woo cart, create orders, or create bookings.
     *
     * @return array<string, mixed>
     */
    public function validateCartReady(int $quoteId, ?int $actorId = null): array
    {
        $quote = $this->repository->findQuote($quoteId);
        if ($quote === null) {
            throw new InvalidArgumentException('Quote not found.');
        }

        if ((string) ($quote['handoff_status'] ?? 'not_ready') !== 'execution_payload_ready') {
            throw new InvalidArgumentException('Cart-ready validatie vereist eerst een execution adapter payload.');
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
        $executionPayload = isset($handoffPayload['execution_adapter']) && is_array($handoffPayload['execution_adapter'])
            ? $handoffPayload['execution_adapter']
            : array();

        if (($executionPayload['adapter_type'] ?? '') !== 'cart_order_prep') {
            throw new InvalidArgumentException('Geen cart_order_prep execution payload gevonden.');
        }

        $items = isset($executionPayload['items']) && is_array($executionPayload['items'])
            ? $executionPayload['items']
            : array();
        if ($items === array()) {
            throw new InvalidArgumentException('Execution payload bevat geen items.');
        }

        $validatedItems = array();
        $issues = array();
        $ready = true;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $lineForLookup = array(
                'product_id' => (int) ($item['product_id'] ?? 0),
                'resource_id' => (int) ($item['resource_id'] ?? 0),
                'participants' => (int) ($item['participants'] ?? $item['quantity'] ?? 1),
                'quantity' => (int) ($item['quantity'] ?? 1),
                'service_date' => (string) ($item['date'] ?? ''),
                'start_time' => $this->extractTime((string) ($item['start'] ?? '')),
                'end_time' => $this->extractTime((string) ($item['end'] ?? '')),
            );

            $pricing = $this->lookup->lookupPricing($lineForLookup);
            $availability = $this->lookup->lookupAvailability($lineForLookup);

            $priceChanged = $this->hasMaterialPriceDelta(
                isset($item['line_total_snapshot']) ? (float) $item['line_total_snapshot'] : null,
                $pricing['line_total_snapshot'] ?? null
            );
            $available = (bool) ($availability['available'] ?? false);
            $itemReady = ($pricing['confidence'] ?? 'unknown') === 'execution_verified'
                && ($availability['confidence'] ?? 'unknown') === 'confirmed'
                && $available
                && ! $priceChanged;

            if (! $itemReady) {
                $ready = false;
                $issues[] = array(
                    'line_number' => (int) ($item['line_number'] ?? 0),
                    'product_id' => (int) ($item['product_id'] ?? 0),
                    'reason' => $this->buildIssueReason($pricing, $availability, $priceChanged),
                );
            }

            $validatedItems[] = array(
                'line_number' => (int) ($item['line_number'] ?? 0),
                'product_id' => (int) ($item['product_id'] ?? 0),
                'resource_id' => (int) ($item['resource_id'] ?? 0),
                'runtime_pricing' => $pricing,
                'runtime_availability' => $availability,
                'price_changed' => $priceChanged,
                'ready' => $itemReady,
            );
        }

        $validation = array(
            'validation_type' => 'cart_ready_validation',
            'quote_id' => $quoteId,
            'quote_version_id' => $versionId,
            'validated_at' => $this->now(),
            'ready_for_runtime_execution' => $ready,
            'issues' => $issues,
            'items' => $validatedItems,
            'execution_note' => 'Deze validatie bevestigt alleen of het adapter-payload nu uitvoerbaar lijkt. Woo cart/order creatie blijft een aparte expliciete stap.',
        );

        $handoffPayload['execution_validation'] = $validation;
        $this->repository->updateQuoteVersion($versionId, array(
            'handoff_payload_json' => $handoffPayload,
            'updated_at' => $this->now(),
        ));
        $this->repository->updateQuote($quoteId, array(
            'handoff_status' => $ready ? 'execution_validated' : 'execution_blocked',
            'updated_at' => $this->now(),
        ));

        $this->events->log(
            'quote_execution_validated',
            isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
            $quoteId,
            $versionId,
            $actorId,
            $ready ? 'Execution payload runtime gevalideerd.' : 'Execution payload runtime validatie geblokkeerd.',
            array(
                'ready_for_runtime_execution' => $ready,
                'issue_count' => count($issues),
            )
        );

        return $validation;
    }

    private function extractTime(string $iso): string
    {
        if (preg_match('/T(\d{2}:\d{2})/', $iso, $matches) === 1) {
            return $matches[1];
        }

        return substr(trim($iso), 0, 5);
    }

    private function hasMaterialPriceDelta(?float $quoted, ?float $runtime): bool
    {
        if ($quoted === null || $runtime === null) {
            return true;
        }

        return abs($quoted - $runtime) > 0.01;
    }

    /**
     * @param array<string, mixed> $pricing
     * @param array<string, mixed> $availability
     */
    private function buildIssueReason(array $pricing, array $availability, bool $priceChanged): string
    {
        if (($availability['available'] ?? false) !== true) {
            return 'availability_not_confirmed';
        }

        if (($availability['confidence'] ?? 'unknown') !== 'confirmed') {
            return 'availability_confidence_not_confirmed';
        }

        if (($pricing['confidence'] ?? 'unknown') !== 'execution_verified') {
            return 'pricing_not_execution_verified';
        }

        if ($priceChanged) {
            return 'runtime_price_delta';
        }

        return 'unknown_execution_block';
    }

    private function now(): string
    {
        return \function_exists('current_time')
            ? (string) \current_time('mysql', true)
            : gmdate('Y-m-d H:i:s');
    }
}
