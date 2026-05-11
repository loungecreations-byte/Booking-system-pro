<?php

declare(strict_types=1);

namespace SBDP\Modules\Pricing;

use BSP\Sales\Pricing\YieldEngine;
use SBDP\BookingEngine;
use SBDP\Contracts\ModuleInterface;
use SBDP\Modules\Pricing\Rest\PricingRoutes;
use SBDP\Modules\Pricing\Services\PricingService;
use function __;

final class Module implements ModuleInterface
{
    private PricingService $service;
    private PricingRoutes $routes;
    private bool $registered = false;

    /**
     * @var array<int, array<int, array<string, mixed>>>
     */
    private array $appliedRules = array();

    public function __construct(?PricingService $service = null, ?PricingRoutes $routes = null)
    {
        $this->service = $service ?? new PricingService();
        $this->routes  = $routes ?? new PricingRoutes($this->service);
    }

    public function register(BookingEngine $engine): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;

        $dispatcher = $engine->getDispatcher();

        $dispatcher->on(
            'pricing.calculate',
            function (array $payload): array {
                $items = array();

                if (isset($payload['items']) && is_array($payload['items'])) {
                    $items = $payload['items'];
                } elseif (isset($payload['product_id'])) {
                    $items[] = array(
                        'product_id' => (int) $payload['product_id'],
                        'quantity'   => isset($payload['quantity']) ? (int) $payload['quantity'] : 1,
                    );
                }

                $channel = isset($payload['channel']) ? (string) $payload['channel'] : 'web';

                $quotes = array();
                $errors = array();
                $total  = 0.0;
                $currency = $payload['currency'] ?? null;

                foreach ($items as $item) {
                    $product_id = isset($item['product_id']) ? (int) $item['product_id'] : 0;
                    $quantity   = isset($item['quantity']) ? (int) $item['quantity'] : 1;

                    if ($product_id <= 0) {
                        $errors[] = 'missing_product_id';
                        continue;
                    }

                    $quote = $this->service->quote(
                        $product_id,
                        $quantity > 0 ? $quantity : 1,
                        array_merge(
                            $payload,
                            array(
                                'channel' => $channel,
                            )
                        )
                    );

                    if (! ($quote['success'] ?? false)) {
                        $errors[] = $quote['error'] ?? 'pricing_failed';
                        continue;
                    }

                    $quotes[] = $quote;
                    $total   += (float) ($quote['total_adjusted'] ?? 0.0);
                    $currency = $currency ?? ($quote['currency'] ?? null);
                }

                $payload['pricing'] = array(
                    'quotes'   => $quotes,
                    'total'    => \round($total, 2),
                    'currency' => $currency,
                );

                if (! empty($errors)) {
                    $payload['pricing_errors'] = $errors;
                }

                if (! empty($quotes)) {
                    $payload['total']   = \round($total, 2);
                    $payload['currency'] = $currency ?? $payload['currency'] ?? null;
                }

                return $payload;
            }
        );

        add_filter(
            'sbdp/pricing/booking_channel_modifiers',
            array($this, 'applyBookingChannelModifiers'),
            10,
            3
        );

        add_filter(
            'sbdp/pricing/plan_channel_modifiers',
            array($this, 'applyPlanChannelModifiers'),
            10,
            5
        );

        add_filter(
            'sbdp/pricing/quote',
            array($this, 'enrichQuoteMeta'),
            10,
            2
        );

        add_action('rest_api_init', array($this->routes, 'register'));
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $monetary
     * @param array<string, mixed>                            $lineItem
     * @param array<string, mixed>                            $context
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function applyBookingChannelModifiers(array $monetary, array $lineItem, array $context): array
    {
        $productId = isset($lineItem['product_id']) ? (int) $lineItem['product_id'] : 0;
        if ($productId <= 0) {
            return $monetary;
        }

        $participants = isset($lineItem['participants']) ? (int) $lineItem['participants'] : 1;
        if ($participants <= 0) {
            $participants = 1;
        }

        $channel = isset($context['channel']) ? (string) $context['channel'] : 'web';

        $quote = $this->legacyQuote(
            $productId,
            $participants,
            array_merge($context, array('channel' => $channel))
        );

        if ($quote === null) {
            return $monetary;
        }

        $currentSubtotal = (float) ($lineItem['line_subtotal'] ?? 0.0);
        $currentAdjustments = $this->sumMonetary($monetary['adjustments'] ?? array());
        $currentDiscounts = $this->sumMonetary($monetary['discounts'] ?? array());
        $currentTaxes = $this->sumMonetary($monetary['taxes'] ?? array());

        $currentTotal = round($currentSubtotal + $currentAdjustments + $currentTaxes - $currentDiscounts, 2);
        $targetTotal = round((float) ($quote['total_adjusted'] ?? 0.0), 2);

        $delta = round($targetTotal - $currentTotal, 2);
        if (abs($delta) < 0.01) {
            $this->captureAppliedRules($productId, $quote['applied_rules'] ?? array());

            return $monetary;
        }

        if (! isset($monetary['adjustments']) || ! is_array($monetary['adjustments'])) {
            $monetary['adjustments'] = array();
        }
        if (! isset($monetary['discounts']) || ! is_array($monetary['discounts'])) {
            $monetary['discounts'] = array();
        }

        $entry = array(
            'code'  => 'channel_yield',
            'label' => __('Channel pricing adjustment', 'sbdp'),
            'amount'=> abs($delta),
            'meta'  => array(
                'channel'       => $channel,
                'applied_rules' => $quote['applied_rules'] ?? array(),
            ),
        );

        if ($delta > 0) {
            $monetary['adjustments'][] = $entry;
        } else {
            $monetary['discounts'][] = $entry;
        }

        $this->captureAppliedRules($productId, $quote['applied_rules'] ?? array());

        return $monetary;
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $monetary
     * @param array<int, array<string, mixed>>                $lineItems
     * @param array<string, mixed>                            $context
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function applyPlanChannelModifiers(
        array $monetary,
        array $lineItems,
        int $participants,
        array $productMap,
        array $context
    ): array {
        unset($productMap);

        $channel = isset($context['channel']) ? (string) $context['channel'] : 'web';

        $currentSubtotal = 0.0;
        foreach ($lineItems as $lineItem) {
            if (is_array($lineItem)) {
                $currentSubtotal += (float) ($lineItem['line_subtotal'] ?? 0.0);
            }
        }

        $currentAdjustments = $this->sumMonetary($monetary['adjustments'] ?? array());
        $currentDiscounts = $this->sumMonetary($monetary['discounts'] ?? array());
        $currentTaxes = $this->sumMonetary($monetary['taxes'] ?? array());

        $targetTotal = 0.0;
        $rules = array();

        foreach ($lineItems as $lineItem) {
            if (! is_array($lineItem)) {
                continue;
            }

            $productId = isset($lineItem['product_id']) ? (int) $lineItem['product_id'] : 0;
            if ($productId <= 0) {
                continue;
            }

            $itemParticipants = isset($lineItem['participants']) ? (int) $lineItem['participants'] : $participants;
            if ($itemParticipants <= 0) {
                $itemParticipants = 1;
            }

            $quote = $this->legacyQuote(
                $productId,
                $itemParticipants,
                array_merge($context, array('channel' => $channel))
            );

            if ($quote === null) {
                continue;
            }

            $targetTotal += (float) ($quote['total_adjusted'] ?? 0.0);
            $rules = $this->mergeRules($rules, $quote['applied_rules'] ?? array());
            $this->captureAppliedRules($productId, $quote['applied_rules'] ?? array());
        }

        $currentTotal = round($currentSubtotal + $currentAdjustments + $currentTaxes - $currentDiscounts, 2);
        $targetTotal = round($targetTotal, 2);

        $delta = round($targetTotal - $currentTotal, 2);
        if (abs($delta) < 0.01) {
            return $monetary;
        }

        if (! isset($monetary['adjustments']) || ! is_array($monetary['adjustments'])) {
            $monetary['adjustments'] = array();
        }
        if (! isset($monetary['discounts']) || ! is_array($monetary['discounts'])) {
            $monetary['discounts'] = array();
        }

        $entry = array(
            'code'  => 'channel_yield_plan',
            'label' => __('Channel pricing adjustment', 'sbdp'),
            'amount'=> abs($delta),
            'meta'  => array(
                'channel'       => $channel,
                'applied_rules' => $rules,
            ),
        );

        if ($delta > 0) {
            $monetary['adjustments'][] = $entry;
        } else {
            $monetary['discounts'][] = $entry;
        }

        return $monetary;
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function enrichQuoteMeta(array $quote, array $context): array
    {
        unset($context);

        if (! isset($quote['meta']) || ! is_array($quote['meta'])) {
            $quote['meta'] = array();
        }

        $productId = isset($quote['product_id']) ? (int) $quote['product_id'] : 0;

        if ($productId > 0 && isset($this->appliedRules[$productId])) {
            $quote['meta']['applied_rules'] = array_values($this->appliedRules[$productId]);
            unset($this->appliedRules[$productId]);
        } elseif (! isset($quote['meta']['applied_rules'])) {
            $quote['meta']['applied_rules'] = array();
        }

        return $quote;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function legacyQuote(int $productId, int $participants, array $context): ?array
    {
        $participants = max(1, $participants);

        if (! function_exists('wc_get_product')) {
            return null;
        }

        $product = wc_get_product($productId);
        if (! $product instanceof \WC_Product) {
            return null;
        }

        $base = (float) $product->get_price('edit');
        if ($base <= 0.0) {
            $base = (float) $product->get_regular_price('edit');
        }

        $adjusted = YieldEngine::calculateAdjustedPrice($product, $base);
        $total = round($adjusted * $participants, 2);

        $rules = YieldEngine::getMatchedRules();

        return array(
            'success'        => true,
            'product_id'     => $productId,
            'quantity'       => $participants,
            'participants'   => $participants,
            'currency'       => function_exists('get_option') ? (string) get_option('woocommerce_currency', 'EUR') : 'EUR',
            'base_price'     => round($base, 2),
            'adjusted_price' => round($adjusted, 2),
            'total_adjusted' => $total,
            'unit_subtotal'  => round($base, 2),
            'unit_total'     => round($adjusted, 2),
            'unit'           => array(
                'subtotal' => round($base, 2),
                'total'    => round($adjusted, 2),
            ),
            'applied_rules'  => $rules,
            'channel'        => isset($context['channel']) ? (string) $context['channel'] : 'web',
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function sumMonetary(array $rows): float
    {
        $sum = 0.0;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $sum += (float) ($row['amount'] ?? 0.0);
        }

        return round($sum, 2);
    }

    /**
     * @param array<int, array<string, mixed>> $existing
     * @param array<int, array<string, mixed>> $incoming
     *
     * @return array<int, array<string, mixed>>
     */
    private function mergeRules(array $existing, array $incoming): array
    {
        if ($incoming === array()) {
            return $existing;
        }

        $index = array();

        foreach ($existing as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $key = $this->ruleKey($rule);
            $index[$key] = $rule;
        }

        foreach ($incoming as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $key = $this->ruleKey($rule);
            $index[$key] = $rule;
        }

        return array_values($index);
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     */
    private function captureAppliedRules(int $productId, array $rules): void
    {
        if ($rules === array()) {
            return;
        }

        $existing = $this->appliedRules[$productId] ?? array();
        $this->appliedRules[$productId] = $this->mergeRules($existing, $rules);
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function ruleKey(array $rule): string
    {
        $id = isset($rule['id']) ? (int) $rule['id'] : 0;
        if ($id > 0) {
            return (string) $id;
        }

        return strtolower(trim((string) ($rule['name'] ?? 'rule')));
    }
}
