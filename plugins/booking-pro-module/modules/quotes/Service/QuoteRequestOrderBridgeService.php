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
            return $this->existingOrderPayload($quoteId, $quote, $existingOrderId);
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

        $versionId = isset($quote['current_version_id']) ? (int) $quote['current_version_id'] : 0;
        if ($versionId <= 0) {
            throw new HandoffValidationException(
                'bsp_quotes_handoff_missing_version',
                'Quote heeft geen actieve versie.',
                422,
                array('quote_id' => $quoteId)
            );
        }

        $version = $this->repository->findQuoteVersion($versionId);
        if ($version === null) {
            throw new HandoffValidationException(
                'bsp_quotes_handoff_version_not_found',
                'Actieve quote-versie niet gevonden.',
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
            $composeRequest->set_body(wp_json_encode($composePayload));
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

        $updatedQuote = $this->repository->updateQuote($quoteId, array(
            'woo_order_id' => $orderId,
            'handoff_status' => 'woo_request_order_created',
            'updated_at' => $this->now(),
        ));

        $this->events->log(
            'quote_woo_request_order_created',
            $requestId,
            $quoteId,
            isset($updatedQuote['current_version_id']) ? (int) $updatedQuote['current_version_id'] : null,
            $actorId,
            'Woo request-order aangemaakt vanuit quote.',
            array(
                'order_id' => $orderId,
                'force' => $force,
            )
        );

        return array(
            'quote_id' => $quoteId,
            'quote_reference' => (string) ($updatedQuote['quote_reference'] ?? ''),
            'woo_order_id' => $orderId,
            'redirect' => (string) ($result['redirect'] ?? ''),
            'view_url' => (string) ($result['view_url'] ?? ''),
            'created' => true,
        );
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

                $participants = (int) ($item['participants'] ?? $item['quantity'] ?? 0);
                if ($participants > 0) {
                    return $participants;
                }
            }
        }

        return 1;
    }

    /**
     * @param array<string, mixed> $quote
     * @return array<string, mixed>
     */
    private function existingOrderPayload(int $quoteId, array $quote, int $orderId): array
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
            'quote_reference' => (string) ($quote['quote_reference'] ?? ''),
            'woo_order_id' => $orderId,
            'view_url' => $viewUrl,
            'created' => false,
        );
    }

    private function now(): string
    {
        return \function_exists('current_time')
            ? (string) \current_time('mysql', true)
            : gmdate('Y-m-d H:i:s');
    }
}
