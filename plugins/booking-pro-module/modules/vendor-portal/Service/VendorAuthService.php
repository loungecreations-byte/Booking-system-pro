<?php

declare(strict_types=1);

namespace BSP\VendorPortal\Service;

use BSP\Sales\Vendors\VendorService;
use BSP\VendorPortal\Service\VendorPortalAdminService;
use InvalidArgumentException;
use Throwable;

final class VendorAuthService
{
    private const TOKEN_PREFIX        = 'sbdp_vendor_portal_token_';
    private const TOKEN_LIFETIME        = 28800;  // 8 hours (workday)
    private const TOKEN_LIFETIME_LONG   = 604800; // 7 days (remember me)

    /**
     * @var array<string, array<string, mixed>>
     */
    private static array $runtimeSessions = array();

    private VendorPortalAuditLogger $auditLogger;

    public function __construct(?VendorPortalAuditLogger $auditLogger = null)
    {
        $this->auditLogger = $auditLogger ?? new VendorPortalAuditLogger();
    }

    /**
     * @return array<string, mixed>
     */
    public function login(int $vendorId, string $accessKey, bool $rememberMe = false): array
    {
        if ($vendorId <= 0) {
            throw new InvalidArgumentException(__('Vendor ID ontbreekt.', 'sbdp'));
        }

        $accessKey = trim($accessKey);
        if ($accessKey === '') {
            throw new InvalidArgumentException(__('Toegangssleutel is verplicht.', 'sbdp'));
        }

        $isValid = $this->validateAccessKey($vendorId, $accessKey);
        if (! $isValid) {
            throw new InvalidArgumentException(__('Ongeldige inloggegevens.', 'sbdp'));
        }

        $token    = bin2hex(random_bytes(16));
        $lifetime = $rememberMe ? self::TOKEN_LIFETIME_LONG : self::TOKEN_LIFETIME;
        $session  = $this->createSessionPayload($vendorId, $lifetime);

        $this->persistSession($token, $session);

        try {
            (new VendorPortalAdminService())->recordLogin($vendorId);
        } catch (Throwable $exception) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
        }

        $this->auditLogger->log('login_success', array(
            'vendor_id' => (string) $vendorId,
            'session'   => $this->tokenFingerprint($token),
            'expires_at'=> $session['expires_at'] ?? '',
        ));

        return array(
            'token'       => $token,
            'expires_in'  => $session['expires_in'],
            'vendor_id'   => $vendorId,
            'remember_me' => $rememberMe,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function validateToken(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            throw new InvalidArgumentException(__('Authenticatie vereist.', 'sbdp'));
        }

        $session = $this->readSession($token);
        if (! is_array($session) || empty($session['vendor_id'])) {
            $this->auditLogger->log('session_invalid', array('session' => $this->tokenFingerprint($token)));
            throw new InvalidArgumentException(__('Sessie is verlopen of ongeldig.', 'sbdp'));
        }

        $now       = time();
        $expiresAt = isset($session['expires_at']) ? (int) $session['expires_at'] : ($now + self::TOKEN_LIFETIME);
        if ($expiresAt <= $now) {
            $this->destroyToken($token, false);
            $this->auditLogger->log('session_expired', array('session' => $this->tokenFingerprint($token)));
            throw new InvalidArgumentException(__('Sessie is verlopen of ongeldig.', 'sbdp'));
        }

        $lifetime                = ! empty($session['remember_me']) ? self::TOKEN_LIFETIME_LONG : self::TOKEN_LIFETIME;
        $session['last_seen_at'] = gmdate('c', $now);
        $session['expires_at']   = $now + $lifetime;
        $session['expires_in']   = $lifetime;

        $this->persistSession($token, $session);

        return $session;
    }

    public function destroyToken(string $token, bool $log = false): void
    {
        if ($token === '') {
            return;
        }

        unset(self::$runtimeSessions[$token]);

        if (function_exists('delete_transient')) {
            delete_transient($this->buildCacheKey($token));
        }

        if ($log) {
            $this->auditLogger->log('logout', array('session' => $this->tokenFingerprint($token)));
        }
    }

    private function validateAccessKey(int $vendorId, string $accessKey): bool
    {
        $accessKey = trim($accessKey);
        if ($accessKey === '') {
            return false;
        }

        $valid = $this->matchesVendorAccessKey($vendorId, $accessKey);

        if (function_exists('apply_filters')) {
            /** @var bool $valid */
            $valid = (bool) apply_filters('sbdp/vendor_portal/validate_key', $valid, $vendorId, $accessKey);
        }

        return $valid;
    }

    private function matchesVendorAccessKey(int $vendorId, string $accessKey): bool
    {
        $candidates = $this->resolveVendorAccessKeys($vendorId);
        if ($candidates === array()) {
            return false;
        }

        foreach ($candidates as $candidate) {
            $type  = isset($candidate['type']) ? (string) $candidate['type'] : 'plain';
            $value = isset($candidate['value']) ? (string) $candidate['value'] : '';
            if ($value === '') {
                continue;
            }

            if ($type === 'hash' && $this->verifyPasswordHash($accessKey, $value)) {
                return true;
            }

            if ($type !== 'hash' && $this->comparePlainKey($value, $accessKey)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array{type:string,value:string}>
     */
    private function resolveVendorAccessKeys(int $vendorId): array
    {
        $keys = array();

        if ($vendorId > 0) {
            $keys = $this->collectVendorAccessKeyMetadata($vendorId);
        }

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('sbdp/vendor_portal/access_keys', $keys, $vendorId);
            if (is_array($filtered)) {
                $keys = $this->normaliseCandidates($filtered);
            }
        }

        $keys = $this->deduplicateCandidates($keys);

        if ($keys === array()) {
            $fallback = $this->defaultAccessKey($vendorId);
            if ($fallback !== null) {
                $keys[] = array(
                    'type'  => 'plain',
                    'value' => $fallback,
                );
            }
        }

        return $keys;
    }

    /**
     * @return array<int, array{type:string,value:string}>
     */
    private function collectVendorAccessKeyMetadata(int $vendorId): array
    {
        if (! class_exists(VendorService::class)) {
            return array();
        }

        try {
            VendorService::init();
            $vendor = VendorService::get($vendorId, true);
        } catch (Throwable $exception) {
            return array();
        }

        if (! is_array($vendor)) {
            return array();
        }

        $candidates = array();

        $plainKeys = array(
            'portal_key',
            'vendor_portal_key',
            'access_key',
        );
        $hashKeys = array(
            'portal_key_hash',
            'vendor_portal_key_hash',
            'access_key_hash',
        );

        foreach ($plainKeys as $field) {
            if (isset($vendor[$field]) && is_string($vendor[$field]) && $vendor[$field] !== '') {
                $candidates[] = array(
                    'type'  => 'plain',
                    'value' => (string) $vendor[$field],
                );
            }
        }

        foreach ($hashKeys as $field) {
            if (isset($vendor[$field]) && is_string($vendor[$field]) && $vendor[$field] !== '') {
                $candidates[] = array(
                    'type'  => 'hash',
                    'value' => (string) $vendor[$field],
                );
            }
        }

        if (isset($vendor['metadata']) && is_array($vendor['metadata'])) {
            foreach ($plainKeys as $field) {
                if (isset($vendor['metadata'][$field]) && is_string($vendor['metadata'][$field]) && $vendor['metadata'][$field] !== '') {
                    $candidates[] = array(
                        'type'  => 'plain',
                        'value' => (string) $vendor['metadata'][$field],
                    );
                }
            }
            foreach ($hashKeys as $field) {
                if (isset($vendor['metadata'][$field]) && is_string($vendor['metadata'][$field]) && $vendor['metadata'][$field] !== '') {
                    $candidates[] = array(
                        'type'  => 'hash',
                        'value' => (string) $vendor['metadata'][$field],
                    );
                }
            }
        }

        return $candidates;
    }

    /**
     * @param array<int, mixed> $candidates
     * @return array<int, array{type:string,value:string}>
     */
    private function normaliseCandidates(array $candidates): array
    {
        $normalised = array();

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $type  = isset($candidate['type']) ? strtolower((string) $candidate['type']) : 'plain';
            $value = isset($candidate['value']) ? (string) $candidate['value'] : '';

            if ($value === '') {
                continue;
            }

            if ($type !== 'hash') {
                $type = 'plain';
            }

            $normalised[] = array(
                'type'  => $type,
                'value' => $value,
            );
        }

        return $normalised;
    }

    /**
     * @param array<int, array{type:string,value:string}> $candidates
     * @return array<int, array{type:string,value:string}>
     */
    private function deduplicateCandidates(array $candidates): array
    {
        $unique = array();

        foreach ($candidates as $candidate) {
            $key = $candidate['type'] . ':' . $candidate['value'];
            $unique[$key] = $candidate;
        }

        return array_values($unique);
    }

    private function verifyPasswordHash(string $accessKey, string $hash): bool
    {
        if (function_exists('wp_check_password')) {
            return (bool) wp_check_password($accessKey, $hash);
        }

        if (function_exists('password_verify')) {
            return password_verify($accessKey, $hash);
        }

        return false;
    }

    private function comparePlainKey(string $expected, string $provided): bool
    {
        if ($expected === '') {
            return false;
        }

        if (function_exists('hash_equals')) {
            return hash_equals($expected, $provided);
        }

        return $expected === $provided;
    }

    private function buildCacheKey(string $token): string
    {
        return self::TOKEN_PREFIX . $token;
    }

    /**
     * @return array<string, mixed>
     */
    private function createSessionPayload(int $vendorId, int $lifetime = self::TOKEN_LIFETIME): array
    {
        $issuedAt  = time();
        $expiresAt = $issuedAt + $lifetime;

        return array(
            'vendor_id'    => $vendorId,
            'issued_at'    => gmdate('c', $issuedAt),
            'last_seen_at' => gmdate('c', $issuedAt),
            'expires_at'   => $expiresAt,
            'expires_in'   => $lifetime,
            'remember_me'  => $lifetime === self::TOKEN_LIFETIME_LONG,
        );
    }

    private function tokenFingerprint(string $token): string
    {
        return substr(hash('sha256', $token), 0, 12);
    }

    private function persistSession(string $token, array $session): void
    {
        self::$runtimeSessions[$token] = $session;

        if (function_exists('set_transient')) {
            $ttl = isset($session['expires_in']) ? (int) $session['expires_in'] : self::TOKEN_LIFETIME;
            set_transient($this->buildCacheKey($token), $session, $ttl);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readSession(string $token): ?array
    {
        if (function_exists('get_transient')) {
            $session = get_transient($this->buildCacheKey($token));
            if (is_array($session)) {
                return $session;
            }
        }

        return self::$runtimeSessions[$token] ?? null;
    }

    private function defaultAccessKey(int $vendorId): ?string
    {
        $fallback = null;
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('sbdp/vendor_portal/default_access_key', $fallback, $vendorId);
            if (is_string($filtered) || is_numeric($filtered)) {
                $fallback = (string) $filtered;
            }
        }

        $fallback = trim((string) $fallback);

        return $fallback === '' ? null : $fallback;
    }
}
