<?php
declare(strict_types=1);

namespace BSP\Core;

use BSP\Core\Helpers\Logger;

/**
 * Exposes shared services for Booking System Pro modules.
 */
final class CoreServiceProvider
{
    private static ?Logger $logger = null;

    /**
     * Retrieve a shared logger instance.
     */
    public static function logger(): Logger
    {
        if (null === self::$logger) {
            self::$logger = new Logger();
        }

        return self::$logger;
    }
}
