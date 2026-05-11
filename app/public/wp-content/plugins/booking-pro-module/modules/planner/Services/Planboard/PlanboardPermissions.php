<?php

declare(strict_types=1);

namespace BSP\Planner\Services\Planboard;

final class PlanboardPermissions
{
    public const CAP_BOARD_VIEW   = 'board.view';
    public const CAP_BOOKING_MOVE = 'booking.move';
    public const CAP_BOOKING_CREATE = 'booking.create';
    public const CAP_BOOKING_CHECKIN = 'booking.checkin';
    public const CAP_PAYMENT_ADD = 'payment.add';
    public const CAP_RULES_MANAGE = 'rules.manage';

    public static function canView(): bool
    {
        return self::check(self::CAP_BOARD_VIEW);
    }

    public static function canMove(): bool
    {
        return self::check(self::CAP_BOOKING_MOVE);
    }

    public static function canCreate(): bool
    {
        return self::check(self::CAP_BOOKING_CREATE);
    }

    public static function canCheckin(): bool
    {
        return self::check(self::CAP_BOOKING_CHECKIN);
    }

    public static function canAddPayment(): bool
    {
        return self::check(self::CAP_PAYMENT_ADD);
    }

    public static function canManageRules(): bool
    {
        return self::check(self::CAP_RULES_MANAGE);
    }

    private static function check(string $capability): bool
    {
        if (! function_exists('current_user_can')) {
            return false;
        }

        $fallback = array('manage_woocommerce', 'manage_options');
        $caps = array_merge(array($capability), $fallback);

        if (function_exists('apply_filters')) {
            $caps = (array) apply_filters('bsp/planboard/capability_map', $caps, $capability);
        }

        foreach ($caps as $cap) {
            if (is_string($cap) && $cap !== '' && current_user_can($cap)) {
                return true;
            }
        }

        return false;
    }
}
