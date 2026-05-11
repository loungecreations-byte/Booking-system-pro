<?php

declare(strict_types=1);

namespace BSP\DayPlanner\Service;

final class DBSpotsClient
{
    /**
     * @param array<string, mixed> $filters
     *
     * @return array<int, array<string, mixed>>
     */
    public function listSpots(array $filters = []): array
    {
        if (! function_exists('rest_url') || ! function_exists('wp_remote_get')) {
            return [];
        }

        $query = [
            'status' => 'published',
        ];

        if (isset($filters['area']) && is_string($filters['area']) && $filters['area'] !== '') {
            $query['area'] = $filters['area'];
        }
        if (isset($filters['q']) && is_string($filters['q']) && $filters['q'] !== '') {
            $query['q'] = $filters['q'];
        }
        if (isset($filters['type']) && is_string($filters['type']) && $filters['type'] !== '') {
            $query['type'] = $filters['type'];
        }

        $endpoint = add_query_arg($query, rest_url('dbspots/v1/spots'));
        $response = wp_remote_get($endpoint, ['timeout' => 8]);
        if (is_wp_error($response)) {
            return [];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            return [];
        }

        $body = wp_remote_retrieve_body($response);
        if (! is_string($body) || $body === '') {
            return [];
        }

        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return [];
        }

        $rawItems = [];
        if (isset($decoded['spots']) && is_array($decoded['spots'])) {
            $rawItems = $decoded['spots'];
        } elseif (isset($decoded['items']) && is_array($decoded['items'])) {
            $rawItems = $decoded['items'];
        } elseif (array_is_list($decoded)) {
            $rawItems = $decoded;
        }

        $spots = [];
        foreach ($rawItems as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = $item['id'] ?? ($item['spot_id'] ?? null);
            if (! is_scalar($id)) {
                continue;
            }

            $title = '';
            if (isset($item['title'])) {
                if (is_array($item['title']) && isset($item['title']['rendered'])) {
                    $title = (string) $item['title']['rendered'];
                } else {
                    $title = (string) $item['title'];
                }
            } elseif (isset($item['name'])) {
                $title = (string) $item['name'];
            }

            if ($title === '') {
                $title = 'Spot';
            }

            $url = (string) ($item['url'] ?? $item['link'] ?? '');
            $location = (string) ($item['area'] ?? $item['location'] ?? 'Den Bosch');
            $duration = (int) ($item['duration_minutes'] ?? $item['duration'] ?? 75);

            $spots[] = [
                'id'                => (string) $id,
                'title'             => wp_strip_all_tags($title),
                'url'               => $url,
                'location'          => $location,
                'duration_minutes'  => max(30, $duration),
                'manual_priority'   => isset($item['manual_priority']) ? (float) $item['manual_priority'] : 0.45,
                'type_match'        => isset($item['type_match']) ? (float) $item['type_match'] : 0.65,
                'suitability_match' => isset($item['suitability_match']) ? (float) $item['suitability_match'] : 0.60,
                'distance_heuristic'=> isset($item['distance_heuristic']) ? (float) $item['distance_heuristic'] : 0.60,
            ];
        }

        return $spots;
    }
}

