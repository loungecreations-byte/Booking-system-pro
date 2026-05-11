<?php

declare(strict_types=1);

namespace BSP\DayPlanner\Service;

use BSPModule\Core\Rest\RestService;
use SBDP\Pricing\SelectionPricing;

final class PriceEngine
{
    /**
     * @param array<string, mixed> $pricing
     */
    private function resolveDisplayTotal(array $pricing): float
    {
        if (isset($pricing['display_total'])) {
            return (float) $pricing['display_total'];
        }

        return isset($pricing['total']) ? (float) $pricing['total'] : 0.0;
    }

    /**
     * @param array<string, mixed> $pricing
     */
    private function resolveDisplayUnitPrice(array $pricing): float
    {
        if (isset($pricing['display_unit_price'])) {
            return (float) $pricing['display_unit_price'];
        }

        if (isset($pricing['display_per_person'])) {
            return (float) $pricing['display_per_person'];
        }

        if (isset($pricing['unit_price'])) {
            return (float) $pricing['unit_price'];
        }

        return isset($pricing['per_person']) ? (float) $pricing['per_person'] : 0.0;
    }

    /**
     * @param array<string, mixed> $pricing
     */
    private function resolveDisplayAdjustment(array $pricing): float
    {
        if (isset($pricing['display_booking_adjustment'])) {
            return (float) $pricing['display_booking_adjustment'];
        }

        return isset($pricing['booking_adjustment']) ? (float) $pricing['booking_adjustment'] : 0.0;
    }

    /**
     * @param array<string, mixed> $plan
     */
    public function calculateTotals(array $plan): array
    {
        $plannerItems = isset($plan['meta']['planner_items']) && is_array($plan['meta']['planner_items'])
            ? $plan['meta']['planner_items']
            : [];
        if ($plannerItems !== []) {
            return $this->calculatePlannerItemTotals($plan, $plannerItems);
        }

        $slots  = $plan['days'] ?? [];
        $subtotal = 0.0;
        if (! isset($plan['participants_count']) && isset($plan['participants']) && is_array($plan['participants'])) {
            $plan['participants_count'] = count(array_filter($plan['participants']));
        }
        $people = $plan['participants'] ?? [];
        $slotBreakdown = [];

        foreach ($slots as $dayIndex => $day) {
            if (! isset($day['slots']) || ! is_array($day['slots'])) {
                continue;
            }

            $date = isset($day['date']) ? (string) $day['date'] : null;
            foreach ($day['slots'] as $slotIndex => $slot) {
                $pricing = $this->calculateSlotBreakdown($slot, $date, $plan);
                $slotBreakdown[$dayIndex][$slotIndex] = $pricing;
                $subtotal += $this->resolveDisplayTotal($pricing);
            }
        }

        $planFee = $this->applyPlanFeeFilter($plan, $slotBreakdown);
        $total = $subtotal + $planFee;
        $participantCount = is_array($people) ? count(array_filter($people)) : 0;

        $participantShare = $participantCount > 0 ? $subtotal / $participantCount : 0.0;

        $summary = [
            'subtotal'       => round($subtotal, 2),
            'booking_fee'    => round($planFee, 2),
            'total'          => round($total, 2),
            'participant_pp' => $subtotal > 0 && $participantCount > 0 ? round($participantShare, 2) : 0.0,
            'currency'       => $this->resolveCurrency(),
        ];

        return [
            'summary' => $summary,
            'slots'   => $slotBreakdown,
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<int, array<string, mixed>> $plannerItems
     * @return array<string, mixed>
     */
    private function calculatePlannerItemTotals(array $plan, array $plannerItems): array
    {
        $subtotal = 0.0;
        if (! isset($plan['participants_count']) && isset($plan['participants']) && is_array($plan['participants'])) {
            $plan['participants_count'] = count(array_filter($plan['participants']));
        }
        $currency = $this->resolveCurrency();
        $people = $plan['participants'] ?? [];
        $quotedPlannerItems = [];

        foreach ($plannerItems as $item) {
            if (! is_array($item)) {
                continue;
            }

            $productId = isset($item['productId'])
                ? (int) $item['productId']
                : (isset($item['product_id']) ? (int) $item['product_id'] : 0);
            if ($productId <= 0) {
                continue;
            }

            $participants = max(
                1,
                isset($item['participants']) ? (int) $item['participants'] : (int) ($plan['participants_count'] ?? 1)
            );
            $dayIndex = isset($item['dayIndex']) ? (int) $item['dayIndex'] : 0;
            $date = isset($item['date']) && is_string($item['date']) && trim($item['date']) !== ''
                ? trim((string) $item['date'])
                : (isset($plan['days'][$dayIndex]['date']) ? (string) $plan['days'][$dayIndex]['date'] : '');
            $startTime = isset($item['startTime']) && is_string($item['startTime']) && trim($item['startTime']) !== ''
                ? trim((string) $item['startTime'])
                : (isset($item['aggregate']['timeline']['startTime']) ? (string) $item['aggregate']['timeline']['startTime'] : '');
            $startIso = $this->composeStartIso($startTime, $date);
            $resourceId = isset($item['resourceId'])
                ? (int) $item['resourceId']
                : (isset($item['resource_id']) ? (int) $item['resource_id'] : 0);
            $combiItems = SelectionPricing::normaliseCombiItems(
                isset($item['options']) && is_array($item['options']) ? ($item['options']['combiItems'] ?? []) : []
            );

            $pricing = SelectionPricing::quote(
                $productId,
                $participants,
                $startIso,
                $resourceId,
                $combiItems,
                [
                    'channel' => 'day_planner',
                    'source'  => 'day_planner_price_engine',
                    'date'    => $date,
                ]
            );

            $itemTotal = $this->resolveDisplayTotal($pricing);
            $subtotal += $itemTotal;

            if ($currency === 'EUR') {
                $currency = (string) ($pricing['currency'] ?? $currency);
            }

            $quotedItem = $item;
            if (isset($quotedItem['aggregate']) && is_array($quotedItem['aggregate']) && isset($quotedItem['aggregate']['pricing'])) {
                unset($quotedItem['aggregate']['pricing']);
            }
            $quotedItem['pricing'] = $pricing;
            $quotedItem['totalCost'] = round($itemTotal, 2);
            $quotedItem['price_pp'] = $this->resolveDisplayUnitPrice($pricing);
            $quotedItem['fixedCost'] = $this->resolveDisplayAdjustment($pricing);
            $quotedItem['pricing_source'] = 'server';
            $quotedItem['serverQuoted'] = true;
            $quotedPlannerItems[] = $quotedItem;
        }

        // Aggregate-backed planner items already carry their full commercial total.
        // Do not add a second planner fee on top, or planner and cart diverge.
        $planFee = 0.0;
        $total = $subtotal;
        $participantCount = is_array($people) ? count(array_filter($people)) : 0;
        $participantShare = $participantCount > 0 ? $subtotal / $participantCount : 0.0;

        return [
            'summary' => [
                'subtotal'       => round($subtotal, 2),
                'booking_fee'    => round($planFee, 2),
                'total'          => round($total, 2),
                'participant_pp' => $subtotal > 0 && $participantCount > 0 ? round($participantShare, 2) : 0.0,
                'currency'       => $currency,
            ],
            'slots'   => [],
            'planner_items' => $quotedPlannerItems,
        ];
    }

    /**
     * @param array<string, mixed> $slot
     * @return array<string, mixed>
     */
    private function calculateSlotBreakdown(array $slot, ?string $dayDate, array $plan = []): array
    {
        $participants = max(1, (int) ($slot['people'] ?? $slot['participants'] ?? $plan['participants_count'] ?? 1));
        $productId    = (int) ($slot['product_id'] ?? $slot['activity_id'] ?? 0);
        $resourceId   = isset($slot['resource_id']) ? (int) $slot['resource_id'] : 0;
        $startRaw     = isset($slot['start']) ? (string) $slot['start'] : '';
        $startIso     = $this->composeStartIso($startRaw, $dayDate);

        if ($productId > 0 && \function_exists('wc_get_product')) {
            $product = \wc_get_product($productId);
            if ($product) {
                $calc = RestService::calculate_pricing_for_item(
                    $product,
                    $resourceId,
                    $startIso,
                    $participants,
                    [
                        'channel' => 'day_planner',
                        'source'  => 'day_planner_price_engine',
                        'price_mode' => 'gross',
                        'date'        => $dayDate,
                    ]
                );

                if (isset($calc['unit_price'])) {
                    $slot['price_pp'] = $calc['unit_price'];
                }

                $fixedCost = (float) ($calc['booking_adjustment'] ?? 0.0);
                $fixedCost += $this->sum_monetary_rows($calc['adjustments'] ?? []);
                $fixedCost += $this->sum_monetary_rows($calc['taxes'] ?? []);
                
                $slot['fixed_cost'] = $fixedCost;
            }
        }

        $pricePp = (float) ($slot['price_pp'] ?? 0.0);
        $fixed   = (float) ($slot['fixed_cost'] ?? 0.0);
        $services = 0.0;

        if (isset($slot['services']) && is_array($slot['services'])) {
            foreach ($slot['services'] as $service) {
                $services += (float) ($service['price'] ?? 0.0);
            }
        }

        $subtotal = ($participants * $pricePp) + $fixed + $services;

        return [
            'product_id'        => $productId,
            'resource_id'       => $resourceId,
            'start'             => $startRaw,
            'date'              => $dayDate,
            'base_price'        => round($pricePp, 2),
            'unit_price'        => round($pricePp, 2),
            'booking_adjustment'=> round($fixed + $services, 2),
            'applied_rules'     => [],
            'participants'      => $participants,
            'total'             => round($subtotal, 2),
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<int, array<int, array<string, mixed>>> $slotBreakdown
     */
    private function applyPlanFeeFilter(array $plan, array $slotBreakdown): float
    {
        $defaultFee = 4.95;

        if (function_exists('apply_filters')) {
            return (float) apply_filters('sbdp/day_planner/plan_fee', $defaultFee, $plan, $slotBreakdown);
        }

        return $defaultFee;
    }

    private function composeStartIso(string $timeValue, ?string $dayDate): string
    {
        $timeValue = trim($timeValue);
        if ($timeValue === '') {
            return '';
        }

        if ($dayDate && preg_match('/^\d{2}:\d{2}$/', $timeValue) === 1) {
            return $dayDate . 'T' . $timeValue . ':00';
        }

        if ($dayDate && preg_match('/^\d{2}:\d{2}:\d{2}$/', $timeValue) === 1) {
            return $dayDate . 'T' . $timeValue;
        }

        return $timeValue;
    }

    private function sum_monetary_rows(array $rows): float
    {
        $sum = 0.0;
        foreach ($rows as $row) {
            $sum += (float) ($row['amount'] ?? 0.0);
        }
        return $sum;
    }

    private function resolveCurrency(): string
    {
        if (\function_exists('get_woocommerce_currency')) {
            $currency = (string) \get_woocommerce_currency();
            if ($currency !== '') {
                return $currency;
            }
        }

        return 'EUR';
    }
}
