<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use InvalidArgumentException;

final class QuoteLegalAcceptancePayloadService
{
    public const TERMS_VERSION = 'ddb-terms-2026-07-08';

    private QuoteAcceptanceSnapshotService $snapshots;

    public function __construct(?QuoteAcceptanceSnapshotService $snapshots = null)
    {
        $this->snapshots = $snapshots ?? new QuoteAcceptanceSnapshotService();
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $quote
     * @param array<string, mixed> $version
     * @param array<string, mixed> $request
     * @param array<int, array<string, mixed>> $lines
     * @param array<string, mixed> $client
     * @return array<string, mixed>
     */
    public function build(array $input, array $quote, array $version, array $request, array $lines, array $client, string $tokenId): array
    {
        $name = $this->clean((string) ($input['acceptance_name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Vul uw naam in om akkoord te geven.');
        }

        $email = $this->email((string) ($input['acceptance_email'] ?? ''));
        if ($email === '') {
            throw new InvalidArgumentException('Vul een geldig e-mailadres in om akkoord te geven.');
        }

        if (! $this->truthy($input['acceptance_terms_checked'] ?? false)) {
            throw new InvalidArgumentException('Bevestig dat u akkoord gaat met het programma, de prijsopbouw en de voorwaarden.');
        }

        $termsVersion = $this->clean((string) (($input['terms_version'] ?? '') ?: self::TERMS_VERSION));
        $termsUrl = $this->cleanUrl((string) (($input['terms_url'] ?? '') ?: $this->defaultTermsUrl()));
        $acceptedAt = $this->now();
        $snapshot = $this->snapshots->build($quote, $version, $request, $lines, $termsVersion);

        return array(
            'action' => 'accepted',
            'source' => 'public_proposal',
            'quote_id' => (int) ($quote['id'] ?? 0),
            'approved_version_id' => (int) ($version['id'] ?? 0),
            'current_version_id_at_acceptance' => (int) ($quote['current_version_id'] ?? 0),
            'acceptance_name' => $name,
            'acceptance_email' => $email,
            'acceptance_company' => $this->clean((string) ($input['acceptance_company'] ?? '')),
            'acceptance_role' => $this->clean((string) ($input['acceptance_role'] ?? '')),
            'terms_checked' => true,
            'terms_version' => $termsVersion,
            'terms_url' => $termsUrl,
            'terms_snapshot_hash' => hash('sha256', $termsVersion . '|' . $termsUrl),
            'proposal_snapshot_hash' => $snapshot['proposal_snapshot_hash'],
            'quote_version_hash' => $snapshot['quote_version_hash'],
            'snapshot_json' => $snapshot['snapshot'],
            'accepted_at' => $acceptedAt,
            'ip_address' => $this->clean((string) ($client['ip'] ?? '')),
            'user_agent' => substr($this->clean((string) ($client['user_agent'] ?? '')), 0, 500),
            'public_token_id' => $tokenId,
        );
    }

    private function clean(string $value): string
    {
        $value = trim($value);
        return function_exists('sanitize_text_field') ? (string) sanitize_text_field($value) : trim(strip_tags($value));
    }

    private function email(string $value): string
    {
        $value = trim($value);
        $value = function_exists('sanitize_email') ? (string) sanitize_email($value) : $value;
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
    }

    private function cleanUrl(string $value): string
    {
        $value = trim($value);
        if (function_exists('esc_url_raw')) {
            return (string) esc_url_raw($value);
        }

        return filter_var($value, FILTER_VALIDATE_URL) ? $value : '';
    }

    private function truthy($value): bool
    {
        return in_array($value, array(true, 1, '1', 'yes', 'on'), true);
    }

    private function defaultTermsUrl(): string
    {
        return function_exists('home_url') ? (string) home_url('/voorwaarden/') : '/voorwaarden/';
    }

    private function now(): string
    {
        return function_exists('current_time') ? (string) current_time('mysql', true) : gmdate('Y-m-d H:i:s');
    }
}
