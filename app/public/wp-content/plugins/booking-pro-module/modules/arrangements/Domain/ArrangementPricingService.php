<?php

declare(strict_types=1);

namespace SBDP\Modules\Arrangements\Domain;

use SBDP\Pricing\SelectionPricing;

use function array_map;
use function array_sum;
use function class_exists;
use function function_exists;
use function in_array;
use function is_array;
use function max;
use function round;
use function sanitize_key;

final class ArrangementPricingService
{
    /**
     * @param array<string, mixed> $arrangement
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function quote(array $arrangement, int $participants = 1, array $context = array()): array
    {
        $participants = max(1, $participants);
        $strategy = sanitize_key((string) ($arrangement['price_strategy'] ?? 'sum_children'));
        if (! in_array($strategy, ArrangementSchema::PRICE_STRATEGIES, true)) {
            $strategy = 'sum_children';
        }

        $salesProductId = (int) ($arrangement['sales_product_id'] ?? 0);
        $segments = is_array($arrangement['segments'] ?? null) ? array_values($arrangement['segments']) : array();
        $basePrice = (float) ($arrangement['base_price'] ?? 0.0);
        $currency = strtoupper((string) ($arrangement['currency'] ?? 'EUR'));
        $date = isset($context['date']) && is_string($context['date']) ? $context['date'] : '';
        $start = isset($context['start']) && is_string($context['start']) ? $context['start'] : '';
        $resourceId = isset($context['resource_id']) ? (int) $context['resource_id'] : 0;

        $baseQuote = array();
        if ($salesProductId > 0 && class_exists(SelectionPricing::class)) {
            $baseQuote = SelectionPricing::quote(
                $salesProductId,
                $participants,
                $start,
                $resourceId,
                array(),
                array_merge(
                    $context,
                    array(
                        'channel' => 'arrangement',
                        'source' => 'arrangement_pricing',
                        'price_mode' => 'gross',
                        'date' => $date,
                    )
                )
            );
        }

        $segmentQuotes = array();
        foreach ($segments as $segment) {
            if (! is_array($segment) || ! empty($segment['is_hidden'])) {
                continue;
            }

            $linkedProductId = (int) ($segment['linked_product_id'] ?? 0);
            $segmentPrice = (float) ($segment['pricing']['price'] ?? $segment['pricing']['total'] ?? 0.0);

            if ($linkedProductId > 0 && class_exists(SelectionPricing::class) && function_exists('wc_get_product')) {
                $segmentQuotes[] = SelectionPricing::quote(
                    $linkedProductId,
                    $participants,
                    $start,
                    (int) ($segment['linked_resource_id'] ?? 0),
                    array(),
                    array_merge(
                        $context,
                        array(
                            'channel' => 'arrangement',
                            'source' => 'arrangement_pricing_segment',
                            'price_mode' => 'gross',
                            'date' => $date,
                        )
                    )
                );
            } elseif ($segmentPrice > 0.0) {
                $segmentQuotes[] = array(
                    'total' => round($segmentPrice, 2),
                    'unit_price' => round($segmentPrice / $participants, 2),
                    'display_total' => round($segmentPrice, 2),
                    'display_unit_price' => round($segmentPrice / $participants, 2),
                    'currency' => $currency,
                );
            }
        }

        $childTotal = (float) array_sum(array_map(static fn (array $quote): float => (float) ($quote['total'] ?? 0.0), $segmentQuotes));
        $childDisplayTotal = (float) array_sum(array_map(static fn (array $quote): float => (float) ($quote['display_total'] ?? $quote['total'] ?? 0.0), $segmentQuotes));
        $baseTotal = (float) ($baseQuote['total'] ?? $basePrice);
        $baseDisplayTotal = (float) ($baseQuote['display_total'] ?? $baseTotal);
        $discount = 0.0;

        if ($strategy === 'sum_children') {
            $total = $childTotal;
            $displayTotal = $childDisplayTotal;
        } elseif ($strategy === 'sum_children_minus_discount') {
            $discount = $this->resolveDiscount($arrangement, $childTotal);
            $total = max(0.0, $childTotal - $discount);
            $displayTotal = max(0.0, $childDisplayTotal - $discount);
        } elseif ($strategy === 'fixed_bundle_price') {
            $total = $basePrice > 0.0 ? $basePrice : $baseTotal;
            $displayTotal = $basePrice > 0.0 ? $basePrice : $baseDisplayTotal;
        } else {
            $total = $baseTotal + $childTotal;
            $displayTotal = $baseDisplayTotal + $childDisplayTotal;
        }

        if ($total <= 0.0) {
            $total = $baseTotal + $childTotal;
        }
        if ($displayTotal <= 0.0) {
            $displayTotal = $baseDisplayTotal + $childDisplayTotal;
        }

        $unitPrice = round($total / $participants, 2);
        $displayUnitPrice = round($displayTotal / $participants, 2);

        return array(
            'strategy' => $strategy,
            'currency' => $currency,
            'participants' => $participants,
            'base_total' => round($baseTotal, 2),
            'base_display_total' => round($baseDisplayTotal, 2),
            'child_total' => round($childTotal, 2),
            'child_display_total' => round($childDisplayTotal, 2),
            'discount' => round($discount, 2),
            'total' => round($total, 2),
            'unit_price' => $unitPrice,
            'unitPrice' => $unitPrice,
            'display_total' => round($displayTotal, 2),
            'display_unit_price' => $displayUnitPrice,
            'display_per_person' => $displayUnitPrice,
            'per_person' => $unitPrice,
            'base_quote' => $baseQuote,
            'segment_quotes' => $segmentQuotes,
            'segments' => $segments,
        );
    }

    /**
     * @param array<string, mixed> $arrangement
     */
    private function resolveDiscount(array $arrangement, float $childTotal): float
    {
        $rules = is_array($arrangement['rules'] ?? null) ? $arrangement['rules'] : array();
        foreach ($rules as $rule) {
            if (! is_array($rule) || (string) ($rule['type'] ?? '') !== 'discount') {
                continue;
            }

            if (isset($rule['value']) && is_numeric($rule['value'])) {
                $value = (float) $rule['value'];
                if (($rule['mode'] ?? '') === 'percent') {
                    return max(0.0, $childTotal * ($value / 100.0));
                }

                return max(0.0, $value);
            }
        }

        $basePrice = (float) ($arrangement['base_price'] ?? 0.0);
        if ($basePrice > 0.0 && $childTotal > $basePrice) {
            return $childTotal - $basePrice;
        }

        return 0.0;
    }
}
