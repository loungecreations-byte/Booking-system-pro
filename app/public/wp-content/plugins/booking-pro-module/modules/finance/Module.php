<?php

declare(strict_types=1);

namespace BSP\Finance;

use BSP\Core\ModuleInterface;
use BSPModule\Finance\LegacyModule as FinanceLegacyModule;
use BPM\Modules\Finance\FinanceModule as ModernFinanceModule;

if (class_exists(__NAMESPACE__ . '\\Module', false)) {
    return;
}

final class Module implements ModuleInterface
{
    private static bool $booted = false;

    private FinanceLegacyModule $legacy;

    public function __construct(?FinanceLegacyModule $legacy = null)
    {
        $this->legacy = $legacy ?? new FinanceLegacyModule();
    }

    public function init(): void
    {
        if (self::$booted) {
            return;
        }

        if (class_exists(ModernFinanceModule::class)) {
            ModernFinanceModule::boot();
        }

        $this->legacy->register();
        self::$booted = true;
    }
}

namespace BSPModule\Finance;

use BSPModule\Finance\Agent\FinanceModuleAgent;
use BSPModule\Shared\Modules\ModuleInterface;
use BPM\Modules\Finance\FinanceModule;

use function class_exists;
use function do_action;
use function function_exists;

final class LegacyModule implements ModuleInterface
{
    public function moduleName(): string
    {
        return 'booking-finance';
    }

    public function register(): void
    {
        if (class_exists(FinanceModule::class)) {
            FinanceModule::boot();
        }

        if (! function_exists('do_action')) {
            return;
        }

        do_action('bsp/module/register', $this->moduleName(), $this);

        if (class_exists('BSP_Core_Agent')) {
            \BSP_Core_Agent::instance()->register_agent(new FinanceModuleAgent());
        }
    }
}

if (! class_exists(__NAMESPACE__ . '\\Module')) {
    class_alias(LegacyModule::class, __NAMESPACE__ . '\\Module');
}
