<?php

declare(strict_types=1);

namespace BSP\DayPlanner\Service;

final class SessionContextStore
{
    private const TTL_HOURS = 224;
    private const OPTION_PREFIX = 'sbdp_dayplanner_session_';
    private const CACHE_GROUP = 'sbdp_dayplanner_sessions';

    /**
     * @return array<string, mixed>
     */
    public function load(string $sessionId): array
    {
        $key = $this->storageKey($sessionId);
        if ($key === '') {
            return $this->defaultContext();
        }

        $fromObjectCache = $this->loadFromObjectCache($key);
        if ($fromObjectCache !== null) {
            return $this->normalise($fromObjectCache);
        }

        $fromTransient = function_exists('get_transient') ? get_transient($key) : false;
        if (is_array($fromTransient)) {
            return $this->normalise($fromTransient);
        }

        if (is_string($fromTransient)) {
            $decoded = json_decode($fromTransient, true);
            if (is_array($decoded)) {
                return $this->normalise($decoded);
            }
        }

        if (! function_exists('get_option')) {
            return $this->defaultContext();
        }

        $raw = get_option(self::OPTION_PREFIX . $key);
        if (! is_string($raw) || $raw === '') {
            return $this->defaultContext();
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $this->defaultContext();
        }

        $expiresAt = isset($decoded['expires_at']) ? (int) $decoded['expires_at'] : 0;
        if ($expiresAt > 0 && $expiresAt < time()) {
            $this->clear($sessionId);
            return $this->defaultContext();
        }

        $context = is_array($decoded['context'] ?? null) ? $decoded['context'] : $decoded;

        return $this->normalise($context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function save(string $sessionId, array $context): void
    {
        $key = $this->storageKey($sessionId);
        if ($key === '') {
            return;
        }

        $normalised = $this->normalise($context);
        $ttl = self::TTL_HOURS * 3600;
        $this->saveToObjectCache($key, $normalised, $ttl);

        if (function_exists('set_transient')) {
            set_transient($key, $normalised, $ttl);
        }

        if (! function_exists('update_option')) {
            return;
        }

        $payload = [
            'expires_at' => time() + $ttl,
            'context'    => $normalised,
        ];
        update_option(self::OPTION_PREFIX . $key, wp_json_encode($payload), false);
    }

    public function clear(string $sessionId): void
    {
        $key = $this->storageKey($sessionId);
        if ($key === '') {
            return;
        }

        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($key, self::CACHE_GROUP);
        }

        if (function_exists('delete_transient')) {
            delete_transient($key);
        }

        if (function_exists('delete_option')) {
            delete_option(self::OPTION_PREFIX . $key);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadFromObjectCache(string $key): ?array
    {
        if (! $this->canUseObjectCache()) {
            return null;
        }

        $cached = wp_cache_get($key, self::CACHE_GROUP);
        if ($cached === false || $cached === null) {
            return null;
        }

        if (is_array($cached)) {
            if (isset($cached['expires_at']) && (int) $cached['expires_at'] > 0 && (int) $cached['expires_at'] < time()) {
                wp_cache_delete($key, self::CACHE_GROUP);
                return null;
            }

            $payload = is_array($cached['context'] ?? null) ? $cached['context'] : $cached;
            return is_array($payload) ? $payload : null;
        }

        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                $payload = is_array($decoded['context'] ?? null) ? $decoded['context'] : $decoded;
                return is_array($payload) ? $payload : null;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function saveToObjectCache(string $key, array $context, int $ttl): void
    {
        if (! $this->canUseObjectCache()) {
            return;
        }

        $payload = [
            'expires_at' => time() + $ttl,
            'context'    => $context,
        ];

        wp_cache_set($key, $payload, self::CACHE_GROUP, $ttl);
    }

    private function canUseObjectCache(): bool
    {
        if (! function_exists('wp_cache_get') || ! function_exists('wp_cache_set')) {
            return false;
        }

        if (function_exists('wp_using_ext_object_cache')) {
            return (bool) wp_using_ext_object_cache();
        }

        return true;
    }

    private function storageKey(string $sessionId): string
    {
        $trimmed = trim($sessionId);
        if ($trimmed === '') {
            return '';
        }

        $sanitised = preg_replace('/[^A-Za-z0-9\-_]/', '', $trimmed);
        if (! is_string($sanitised) || $sanitised === '') {
            return '';
        }

        return 'sess_' . substr($sanitised, 0, 64);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function normalise(array $context): array
    {
        $default = $this->defaultContext();
        $constraints = is_array($context['constraints'] ?? null) ? $context['constraints'] : [];
        $flowState = isset($context['flow_state']) ? (string) $context['flow_state'] : '';
        $stickyType = isset($context['sticky_primary_type']) ? (string) $context['sticky_primary_type'] : null;
        $stickyId = $context['sticky_primary_id'] ?? null;
        $lastCandidates = is_array($context['last_candidates_shown'] ?? null) ? array_values($context['last_candidates_shown']) : [];
        $attribution = is_array($context['attribution'] ?? null) ? $context['attribution'] : [];
        $traceId = isset($context['trace_id']) ? (string) $context['trace_id'] : '';
        $turnCount = max(0, (int) ($context['turn_count'] ?? 0));
        $sessionStartedAt = isset($context['session_started_at']) ? (string) $context['session_started_at'] : '';
        $firstPrimaryAt = isset($context['first_primary_at']) ? (string) $context['first_primary_at'] : '';
        $timeToPrimarySeconds = max(0, (int) ($context['time_to_primary_seconds'] ?? 0));
        $experimentVariant = strtoupper(trim((string) ($context['experiment_variant'] ?? '')));
        if (! in_array($experimentVariant, array('A', 'B'), true)) {
            $experimentVariant = 'A';
        }

        $normalised = $default;
        $normalised['constraints'] = array_merge($default['constraints'], $constraints);
        $normalised['flow_state'] = $flowState;
        $normalised['sticky_primary_type'] = in_array($stickyType, ['offer', 'spot'], true) ? $stickyType : null;
        $normalised['sticky_primary_id'] = is_scalar($stickyId) ? $stickyId : null;
        $normalised['last_candidates_shown'] = $lastCandidates;
        $normalised['attribution'] = $attribution;
        $normalised['trace_id'] = $traceId;
        $normalised['turn_count'] = $turnCount;
        $normalised['session_started_at'] = $sessionStartedAt !== '' ? $sessionStartedAt : gmdate('c');
        $normalised['first_primary_at'] = $firstPrimaryAt;
        $normalised['time_to_primary_seconds'] = $timeToPrimarySeconds;
        $normalised['experiment_variant'] = $experimentVariant;
        $normalised['updated_at'] = gmdate('c');

        return $normalised;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultContext(): array
    {
        return [
            'sticky_primary_type'  => null,
            'sticky_primary_id'    => null,
            'constraints'          => [
                'date'             => null,
                'start_time'       => null,
                'duration_minutes' => null,
                'pax'              => null,
                'budget_band'      => null,
                'vibe_tags'        => [],
                'kids'             => null,
                'wheelchair'       => null,
                'rainy_day'        => null,
                'area_preference'  => null,
            ],
            'last_candidates_shown' => [],
            'flow_state'            => '',
            'attribution'           => [],
            'trace_id'              => '',
            'turn_count'            => 0,
            'session_started_at'    => '',
            'first_primary_at'      => '',
            'time_to_primary_seconds' => 0,
            'experiment_variant'    => 'A',
            'updated_at'            => gmdate('c'),
        ];
    }
}
