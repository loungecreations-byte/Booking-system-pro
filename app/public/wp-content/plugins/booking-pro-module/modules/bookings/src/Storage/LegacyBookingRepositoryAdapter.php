<?php

declare(strict_types=1);

namespace SBDP\Bookings\Storage;

use BSP\Bookings\Service\BookingRepositoryWriteGuard;
use InvalidArgumentException;
use SBDP\Bookings\Domain\Booking;
use SBDP\Bookings\Legacy\LegacyBookingMapper;
use BSP\Bookings\Service\BookingRepositoryInterface;

/**
 * Adapter that allows legacy array-based consumers to operate on the new booking storage layer.
 */
final class LegacyBookingRepositoryAdapter implements BookingRepositoryInterface
{
    public function __construct(
        private readonly BookingStorageInterface $storage
    ) {
    }

    /**
     * @param array<string, mixed> $booking
     *
     * @return array<string, mixed>
     */
    public function create(array $booking): array
    {
        BookingRepositoryWriteGuard::assertWriteAllowed(__METHOD__);
        $persisted = $this->storage->create(LegacyBookingMapper::toBooking($booking));

        return LegacyBookingMapper::fromBooking($persisted);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $booking = $this->storage->find($id);
        if ($booking === null) {
            return null;
        }

        return LegacyBookingMapper::fromBooking($booking);
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    public function update(int $id, array $changes): array
    {
        BookingRepositoryWriteGuard::assertWriteAllowed(__METHOD__);
        $existing = $this->find($id);
        if ($existing === null) {
            throw new InvalidArgumentException('Unknown booking identifier.');
        }

        $payload = array_replace_recursive($existing, $changes);
        $payload['id'] = $id;

        $updated = $this->storage->update(LegacyBookingMapper::toBooking($payload));

        return LegacyBookingMapper::fromBooking($updated);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return array_map(
            static fn (Booking $booking): array => LegacyBookingMapper::fromBooking($booking),
            $this->storage->all()
        );
    }

    public function reset(): void
    {
        BookingRepositoryWriteGuard::assertResetAllowed(__METHOD__);
        $this->storage->reset();
    }
}
