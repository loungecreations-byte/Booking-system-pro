<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

final class PartnerConfirmationTokenService
{
    /**
     * @return array{token:string,token_id:string,token_hash:string}
     */
    public function create(int $quoteId, int $versionId, int $lineId, string $quoteReference): array
    {
        $secret = $this->randomSecret();
        $payload = $this->base64UrlEncode((string) json_encode(array(
            'q' => $quoteId,
            'v' => $versionId,
            'l' => $lineId,
            'r' => $quoteReference,
            'n' => $secret,
        )));
        $token = $payload . '.' . $this->signature($payload);

        return array(
            'token' => $token,
            'token_id' => $this->tokenId($token),
            'token_hash' => $this->secretHash($secret),
        );
    }

    /**
     * @return array{quote_id:int,version_id:int,line_id:int,quote_reference:string,secret_hash:string,token_id:string}|null
     */
    public function verify(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || ! str_contains($token, '.')) {
            return null;
        }

        [$payload, $signature] = explode('.', $token, 2);
        if ($payload === '' || $signature === '' || ! hash_equals($this->signature($payload), $signature)) {
            return null;
        }

        $decoded = json_decode($this->base64UrlDecode($payload), true);
        if (! is_array($decoded)) {
            return null;
        }

        $quoteId = (int) ($decoded['q'] ?? 0);
        $versionId = (int) ($decoded['v'] ?? 0);
        $lineId = (int) ($decoded['l'] ?? 0);
        $quoteReference = trim((string) ($decoded['r'] ?? ''));
        $secret = trim((string) ($decoded['n'] ?? ''));
        if ($quoteId <= 0 || $versionId <= 0 || $lineId <= 0 || $quoteReference === '' || $secret === '') {
            return null;
        }

        return array(
            'quote_id' => $quoteId,
            'version_id' => $versionId,
            'line_id' => $lineId,
            'quote_reference' => $quoteReference,
            'secret_hash' => $this->secretHash($secret),
            'token_id' => $this->tokenId($token),
        );
    }

    public function tokenId(string $token): string
    {
        return substr(hash('sha256', trim($token)), 0, 16);
    }

    private function randomSecret(): string
    {
        try {
            return bin2hex(random_bytes(32));
        } catch (\Throwable) {
            return hash('sha256', uniqid('bsp_partner_', true) . microtime(true));
        }
    }

    private function secretHash(string $secret): string
    {
        return hash_hmac('sha256', $secret, $this->secret());
    }

    private function signature(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret());
    }

    private function secret(): string
    {
        if (function_exists('wp_salt')) {
            return (string) wp_salt('auth');
        }

        if (defined('AUTH_SALT')) {
            return (string) AUTH_SALT;
        }

        return 'bsp-quotes-partner-confirmation';
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return is_string($decoded) ? $decoded : '';
    }
}
