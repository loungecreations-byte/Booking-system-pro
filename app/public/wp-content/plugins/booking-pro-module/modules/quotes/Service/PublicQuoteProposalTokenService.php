<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

final class PublicQuoteProposalTokenService
{
    public function create(int $quoteId, int $versionId, string $quoteReference): string
    {
        $payload = $this->base64UrlEncode((string) json_encode(array(
            'q' => $quoteId,
            'v' => $versionId,
            'r' => $quoteReference,
        )));

        return $payload . '.' . $this->signature($payload);
    }

    /**
     * @return array{quote_id:int,version_id:int,quote_reference:string}|null
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
        $quoteReference = trim((string) ($decoded['r'] ?? ''));
        if ($quoteId <= 0 || $versionId <= 0 || $quoteReference === '') {
            return null;
        }

        return array(
            'quote_id' => $quoteId,
            'version_id' => $versionId,
            'quote_reference' => $quoteReference,
        );
    }

    public function tokenId(string $token): string
    {
        return substr(hash('sha256', trim($token)), 0, 16);
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
