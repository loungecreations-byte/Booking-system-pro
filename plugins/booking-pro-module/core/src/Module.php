<?php

declare(strict_types=1);

namespace BSP\Core;

use BSP\Core\Interfaces\ModuleInterface;
use BSPModule\Core\Module as LegacyCoreModule;

final class Module implements ModuleInterface
{
    private static bool $booted = false;

    private LegacyCoreModule $legacy;

    public function __construct(?LegacyCoreModule $legacy = null)
    {
        $this->legacy = $legacy ?? new LegacyCoreModule();
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
