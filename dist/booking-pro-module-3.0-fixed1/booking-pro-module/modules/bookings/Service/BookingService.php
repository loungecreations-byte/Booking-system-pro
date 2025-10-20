<?php

declare(strict_types=1);

namespace BSP\Bookings\Service;

use InvalidArgumentException;

final class BookingService
{
    private static ?BookingRepository $repository = null;

    private static ?BookingManager $manager = null;

    public static function reset(): void
    {
        $repository = self::repository();
        $repository->reset();
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
        return self::manager()->createBooking($payload);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public static function requestBooking(array $payload): array
    {
        return self::manager()->requestBooking($payload);
    }

    public static function payBooking(int $bookingId, string $method): array
    {
        return self::manager()->payBooking($bookingId, $method);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return self::repository()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getBookings(): array
    {
        return self::manager()->getBookings();
    }

    private static function repository(): BookingRepository
    {
        if (self::$repository === null) {
            self::$repository = new BookingRepository();
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
