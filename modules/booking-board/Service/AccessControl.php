<?php

declare(strict_types=1);

namespace BSP\BookingBoard\Service;

use InvalidArgumentException;

final class AccessControl
{
    public function canManage(): bool
    {
        return function_exists('current_user_can') ? current_user_can('manage_woocommerce') : true;
    }

    public function enforceManage(): void
    {
        if (! $this->canManage()) {
            throw new InvalidArgumentException(__('You do not have permission to modify bookings.', 'sbdp'));
        }
    }

    /**
     * @param array<int, array<string, mixed>> $bookings
     *
     * @return array<int, array<string, mixed>>
     */
    public function filter(array $bookings): array
    {
        if ($this->canManage()) {
            return $bookings;
        }

        if (! function_exists('get_current_user_id') || ! function_exists('get_user_meta')) {
            return $bookings;
        }

        $userId = get_current_user_id();
        if ($userId <= 0) {
            return [];
        }

        $vendorId = (int) get_user_meta($userId, '_sbdp_vendor_id', true);
        if ($vendorId <= 0) {
            return $bookings;
        }

        return array_values(
            array_filter(
                $bookings,
                static function (array $booking) use ($vendorId): bool {
                    $recordVendor = $booking['vendor']['id'] ?? null;

                    return $recordVendor !== null && (int) $recordVendor === $vendorId;
                }
            )
        );
    }
}
