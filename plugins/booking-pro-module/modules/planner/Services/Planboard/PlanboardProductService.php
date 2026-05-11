<?php

declare(strict_types=1);

namespace BSP\Planner\Services\Planboard;

use SBDP\Modules\Planner\Services\PlannerService;

/**
 * Provide planner product catalog for the planboard UI.
 */
final class PlanboardProductService
{
    private PlannerService $planner;

    public function __construct(?PlannerService $planner = null)
    {
        $this->planner = $planner ?? new PlannerService();
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(array $filters = array()): array
    {
        $products = $this->planner->listProducts($filters);
        if (! is_array($products)) {
            return array();
        }

        return array_values(array_map(array($this, 'normalizeProduct'), $products));
    }

    /**
     * @param array<string, mixed> $product
     *
     * @return array<string, mixed>
     */
    private function normalizeProduct(array $product): array
    {
        $rawResources = $product['resources'] ?? array();
        $resourceCandidates = array();

        if (is_array($rawResources)) {
            if (isset($rawResources['items']) && is_array($rawResources['items'])) {
                $resourceCandidates = $rawResources['items'];
            } elseif (isset($rawResources['summary']['items']) && is_array($rawResources['summary']['items'])) {
                $resourceCandidates = $rawResources['summary']['items'];
            } elseif ($rawResources !== array()) {
                $resourceCandidates = $rawResources;
            }
        }

        $normalizedResources = array();
        foreach ($resourceCandidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $resourceId = isset($candidate['id']) ? (int) $candidate['id'] : (isset($candidate['resource_id']) ? (int) $candidate['resource_id'] : 0);
            if ($resourceId <= 0) {
                continue;
            }
            $normalizedResources[] = array(
                'id'       => $resourceId,
                'name'     => (string) ($candidate['name'] ?? $candidate['label'] ?? $candidate['title'] ?? ''),
                'capacity' => isset($candidate['capacity']) ? (int) $candidate['capacity'] : null,
            );
        }

        $resourceId = isset($product['resource_id']) ? (int) $product['resource_id'] : 0;
        if ($resourceId <= 0 && ! empty($normalizedResources)) {
            $resourceId = (int) ($normalizedResources[0]['id'] ?? 0);
        }

        return array(
            'id'          => (int) ($product['id'] ?? ($product['product_id'] ?? 0)),
            'name'        => (string) ($product['name'] ?? ($product['title'] ?? '')),
            'resource_id' => $resourceId,
            'resources'   => $normalizedResources,
            'pricing'     => $product['pricing'] ?? array(),
            'price_pp'    => isset($product['price_pp']) ? (float) $product['price_pp'] : null,
            'combos'      => $product['combos'] ?? array(),
            'calendar_blocks'    => $product['calendar_blocks'] ?? array(),
            'calendar_last_sync' => $product['calendar_last_sync'] ?? null,
            'calendar_status'    => $product['calendar_status'] ?? '',
        );
    }
}

if (! class_exists('BSPModule\\Planner\\Services\\Planboard\\PlanboardProductService', false)) {
    class_alias(PlanboardProductService::class, 'BSPModule\\Planner\\Services\\Planboard\\PlanboardProductService');
}
