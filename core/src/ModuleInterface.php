<?php

declare(strict_types=1);

namespace BSP\Core;

interface ModuleInterface
{
    public function init(): void;
}

if (! \interface_exists(\BSP\Core\Interfaces\ModuleInterface::class)) {
    // Provide backwards compatibility for builds expecting the new namespace.
    \class_alias(__NAMESPACE__ . '\ModuleInterface', \BSP\Core\Interfaces\ModuleInterface::class);
}
