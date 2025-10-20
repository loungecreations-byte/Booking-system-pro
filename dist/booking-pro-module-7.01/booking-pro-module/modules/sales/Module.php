<?php
declare(strict_types=1);

namespace BSP\Sales;

use BSP\Core\CoreServiceProvider;
use BSP\Core\Interfaces\ModuleInterface;
use BSP\Sales\Rest\Controller as RestController;

/**
 * Sales analytics and promotion helper module.
 */
final class Module implements ModuleInterface
{
    /**
     * Register REST routes and record module bootstrap.
     */
    public function init(): void
    {
        CoreServiceProvider::logger()->log('Sales module initialized');

        if (\function_exists('add_action')) {
            \add_action('rest_api_init', [RestController::class, 'register']);
        }
    }

    /**
     * Calculate total revenue for the provided orders.
     *
     * @param array<int, array<string, mixed>> $orders
     */
    public function calculateRevenue(array $orders): float
    {
        $sum = 0.0;

        foreach ($orders as $order) {
            $sum += (float)($order['amount'] ?? 0.0);
        }

        return \round($sum, 2);
    }

    /**
     * Return the top products ranked by sold quantity.
     *
     * @param array<int, array<string, mixed>> $orderLines
     *
     * @return array<string, int>
     */
    public function topProducts(array $orderLines, int $limit = 3): array
    {
        $counter = [];

        foreach ($orderLines as $line) {
            $sku = (string)($line['sku'] ?? '');
            if ('' === $sku) {
                continue;
            }

            $counter[$sku] = ($counter[$sku] ?? 0) + (int)($line['qty'] ?? 0);
        }

        \arsort($counter);

        return \array_slice($counter, 0, $limit, true);
    }

    /**
     * Calculate conversion rate as a percentage.
     */
    public function conversionRate(int $visitors, int $orders): float
    {
        if ($visitors <= 0) {
            return 0.0;
        }

        return \round(($orders / $visitors) * 100, 2);
    }

    /**
     * Build a trimmed sales feed representation.
     *
     * @param array<int, array<string, mixed>> $orders
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildSalesFeed(array $orders): array
    {
        return \array_map(
            static function (array $order): array {
                return [
                    'id' => $order['id'] ?? null,
                    'amount' => (float)($order['amount'] ?? 0.0),
                    'ts' => $order['timestamp'] ?? null,
                ];
            },
            $orders
        );
    }

    /**
     * Execute promotion rules and return the discounted cart total.
     *
     * @param array<int, array<string, mixed>> $cart
     * @param array<int, array<string, mixed>> $rules
     *
     * @return array<string, float>
     */
    public function runPromotionEngine(array $cart, array $rules): array
    {
        $total = 0.0;

        foreach ($cart as $item) {
            $price = (float)($item['price'] ?? 0.0);
            $qty = (int)($item['qty'] ?? 1);
            $total += $price * \max(1, $qty);
        }

        foreach ($rules as $rule) {
            $type = (string)($rule['type'] ?? '');
            $value = (float)($rule['value'] ?? 0.0);

            if ('percent' === $type) {
                $total -= $total * ($value / 100.0);
                continue;
            }

            if ('fixed' === $type) {
                $total -= $value;
            }
        }

        return ['total' => \max(0.0, \round($total, 2))];
    }

    /**
     * Aggregate revenue per cohort month (YYYY-MM).
     *
     * @param array<int, array<string, mixed>> $orders
     *
     * @return array<string, float>
     */
    public function cohortRevenue(array $orders): array
    {
        $cohorts = [];

        foreach ($orders as $order) {
            $timestamp = (string)($order['timestamp'] ?? '');
            $cohortKey = '' === $timestamp ? 'unknown' : \substr($timestamp, 0, 7);
            $cohorts[$cohortKey] = ($cohorts[$cohortKey] ?? 0.0) + (float)($order['amount'] ?? 0.0);
        }

        \ksort($cohorts);

        foreach ($cohorts as $key => $value) {
            $cohorts[$key] = \round($value, 2);
        }

        return $cohorts;
    }
}
