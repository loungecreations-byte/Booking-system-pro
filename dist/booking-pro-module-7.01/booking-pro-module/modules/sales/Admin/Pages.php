<?php

declare(strict_types=1);

namespace BSP\Sales\Admin;

use BSP\Sales\Promotions\PromotionsService;
use BSP\Sales\Vendors\VendorService;
use BSP\Sales\Channels\ChannelManager;
use function absint;
use function add_action;
use function add_menu_page;
use function add_submenu_page;
use function array_map;
use function current_user_can;
use function date_i18n;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_html_e;
use function esc_url;
use function get_option;
use function get_role;
use function is_array;
use function number_format_i18n;
use function sanitize_text_field;
use function sprintf;
use function strtotime;

final class Pages
{
    private const CAPABILITY = 'manage_options';

    public static function init(): void
    {
        self::ensureCapability();
        add_action('admin_menu', [__CLASS__, 'registerMenu']);
    }

    public static function registerMenu(): void
    {
        add_menu_page(
            esc_html__('Sales Suite', 'sbdp'),
            esc_html__('Sales Suite', 'sbdp'),
            self::CAPABILITY,
            'bsp-sales',
            [__CLASS__, 'renderPricing'],
            'dashicons-chart-line',
            57
        );

        add_submenu_page('bsp-sales', esc_html__('Pricing & Yield', 'sbdp'), esc_html__('Pricing & Yield', 'sbdp'), self::CAPABILITY, 'bsp-sales', [__CLASS__, 'renderPricing']);
        add_submenu_page('bsp-sales', esc_html__('Channels', 'sbdp'), esc_html__('Channels', 'sbdp'), self::CAPABILITY, 'bsp-sales-channels', [__CLASS__, 'renderChannels']);
        add_submenu_page('bsp-sales', esc_html__('Vendors & Partners', 'sbdp'), esc_html__('Vendors', 'sbdp'), self::CAPABILITY, 'bsp-sales-vendors', [__CLASS__, 'renderVendors']);
        add_submenu_page('bsp-sales', esc_html__('Promotions & Offers', 'sbdp'), esc_html__('Promotions & Offers', 'sbdp'), self::CAPABILITY, 'bsp-sales-promotions', [__CLASS__, 'renderPromotions']);
    }

    public static function renderPricing(): void
    {
        self::renderShell('pricing', [__CLASS__, 'renderPricingContent']);
    }

    public static function renderChannels(): void
    {
        self::renderShell('channels', [__CLASS__, 'renderChannelsContent']);
    }

    public static function renderVendors(): void
    {
        self::renderShell('vendors', [__CLASS__, 'renderVendorsContent']);
    }

    public static function renderPromotions(): void
    {
        self::renderShell('promotions', [__CLASS__, 'renderPromotionsContent']);
    }

    private static function renderShell(string $screen, callable $callback): void
    {
        echo '<div class="wrap bsp-sales-wrap" data-screen="' . esc_attr($screen) . '">';
        echo '<h1>' . esc_html__('Sales Suite', 'sbdp') . '</h1>';
        echo '<p class="description">' . esc_html__('Overzicht van verkoopkanalen, vendors en acties.', 'sbdp') . '</p>';
        echo '<style>
            .bsp-sales-cards{display:flex;flex-wrap:wrap;gap:16px;margin:16px 0;}
            .bsp-sales-card{flex:1 1 260px;background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px;box-shadow:0 1px 2px rgba(0,0,0,0.04);}
            .bsp-sales-table{width:100%;border-collapse:collapse;margin-top:12px;}
            .bsp-sales-table th,.bsp-sales-table td{border:1px solid #dcdcde;padding:8px 10px;text-align:left;}
            .bsp-sales-table th{background:#f6f7f7;font-weight:600;}
            .bsp-sales-empty{margin:24px 0;padding:16px;border:1px dashed #c3c4c7;background:#fff;}
        </style>';
        call_user_func($callback);
        echo '</div>';
    }

    private static function renderPricingContent(): void
    {
        $channels = ChannelManager::getChannels();
        $active   = array_filter($channels, static fn(array $channel) => !empty($channel['active']));
        $commissionAvg = null;
        if ($active !== []) {
            $commissions = array_map(static fn(array $channel) => (float)($channel['commission_rate'] ?? 0.0), $active);
            $commissionAvg = array_sum($commissions) / max(count($commissions), 1);
        }

        echo '<div class="bsp-sales-cards">';
        echo '<div class="bsp-sales-card"><h2>' . esc_html__('Actieve kanalen', 'sbdp') . '</h2><p>' . number_format_i18n(count($active)) . '</p></div>';

        $defaultCurrency = get_option('woocommerce_currency', 'EUR');
        echo '<div class="bsp-sales-card"><h2>' . esc_html__('Kassavoluta', 'sbdp') . '</h2><p>' . esc_html($defaultCurrency ?: 'EUR') . '</p></div>';

        echo '<div class="bsp-sales-card"><h2>' . esc_html__('Gemiddelde commissie', 'sbdp') . '</h2>';
        echo '<p>' . esc_html($commissionAvg === null ? __('Onbekend', 'sbdp') : sprintf('%s%%', number_format_i18n($commissionAvg, 2))) . '</p></div>';
        echo '</div>';

        self::renderChannelsTable($channels);
    }

    private static function renderChannelsContent(): void
    {
        $channels = ChannelManager::getChannels();
        echo '<p>' . esc_html__('Overzicht van alle verkoopkanalen en synchronisatiestatus.', 'sbdp') . '</p>';
        self::renderChannelsTable($channels);
    }

    private static function renderVendorsContent(): void
    {
        VendorService::init();
        $vendors = VendorService::list(array(), true);

        if ($vendors === []) {
            self::renderEmptyNotice(__('Er zijn nog geen vendors geregistreerd.', 'sbdp'));
            return;
        }

        echo '<table class="bsp-sales-table"><thead><tr>';
        echo '<th>' . esc_html__('Vendor', 'sbdp') . '</th>';
        echo '<th>' . esc_html__('Status', 'sbdp') . '</th>';
        echo '<th>' . esc_html__('Producten', 'sbdp') . '</th>';
        echo '<th>' . esc_html__('Resources', 'sbdp') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($vendors as $vendor) {
            $name = $vendor['name'] ?? sprintf(__('Vendor #%d', 'sbdp'), absint($vendor['id'] ?? 0));
            $status = $vendor['status'] ?? 'pending';
            $products = isset($vendor['product_ids']) && is_array($vendor['product_ids']) ? count($vendor['product_ids']) : 0;
            $resources = isset($vendor['resource_ids']) && is_array($vendor['resource_ids']) ? count($vendor['resource_ids']) : 0;

            echo '<tr>';
            echo '<td>' . esc_html($name) . '</td>';
            echo '<td>' . esc_html(ucfirst((string)$status)) . '</td>';
            echo '<td>' . esc_html(number_format_i18n($products)) . '</td>';
            echo '<td>' . esc_html(number_format_i18n($resources)) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private static function renderPromotionsContent(): void
    {
        PromotionsService::init();
        $promotions = PromotionsService::listPromotions();

        if (! is_array($promotions) || $promotions === []) {
            self::renderEmptyNotice(__('Nog geen promoties vastgelegd.', 'sbdp'));
            return;
        }

        echo '<table class="bsp-sales-table"><thead><tr>';
        echo '<th>' . esc_html__('Code', 'sbdp') . '</th>';
        echo '<th>' . esc_html__('Naam', 'sbdp') . '</th>';
        echo '<th>' . esc_html__('Type', 'sbdp') . '</th>';
        echo '<th>' . esc_html__('Status', 'sbdp') . '</th>';
        echo '<th>' . esc_html__('Ingang', 'sbdp') . '</th>';
        echo '<th>' . esc_html__('Einde', 'sbdp') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($promotions as $promotion) {
            $code   = $promotion['code'] ?? '';
            $name   = $promotion['name'] ?? '';
            $type   = $promotion['type'] ?? '';
            $status = $promotion['status'] ?? '';
            $start  = self::formatDate($promotion['starts_at'] ?? null);
            $end    = self::formatDate($promotion['ends_at'] ?? null);

            echo '<tr>';
            echo '<td>' . esc_html($code) . '</td>';
            echo '<td>' . esc_html($name) . '</td>';
            echo '<td>' . esc_html(ucfirst($type)) . '</td>';
            echo '<td>' . esc_html(ucfirst($status)) . '</td>';
            echo '<td>' . esc_html($start) . '</td>';
            echo '<td>' . esc_html($end) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private static function renderChannelsTable(array $channels): void
    {
        if ($channels === []) {
            self::renderEmptyNotice(__('Geen kanalen gevonden. Voeg kanalen toe via de API of database.', 'sbdp'));
            return;
        }

        echo '<table class="bsp-sales-table"><thead><tr>';
        echo '<th>' . esc_html__('Kanaal', 'sbdp') . '</th>';
        echo '<th>' . esc_html__('Commissie', 'sbdp') . '</th>';
        echo '<th>' . esc_html__('Status', 'sbdp') . '</th>';
        echo '<th>' . esc_html__('Laatste synchronisatie', 'sbdp') . '</th>';
        echo '<th>' . esc_html__('Laatste fout', 'sbdp') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($channels as $channel) {
            $name      = $channel['name'] ?? sprintf(__('Kanaal #%d', 'sbdp'), absint($channel['id'] ?? 0));
            $commission = isset($channel['commission_rate']) ? sprintf('%s%%', number_format_i18n((float)$channel['commission_rate'], 2)) : __('n.v.t.', 'sbdp');
            $status    = !empty($channel['active']) ? __('Actief', 'sbdp') : __('Inactief', 'sbdp');
            $lastSync  = self::formatDate($channel['last_sync'] ?? null);
            $lastError = sanitize_text_field((string)($channel['last_error'] ?? ''));

            echo '<tr>';
            echo '<td>' . esc_html($name) . '</td>';
            echo '<td>' . esc_html($commission) . '</td>';
            echo '<td>' . esc_html($status) . '</td>';
            echo '<td>' . esc_html($lastSync) . '</td>';
            echo '<td>' . esc_html($lastError ?: '—') . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private static function renderEmptyNotice(string $message): void
    {
        echo '<div class="bsp-sales-empty"><p>' . esc_html($message) . '</p></div>';
    }

    private static function formatDate($value): string
    {
        if (empty($value)) {
            return '—';
        }

        $timestamp = strtotime((string)$value);
        if (! $timestamp) {
            return esc_html((string)$value);
        }

        return esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp));
    }

    private static function ensureCapability(): void
    {
        $role = get_role('administrator');
        if ($role && ! $role->has_cap(self::CAPABILITY)) {
            $role->add_cap(self::CAPABILITY);
        }
    }
}









