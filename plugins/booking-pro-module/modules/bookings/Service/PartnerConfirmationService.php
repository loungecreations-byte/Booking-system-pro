<?php

declare(strict_types=1);

namespace BSP\Bookings\Service;

use BSP\Core\CoreServiceProvider;
use InvalidArgumentException;
use Throwable;
use wpdb;

use function array_column;
use function array_fill;
use function array_filter;
use function array_map;
use function current_time;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function sprintf;

final class PartnerConfirmationService
{
    /**
     * @param array<string, mixed> $master
     * @param array<string, mixed> $booking
     * @param array<int, array<string, mixed>> $legs
     */
    public function syncForMaster(int $masterId, array $master, array $booking, array $legs): void
    {
        unset($booking);

        try {
            global $wpdb;
            if (! $wpdb instanceof wpdb) {
                return;
            }

            $table = $wpdb->prefix . 'bsp_partner_confirmations';
            $legTable = $wpdb->prefix . 'bsp_booking_legs';
            $demands = $this->buildDemands($masterId, $master, $legs);
            $this->deleteStaleRows($wpdb, $table, $masterId, array_column($demands, 'leg_key'));

            foreach ($demands as $demand) {
                $existing = $this->findExistingRow($wpdb, $table, $masterId, (string) $demand['leg_key']);
                $status = $this->resolveStatus($existing, $demand);
                $row = $demand;
                $row['status'] = $status;

                if ($existing !== null && in_array($status, ['confirmed', 'declined', 'alternative_proposed'], true)) {
                    $row['responded_at'] = isset($existing['responded_at']) && is_string($existing['responded_at']) && $existing['responded_at'] !== ''
                        ? $existing['responded_at']
                        : current_time('mysql', true);
                }

                if ($existing !== null && $status === 'confirmed') {
                    $row['confirmed_at'] = isset($existing['confirmed_at']) && is_string($existing['confirmed_at']) && $existing['confirmed_at'] !== ''
                        ? $existing['confirmed_at']
                        : current_time('mysql', true);
                }

                $this->upsertRow($wpdb, $table, $existing, $row);
                $wpdb->update(
                    $legTable,
                    ['status' => $status],
                    ['master_id' => $masterId, 'leg_key' => (string) $demand['leg_key']]
                );
            }
        } catch (Throwable $exception) {
            CoreServiceProvider::logger()->log(
                sprintf('Partner confirmation sync failed for master #%d: %s', $masterId, $exception->getMessage())
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function respond(int $vendorId, string $legKey, string $action, string $note = ''): array
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            throw new InvalidArgumentException('Database connection unavailable.');
        }

        $vendorId = max(0, $vendorId);
        $legKey = trim($legKey);
        $action = strtolower(trim($action));
        $note = trim($note);

        if ($vendorId <= 0 || $legKey === '') {
            throw new InvalidArgumentException('Confirmation target ontbreekt.');
        }

        $status = match ($action) {
            'confirm' => 'confirmed',
            'decline' => 'declined',
            'alternative' => 'alternative_proposed',
            default => '',
        };

        if ($status === '') {
            throw new InvalidArgumentException('Ongeldige partneractie.');
        }

        if (($action === 'decline' || $action === 'alternative') && $note === '') {
            throw new InvalidArgumentException('Een toelichting is verplicht voor deze partneractie.');
        }

        $table = $wpdb->prefix . 'bsp_partner_confirmations';
        $legTable = $wpdb->prefix . 'bsp_booking_legs';
        $eventTable = $wpdb->prefix . 'bsp_booking_events';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE supplier_id = %d AND leg_key = %s LIMIT 1",
                $vendorId,
                $legKey
            ),
            ARRAY_A
        );

        if (! is_array($row)) {
            throw new InvalidArgumentException('Partnerbevestiging niet gevonden.');
        }

        $payload = json_decode((string) ($row['payload'] ?? ''), true);
        if (! is_array($payload)) {
            $payload = [];
        }
        $previousResponse = is_array($payload['partner_response'] ?? null) ? $payload['partner_response'] : array();
        if ((string) ($row['status'] ?? '') === $status
            && (string) ($previousResponse['action'] ?? '') === $action
            && (string) ($previousResponse['note'] ?? '') === $note) {
            return [
                'booking_reference' => (string) ($row['booking_reference'] ?? ''),
                'leg_key' => $legKey,
                'status' => $status,
                'scheduled_date' => (string) ($row['scheduled_date'] ?? ''),
                'scheduled_time' => (string) ($row['scheduled_time'] ?? ''),
                'scheduled_end_time' => (string) ($row['scheduled_end_time'] ?? ''),
                'participants' => (int) ($row['participants'] ?? 0),
                'note' => $note,
                'responded_at' => (string) ($row['responded_at'] ?? ''),
                'confirmed_at' => (string) ($row['confirmed_at'] ?? ''),
                'title' => isset($payload['title']) ? (string) $payload['title'] : '',
                'customer_name' => isset($payload['customer_name']) ? (string) $payload['customer_name'] : '',
                'customer_email' => isset($payload['customer_email']) ? (string) $payload['customer_email'] : '',
                'leg_type' => isset($payload['leg']['leg_type']) ? (string) $payload['leg']['leg_type'] : '',
                'idempotent_replay' => true,
            ];
        }
        $payload['partner_response'] = [
            'action' => $action,
            'note' => $note,
            'responded_at' => current_time('mysql', true),
        ];

        $update = [
            'status' => $status,
            'responded_at' => current_time('mysql', true),
            'payload' => $this->encodeJson($payload),
        ];

        if ($status === 'confirmed') {
            $update['confirmed_at'] = current_time('mysql', true);
        }

        $updated = $wpdb->update($table, $update, ['id' => (int) $row['id']]);
        if ($updated === false) {
            throw new InvalidArgumentException('Partnerbevestiging kon niet worden bijgewerkt.');
        }

        $wpdb->update(
            $legTable,
            ['status' => $status],
            ['master_id' => (int) ($row['master_id'] ?? 0), 'leg_key' => $legKey]
        );

        $wpdb->insert(
            $eventTable,
            [
                'master_id' => (int) ($row['master_id'] ?? 0),
                'leg_id' => isset($row['leg_id']) ? (int) $row['leg_id'] : null,
                'booking_reference' => (string) ($row['booking_reference'] ?? ''),
                'woo_order_id' => null,
                'event_type' => 'partner_confirmation_response',
                'payload' => $this->encodeJson([
                    'supplier_id' => $vendorId,
                    'leg_key' => $legKey,
                    'status' => $status,
                    'note' => $note,
                ]),
            ]
        );

        return [
            'booking_reference' => (string) ($row['booking_reference'] ?? ''),
            'leg_key' => $legKey,
            'status' => $status,
            'scheduled_date' => (string) ($row['scheduled_date'] ?? ''),
            'scheduled_time' => (string) ($row['scheduled_time'] ?? ''),
            'scheduled_end_time' => (string) ($row['scheduled_end_time'] ?? ''),
            'participants' => (int) ($row['participants'] ?? 0),
            'note' => $note,
            'responded_at' => (string) ($update['responded_at'] ?? ''),
            'confirmed_at' => isset($update['confirmed_at']) ? (string) $update['confirmed_at'] : (string) ($row['confirmed_at'] ?? ''),
            'title' => isset($payload['title']) ? (string) $payload['title'] : '',
            'customer_name' => isset($payload['customer_name']) ? (string) $payload['customer_name'] : '',
            'customer_email' => isset($payload['customer_email']) ? (string) $payload['customer_email'] : '',
            'leg_type' => isset($payload['leg']['leg_type']) ? (string) $payload['leg']['leg_type'] : '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function replaceWithFallback(string $bookingReference, string $legKey, ?int $preferredSupplierId = null): array
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            throw new InvalidArgumentException('Database connection unavailable.');
        }

        $bookingReference = trim($bookingReference);
        $legKey = trim($legKey);
        $preferredSupplierId = $preferredSupplierId !== null ? max(0, $preferredSupplierId) : null;

        if ($bookingReference === '' || $legKey === '') {
            throw new InvalidArgumentException('Fallback target ontbreekt.');
        }

        $table = $wpdb->prefix . 'bsp_partner_confirmations';
        $legTable = $wpdb->prefix . 'bsp_booking_legs';
        $eventTable = $wpdb->prefix . 'bsp_booking_events';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE booking_reference = %s AND leg_key = %s LIMIT 1",
                $bookingReference,
                $legKey
            ),
            ARRAY_A
        );

        if (! is_array($row)) {
            throw new InvalidArgumentException('Partnerbevestiging niet gevonden.');
        }

        $payload = json_decode((string) ($row['payload'] ?? ''), true);
        if (! is_array($payload)) {
            $payload = [];
        }

        $fallbackSupplierIds = $this->extractFallbackSupplierIds($payload);
        $currentSupplierId = isset($row['supplier_id']) ? (int) $row['supplier_id'] : 0;
        $fallbackSupplierIds = array_values(array_filter(
            $fallbackSupplierIds,
            static fn (int $supplierId): bool => $supplierId > 0 && $supplierId !== $currentSupplierId
        ));

        if ($fallbackSupplierIds === []) {
            throw new InvalidArgumentException('Geen fallback-partners beschikbaar voor deze stop.');
        }

        $nextSupplierId = $preferredSupplierId !== null && in_array($preferredSupplierId, $fallbackSupplierIds, true)
            ? $preferredSupplierId
            : (int) $fallbackSupplierIds[0];

        $history = isset($payload['replacement_history']) && is_array($payload['replacement_history'])
            ? $payload['replacement_history']
            : [];
        $history[] = [
            'from_supplier_id' => $currentSupplierId,
            'to_supplier_id' => $nextSupplierId,
            'replaced_at' => current_time('mysql', true),
            'previous_status' => (string) ($row['status'] ?? ''),
            'partner_response' => isset($payload['partner_response']) && is_array($payload['partner_response'])
                ? $payload['partner_response']
                : null,
        ];

        unset($payload['partner_response']);
        $payload['replacement_history'] = $history;
        $payload['active_supplier_id'] = $nextSupplierId;

        $updated = $wpdb->update(
            $table,
            [
                'supplier_id' => $nextSupplierId,
                'status' => 'awaiting_partner',
                'responded_at' => null,
                'confirmed_at' => null,
                'payload' => $this->encodeJson($payload),
            ],
            ['id' => (int) $row['id']]
        );

        if ($updated === false) {
            throw new InvalidArgumentException('Fallback-partner kon niet worden toegepast.');
        }

        $wpdb->update(
            $legTable,
            [
                'supplier_id' => $nextSupplierId,
                'status' => 'awaiting_partner',
            ],
            ['master_id' => (int) ($row['master_id'] ?? 0), 'leg_key' => $legKey]
        );

        $wpdb->insert(
            $eventTable,
            [
                'master_id' => (int) ($row['master_id'] ?? 0),
                'leg_id' => isset($row['leg_id']) ? (int) $row['leg_id'] : null,
                'booking_reference' => $bookingReference,
                'woo_order_id' => null,
                'event_type' => 'partner_confirmation_replaced',
                'payload' => $this->encodeJson([
                    'leg_key' => $legKey,
                    'from_supplier_id' => $currentSupplierId,
                    'to_supplier_id' => $nextSupplierId,
                ]),
            ]
        );

        return [
            'booking_reference' => $bookingReference,
            'leg_key' => $legKey,
            'previous_supplier_id' => $currentSupplierId,
            'supplier_id' => $nextSupplierId,
            'status' => 'awaiting_partner',
            'fallback_supplier_ids' => $fallbackSupplierIds,
        ];
    }

    /**
     * @param array<string, mixed> $master
     * @param array<int, array<string, mixed>> $legs
     * @return array<int, array<string, mixed>>
     */
    private function buildDemands(int $masterId, array $master, array $legs): array
    {
        $demands = [];

        foreach ($legs as $leg) {
            if (! is_array($leg) || (string) ($leg['leg_type'] ?? '') !== 'restaurant_stop') {
                continue;
            }

            $demands[] = [
                'master_id'         => $masterId,
                'leg_id'            => isset($leg['id']) ? (int) $leg['id'] : null,
                'leg_key'           => (string) ($leg['leg_key'] ?? ''),
                'booking_reference' => (string) ($master['booking_reference'] ?? ''),
                'supplier_id'       => isset($leg['supplier_id']) ? (int) $leg['supplier_id'] : null,
                'scheduled_date'    => isset($leg['scheduled_date']) ? (string) $leg['scheduled_date'] : null,
                'scheduled_time'    => (string) ($leg['scheduled_time'] ?? ''),
                'scheduled_end_time'=> (string) ($leg['scheduled_end_time'] ?? ''),
                'participants'      => max(0, (int) ($leg['participants'] ?? 0)),
                'responded_at'      => null,
                'confirmed_at'      => null,
                'payload'           => $this->encodeJson([
                    'title'        => (string) ($leg['title'] ?? ''),
                    'booking_type' => (string) ($master['booking_type'] ?? ''),
                    'customer_name'=> (string) ($master['customer_name'] ?? ''),
                    'customer_email'=> (string) ($master['customer_email'] ?? ''),
                    'fallback_supplier_ids' => $this->extractFallbackSupplierIdsFromLeg($leg),
                    'leg'          => $leg,
                ]),
            ];
        }

        return $demands;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findExistingRow(wpdb $wpdb, string $table, int $masterId, string $legKey): ?array
    {
        if ($legKey === '') {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE master_id = %d AND leg_key = %s LIMIT 1",
                $masterId,
                $legKey
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed>|null $existing
     * @param array<string, mixed> $demand
     */
    private function resolveStatus(?array $existing, array $demand): string
    {
        if ($existing !== null) {
            $status = strtolower(trim((string) ($existing['status'] ?? '')));
            if (in_array($status, ['confirmed', 'declined', 'alternative_proposed', 'replaced'], true)) {
                return $status;
            }
        }

        return isset($demand['supplier_id']) && (int) $demand['supplier_id'] > 0
            ? 'awaiting_partner'
            : 'draft';
    }

    /**
     * @param array<string, mixed>|null $existing
     * @param array<string, mixed> $row
     */
    private function upsertRow(wpdb $wpdb, string $table, ?array $existing, array $row): void
    {
        if ($existing !== null && isset($existing['id'])) {
            $wpdb->update($table, $row, ['id' => (int) $existing['id']]);
            return;
        }

        $wpdb->insert($table, $row);
    }

    /**
     * @param array<int, string> $activeLegKeys
     */
    private function deleteStaleRows(wpdb $wpdb, string $table, int $masterId, array $activeLegKeys): void
    {
        $activeLegKeys = array_values(array_filter(array_map('strval', $activeLegKeys), static fn (string $value): bool => $value !== ''));
        if ($activeLegKeys === []) {
            $wpdb->delete($table, ['master_id' => $masterId], ['%d']);
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($activeLegKeys), '%s'));
        $params = array_merge([$masterId], $activeLegKeys);

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE master_id = %d AND leg_key NOT IN ({$placeholders})",
                $params
            )
        );
    }

    /**
     * @param array<string, mixed> $leg
     * @return array<int, int>
     */
    private function extractFallbackSupplierIdsFromLeg(array $leg): array
    {
        $payload = [];
        if (isset($leg['payload']) && is_string($leg['payload']) && $leg['payload'] !== '') {
            $decoded = json_decode($leg['payload'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $resolvedLeg = isset($payload['resolved_leg']) && is_array($payload['resolved_leg']) ? $payload['resolved_leg'] : [];
        $segment = isset($resolvedLeg['segment']) && is_array($resolvedLeg['segment']) ? $resolvedLeg['segment'] : [];
        $rules = isset($segment['rules']) && is_array($segment['rules']) ? $segment['rules'] : [];

        $candidates = [];
        foreach ([
            $segment['fallback_supplier_ids'] ?? null,
            $segment['fallback_vendor_ids'] ?? null,
            $rules['fallback_supplier_ids'] ?? null,
            $rules['fallback_vendor_ids'] ?? null,
        ] as $value) {
            if (is_array($value)) {
                $candidates = array_merge($candidates, array_map('intval', $value));
            }
        }

        $candidates = array_values(array_unique(array_filter($candidates, static fn (int $id): bool => $id > 0)));
        sort($candidates);

        return $candidates;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, int>
     */
    private function extractFallbackSupplierIds(array $payload): array
    {
        $candidates = [];

        foreach ([
            $payload['fallback_supplier_ids'] ?? null,
            $payload['fallback_vendor_ids'] ?? null,
        ] as $value) {
            if (is_array($value)) {
                $candidates = array_merge($candidates, array_map('intval', $value));
            }
        }

        if ($candidates === [] && isset($payload['leg']) && is_array($payload['leg'])) {
            $candidates = $this->extractFallbackSupplierIdsFromLeg($payload['leg']);
        }

        $candidates = array_values(array_unique(array_filter($candidates, static fn (int $id): bool => $id > 0)));
        sort($candidates);

        return $candidates;
    }

    /**
     * @param mixed $value
     */
    private function encodeJson($value): string
    {
        if (function_exists('wp_json_encode')) {
            $encoded = wp_json_encode($value);
        } else {
            $encoded = json_encode($value);
        }

        return is_string($encoded) ? $encoded : '';
    }
}

if (! class_exists('BSPModule\\Bookings\\Service\\PartnerConfirmationService', false)) {
    class_alias(PartnerConfirmationService::class, 'BSPModule\\Bookings\\Service\\PartnerConfirmationService');
}
