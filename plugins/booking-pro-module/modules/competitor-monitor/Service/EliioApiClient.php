<?php

declare(strict_types=1);

namespace BSP\CompetitorMonitor\Service;

/**
 * Fetches public product/calendar data from the Eliio widget API.
 *
 * The API requires no authentication — all endpoints are publicly
 * accessible via the widget embed. We use wp_remote_get() so WP's
 * HTTP API handles proxy/SSL transparently.
 */
final class EliioApiClient
{
    private const BASE_URL  = 'https://app-be-booking.eliio.com/widget';
    private const TIMEOUT   = 15;

    /** @var array<string, string> */
    private array $tenants;

    /**
     * @param array<string, string> $tenants  ['label' => 'tenant-uuid', ...]
     */
    public function __construct(array $tenants)
    {
        $this->tenants = $tenants;
    }

    /**
     * Fetch all products for every registered tenant.
     *
     * @return array<string, list<array<string, mixed>>>  keyed by tenant label
     */
    public function fetchAllProducts(): array
    {
        $result = [];
        foreach ($this->tenants as $label => $tenantId) {
            $result[$label] = $this->fetchProducts($tenantId);
        }
        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchProducts(string $tenantId): array
    {
        $url = \add_query_arg(
            ['page' => '1', 'limit' => '100'],
            self::BASE_URL . '/product/v2/' . \rawurlencode($tenantId)
        );

        $response = \wp_remote_get($url, ['timeout' => self::TIMEOUT]);

        if (\is_wp_error($response)) {
            return [];
        }

        $code = (int) \wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return [];
        }

        $body = \wp_remote_retrieve_body($response);
        $decoded = \json_decode($body, true);

        if (! \is_array($decoded) || ! isset($decoded['data']) || ! \is_array($decoded['data'])) {
            return [];
        }

        return $decoded['data'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchCategories(string $tenantId): array
    {
        $url = self::BASE_URL . '/categories/' . \rawurlencode($tenantId);
        $response = \wp_remote_get($url, ['timeout' => self::TIMEOUT]);

        if (\is_wp_error($response)) {
            return [];
        }

        $body = \wp_remote_retrieve_body($response);
        $decoded = \json_decode($body, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
