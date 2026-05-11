<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use InvalidArgumentException;

final class QuoteWooCartHydrationService
{
    public function __construct(
        private WooCartLaunchGatewayInterface $gateway,
        private \BSP\Quotes\Repository\QuoteRepositoryInterface $repository,
        private QuoteEventLogger $events
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function hydrateLaunchToCart(int $quoteId, string $launchToken, ?int $actorId = null): array
    {
        $quote = $this->repository->findQuote($quoteId);
        if ($quote === null) {
            throw new InvalidArgumentException('Quote not found.');
        }

        if ((string) ($quote['handoff_status'] ?? 'not_ready') !== 'execution_launch_ready') {
            throw new InvalidArgumentException('Woo cart hydration vereist eerst een execution launch payload.');
        }

        // Handoff MUST read approved_version_id (pinned at acceptance), never current_version_id.
        $versionId = (int) ($quote['approved_version_id'] ?? 0);
        if ($versionId <= 0) {
            throw new InvalidArgumentException('Quote heeft geen geaccepteerde versie (approved_version_id ontbreekt). Accepteer de quote eerst.');
        }

        $version = $this->repository->findQuoteVersion($versionId);
        if ($version === null) {
            throw new InvalidArgumentException('Geaccepteerde quote-versie niet gevonden in database.');
        }

        $handoffPayload = is_array($version['handoff_payload_json'] ?? null)
            ? $version['handoff_payload_json']
            : array();
        $launchPayload = isset($handoffPayload['execution_launch']) && is_array($handoffPayload['execution_launch'])
            ? $handoffPayload['execution_launch']
            : array();

        if (($launchPayload['launch_type'] ?? '') !== 'woo_cart_session_prep') {
            throw new InvalidArgumentException('Geen execution launch payload gevonden.');
        }

        $expectedToken = (string) ($launchPayload['launch_token'] ?? '');
        if ($expectedToken === '' || ! hash_equals($expectedToken, $launchToken)) {
            throw new InvalidArgumentException('Execution launch token is ongeldig.');
        }

        $expiresAt = strtotime((string) ($launchPayload['expires_at'] ?? ''));
        if ($expiresAt === false || $expiresAt < time()) {
            throw new InvalidArgumentException('Execution launch token is verlopen.');
        }

        $result = $this->gateway->hydrate($launchPayload);
        $handoffPayload['hydration_result'] = array(
            'hydrated_at' => $this->now(),
            'result' => $result,
        );

        $this->repository->updateQuoteVersion($versionId, array(
            'handoff_payload_json' => $handoffPayload,
            'updated_at' => $this->now(),
        ));
        $this->repository->updateQuote($quoteId, array(
            'handoff_status' => 'woo_cart_hydrated',
            'updated_at' => $this->now(),
        ));

        $this->events->log(
            'quote_woo_cart_hydrated',
            isset($quote['quote_request_id']) ? (int) $quote['quote_request_id'] : null,
            $quoteId,
            $versionId,
            $actorId,
            'Execution launch payload naar Woo cart gehydrateerd.',
            array(
                'cart_item_count' => $result['cart_item_count'] ?? 0,
            )
        );

        return $result;
    }

    private function now(): string
    {
        return \function_exists('current_time')
            ? (string) \current_time('mysql', true)
            : gmdate('Y-m-d H:i:s');
    }
}
