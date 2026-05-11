<?php

declare(strict_types=1);

namespace SBDP\Bookings\Storage;

use SBDP\Bookings\Domain\Booking;

/**
 * Contract for booking persistence providers.
 */
interface BookingStorageInterface
{
    public function create(Booking $booking): Booking;

    public function update(Booking $booking): Booking;

    public function find(int $id): ?Booking;

    /**
     * @return list<Booking>
     */
    public function all(): array;

    public function delete(int $id): void;

    public function reset(): void;
}

