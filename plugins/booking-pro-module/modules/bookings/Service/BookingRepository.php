<?php

declare(strict_types=1);

namespace BSP\Bookings\Service;

use InvalidArgumentException;

/**
 * Repository that persists booking records using WordPress transients when available,
 * falling back to an in-memory store during CLI/test execution.
 */
final class BookingRepository implements BookingRepositoryInterface
{
    private const TRANSIENT_KEY = 'sbdp_booking_records_v3';
    private const TRANSIENT_TTL = 1800; // 30 minutes

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $records = [];

    private int $increment = 1;

    private bool $useTransient = false;

    public function __construct()
    {
        $this->useTransient = function_exists('get_transient') && function_exists('set_transient');
        $this->bootstrap();
    }

    public function reset(): void
    {
        BookingRepositoryWriteGuard::assertResetAllowed(__METHOD__);
        $this->records  = [];
        $this->increment = 1;
        $this->persist();
    }

    /**
     * Store a new booking and assign the next identifier.
     *
     * @param array<string, mixed> $booking
     *
     * @return array<string, mixed>
     */
    public function create(array $booking): array
    {
        BookingRepositoryWriteGuard::assertWriteAllowed(__METHOD__);
        $booking['id'] = $this->nextId();
        $this->records[$booking['id']] = $booking;
        $this->persist();

        return $booking;
    }

    public function find(int $id): ?array
    {
        return $this->records[$id] ?? null;
    }

    /**
     * Persist changes to an existing booking record.
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    public function update(int $id, array $changes): array
    {
        BookingRepositoryWriteGuard::assertWriteAllowed(__METHOD__);
        if (! isset($this->records[$id])) {
            throw new InvalidArgumentException('Unknown booking identifier.');
        }

        $record = array_replace_recursive($this->records[$id], $changes);
        $this->records[$id] = $record;
        $this->persist();

        return $record;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return array_values($this->records);
    }

    private function nextId(): int
    {
        return $this->increment++;
    }

    private function bootstrap(): void
    {
        if (! $this->useTransient) {
            return;
        }

        $payload = get_transient(self::TRANSIENT_KEY);
        if (is_array($payload)) {
            $records   = $payload['records'] ?? [];
            $increment = (int) ($payload['increment'] ?? 1);

            if (is_array($records)) {
                $this->records = $records;
            }

            if ($increment > 1) {
                $this->increment = $increment;
            }
        }
    }

    private function persist(): void
    {
        if (! $this->useTransient) {
            return;
        }

        if (function_exists('set_transient')) {
            set_transient(
                self::TRANSIENT_KEY,
                [
                    'records'   => $this->records,
                    'increment' => $this->increment,
                ],
                self::TRANSIENT_TTL
            );
        }
    }
}
