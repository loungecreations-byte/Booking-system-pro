<?php

declare(strict_types=1);

namespace BSP\CompetitorMonitor;

use BSP\CompetitorMonitor\Admin\DashboardPage;
use BSP\CompetitorMonitor\Service\EliioApiClient;
use BSP\CompetitorMonitor\Service\PriceMonitorService;
use BSP\Core\Interfaces\ModuleInterface;

final class Module implements ModuleInterface
{
    private const CRON_HOOK     = 'bsp_competitor_monitor_run';
    private const CRON_SCHEDULE = 'daily';

    /**
     * Known Eliio tenants to monitor.
     *
     * @var array<string, string>
     */
    private const TENANTS = [
        'Eropuitje.nl' => '019c9db6-92f5-716e-ab35-7cc7a0310272',
    ];

    public function init(): void
    {
        if (! \function_exists('add_action')) {
            return;
        }

        // Register WP-Cron job
        \add_action(self::CRON_HOOK, [$this, 'runMonitor']);
        \add_action('init', [$this, 'scheduleCron']);

        // Admin dashboard
        \add_action('admin_menu', [DashboardPage::class, 'register']);

        // Manual "run now" via admin POST
        \add_action('admin_post_bsp_competitor_run_now', [$this, 'handleRunNow']);

        // Save settings
        \add_action('admin_post_bsp_competitor_save_settings', [$this, 'handleSaveSettings']);

        // Deactivation: remove cron
        \register_deactivation_hook(SBDP_FILE, [$this, 'deactivate']);
    }

    public function scheduleCron(): void
    {
        if (! \wp_next_scheduled(self::CRON_HOOK)) {
            \wp_schedule_event(\time(), self::CRON_SCHEDULE, self::CRON_HOOK);
        }
    }

    public function runMonitor(): void
    {
        $service = $this->buildService();
        $service->run();
    }

    public function handleRunNow(): void
    {
        if (! \current_user_can('manage_options')) {
            \wp_die('Geen toegang');
        }

        \check_admin_referer('bsp_competitor_run_now');

        $this->runMonitor();

        \wp_redirect(\add_query_arg(
            ['page' => 'bsp-competitor-monitor', 'ran' => '1'],
            \admin_url('admin.php')
        ));
        exit;
    }

    public function handleSaveSettings(): void
    {
        if (! \current_user_can('manage_options')) {
            \wp_die('Geen toegang');
        }

        \check_admin_referer('bsp_competitor_save_settings');

        $email = \sanitize_email((string) ($_POST['bsp_competitor_notify_email'] ?? ''));
        \update_option('bsp_competitor_notify_email', $email, false);

        \wp_redirect(\add_query_arg(
            ['page' => 'bsp-competitor-monitor', 'saved' => '1'],
            \admin_url('admin.php')
        ));
        exit;
    }

    public function deactivate(): void
    {
        $timestamp = \wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp !== false) {
            \wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    private function buildService(): PriceMonitorService
    {
        return new PriceMonitorService(
            new EliioApiClient(self::TENANTS)
        );
    }
}
