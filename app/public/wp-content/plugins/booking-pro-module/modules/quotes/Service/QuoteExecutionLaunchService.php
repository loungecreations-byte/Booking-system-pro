<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Quotes\Repository\QuoteRepositoryInterface;
use InvalidArgumentException;

final class QuoteExecutionLaunchService
{
    public function __construct(
        private QuoteRepositoryInterface $repository,
        private QuoteEventLogger $events
    ) {
    }

    /**
     * Build an admin-only launch payload that can be consumed by a later Woo/cart hydrator.
     * This does not mutate the Woo cart, create orders, or create bookings.
     *
     * @return array<string, mixed>
     */
    public function buildWooCartSessionPrep(int $quoteId, ?int $actorId = null): array
    {
        $quote = $this->repository->findQuote($quoteId);
        if ($quote === null) {
            throw new InvalidArgumentException('Quote not found.');
        }

        if ((string) ($quote['handoff_status'] ?? 'not_ready') !== 'execution_validated') {
            throw new InvalidArgumentException('Execution launch vereist eerst een runtime gevalideerde execution payload.');
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
        $validation = isset($handoffPayload['execution_validation']) && is_array($handoffPayload['execution_validation'])
            ? $handoffPayload['execution_validation']
            : array();

        if (($executionPayload['adapter_type'] ?? '') !== 'cart_order_prep') {
            throw new InvalidArgumentException('Geen cart_order_prep payload beschikbaar.');
        }

        if (empty($validation['ready_for_runtime_execution'])) {
            throw new InvalidArgumentException('Execution launch kan niet doorgaan zolang runtime validatie niet groen is.');
        }

        $items = isset($executionPayload['items']) && is_array($executionPayload['items'])
            ? $executionPayload['items']
            : array();
        if ($items === array()) {
            throw new InvalidArgumentException('Execution payload bevat geen launchbare items.');
        }

        $launchItems = array();
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId <= 0) {
                throw new InvalidArgumentException('Execution launch vereist een product mapping voor alle items.');
            }

            $launchItems[] = array(
                'product_id' => $productId,
                'quantity' => max(1, (int) ($item['quantity'] ?? $item['participants'] ?? 1)),
                'resource_id' => (int) ($item['resource_id'] ?? 0),
                'date' => (string) ($item['date'] ?? ''),
                'start' => (string) ($item['start'] ?? ''),
                'end' => (string) ($item['end'] ?? ''),
                'participants' => max(1, (int) ($item['participants'] ?? $item['quantity'] ?? 1)),
                'sbdp_meta' => is_array($item['sbdp_meta'] ?? null) ? $item['sbdp_meta'] : array(),
                'sbdp_summary' => is_array($item['sbdp_summary'] ?? null) ? $item['sbdp_summary'] : array(),
                'sbdp_pricing' => is_array($item['sbdp_pricing'] ?? null) ? $item['sbdp_pricing'] : array(),
            );
        }

        $launchPayload = array(
            'launch_type' => 'woo_cart_session_prep',
            'quote_id' => $quoteId,
            'quote_reference' => (string) ($quote['quote_reference'] ?? ''),
            'quote_version_id' => $versionId,
            'generated_at' => $this->now(),
            'expires_at' => $this->expiresAt(),
            'launch_token' => $this->buildLaunchToken($quoteId, $versionId),
            'customer' => is_array($executionPayload['customer'] ?? null)
                ? $executionPayload['customer']
                : (is_array($handoffPayload['customer'] ?? null) ? $handoffPayload['customer'] : array()),
            'items' => $launchItems,
            'totals' => is_array($executionPayload['totals'] ?? null) ? $executionPayload['totals'] : array(),
            'execution_boundary' => 'Dit launch payload is alleen een admin-only startpunt voor latere Woo cart/session hydration. Er is nog geen cart, order of booking aangemaakt.',
        );

        $handoffPayload['execution_launch'] = $launchPayload;
        $this->repository->updateQuoteVersion($versionId, array(
            'handoff_payload_json' => $handoffPayload,
            'updated_at' => $this->now(),
        ));
        $this->repository->updateQuote($quoteId, array(
            'handoff_status' => 'execution_launch_ready',
            'updated_at' => $this->now(),
        ));

        $this->events->log(
            'quote_execution_launch_built',
            isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
            $quoteId,
            $versionId,
            $actorId,
            'Execution launch payload opgebouwd.',
            array(
                'launch_type' => 'woo_cart_session_prep',
                'item_count' => count($launchItems),
            )
        );

        return $launchPayload;
    }

    private function buildLaunchToken(int $quoteId, int $versionId): string
    {
        return md5($quoteId . '|' . $versionId . '|' . $this->now() . '|' . uniqid('launch_', true));
    }

    private function expiresAt(): string
    {
        $timestamp = strtotime('+2 hours');
        return gmdate('Y-m-d H:i:s', $timestamp !== false ? $timestamp : time());
    }

    private function now(): string
    {
        return \function_exists('current_time')
            ? (string) \current_time('mysql', true)
            : gmdate('Y-m-d H:i:s');
    }
}
