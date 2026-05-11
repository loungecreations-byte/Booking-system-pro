<?php

declare(strict_types=1);

namespace SBDP\Modules\Pricing\Services;

use BSP\Sales\Pricing\PricingPresetRegistry;
use BSP\Sales\Pricing\PricingService as LegacyPricingService;
use BSP\Sales\Pricing\YieldEngine;
use SBDP\Pricing\PricingService as CorePricingService;
use WP_Error;
use wpdb;

class PricingService
{
    /**
     * Produce a pricing quote for the given WooCommerce product.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function quote(int $product_id, int $quantity = 1, array $context = array()): array
    {
        $quantity = \max(1, $quantity);
        $channel = isset($context['channel']) ? (string) $context['channel'] : 'web';
        $payload = array_merge(
            $context,
            array(
                'channel' => $channel,
            )
        );

        try {
            $quote = CorePricingService::instance()->quote($product_id, $quantity, $payload);
        } catch (\Throwable $exception) {
            return array(
                'success' => false,
                'error'   => new WP_Error(
                    'sbdp_pricing_quote_failed',
                    $exception->getMessage(),
                    array('status' => 500)
                ),
            );
        }

        $lineItem = isset($quote['line_item']) && is_array($quote['line_item'])
            ? $quote['line_item']
            : array();
        $linePricing = isset($lineItem['pricing']) && is_array($lineItem['pricing'])
            ? $lineItem['pricing']
            : array();

        $unitSubtotal = isset($linePricing['base_price'])
            ? (float) $linePricing['base_price']
            : 0.0;
        $unitTotal = isset($quote['unit_price'])
            ? (float) $quote['unit_price']
            : $unitSubtotal;
        $total = (float) ($quote['total'] ?? 0.0);
        $displayUnitTotal = isset($quote['display_unit_price'])
            ? (float) $quote['display_unit_price']
            : $unitTotal;
        $displayTotal = isset($quote['display_total'])
            ? (float) $quote['display_total']
            : $total;
        $currency = isset($quote['currency'])
            ? (string) $quote['currency']
            : (isset($payload['currency']) ? (string) $payload['currency'] : (function_exists('get_option') ? (string) get_option('woocommerce_currency', 'EUR') : 'EUR'));

        $result = array(
            'success'        => true,
            'product_id'     => $product_id,
            'quantity'       => $quantity,
            'channel'        => $quote['channel'] ?? $channel,
            'currency'       => $currency,
            'base_price'     => round($unitSubtotal, 2),
            'adjusted_price' => round($unitTotal, 2),
            'total_adjusted' => round($total, 2),
            'display_price'  => round($displayUnitTotal, 2),
            'display_total'  => round($displayTotal, 2),
            'applied_rules'  => isset($quote['meta']['applied_rules'])
                ? (array) $quote['meta']['applied_rules']
                : array(),
            'pricing_source' => isset($payload['source']) && is_string($payload['source']) && $payload['source'] !== ''
                ? $payload['source']
                : 'core_pricing_service',
            'pricing'        => $quote,
        );

        if (method_exists(LegacyPricingService::class, 'logPrice')) {
            LegacyPricingService::logPrice(
                $product_id,
                $unitSubtotal,
                $unitTotal,
                (string) $result['channel'],
                $payload
            );
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPresets(): array
    {
        return PricingPresetRegistry::all();
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|WP_Error
     */
    public function applyPreset(array $payload)
    {
        $key = isset($payload['key']) ? sanitize_text_field((string) $payload['key']) : '';
        if ('' === $key) {
            return new WP_Error(
                'sbdp_pricing_missing_key',
                __('Preset key is required.', 'sbdp'),
                array('status' => 422)
            );
        }

        $preset = PricingPresetRegistry::get($key);
        if (! $preset) {
            return new WP_Error(
                'sbdp_pricing_unknown_preset',
                __('Preset not found.', 'sbdp'),
                array('status' => 404)
            );
        }

        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return new WP_Error(
                'sbdp_pricing_db_unavailable',
                __('Database unavailable.', 'sbdp'),
                array('status' => 500)
            );
        }

        $name = isset($payload['name']) && '' !== (string) $payload['name']
            ? sanitize_text_field((string) $payload['name'])
            : (string) ($preset['name'] ?? $key);

        $priority = isset($payload['priority'])
            ? absint((int) $payload['priority'])
            : (int) ($preset['priority'] ?? 0);

        $active = isset($payload['active'])
            ? (int) (bool) $payload['active']
            : (int) ($preset['active'] ?? 1);

        $table = $wpdb->prefix . 'bsp_yield_rules';
        $wpdb->insert(
            $table,
            array(
                'name'            => $name,
                'condition_json'  => wp_json_encode($preset['conditions']),
                'adjustment_json' => wp_json_encode($preset['adjustment']),
                'priority'        => $priority,
                'active'          => $active,
                'created_at'      => current_time('mysql', true),
            ),
            array('%s', '%s', '%s', '%d', '%d', '%s')
        );

        YieldEngine::flushRuleCache();

        return array(
            'id'   => (int) $wpdb->insert_id,
            'name' => $name,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listRules(): array
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return array();
        }

        $table = $wpdb->prefix . 'bsp_yield_rules';
        $rows  = $wpdb->get_results(
            "SELECT id, name, priority, active, created_at, updated_at FROM {$table} ORDER BY priority DESC, id ASC",
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|WP_Error
     */
    public function createRule(array $payload)
    {
        $name = isset($payload['name']) ? sanitize_text_field((string) $payload['name']) : '';
        if ('' === $name) {
            return new WP_Error(
                'sbdp_pricing_missing_name',
                __('Rule name is required.', 'sbdp'),
                array('status' => 422)
            );
        }

        $conditions = $payload['conditions'] ?? array();
        $adjustment = $payload['adjustment'] ?? array();
        $priority   = isset($payload['priority']) ? absint((int) $payload['priority']) : 0;
        $active     = isset($payload['active']) ? (int) (bool) $payload['active'] : 1;

        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return new WP_Error(
                'sbdp_pricing_db_unavailable',
                __('Database unavailable.', 'sbdp'),
                array('status' => 500)
            );
        }

        $table = $wpdb->prefix . 'bsp_yield_rules';
        $wpdb->insert(
            $table,
            array(
                'name'            => $name,
                'condition_json'  => wp_json_encode($conditions),
                'adjustment_json' => wp_json_encode($adjustment),
                'priority'        => $priority,
                'active'          => $active,
                'created_at'      => current_time('mysql', true),
            ),
            array('%s', '%s', '%s', '%d', '%d', '%s')
        );

        YieldEngine::flushRuleCache();

        return array(
            'id'   => (int) $wpdb->insert_id,
            'name' => $name,
        );
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLog(array $filters = array()): array
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return array();
        }

        $table = $wpdb->prefix . 'bsp_price_log';
        $sql   = "SELECT id, product_id, rule_id, base_price, adjusted_price, channel, logged_at FROM {$table}";

        $product_id = isset($filters['product_id']) ? absint((int) $filters['product_id']) : 0;
        if ($product_id > 0) {
            $sql .= $wpdb->prepare(' WHERE product_id = %d', $product_id);
        }

        $sql .= ' ORDER BY logged_at DESC LIMIT 100';

        $entries = $wpdb->get_results($sql, ARRAY_A);

        return is_array($entries) ? $entries : array();
    }

    public function canManagePricing(): bool
    {
        return current_user_can('manage_bsp_sales') || current_user_can('manage_woocommerce');
    }
}
