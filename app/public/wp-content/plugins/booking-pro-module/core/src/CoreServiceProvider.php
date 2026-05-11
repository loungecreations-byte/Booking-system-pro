<?php
declare(strict_types=1);

namespace BSP\Core;

use BSP\Core\Helpers\Logger;

/**
 * Provides access to shared core services.
 */
final class CoreServiceProvider
{
    private static ?Logger $logger = null;

    /**
     * Retrieve a PSR-simple logger instance.
     */
    public static function logger(): Logger
    {
        if (null === self::$logger) {
            self::$logger = new Logger();
        }

        return self::$logger;
    }
}
