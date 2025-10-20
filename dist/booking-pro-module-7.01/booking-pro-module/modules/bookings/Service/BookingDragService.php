<?php
declare(strict_types=1);

namespace BSP\Bookings\Service;

use DateTimeImmutable;
use Exception;
use InvalidArgumentException;

use function array_replace_recursive;
use function bin2hex;
use function function_exists;
use function get_transient;
use function gmdate;
use function is_array;
use function preg_match;
use function random_bytes;
use function set_transient;
use function sprintf;
use function trim;
use function wp_generate_uuid4;

/**
 * Handles drag-and-drop booking sessions with transient-backed storage.
 */
final class BookingDragService
{
    private const TRANSIENT_KEY = 'sbdp_drag_sessions_v1';
    private const TRANSIENT_TTL = 900;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $sessions = [];

    private bool $useTransient = false;

    public function __construct(
        private BookingRepository $repository = new BookingRepository()
    ) {
        $this->useTransient = function_exists('get_transient') && function_exists('set_transient');
        $this->bootstrap();
    }

    /**
     * @return array<string, mixed>
     */
    public function start(int $bookingId): array
    {
        $booking = $this->repository->find($bookingId);
        if ($booking === null) {
            throw new InvalidArgumentException('Unknown booking identifier.');
        }

        $sessionId = $this->generateId();
        $session   = [
            'id'          => $sessionId,
            'booking_id'  => $bookingId,
            'created_at'  => gmdate('c'),
            'original'    => $booking,
            'proposed'    => null,
        ];

        $this->sessions[$sessionId] = $session;
        $this->persist();

        return $session;
    }

    /**
     * @param array<string, string> $move
     *
     * @return array<string, mixed>
     */
    public function apply(string $sessionId, array $move): array
    {
        $session = $this->requireSession($sessionId);

        $date     = trim((string) ($move['date'] ?? ''));
        $time     = trim((string) ($move['time'] ?? ''));
        $resource = trim((string) ($move['resource'] ?? ''));

        $this->assertValidDate($date);
        $this->assertValidTime($time);
        $this->assertValidResource($resource);

        $session['proposed'] = [
            'date'        => $date,
            'time'        => $time,
            'resource'    => $resource,
            'updated_at'  => gmdate('c'),
        ];

        $this->sessions[$sessionId] = $session;
        $this->persist();

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    public function commit(string $sessionId): array
    {
        $session = $this->requireSession($sessionId);
        $proposed = $session['proposed'];
        if (! is_array($proposed)) {
            throw new InvalidArgumentException('No pending move for session.');
        }

        $booking = $this->repository->find($session['booking_id']);
        if ($booking === null) {
            throw new InvalidArgumentException('Unknown booking identifier.');
        }

        $updated = array_replace_recursive(
            $booking,
            [
                'date'     => $proposed['date'],
                'time'     => $proposed['time'],
                'resource' => $proposed['resource'],
                'planner'  => $this->buildPlannerMeta($booking, $proposed),
                'updated_at' => gmdate('c'),
            ],
        );

        $stored = $this->repository->update($session['booking_id'], $updated);

        $this->removeSession($sessionId);

        return $stored;
    }

    public function rollback(string $sessionId): void
    {
        $this->requireSession($sessionId);
        $this->removeSession($sessionId);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireSession(string $sessionId): array
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '' || ! isset($this->sessions[$sessionId])) {
            throw new InvalidArgumentException('Unknown drag session identifier.');
        }

        return $this->sessions[$sessionId];
    }

    /**
     * @param array<string, mixed> $booking
     * @param array<string, string> $proposed
     *
     * @return array<string, mixed>
     */
    private function buildPlannerMeta(array $booking, array $proposed): array
    {
        $label = isset($booking['customer']['name']) ? (string) $booking['customer']['name'] : '';

        return [
            'resource' => $proposed['resource'],
            'slot'     => $proposed['time'],
            'timeline' => [
                [
                    'slot'     => $proposed['time'],
                    'label'    => $label,
                    'resource' => $proposed['resource'],
                ],
            ],
        ];
    }

    private function assertValidDate(string $date): void
    {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if (! $dt instanceof DateTimeImmutable || $dt->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Invalid date format (expected Y-m-d).');
        }
    }

    private function assertValidTime(string $time): void
    {
        if (! preg_match('/^\d{2}:\d{2}$/', $time)) {
            throw new InvalidArgumentException('Invalid time format (expected HH:MM).');
        }
    }

    private function assertValidResource(string $resource): void
    {
        if ($resource === '') {
            throw new InvalidArgumentException('Resource is required.');
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

        $sessions = $payload['sessions'] ?? [];
        if (is_array($sessions)) {
            $this->sessions = $sessions;
        }
    }

    private function persist(): void
    {
        if (! $this->useTransient) {
            return;
        }

        set_transient(
            self::TRANSIENT_KEY,
            [
                'sessions' => $this->sessions,
            ],
            self::TRANSIENT_TTL
        );
    }

    private function removeSession(string $sessionId): void
    {
        unset($this->sessions[$sessionId]);
        $this->persist();
    }

    private function generateId(): string
    {
        if (function_exists('wp_generate_uuid4')) {
            return (string) wp_generate_uuid4();
        }

        try {
            return bin2hex(random_bytes(8));
        } catch (Exception) {
            return (string) rand(100000, 999999); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_rand
        }
    }
}
