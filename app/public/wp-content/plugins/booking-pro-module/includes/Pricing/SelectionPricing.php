<?php

declare(strict_types=1);

namespace SBDP\Pricing;

use BSPModule\Core\Rest\RestService;

final class SelectionPricing
{
    /**
     * Quote a structural booking selection using Woo-backed pricing as the single source of truth.
     *
     * @param array<int, array<string, mixed>> $combiItems
     * @param array<string, mixed>             $context
     * @return array<string, mixed>
     */
    public static function quote(
        int $productId,
        int $participants,
        string $start = '',
        int $resourceId = 0,
        array $combiItems = array(),
        array $context = array()
    ): array {
        if ($productId <= 0 || ! \function_exists('wc_get_product')) {
            return array();
        }

        $product = \wc_get_product($productId);
        if (! $product instanceof \WC_Product) {
            return array();
        }

        $participants = max(1, $participants);
        $resourceId   = max(0, $resourceId);
        $start        = \is_string($start) ? $start : '';

        $baseContext = array_merge(
            array(
                'channel'    => 'planner_selection',
                'source'     => 'selection_pricing',
                'price_mode' => 'gross',
            ),
            $context
        );

        $pricing = RestService::calculate_pricing_for_item(
            $product,
            $resourceId,
            $start,
            $participants,
            $baseContext
        );

        if (! \is_array($pricing)) {
            $pricing = array();
        }

        $currency = isset($pricing['currency']) && \is_string($pricing['currency'])
            ? $pricing['currency']
            : self::defaultCurrency();

		$combiBreakdown = array();
		$combiTotal     = 0.0;
		$displayCombiTotal = 0.0;

		foreach (self::normaliseCombiItems($combiItems) as $combiItem) {
			$combiId = (int) $combiItem['id'];
			if ($combiId <= 0) {
				continue;
            }

            $quote = array();
            if (\class_exists(PricingService::class)) {
                try {
                    $quote = PricingService::instance()->quote(
                        $combiId,
                        $participants,
                        array_merge(
                            $baseContext,
                            array(
                                'channel'     => (string) ($baseContext['channel'] ?? 'planner_selection'),
                                'source'      => 'selection_pricing_combi',
                                'resource_id' => 0,
                                'start'       => $start,
                                'time'        => $start,
                            )
                        )
                    );
                } catch (\Throwable $exception) {
                    unset($exception);
                    $quote = array();
                }
            }

			$lineTotal = isset($quote['total']) ? (float) $quote['total'] : 0.0;
			$unitPrice = isset($quote['unit_price']) ? (float) $quote['unit_price'] : 0.0;
			$displayLineTotal = isset($quote['display_total']) ? (float) $quote['display_total'] : $lineTotal;
			$displayUnitPrice = isset($quote['display_unit_price']) ? (float) $quote['display_unit_price'] : $unitPrice;

			if ($lineTotal <= 0.0 && $unitPrice > 0.0) {
				$lineTotal = $unitPrice * $participants;
			}

            if ($unitPrice <= 0.0 && $lineTotal > 0.0 && $participants > 0) {
                $unitPrice = round($lineTotal / $participants, 2);
            }

            if ($lineTotal <= 0.0 && $unitPrice <= 0.0) {
                continue;
			}

			$combiTotal += $lineTotal;
			$displayCombiTotal += $displayLineTotal > 0.0 ? $displayLineTotal : $lineTotal;

			$combiBreakdown[] = array(
				'id'              => $combiId,
				'label'           => (string) ($combiItem['label'] ?? ''),
                'timing'          => (string) ($combiItem['timing'] ?? 'before'),
                'role'            => (string) ($combiItem['role'] ?? 'pre'),
                'order'           => (int) ($combiItem['order'] ?? 0),
				'duration'        => (int) ($combiItem['duration'] ?? 0),
				'durationMinutes' => (int) ($combiItem['durationMinutes'] ?? ($combiItem['duration'] ?? 0)),
				'unit_price'      => round($unitPrice, 2),
				'total'           => round($lineTotal, 2),
				'display_unit_price' => round($displayUnitPrice, 2),
				'display_total'      => round($displayLineTotal > 0.0 ? $displayLineTotal : $lineTotal, 2),
			);
		}

		$baseTotal  = isset($pricing['total']) ? (float) $pricing['total'] : 0.0;
		$finalTotal = round($baseTotal + $combiTotal, 2);
		$displayBaseTotal = isset($pricing['display_total']) ? (float) $pricing['display_total'] : $baseTotal;
		$displayFinalTotal = round($displayBaseTotal + $displayCombiTotal, 2);
		$unitPrice  = $participants > 0 ? round($finalTotal / $participants, 2) : round($finalTotal, 2);
		$displayUnitPrice = $participants > 0 ? round($displayFinalTotal / $participants, 2) : round($displayFinalTotal, 2);

		$pricing['currency']    = $currency;
		$pricing['participants'] = $participants;
		$pricing['total']       = $finalTotal;
		$pricing['unit_price']  = $unitPrice;
		$pricing['unitPrice']   = $unitPrice;
		$pricing['per_person']  = $unitPrice;
		$pricing['display_total'] = $displayFinalTotal;
		$pricing['display_unit_price'] = $displayUnitPrice;
		$pricing['display_per_person'] = $displayUnitPrice;

		if ($combiBreakdown !== array()) {
			$pricing['combi_multi'] = $combiBreakdown;
			$pricing['combi_total'] = round($combiTotal, 2);
			$pricing['display_combi_total'] = round($displayCombiTotal, 2);
		} else {
			unset($pricing['combi_multi'], $pricing['combi_total']);
			unset($pricing['display_combi_total']);
		}

		return $pricing;
	}

    /**
     * @param mixed $items
     * @return array<int, array<string, mixed>>
     */
    public static function normaliseCombiItems($items): array
    {
        if (! \is_array($items)) {
            return array();
        }

        $normalised = array();
        $order      = 0;

        foreach ($items as $item) {
            if (! \is_array($item)) {
                continue;
            }

            $id = isset($item['id'])
                ? (int) $item['id']
                : (isset($item['product_id']) ? (int) $item['product_id'] : (isset($item['productId']) ? (int) $item['productId'] : 0));
            if ($id <= 0) {
                continue;
            }

            $timing = self::normaliseTiming(
                isset($item['timing']) ? $item['timing'] : ($item['role'] ?? 'before')
            );
            $duration = isset($item['durationMinutes'])
                ? max(0, (int) $item['durationMinutes'])
                : (isset($item['duration']) ? max(0, (int) $item['duration']) : 0);

            $label = isset($item['label']) && \is_string($item['label'])
                ? trim($item['label'])
                : '';
            if ($label === '' && \function_exists('wc_get_product')) {
                $product = \wc_get_product($id);
                if ($product instanceof \WC_Product) {
                    $label = (string) $product->get_name();
                }
            }

            $normalised[] = array(
                'id'              => $id,
                'label'           => \function_exists('sanitize_text_field') ? \sanitize_text_field($label) : $label,
                'timing'          => $timing,
                'role'            => $timing === 'after' ? 'post' : 'pre',
                'duration'        => $duration,
                'durationMinutes' => $duration,
                'order'           => isset($item['order']) ? max(0, (int) $item['order']) : $order,
            );

            $order++;
        }

        \usort(
            $normalised,
            static function (array $left, array $right): int {
                return ((int) ($left['order'] ?? 0)) <=> ((int) ($right['order'] ?? 0));
            }
        );

        return array_values($normalised);
    }

    /**
     * @param mixed $value
     */
    private static function normaliseTiming($value): string
    {
        if (! \is_string($value)) {
            return 'before';
        }

        $value = \strtolower(\trim($value));
        if ($value === 'post' || $value === 'after') {
            return 'after';
        }

        return 'before';
    }

    private static function defaultCurrency(): string
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
