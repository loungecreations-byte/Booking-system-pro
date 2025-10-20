<?php

declare(strict_types=1);

namespace BSP\Support;

use BSP\Core\ModuleInterface;
use BSPModule\Support\LegacyModule as SupportLegacyModule;

final class Module implements ModuleInterface
{
    private static bool $booted = false;

    private SupportLegacyModule $legacy;

    public function __construct(?SupportLegacyModule $legacy = null)
    {
        $this->legacy = $legacy ?? new SupportLegacyModule();
    }

    public function init(): void
    {
        if (self::$booted) {
            return;
        }

        $this->legacy->register();
        self::$booted = true;
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
