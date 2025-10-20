<?php
declare(strict_types=1);

namespace BSP\Notifications;

use BSP\Core\Interfaces\ModuleInterface;
use BSP\Core\Helpers\Logger;
use function add_action;
use function add_shortcode;
use function current_time;

final class Module implements ModuleInterface
{
    private Rest_Controller $restController;

    private Admin_Settings_Page $adminPage;

    private SetupService $setup;

    public function __construct(
        ?Rest_Controller $restController = null,
        ?Admin_Settings_Page $adminPage = null,
        ?SetupService $setup = null
    )
    {
        $this->setup          = $setup ?? new SetupService(new Logger());
        $this->restController = $restController ?? new Rest_Controller($this->setup);
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

        $config     = $this->setup->getConfiguration();
        $templates  = $config['templates'] ?? [];
        $enabled    = \array_filter(
            $templates,
            static fn(array $template): bool => (bool) ($template['enabled'] ?? false)
        );

        $items = [];
        foreach ($enabled as $template) {
            $label    = (string) ($template['label'] ?? $template['key']);
            $items[] = '<li>' . $this->escape($label) . '</li>';
        }

        if ([] === $items) {
            $items[] = '<li>' . $this->escape(
                \function_exists('esc_html__')
                    ? \esc_html__('No notification templates active.', 'sbdp')
                    : 'No notification templates active.'
            ) . '</li>';
        }

        $heading = \function_exists('esc_html__')
            ? \esc_html__('Active booking notifications:', 'sbdp')
            : 'Active booking notifications:';

        return '<div class="bsp-notifications" data-generated="' . $timestamp . '">' .
            '<p>' . $this->escape($heading) . '</p>' .
            '<ul class="bsp-notification-list">' . \implode('', $items) . '</ul>' .
            '</div>';
    }

    private function escape(string $value): string
    {
        if (\function_exists('esc_html')) {
            return \esc_html($value);
        }

        return \htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (! class_exists('BSPModule\\Notifications\\Module', false)) {
    class_alias(Module::class, 'BSPModule\\Notifications\\Module');
}
