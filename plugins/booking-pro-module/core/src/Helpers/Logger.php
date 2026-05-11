<?php
declare(strict_types=1);

namespace BSP\Core\Helpers;

/**
 * Simple wrapper that proxies messages to error_log with a BSP prefix.
 */
final class Logger
{
    /**
     * Common indicators that a message is operationally important.
     *
     * @var string[]
     */
    private const PRIORITY_KEYWORDS = [
        'error',
        'failed',
        'exception',
        'fatal',
        'invalid',
        'denied',
        'unauthor',
    ];

    /**
     * Log a message through PHP's error_log function.
     */
    public function log(string $message): void
    {
        if (! $this->shouldLog($message)) {
            return;
        }

        \error_log('[BSP] ' . $message);
    }

    private function shouldLog(string $message): bool
    {
        if (\defined('BSP_ENABLE_LOGGING') && BSP_ENABLE_LOGGING) {
            return true;
        }

        $normalized = \strtolower($message);
        foreach (self::PRIORITY_KEYWORDS as $keyword) {
            if (\str_contains($normalized, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
