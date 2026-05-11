<?php
declare(strict_types=1);

namespace BSPModule\Shared\Modules;

/**
 * Legacy module interface retained for backwards-compatible tests.
 */
interface ModuleInterface
{
    public function moduleName(): string;

    public function register(): void;
}
