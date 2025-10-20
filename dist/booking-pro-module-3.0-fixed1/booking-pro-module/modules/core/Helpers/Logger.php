<?php
declare(strict_types=1);

namespace BSP\Core\Helpers;

/**
 * Minimal logger that proxies messages to error_log with a BSP prefix.
 */
final class Logger
{
    /**
     * Log the provided message.
     */
    public function log(string $message): void
    {
        if (function_exists('error_log')) {
            error_log('[BSP] ' . $message);
        }
    }
}
