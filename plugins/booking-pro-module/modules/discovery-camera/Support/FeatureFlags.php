<?php

declare(strict_types=1);

namespace BSP\DiscoveryCamera\Support;

final class FeatureFlags
{
    public const OPTION_ENABLED = 'ddb_discovery_camera_enabled';
    public const OPTION_TOUR_ALLOWLIST = 'ddb_discovery_camera_tour_allowlist';
    public const OPTION_PROVIDER_MODE = 'ddb_discovery_camera_provider_mode';

    public static function enabled(): bool
    {
        return (bool) apply_filters(
            'ddb/discovery_camera/enabled',
            (string) get_option(self::OPTION_ENABLED, '0') === '1'
        );
    }

    public static function enabledForTour(int $tourId): bool
    {
        if (! self::enabled() || $tourId <= 0) {
            return false;
        }

        $allowlist = get_option(self::OPTION_TOUR_ALLOWLIST, array());
        if (is_string($allowlist)) {
            $allowlist = preg_split('/[\s,]+/', $allowlist, -1, PREG_SPLIT_NO_EMPTY);
        }

        $ids = array_values(array_unique(array_filter(array_map('absint', (array) $allowlist))));

        return $ids === array() || in_array($tourId, $ids, true);
    }

    public static function providerMode(): string
    {
        $mode = sanitize_key((string) get_option(self::OPTION_PROVIDER_MODE, 'fake'));

        return in_array($mode, array('fake', 'shadow', 'live'), true) ? $mode : 'fake';
    }
}
