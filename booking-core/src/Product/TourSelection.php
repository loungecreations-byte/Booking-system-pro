<?php

declare(strict_types=1);

namespace BSPModule\Core\Product;

use InvalidArgumentException;

/**
 * Canonical selection validator for supplier-backed tour variants and upgrades.
 *
 * This class is intentionally pure: it never calls a supplier API, never trusts
 * client-side prices, and never creates WooCommerce orders or options.
 */
final class TourSelection
{
    /**
     * @param array<string,mixed> $catalog Server-side, supplier-verified configuration.
     * @param array<string,mixed> $input   Client selection: variant and upgrade codes only.
     * @return array<string,mixed> Validated, immutable-order-metadata-ready snapshot.
     */
    public static function normalize(array $catalog, array $input, int $participants): array
    {
        if ($participants < 1) {
            throw new InvalidArgumentException('At least one participant is required.');
        }
        if (empty($catalog['enabled']) || empty($catalog['variants']) || !is_array($catalog['variants'])) {
            throw new InvalidArgumentException('Tour selection is not enabled.');
        }

        $variantCode = self::safeCode($input['variant'] ?? '');
        $variant = $catalog['variants'][$variantCode] ?? null;
        if (!$variantCode || !is_array($variant) || empty($variant['enabled'])) {
            throw new InvalidArgumentException('Unknown or disabled tour variant.');
        }

        $minutes = (int) ($variant['duration_minutes'] ?? 0);
        if ($minutes <= 0 || $minutes > 1440) {
            throw new InvalidArgumentException('Invalid tour duration.');
        }

        $cityQuest = !empty($variant['city_quest']);
        if ($cityQuest && $minutes !== 120) {
            throw new InvalidArgumentException('City Quest is only configured for the two-hour variant.');
        }

        $selected = $input['upgrades'] ?? [];
        if (!is_array($selected) || count($selected) > 12) {
            throw new InvalidArgumentException('Invalid upgrade selection.');
        }

        $available = isset($catalog['upgrades']) && is_array($catalog['upgrades'])
            ? $catalog['upgrades'] : [];
        $seen = [];
        $upgrades = [];

        foreach ($selected as $rawCode) {
            $code = self::safeCode($rawCode);
            if (!$code || isset($seen[$code])) {
                throw new InvalidArgumentException('Invalid or duplicate upgrade code.');
            }
            $seen[$code] = true;
            $upgrade = $available[$code] ?? null;
            if (!is_array($upgrade) || empty($upgrade['enabled'])) {
                throw new InvalidArgumentException('Unknown or disabled upgrade.');
            }
            $allowed = $upgrade['allowed_variants'] ?? [];
            if (!is_array($allowed) || !in_array($variantCode, $allowed, true)) {
                throw new InvalidArgumentException('Upgrade is not valid for this tour variant.');
            }
            if (trim((string) ($upgrade['terms_version'] ?? '')) === '') {
                throw new InvalidArgumentException('Upgrade terms have not been configured.');
            }

            $upgrades[$code] = [
                'code'               => $code,
                'label'              => trim((string) ($upgrade['label'] ?? $code)),
                'terms_version'      => trim((string) $upgrade['terms_version']),
                'external_option_id' => trim((string) ($upgrade['external_option_id'] ?? '')),
            ];
        }

        ksort($upgrades);

        return [
            'schema'               => 1,
            'catalog_version'      => trim((string) ($catalog['version'] ?? '')),
            'variant_code'         => $variantCode,
            'variant_label'        => trim((string) ($variant['label'] ?? $variantCode)),
            'external_variant_id'  => trim((string) ($variant['external_variant_id'] ?? '')),
            'duration_minutes'     => $minutes,
            'city_quest'           => $cityQuest,
            'participants'         => $participants,
            'upgrades'             => array_values($upgrades),
        ];
    }

    /**
     * A booking is never authorized based on a public availability response alone.
     * The API and payment/booking transaction must be verified independently.
     *
     * @param array<string,mixed> $catalog
     * @param array<string,mixed> $snapshot Result from normalize().
     */
    public static function canAutoBook(array $catalog, array $snapshot): bool
    {
        if (empty($catalog['enabled'])
            || empty($catalog['supplier_catalog_verified'])
            || empty($catalog['booking_flow_verified'])
            || empty($catalog['transaction_safety_verified'])
            || empty($catalog['auto_booking_enabled'])
            || ($snapshot['external_variant_id'] ?? '') === '') {
            return false;
        }

        foreach (($snapshot['upgrades'] ?? []) as $upgrade) {
            if (empty($upgrade['external_option_id'])) {
                return false;
            }
        }
        return true;
    }

    /** @param mixed $value */
    private static function safeCode($value): string
    {
        if (!is_string($value) || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $value)) {
            return '';
        }
        return $value;
    }
}
