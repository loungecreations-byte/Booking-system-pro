<?php

declare(strict_types=1);

namespace BSP\Planner\Services\Planboard;

final class PlanboardCache
{
    private const CACHE_GROUP = 'sbdp_planboard';
    private const TRANSIENT_PREFIX = 'sbdp_planboard_';
    private const DEFAULT_TTL = 120;
    private const INDEX_OPTION = 'sbdp_planboard_cache_keys';

    public static function get(string $key)
    {
        if (function_exists('wp_cache_get')) {
            $cached = wp_cache_get($key, self::CACHE_GROUP);
            if ($cached !== false) {
                return $cached;
            }
        }

        if (function_exists('get_transient')) {
            $cached = get_transient(self::TRANSIENT_PREFIX . $key);
            if ($cached !== false) {
                return $cached;
            }
        }

        return null;
    }

    public static function set(string $key, $value, ?int $ttl = null): void
    {
        $ttl = $ttl !== null ? max(10, $ttl) : self::DEFAULT_TTL;

        if (function_exists('wp_cache_set')) {
            wp_cache_set($key, $value, self::CACHE_GROUP, $ttl);
        }

        if (function_exists('set_transient')) {
            set_transient(self::TRANSIENT_PREFIX . $key, $value, $ttl);
        }

        self::trackKey($key);
    }

    public static function invalidate(?string $scope = null): void
    {
        $keys = self::getTrackedKeys();

        if ($scope !== null) {
            $keys = array_values(array_filter($keys, static fn (string $key): bool => $key === $scope));
        }

        foreach ($keys as $key) {
            if (function_exists('wp_cache_delete')) {
                wp_cache_delete($key, self::CACHE_GROUP);
            }

            if (function_exists('delete_transient')) {
                delete_transient(self::TRANSIENT_PREFIX . $key);
            }
        }

        if ($scope === null) {
            self::clearTrackedKeys();
        }
    }

    public static function buildKey(string $prefix, array $filters): string
    {
        $tenant = function_exists('get_current_blog_id') ? (string) get_current_blog_id() : '0';
        $locale = function_exists('determine_locale') ? (string) determine_locale() : 'default';

        $payload = array_merge(
            array(
                'tenant' => $tenant,
                'locale' => $locale,
            ),
            $filters
        );

        $encoded = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);
        $hash = is_string($encoded) ? md5($encoded) : md5(serialize($payload));

        return $prefix . '_' . $hash;
    }

    public static function registerHooks(): void
    {
        if (! function_exists('add_action')) {
            return;
        }

        $events = array(
            'sbdp/planboard/booking/created',
            'sbdp/planboard/booking/moved',
            'sbdp/planboard/booking/checkin',
            'sbdp/planboard/payment/added',
            'sbdp/planboard/rules/changed',
        );

        foreach ($events as $event) {
            add_action(
                $event,
                static function (): void {
                    self::invalidate();
                },
                10,
                0
            );
        }
    }

    private static function trackKey(string $key): void
    {
        if (! function_exists('get_option') || ! function_exists('update_option')) {
            return;
        }

        $keys = self::getTrackedKeys();
        if (! in_array($key, $keys, true)) {
            $keys[] = $key;
            update_option(self::INDEX_OPTION, $keys, false);
        }
    }

    /**
     * @return array<int, string>
     */
    private static function getTrackedKeys(): array
    {
        if (! function_exists('get_option')) {
            return array();
        }

        $keys = get_option(self::INDEX_OPTION, array());
        if (! is_array($keys)) {
            return array();
        }

        $normalized = array();
        foreach ($keys as $key) {
            if (is_string($key) && $key !== '') {
                $normalized[] = $key;
            }
        }

        return array_values(array_unique($normalized));
    }

    private static function clearTrackedKeys(): void
    {
        if (function_exists('update_option')) {
            update_option(self::INDEX_OPTION, array(), false);
        }
    }
}
