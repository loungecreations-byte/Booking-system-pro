<?php
declare(strict_types=1);

namespace BSP\Notifications;

use function add_action;
use function add_shortcode;
use function current_time;

final class Module
{
    private Rest_Controller $restController;

    private Admin_Settings_Page $adminPage;

    public function __construct(?Rest_Controller $restController = null, ?Admin_Settings_Page $adminPage = null)
    {
        $this->restController = $restController ?? new Rest_Controller();
        $this->adminPage      = $adminPage ?? new Admin_Settings_Page();
    }

    public function init(): void
    {
        add_shortcode('booking_notifications', [$this, 'render_shortcode']);
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
        add_action('admin_menu', [$this, 'registerAdminMenu']);
        add_action('admin_init', [$this, 'registerAdminSettings']);
    }

    public function registerRestRoutes(): void
    {
        $this->restController->register_routes();
    }

    public function registerAdminMenu(): void
    {
        $this->adminPage->register_menu();
    }

    public function registerAdminSettings(): void
    {
        $this->adminPage->register_settings();
    }

    public function render_shortcode(): string
    {
        $timestamp = (string) current_time('timestamp');

        return '<div class="bsp-notifications" data-generated="' . $timestamp . '">' .
            '<p>' . __('Booking updates coming soon.', 'bsp') . '</p>' .
            '</div>';
    }
}
