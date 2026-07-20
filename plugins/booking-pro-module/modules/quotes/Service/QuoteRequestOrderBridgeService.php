<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Quotes\Repository\QuoteRepositoryInterface;
use BSPModule\Core\Rest\RestService;
use InvalidArgumentException;

use function is_array;
use function is_wp_error;
use function method_exists;
use function wp_json_encode;

final class QuoteRequestOrderBridgeService
{
    public function __construct(
        private QuoteRepositoryInterface $repository,
        private QuoteEventLogger $events
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function createWooRequestOrder(int $quoteId, ?int $actorId = null, bool $force = false): array
    {
        $quote = $this->repository->findQuote($quoteId);
        if ($quote === null) {
            throw new HandoffValidationException(
                'bsp_quotes_handoff_quote_not_found',
                'Quote not found.',
                404,
                array('quote_id' => $quoteId)
            );
        }

        $existingOrderId = isset($quote['woo_order_id']) ? (int) $quote['woo_order_id'] : 0;
        if ($existingOrderId > 0 && ! $force) {
            $existingVersionId = $this->resolveExistingOrderVersionId($quote, $quoteId);
            if ($existingVersionId > 0) {
                $this->attachOrderQuoteMeta($existingOrderId, $quoteId, $existingVersionId, $quote);
            }

            return $this->existingOrderPayload($quoteId, $quote, $existingOrderId, $existingVersionId);
        }

        $requestId = isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : 0;
        if ($requestId <= 0) {
            throw new HandoffValidationException(
                'bsp_quotes_handoff_missing_request',
                'Quote mist een gekoppelde request.',
                422,
                array('quote_id' => $quoteId)
            );
        }

        $versionId = $this->resolveOrderVersionId($quote, $quoteId);

        $version = $this->repository->findQuoteVersion($versionId);
        if ($version === null) {
            throw new HandoffValidationException(
                'bsp_quotes_handoff_version_not_found',
                'Quote-versie voor Woo request-order niet gevonden.',
                404,
                array('quote_id' => $quoteId, 'version_id' => $versionId)
            );
        }

        $handoffPayload = is_array($version['handoff_payload_json'] ?? null)
            ? $version['handoff_payload_json']
            : array();
        $executionPayload = isset($handoffPayload['execution_adapter']) && is_array($handoffPayload['execution_adapter'])
            ? $handoffPayload['execution_adapter']
            : array();

        if (($executionPayload['adapter_type'] ?? '') !== 'cart_order_prep') {
            throw new HandoffValidationException(
                'bsp_quotes_handoff_invalid_adapter_type',
                'Woo request-order vereist eerst een execution adapter payload uit de quote resnapshot-keten.',
                422,
                array(
                    'quote_id' => $quoteId,
                    'adapter_type' => (string) ($executionPayload['adapter_type'] ?? ''),
                )
            );
        }

        $participants = $this->resolveParticipants($executionPayload);
        if ($participants <= 0) {
            throw new HandoffValidationException(
                'bsp_quotes_handoff_invalid_participants',
                'Participants in handoff payload is ongeldig.',
                422,
                array('quote_id' => $quoteId, 'participants' => $participants)
            );
        }

        $items = $this->extractComposeItems($executionPayload);
        if ($items === array()) {
            throw new HandoffValidationException(
                'bsp_quotes_handoff_missing_items',
                'Geen uitvoerbare execution items gevonden voor Woo request-order.',
                422,
                array('quote_id' => $quoteId)
            );
        }

        $priceCheck = $this->assertProposalMatchesWooTotal($quote, $version, $executionPayload, $participants, $actorId);
        if (! empty($priceCheck['blocked'])) {
            throw new HandoffValidationException(
                'bsp_quotes_handoff_price_mismatch',
                'Woo request-order geblokkeerd: offertebedrag wijkt af van Woo-prijs.',
                409,
                $priceCheck
            );
        }

        $composeRequest = new \WP_REST_Request('POST', '/sbdp/v1/compose_booking');
        $composePayload = array(
            'mode' => 'request',
            'participants' => $participants,
            'items' => $items,
        );

        if (method_exists($composeRequest, 'set_json_params')) {
            $composeRequest->set_json_params($composePayload);
        } else {
            $composeRequest->set_header('content-type', 'application/json');
            if (method_exists($composeRequest, 'set_body')) {
                $composeRequest->set_body(wp_json_encode($composePayload));
            } elseif (method_exists($composeRequest, 'set_param')) {
                foreach ($composePayload as $key => $value) {
                    $composeRequest->set_param($key, $value);
                }
            }
        }

        $result = RestService::compose_booking($composeRequest);
        if (is_wp_error($result)) {
            throw new HandoffValidationException(
                'bsp_quotes_handoff_compose_failed',
                (string) $result->get_error_message(),
                422,
                array('quote_id' => $quoteId)
            );
        }

        if (! is_array($result) || empty($result['ok'])) {
            throw new HandoffValidationException(
                'bsp_quotes_handoff_compose_not_ok',
                'Woo request-order kon niet worden aangemaakt.',
                422,
                array(
                    'quote_id' => $quoteId,
                    'compose_result' => is_array($result) ? $result : array(),
                )
            );
        }

        $orderId = isset($result['order_id']) ? (int) $result['order_id'] : 0;
        if ($orderId <= 0) {
            throw new HandoffValidationException(
                'bsp_quotes_handoff_missing_order_id',
                'Woo request-order mist order_id.',
                422,
                array('quote_id' => $quoteId)
            );
        }

        $this->attachOrderQuoteMeta($orderId, $quoteId, $versionId, $quote);
        $this->applyApprovedProposalTotalToOrder($quote, $version, $executionPayload, $orderId);
        $createdOrderCheck = $this->assertCreatedWooOrderMatchesProposal($quote, $version, $executionPayload, $orderId, $actorId);
        if (! empty($createdOrderCheck['blocked'])) {
            throw new HandoffValidationException(
                'bsp_quotes_handoff_price_mismatch',
                'Woo request-order geblokkeerd: aangemaakte Woo order wijkt af van het approved voorstelbedrag.',
                409,
                $createdOrderCheck
            );
        }

        $updatedQuote = $this->repository->updateQuote($quoteId, array(
            'woo_order_id' => $orderId,
            'handoff_status' => 'woo_request_order_created',
            'updated_at' => $this->now(),
        ));

        $this->events->log(
            'quote_woo_request_order_created',
            $requestId,
            $quoteId,
            $versionId,
            $actorId,
            'Woo request-order aangemaakt vanuit quote.',
            array(
                'order_id' => $orderId,
                'quote_version_id' => $versionId,
                'force' => $force,
            )
        );
        (new QuoteTimelineService($this->repository))->logOnce(
            'woo_order_created',
            'woo_order_created:order:' . $orderId,
            $requestId,
            $quoteId,
            $versionId,
            $actorId,
            'Woo request-order aangemaakt.',
            array(
                'order_id' => $orderId,
                'quote_version_id' => $versionId,
                'force' => $force,
            )
        );

        return array(
            'quote_id' => $quoteId,
            'quote_version_id' => $versionId,
            'quote_reference' => (string) ($updatedQuote['quote_reference'] ?? ''),
            'woo_order_id' => $orderId,
            'redirect' => (string) ($result['redirect'] ?? ''),
            'view_url' => (string) ($result['view_url'] ?? ''),
            'created' => true,
        );
    }

    /**
     * @param array<string, mixed> $quote
     */
    private function resolveOrderVersionId(array $quote, int $quoteId): int
    {
        if ((string) ($quote['status'] ?? '') === 'accepted') {
            $approvedVersionId = (int) ($quote['approved_version_id'] ?? 0);
            if ($approvedVersionId <= 0) {
                throw new HandoffValidationException(
                    'bsp_quotes_handoff_missing_approved_version',
                    'Geaccepteerde quote mist approved_version_id; Woo request-order wordt niet aangemaakt.',
                    422,
                    array('quote_id' => $quoteId)
                );
            }

            return $approvedVersionId;
        }

        $currentVersionId = isset($quote['current_version_id']) ? (int) $quote['current_version_id'] : 0;
        if ($currentVersionId <= 0) {
            throw new HandoffValidationException(
                'bsp_quotes_handoff_missing_version',
                'Quote heeft geen actieve versie.',
                422,
                array('quote_id' => $quoteId)
            );
        }

        return $currentVersionId;
    }

    /**
     * @param array<string, mixed> $quote
     */
    private function resolveExistingOrderVersionId(array $quote, int $quoteId): int
    {
        if ((string) ($quote['status'] ?? '') === 'accepted') {
            return $this->resolveOrderVersionId($quote, $quoteId);
        }

        return (int) ($quote['approved_version_id'] ?? 0) ?: (int) ($quote['current_version_id'] ?? 0);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function extractComposeItems(array $payload): array
    {
        $executionItems = $payload['items'] ?? array();
        if (! is_array($executionItems)) {
            return array();
        }

        $items = array();
        foreach ($executionItems as $item) {
            if (! is_array($item)) {
                continue;
            }

            $productId = (int) ($item['product_id'] ?? 0);
            $start = (string) ($item['start'] ?? '');
            $end = (string) ($item['end'] ?? '');

            if ($productId <= 0 || $start === '') {
                continue;
            }

            $items[] = array(
                'product_id' => $productId,
                'start' => $start,
                'end' => $end,
                'resource_id' => (int) ($item['resource_id'] ?? 0),
            );
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveParticipants(array $payload): int
    {
        $count = isset($payload['request_context']['group_size']) ? (int) $payload['request_context']['group_size'] : 0;
        if ($count > 0) {
            return $count;
        }

        $executionItems = $payload['items'] ?? array();
        if (is_array($executionItems)) {
            foreach ($executionItems as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $participants = (int) ($item['participants'] ?? 0);
                if ($participants > 0) {
                    return $participants;
                }
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $quote
     * @return array<string, mixed>
     */
    private function existingOrderPayload(int $quoteId, array $quote, int $orderId, int $versionId = 0): array
    {
        $viewUrl = '';
        if (function_exists('wc_get_order')) {
            $order = wc_get_order($orderId);
            if ($order instanceof \WC_Order && method_exists($order, 'get_view_order_url')) {
                $viewUrl = (string) $order->get_view_order_url();
            }
        }

        return array(
            'quote_id' => $quoteId,
            'quote_version_id' => $versionId,
            'quote_reference' => (string) ($quote['quote_reference'] ?? ''),
            'woo_order_id' => $orderId,
            'view_url' => $viewUrl,
            'created' => false,
        );
    }

    /**
     * @param array<string, mixed> $quote
     */
    private function attachOrderQuoteMeta(int $orderId, int $quoteId, int $versionId, array $quote): void
    {
        if ($orderId <= 0 || $quoteId <= 0 || $versionId <= 0 || ! \function_exists('wc_get_order')) {
            return;
        }

        $order = \wc_get_order($orderId);
        if (! $order || ! method_exists($order, 'update_meta_data')) {
            return;
        }

        $order->update_meta_data('_sbdp_quote_id', $quoteId);
        $order->update_meta_data('_sbdp_quote_version_id', $versionId);
        $order->update_meta_data('sbdp_quote_id', $quoteId);
        $order->update_meta_data('sbdp_quote_version_id', $versionId);
        $quoteReference = trim((string) ($quote['quote_reference'] ?? ''));
        if ($quoteReference !== '') {
            $order->update_meta_data('_sbdp_quote_reference', $quoteReference);
        }
        if (method_exists($order, 'save')) {
            $order->save();
        }
    }

    /**
     * WooCommerce remains the commercial truth. Quote snapshots may only create
     * a payment request when their approved total still matches the Woo-resolved
     * product total. We fail closed so admins can resnapshot or resend a version.
     *
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $version
     * @param array<string, mixed> $executionPayload
     * @return array<string, mixed>
     */
    private function assertProposalMatchesWooTotal(array $quote, array $version, array $executionPayload, int $participants, ?int $actorId): array
    {
        $quoteId = (int) ($quote['id'] ?? 0);
        $versionId = (int) ($version['id'] ?? 0);
        $proposalTotal = $this->resolveProposalTotal($version, $executionPayload);
        if ((string) ($quote['status'] ?? '') === 'accepted' && ! $this->hasExplicitWooPricing($executionPayload)) {
            return array(
                'checked' => false,
                'blocked' => false,
                'proposal_total' => $proposalTotal,
                'source' => 'deferred_to_created_order_total',
            );
        }

        $wooTotal = $this->resolveExpectedWooTotal($executionPayload, $participants);

        if ($proposalTotal === null || $wooTotal === null) {
            return array('checked' => false, 'blocked' => false);
        }

        $delta = round($wooTotal - $proposalTotal, 2);
        if (abs($delta) <= 0.01) {
            return array(
                'checked' => true,
                'blocked' => false,
                'proposal_total' => $proposalTotal,
                'woo_total' => $wooTotal,
                'delta' => $delta,
                'currency' => $this->currency($executionPayload),
            );
        }

        return $this->recordPriceMismatch($quote, $versionId, $proposalTotal, $wooTotal, $delta, $this->currency($executionPayload), $actorId);
    }

    /**
     * The pre-compose check catches predictable product price drift. This
     * post-compose guard checks WooCommerce's actual order grand total after tax,
     * fee, discount and order calculation hooks have run.
     *
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $version
     * @param array<string, mixed> $executionPayload
     * @return array<string, mixed>
     */
    private function assertCreatedWooOrderMatchesProposal(array $quote, array $version, array $executionPayload, int $orderId, ?int $actorId): array
    {
        $quoteId = (int) ($quote['id'] ?? 0);
        $versionId = (int) ($version['id'] ?? 0);
        $proposalTotal = $this->resolveProposalTotal($version, $executionPayload);
        $wooTotal = $this->resolveCreatedOrderTotal($orderId);

        if ($proposalTotal === null || $wooTotal === null) {
            return array('checked' => false, 'blocked' => false, 'order_id' => $orderId);
        }

        $delta = round($wooTotal - $proposalTotal, 2);
        if (abs($delta) <= 0.01) {
            return array(
                'checked' => true,
                'blocked' => false,
                'quote_id' => $quoteId,
                'order_id' => $orderId,
                'approved_version_id' => $versionId,
                'proposal_total' => $proposalTotal,
                'woo_total' => $wooTotal,
                'delta' => $delta,
                'currency' => $this->currency($executionPayload),
                'source' => 'actual_woo_order_total',
            );
        }

        return $this->recordPriceMismatch($quote, $versionId, $proposalTotal, $wooTotal, $delta, $this->currency($executionPayload), $actorId, array(
            'order_id' => $orderId,
            'source' => 'actual_woo_order_total',
        ));
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function recordPriceMismatch(array $quote, int $versionId, float $proposalTotal, float $wooTotal, float $delta, string $currency, ?int $actorId, array $extra = array()): array
    {
        $quoteId = (int) ($quote['id'] ?? 0);
        $orderId = (int) ($extra['order_id'] ?? 0);
        $payload = array_merge(array(
            'quote_id' => $quoteId,
            'approved_version_id' => $versionId,
            'proposal_total' => $proposalTotal,
            'woo_total' => $wooTotal,
            'delta' => $delta,
            'currency' => $currency,
        ), $extra);

        $changes = array(
            'handoff_status' => 'price_mismatch_requires_review',
            'updated_at' => $this->now(),
        );
        if ($orderId > 0) {
            $changes['woo_order_id'] = $orderId;
            $this->markOrderPriceMismatch($orderId, $payload);
        }

        $this->repository->updateQuote($quoteId, $changes);
        $this->events->log(
            'quote_order_price_mismatch',
            isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
            $quoteId,
            $versionId,
            $actorId,
            'Woo request-order geblokkeerd door prijsverschil tussen approved proposal en Woo.',
            $payload
        );

        return array_merge($payload, array('checked' => true, 'blocked' => true));
    }

    private function resolveCreatedOrderTotal(int $orderId): ?float
    {
        if ($orderId <= 0 || ! \function_exists('wc_get_order')) {
            return null;
        }

        $order = \wc_get_order($orderId);
        if (! $order || ! method_exists($order, 'get_total')) {
            return null;
        }

        $total = $order->get_total();
        return is_numeric($total) ? round((float) $total, 2) : null;
    }

    /**
     * Request-order creation starts from the normal Woo compose path, but an
     * accepted quote's approved version is the contract amount for this handoff.
     * We project that approved gross total onto the draft Woo order before the
     * final guard compares Woo's payable total. If Woo hooks still alter the
     * payable total afterwards, the existing mismatch guard blocks the flow.
     *
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $version
     * @param array<string, mixed> $executionPayload
     */
    private function applyApprovedProposalTotalToOrder(array $quote, array $version, array $executionPayload, int $orderId): void
    {
        if ((string) ($quote['status'] ?? '') !== 'accepted' || $orderId <= 0 || ! \function_exists('wc_get_order')) {
            return;
        }

        $proposalTotal = $this->resolveProposalTotal($version, $executionPayload);
        if ($proposalTotal === null || $proposalTotal < 0.0) {
            return;
        }

        $order = \wc_get_order($orderId);
        if (! $order) {
            return;
        }

        $lineTotals = $this->resolveProposalLineTotals($version);
        $this->applyProposalLineTotals($order, $lineTotals, $proposalTotal);

        if (method_exists($order, 'calculate_totals')) {
            $order->calculate_totals(false);
        }
        if (method_exists($order, 'set_total')) {
            $order->set_total($proposalTotal);
        }
        if (method_exists($order, 'update_meta_data')) {
            $order->update_meta_data('_sbdp_quote_approved_total', $proposalTotal);
            $order->update_meta_data('_sbdp_quote_total_source', 'approved_quote_version');
            $order->update_meta_data('_sbdp_quote_total_normalized', 'yes');
        }
        if (method_exists($order, 'save')) {
            $order->save();
        }
    }

    /**
     * @param array<string, mixed> $version
     * @return array<int, float>
     */
    private function resolveProposalLineTotals(array $version): array
    {
        $versionId = (int) ($version['id'] ?? 0);
        if ($versionId <= 0) {
            return array();
        }

        $totals = array();
        foreach ($this->repository->listQuoteLines($versionId) as $line) {
            if (! isset($line['line_total_snapshot']) || ! is_numeric($line['line_total_snapshot'])) {
                continue;
            }

            $total = round((float) $line['line_total_snapshot'], 2);
            if ($total < 0.0) {
                continue;
            }

            $totals[] = $total;
        }

        return $totals;
    }

    /**
     * @param mixed $order
     * @param array<int, float> $lineTotals
     */
    private function applyProposalLineTotals($order, array $lineTotals, float $proposalTotal): void
    {
        if (! method_exists($order, 'get_items')) {
            return;
        }

        $items = $order->get_items('line_item');
        if (! is_array($items) || $items === array()) {
            return;
        }

        $fallbackTotals = $lineTotals;
        if ($fallbackTotals === array()) {
            $fallbackTotals = $this->distributeTotalAcrossItems($proposalTotal, count($items));
        }

        $index = 0;
        foreach ($items as $item) {
            if (! is_object($item)) {
                $index++;
                continue;
            }

            $lineTotal = $fallbackTotals[$index] ?? null;
            if ($lineTotal === null) {
                $lineTotal = $this->remainingLineTotal($proposalTotal, $fallbackTotals);
            }
            $lineTotal = round(max(0.0, (float) $lineTotal), 2);

            if (method_exists($item, 'set_subtotal')) {
                $item->set_subtotal($lineTotal);
            }
            if (method_exists($item, 'set_total')) {
                $item->set_total($lineTotal);
            }
            if (method_exists($item, 'add_meta_data')) {
                $item->add_meta_data('sbdp_display_total', $lineTotal, true);
                $item->add_meta_data('_sbdp_quote_line_total_source', 'approved_quote_version', true);
            }
            if (method_exists($item, 'save')) {
                $item->save();
            }

            $index++;
        }
    }

    /**
     * @return array<int, float>
     */
    private function distributeTotalAcrossItems(float $total, int $count): array
    {
        if ($count <= 0) {
            return array();
        }

        $unit = floor(($total / $count) * 100) / 100;
        $totals = array_fill(0, $count, $unit);
        $remainder = round($total - ($unit * $count), 2);
        if ($remainder !== 0.0) {
            $totals[$count - 1] = round($totals[$count - 1] + $remainder, 2);
        }

        return $totals;
    }

    /**
     * @param array<int, float> $knownTotals
     */
    private function remainingLineTotal(float $proposalTotal, array $knownTotals): float
    {
        return round(max(0.0, $proposalTotal - array_sum($knownTotals)), 2);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function markOrderPriceMismatch(int $orderId, array $payload): void
    {
        if ($orderId <= 0 || ! \function_exists('wc_get_order')) {
            return;
        }

        $order = \wc_get_order($orderId);
        if (! $order) {
            return;
        }
        if (method_exists($order, 'update_meta_data')) {
            $order->update_meta_data('_sbdp_quote_price_mismatch_requires_review', 'yes');
            $order->update_meta_data('_sbdp_quote_price_mismatch_payload', $payload);
        }
        if (method_exists($order, 'add_order_note')) {
            $order->add_order_note('Quote OS: betaalverzoek geblokkeerd door prijsverschil tussen approved proposal en Woo order total.');
        }
        if (method_exists($order, 'save')) {
            $order->save();
        }
    }

    /**
     * @param array<string, mixed> $version
     * @param array<string, mixed> $executionPayload
     */
    private function resolveProposalTotal(array $version, array $executionPayload): ?float
    {
        $versionId = (int) ($version['id'] ?? 0);
        $total = 0.0;
        $priced = 0;
        if ($versionId > 0) {
            foreach ($this->repository->listQuoteLines($versionId) as $line) {
                if (isset($line['line_total_snapshot']) && is_numeric($line['line_total_snapshot'])) {
                    $total += (float) $line['line_total_snapshot'];
                    $priced++;
                }
            }
        }
        if ($priced > 0) {
            return round($total, 2);
        }

        $totals = is_array($executionPayload['totals'] ?? null) ? $executionPayload['totals'] : array();
        foreach (array('display_total', 'total', 'grand_total') as $key) {
            if (isset($totals[$key]) && is_numeric($totals[$key])) {
                return round((float) $totals[$key], 2);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $executionPayload
     */
    private function resolveExpectedWooTotal(array $executionPayload, int $participants): ?float
    {
        $items = is_array($executionPayload['items'] ?? null) ? $executionPayload['items'] : array();
        $total = 0.0;
        $priced = 0;
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $pricing = is_array($item['sbdp_pricing'] ?? null) ? $item['sbdp_pricing'] : array();
            $explicitWooPrice = null;
            foreach (array('woo_unit_price', 'product_unit_price') as $key) {
                if (isset($pricing[$key]) && is_numeric($pricing[$key])) {
                    $explicitWooPrice = (float) $pricing[$key];
                    break;
                }
            }

            if ($explicitWooPrice !== null) {
                $price = $explicitWooPrice;
            } else {
                $product = function_exists('wc_get_product') ? \wc_get_product($productId) : null;
                if (! $product || ! method_exists($product, 'get_price')) {
                    return null;
                }
                $price = (float) $product->get_price();
            }
            $quantity = (int) ($item['participants'] ?? 0);
            if ($quantity <= 0) {
                $quantity = $participants > 0 ? $participants : (int) ($item['quantity'] ?? 1);
            }
            $total += $price * max(1, $quantity);
            $priced++;
        }

        return $priced > 0 ? round($total, 2) : null;
    }

    /**
     * @param array<string, mixed> $executionPayload
     */
    private function hasExplicitWooPricing(array $executionPayload): bool
    {
        $items = is_array($executionPayload['items'] ?? null) ? $executionPayload['items'] : array();
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $pricing = is_array($item['sbdp_pricing'] ?? null) ? $item['sbdp_pricing'] : array();
            foreach (array('woo_unit_price', 'product_unit_price') as $key) {
                if (isset($pricing[$key]) && is_numeric($pricing[$key])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $executionPayload
     */
    private function currency(array $executionPayload): string
    {
        $totals = is_array($executionPayload['totals'] ?? null) ? $executionPayload['totals'] : array();
        $currency = trim((string) ($totals['currency'] ?? ''));
        if ($currency !== '') {
            return $currency;
        }

        return function_exists('get_woocommerce_currency') ? (string) \get_woocommerce_currency() : 'EUR';
    }

    private function now(): string
    {
        return \function_exists('current_time')
            ? (string) \current_time('mysql', true)
            : gmdate('Y-m-d H:i:s');
    }
}
