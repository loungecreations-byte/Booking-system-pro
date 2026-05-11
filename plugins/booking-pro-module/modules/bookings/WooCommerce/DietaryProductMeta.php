<?php

declare(strict_types=1);

namespace BSP\Bookings\WooCommerce;

use function add_action;
use function function_exists;
use function get_post_meta;
use function update_post_meta;
use function woocommerce_wp_checkbox;
use function absint;

/**
 * Adds a "Vereist dieetopgave" checkbox to the WooCommerce product edit page.
 * Meta key: _sbdp_requires_dietary (value: 'yes' | '')
 *
 * Only orders containing at least one product with this meta set to 'yes'
 * will trigger the dietary intake email.
 */
final class DietaryProductMeta
{
    public const META_KEY = '_sbdp_requires_dietary';

    public static function register(): void
    {
        if (! function_exists('add_action')) {
            return;
        }

        add_action('woocommerce_product_options_general_product_data', [self::class, 'renderCheckbox']);
        add_action('woocommerce_process_product_meta', [self::class, 'saveCheckbox']);
    }

    public static function renderCheckbox(): void
    {
        if (! function_exists('woocommerce_wp_checkbox')) {
            return;
        }

        woocommerce_wp_checkbox([
            'id'          => self::META_KEY,
            'label'       => 'Vereist dieetopgave',
            'description' => 'Klanten ontvangen na betaling een e-mail om dieetwensen en allergieën in te vullen.',
        ]);
    }

    public static function saveCheckbox(int $postId): void
    {
        if (! function_exists('update_post_meta')) {
            return;
        }

        $value = isset($_POST[self::META_KEY]) ? 'yes' : '';
        update_post_meta($postId, self::META_KEY, $value);
    }

    /**
     * Returns true if the given WooCommerce order contains at least one
     * product that requires dietary intake.
     */
    public static function orderRequiresDietary(\WC_Order $order): bool
    {
        foreach ($order->get_items() as $item) {
            /** @var \WC_Order_Item_Product $item */
            $productId = (int) $item->get_product_id();
            if ($productId <= 0) {
                continue;
            }

            $meta = get_post_meta($productId, self::META_KEY, true);
            if ($meta === 'yes') {
                return true;
            }
        }

        return false;
    }
}
