<?php
declare(strict_types=1);

namespace BSP\Core\Helpers;

/**
 * Simple wrapper that proxies messages to error_log with a BSP prefix.
 */
final class Logger
{
    /**
     * Log a message through PHP's error_log function.
     */
    public function log(string $message): void
    {
        \error_log('[BSP] ' . $message);
    }
}
