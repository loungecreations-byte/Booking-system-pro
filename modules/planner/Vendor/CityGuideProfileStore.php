<?php
declare(strict_types=1);

namespace BSP\Planner\Vendor;

use WP_Post;

final class CityGuideProfileStore
{
    public const POST_TYPE = 'bsp_city_guide';

    /** @var array<int, array<string, mixed>> */
    private static array $memoryPosts = [];

    /** @var array<int, array<string, mixed>> */
    private static array $memoryMeta = [];

    private static int $memoryIncrement = 1;

    public static function resetMemory(): void
    {
        self::$memoryPosts = [];
        self::$memoryMeta = [];
        self::$memoryIncrement = 1;
    }

    public function register(): void
    {
        if (\function_exists('register_post_type')) {
            register_post_type(self::POST_TYPE, [
                'labels' => [
                    'name'          => __('City Guides', 'bsp'),
                    'singular_name' => __('City Guide', 'bsp'),
                ],
                'public'          => false,
                'show_ui'         => true,
                'show_in_menu'    => false,
                'supports'        => ['title'],
                'capability_type' => 'post',
                'map_meta_cap'    => true,
            ]);
        }
    }

    /**
     * @return array<int, CityGuideProfile>
     */
    public function all(): array
    {
        $posts = [];

        if (\function_exists('get_posts')) {
            $posts = get_posts([
                'post_type'      => self::POST_TYPE,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
            ]);
        } else {
            $posts = array_map(static function (array $record): WP_Post {
                return new WP_Post((object) $record);
            }, self::$memoryPosts);
        }

        $profiles = [];
        foreach ($posts as $post) {
            if ($post instanceof WP_Post && self::POST_TYPE === $post->post_type) {
                $profiles[] = $this->mapPost($post);
            }
        }

        return $profiles;
    }

    public function find(int $id): ?CityGuideProfile
    {
        $post = null;

        if (\function_exists('get_post')) {
            $candidate = get_post($id);
            if ($candidate instanceof WP_Post && self::POST_TYPE === $candidate->post_type) {
                $post = $candidate;
            }
        } elseif (isset(self::$memoryPosts[$id])) {
            $post = new WP_Post((object) self::$memoryPosts[$id]);
        }

        return $post ? $this->mapPost($post) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save(array $data): int
    {
        if (\function_exists('wp_insert_post')) {
            $payload = [
                'post_title'  => (string) ($data['name'] ?? ''),
                'post_status' => 'publish',
                'post_type'   => self::POST_TYPE,
            ];

            if (isset($data['id'])) {
                $payload['ID'] = (int) $data['id'];
            }

            $postId = wp_insert_post($payload);
            if (is_wp_error($postId)) {
                return 0;
            }

            $this->updateMeta($postId, '_bsp_cityguide_ical', (string) ($data['ical_url'] ?? ''));
            $this->updateMeta($postId, '_bsp_cityguide_timezone', (string) ($data['timezone'] ?? 'UTC'));
            $this->updateMeta($postId, '_bsp_cityguide_note', (string) ($data['note'] ?? ''));
            $this->updateMeta($postId, '_bsp_cityguide_status', (string) ($data['status'] ?? 'idle'));
            if (isset($data['last_sync'])) {
                $this->updateMeta($postId, '_bsp_cityguide_last_sync', (string) $data['last_sync']);
            }

            return (int) $postId;
        }

        $id = isset($data['id']) ? (int) $data['id'] : self::$memoryIncrement++;
        self::$memoryPosts[$id] = [
            'ID'          => $id,
            'post_title'  => (string) ($data['name'] ?? ''),
            'post_status' => 'publish',
            'post_type'   => self::POST_TYPE,
        ];
        self::$memoryMeta[$id] = [
            '_bsp_cityguide_ical'      => (string) ($data['ical_url'] ?? ''),
            '_bsp_cityguide_timezone'  => (string) ($data['timezone'] ?? 'UTC'),
            '_bsp_cityguide_note'      => (string) ($data['note'] ?? ''),
            '_bsp_cityguide_status'    => (string) ($data['status'] ?? 'idle'),
            '_bsp_cityguide_last_sync' => (string) ($data['last_sync'] ?? ''),
        ];

        return $id;
    }

    private function mapPost(WP_Post $post): CityGuideProfile
    {
        $ical     = $this->getMeta($post->ID, '_bsp_cityguide_ical');
        $timezone = $this->getMeta($post->ID, '_bsp_cityguide_timezone') ?: 'UTC';
        $note     = $this->getMeta($post->ID, '_bsp_cityguide_note');
        $lastSync = $this->getMeta($post->ID, '_bsp_cityguide_last_sync');
        $status   = $this->getMeta($post->ID, '_bsp_cityguide_status') ?: 'idle';

        return new CityGuideProfile(
            (int) $post->ID,
            $post->post_title,
            $ical,
            $timezone,
            $note !== '' ? $note : null,
            $lastSync !== '' ? $lastSync : null,
            $status
        );
    }

    private function updateMeta(int $postId, string $key, string $value): void
    {
        if (\function_exists('update_post_meta')) {
            update_post_meta($postId, $key, $value);
            return;
        }

        if (!isset(self::$memoryMeta[$postId])) {
            self::$memoryMeta[$postId] = [];
        }

        self::$memoryMeta[$postId][$key] = $value;
    }

    private function getMeta(int $postId, string $key): string
    {
        if (\function_exists('get_post_meta')) {
            return (string) get_post_meta($postId, $key, true);
        }

        return (string) (self::$memoryMeta[$postId][$key] ?? '');
    }
}
