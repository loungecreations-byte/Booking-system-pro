<?php

declare(strict_types=1);

namespace BSP\Bookings\Service;

use BSP\Core\CoreServiceProvider;
use BSP\Planner\Vendor\CityGuideProfile;
use BSP\Planner\Vendor\CityGuideProfileStore;
use Throwable;
use wpdb;

final class GuideAssignmentService
{
    public function __construct(private ?CityGuideProfileStore $store = null)
    {
        $this->store = $store ?? new CityGuideProfileStore();
    }

    /**
     * @param array<string, mixed> $master
     * @param array<string, mixed> $booking
     * @param array<int, array<string, mixed>> $legs
     */
    public function syncForMaster(int $masterId, array $master, array $booking, array $legs): void
    {
        try {
            global $wpdb;
            if (! $wpdb instanceof wpdb) {
                return;
            }

            $this->syncGuideProfiles($wpdb);

            $demand = $this->buildAssignmentDemand($masterId, $master, $booking, $legs);
            if ($demand === null) {
                $this->deleteAssignment($wpdb, $masterId);
                return;
            }

            [$primaryGuideId, $backupGuideId, $scarcityScore] = $this->resolveGuides($wpdb, $demand);

            $row = [
                'master_id'            => $masterId,
                'leg_id'               => $demand['leg_id'],
                'leg_key'              => $demand['leg_key'],
                'booking_reference'    => $demand['booking_reference'],
                'requested_language'   => $demand['requested_language'],
                'status'               => $primaryGuideId > 0 ? 'assigned' : 'needed',
                'primary_guide_id'     => $primaryGuideId > 0 ? $primaryGuideId : null,
                'backup_guide_id'      => $backupGuideId > 0 ? $backupGuideId : null,
                'scheduled_date'       => $demand['scheduled_date'],
                'scheduled_start_time' => $demand['scheduled_start_time'],
                'scheduled_end_time'   => $demand['scheduled_end_time'],
                'scarcity_score'       => $scarcityScore,
                'payload'              => $this->encodeJson(array_merge(
                    $demand,
                    [
                        'primary_city_guide_post_id' => $primaryGuideId,
                        'backup_city_guide_post_id'  => $backupGuideId,
                    ]
                )),
            ];

            $this->upsertAssignment($wpdb, $row);
        } catch (Throwable $exception) {
            CoreServiceProvider::logger()->log(
                sprintf('Guide assignment sync failed for master #%d: %s', $masterId, $exception->getMessage())
            );
        }
    }

    private function syncGuideProfiles(wpdb $wpdb): void
    {
        $profilesTable = $wpdb->prefix . 'bsp_guide_profiles';
        $skillsTable = $wpdb->prefix . 'bsp_guide_skills';

        foreach ($this->store->all() as $profile) {
            if (! $profile instanceof CityGuideProfile || $profile->id <= 0) {
                continue;
            }

            $existingId = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$profilesTable} WHERE city_guide_post_id = %d LIMIT 1",
                    $profile->id
                )
            );

            $row = [
                'city_guide_post_id' => $profile->id,
                'display_name'       => $profile->name,
                'status'             => $profile->status,
                'timezone'           => $profile->timezone !== '' ? $profile->timezone : 'UTC',
                'allow_nl_tours'     => $profile->allowNlTours ? 1 : 0,
                'payload'            => $this->encodeJson([
                    'ical_url'            => $profile->icalUrl,
                    'note'                => $profile->note,
                    'last_sync'           => $profile->lastSync,
                    'languages'           => $profile->languages,
                    'protected_languages' => $profile->protectedLanguages,
                ]),
            ];

            if ($existingId > 0) {
                $wpdb->update($profilesTable, $row, ['id' => $existingId]);
                $profileId = $existingId;
            } else {
                $wpdb->insert($profilesTable, $row);
                $profileId = (int) $wpdb->insert_id;
            }

            $wpdb->delete($skillsTable, ['profile_id' => $profileId], ['%d']);
            foreach ($profile->languages as $language) {
                $language = $this->normalizeLanguage($language);
                if ($language === '') {
                    continue;
                }

                $wpdb->insert(
                    $skillsTable,
                    [
                        'profile_id'     => $profileId,
                        'skill_type'     => 'language',
                        'skill_code'     => $language,
                        'proficiency'    => 5,
                        'protected_pool' => in_array($language, $profile->protectedLanguages, true) ? 1 : 0,
                    ]
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $master
     * @param array<string, mixed> $booking
     * @param array<int, array<string, mixed>> $legs
     * @return array<string, mixed>|null
     */
    private function buildAssignmentDemand(int $masterId, array $master, array $booking, array $legs): ?array
    {
        $guideLeg = null;
        foreach ($legs as $leg) {
            if (! is_array($leg)) {
                continue;
            }

            $legType = strtolower(trim((string) ($leg['leg_type'] ?? '')));
            if (in_array($legType, ['anchor_activity', 'activity'], true)) {
                $guideLeg = $leg;
                break;
            }
        }

        $resourceRef = trim((string) ($master['resource_ref'] ?? ''));
        $requiresGuide = $guideLeg !== null
            || strtolower((string) ($master['booking_type'] ?? '')) === 'walking_dinner'
            || $resourceRef !== '';
        if (! $requiresGuide) {
            return null;
        }

        if ($guideLeg === null && $legs !== []) {
            $guideLeg = $legs[0];
        }

        return [
            'master_id'             => $masterId,
            'leg_id'                => isset($guideLeg['id']) ? (int) $guideLeg['id'] : null,
            'leg_key'               => isset($guideLeg['leg_key']) ? (string) $guideLeg['leg_key'] : '',
            'booking_reference'     => (string) ($master['booking_reference'] ?? ''),
            'requested_language'    => $this->deriveRequestedLanguage($booking),
            'scheduled_date'        => isset($guideLeg['scheduled_date']) ? (string) $guideLeg['scheduled_date'] : (string) ($master['booking_date'] ?? ''),
            'scheduled_start_time'  => isset($guideLeg['scheduled_time']) ? (string) $guideLeg['scheduled_time'] : (string) ($master['booking_time'] ?? ''),
            'scheduled_end_time'    => isset($guideLeg['scheduled_end_time']) ? (string) $guideLeg['scheduled_end_time'] : (string) ($master['booking_end_time'] ?? ''),
            'preferred_resource_ref'=> $resourceRef,
        ];
    }

    /**
     * @param array<string, mixed> $booking
     */
    private function deriveRequestedLanguage(array $booking): string
    {
        $candidates = [];
        $candidates[] = $booking['language'] ?? null;

        $customer = isset($booking['customer']) && is_array($booking['customer']) ? $booking['customer'] : [];
        $candidates[] = $customer['language'] ?? null;

        $items = isset($booking['items']) && is_array($booking['items']) ? $booking['items'] : [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $meta = isset($item['meta']) && is_array($item['meta']) ? $item['meta'] : [];
            $planItem = isset($meta['plan_item']) && is_array($meta['plan_item']) ? $meta['plan_item'] : [];
            $plannerInput = isset($meta['planner_input']) && is_array($meta['planner_input']) ? $meta['planner_input'] : [];

            $candidates[] = $planItem['language'] ?? null;
            $candidates[] = $plannerInput['language'] ?? null;
            $candidates[] = $plannerInput['locale'] ?? null;
        }

        if (function_exists('determine_locale')) {
            $candidates[] = determine_locale();
        } elseif (function_exists('get_locale')) {
            $candidates[] = get_locale();
        }

        foreach ($candidates as $candidate) {
            $language = $this->normalizeLanguage($candidate);
            if ($language !== '') {
                return $language;
            }
        }

        return 'nl';
    }

    /**
     * @return array{0:int,1:int,2:int}
     * @param array<string, mixed> $demand
     */
    private function resolveGuides(wpdb $wpdb, array $demand): array
    {
        $requestedLanguage = (string) $demand['requested_language'];
        $preferredPostId = (int) $demand['preferred_resource_ref'];
        $candidates = $this->loadGuideCandidates($wpdb, $requestedLanguage);
        if ($candidates === []) {
            return [0, 0, 0];
        }

        usort(
            $candidates,
            function (array $left, array $right) use ($wpdb, $demand): int {
                $leftLoad = $this->calculateGuideLoad($wpdb, (int) ($left['city_guide_post_id'] ?? 0), $demand);
                $rightLoad = $this->calculateGuideLoad($wpdb, (int) ($right['city_guide_post_id'] ?? 0), $demand);
                if ($leftLoad === $rightLoad) {
                    return ((int) ($left['city_guide_post_id'] ?? 0)) <=> ((int) ($right['city_guide_post_id'] ?? 0));
                }

                return $leftLoad <=> $rightLoad;
            }
        );

        if ($preferredPostId > 0) {
            foreach ($candidates as $index => $candidate) {
                if ((int) ($candidate['city_guide_post_id'] ?? 0) === $preferredPostId) {
                    $preferred = $candidate;
                    unset($candidates[$index]);
                    array_unshift($candidates, $preferred);
                    break;
                }
            }
        }

        $primary = (int) ($candidates[0]['city_guide_post_id'] ?? 0);
        $backup = (int) ($candidates[1]['city_guide_post_id'] ?? 0);
        $scarcityScore = max(0, 100 - (count($candidates) * 20));

        return [$primary, $backup, $scarcityScore];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadGuideCandidates(wpdb $wpdb, string $requestedLanguage): array
    {
        $profilesTable = $wpdb->prefix . 'bsp_guide_profiles';
        $skillsTable = $wpdb->prefix . 'bsp_guide_skills';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.id, p.city_guide_post_id, p.display_name, p.allow_nl_tours, s.skill_code, s.protected_pool
                 FROM {$profilesTable} p
                 LEFT JOIN {$skillsTable} s ON s.profile_id = p.id AND s.skill_type = %s
                 WHERE p.status IN ('idle', 'active', 'synced', 'ok')",
                'language'
            ),
            ARRAY_A
        );

        if (! is_array($rows)) {
            return [];
        }

        $profiles = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $profileId = (int) ($row['id'] ?? 0);
            if ($profileId <= 0) {
                continue;
            }

            if (! isset($profiles[$profileId])) {
                $profiles[$profileId] = [
                    'id'                  => $profileId,
                    'city_guide_post_id'  => (int) ($row['city_guide_post_id'] ?? 0),
                    'display_name'        => (string) ($row['display_name'] ?? ''),
                    'allow_nl_tours'      => ! empty($row['allow_nl_tours']),
                    'languages'           => [],
                    'protected_languages' => [],
                ];
            }

            $skillCode = $this->normalizeLanguage($row['skill_code'] ?? null);
            if ($skillCode !== '') {
                $profiles[$profileId]['languages'][] = $skillCode;
                if (! empty($row['protected_pool'])) {
                    $profiles[$profileId]['protected_languages'][] = $skillCode;
                }
            }
        }

        $candidates = [];
        foreach ($profiles as $profile) {
            $languages = array_values(array_unique($profile['languages']));
            $protectedLanguages = array_values(array_unique($profile['protected_languages']));
            if ($languages === [] || ! in_array($requestedLanguage, $languages, true)) {
                continue;
            }

            if (
                $requestedLanguage === 'nl'
                && $protectedLanguages !== []
                && ! $profile['allow_nl_tours']
                && ! in_array('nl', $protectedLanguages, true)
            ) {
                continue;
            }

            $profile['languages'] = $languages;
            $profile['protected_languages'] = $protectedLanguages;
            $candidates[] = $profile;
        }

        return $candidates;
    }

    /**
     * @param array<string, mixed> $demand
     */
    private function calculateGuideLoad(wpdb $wpdb, int $cityGuidePostId, array $demand): int
    {
        if ($cityGuidePostId <= 0) {
            return 999;
        }

        $table = $wpdb->prefix . 'bsp_guide_assignments';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT scheduled_start_time, scheduled_end_time
                 FROM {$table}
                 WHERE primary_guide_id = %d
                   AND scheduled_date = %s
                   AND status IN ('assigned', 'confirmed')",
                $cityGuidePostId,
                (string) ($demand['scheduled_date'] ?? '')
            ),
            ARRAY_A
        );

        if (! is_array($rows)) {
            return 0;
        }

        $load = 0;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            if ($this->timesOverlap(
                (string) ($row['scheduled_start_time'] ?? ''),
                (string) ($row['scheduled_end_time'] ?? ''),
                (string) ($demand['scheduled_start_time'] ?? ''),
                (string) ($demand['scheduled_end_time'] ?? '')
            )) {
                $load += 10;
            } else {
                $load++;
            }
        }

        return $load;
    }

    private function timesOverlap(string $leftStart, string $leftEnd, string $rightStart, string $rightEnd): bool
    {
        if ($leftStart === '' || $rightStart === '') {
            return false;
        }

        $leftEnd = $leftEnd !== '' ? $leftEnd : $leftStart;
        $rightEnd = $rightEnd !== '' ? $rightEnd : $rightStart;

        return $leftStart < $rightEnd && $rightStart < $leftEnd;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function upsertAssignment(wpdb $wpdb, array $row): void
    {
        $table = $wpdb->prefix . 'bsp_guide_assignments';
        $masterId = (int) $row['master_id'];
        $legKey = isset($row['leg_key']) ? trim((string) $row['leg_key']) : '';

        if ($legKey !== '') {
            $existingId = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE master_id = %d AND leg_key = %s LIMIT 1",
                    $masterId,
                    $legKey
                )
            );
        } else {
            $existingId = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE master_id = %d LIMIT 1",
                    $masterId
                )
            );
        }

        if ($existingId > 0) {
            $wpdb->update($table, $row, ['id' => $existingId]);
            return;
        }

        $wpdb->insert($table, $row);
    }

    private function deleteAssignment(wpdb $wpdb, int $masterId): void
    {
        $wpdb->delete($wpdb->prefix . 'bsp_guide_assignments', ['master_id' => $masterId], ['%d']);
    }

    /**
     * @param mixed $language
     */
    private function normalizeLanguage($language): string
    {
        $language = strtolower(trim((string) $language));
        if ($language === '') {
            return '';
        }

        if (preg_match('/^[a-z]{2}(?:[_-][a-z]{2})?$/', $language) === 1) {
            return substr($language, 0, 2);
        }

        return match ($language) {
            'english' => 'en',
            'deutsch', 'german' => 'de',
            'nederlands', 'dutch' => 'nl',
            default => '',
        };
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

if (! class_exists('BSPModule\\Bookings\\Service\\GuideAssignmentService', false)) {
    class_alias(GuideAssignmentService::class, 'BSPModule\\Bookings\\Service\\GuideAssignmentService');
}
