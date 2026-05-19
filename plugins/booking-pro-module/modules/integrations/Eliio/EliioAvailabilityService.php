<?php

declare(strict_types=1);

namespace BSP\Integrations\Eliio;

use DateTimeImmutable;
use DateTimeZone;
use WP_Error;

final class EliioAvailabilityService
{
    private const CACHE_TTL = 60;

    private EliioAvailabilityClient $client;

    public function __construct(?EliioAvailabilityClient $client = null)
    {
        $this->client = $client ?? new EliioAvailabilityClient();
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function check(int $productId, string $date, int $participants, string $startTime = '')
    {
        $validation = $this->validate($productId, $date, $participants, $startTime);
        if ($validation instanceof WP_Error) {
            return $validation;
        }

        $mapping = $this->readMapping($productId);
        if (! $this->mappingIsComplete($mapping)) {
            return $this->buildResponse(
                $productId,
                $mapping,
                'unknown',
                $participants,
                $date,
                array(),
                __('Beschikbaarheid kan nog niet live gecontroleerd worden.', 'sbdp')
            );
        }

        $cacheKey = $this->cacheKey($productId, $date, $participants, $startTime, $mapping);
        $cached = function_exists('get_transient') ? get_transient($cacheKey) : false;
        if (is_array($cached)) {
            return $cached;
        }

        $payload = $this->client->fetchAvailability(
            array(
                'productId'    => (string) $mapping['product_id'],
                'resourceId'   => (string) $mapping['resource_id'],
                'branchId'     => (string) $mapping['branch_id'],
                'bookingDate'  => $date,
                'participants' => $participants,
            )
        );

        if ($payload instanceof WP_Error || (function_exists('is_wp_error') && is_wp_error($payload))) {
            $response = $this->buildResponse(
                $productId,
                $mapping,
                'error',
                $participants,
                $date,
                array(),
                __('Beschikbaarheid kan nu niet live gecontroleerd worden. Wij controleren dit handmatig.', 'sbdp')
            );
            $this->storeCache($cacheKey, $response);

            return $response;
        }

        $slots = $this->extractSlots(is_array($payload) ? $payload : array(), $startTime);
        $status = $this->resolveStatus($slots);
        $message = $status === 'available'
            ? __('Beschikbaarheidscheck geslaagd. Definitieve bevestiging volgt via de aanbieder.', 'sbdp')
            : __('Niet beschikbaar voor dit aantal personen.', 'sbdp');

        $response = $this->buildResponse($productId, $mapping, $status, $participants, $date, $slots, $message);
        $this->storeCache($cacheKey, $response);

        return $response;
    }

    public function validate(int $productId, string $date, int $participants, string $startTime = ''): ?WP_Error
    {
        if ($productId <= 0) {
            return new WP_Error('ddb_eliio_invalid_product', __('Ongeldig product.', 'sbdp'), array('status' => 400));
        }

        if (function_exists('get_post')) {
            $post = get_post($productId);
            if (! $post || (isset($post->post_type) && $post->post_type !== 'product')) {
                return new WP_Error('ddb_eliio_invalid_product', __('Ongeldig product.', 'sbdp'), array('status' => 400));
            }
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return new WP_Error('ddb_eliio_invalid_date', __('Ongeldige datum.', 'sbdp'), array('status' => 400));
        }

        if ($date < $this->today()) {
            return new WP_Error('ddb_eliio_past_date', __('Datum ligt in het verleden.', 'sbdp'), array('status' => 400));
        }

        if ($participants < 1) {
            return new WP_Error('ddb_eliio_invalid_participants', __('Ongeldig aantal deelnemers.', 'sbdp'), array('status' => 400));
        }

        if ($startTime !== '' && ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $startTime)) {
            return new WP_Error('ddb_eliio_invalid_start_time', __('Ongeldige starttijd.', 'sbdp'), array('status' => 400));
        }

        return null;
    }

    /**
     * @return array{provider:string,company_id:string,product_id:string,branch_id:string,resource_id:string,duration_id:string,direct_booking:string,confirmation_required:string,availability_mode:string}
     */
    private function readMapping(int $productId): array
    {
        return array(
            'provider'              => $this->readMeta($productId, '_ddb_supplier_provider'),
            'company_id'            => $this->readMeta($productId, '_ddb_eliio_company_id'),
            'product_id'            => $this->readMeta($productId, '_ddb_eliio_product_id'),
            'branch_id'             => $this->readMeta($productId, '_ddb_eliio_branch_id'),
            'resource_id'           => $this->readMeta($productId, '_ddb_eliio_resource_id'),
            'duration_id'           => $this->readMeta($productId, '_ddb_eliio_duration_id'),
            'direct_booking'        => $this->readMeta($productId, '_ddb_supplier_direct_booking'),
            'confirmation_required' => $this->readMeta($productId, '_ddb_supplier_confirmation_required'),
            'availability_mode'     => $this->readMeta($productId, '_ddb_supplier_availability_mode'),
        );
    }

    private function readMeta(int $productId, string $key): string
    {
        if (! function_exists('get_post_meta')) {
            return '';
        }

        $value = get_post_meta($productId, $key, true);
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param array<string, string> $mapping
     */
    private function mappingIsComplete(array $mapping): bool
    {
        return strtolower($mapping['provider'] ?? '') === 'eliio'
            && ($mapping['product_id'] ?? '') !== ''
            && ($mapping['branch_id'] ?? '') !== ''
            && ($mapping['resource_id'] ?? '') !== '';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array{startTime:string,endTime:string,available:bool}>
     */
    private function extractSlots(array $payload, string $startTime = ''): array
    {
        $rawSlots = $this->findSlotList($payload);
        $slots = array();

        foreach ($rawSlots as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $slot = $this->normalizeSlot($raw);
            if ($slot === null) {
                continue;
            }

            if ($startTime !== '' && $slot['startTime'] !== $startTime) {
                continue;
            }

            $slots[] = $slot;
        }

        return $slots;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, mixed>
     */
    private function findSlotList(array $payload): array
    {
        if ($this->isList($payload)) {
            return $payload;
        }

        foreach (array('data', 'slots', 'availability', 'availabilities', 'timeSlots', 'items') as $key) {
            if (! isset($payload[$key]) || ! is_array($payload[$key])) {
                continue;
            }

            $candidate = $payload[$key];
            if ($this->isList($candidate)) {
                return $candidate;
            }

            $nested = $this->findSlotList($candidate);
            if ($nested !== array()) {
                return $nested;
            }
        }

        return array();
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{startTime:string,endTime:string,available:bool}|null
     */
    private function normalizeSlot(array $raw): ?array
    {
        $source = isset($raw['slot']) && is_array($raw['slot']) ? array_merge($raw['slot'], $raw) : $raw;
        $start = $this->extractTime($source, array('startTime', 'start_time', 'start', 'time', 'from'));
        $end = $this->extractTime($source, array('endTime', 'end_time', 'end', 'until', 'to'));

        if ($start === '') {
            return null;
        }

        return array(
            'startTime' => $start,
            'endTime'   => $end,
            'available' => $this->extractAvailable($source),
        );
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string>   $keys
     */
    private function extractTime(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            if (! isset($source[$key]) || ! is_scalar($source[$key])) {
                continue;
            }

            $value = (string) $source[$key];
            if (preg_match('/(\d{2}:\d{2})/', $value, $matches)) {
                return $matches[1];
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $source
     */
    private function extractAvailable(array $source): bool
    {
        foreach (array('available', 'isAvailable', 'is_available') as $key) {
            if (array_key_exists($key, $source)) {
                return filter_var($source[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return false;
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private function isList(array $value): bool
    {
        if ($value === array()) {
            return false;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * @param array<int, array{startTime:string,endTime:string,available:bool}> $slots
     */
    private function resolveStatus(array $slots): string
    {
        if ($slots === array()) {
            return 'unavailable';
        }

        foreach ($slots as $slot) {
            if (! empty($slot['available'])) {
                return 'available';
            }
        }

        return 'unavailable';
    }

    /**
     * @param array<string, string> $mapping
     * @param array<int, array{startTime:string,endTime:string,available:bool}> $slots
     * @return array<string, mixed>
     */
    private function buildResponse(
        int $productId,
        array $mapping,
        string $status,
        int $participants,
        string $date,
        array $slots,
        string $message
    ): array {
        return array(
            'supplier'                     => 'eliio',
            'productId'                    => $productId,
            'externalProductId'            => (string) ($mapping['product_id'] ?? ''),
            'externalBranchId'             => (string) ($mapping['branch_id'] ?? ''),
            'externalResourceId'           => (string) ($mapping['resource_id'] ?? ''),
            'status'                       => $status,
            'directBookable'               => false,
            'supplierConfirmationRequired' => true,
            'checkedAt'                    => $this->nowIso(),
            'participants'                 => $participants,
            'date'                         => $date,
            'slots'                        => array_values($slots),
            'message'                      => $message,
        );
    }

    /**
     * @param array<string, string> $mapping
     */
    private function cacheKey(int $productId, string $date, int $participants, string $startTime, array $mapping): string
    {
        return 'ddb_eliio_av_' . md5(
            implode(
                '|',
                array(
                    (string) $productId,
                    $date,
                    (string) $participants,
                    $startTime,
                    (string) ($mapping['product_id'] ?? ''),
                    (string) ($mapping['branch_id'] ?? ''),
                    (string) ($mapping['resource_id'] ?? ''),
                    (string) ($mapping['duration_id'] ?? ''),
                )
            )
        );
    }

    /**
     * @param array<string, mixed> $response
     */
    private function storeCache(string $cacheKey, array $response): void
    {
        if (function_exists('set_transient')) {
            set_transient($cacheKey, $response, self::CACHE_TTL);
        }
    }

    private function today(): string
    {
        if (function_exists('current_time')) {
            return substr((string) current_time('mysql'), 0, 10);
        }

        return $this->localNow()->format('Y-m-d');
    }

    private function nowIso(): string
    {
        return $this->localNow()->format(DATE_ATOM);
    }

    private function localNow(): DateTimeImmutable
    {
        $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        return new DateTimeImmutable('now', $timezone instanceof DateTimeZone ? $timezone : new DateTimeZone('UTC'));
    }
}
