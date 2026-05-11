<?php

declare(strict_types=1);

namespace BSP\Support;

if (class_exists(__NAMESPACE__ . '\Module', false)) {
    return;
}

use BSP\Core\CoreServiceProvider;
use BSP\Core\ModuleInterface;
use BSP\Support\Automation\AutomationScheduler;
use BSP\Support\IntegrationManager;
use BSPModule\Support\LegacyModule as SupportLegacyModule;

final class Module implements ModuleInterface
{
    private static bool $booted = false;

    private SupportLegacyModule $legacy;

    private AutomationScheduler $automation;

    private IntegrationManager $integrations;

    public function __construct(
        ?SupportLegacyModule $legacy = null,
        ?AutomationScheduler $automation = null,
        ?IntegrationManager $integrations = null
    )
    {
        $logger           = CoreServiceProvider::logger();
        $this->legacy     = $legacy ?? new SupportLegacyModule();
        $this->automation = $automation ?? new AutomationScheduler($logger);
        $this->integrations = $integrations ?? new IntegrationManager($logger);
    }

    public function init(): void
    {
        if (self::$booted) {
            return;
        }

        $this->legacy->register();

        if (\function_exists('add_filter')) {
            \add_filter('cron_schedules', [$this->automation, 'registerCronSchedules']);
        }

        $this->automation->bootstrap();
        $this->ensureDefaultIntegrations();

        self::$booted = true;
    }

    private function ensureDefaultIntegrations(): void
    {
        if ([] !== $this->integrations->getActive()) {
            return;
        }

        $this->integrations->activate(
            [
                'payment'    => ['mollie', 'stripe'],
                'calendar'   => ['google', 'apple'],
                'crm'        => ['hubspot', 'mailchimp'],
                'analytics'  => ['ga4', 'facebook_pixel'],
                'webhooks'   => ['booking_created', 'booking_completed'],
            ]
        );
    }
}

namespace BSPModule\Support;

use BSPModule\Shared\Modules\ModuleInterface;
use BSP\Support\Admin\Menu as AdminMenu;
use BSP\Support\Agent\SupportModuleAgent;

use function class_exists;
use function do_action;
use function function_exists;

final class LegacyModule implements ModuleInterface
{
    public function moduleName(): string
    {
        return 'booking-support';
    }

    public function register(): void
    {
        AdminMenu::init();

        if (! function_exists('do_action')) {
            return;
        }

        do_action('bsp/module/register', $this->moduleName(), $this);

        if (class_exists('BSP_Core_Agent')) {
            \BSP_Core_Agent::instance()->register_agent(new SupportModuleAgent());
        }
    }
}

if (! class_exists(__NAMESPACE__ . '\\Module')) {
    class_alias(LegacyModule::class, __NAMESPACE__ . '\\Module');
}
