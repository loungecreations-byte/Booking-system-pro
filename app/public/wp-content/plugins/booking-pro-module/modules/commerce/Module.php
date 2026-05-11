<?php
declare(strict_types=1);

namespace BSP\Commerce;

use BSP\Commerce\Rest\Controller as RestController;
use BSP\Core\CoreServiceProvider;
use BSP\Core\Interfaces\ModuleInterface;

if (! class_exists(__NAMESPACE__ . '\\Module', false)) {
    /**
     * Commerce module handling pricing, couponing, and order bookkeeping helpers.
     */
    final class Module implements ModuleInterface
    {
        /**
         * Initialise the module by wiring REST routes and emitting a log entry.
         */
        public function init(): void
        {
            CoreServiceProvider::logger()->log('Commerce module initialized');

            if (\function_exists('add_action')) {
                \add_action('rest_api_init', [RestController::class, 'register']);
            }
        }

        /**
         * Simulate order processing and return a status message.
         */
        public function processOrder(int $orderId): string
        {
            if ($orderId <= 0) {
                return 'Invalid order identifier provided';
            }

            return \sprintf('Processing order #%d', $orderId);
        }

        /**
         * Calculate a price by applying percentage or fixed rules to a base amount.
         *
         * @param array<int, array<string, mixed>> $rules
         */
        public function calculatePrice(float $base, array $rules = []): float
        {
            $price = $base;

            foreach ($rules as $rule) {
                $type  = (string) ($rule['type'] ?? '');
                $value = (float) ($rule['value'] ?? 0.0);

                if ($type === 'percent') {
                    $price += $base * ($value / 100.0);
                    continue;
                }

                if ($type === 'fixed') {
                    $price += $value;
                }
            }

            return \max(0.0, \round($price, 2));
        }

        /**
         * Apply coupon adjustments to each provided item.
         *
         * @param array<int, array<string, mixed>> $items
         * @param array<int, array<string, mixed>> $coupons
         *
         * @return array<int, array<string, mixed>>
         */
        public function applyCoupons(array $items, array $coupons): array
        {
            $updated = array();

            foreach ($items as $item) {
                $price = (float) ($item['price'] ?? 0.0);

                foreach ($coupons as $coupon) {
                    $type  = (string) ($coupon['type'] ?? '');
                    $value = (float) ($coupon['value'] ?? 0.0);

                    if ($type === 'percent') {
                        $price -= $price * ($value / 100.0);
                    } elseif ($type === 'fixed') {
                        $price -= $value;
                    }
                }

                $item['price'] = \max(0.0, \round($price, 2));
                $updated[]     = $item;
            }

            return $updated;
        }

        /**
         * Reserve inventory for a set of items (stub implementation).
         *
         * @param array<int, array<string, mixed>> $items
         */
        public function reserveInventory(array $items): bool
        {
            return ! empty($items);
        }

        /**
         * Stubbed persistence layer for order metadata.
         *
         * @param array<string, mixed> $meta
         */
        public function saveOrderMeta(int $orderId, array $meta): bool
        {
            return $orderId > 0 && ! empty($meta);
        }

        /**
         * Retrieve an order status label.
         */
        public function getOrderStatus(int $orderId): string
        {
            return $orderId > 0 ? 'processing' : 'unknown';
        }
    }
}

