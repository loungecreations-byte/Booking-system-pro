<?php

declare(strict_types=1);

namespace BSP\BookingBoard\Service;

use BSP\Bookings\Service\BookingManager;
use BSP\Core\CoreServiceProvider;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

final class DragDropSessionService
{
    private const SESSION_TTL = 600; // 10 minutes.

    private const TRANSIENT_PREFIX = 'sbdp_drag_session_';

    /**
     * @var array<string, array<string, mixed>>
     */
    private static array $memoryStore = array();

    private BookingManager $manager;

    private BoardService $board;

    public function __construct(?BookingManager $manager = null, ?BoardService $board = null)
    {
        $this->manager = $manager ?? BookingManager::createDefault();
        $this->board   = $board ?? new BoardService($this->manager);
    }

    /**
     * Start a drag & drop session for the given booking.
     *
     * @return array<string, mixed>
     */
    public function start(int $bookingId, int $userId): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Authenticated user required to start a drag session.');
        }

        $booking = $this->locateBooking($bookingId);
        $sessionId = $this->generateSessionId();

        $session = array(
            'id'          => $sessionId,
            'booking_id'  => $bookingId,
            'user_id'     => $userId,
            'created_at'  => gmdate('c'),
            'expires_at'  => gmdate('c', time() + self::SESSION_TTL),
            'original'    => $booking,
            'pending'     => null,
            'conflicts'   => array(),
            'note'        => '',
        );

        $this->storeSession($session);

        CoreServiceProvider::logger()->log(
            sprintf('Drag session %s started for booking #%d', $sessionId, $bookingId)
        );

        return array(
            'session_id' => $sessionId,
            'id'         => $sessionId,
            'booking_id' => $bookingId,
            'booking'    => $booking,
            'expires_at' => $session['expires_at'],
            'conflicts'  => array(),
        );
    }

    /**
     * Apply a tentative slot to the session and perform conflict detection.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function apply(string $sessionId, int $userId, array $payload): array
    {
        $session = $this->getSession($sessionId);
        $this->assertSessionOwner($session, $userId);

        $bookingId = $session['booking_id'];
        $dateStart = $this->sanitizeDate((string) ($payload['date_start'] ?? ''));
        $timeStart = $this->sanitizeTime((string) ($payload['time_start'] ?? ''));
        $dateEnd   = array_key_exists('date_end', $payload) && $payload['date_end'] !== null
            ? $this->sanitizeDate((string) $payload['date_end'])
            : $dateStart;
        $timeEnd   = array_key_exists('time_end', $payload) && $payload['time_end'] !== null
            ? $this->sanitizeTime((string) $payload['time_end'])
            : $timeStart;
        $note      = isset($payload['note']) ? trim((string) $payload['note']) : '';

        $conflicts = $this->detectConflicts($bookingId, $dateStart, $timeStart, $dateEnd, $timeEnd);

        $session['pending'] = array(
            'date_start' => $dateStart,
            'time_start' => $timeStart,
            'date_end'   => $dateEnd,
            'time_end'   => $timeEnd,
        );
        $session['conflicts'] = $conflicts;
        $session['note'] = $note;
        $session['preview'] = $this->buildPreview($session['original'], $session['pending']);

        $this->storeSession($session);

        return array(
            'session_id' => $session['id'],
            'id'         => $session['id'],
            'booking_id' => $session['booking_id'],
            'booking'    => $session['preview'],
            'pending'    => $session['pending'],
            'conflicts'  => $conflicts,
            'note'       => $session['note'],
        );
    }

    /**
     * Commit the pending changes for this session.
     *
     * @return array<string, mixed>
     */
    public function commit(string $sessionId, int $userId): array
    {
        $session = $this->getSession($sessionId);
        $this->assertSessionOwner($session, $userId);

        if ($session['pending'] === null) {
            throw new InvalidArgumentException('There is no pending change to commit for this session.');
        }

        if (! empty($session['conflicts'])) {
            throw new InvalidArgumentException('Conflicts must be resolved before committing changes.');
        }

        $pending = $session['pending'];

        $payload = array(
            'booking_id' => $session['booking_id'],
            'date_start' => $pending['date_start'],
            'time_start' => $pending['time_start'],
            'date_end'   => $pending['date_end'],
            'time_end'   => $pending['time_end'],
        );

        $updated = $this->board->reschedule($payload);

        if ($session['note'] !== '') {
            try {
                $this->board->updateDetails(
                    array(
                        'booking_id' => $session['booking_id'],
                        'notes'      => $session['note'],
                    )
                );
            } catch (\Throwable $noteError) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
                // Ignore note update failures; reschedule already applied.
            }
        }

        $this->deleteSession($sessionId);

        CoreServiceProvider::logger()->log(
            sprintf(
                'Drag session %s committed for booking #%d',
                $sessionId,
                $session['booking_id']
            )
        );

        return array(
            'session_id' => $sessionId,
            'id'         => $sessionId,
            'booking'    => $updated,
        );
    }

    /**
     * Rollback the session without changing the booking.
     *
     * @return array<string, mixed>
     */
    public function rollback(string $sessionId, int $userId): array
    {
        $session = $this->getSession($sessionId);
        $this->assertSessionOwner($session, $userId);

        $this->deleteSession($sessionId);

        CoreServiceProvider::logger()->log(
            sprintf('Drag session %s rolled back for booking #%d', $sessionId, $session['booking_id'])
        );

        return array(
            'session_id' => $sessionId,
            'id'         => $sessionId,
            'booking'    => $session['original'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function locateBooking(int $bookingId): array
    {
        $booking = $this->board->get($bookingId);

        if (! is_array($booking)) {
            throw new RuntimeException('Unable to load booking data.');
        }

        return $booking;
    }

    /**
     * @param array<string, mixed> $session
     */
    private function assertSessionOwner(array $session, int $userId): void
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Authenticated user required.');
        }

        if ((int) ($session['user_id'] ?? 0) !== $userId) {
            throw new InvalidArgumentException('This drag session belongs to another user.');
        }

        $expiresAt = isset($session['expires_at']) ? strtotime((string) $session['expires_at']) : 0;
        if ($expiresAt > 0 && time() > $expiresAt) {
            $this->deleteSession((string) $session['id']);
            throw new InvalidArgumentException('The drag session has expired, start a new one.');
        }
    }

    /**
     * @param array<string, mixed> $original
     * @param array<string, string> $pending
     *
     * @return array<string, mixed>
     */
    private function buildPreview(array $original, array $pending): array
    {
        $booking = $original;
        $startIso = $pending['date_start'] . 'T' . $pending['time_start'] . ':00';
        $endIso   = $pending['date_end'] . 'T' . $pending['time_end'] . ':00';

        $booking['from']   = $startIso;
        $booking['to']     = $endIso;
        $booking['date']   = $pending['date_start'];
        $booking['time']   = $pending['time_start'];
        $booking['date_end'] = $pending['date_end'];
        $booking['time_end'] = $pending['time_end'];

        return $booking;
    }

    /**
     * @param array<string, mixed> $session
     */
    private function storeSession(array $session): void
    {
        $id = (string) $session['id'];
        self::$memoryStore[$id] = $session;

        if (function_exists('set_transient')) {
            set_transient(
                self::TRANSIENT_PREFIX . $id,
                $session,
                self::SESSION_TTL
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getSession(string $sessionId): array
    {
        if (isset(self::$memoryStore[$sessionId])) {
            return self::$memoryStore[$sessionId];
        }

        if (function_exists('get_transient')) {
            $cached = get_transient(self::TRANSIENT_PREFIX . $sessionId);
            if (is_array($cached)) {
                self::$memoryStore[$sessionId] = $cached;

                return $cached;
            }
        }

        throw new InvalidArgumentException('Drag session not found or expired.');
    }

    private function deleteSession(string $sessionId): void
    {
        unset(self::$memoryStore[$sessionId]);

        if (function_exists('delete_transient')) {
            delete_transient(self::TRANSIENT_PREFIX . $sessionId);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function detectConflicts(int $bookingId, string $dateStart, string $timeStart, string $dateEnd, string $timeEnd): array
    {
        $conflicts = array();
        $start = $this->createDateTime($dateStart, $timeStart);
        $end   = $this->createDateTime($dateEnd, $timeEnd);

        if ($start >= $end) {
            return array(
                array(
                    'code'    => 'invalid_timeslot',
                    'message' => 'Eindtijd moet na starttijd liggen.',
                ),
            );
        }

        foreach ($this->manager->getBookings() as $booking) {
            if ((int) ($booking['id'] ?? 0) === $bookingId) {
                continue;
            }

            try {
                $otherStart = $this->createDateTime(
                    (string) ($booking['date'] ?? ''),
                    (string) ($booking['time'] ?? '')
                );
                $otherEnd = $this->createDateTime(
                    (string) ($booking['date_end'] ?? ($booking['date'] ?? '')),
                    (string) ($booking['time_end'] ?? ($booking['time'] ?? ''))
                );
            } catch (InvalidArgumentException $exception) {
                CoreServiceProvider::logger()->log(
                    sprintf(
                        'Skipping conflict check for booking #%d: %s',
                        (int) ($booking['id'] ?? 0),
                        $exception->getMessage()
                    )
                );
                continue;
            }

            if ($this->overlaps($start, $end, $otherStart, $otherEnd)) {
                $conflicts[] = array(
                    'code'    => 'overlap',
                    'message' => sprintf(
                        'Boeking #%d overlapt met het gekozen tijdslot.',
                        (int) ($booking['id'] ?? 0)
                    ),
                );
            }
        }

        return $conflicts;
    }

    private function overlaps(DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $otherStart, DateTimeImmutable $otherEnd): bool
    {
        if ($start >= $end || $otherStart >= $otherEnd) {
            return false;
        }

        return $start < $otherEnd && $end > $otherStart;
    }

    private function createDateTime(string $date, string $time): DateTimeImmutable
    {
        if ($date === '' || $time === '') {
            throw new InvalidArgumentException('Ontbrekende datum of tijd voor beschikbaarheidscontrole.');
        }

        try {
            return new DateTimeImmutable($date . ' ' . $time);
        } catch (\Exception $exception) {
            throw new InvalidArgumentException('Onjuist datum/tijd formaat voor beschikbaarheidscontrole.');
        }
    }

    private function sanitizeDate(string $value): string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new InvalidArgumentException('Datum heeft onjuist formaat (YYYY-MM-DD).');
        }

        return $value;
    }

    private function sanitizeTime(string $value): string
    {
        if (! preg_match('/^\d{2}:\d{2}$/', $value)) {
            throw new InvalidArgumentException('Tijd heeft onjuist formaat (HH:MM).');
        }

        return $value;
    }

    private function generateSessionId(): string
    {
        if (function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }

        try {
            return bin2hex(random_bytes(16));
        } catch (\Exception) {
            return uniqid('sbdp_drag_', true);
        }
    }
}
