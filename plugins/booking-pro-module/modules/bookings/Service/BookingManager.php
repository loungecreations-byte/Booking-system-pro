<?php

declare(strict_types=1);

namespace BSP\Bookings\Service;

use BSP\Commerce\Module as CommerceModule;
use BSP\Core\CoreServiceProvider;
use BSP\Planner\Module as PlannerModule;
use BSP\Planner\Vendor\CityGuideProfile;
use BSP\Planner\Vendor\CityGuideProfileStore;
use BSP\Sales\Vendors\VendorService;
use BSPModule\Core\Services\BookingTruthRuntimeService;
use DateTimeImmutable;
use InvalidArgumentException;
use SBDP\Bookings\Storage\LegacyBookingRepositoryAdapter;
use SBDP\Bookings\Storage\TransientBookingStorage;
use Throwable;

final class BookingManager
{
    private const DEFAULT_CURRENCY = 'EUR';

    private PaymentRequestDispatcher $paymentDispatcher;
    private OperationsSyncService $operationsSync;
    private BookingTruthRuntimeService $bookingTruthRuntime;

    public function __construct(
        private BookingRepositoryInterface $repository,
        private CommerceModule $commerce,
        private PlannerModule $planner,
        private CityGuideProfileStore $profiles,
        ?PaymentRequestDispatcher $paymentDispatcher = null,
        ?OperationsSyncService $operationsSync = null,
        ?BookingTruthRuntimeService $bookingTruthRuntime = null
    ) {
        $this->paymentDispatcher = $paymentDispatcher ?? new PaymentRequestDispatcher();
        $this->operationsSync = $operationsSync ?? new OperationsSyncService();
        $this->bookingTruthRuntime = $bookingTruthRuntime ?? new BookingTruthRuntimeService();
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{page_size:int,max_records:int,cutoff:?DateTimeImmutable,fallback_from:?string}
     */
    private function buildWooCommerceConstraints(array $filters): array
    {
        $pageSize   = $this->resolveRepositoryPageSize($filters);
        $maxRecords = $this->resolveRepositoryRecordCap($filters);
        $cutoff     = $this->resolveRepositoryCutoff($filters);

        $fallbackFrom = $cutoff instanceof DateTimeImmutable
            ? $cutoff->format('Y-m-d')
            : null;

        return [
            'page_size'     => $pageSize,
            'max_records'   => $maxRecords,
            'cutoff'        => $cutoff,
            'fallback_from' => $fallbackFrom,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<int, string>   $keys
     *
     * @return array<int, string>
     */
    private function extractFilterList(array $filters, array $keys): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $filters)) {
                return $this->normaliseFilterList($filters[$key]);
            }
        }

        return [];
    }

    /**
     * @param mixed $value
     *
     * @return array<int, string>
     */
    private function normaliseFilterList($value): array
    {
        $entries = $this->prepareFilterEntries($value);
        if ($entries === []) {
            return [];
        }

        $tokens = [];
        foreach ($entries as $entry) {
            if (\is_array($entry)) {
                $tokens[] = $this->extractIdentifierToken($entry);
            } else {
                $tokens[] = $this->normalizeFilterToken($entry);
            }
        }

        $tokens = \array_filter(
            \array_unique($tokens),
            static fn (string $token): bool => $token !== ''
        );

        \sort($tokens);

        return \array_values($tokens);
    }

    /**
     * @param mixed $value
     *
     * @return array<int, mixed>
     */
    private function prepareFilterEntries($value): array
    {
        if ($value === null) {
            return [];
        }

        if (\is_array($value)) {
            return $this->isSequentialArray($value) ? $value : [$value];
        }

        $string = \trim((string) $value);
        if ($string === '') {
            return [];
        }

        if (\strpos($string, ',') !== false) {
            return \array_filter(
                \array_map(
                    static fn (string $part): string => \trim($part),
                    \explode(',', $string)
                ),
                static fn (string $part): bool => $part !== ''
            );
        }

        return [$string];
    }

    /**
     * @param mixed $value
     */
    private function normalizeFilterToken($value): string
    {
        if (\is_array($value)) {
            return '';
        }

        $token = \trim((string) $value);
        if ($token === '') {
            return '';
        }

        $token = \strtolower($token);
        if ($token === 'unassigned') {
            return 'unassigned';
        }

        $token = \preg_replace('/[^a-z0-9]+/', '-', $token);

        return $token !== null ? \trim($token, '-') : '';
    }

    /**
     * @param mixed $value
     */
    private function extractIdentifierToken($value): string
    {
        if (\is_array($value)) {
            $candidates = ['id', 'slug', 'key', 'code', 'value'];
            foreach ($candidates as $candidate) {
                if (isset($value[$candidate]) && $value[$candidate] !== '') {
                    $token = $this->normalizeFilterToken($value[$candidate]);
                    if ($token !== '') {
                        return $token;
                    }
                }
            }

            if (isset($value['label']) && $value['label'] !== '') {
                return $this->normalizeFilterToken($value['label']);
            }

            return '';
        }

        return $this->normalizeFilterToken($value);
    }

    /**
     * @param array<int, mixed> $value
     */
    private function isSequentialArray(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return \array_keys($value) === \range(0, \count($value) - 1);
    }

    /**
     * @param array<int, array<string, mixed>> $bookings
     * @param array<string, mixed>             $filters
     *
     * @return array<int, array<string, mixed>>
     */
    private function applyFilterSet(array $bookings, array $filters): array
    {
        if (isset($filters['status'])) {
            $statuses = array_map(
                static fn ($status) => strtolower((string) $status),
                (array) $filters['status']
            );

            $bookings = array_filter(
                $bookings,
                static function (array $booking) use ($statuses): bool {
                    $status = strtolower((string) ($booking['status'] ?? ''));

                    return $status !== '' && in_array($status, $statuses, true);
                }
            );
        }

        $channels = $this->extractFilterList($filters, ['channels', 'channel']);
        if ($channels !== []) {
            $bookings = array_filter(
                $bookings,
                function (array $booking) use ($channels): bool {
                    $token = $this->extractIdentifierToken($booking['channel'] ?? null);
                    if ($token === '' && isset($booking['meta']['channel'])) {
                        $token = $this->extractIdentifierToken($booking['meta']['channel']);
                    }

                    return $token !== '' && in_array($token, $channels, true);
                }
            );
        }

        $vendors = $this->extractFilterList($filters, ['vendors', 'vendor', 'vendor_id', 'vendor_ids']);
        if ($vendors !== []) {
            $bookings = array_filter(
                $bookings,
                function (array $booking) use ($vendors): bool {
                    $token = $this->extractIdentifierToken($booking['vendor'] ?? null);
                    if ($token === '') {
                        return in_array('unassigned', $vendors, true);
                    }

                    return in_array($token, $vendors, true);
                }
            );
        }

        $outlets = $this->extractFilterList($filters, ['outlets', 'outlet', 'outlet_id', 'outlet_ids']);
        if ($outlets !== []) {
            $bookings = array_filter(
                $bookings,
                function (array $booking) use ($outlets): bool {
                    $token = $this->extractIdentifierToken($booking['vendor'] ?? null);
                    if ($token === '') {
                        return in_array('unassigned', $outlets, true);
                    }

                    return in_array($token, $outlets, true);
                }
            );
        }

        $products = $this->extractFilterList($filters, ['products', 'product', 'product_id', 'product_ids']);
        if ($products !== []) {
            $bookings = array_filter(
                $bookings,
                function (array $booking) use ($products): bool {
                    $items = isset($booking['items']) && is_array($booking['items']) ? $booking['items'] : [];
                    if ($items === []) {
                        return false;
                    }

                    foreach ($items as $item) {
                        if (! is_array($item)) {
                            continue;
                        }

                        $productId = $item['product_id'] ?? ($item['id'] ?? null);
                        if ($productId === null) {
                            continue;
                        }

                        $token = $this->normalizeFilterToken($productId);
                        if ($token !== '' && in_array($token, $products, true)) {
                            return true;
                        }
                    }

                    return false;
                }
            );
        }

        $agents = $this->extractFilterList($filters, ['assigned_agents', 'assigned_agent', 'resource', 'resources']);
        if ($agents !== []) {
            $bookings = array_filter(
                $bookings,
                function (array $booking) use ($agents): bool {
                    $resource = $booking['planner']['resource'] ?? ($booking['resource'] ?? null);
                    $token = $this->normalizeFilterToken($resource);

                    if ($token === '') {
                        $token = 'unassigned';
                    }

                    if (in_array($token, $agents, true)) {
                        return true;
                    }

                    if ($token === 'unassigned' && in_array('unassigned', $agents, true)) {
                        return true;
                    }

                    if (isset($booking['assignee']) && is_array($booking['assignee'])) {
                        $token = $this->extractIdentifierToken($booking['assignee']);
                        if ($token === '') {
                            $token = 'unassigned';
                        }

                        if (in_array($token, $agents, true)) {
                            return true;
                        }
                    }

                    return false;
                }
            );
        }

        if (isset($filters['customer_email']) && $filters['customer_email'] !== '') {
            $needle = strtolower(trim((string) $filters['customer_email']));

            $bookings = array_filter(
                $bookings,
                static function (array $booking) use ($needle): bool {
                    $email = strtolower(trim((string) ($booking['customer']['email'] ?? '')));
                    return $email !== '' && $email === $needle;
                }
            );
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $needle = strtolower((string) $filters['search']);

            $bookings = array_filter(
                $bookings,
                static function (array $booking) use ($needle): bool {
                    $haystacks = [
                        $booking['customer']['name'] ?? '',
                        $booking['customer']['email'] ?? '',
                        $booking['notes'] ?? '',
                    ];

                    if (isset($booking['items']) && is_array($booking['items'])) {
                        foreach ($booking['items'] as $item) {
                            if (isset($item['label'])) {
                                $haystacks[] = (string) $item['label'];
                            }
                        }
                    }

                    foreach ($haystacks as $haystack) {
                        if ($haystack !== '' && strpos(strtolower((string) $haystack), $needle) !== false) {
                            return true;
                        }
                    }

                    return false;
                }
            );
        }

        if (isset($filters['date_from']) || isset($filters['date_to'])) {
            $from = $this->normalizeFilterDateValue($filters['date_from'] ?? null);
            $to   = $this->normalizeFilterDateValue($filters['date_to'] ?? null);

            $bookings = array_filter(
                $bookings,
                static function (array $booking) use ($from, $to): bool {
                    $date = isset($booking['date']) ? (string) $booking['date'] : '';
                    if ($date === '') {
                        return false;
                    }

                    if ($from !== null && $date < $from) {
                        return false;
                    }

                    if ($to !== null && $date > $to) {
                        return false;
                    }

                    return true;
                }
            );
        }

        if (isset($filters['location']) && $filters['location'] !== '') {
            $needle = strtolower((string) $filters['location']);
            $bookings = array_filter(
                $bookings,
                static function (array $booking) use ($needle): bool {
                    $candidates = [];

                    if (isset($booking['location']) && is_string($booking['location'])) {
                        $candidates[] = $booking['location'];
                    }

                    if (isset($booking['planner']) && is_array($booking['planner'])) {
                        if (isset($booking['planner']['location']) && is_string($booking['planner']['location'])) {
                            $candidates[] = $booking['planner']['location'];
                        }

                        if (isset($booking['planner']['venue']) && is_string($booking['planner']['venue'])) {
                            $candidates[] = $booking['planner']['venue'];
                        }
                    }

                    foreach ($candidates as $candidate) {
                        if ($candidate === '') {
                            continue;
                        }

                        if (strpos(strtolower($candidate), $needle) !== false) {
                            return true;
                        }
                    }

                    return false;
                }
            );
        }

        return array_values($bookings);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function resolveRepositoryPageSize(array $filters): int
    {
        $pageSize = 250;

        if (\function_exists('apply_filters')) {
            $pageSize = (int) \apply_filters('sbdp/booking/woocommerce_page_size', $pageSize, $filters);
        }

        return max(1, $pageSize);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function resolveRepositoryRecordCap(array $filters): int
    {
        $cap = 1000;

        if (\function_exists('apply_filters')) {
            $cap = (int) \apply_filters('sbdp/booking/woocommerce_max_records', $cap, $filters);
        }

        return $cap > 0 ? $cap : 0;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function resolveRepositoryCutoff(array $filters): ?DateTimeImmutable
    {
        $from = $this->normalizeFilterDateValue($filters['date_from'] ?? null);

        if ($from === null) {
            $from = $this->defaultLookbackStart();
        }

        if ($from === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($from . ' 00:00:00');
        } catch (\Throwable $exception) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
        }

        return null;
    }

    private function defaultLookbackStart(): ?string
    {
        $days = 180;

        if (\function_exists('apply_filters')) {
            $days = (int) \apply_filters('sbdp/booking/default_lookback_days', $days);
        }

        if ($days <= 0) {
            return null;
        }

        $reference = new DateTimeImmutable(\sprintf('-%d days', $days));

        return $reference->format('Y-m-d');
    }

    /**
     * @param mixed $value
     */
    private function normalizeFilterDateValue($value): ?string
    {
        if (! \is_string($value)) {
            return null;
        }

        $value = \trim($value);
        if ($value === '') {
            return null;
        }

        return \preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    public static function createDefault(
        ?BookingRepositoryInterface $repository = null,
        ?PlannerModule $planner = null,
        ?CommerceModule $commerce = null,
        ?CityGuideProfileStore $profiles = null
    ): self
    {
        if ($repository === null) {
            $repository = WooCommerceBookingRepository::isSupported()
                ? new WooCommerceBookingRepository()
                : new LegacyBookingRepositoryAdapter(new TransientBookingStorage());
        }
        $profiles    = $profiles ?? new CityGuideProfileStore();
        $planner     = $planner ?? new PlannerModule();
        $commerce    = $commerce ?? new CommerceModule();
        $dispatcher  = new PaymentRequestDispatcher();
        $operationsSync = new OperationsSyncService();

        return new self($repository, $commerce, $planner, $profiles, $dispatcher, $operationsSync, new BookingTruthRuntimeService());
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function createBooking(array $data, ?array $bookingTruthContext = null): array
    {
        $payload   = $this->validatePayload($data);
        $truthContext = $this->requireCreateWriteContext($payload, $bookingTruthContext ?? ($data['booking_truth'] ?? null));
        $existing  = $this->repository->all();
        $status    = $payload['status'];
        unset($payload['status']);
        $record    = $this->buildBookingRecord($payload, $status, $truthContext);
        $record    = $this->assignPlannerDetails($record, $payload);
        $record    = $this->enrichWithVendor($record, $payload);
        $record    = $this->enforceAvailability($record, $existing);
        $stored    = $this->repositoryCreate($record);
        $this->syncWooBookingTruthMetaIfOrderExists($stored, $truthContext);
        $this->syncOperationalProjection($stored);

        CoreServiceProvider::logger()->log(
            sprintf('Booking #%d created with status %s', $stored['id'], $stored['status'])
        );

        return $stored;
    }

    /**
     * Public booking creation accepts intent only. Server-side runtime and pricing
     * derive the stored booking truth.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createPublicBooking(array $data): array
    {
        [$payload, $truthContext] = $this->adaptPublicIntentPayload($data, 'public_rest_booking_create');

        return $this->createBooking($payload, $truthContext);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function requestBooking(array $data, ?array $bookingTruthContext = null): array
    {
        $created = $this->createBooking($data, $bookingTruthContext);

        $updated = $this->repositoryUpdate(
            $created['id'],
            [
                'status'     => $this->normalizeStatus('requested'),
                'updated_at' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
            ]
        );
        $this->syncOperationalProjection($updated);

        $this->notifyAdmin($updated);
        CoreServiceProvider::logger()->log(sprintf('Booking #%d requested', $updated['id']));

        $paymentMeta = $this->paymentDispatcher->prepare($updated);
        if (is_array($paymentMeta)) {
            $updated = $this->applyCapturedState($updated, $paymentMeta);
            $this->notifyCaptured($updated);
        }

        return $updated;
    }

    /**
     * Public booking requests accept intent only. Server-side runtime and pricing
     * derive the stored booking truth.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function requestPublicBooking(array $data): array
    {
        [$payload, $truthContext] = $this->adaptPublicIntentPayload($data, 'public_rest_booking_request');

        return $this->requestBooking($payload, $truthContext);
    }

    public function dispatchInvoice(int $bookingId, bool $force = false): array
    {
        return $this->processInvoice($bookingId, true, $force);
    }

    public function prepareInvoiceDocument(int $bookingId): array
    {
        return $this->processInvoice($bookingId, false, false);
    }

    private function processInvoice(int $bookingId, bool $sendEmail, bool $refreshTimestamp): array
    {
        $booking = $this->repository->find($bookingId);
        if ($booking === null) {
            throw new InvalidArgumentException('Unknown booking identifier.');
        }

        $payload = $this->paymentDispatcher->prepare($booking, $sendEmail);
        if (! is_array($payload)) {
            return $booking;
        }

        $updated = $this->applyCapturedState(
            $booking,
            $payload,
            $refreshTimestamp,
            $sendEmail
        );

        if ($sendEmail) {
            $this->notifyCaptured($updated);
        }

        $this->notifyInvoiceIssued(
            $updated,
            $payload['order']['id'] ?? null,
            $sendEmail
        );

        return $updated;
    }

    public function payBooking(int $bookingId, string $method): array
    {
        return $this->payBookingWithReference($bookingId, $method, null);
    }

    public function payBookingWithReference(int $bookingId, string $method, ?string $reference): array
    {
        $booking = $this->repository->find($bookingId);
        if ($booking === null) {
            throw new InvalidArgumentException('Unknown booking identifier.');
        }

        $method = trim($method);
        if ($method === '') {
            throw new InvalidArgumentException('Payment method is required.');
        }

        $orderMessage = $this->commerce->processOrder($bookingId);
        $timestamp    = (new DateTimeImmutable())->format(DateTimeImmutable::ATOM);

        $orderDetails = $booking['order'] ?? [];
        if (! is_array($orderDetails)) {
            $orderDetails = [];
        }

        $orderDetails['status_message'] = $orderMessage;
        $orderDetails['processed_at']   = $timestamp;
        $orderDetails['status']         = 'processing';

        $updatePayload = [
            'status'     => $this->normalizeStatus('paid'),
            'paid_at'    => $timestamp,
            'updated_at' => $timestamp,
            'payment'    => [
                'method'    => $method,
                'reference' => $reference ?? '',
            ],
            'order'      => $orderDetails,
        ];

        $paymentRequest = $booking['payment_request'] ?? null;
        if (is_array($paymentRequest)) {
            $paymentRequest['status']       = 'completed';
            $paymentRequest['completed_at'] = $timestamp;
            if ($reference !== null && $reference !== '') {
                $paymentRequest['reference'] = $reference;
            }
            $updatePayload['payment_request'] = $paymentRequest;
        }

        $updated = $this->repositoryUpdate($bookingId, $updatePayload);
        $this->syncOperationalProjection($updated);

        $this->triggerWooCommerceOrder($updated);
        CoreServiceProvider::logger()->log(
            sprintf('Booking #%d paid via %s', $bookingId, $method)
        );

        return $updated;
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<int, array<string, mixed>>
     */
    public function getBookings(array $filters = []): array
    {
        if (isset($filters['booking_id'])) {
            $bookingId = (int) $filters['booking_id'];
            unset($filters['booking_id']);

            if ($bookingId > 0) {
                $record = $this->repository->find($bookingId);

                return $record !== null ? [$record] : [];
            }
        }

        $filtersForApply = $filters;

        if ($this->repository instanceof WooCommerceBookingRepository) {
            $constraints = $this->buildWooCommerceConstraints($filters);
            $bookings    = $this->repository->allWithConstraints(
                $constraints['page_size'],
                $constraints['max_records'],
                $constraints['cutoff']
            );

            if (! isset($filtersForApply['date_from']) && $constraints['fallback_from'] !== null) {
                $filtersForApply['date_from'] = $constraints['fallback_from'];
            }
        } else {
            $bookings = $this->repository->all();
        }

        return $filtersForApply === [] ? $bookings : $this->applyFilterSet($bookings, $filtersForApply);
    }

    public function rescheduleBooking(
        int $bookingId,
        string $date,
        string $time,
        ?string $dateEnd = null,
        ?string $timeEnd = null,
        ?array $bookingTruthContext = null
    ): array {
        $booking = $this->repository->find($bookingId);
        if ($booking === null) {
            throw new InvalidArgumentException('Unknown booking identifier.');
        }

        $date = $this->sanitizeDate($date);
        $time = $this->sanitizeTime($time);
        $dateEnd = $dateEnd !== null ? $this->sanitizeDate($dateEnd) : $date;
        $timeEnd = $timeEnd !== null ? $this->sanitizeTime($timeEnd) : $time;

        $payload = [
            'time'     => $time,
            'customer' => $booking['customer'],
        ];
        $writePayload = [
            'date'         => $date,
            'time'         => $time,
            'date_end'     => $dateEnd,
            'time_end'     => $timeEnd,
            'participants' => (int) ($booking['participants'] ?? 1),
            'items'        => isset($booking['items']) && is_array($booking['items']) ? $booking['items'] : [],
        ];
        $truthContext = $this->requireMutationWriteContext('reschedule', $writePayload, $bookingTruthContext);

        $recordWithPlanner = $this->assignPlannerDetails(
            array_merge($booking, ['date' => $date, 'time' => $time]),
            $payload
        );

        $timestamp = (new DateTimeImmutable())->format(DateTimeImmutable::ATOM);

        $updated = $this->repositoryUpdate(
            $bookingId,
            [
                'date'       => $date,
                'time'       => $time,
                'date_end'   => $dateEnd,
                'time_end'   => $timeEnd,
                'updated_at' => $timestamp,
                'planner'    => $recordWithPlanner['planner'] ?? ($booking['planner'] ?? null),
                'booking_truth' => $truthContext,
            ]
        );
        $this->syncWooBookingTruthMetaIfOrderExists($updated, $truthContext);
        $this->syncOperationalProjection($updated);

        $this->notifyReschedule($updated);

        return $updated;
    }

    /**
     * @param array<string, mixed> $mutations
     */
    public function updateBookingDetails(int $bookingId, array $mutations, ?array $bookingTruthContext = null): array
    {
        $booking = $this->repository->find($bookingId);
        if ($booking === null) {
            throw new InvalidArgumentException('Unknown booking identifier.');
        }

        $needsReschedule = isset($mutations['date'])
            || isset($mutations['time'])
            || isset($mutations['date_end'])
            || isset($mutations['time_end']);
        if ($needsReschedule) {
            $nextDateStart = isset($mutations['date'])
                ? (string) $mutations['date']
                : (string) $booking['date'];
            $nextTimeStart = isset($mutations['time'])
                ? (string) $mutations['time']
                : (string) $booking['time'];
            $nextDateEnd = isset($mutations['date_end'])
                ? (string) $mutations['date_end']
                : (string) ($booking['date_end'] ?? $booking['date']);
            $nextTimeEnd = isset($mutations['time_end'])
                ? (string) $mutations['time_end']
                : (string) ($booking['time_end'] ?? $booking['time']);

            $booking = $this->rescheduleBooking(
                $bookingId,
                $nextDateStart,
                $nextTimeStart,
                $nextDateEnd,
                $nextTimeEnd,
                $bookingTruthContext
            );
        }

        $changes = [];

        if (array_key_exists('notes', $mutations)) {
            $changes['notes'] = isset($mutations['notes']) ? (string) $mutations['notes'] : null;
        }

        if (array_key_exists('status', $mutations)) {
            $changes['status'] = $this->normalizeStatus((string) $mutations['status']);
        }

        if (array_key_exists('participants', $mutations)) {
            $participants = (int) $mutations['participants'];
            if ($participants <= 0) {
                throw new InvalidArgumentException('Participants must be greater than zero.');
            }

            $changes['participants'] = $participants;
        }

        if (array_key_exists('currency', $mutations)) {
            $currency = (string) $mutations['currency'];
            if ($currency === '') {
                $currency = self::DEFAULT_CURRENCY;
            }

            $changes['currency'] = $currency;
        }

        if (array_key_exists('total', $mutations)) {
            $changes['total'] = (float) $mutations['total'];
        }

        if ($changes === []) {
            return $booking;
        }

        if (array_key_exists('participants', $changes)) {
            $writePayload = [
                'date'         => (string) ($booking['date'] ?? ''),
                'time'         => (string) ($booking['time'] ?? ''),
                'date_end'     => (string) ($booking['date_end'] ?? $booking['date'] ?? ''),
                'time_end'     => (string) ($booking['time_end'] ?? $booking['time'] ?? ''),
                'participants' => (int) ($changes['participants'] ?? $booking['participants'] ?? 1),
                'items'        => isset($booking['items']) && is_array($booking['items']) ? $booking['items'] : [],
            ];
            $truthContext = $this->requireMutationWriteContext('update_participants', $writePayload, $bookingTruthContext);
            $changes['booking_truth'] = $truthContext;
        } else {
            $truthContext = isset($booking['booking_truth']) && is_array($booking['booking_truth']) ? $booking['booking_truth'] : null;
        }

        $changes['updated_at'] = (new DateTimeImmutable())->format(DateTimeImmutable::ATOM);

        $updated = $this->repositoryUpdate($bookingId, $changes);
        if (is_array($truthContext)) {
            $this->syncWooBookingTruthMetaIfOrderExists($updated, $truthContext);
        }
        $this->syncOperationalProjection($updated);
        $this->notifyUpdated($updated);

        return $updated;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function assignPlannerDetails(array $record, array $payload): array
    {
        $resources = $this->collectPlannerResources();
        $allocation = $this->planner->assignResource(
            [
                'resource' => $record['resource'],
                'time'     => $payload['time'],
                'name'     => $record['customer']['name'],
            ],
            $resources
        );

        $record['resource'] = $allocation['resource'] ?? 'unassigned';
        $record['planner']  = [
            'resource' => $record['resource'],
            'slot'     => $payload['time'],
            'timeline' => $this->planner->generateSchedule([
                [
                    'time'     => $payload['time'],
                    'name'     => $record['customer']['name'],
                    'resource' => $record['resource'],
                ],
            ]),
        ];

        return $record;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function enrichWithVendor(array $record, array $payload): array
    {
        $vendorId = $payload['vendor_id'];
        if ($vendorId === null || $vendorId <= 0) {
            return $record;
        }

        $record['vendor'] = [
            'id'           => $vendorId,
            'name'         => null,
            'status'       => null,
            'availability' => [],
        ];

        if (! class_exists(VendorService::class)) {
            return $record;
        }

        if (! function_exists('get_post') || ! isset($GLOBALS['wpdb'])) {
            return $record;
        }

        try {
            VendorService::init();
            $data = VendorService::get($vendorId, true);
            if (is_array($data)) {
                $record['vendor']['name']   = isset($data['name']) ? (string) $data['name'] : null;
                $record['vendor']['status'] = isset($data['status']) ? (string) $data['status'] : null;
                $record['vendor']['availability'] = $this->extractVendorAvailability($vendorId);
            }
        } catch (Throwable $exception) {
            CoreServiceProvider::logger()->log(
                sprintf('Vendor lookup failed for #%d: %s', $vendorId, $exception->getMessage())
            );
        }

        return $record;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<int, array<string, mixed>> $existing
     *
     * @return array<string, mixed>
     */
    private function enforceAvailability(array $record, array $existing): array
    {
        $plannerPayload = [];
        foreach ($existing as $booking) {
            $plannerPayload[] = [
                'time'     => (string) ($booking['planner']['slot'] ?? $booking['time'] ?? ''),
                'resource' => (string) ($booking['planner']['resource'] ?? $booking['resource'] ?? ''),
                'name'     => (string) ($booking['customer']['name'] ?? ''),
            ];
        }

        $plannerPayload[] = [
            'time'     => (string) ($record['planner']['slot'] ?? $record['time']),
            'resource' => (string) ($record['planner']['resource'] ?? $record['resource']),
            'name'     => (string) $record['customer']['name'],
        ];

        $hasConflict = $this->planner->hasOverlap($plannerPayload);
        $record['conflict'] = $hasConflict;
        if ($hasConflict) {
            $record['status'] = 'conflict';
        }

        return $record;
    }

    private function notifyAdmin(array $booking): void
    {
        if (! function_exists('get_option') || ! function_exists('wp_mail')) {
            return;
        }

        $adminEmail = (string) get_option('admin_email');
        if ($adminEmail === '') {
            return;
        }

        $subject = function_exists('__') ? __('Nieuwe boekingsaanvraag', 'sbdp') : 'Nieuwe boekingsaanvraag';
        $message = sprintf(
            "Nieuwe boekingsaanvraag #%d\nNaam: %s\nDatum: %s\nTijd: %s",
            (int) ($booking['id'] ?? 0),
            (string) ($booking['customer']['name'] ?? ''),
            (string) ($booking['date'] ?? ''),
            (string) ($booking['time'] ?? '')
        );

        wp_mail($adminEmail, $subject, $message);
    }

    private function triggerWooCommerceOrder(array $booking): void
    {
        if (function_exists('do_action')) {
            do_action('sbdp/booking/woocommerce_order_stub', $booking);
        }

        $orderId    = (int) ($booking['order']['id'] ?? 0);
        $reference  = (string) ($booking['payment']['reference'] ?? '');
        $methodName = (string) ($booking['payment']['method'] ?? 'manual');

        if ($orderId > 0 && function_exists('wc_get_order')) {
            try {
                $order = wc_get_order($orderId);
                if ($order) {
                    if (method_exists($order, 'payment_complete')) {
                        $transactionId = $reference !== '' ? $reference : null;
                        $order->payment_complete($transactionId);
                    } elseif (method_exists($order, 'update_status')) {
                        $order->update_status('processing');
                    }

                    if (method_exists($order, 'add_order_note')) {
                        $order->add_order_note(
                            sprintf(
                                'Planner booking #%d marked paid via %s.',
                                $booking['id'] ?? 0,
                                $methodName
                            )
                        );
                    }

                    if (method_exists($order, 'save')) {
                        $order->save();
                    }

                    return;
                }
            } catch (Throwable $exception) {
                CoreServiceProvider::logger()->log(
                    sprintf(
                        'WooCommerce order update failed for booking #%d: %s',
                        $booking['id'] ?? 0,
                        $exception->getMessage()
                    )
                );
            }
        }

        if (function_exists('wc_create_order')) {
            try {
                $order = wc_create_order();
                if (is_object($order) && method_exists($order, 'add_meta_data')) {
                    $order->add_meta_data('_sbdp_booking_id', $booking['id'] ?? 0, true);
                }
                if (is_object($order) && method_exists($order, 'add_order_note')) {
                    $order->add_order_note('Auto-generated planner payment placeholder order.');
                }
                if (is_object($order) && method_exists($order, 'save')) {
                    $order->save();
                }
            } catch (Throwable $exception) {
                CoreServiceProvider::logger()->log(
                    sprintf(
                        'WooCommerce order stub failed for booking #%d: %s',
                        $booking['id'] ?? 0,
                        $exception->getMessage()
                    )
                );
            }

            return;
        }

        CoreServiceProvider::logger()->log(
            sprintf('WooCommerce order stub triggered for booking #%d', $booking['id'] ?? 0)
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function buildBookingRecord(array $payload, string $status, array $truthContext): array
    {
        $lineTotal = 0.0;
        foreach ($payload['items'] as $item) {
            $lineTotal += (float) $item['unit_price'] * max(1, (int) $item['quantity']);
        }

        // BookingManager is an operations layer. Preserve the commercial amount as
        // a snapshot of the provided line items and keep pricing_rules as metadata.
        $total = round(max(0.0, $lineTotal), 2);
        $reserved = $this->commerce->reserveInventory($payload['items']);
        $timestamp = (new DateTimeImmutable())->format(DateTimeImmutable::ATOM);
        $dateEnd = $payload['date_end'] ?? $payload['date'];
        $timeEnd = $payload['time_end'] ?? $payload['time'];
        $duration = $payload['duration'] ?? null;

        return [
            'status'             => $status,
            'customer'           => $payload['customer'],
            'date'               => $payload['date'],
            'time'               => $payload['time'],
            'date_end'           => $dateEnd,
            'time_end'           => $timeEnd,
            'duration_minutes'   => $duration,
            'participants'       => $payload['participants'],
            'items'              => $payload['items'],
            'notes'              => $payload['notes'],
            'currency'           => $payload['currency'],
            'total'              => $total,
            'booking_reference'  => $payload['booking_reference'],
            'created_at'         => $timestamp,
            'updated_at'         => $timestamp,
            'pricing_snapshot'   => [
                'source'        => 'line_items_gross_snapshot',
                'line_total'    => $total,
                'rules_present' => $payload['pricing_rules'] !== [],
            ],
            'pricing_rules'      => $payload['pricing_rules'],
            'inventory_reserved' => $reserved,
            'channel'            => $payload['channel'],
            'vendor'             => null,
            'resource'           => 'unassigned',
            'order'              => null,
            'payment'            => null,
            'payment_request'    => null,
            'captured_at'        => null,
            'booking_truth'      => $truthContext,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function validatePayload(array $payload): array
    {
        $customer = $payload['customer'] ?? [];
        if (! is_array($customer)) {
            throw new InvalidArgumentException('Customer details are required.');
        }

        $name  = trim((string) ($customer['name'] ?? ''));
        $email = trim((string) ($customer['email'] ?? ''));
        if ($name === '' || $email === '') {
            throw new InvalidArgumentException('Customer name and email are required.');
        }

        $date = (string) ($payload['date'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('Booking date must be in YYYY-MM-DD format.');
        }

        $time = trim((string) ($payload['time'] ?? '09:00'));
        if ($time === '') {
            $time = '09:00';
        }

        $participants = (int) ($payload['participants'] ?? 0);
        if ($participants <= 0) {
            throw new InvalidArgumentException('Participants must be greater than zero.');
        }

        $items = $payload['items'] ?? [];
        if (! is_array($items) || $items === []) {
            throw new InvalidArgumentException('At least one booking item is required.');
        }

        $pricingRules = $payload['pricing_rules'] ?? [];
        if (! is_array($pricingRules)) {
            $pricingRules = [];
        }

        $notes = isset($payload['notes']) ? (string) $payload['notes'] : null;
        $currency = (string) ($payload['currency'] ?? self::DEFAULT_CURRENCY);
        if ($currency === '') {
            $currency = self::DEFAULT_CURRENCY;
        }

        $channel = isset($payload['channel']) ? (string) $payload['channel'] : null;
        if ($channel !== null && $channel === '') {
            $channel = null;
        }

        $vendorId = isset($payload['vendor_id']) ? (int) $payload['vendor_id'] : null;
        if ($vendorId !== null && $vendorId <= 0) {
            $vendorId = null;
        }

        $status = isset($payload['status']) ? $this->normalizeStatus((string) $payload['status']) : 'created';
        $bookingReference = trim((string) ($payload['booking_reference'] ?? ''));

        $dateEnd = (string) ($payload['date_end'] ?? $date);
        if ($dateEnd === '') {
            $dateEnd = $date;
        } elseif (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateEnd)) {
            throw new InvalidArgumentException('Booking end date must be in YYYY-MM-DD format.');
        }

        $timeEnd = trim((string) ($payload['time_end'] ?? ''));
        if ($timeEnd === '') {
            $timeEnd = $time;
        }

        $durationMinutes = null;
        if (isset($payload['duration'])) {
            $durationMinutes = (int) $payload['duration'];
            if ($durationMinutes < 0) {
                $durationMinutes = null;
            }
        }

        $customerId = isset($customer['id']) ? (int) $customer['id'] : null;
        if ($customerId !== null && $customerId <= 0) {
            $customerId = null;
        }

        $company  = trim((string) ($customer['company'] ?? ''));
        $phone    = trim((string) ($customer['phone'] ?? ''));
        $billing  = $this->sanitizeAddress(isset($customer['billing']) ? $customer['billing'] : null);
        $shipping = $this->sanitizeAddress(isset($customer['shipping']) ? $customer['shipping'] : null);

        if ($company === '') {
            $company = $billing['company'];
        }

        return [
            'customer'      => [
                'id'       => $customerId,
                'name'     => $name,
                'email'    => $email,
                'phone'    => $phone,
                'company'  => $company,
                'billing'  => $billing,
                'shipping' => $shipping,
            ],
            'date'          => $date,
            'time'          => $time,
            'date_end'      => $dateEnd,
            'time_end'      => $timeEnd,
            'duration'      => $durationMinutes,
            'participants'  => $participants,
            'items'         => $this->normalizeItems($items),
            'pricing_rules' => $pricingRules,
            'notes'         => $notes,
            'currency'      => $currency,
            'channel'       => $channel,
            'vendor_id'     => $vendorId,
            'status'        => $status,
            'booking_reference' => $bookingReference,
        ];
    }

    private function sanitizeDate(string $date): string
    {
        $date = trim($date);
        if ($date === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('Date must be in YYYY-MM-DD format.');
        }

        return $date;
    }

    private function sanitizeTime(string $time): string
    {
        $time = trim($time);
        if ($time === '') {
            throw new InvalidArgumentException('Time is required.');
        }

        return $time;
    }

    /**
     * @param array<string, mixed>|null $address
     *
     * @return array<string, string>
     */
    private function sanitizeAddress($address): array
    {
        $fields = [
            'company',
            'address_1',
            'address_2',
            'postcode',
            'city',
            'state',
            'country',
        ];

        $sanitized = [];
        foreach ($fields as $field) {
            $value = '';
            if (is_array($address) && isset($address[$field])) {
                $value = trim((string) $address[$field]);
            }

            $sanitized[$field] = $value;
        }

        $sanitized['formatted'] = '';

        return $sanitized;
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === '') {
            throw new InvalidArgumentException('Status cannot be empty.');
        }

        return $status;
    }

    private function notifyReschedule(array $booking): void
    {
        CoreServiceProvider::logger()->log(
            sprintf(
                'Booking #%d rescheduled to %s %s',
                $booking['id'] ?? 0,
                $booking['date'] ?? '',
                $booking['time'] ?? ''
            )
        );

        if (function_exists('do_action')) {
            do_action('sbdp/booking/rescheduled', $booking);
        }
    }

    private function notifyUpdated(array $booking): void
    {
        CoreServiceProvider::logger()->log(
            sprintf('Booking #%d details updated', $booking['id'] ?? 0)
        );

        if (function_exists('do_action')) {
            do_action('sbdp/booking/updated', $booking);
        }
    }

    private function notifyInvoiceIssued(array $booking, $orderId, bool $emailSent): void
    {
        $message = sprintf(
            $emailSent
                ? 'Invoice dispatched for booking #%d'
                : 'Invoice prepared for booking #%d',
            $booking['id'] ?? 0
        );

        if (is_numeric($orderId) && (int) $orderId > 0) {
            $message .= sprintf(' (order #%d)', (int) $orderId);
        }

        CoreServiceProvider::logger()->log($message);

        if (function_exists('do_action')) {
            do_action(
                'sbdp/booking/invoice/issued',
                $booking,
                is_numeric($orderId) ? (int) $orderId : null,
                $emailSent
            );
        }
    }

    /**
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $meta
     */
    private function applyCapturedState(
        array $booking,
        array $meta,
        bool $refreshTimestamp = false,
        bool $updateStatus = true
    ): array
    {
        if (! isset($booking['id'])) {
            return $booking;
        }

        $timestamp = (new DateTimeImmutable())->format(DateTimeImmutable::ATOM);
        $updatePayload = $meta;
        $updatePayload['updated_at'] = $timestamp;

        if (
            $updateStatus
            && (
                $refreshTimestamp
                || ! isset($booking['captured_at'])
                || $booking['captured_at'] === null
                || $booking['captured_at'] === ''
            )
        ) {
            $updatePayload['captured_at'] = $timestamp;
        }

        if ($updateStatus) {
            $currentStatus = strtolower((string) ($booking['status'] ?? ''));
            if (! in_array($currentStatus, ['paid', 'completed', 'cancelled'], true)) {
                $updatePayload['status'] = $this->normalizeStatus('captured');
            }
        }

        $updated = $this->repositoryUpdate((int) $booking['id'], $updatePayload);
        $this->syncOperationalProjection($updated);

        return $updated;
    }

    private function notifyCaptured(array $booking): void
    {
        CoreServiceProvider::logger()->log(
            sprintf('Booking #%d captured - payment request dispatched', $booking['id'] ?? 0)
        );

        if (function_exists('do_action')) {
            do_action('sbdp/booking/captured', $booking);
        }
    }

    /**
     * @param array<string, mixed> $booking
     */
    private function syncOperationalProjection(array $booking): void
    {
        $this->operationsSync->sync($booking);
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function repositoryCreate(array $record): array
    {
        return BookingRepositoryWriteGuard::allowManagerWrite(
            fn (): array => $this->repository->create($record)
        );
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function repositoryUpdate(int $bookingId, array $changes): array
    {
        return BookingRepositoryWriteGuard::allowManagerWrite(
            fn (): array => $this->repository->update($bookingId, $changes)
        );
    }

    /**
     * @param array<int, mixed> $items
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeItems(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $productId = (int) ($item['product_id'] ?? 0);
            $quantity  = (int) ($item['quantity'] ?? 1);
            $unitPrice = (float) ($item['unit_price'] ?? 0.0);

            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }

            $normalized[] = [
                'product_id' => $productId,
                'quantity'   => $quantity,
                'unit_price' => $unitPrice,
                'label'      => isset($item['label']) ? (string) $item['label'] : '',
                'resource_id'=> isset($item['resource_id']) ? (int) $item['resource_id'] : 0,
                'participants' => isset($item['participants']) ? (int) $item['participants'] : null,
                'meta'       => isset($item['meta']) && is_array($item['meta']) ? $item['meta'] : array(),
            ];
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('Valid booking items are required.');
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function adaptPublicIntentPayload(array $payload, string $channel): array
    {
        $participants = max(1, (int) ($payload['participants'] ?? 0));
        if ($participants <= 0) {
            throw new InvalidArgumentException('Participants must be greater than zero.');
        }

        $customer = $this->extractPublicCustomer(
            isset($payload['customer']) && is_array($payload['customer'])
                ? $payload['customer']
                : array()
        );

        $rawItems = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : array();
        $publicItems = [];
        $firstSlot = null;

        foreach ($rawItems as $item) {
            if (! is_array($item)) {
                continue;
            }

            $normalizedItem = $this->normalizePublicIntentItem($item, $participants);
            if ($firstSlot === null) {
                $firstSlot = $normalizedItem;
            }
            $publicItems[] = $normalizedItem;
        }

        if ($publicItems === []) {
            throw new InvalidArgumentException('Valid booking items are required.');
        }

        if ($firstSlot === null) {
            throw new InvalidArgumentException('Valid booking items are required.');
        }

        $sanitizedPayload = [
            'customer'     => $customer,
            'date'         => $firstSlot['date'],
            'time'         => $firstSlot['time'],
            'date_end'     => $firstSlot['date_end'],
            'time_end'     => $firstSlot['time_end'],
            'participants' => $participants,
            'items'        => array_map(
                fn (array $item): array => [
                    'product_id'   => $item['product_id'],
                    'quantity'     => $participants,
                    'unit_price'   => $item['unit_price'],
                    'label'        => '',
                    'participants' => $participants,
                    'meta'         => $item['meta'],
                ],
                $publicItems
            ),
            'notes'        => $this->extractPublicCustomerNote($payload),
            'currency'     => $this->resolvePublicCurrency(),
            'channel'      => $channel,
            'pricing_rules'=> array(),
            'vendor_id'    => null,
            'status'       => 'created',
        ];

        $truthContext = $this->bookingTruthRuntime->resolveBookingWriteContext(
            $sanitizedPayload,
            [
                'validation_source' => $channel,
            ]
        );

        return [$sanitizedPayload, $truthContext];
    }

    /**
     * @param array<string, mixed> $customer
     * @return array<string, mixed>
     */
    private function extractPublicCustomer(array $customer): array
    {
        return [
            'name'     => (string) ($customer['name'] ?? ''),
            'email'    => (string) ($customer['email'] ?? ''),
            'phone'    => (string) ($customer['phone'] ?? ''),
            'company'  => (string) ($customer['company'] ?? ''),
            'billing'  => isset($customer['billing']) && is_array($customer['billing']) ? $customer['billing'] : array(),
            'shipping' => isset($customer['shipping']) && is_array($customer['shipping']) ? $customer['shipping'] : array(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractPublicCustomerNote(array $payload): ?string
    {
        $note = isset($payload['note']) ? trim((string) $payload['note']) : '';
        if ($note === '' && isset($payload['notes'])) {
            $note = trim((string) $payload['notes']);
        }

        return $note === '' ? null : $note;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function normalizePublicIntentItem(array $item, int $participants): array
    {
        $productId = (int) ($item['product_id'] ?? 0);
        if ($productId <= 0) {
            throw new InvalidArgumentException('Valid booking items are required.');
        }

        $date = $this->resolvePublicIntentDate($item);
        $startIso = $this->resolvePublicIntentIso($date, isset($item['start']) ? (string) $item['start'] : '', 'start');
        $endIso = $this->resolvePublicIntentIso($date, isset($item['end']) ? (string) $item['end'] : '', 'end');
        $time = $this->extractTimePart($startIso);
        $timeEnd = $this->extractTimePart($endIso);
        if ($time === '' || $timeEnd === '') {
            throw new InvalidArgumentException('Booking item start and end times are required.');
        }

        $combiIds = array_values(array_filter(array_map(
            static fn ($value): int => max(0, (int) $value),
            isset($item['combi_ids']) && is_array($item['combi_ids']) ? $item['combi_ids'] : array()
        ), static fn (int $value): bool => $value > 0));

        return [
            'product_id' => $productId,
            'date'       => $date,
            'time'       => $time,
            'date_end'   => substr($endIso, 0, 10),
            'time_end'   => $timeEnd,
            'unit_price' => $this->resolvePublicIntentUnitPrice($productId, $participants),
            'meta'       => [
                'public_booking_intent' => [
                    'date'                   => $date,
                    'start'                  => $startIso,
                    'end'                    => $endIso,
                    'resource_preference_id' => max(0, (int) ($item['resource_preference_id'] ?? 0)),
                    'combi_ids'              => $combiIds,
                    'addons'                 => isset($item['addons']) && is_array($item['addons']) ? $item['addons'] : array(),
                    'mode'                   => isset($item['mode']) ? (string) $item['mode'] : '',
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function resolvePublicIntentDate(array $item): string
    {
        $date = trim((string) ($item['date'] ?? ''));
        if ($date !== '') {
            return $this->sanitizeDate($date);
        }

        $start = trim((string) ($item['start'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}T/', $start) === 1) {
            return $this->sanitizeDate(substr($start, 0, 10));
        }

        throw new InvalidArgumentException('Booking item date is required.');
    }

    private function resolvePublicIntentIso(string $date, string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException(sprintf('Booking item %s time is required.', $field));
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?$/', $value) === 1) {
            return strlen($value) === 16 ? $value . ':00' : $value;
        }

        $time = $this->sanitizeTime($value);
        if (preg_match('/^\d{2}:\d{2}$/', $time) === 1) {
            return sprintf('%sT%s:00', $date, $time);
        }
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time) === 1) {
            return sprintf('%sT%s', $date, $time);
        }

        throw new InvalidArgumentException(sprintf('Booking item %s time must be a valid ISO or HH:MM value.', $field));
    }

    private function extractTimePart(string $iso): string
    {
        if (preg_match('/T(\d{2}:\d{2})(?::\d{2})?$/', $iso, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }

    private function resolvePublicIntentUnitPrice(int $productId, int $participants): float
    {
        if (class_exists('\\SBDP\\Pricing\\PricingService') && method_exists('\\SBDP\\Pricing\\PricingService', 'instance')) {
            try {
                $quote = \SBDP\Pricing\PricingService::instance()->quote(
                    $productId,
                    max(1, $participants),
                    [
                        'channel' => 'public_rest_booking',
                        'source'  => 'public_rest_booking',
                    ]
                );

                if (is_array($quote)) {
                    if (isset($quote['display_unit_price']) && is_numeric($quote['display_unit_price'])) {
                        return round((float) $quote['display_unit_price'], 2);
                    }
                    if (isset($quote['unit_price']) && is_numeric($quote['unit_price'])) {
                        return round((float) $quote['unit_price'], 2);
                    }
                    if (isset($quote['display_total']) && is_numeric($quote['display_total']) && $participants > 0) {
                        return round(((float) $quote['display_total']) / max(1, $participants), 2);
                    }
                    if (isset($quote['total']) && is_numeric($quote['total']) && $participants > 0) {
                        return round(((float) $quote['total']) / max(1, $participants), 2);
                    }
                }
            } catch (Throwable) {
                // Fall through to Woo price or zero placeholder.
            }
        }

        if (function_exists('wc_get_product')) {
            $product = wc_get_product($productId);
            if (is_object($product) && method_exists($product, 'get_price')) {
                $raw = (float) $product->get_price();
                if ($raw > 0.0) {
                    return round($raw, 2);
                }
            }
        }

        return 0.0;
    }

    private function resolvePublicCurrency(): string
    {
        if (function_exists('get_option')) {
            $currency = (string) get_option('woocommerce_currency', self::DEFAULT_CURRENCY);
            if ($currency !== '') {
                return $currency;
            }
        }

        return self::DEFAULT_CURRENCY;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectPlannerResources(): array
    {
        if (function_exists('get_posts')) {
            try {
                return array_map(
                    static fn (CityGuideProfile $profile): array => [
                        'id'   => $profile->id,
                        'name' => $profile->name,
                    ],
                    $this->profiles->all()
                );
            } catch (Throwable) {
                return [];
            }
        }

        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractVendorAvailability(int $vendorId): array
    {
        if (! class_exists(VendorService::class)) {
            return [];
        }

        try {
            $resources = VendorService::getResources($vendorId);
            if (! is_array($resources)) {
                return [];
            }

            return array_map(
                static fn (array $resource): array => [
                    'id'           => (int) ($resource['id'] ?? 0),
                    'title'        => (string) ($resource['title'] ?? ''),
                    'availability' => $resource['availability'] ?? [],
                ],
                $resources
            );
        } catch (Throwable $exception) {
            CoreServiceProvider::logger()->log(
                sprintf('Vendor availability lookup failed for #%d: %s', $vendorId, $exception->getMessage())
            );
        }

        return [];
    }

    /**
     * @param array<string, mixed> $payload
     * @param mixed $providedContext
     * @return array<string, mixed>
     */
    private function requireCreateWriteContext(array $payload, $providedContext): array
    {
        if (! is_array($providedContext)) {
            throw new InvalidArgumentException('Canonical booking truth context is required for booking creation.');
        }

        $expected = $this->bookingTruthRuntime->resolveBookingWriteContext(
            $payload,
            array(
                'validation_source' => (string) ($providedContext['validation_source'] ?? 'booking_manager_create'),
                'resource_id'       => (int) ($providedContext['resource_id'] ?? 0),
            )
        );

        if (! $this->bookingTruthRuntime->writeContextMatches($expected, $providedContext)) {
            throw new InvalidArgumentException('Canonical booking truth context is stale or incomplete for booking creation.');
        }

        if (($expected['booking_capability'] ?? BookingTruthRuntimeService::CAPABILITY_STATUS_UNAVAILABLE) === BookingTruthRuntimeService::CAPABILITY_STATUS_UNAVAILABLE) {
            throw new InvalidArgumentException('Canonical booking truth rejected the requested booking selection.');
        }

        return $expected;
    }

    /**
     * @param array<string, mixed> $payload
     * @param mixed $providedContext
     * @return array<string, mixed>
     */
    private function requireMutationWriteContext(string $action, array $payload, $providedContext): array
    {
        if (! is_array($providedContext)) {
            throw new InvalidArgumentException('Canonical booking truth context is required for booking mutation.');
        }

        $expected = $this->bookingTruthRuntime->resolveBookingWriteContext(
            $payload,
            array(
                'validation_source' => (string) ($providedContext['validation_source'] ?? ('booking_manager_' . $action)),
                'resource_id'       => (int) ($providedContext['resource_id'] ?? 0),
            )
        );

        if (! $this->bookingTruthRuntime->writeContextMatches($expected, $providedContext)) {
            throw new InvalidArgumentException('Canonical booking truth context is stale or incomplete for booking mutation.');
        }

        if (($expected['booking_capability'] ?? BookingTruthRuntimeService::CAPABILITY_STATUS_UNAVAILABLE) === BookingTruthRuntimeService::CAPABILITY_STATUS_UNAVAILABLE) {
            throw new InvalidArgumentException('Canonical booking truth rejected the requested booking mutation.');
        }

        return $expected;
    }

    /**
     * @param array<string, mixed> $booking
     * @param array<string, mixed> $truthContext
     */
    private function syncWooBookingTruthMetaIfOrderExists(array $booking, array $truthContext): void
    {
        if (! function_exists('wc_get_order') || ! function_exists('wc_update_order_item_meta')) {
            return;
        }

        $orderId = isset($booking['order']) && is_array($booking['order']) && isset($booking['order']['id'])
            ? (int) $booking['order']['id']
            : 0;
        if ($orderId <= 0) {
            return;
        }

        $order = wc_get_order($orderId);
        if (! is_object($order) || ! method_exists($order, 'get_items')) {
            return;
        }

        $items = isset($truthContext['items']) && is_array($truthContext['items']) ? array_values($truthContext['items']) : array();
        $itemsByProduct = array();
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId > 0 && ! isset($itemsByProduct[$productId])) {
                $itemsByProduct[$productId] = $item;
            }
        }

        foreach ($order->get_items() as $item) {
            if (! is_object($item) || ! method_exists($item, 'get_id') || ! method_exists($item, 'get_product_id')) {
                continue;
            }

            $productId = (int) $item->get_product_id();
            $resolved = $itemsByProduct[$productId] ?? null;
            if (! is_array($resolved)) {
                continue;
            }

            $meta = isset($resolved['canonical_meta']) && is_array($resolved['canonical_meta'])
                ? $resolved['canonical_meta']
                : array();
            wc_update_order_item_meta($item->get_id(), 'sbdp_start', (string) ($meta['sbdp_start'] ?? ''));
            wc_update_order_item_meta($item->get_id(), 'sbdp_end', (string) ($meta['sbdp_end'] ?? ''));
            wc_update_order_item_meta($item->get_id(), 'sbdp_participants', (int) ($meta['sbdp_participants'] ?? 0));
            wc_update_order_item_meta($item->get_id(), 'sbdp_canonical_participants', (int) ($meta['sbdp_canonical_participants'] ?? 0));
            wc_update_order_item_meta($item->get_id(), 'sbdp_resource_id', (int) ($meta['sbdp_resource_id'] ?? 0));
            wc_update_order_item_meta($item->get_id(), 'sbdp_route_intent', (string) ($meta['sbdp_route_intent'] ?? 'blocked'));
            wc_update_order_item_meta($item->get_id(), 'sbdp_booking_capability', (string) ($meta['sbdp_booking_capability'] ?? BookingTruthRuntimeService::CAPABILITY_STATUS_UNAVAILABLE));
        }
    }
}
