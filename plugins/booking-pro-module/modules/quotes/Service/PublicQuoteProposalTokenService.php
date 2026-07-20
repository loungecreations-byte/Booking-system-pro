<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

final class PublicQuoteProposalTokenService
{
    private const DEFAULT_TTL_SECONDS = 2592000;

    public function create(int $quoteId, int $versionId, string $quoteReference, ?int $expiresAt = null): string
    {
        $issuedAt = $this->now();
        $expiresAt = $expiresAt ?? ($issuedAt + $this->ttlSeconds());
        $payload = $this->base64UrlEncode((string) json_encode(array(
            'q' => $quoteId,
            'v' => $versionId,
            'r' => $quoteReference,
            'iat' => $issuedAt,
            'exp' => $expiresAt,
        )));

        return $payload . '.' . $this->signature($payload);
    }

    /**
     * @return array{ok:bool,reason:string,payload?:array{quote_id:int,version_id:int,quote_reference:string,issued_at?:int,expires_at?:int}}
     */
    public function verifyDetailed(string $token): array
    {
        $token = trim($token);
        if ($token === '' || ! str_contains($token, '.')) {
            return array('ok' => false, 'reason' => 'invalid');
        }

        [$payload, $signature] = explode('.', $token, 2);
        if ($payload === '' || $signature === '' || ! hash_equals($this->signature($payload), $signature)) {
            return array('ok' => false, 'reason' => 'invalid_signature');
        }

        $decoded = json_decode($this->base64UrlDecode($payload), true);
        if (! is_array($decoded)) {
            return array('ok' => false, 'reason' => 'invalid_payload');
        }

        $quoteId = (int) ($decoded['q'] ?? 0);
        $versionId = (int) ($decoded['v'] ?? 0);
        $quoteReference = trim((string) ($decoded['r'] ?? ''));
        if ($quoteId <= 0 || $versionId <= 0 || $quoteReference === '') {
            return array('ok' => false, 'reason' => 'invalid_payload');
        }

        $expiresAt = isset($decoded['exp']) ? (int) $decoded['exp'] : 0;
        if ($expiresAt > 0 && $expiresAt < $this->now()) {
            return array('ok' => false, 'reason' => 'expired');
        }

        $verified = array(
            'quote_id' => $quoteId,
            'version_id' => $versionId,
            'quote_reference' => $quoteReference,
        );
        if (isset($decoded['iat'])) {
            $verified['issued_at'] = (int) $decoded['iat'];
        }
        if ($expiresAt > 0) {
            $verified['expires_at'] = $expiresAt;
        }

        return array('ok' => true, 'reason' => 'ok', 'payload' => $verified);
    }

    /**
     * @return array{quote_id:int,version_id:int,quote_reference:string,issued_at?:int,expires_at?:int}|null
     */
    public function verify(string $token): ?array
    {
        $result = $this->verifyDetailed($token);
        return ! empty($result['ok']) && is_array($result['payload'] ?? null) ? $result['payload'] : null;
    }

    public function tokenId(string $token): string
    {
        return substr(hash('sha256', trim($token)), 0, 16);
    }

    private function signature(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret());
    }

    private function ttlSeconds(): int
    {
        $ttl = self::DEFAULT_TTL_SECONDS;
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('sbdp_quotes/public_proposal_token_ttl_seconds', $ttl);
            if (is_numeric($filtered)) {
                $ttl = (int) $filtered;
            }
        }

        return max(3600, $ttl);
    }

    private function now(): int
    {
        return time();
    }

    private function secret(): string
    {
        if (function_exists('wp_salt')) {
            return (string) wp_salt('auth');
        }

        if (defined('AUTH_SALT')) {
            return (string) AUTH_SALT;
        }

        return 'bsp-quotes-public-proposal';
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
