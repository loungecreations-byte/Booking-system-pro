<?php

declare(strict_types=1);

namespace BSP\PartnerProgram\Service;

use wpdb;
use function wp_json_encode;
use function absint;

/**
 * GooglePlacesService — one-way sync from Google Places API into bsp_place_seeds.
 *
 * This is the ONLY place that reads from Google.
 * Seeds are discovery data only — not commercial truth.
 *
 * Usage:
 *   GooglePlacesService::syncByQuery('Den Bosch restaurants');
 *   GooglePlacesService::syncByPlaceId('ChIJXXX...');
 *   GooglePlacesService::syncNearby(51.6978, 5.3037, 5000, 'tourist_attraction');
 *
 * Requires: GOOGLE_PLACES_API_KEY constant or BSP_GOOGLE_PLACES_KEY option.
 */
final class GooglePlacesService
{
    private const API_BASE    = 'https://maps.googleapis.com/maps/api';
    private const TIMEOUT     = 15;
    private const MAX_RESULTS = 60; // Google returns max 60 (3 pages × 20)

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Sync places near a coordinate.
     *
     * @param float  $lat      Latitude
     * @param float  $lng      Longitude
     * @param int    $radius   Metres (max 50000)
     * @param string $type     Google place type (e.g. 'tourist_attraction', 'restaurant', 'museum')
     *
     * @return array{synced: int, errors: int, skipped: int}
     */
    public static function syncNearby(float $lat, float $lng, int $radius = 5000, string $type = 'tourist_attraction'): array
    {
        $apiKey = self::resolveApiKey();
        if (! $apiKey) {
            return ['synced' => 0, 'errors' => 1, 'skipped' => 0, 'error_message' => 'Google API key not configured.'];
        }

        $url = self::API_BASE . '/place/nearbysearch/json?' . http_build_query([
            'location' => "{$lat},{$lng}",
            'radius'   => $radius,
            'type'     => $type,
            'key'      => $apiKey,
        ]);

        return self::fetchAndUpsertPages($url, $apiKey);
    }

    /**
     * Sync places by text query.
     *
     * @return array{synced: int, errors: int, skipped: int}
     */
    public static function syncByQuery(string $query, ?float $lat = null, ?float $lng = null): array
    {
        $apiKey = self::resolveApiKey();
        if (! $apiKey) {
            return ['synced' => 0, 'errors' => 1, 'skipped' => 0, 'error_message' => 'Google API key not configured.'];
        }

        $params = ['query' => $query, 'key' => $apiKey];
        if ($lat !== null && $lng !== null) {
            $params['location'] = "{$lat},{$lng}";
            $params['radius']   = 5000;
        }

        $url = self::API_BASE . '/place/textsearch/json?' . http_build_query($params);

        return self::fetchAndUpsertPages($url, $apiKey);
    }

    /**
     * Sync a single place by Google Place ID (fetches full detail).
     *
     * @return array{synced: int, errors: int}
     */
    public static function syncByPlaceId(string $placeId): array
    {
        $apiKey = self::resolveApiKey();
        if (! $apiKey) {
            return ['synced' => 0, 'errors' => 1, 'error_message' => 'Google API key not configured.'];
        }

        $url = self::API_BASE . '/place/details/json?' . http_build_query([
            'place_id' => $placeId,
            'fields'   => 'place_id,name,formatted_address,geometry,international_phone_number,website,types',
            'key'      => $apiKey,
        ]);

        $data = self::httpGet($url);
        if (! $data || ($data['status'] ?? '') !== 'OK') {
            return ['synced' => 0, 'errors' => 1, 'error_message' => $data['status'] ?? 'API error'];
        }

        $result = self::upsertSeed($data['result']);
        return ['synced' => $result ? 1 : 0, 'errors' => $result ? 0 : 1];
    }

    // -------------------------------------------------------------------------
    // Private: pagination + upsert
    // -------------------------------------------------------------------------

    /**
     * Follows nextPageToken to fetch up to 3 pages from Places API.
     */
    private static function fetchAndUpsertPages(string $url, string $apiKey): array
    {
        $synced  = 0;
        $errors  = 0;
        $skipped = 0;
        $page    = 0;

        do {
            $data = self::httpGet($url);
            if (! $data) {
                $errors++;
                break;
            }

            $status = $data['status'] ?? 'UNKNOWN';

            if ($status === 'ZERO_RESULTS') {
                break;
            }

            if (! in_array($status, ['OK', 'ZERO_RESULTS'], true)) {
                self::logSyncError(null, $status, $url);
                $errors++;
                break;
            }

            foreach (($data['results'] ?? []) as $place) {
                $upserted = self::upsertSeed($place);
                if ($upserted === null) {
                    $errors++;
                } elseif ($upserted === false) {
                    $skipped++;
                } else {
                    $synced++;
                }
            }

            $token = $data['next_page_token'] ?? null;
            if ($token) {
                // Google requires a short pause before using nextPageToken.
                sleep(2);
                $url = self::API_BASE . '/place/nearbysearch/json?' . http_build_query([
                    'pagetoken' => $token,
                    'key'       => $apiKey,
                ]);
            }

            $page++;

        } while ($token && $page < 3);

        return compact('synced', 'errors', 'skipped');
    }

    /**
     * Upsert a place record into bsp_place_seeds.
     *
     * @return bool|null  true=upserted, false=no-change skip, null=error
     */
    private static function upsertSeed(array $place): ?bool
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return null;
        }

        $externalId = (string) ($place['place_id'] ?? '');
        if (! $externalId) {
            return null;
        }

        $table = $wpdb->prefix . 'bsp_place_seeds';

        $address  = (string) ($place['vicinity'] ?? $place['formatted_address'] ?? '');
        $name     = (string) ($place['name'] ?? '');
        $lat      = (float) ($place['geometry']['location']['lat'] ?? 0);
        $lng      = (float) ($place['geometry']['location']['lng'] ?? 0);
        $phone    = (string) ($place['international_phone_number'] ?? $place['formatted_phone_number'] ?? '');
        $website  = (string) ($place['website'] ?? '');
        $types    = wp_json_encode($place['types'] ?? []);
        $city     = self::extractCity($address, $place);
        $postal   = self::extractPostal($address, $place);

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE external_source = 'google' AND external_id = %s LIMIT 1",
            $externalId
        ));

        $row = [
            'external_source' => 'google',
            'external_id'     => $externalId,
            'name'            => $name,
            'address'         => $address,
            'city'            => $city,
            'postal_code'     => $postal,
            'lat'             => $lat,
            'lng'             => $lng,
            'phone'           => $phone,
            'website'         => $website,
            'categories'      => $types,
            'raw_payload'     => wp_json_encode($place),
            'sync_status'     => 'synced',
            'last_synced_at'  => current_time('mysql'),
        ];

        if ($existing) {
            $ok = $wpdb->update($table, $row, ['id' => (int) $existing]);
            self::logSync((int) $existing, $ok !== false ? 'ok' : 'failed', 200);
            return $ok !== false ? true : null;
        }

        $ok = $wpdb->insert($table, $row);
        if ($ok) {
            self::logSync((int) $wpdb->insert_id, 'ok', 200);
            return true;
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Private: helpers
    // -------------------------------------------------------------------------

    private static function resolveApiKey(): string
    {
        return \BSP\PartnerProgram\Admin\Settings::googleApiKey();
    }

    private static function httpGet(string $url): ?array
    {
        $response = wp_remote_get($url, [
            'timeout'   => self::TIMEOUT,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return is_array($data) ? $data : null;
    }

    private static function extractCity(string $address, array $place): string
    {
        // Try address_components first (from details endpoint).
        foreach (($place['address_components'] ?? []) as $comp) {
            if (in_array('locality', $comp['types'], true)) {
                return (string) $comp['long_name'];
            }
        }

        // Fallback: last segment of comma-separated address string (often "City, Country").
        $parts = array_map('trim', explode(',', $address));
        return count($parts) >= 2 ? $parts[count($parts) - 2] : '';
    }

    private static function extractPostal(string $address, array $place): string
    {
        foreach (($place['address_components'] ?? []) as $comp) {
            if (in_array('postal_code', $comp['types'], true)) {
                return (string) $comp['long_name'];
            }
        }
        return '';
    }

    private static function logSync(int $seedId, string $result, int $code): void
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return;
        }

        $wpdb->insert($wpdb->prefix . 'bsp_place_seed_sync_log', [
            'place_seed_id'     => $seedId,
            'sync_result'       => $result,
            'api_response_code' => $code,
            'synced_at'         => current_time('mysql'),
        ]);
    }

    private static function logSyncError(?int $seedId, string $status, string $url): void
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return;
        }

        $wpdb->insert($wpdb->prefix . 'bsp_place_seed_sync_log', [
            'place_seed_id'     => $seedId,
            'sync_result'       => 'failed',
            'api_response_code' => null,
            'note'              => "Status: {$status} | URL: " . substr($url, 0, 200),
            'synced_at'         => current_time('mysql'),
        ]);
    }
}
