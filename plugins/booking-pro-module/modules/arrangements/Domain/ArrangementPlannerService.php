<?php

declare(strict_types=1);

namespace SBDP\Modules\Arrangements\Domain;

use function array_values;
use function function_exists;
use function is_array;
use function max;

final class ArrangementPlannerService
{
    public function __construct(
        private ArrangementRepository $repository = new ArrangementRepository(),
        private ArrangementPricingService $pricing = new ArrangementPricingService(),
        private ArrangementAvailabilityService $availability = new ArrangementAvailabilityService()
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listPlannerProducts(array $filters = array()): array
    {
        $arrangements = array();
        foreach ($this->repository->query($filters) as $arrangement) {
            $product = $this->toPlannerProduct($arrangement, $filters);
            if (! is_array($product) || $product === array()) {
                continue;
            }

            $arrangements[] = $product;
        }

        return array_values($arrangements);
    }

    /**
     * @param array<string, mixed> $arrangement
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    public function toPlannerProduct(array $arrangement, array $context = array()): ?array
    {
        $salesProductId = (int) ($arrangement['sales_product_id'] ?? 0);
        $product = null;
        $canAddToCart = false;
        if ($salesProductId > 0 && function_exists('wc_get_product')) {
            $product = \wc_get_product($salesProductId);
            $canAddToCart = $product instanceof \WC_Product;
        }

        $participants = max(1, (int) ($context['participants'] ?? 1));
        $pricing = $this->pricing->quote($arrangement, $participants, $context);
        $availability = $this->availability->resolve($arrangement, $context);

        $model = ArrangementViewModel::forPlanner($arrangement, $pricing, $availability);
        $model['people'] = array(
            'enabled' => true,
            'min' => 1,
            'max' => max(1, (int) ($context['capacity'] ?? 999)),
        );
        $model['resource_id'] = (int) ($context['resource_id'] ?? 0);
        $model['availability_windows'] = is_array($availability['segments'] ?? null) ? array_values($availability['segments']) : array();
        $model['categories'] = is_array($arrangement['categories'] ?? null) ? array_values($arrangement['categories']) : array();
        $model['pricing']['currency'] = (string) ($pricing['currency'] ?? ($arrangement['currency'] ?? 'EUR'));
        $model['pricing']['price_strategy'] = (string) ($pricing['strategy'] ?? ($arrangement['price_strategy'] ?? 'sum_children'));
        $model['can_add_to_cart'] = $canAddToCart;
        $model['sales_product_id'] = $salesProductId;
        $model['has_sales_product'] = $salesProductId > 0;
        if ($product instanceof \WC_Product) {
            $model['sales_product_type'] = (string) $product->get_type();
        }

        return $model;
    }
}
