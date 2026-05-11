<?php

declare(strict_types=1);

namespace BSP\Bookings\Service;

use InvalidArgumentException;
use SBDP\Bookings\Storage\LegacyBookingRepositoryAdapter;
use SBDP\Bookings\Storage\TransientBookingStorage;

final class BookingService
{
    private static ?BookingRepositoryInterface $repository = null;

    private static ?BookingManager $manager = null;

    public static function reset(): void
    {
        $repository = self::repository();
        BookingRepositoryWriteGuard::allowMaintenanceReset(
            static function () use ($repository): void {
                $repository->reset();
            }
        );
        self::$repository = null;
        self::$manager    = null;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public static function create(array $payload): array
    {
        return self::createBooking($payload);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public static function request(array $payload): array
    {
        return self::requestBooking($payload);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public static function pay(array $payload): array
    {
        $bookingId = (int) ($payload['booking_id'] ?? 0);
        if ($bookingId <= 0) {
            throw new InvalidArgumentException('Unknown booking identifier.');
        }

        $method = trim((string) ($payload['method'] ?? ''));
        if ($method === '') {
            throw new InvalidArgumentException('Payment method is required.');
        }

        $reference = isset($payload['reference']) ? (string) $payload['reference'] : null;

        return self::manager()->payBookingWithReference($bookingId, $method, $reference);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public static function createBooking(array $payload): array
    {
        return self::manager()->createBooking($payload, isset($payload['booking_truth']) && is_array($payload['booking_truth']) ? $payload['booking_truth'] : null);
    }

    /**
     * Public booking creation only accepts booking intent. Canonical booking truth,
     * pricing, status, and currency are derived server-side.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function createPublicBooking(array $payload): array
    {
        return self::manager()->createPublicBooking($payload);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public static function requestBooking(array $payload): array
    {
        return self::manager()->requestBooking($payload, isset($payload['booking_truth']) && is_array($payload['booking_truth']) ? $payload['booking_truth'] : null);
    }

    /**
     * Public booking request only accepts booking intent. Canonical booking truth,
     * pricing, status, and currency are derived server-side.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function requestPublicBooking(array $payload): array
    {
        return self::manager()->requestPublicBooking($payload);
    }

    public static function payBooking(int $bookingId, string $method): array
    {
        return self::manager()->payBooking($bookingId, $method);
    }

    /**
     * Confirm availability and dispatch a payment link to the customer.
     * Wraps BookingManager::dispatchInvoice which creates/syncs the WC order,
     * generates a Mollie or WC checkout-payment URL, and emails the customer.
     *
     * @return array<string, mixed> Updated booking record
     */
    public static function dispatchInvoice(int $bookingId, bool $force = false): array
    {
        return self::manager()->dispatchInvoice($bookingId, $force);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return self::repository()->all();
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getBookings(array $filters = []): array
    {
        return self::manager()->getBookings($filters);
    }

    private static function repository(): BookingRepositoryInterface
    {
        if (self::$repository === null) {
            self::$repository = WooCommerceBookingRepository::isSupported()
                ? new WooCommerceBookingRepository()
                : new LegacyBookingRepositoryAdapter(new TransientBookingStorage());
        }

        return self::$repository;
    }

    private static function manager(): BookingManager
    {
        if (self::$manager === null) {
            self::$manager = BookingManager::createDefault(self::repository());
        }

        return self::$manager;
    }
}
