<?php

declare(strict_types=1);

namespace BSP\DayPlanner\Service;

use BSPModule\Core\Rest\RestService;

final class PriceEngine
{
    /**
     * @param array<string, mixed> $plan
     */
    public function calculateTotals(array $plan): array
    {
        $slots  = $plan['days'] ?? [];
        $subtotal  = 0.0;
        $people = $plan['participants'] ?? [];
        $slotBreakdown = [];

        foreach ($slots as $dayIndex => $day) {
            if (! isset($day['slots']) || ! is_array($day['slots'])) {
                continue;
            }

            $date = isset($day['date']) ? (string) $day['date'] : null;
            foreach ($day['slots'] as $slotIndex => $slot) {
                $pricing = $this->calculateSlotBreakdown($slot, $date);
                $slotBreakdown[$dayIndex][$slotIndex] = $pricing;
                $subtotal += (float) ($pricing['total'] ?? 0.0);
            }
        }

        $fee = 4.95;
        $total = $subtotal + $fee;
        $participantCount = is_array($people) ? count(array_filter($people)) : 0;

        $participantShare = $participantCount > 0 ? $subtotal / $participantCount : 0.0;

        $summary = [
            'subtotal'       => round($subtotal, 2),
            'booking_fee'    => round($fee, 2),
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
     * @param array<string, mixed> $slot
     * @return array<string, mixed>
     */
    private function calculateSlotBreakdown(array $slot, ?string $dayDate): array
    {
        $participants = max(1, (int) ($slot['people'] ?? $slot['participants'] ?? 1));
        $productId    = (int) ($slot['product_id'] ?? $slot['activity_id'] ?? 0);
        $resourceId   = isset($slot['resource_id']) ? (int) $slot['resource_id'] : 0;
        $startRaw     = isset($slot['start']) ? (string) $slot['start'] : '';
        $startIso     = $this->composeStartIso($startRaw, $dayDate);

        if ($productId > 0 && \function_exists('wc_get_product')) {
            $product = \wc_get_product($productId);
            if ($product) {
                $pricing = RestService::calculate_pricing_for_item($product, $resourceId, $startIso, $participants);

                return array_merge(
                    [
                        'product_id'  => $productId,
                        'resource_id' => $resourceId,
                        'start'       => $startRaw,
                        'date'        => $dayDate,
                    ],
                    $pricing
                );
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

        $subtotal = $participants * $pricePp + $fixed + $services;

        if ($participants >= 10) {
            $subtotal *= 0.95;
        }

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
