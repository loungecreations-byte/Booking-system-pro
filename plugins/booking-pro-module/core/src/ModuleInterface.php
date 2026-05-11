<?php

declare(strict_types=1);

namespace BSP\Core;

if (! \interface_exists(\BSP\Core\Interfaces\ModuleInterface::class, false)) {
    interface ModuleInterface
    {
        public function init(): void;
    }

    \class_alias(__NAMESPACE__ . '\ModuleInterface', \BSP\Core\Interfaces\ModuleInterface::class);

    return;
}

if (! \interface_exists(__NAMESPACE__ . '\ModuleInterface', false)) {
    \class_alias(\BSP\Core\Interfaces\ModuleInterface::class, __NAMESPACE__ . '\ModuleInterface');
}
