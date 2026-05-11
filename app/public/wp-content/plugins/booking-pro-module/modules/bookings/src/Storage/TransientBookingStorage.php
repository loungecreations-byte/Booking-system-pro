<?php

declare(strict_types=1);

namespace SBDP\Bookings\Storage;

use InvalidArgumentException;
use SBDP\Bookings\Domain\Booking;

/**
 * In-memory booking store with optional WordPress transient persistence.
 *
 * Acts as a bridge between the legacy array-based repository and the new
 * domain objects while remaining usable during CLI/test execution where the
 * transient API is unavailable.
 */
final class TransientBookingStorage implements BookingStorageInterface
{
    private const TRANSIENT_KEY = 'sbdp_booking_records_v4';
    private const TRANSIENT_TTL = 1800; // 30 minutes

    /**
     * @var array<int, Booking>
     */
    private array $records = [];

    private int $increment = 1;

    private bool $useTransient = false;

    public function __construct()
    {
        $this->useTransient = function_exists('get_transient') && function_exists('set_transient');
        $this->bootstrap();
    }

    public function create(Booking $booking): Booking
    {
        $id = $booking->getId();
        if ($id === null) {
            $id      = $this->nextId();
            $booking = $booking->withId($id);
        } else {
            $this->ensureIdIsAvailable($id);
            $this->increment = max($this->increment, $id + 1);
        }

        $this->records[$id] = $booking;
        $this->persist();

        return $booking;
    }

    public function update(Booking $booking): Booking
    {
        $id = $booking->getId();
        if ($id === null) {
            throw new InvalidArgumentException('Cannot update a booking without an identifier.');
        }

        if (! isset($this->records[$id])) {
            throw new InvalidArgumentException(sprintf('Unknown booking identifier %d.', $id));
        }

        $this->records[$id] = $booking;
        $this->persist();

        return $booking;
    }

    public function find(int $id): ?Booking
    {
        return $this->records[$id] ?? null;
    }

    /**
     * @return list<Booking>
     */
    public function all(): array
    {
        return array_values($this->records);
    }

    public function delete(int $id): void
    {
        unset($this->records[$id]);
        $this->persist();
    }

    public function reset(): void
    {
        $this->records  = [];
        $this->increment = 1;
        $this->persist();
    }

    private function nextId(): int
    {
        return $this->increment++;
    }

    private function ensureIdIsAvailable(int $id): void
    {
        if (isset($this->records[$id])) {
            throw new InvalidArgumentException(sprintf('Booking identifier %d already exists.', $id));
        }
    }

    private function bootstrap(): void
    {
        if (! $this->useTransient) {
            return;
        }

        $payload = get_transient(self::TRANSIENT_KEY);
        if (! is_array($payload)) {
            return;
        }

        $records = $payload['records'] ?? [];
        $increment = isset($payload['increment']) ? (int) $payload['increment'] : 1;

        if (is_array($records)) {
            foreach ($records as $id => $record) {
                if (! is_array($record)) {
                    continue;
                }

                $booking = Booking::fromArray($record);

                if ($booking->getId() === null) {
                    $booking = $booking->withId((int) $id);
                }

                $this->records[(int) $id] = $booking;
            }
        }

        if ($increment > 1) {
            $this->increment = $increment;
        }
    }

    private function persist(): void
    {
        if (! $this->useTransient) {
            return;
        }

        if (! function_exists('set_transient')) {
            return;
        }

        $payload = [
            'records'   => array_map(
                static fn (Booking $booking): array => $booking->toArray(),
                $this->records
            ),
            'increment' => $this->increment,
        ];

        set_transient(self::TRANSIENT_KEY, $payload, self::TRANSIENT_TTL);
    }
}

