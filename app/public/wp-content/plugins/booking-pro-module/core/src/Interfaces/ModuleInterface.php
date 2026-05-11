<?php
declare(strict_types=1);

namespace BSP\Core\Interfaces;

if (\interface_exists(__NAMESPACE__ . '\ModuleInterface', false)) {
    return;
}

/**
 * Contract implemented by every Booking System Pro module.
 */
interface ModuleInterface
{
    /**
     * Perform module bootstrap: register hooks, services, and REST routes.
     */
    public function init(): void;
}
