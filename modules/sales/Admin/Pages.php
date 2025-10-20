<?php

declare(strict_types=1);

namespace BSP\Sales\Admin;

use BSP\Sales\Promotions\PromotionsService;
use BSP\Sales\Vendors\VendorService;
use BSP\Sales\Channels\ChannelManager;
use function add_action;
use function add_menu_page;
use function add_submenu_page;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_url_raw;
use function rest_url;
use function strpos;
use function wp_create_nonce;
use function wp_enqueue_script;
use function wp_localize_script;
use function wp_register_script;
use function sanitize_key;
use function get_option;
use function function_exists;

final class Pages
{
    private const CAPABILITY = 'manage_bsp_sales';
    private const FALLBACK_CAPABILITY = 'manage_woocommerce';
    private const NONCE_ACTION = 'bsp_sales_admin';

    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'registerMenu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueueAssets']);
    }

    public static function registerMenu(): void
    {
        $capability = self::resolveCapability();

        add_menu_page(
            esc_html__('Sales Suite', 'sbdp'),
            esc_html__('Sales Suite', 'sbdp'),
            $capability,
            'bsp-sales',
            [__CLASS__, 'renderPricing'],
            'dashicons-chart-line',
            57
        );

        add_submenu_page('bsp-sales', esc_html__('Pricing & Yield', 'sbdp'), esc_html__('Pricing & Yield', 'sbdp'), $capability, 'bsp-sales', [__CLASS__, 'renderPricing']);
        add_submenu_page('bsp-sales', esc_html__('Channels', 'sbdp'), esc_html__('Channels', 'sbdp'), $capability, 'bsp-sales-channels', [__CLASS__, 'renderChannels']);
        add_submenu_page('bsp-sales', esc_html__('Vendors & Partners', 'sbdp'), esc_html__('Vendors', 'sbdp'), $capability, 'bsp-sales-vendors', [__CLASS__, 'renderVendors']);
        add_submenu_page('bsp-sales', esc_html__('Promotions & Offers', 'sbdp'), esc_html__('Promotions & Offers', 'sbdp'), $capability, 'bsp-sales-promotions', [__CLASS__, 'renderPromotions']);
    }

    public static function enqueueAssets(string $hook): void
    {
        if (strpos($hook, 'bsp-sales') === false) {
            return;
        }

        $isPromotions = strpos($hook, 'bsp-sales_page_bsp-sales-promotions') !== false;
        $handle       = $isPromotions ? 'bsp-sales-promotions-admin' : 'bsp-sales-admin';
        $script       = $isPromotions ? 'assets/js/bsp-promotions-admin.js' : 'assets/js/bsp-sales-admin.js';
        $nonceAction  = $isPromotions ? PromotionsService::NONCE_ACTION : self::NONCE_ACTION;
        $restBase     = esc_url_raw(rest_url($isPromotions ? 'sbdp/v1/' : 'bsp/v1/'));

        wp_register_script($handle, SBDP_URL . $script, ['wp-element'], defined('SBDP_VER') ? SBDP_VER : '1.0.0', true);

        $vendorStatuses = ['pending', 'active', 'suspended', 'archived'];

        if (class_exists(VendorService::class) && defined(VendorService::class . '::STATUSES')) {
            $vendorStatuses = VendorService::STATUSES;
        }

        $config = [
            'nonce'        => wp_create_nonce($nonceAction),
            'nonceAction'  => $nonceAction,
            'restBase'     => $restBase,
            'capabilities' => [
                'managePromotions' => current_user_can(PromotionsService::CAPABILITY) || self::userCanManageSales(),
                'manageVendors'    => self::userCanManageSales(),
            ],
        ];

        if (! $isPromotions) {
            $config['vendorStatuses'] = array_values($vendorStatuses);

            $channels       = ChannelManager::getChannels();
            $channelOptions = [];

            foreach ($channels as $channel) {
                $slug = sanitize_key($channel['name'] ?? '');

                if ($slug === '') {
                    continue;
                }

                $channelOptions[] = [
                    'id'              => isset($channel['id']) ? (int) $channel['id'] : 0,
                    'name'            => $channel['name'] ?? $slug,
                    'slug'            => $slug,
                    'commission_rate' => isset($channel['commission_rate']) ? (float) $channel['commission_rate'] : null,
                    'active'          => isset($channel['active']) ? (bool) $channel['active'] : true,
                ];
            }

            if ($channelOptions !== []) {
                $config['channelOptions'] = $channelOptions;
            }

            $defaultCurrency = get_option('woocommerce_currency');

            if (is_string($defaultCurrency) && $defaultCurrency !== '') {
                $config['defaultCurrency'] = $defaultCurrency;
            }

            if (function_exists('get_woocommerce_currencies')) {
                $currencies = array_keys((array) get_woocommerce_currencies());

                if ($currencies !== []) {
                    $config['currencyOptions'] = $currencies;
                }
            }
        }

        wp_localize_script(
            $handle,
            $isPromotions ? 'BSP_PROMOTIONS_ADMIN' : 'BSP_SALES_ADMIN',
            $config
        );

        wp_enqueue_script($handle);
    }

    public static function renderPricing(): void
    {
        self::renderShell('pricing');
    }

    public static function renderChannels(): void
    {
        self::renderShell('channels');
    }

    public static function renderVendors(): void
    {
        self::renderShell('vendors');
    }

    public static function renderPromotions(): void
    {
        self::renderShell('promotions');
    }

    private static function renderShell(string $screen): void
    {
        echo '<div class="wrap bsp-sales-wrap" data-screen="' . esc_attr($screen) . '">';
        echo '<h1>' . esc_html__('Sales Suite', 'sbdp') . '</h1>';
        echo '<div id="bsp-sales-admin-root"></div>';
        echo '</div>';
    }

    private static function resolveCapability(): string
    {
        if (! function_exists('current_user_can')) {
            return self::FALLBACK_CAPABILITY;
        }

        if (current_user_can(self::CAPABILITY)) {
            return self::CAPABILITY;
        }

        return self::FALLBACK_CAPABILITY;
    }

    private static function userCanManageSales(): bool
    {
        if (! function_exists('current_user_can')) {
            return false;
        }

        return current_user_can(self::CAPABILITY) || current_user_can(self::FALLBACK_CAPABILITY);
    }
}









