<?php

declare(strict_types=1);

namespace BSP\Bookings\Service;

/**
 * Contract for booking persistence layers consumed by BookingManager.
 */
interface BookingRepositoryInterface
{
    /**
     * Persist a newly created booking record.
     *
     * @param array<string, mixed> $booking
     *
     * @return array<string, mixed>
     */
    public function create(array $booking): array;

    /**
     * Locate a booking by its identifier.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array;

    /**
     * Apply partial updates to an existing booking.
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    public function update(int $id, array $changes): array;

    /**
     * Retrieve all known booking records.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array;

    /**
     * Reset the underlying storage mechanism.
     */
    public function reset(): void;
}

