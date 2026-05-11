<?php

declare(strict_types=1);

namespace BSP\Bookings\Service;

use InvalidArgumentException;

/**
 * Seals repository writes so booking persistence mutations only succeed from
 * explicit manager-controlled or maintenance-controlled execution scopes.
 */
final class BookingRepositoryWriteGuard
{
    /**
     * @var array<int, string>
     */
    private static array $scopes = [];

    /**
     * @template T
     *
     * @param callable():T $callback
     *
     * @return T
     */
    public static function allowManagerWrite(callable $callback)
    {
        self::$scopes[] = 'manager_write';

        try {
            return $callback();
        } finally {
            array_pop(self::$scopes);
        }
    }

    /**
     * @template T
     *
     * @param callable():T $callback
     *
     * @return T
     */
    public static function allowMaintenanceReset(callable $callback)
    {
        self::$scopes[] = 'maintenance_reset';

        try {
            return $callback();
        } finally {
            array_pop(self::$scopes);
        }
    }

    public static function assertWriteAllowed(string $method): void
    {
        if (in_array('manager_write', self::$scopes, true)) {
            return;
        }

        throw new InvalidArgumentException(
            sprintf(
                'Direct booking repository write bypass is blocked for %s. Use BookingManager as the execution gate.',
                $method
            )
        );
    }

    public static function assertResetAllowed(string $method): void
    {
        if (
            in_array('manager_write', self::$scopes, true)
            || in_array('maintenance_reset', self::$scopes, true)
        ) {
            return;
        }

        throw new InvalidArgumentException(
            sprintf(
                'Direct booking repository reset is blocked for %s. Use an explicit maintenance scope.',
                $method
            )
        );
    }
}
