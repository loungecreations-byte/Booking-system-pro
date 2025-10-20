<?php

declare(strict_types=1);

namespace BSP\VendorPortal\Service;

use InvalidArgumentException;

final class VendorAuthService
{
    private const TOKEN_PREFIX   = 'sbdp_vendor_portal_token_';
    private const TOKEN_LIFETIME = 3600; // 1 hour

    /**
     * @return array<string, mixed>
     */
    public function login(int $vendorId, string $accessKey): array
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

        $token = bin2hex(random_bytes(16));

        $session = array(
            'vendor_id'  => $vendorId,
            'issued_at'  => gmdate('c'),
            'expires_in' => self::TOKEN_LIFETIME,
        );

        if (function_exists('set_transient')) {
            set_transient($this->buildCacheKey($token), $session, self::TOKEN_LIFETIME);
        }

        return array(
            'token'      => $token,
            'expires_in' => self::TOKEN_LIFETIME,
            'vendor_id'  => $vendorId,
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

        if (! function_exists('get_transient')) {
            return array('vendor_id' => 0);
        }

        $session = get_transient($this->buildCacheKey($token));
        if (! is_array($session) || empty($session['vendor_id'])) {
            throw new InvalidArgumentException(__('Sessie is verlopen of ongeldig.', 'sbdp'));
        }

        return $session;
    }

    public function destroyToken(string $token): void
    {
        if ($token === '' || ! function_exists('delete_transient')) {
            return;
        }

        delete_transient($this->buildCacheKey($token));
    }

    private function validateAccessKey(int $vendorId, string $accessKey): bool
    {
        $valid = ($accessKey === 'demo');

        if (function_exists('apply_filters')) {
            /** @var bool $valid */
            $valid = (bool) apply_filters('sbdp/vendor_portal/validate_key', $valid, $vendorId, $accessKey);
        }

        return $valid;
    }

    private function buildCacheKey(string $token): string
    {
        return self::TOKEN_PREFIX . $token;
    }
}
