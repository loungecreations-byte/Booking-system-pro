<?php
declare(strict_types=1);

namespace BSP\Support\Admin;

use function add_action;
use function add_submenu_page;
use function current_user_can;
use function esc_html__;
use function function_exists;
use function printf;

final class Menu
{
    public static function init(): void
    {
        // Not implemented yet — menu hidden until Support tools are built.
    }

    public static function register_page(): void
    {
        if (! function_exists('add_submenu_page')) {
            return;
        }

        add_submenu_page(
            'sbdp_bookings',
            esc_html__('Support', 'sbdp'),
            esc_html__('Support', 'sbdp'),
            'manage_options',
            'sbdp_support',
            [__CLASS__, 'render_page']
        );
    }

    public static function render_page(): void
    {
        if (function_exists('current_user_can') && ! current_user_can('manage_options')) {
            return;
        }

        printf(
            '<div class="wrap"><h1>%s</h1><p>%s</p></div>',
            esc_html__('Booking Support', 'sbdp'),
            esc_html__('Support tools worden later toegevoegd.', 'sbdp')
        );
    }
}

if (! class_exists('BSPModule\\Support\\Admin\\Menu', false)) {
    class_alias(Menu::class, 'BSPModule\\Support\\Admin\\Menu');
}
