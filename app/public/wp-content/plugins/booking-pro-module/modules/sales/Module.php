<?php
declare(strict_types=1);

namespace BSP\Sales;

use BSP\Core\CoreServiceProvider;
use BSP\Core\Interfaces\ModuleInterface;
use BSP\Sales\LegacyModule;
use BSP\Sales\Rest\Controller as RestController;
use BSP\Sales\DynamicPricingService;

/**
 * Sales analytics and promotion helper module.
 */
final class Module implements ModuleInterface
{
    // Bridge to legacy service BSPModule\Sales_Legacy_Service
    private static bool $legacyBooted = false;

    private LegacyModule $legacy;

    private DynamicPricingService $pricing;

    public function __construct(?LegacyModule $legacy = null, ?DynamicPricingService $pricing = null)
    {
        $this->legacy  = $legacy ?? new LegacyModule();
        $this->pricing = $pricing ?? new DynamicPricingService(CoreServiceProvider::logger());
    }

    /**
     * Register REST routes and record module bootstrap.
     */
    public function init(): void
    {
        CoreServiceProvider::logger()->log('Sales module initialized');

        if (\function_exists('add_action')) {
            \add_action('rest_api_init', [RestController::class, 'register']);
        }

        if (\function_exists('add_filter')) {
            \add_filter('sbdp_sales_dynamic_price', [$this, 'filterDynamicPrice'], 10, 2);
        }

        if (! self::$legacyBooted) {
            $this->legacy->register();
            self::$legacyBooted = true;
        }

        $legacyFile = null;
        if (\defined('SBDP_DIR')) {
            $legacyFile = \rtrim(\SBDP_DIR, '/\\') . '/includes/legacy/class-sales-legacy-service.php';
        } else {
            $legacyFile = \dirname(__DIR__, 2) . '/includes/legacy/class-sales-legacy-service.php';
        }

        if ($legacyFile && \is_readable($legacyFile)) {
            require_once $legacyFile;
        } else {
            CoreServiceProvider::logger()->log(
                'Sales legacy bridge skipped: missing file ' . ($legacyFile ?? '(unknown)')
            );
        }

        if (\class_exists('BSPModule\\Sales_Legacy_Service')) {
            try {
                $legacyService = new \BSPModule\Sales_Legacy_Service();

                if (\method_exists($legacyService, 'boot')) {
                    $legacyService->boot();
                }
            } catch (\Throwable $throwable) {
                CoreServiceProvider::logger()->log(
                    'Sales legacy bridge failed: ' . $throwable->getMessage()
                );
            }

            return;
        }

        CoreServiceProvider::logger()->log(
            'Sales legacy bridge inactive: BSPModule\\Sales_Legacy_Service not available.'
        );
    }

    /**
     * Persist dynamic pricing configuration.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function configureDynamicPricing(array $payload): array
    {
        return $this->pricing->configure($payload);
    }

    /**
     * Retrieve the persisted dynamic pricing configuration.
     *
     * @return array<string, mixed>
     */
    public function getDynamicPricingConfiguration(): array
    {
        return $this->pricing->getConfiguration();
    }

    /**
     * Produce pricing adjustments for the supplied context.
     *
     * @param array<string, mixed> $signals
     *
     * @return array{price: float, adjustments: array<int, array<string, mixed>>}
     */
    public function generateDynamicPrice(float $basePrice, array $signals = []): array
    {
        return $this->pricing->calculate($basePrice, $signals);
    }

    /**
     * Filter hook used to adjust prices via WordPress filters.
     *
     * @param array<string, mixed> $signals
     */
    public function filterDynamicPrice(float $price, array $signals = []): float
    {
        $result = $this->generateDynamicPrice($price, $signals);

        return $result['price'];
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

if (! \class_exists('BSPModule\\Sales\\Module', false)) {
    \class_alias(Module::class, 'BSPModule\\Sales\\Module');
}
