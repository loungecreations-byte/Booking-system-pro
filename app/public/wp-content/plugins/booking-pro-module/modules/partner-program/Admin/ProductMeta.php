<?php

declare(strict_types=1);

namespace BSP\PartnerProgram\Admin;

use function add_action;
use function add_filter;
use function get_post_meta;
use function update_post_meta;
use function wp_nonce_field;
use function check_admin_referer;
use function esc_html;
use function esc_attr;
use function absint;
use function wc_get_order;

/**
 * ProductMeta — adds a "Partner Programma" tab to the WooCommerce product data editor.
 *
 * Partners buy subscription products in WooCommerce to activate their plan.
 * This meta links a WC product → bsp_subscription_plans.id.
 * Used by ContractService::resolvePlanId() to create a contract on subscription purchase.
 *
 * Meta key: `_bsp_partner_plan_id`
 */
final class ProductMeta
{
    public static function init(): void
    {
        add_filter('woocommerce_product_data_tabs', [self::class, 'addTab']);
        add_action('woocommerce_product_data_panels', [self::class, 'renderPanel']);
        add_action('woocommerce_process_product_meta', [self::class, 'savePanel']);
    }

    public static function addTab(array $tabs): array
    {
        $tabs['bsp_partner_plan'] = [
            'label'    => __('Partner Plan', 'sbdp'),
            'target'   => 'bsp_partner_plan_data',
            'class'    => [],
            'priority' => 80,
        ];
        return $tabs;
    }

    public static function renderPanel(): void
    {
        global $post, $wpdb;

        $planId = (int) get_post_meta($post->ID, '_bsp_partner_plan_id', true);

        $plans = $wpdb->get_results(
            "SELECT id, plan_name, billing_cycle, price_eur FROM {$wpdb->prefix}bsp_subscription_plans ORDER BY id ASC",
            ARRAY_A
        ) ?: [];

        ?>
        <div id="bsp_partner_plan_data" class="panel woocommerce_options_panel">
            <div class="options_group">
                <p class="form-field">
                    <label for="bsp_partner_plan_id">
                        <?php esc_html_e('Partner Abonnement Plan', 'sbdp'); ?>
                    </label>
                    <select id="bsp_partner_plan_id" name="bsp_partner_plan_id" class="short">
                        <option value=""><?php esc_html_e('— Geen / niet van toepassing —', 'sbdp'); ?></option>
                        <?php foreach ($plans as $plan) : ?>
                            <option value="<?php echo (int) $plan['id']; ?>" <?php selected($planId, (int) $plan['id']); ?>>
                                <?php echo esc_html("{$plan['plan_name']} ({$plan['billing_cycle']}) — €{$plan['price_eur']}"); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="description">
                        <?php esc_html_e('Koppel dit product aan een Partner Plan. Bij aankoop wordt automatisch een contract + rechten aangemaakt.', 'sbdp'); ?>
                    </span>
                </p>

                <?php if ($planId) : ?>
                    <p class="form-field">
                        <label><?php esc_html_e('Actief plan', 'sbdp'); ?></label>
                        <strong><?php
                            $activePlan = $wpdb->get_row($wpdb->prepare(
                                "SELECT plan_name, billing_cycle, price_eur FROM {$wpdb->prefix}bsp_subscription_plans WHERE id = %d",
                                $planId
                            ), ARRAY_A);
                            echo $activePlan ? esc_html("{$activePlan['plan_name']} ({$activePlan['billing_cycle']}) — €{$activePlan['price_eur']}") : esc_html__('Plan niet gevonden', 'sbdp');
                        ?></strong>
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public static function savePanel(int $postId): void
    {
        $planId = absint($_POST['bsp_partner_plan_id'] ?? 0);
        update_post_meta($postId, '_bsp_partner_plan_id', $planId ?: '');
    }
}
