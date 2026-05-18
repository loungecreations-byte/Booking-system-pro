<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use BSP\Sales\Pricing\PricingService;
use BSPModule\Core\Rest\RestService;
use SBDP\Pricing\SelectionPricing;
use WC_Product;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class QuoteExecutionLookupService
{
    /**
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    public function lookupPricing(array $line): array
    {
        $productId = (int) ($line['product_id'] ?? 0);
        $participants = max(1, (int) ($line['participants'] ?? $line['quantity'] ?? 1));
        $resourceId = (int) ($line['resource_id'] ?? 0);
        $startIso = $this->composeStartIso(
            (string) ($line['service_date'] ?? ''),
            (string) ($line['start_time'] ?? '')
        );

        if ($productId <= 0 || $startIso === '') {
            return array(
                'confidence' => 'unknown',
                'payload'    => array('reason' => 'missing_product_or_datetime'),
                'unit_amount_snapshot' => null,
                'line_total_snapshot' => null,
                'currency' => 'EUR',
            );
        }

        if (class_exists(SelectionPricing::class)) {
            try {
                $pricing = SelectionPricing::quote(
                    $productId,
                    $participants,
                    $startIso,
                    $resourceId,
                    array(),
                    array(
                        'channel' => 'quote_os_resnapshot',
                        'source'  => 'quote_handoff_preparation',
                        'date'    => (string) ($line['service_date'] ?? ''),
                    )
                );
            } catch (\Throwable $exception) {
                unset($exception);
                $pricing = array();
            }

            $pricingPayload = is_array($pricing) ? $pricing : array();
            $unitAmount = $this->resolveUnitAmount($pricingPayload);
            $lineTotal = $this->resolveLineTotal($pricingPayload);
            if ((float) ($unitAmount ?? 0.0) > 0.0 || (float) ($lineTotal ?? 0.0) > 0.0) {
                return array(
                    'confidence' => 'execution_verified',
                    'payload'    => $pricingPayload,
                    'unit_amount_snapshot' => $unitAmount,
                    'line_total_snapshot' => $lineTotal,
                    'currency' => (string) (($pricingPayload['currency'] ?? 'EUR')),
                );
            }
        }

        if (class_exists(PricingService::class)) {
            try {
                $pricing = PricingService::quote($productId, $participants, array(
                    'channel' => 'quote_os_resnapshot',
                    'source'  => 'quote_handoff_preparation',
                ));
            } catch (\Throwable $exception) {
                unset($exception);
                $pricing = array();
            }

            if (($pricing['success'] ?? false) === true) {
                $unitAmount = isset($pricing['adjusted_price']) ? (float) $pricing['adjusted_price'] : null;
                $lineTotal = isset($pricing['total_adjusted']) ? (float) $pricing['total_adjusted'] : null;
                if ((float) ($unitAmount ?? 0.0) <= 0.0 && (float) ($lineTotal ?? 0.0) <= 0.0) {
                    $pricing = array();
                } else {
                    return array(
                    'confidence' => 'snapshot',
                    'payload'    => $pricing,
                    'unit_amount_snapshot' => $unitAmount,
                    'line_total_snapshot' => $lineTotal,
                    'currency' => (string) ($pricing['currency'] ?? 'EUR'),
                    );
                }
            }
        }

        $wooFallback = $this->lookupWooPricing($productId, $participants);
        if ($wooFallback !== array()) {
            return $wooFallback;
        }

        return array(
            'confidence' => 'unknown',
            'payload'    => array('reason' => 'pricing_lookup_unavailable'),
            'unit_amount_snapshot' => null,
            'line_total_snapshot' => null,
            'currency' => 'EUR',
        );
    }

    /**
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    public function lookupAvailability(array $line): array
    {
        $productId = (int) ($line['product_id'] ?? 0);
        $resourceId = (int) ($line['resource_id'] ?? 0);
        $participants = max(1, (int) ($line['participants'] ?? $line['quantity'] ?? 1));
        $date = trim((string) ($line['service_date'] ?? ''));
        $startTime = trim((string) ($line['start_time'] ?? ''));
        $endTime = trim((string) ($line['end_time'] ?? ''));
        $startIso = $this->composeStartIso($date, $startTime);
        $endIso = $this->composeStartIso($date, $endTime);

        if ($productId <= 0 || $date === '' || $startTime === '' || $endTime === '') {
            return array(
                'confidence' => 'unknown',
                'available'  => false,
                'payload'    => array('reason' => 'missing_product_or_schedule'),
            );
        }

        if (! class_exists(WP_REST_Request::class)) {
            return array(
                'confidence' => 'unknown',
                'available'  => false,
                'payload'    => array('reason' => 'rest_request_unavailable'),
            );
        }

        $request = new WP_REST_Request('GET');
        if (! method_exists($request, 'set_param')) {
            return array(
                'confidence' => 'unknown',
                'available'  => false,
                'payload'    => array('reason' => 'rest_request_set_param_unavailable'),
            );
        }

        $request->set_param('product_id', $productId);
        $request->set_param('resource_id', $resourceId);
        $request->set_param('date', $date);

        $payload = RestService::availability_slots($request);
        $payload = $this->normalizeRestPayload($payload);
        if (($payload['error'] ?? false) === true) {
            return array(
                'confidence' => 'unknown',
                'available'  => false,
                'payload'    => $payload,
            );
        }

        if ($this->isTimeAvailable($payload, $startTime, $endTime, $participants)) {
            return array(
                'confidence' => 'confirmed',
                'available'  => true,
                'payload'    => $payload,
            );
        }

        if ($resourceId <= 0) {
            $fallback = RestService::plan_availability($request);
            $fallback = $this->normalizeRestPayload($fallback);
            if (($fallback['error'] ?? false) !== true && ! $this->hasBlockingFallbackBlock($fallback, $startIso, $endIso, $participants)) {
                return array(
                    'confidence' => 'confirmed',
                    'available'  => true,
                    'payload'    => $fallback,
                );
            }
        }

        return array(
            'confidence' => 'unknown',
            'available'  => false,
            'payload'    => $payload,
        );
    }

    /**
     * @param mixed $payload
     * @return array<string, mixed>
     */
    private function normalizeRestPayload($payload): array
    {
        if ($payload instanceof WP_REST_Response) {
            $payload = $payload->get_data();
        }

        if ($payload instanceof WP_Error) {
            return array(
                'error' => true,
                'reason' => method_exists($payload, 'get_error_message') ? $payload->get_error_message() : 'wp_error',
            );
        }

        return is_array($payload) ? $payload : array('error' => true, 'reason' => 'invalid_payload');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function isTimeAvailable(array $payload, string $startTime, string $endTime, int $participants): bool
    {
        $capacity = (int) ($payload['capacity'] ?? 0);
        if ($capacity > 0 && $participants > $capacity) {
            return false;
        }

        $slots = isset($payload['slots']) && is_array($payload['slots']) ? $payload['slots'] : array();
        if ($slots === array()) {
            return false;
        }

        $slotLength = $this->resolveSlotLengthMinutes($slots);
        $startMinutes = $this->timeToMinutes($startTime);
        $endMinutes = $this->timeToMinutes($endTime);

        if ($slotLength <= 0 || $startMinutes === null || $endMinutes === null || $endMinutes <= $startMinutes) {
            return false;
        }

        $required = max(1, (int) ceil(($endMinutes - $startMinutes) / $slotLength));
        $startSet = array();
        foreach ($slots as $slot) {
            $slotStart = $this->timeToMinutes((string) ($slot['start'] ?? ''));
            if ($slotStart !== null) {
                $startSet[$slotStart] = true;
            }
        }

        for ($i = 0; $i < $required; $i++) {
            $candidate = $startMinutes + ($i * $slotLength);
            if (! isset($startSet[$candidate])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hasBlockingFallbackBlock(array $payload, string $startIso, string $endIso, int $participants): bool
    {
        $capacity = (int) ($payload['capacity'] ?? 0);
        if ($capacity > 0 && $participants > $capacity) {
            return true;
        }

        $blocks = isset($payload['blocks']) && is_array($payload['blocks']) ? $payload['blocks'] : array();
        foreach ($blocks as $block) {
            $blockStart = (string) ($block['start'] ?? '');
            $blockEnd = (string) ($block['end'] ?? '');
            if ($blockStart === '' || $blockEnd === '') {
                continue;
            }

            if ($this->rangesOverlap($startIso, $endIso, $blockStart, $blockEnd)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $pricing
     */
    private function resolveUnitAmount(array $pricing): ?float
    {
        foreach (array('display_unit_price', 'display_per_person', 'unit_price', 'per_person') as $key) {
            if (isset($pricing[$key])) {
                return (float) $pricing[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $pricing
     */
    private function resolveLineTotal(array $pricing): ?float
    {
        foreach (array('display_total', 'total', 'total_adjusted') as $key) {
            if (isset($pricing[$key])) {
                return (float) $pricing[$key];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function lookupWooPricing(int $productId, int $participants): array
    {
        if (! function_exists('wc_get_product')) {
            return array();
        }

        $product = wc_get_product($productId);
        $isWooProduct = class_exists(WC_Product::class) && $product instanceof WC_Product;
        if (! $isWooProduct && (! is_object($product) || ! method_exists($product, 'get_price'))) {
            return array();
        }

        $unitPrice = 0.0;
        if (function_exists('wc_get_price_including_tax')) {
            $unitPrice = (float) wc_get_price_including_tax($product, array('qty' => 1));
        } elseif (method_exists($product, 'get_price')) {
            $unitPrice = (float) $product->get_price();
        }

        if ($unitPrice <= 0.0) {
            return array();
        }

        $supportsPersons = $this->productSupportsPersons($productId);
        $quantity = $supportsPersons ? max(1, $participants) : 1;
        $lineTotal = round($unitPrice * $quantity, 2);
        $currency = function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : 'EUR';

        return array(
            'confidence' => 'woocommerce_taxed_fallback',
            'payload'    => array(
                'source' => 'woocommerce_taxed_fallback',
                'line_item' => array(
                    'pricing' => array(
                        'supports_persons' => $supportsPersons,
                    ),
                ),
                'display_unit_price' => round($unitPrice, 2),
                'display_total' => $lineTotal,
            ),
            'unit_amount_snapshot' => round($unitPrice, 2),
            'line_total_snapshot' => $lineTotal,
            'currency' => $currency !== '' ? $currency : 'EUR',
        );
    }

    private function productSupportsPersons(int $productId): bool
    {
        if (! function_exists('get_post_meta')) {
            return true;
        }

        foreach (array('_sbdp_enable_people', '_wc_booking_has_persons') as $key) {
            $value = get_post_meta($productId, $key, true);
            if (is_bool($value)) {
                return $value;
            }

            $normalized = strtolower(trim((string) $value));
            if (in_array($normalized, array('1', 'yes', 'true', 'on'), true)) {
                return true;
            }
            if (in_array($normalized, array('0', 'no', 'false', 'off'), true)) {
                return false;
            }
        }

        $maxPeople = (int) get_post_meta($productId, '_sbdp_max_people', true);
        if ($maxPeople <= 0) {
            $maxPeople = (int) get_post_meta($productId, '_sbdp_people_max', true);
        }
        if ($maxPeople <= 0) {
            $maxPeople = (int) get_post_meta($productId, '_wc_booking_max_persons', true);
        }

        return $maxPeople > 1;
    }

    private function composeStartIso(string $date, string $time): string
    {
        $date = trim($date);
        $time = trim($time);
        if ($date === '' || $time === '') {
            return '';
        }

        $time = substr($time, 0, 5);
        return $date . 'T' . $time . ':00';
    }

    /**
     * @param array<int, array<string, mixed>> $slots
     */
    private function resolveSlotLengthMinutes(array $slots): int
    {
        $starts = array();
        foreach ($slots as $slot) {
            $minutes = $this->timeToMinutes((string) ($slot['start'] ?? ''));
            if ($minutes !== null) {
                $starts[] = $minutes;
            }
        }

        sort($starts);
        if (count($starts) < 2) {
            return 30;
        }

        $diff = $starts[1] - $starts[0];
        return $diff > 0 ? $diff : 30;
    }

    private function timeToMinutes(string $time): ?int
    {
        if (! preg_match('/^(\d{2}):(\d{2})/', $time, $matches)) {
            return null;
        }

        return (((int) $matches[1]) * 60) + (int) $matches[2];
    }

    private function rangesOverlap(string $leftStart, string $leftEnd, string $rightStart, string $rightEnd): bool
    {
        $leftStartTs = strtotime($leftStart);
        $leftEndTs = strtotime($leftEnd);
        $rightStartTs = strtotime($rightStart);
        $rightEndTs = strtotime($rightEnd);

        if ($leftStartTs === false || $leftEndTs === false || $rightStartTs === false || $rightEndTs === false) {
            return false;
        }

        return $leftStartTs < $rightEndTs && $leftEndTs > $rightStartTs;
    }
}
