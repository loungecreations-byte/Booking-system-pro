<?php

declare(strict_types=1);

namespace SBDP\Contracts;

use SBDP\BookingEngine;

/**
 * Contracts implemented by Booking Pro modules that expect the engine context.
 */
interface ModuleInterface
{
    /**
     * Bootstrap the module and register hooks against the booking engine.
     */
    public function register(BookingEngine $engine): void;
}
