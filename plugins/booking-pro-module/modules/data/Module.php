<?php

declare(strict_types=1);

namespace BSP\Data;

use BSP\Core\ModuleInterface;
use BSPModule\Data\LegacyModule as DataLegacyModule;

if (class_exists(__NAMESPACE__ . '\\Module', false)) {
    return;
}

final class Module implements ModuleInterface
{
    private static bool $booted = false;

    private DataLegacyModule $legacy;

    public function __construct(?DataLegacyModule $legacy = null)
    {
        $this->legacy = $legacy ?? new DataLegacyModule();
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

namespace BSPModule\Data;

use BSPModule\Data\Agent\DataModuleAgent;
use BSPModule\Data\Admin\Menu;
use BSPModule\Shared\Modules\ModuleInterface;

use function class_exists;
use function do_action;
use function function_exists;

final class LegacyModule implements ModuleInterface
{
    public function moduleName(): string
    {
        return 'booking-data';
    }

    public function register(): void
    {
        if (! function_exists('do_action')) {
            return;
        }

        do_action('bsp/module/register', $this->moduleName(), $this);
        Menu::init();

        if (class_exists('BSP_Core_Agent')) {
            \BSP_Core_Agent::instance()->register_agent(new DataModuleAgent());
        }
    }
}

if (! class_exists(__NAMESPACE__ . '\\Module')) {
    class_alias(LegacyModule::class, __NAMESPACE__ . '\\Module');
}
