<?php
declare(strict_types=1);

namespace BSP\Core\Interfaces;

/**
 * Contract implemented by Booking System Pro module classes.
 */
interface ModuleInterface
{
    /**
     * Perform module bootstrap such as registering hooks and REST routes.
     */
    public function init(): void;
}
