<?php

declare(strict_types=1);

namespace BSP\Planner\Services\Planboard;

final class PlanboardLock
{
    private const OPTION_PREFIX = 'sbdp_planboard_lock_';

    public static function acquire(string $key, int $ttl = 15): bool
    {
        $key = trim($key);
        if ($key === '') {
            return false;
        }

        $ttl = max(5, $ttl);
        $optionKey = self::OPTION_PREFIX . $key;

        if (function_exists('wp_cache_add')) {
            $added = wp_cache_add($optionKey, time(), 'sbdp_planboard', $ttl);
            if ($added) {
                return true;
            }
        }

        if (! function_exists('add_option')) {
            return false;
        }

        $expiresAt = time() + $ttl;
        $added = add_option($optionKey, $expiresAt, '', false);
        if ($added) {
            return true;
        }

        $existing = function_exists('get_option') ? get_option($optionKey) : null;
        if (is_numeric($existing) && (int) $existing < time()) {
            delete_option($optionKey);
            return add_option($optionKey, $expiresAt, '', false);
        }

        return false;
    }

    public static function release(string $key): void
    {
        $key = trim($key);
        if ($key === '') {
            return;
        }

        $optionKey = self::OPTION_PREFIX . $key;

        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($optionKey, 'sbdp_planboard');
        }

        if (function_exists('delete_option')) {
            delete_option($optionKey);
        }
    }
}
