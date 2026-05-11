<?php

declare(strict_types=1);

namespace BSP\Planboard;

use BSP\Core\Interfaces\ModuleInterface;
use BSP\Planner\Services\Planboard\PlanboardFeature;

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

final class Module implements ModuleInterface
{
    private const PAGE_SLUG = 'sbdp_planboard_v2';

    public function init(): void
    {
        if (! PlanboardFeature::isEnabled()) {
            return;
        }

        if (function_exists('add_action')) {
            add_action('admin_menu', array($this, 'registerAdminPage'));
            add_action('admin_enqueue_scripts', array($this, 'enqueueAssets'));
            add_action('init', array($this, 'ensureCapabilities'));
        }
    }

    public function registerAdminPage(): void
    {
        $capability = $this->resolveCapability();

        add_menu_page(
            __('Planboard', 'sbdp'),
            __('Planboard', 'sbdp'),
            $capability,
            self::PAGE_SLUG,
            array($this, 'renderAdminPage'),
            'dashicons-calendar-alt',
            58
        );
    }

    public function renderAdminPage(): void
    {
        echo '<div class="wrap sbdp-scheduler-wrap">';
        echo '<h1>' . esc_html__('Planboard', 'sbdp') . '</h1>';
        echo '<p class="description">' . esc_html__('Operationeel overzicht van planning, verplaatsingen en statusacties.', 'sbdp') . '</p>';
        echo '<div id="sbdp-scheduler-app" class="sbdp-scheduler-app"></div>';
        echo '</div>';
    }

    public function enqueueAssets(string $hook): void
    {
        if (empty($_GET['page']) || $_GET['page'] !== self::PAGE_SLUG) {
            return;
        }

        $script = SBDP_URL . 'assets/admin-scheduler.js';
        $style  = SBDP_URL . 'assets/admin-scheduler.css';
        $scriptVersion = defined('SBDP_VER') ? SBDP_VER : null;
        $styleVersion  = $scriptVersion;
        if (defined('SBDP_DIR') && function_exists('filemtime')) {
            $scriptPath = SBDP_DIR . 'assets/admin-scheduler.js';
            $stylePath  = SBDP_DIR . 'assets/admin-scheduler.css';
            if (is_string($scriptPath) && file_exists($scriptPath)) {
                $scriptVersion = (string) filemtime($scriptPath);
            }
            if (is_string($stylePath) && file_exists($stylePath)) {
                $styleVersion = (string) filemtime($stylePath);
            }
        }

        wp_enqueue_script('sbdp-admin-scheduler', $script, array('wp-i18n'), $scriptVersion, true);
        wp_enqueue_style('sbdp-admin-scheduler', $style, array(), $styleVersion);

        wp_localize_script(
            'sbdp-admin-scheduler',
            'SBDP_ADMIN_SCHEDULER',
            array(
                'endpoint' => esc_url_raw(rest_url('bsp/v2/planboard/overview')),
                'nonce'    => wp_create_nonce('wp_rest'),
                'v2'       => array(
                    'snapshot' => esc_url_raw(rest_url('bsp/v2/planboard/snapshot')),
                    'create'   => esc_url_raw(rest_url('bsp/v2/planboard/bookings')),
                    'move'     => esc_url_raw(rest_url('bsp/v2/planboard/bookings/move')),
                    'checkin'  => esc_url_raw(rest_url('bsp/v2/planboard/bookings/checkin')),
                    'payment'  => esc_url_raw(rest_url('bsp/v2/planboard/bookings/payment')),
                    'closures' => esc_url_raw(rest_url('bsp/v2/planboard/closures')),
                    'products' => esc_url_raw(rest_url('bsp/v2/planboard/products')),
                    'pricing'  => esc_url_raw(rest_url('bsp/v2/planboard/pricing/preview')),
                ),
            )
        );
    }

    private function resolveCapability(): string
    {
        if (! function_exists('current_user_can')) {
            return 'manage_woocommerce';
        }

        if (current_user_can('board.view')) {
            return 'board.view';
        }

        if (current_user_can('manage_woocommerce')) {
            return 'manage_woocommerce';
        }

        if (current_user_can('manage_options')) {
            return 'manage_options';
        }

        return 'read';
    }

    public function ensureCapabilities(): void
    {
        if (! function_exists('get_role')) {
            return;
        }

        $caps = array(
            'board.view',
            'booking.move',
            'booking.create',
            'booking.checkin',
            'payment.add',
            'rules.manage',
        );

        foreach (array('administrator', 'shop_manager') as $roleName) {
            $role = get_role($roleName);
            if (! $role instanceof \WP_Role) {
                continue;
            }

            foreach ($caps as $capability) {
                if (! $role->has_cap($capability)) {
                    $role->add_cap($capability);
                }
            }
        }
    }
}

if (! class_exists('BSPModule\\Planboard\\Module', false)) {
    class_alias(Module::class, 'BSPModule\\Planboard\\Module');
}

// phpcs:enable PSR1.Files.SideEffects.FoundWithSymbols
