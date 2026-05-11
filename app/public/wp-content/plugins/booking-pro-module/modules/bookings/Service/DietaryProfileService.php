<?php

declare(strict_types=1);

namespace BSP\Bookings\Service;

use InvalidArgumentException;
use wpdb;

use function array_count_values;
use function array_filter;
use function array_map;
use function array_values;
use function current_time;
use function in_array;
use function is_array;
use function is_string;
use function sort;
use function strtolower;
use function trim;

final class DietaryProfileService
{
    /**
     * Lookup a booking master by WooCommerce order ID and save dietary profiles.
     *
     * @param array<int, array<string, mixed>> $profiles
     * @return array<string, mixed>
     */
    public function replaceForWooOrderId(int $wooOrderId, array $profiles, string $intakeMode = 'per_guest'): array
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            throw new InvalidArgumentException('Database connection unavailable.');
        }

        if ($wooOrderId <= 0) {
            throw new InvalidArgumentException('Geldig WooCommerce order ID is vereist.');
        }

        $masterTable = $wpdb->prefix . 'bsp_booking_masters';
        $master = $wpdb->get_row(
            $wpdb->prepare("SELECT id, booking_reference FROM {$masterTable} WHERE woo_order_id = %d LIMIT 1", $wooOrderId),
            ARRAY_A
        );

        if (! is_array($master) || empty($master['id'])) {
            throw new InvalidArgumentException('Geen boeking gevonden voor deze bestelling.');
        }

        return $this->replaceForBookingReference((string) $master['booking_reference'], $profiles, $intakeMode);
    }

    /**
     * @param array<int, array<string, mixed>> $profiles
     * @return array<string, mixed>
     */
    public function replaceForBookingReference(string $bookingReference, array $profiles, string $intakeMode = 'per_guest'): array
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            throw new InvalidArgumentException('Database connection unavailable.');
        }

        $bookingReference = trim($bookingReference);
        if ($bookingReference === '') {
            throw new InvalidArgumentException('Booking reference ontbreekt.');
        }

        $masterTable = $wpdb->prefix . 'bsp_booking_masters';
        $master = $wpdb->get_row(
            $wpdb->prepare("SELECT id FROM {$masterTable} WHERE booking_reference = %s LIMIT 1", $bookingReference),
            ARRAY_A
        );

        if (! is_array($master) || empty($master['id'])) {
            throw new InvalidArgumentException('Boeking niet gevonden.');
        }

        $masterId = (int) $master['id'];
        $table = $wpdb->prefix . 'bsp_guest_dietary_profiles';
        $wpdb->delete($table, ['master_id' => $masterId], ['%d']);

        $normalized = [];
        foreach (array_values($profiles) as $index => $profile) {
            if (! is_array($profile)) {
                continue;
            }

            $guestIndex = max(1, (int) ($profile['guest_index'] ?? ($index + 1)));
            $guestName = trim((string) ($profile['guest_name'] ?? ''));
            $menuChoice = trim((string) ($profile['menu_choice'] ?? ''));
            $severity = $this->normalizeSeverity($profile['severity'] ?? null);
            $partnerStatus = $this->normalizePartnerStatus($profile['partner_status'] ?? null);
            $allergenFlags = $this->normalizeAllergenFlags($profile['allergen_flags'] ?? []);
            $notes = trim((string) ($profile['notes'] ?? ''));

            $row = [
                'master_id' => $masterId,
                'booking_reference' => $bookingReference,
                'guest_index' => $guestIndex,
                'guest_name' => $guestName,
                'intake_mode' => $intakeMode === 'group' ? 'group' : 'per_guest',
                'menu_choice' => $menuChoice,
                'allergen_flags' => $this->encodeJson($allergenFlags),
                'severity' => $severity,
                'notes' => $notes !== '' ? $notes : null,
                'partner_status' => $partnerStatus,
                'payload' => $this->encodeJson([
                    'allergen_flags' => $allergenFlags,
                ]),
            ];

            $wpdb->insert($table, $row);
            $normalized[] = [
                'guest_index' => $guestIndex,
                'guest_name' => $guestName,
                'menu_choice' => $menuChoice,
                'allergen_flags' => $allergenFlags,
                'severity' => $severity,
                'partner_status' => $partnerStatus,
                'notes' => $notes,
            ];
        }

        $this->syncPartnerCards($masterId, $bookingReference);

        return [
            'booking_reference' => $bookingReference,
            'profiles' => $normalized,
            'summary' => $this->buildMasterSummary($masterId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildMasterSummary(int $masterId): array
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb || $masterId <= 0) {
            return [];
        }

        $table = $wpdb->prefix . 'bsp_guest_dietary_profiles';
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT menu_choice, allergen_flags, severity, partner_status FROM {$table} WHERE master_id = %d ORDER BY guest_index ASC", $masterId),
            ARRAY_A
        );

        if (! is_array($rows) || $rows === []) {
            return [
                'guest_count' => 0,
                'menu_counts' => [],
                'allergen_flags' => [],
                'highest_severity' => 'none',
                'unresolved' => false,
                'partner_ready' => false,
            ];
        }

        $menuChoices = [];
        $allergens = [];
        $severities = [];
        $partnerStatuses = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $menuChoice = trim((string) ($row['menu_choice'] ?? ''));
            if ($menuChoice !== '') {
                $menuChoices[] = $menuChoice;
            }

            $decodedFlags = json_decode((string) ($row['allergen_flags'] ?? '[]'), true);
            if (is_array($decodedFlags)) {
                foreach ($decodedFlags as $flag) {
                    $flag = strtolower(trim((string) $flag));
                    if ($flag !== '') {
                        $allergens[] = $flag;
                    }
                }
            }

            $severities[] = $this->normalizeSeverity($row['severity'] ?? null);
            $partnerStatuses[] = $this->normalizePartnerStatus($row['partner_status'] ?? null);
        }

        $highestSeverity = 'none';
        foreach (['critical', 'high', 'medium', 'low', 'none'] as $candidate) {
            if (in_array($candidate, $severities, true)) {
                $highestSeverity = $candidate;
                break;
            }
        }

        $unresolved = false;
        if ($highestSeverity !== 'none') {
            foreach ($partnerStatuses as $status) {
                if (! in_array($status, ['confirmed', 'accepted'], true)) {
                    $unresolved = true;
                    break;
                }
            }
        }

        $allergens = array_values(array_unique($allergens));
        sort($allergens);

        return [
            'guest_count' => count($rows),
            'menu_counts' => array_count_values($menuChoices),
            'allergen_flags' => $allergens,
            'highest_severity' => $highestSeverity,
            'unresolved' => $unresolved,
            'partner_ready' => ! $unresolved && count($rows) > 0,
        ];
    }

    public function syncPartnerCards(int $masterId, string $bookingReference): void
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb || $masterId <= 0) {
            return;
        }

        $summary = $this->buildMasterSummary($masterId);
        $table = $wpdb->prefix . 'bsp_partner_confirmations';
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT id, payload FROM {$table} WHERE master_id = %d", $masterId),
            ARRAY_A
        );

        if (! is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['id'])) {
                continue;
            }

            $payload = json_decode((string) ($row['payload'] ?? ''), true);
            if (! is_array($payload)) {
                $payload = [];
            }

            $payload['partner_card'] = [
                'booking_reference' => $bookingReference,
                'guest_count' => (int) ($summary['guest_count'] ?? 0),
                'menu_counts' => isset($summary['menu_counts']) && is_array($summary['menu_counts']) ? $summary['menu_counts'] : [],
                'allergen_flags' => isset($summary['allergen_flags']) && is_array($summary['allergen_flags']) ? $summary['allergen_flags'] : [],
                'highest_severity' => (string) ($summary['highest_severity'] ?? 'none'),
                'allergy_risk_status' => ! empty($summary['unresolved']) ? 'pending_review' : 'cleared',
                'updated_at' => current_time('mysql', true),
            ];

            $wpdb->update(
                $table,
                ['payload' => $this->encodeJson($payload)],
                ['id' => (int) $row['id']]
            );
        }
    }

    /**
     * Allow a partner (vendor) to explicitly accept or reject the allergen requirements
     * for all guests linked to their confirmation leg.
     *
     * $action must be 'accept' or 'reject'.
     *
     * @return array<string, mixed>
     */
    public function respondToAllergen(int $vendorId, string $legKey, string $action, string $note = ''): array
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            throw new InvalidArgumentException('Database connection unavailable.');
        }

        $vendorId = max(0, $vendorId);
        $legKey   = trim($legKey);
        $action   = strtolower(trim($action));
        $note     = trim($note);

        if ($vendorId <= 0 || $legKey === '') {
            throw new InvalidArgumentException('Vendor en leg_key zijn verplicht.');
        }

        $partnerStatus = match ($action) {
            'accept' => 'accepted',
            'reject' => 'rejected',
            default  => '',
        };

        if ($partnerStatus === '') {
            throw new InvalidArgumentException('Ongeldige actie. Gebruik "accept" of "reject".');
        }

        if ($action === 'reject' && $note === '') {
            throw new InvalidArgumentException('Een toelichting is verplicht bij afwijzing.');
        }

        $confirmTable = $wpdb->prefix . 'bsp_partner_confirmations';
        $confirmRow   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT master_id, booking_reference FROM {$confirmTable} WHERE supplier_id = %d AND leg_key = %s LIMIT 1",
                $vendorId,
                $legKey
            ),
            ARRAY_A
        );

        if (! is_array($confirmRow) || empty($confirmRow['master_id'])) {
            throw new InvalidArgumentException('Partnerbevestiging niet gevonden voor deze leg.');
        }

        $masterId         = (int) $confirmRow['master_id'];
        $bookingReference = (string) ($confirmRow['booking_reference'] ?? '');

        $dietaryTable = $wpdb->prefix . 'bsp_guest_dietary_profiles';
        $rows         = $wpdb->get_results(
            $wpdb->prepare("SELECT id FROM {$dietaryTable} WHERE master_id = %d", $masterId),
            ARRAY_A
        );

        if (! is_array($rows) || $rows === []) {
            throw new InvalidArgumentException('Geen dieetwensen gevonden voor deze boeking.');
        }

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$dietaryTable} SET partner_status = %s WHERE id IN ({$placeholders})",
                array_merge([$partnerStatus], $ids)
            )
        );

        $eventTable = $wpdb->prefix . 'bsp_booking_events';
        $wpdb->insert(
            $eventTable,
            [
                'master_id'         => $masterId,
                'leg_id'            => null,
                'booking_reference' => $bookingReference,
                'woo_order_id'      => null,
                'event_type'        => 'partner_allergen_response',
                'payload'           => $this->encodeJson([
                    'supplier_id'   => $vendorId,
                    'leg_key'       => $legKey,
                    'action'        => $action,
                    'partner_status'=> $partnerStatus,
                    'note'          => $note,
                ]),
            ]
        );

        $this->syncPartnerCards($masterId, $bookingReference);

        if ($action === 'reject') {
            $this->notifyAdminOfAllergenRejection($vendorId, $legKey, $bookingReference, $note);
        }

        return [
            'booking_reference' => $bookingReference,
            'leg_key'           => $legKey,
            'partner_status'    => $partnerStatus,
            'note'              => $note,
            'summary'           => $this->buildMasterSummary($masterId),
        ];
    }

    private function notifyAdminOfAllergenRejection(int $vendorId, string $legKey, string $bookingReference, string $note): void
    {
        if (! function_exists('wp_mail') || ! function_exists('get_option')) {
            return;
        }

        $adminEmail = get_option('admin_email');
        if (! $adminEmail) {
            return;
        }

        $subject = sprintf('ATTENTIE: Partner heeft allergie-aanvraag AFGEWEZEN (#%s)', $bookingReference);
        $message = sprintf(
            "Beste beheerder,\n\nEen partner (ID: %d) heeft zojuist aangegeven een allergie-aanvraag NIET te kunnen faciliteren voor boeking: %s (leg: %s).\n\nToelichting partner:\n%s\n\nActie vereist: Neem contact op met de klant of zoek een alternatieve partner.\n\nMet vriendelijke groet,\nDagje Den Bosch",
            $vendorId,
            $bookingReference,
            $legKey,
            $note
        );

        wp_mail($adminEmail, $subject, $message);
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeAllergenFlags($value): array
    {
        $flags = [];
        if (is_array($value)) {
            $flags = array_map(static fn ($item): string => strtolower(trim((string) $item)), $value);
        } elseif (is_string($value) && trim($value) !== '') {
            $flags = array_map(static fn (string $item): string => strtolower(trim($item)), explode(',', $value));
        }

        $flags = array_values(array_unique(array_filter($flags, static fn (string $item): bool => $item !== '')));
        sort($flags);

        return $flags;
    }

    /**
     * @param mixed $value
     */
    private function normalizeSeverity($value): string
    {
        $severity = strtolower(trim((string) $value));

        return in_array($severity, ['none', 'low', 'medium', 'high', 'critical'], true)
            ? $severity
            : 'none';
    }

    /**
     * @param mixed $value
     */
    private function normalizePartnerStatus($value): string
    {
        $status = strtolower(trim((string) $value));

        return in_array($status, ['pending_review', 'submitted', 'accepted', 'confirmed', 'rejected'], true)
            ? $status
            : 'pending_review';
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

if (! class_exists('BSPModule\\Bookings\\Service\\DietaryProfileService', false)) {
    class_alias(DietaryProfileService::class, 'BSPModule\\Bookings\\Service\\DietaryProfileService');
}
