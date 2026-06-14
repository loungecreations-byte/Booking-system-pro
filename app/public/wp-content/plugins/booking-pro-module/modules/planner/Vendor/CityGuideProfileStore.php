<?php

declare(strict_types=1);

namespace BSP\Planner\Vendor;

use WP_Post;

final class CityGuideProfileStore
{
    private const POST_TYPE = 'bsp_city_guide';

    public function register(): void
    {
        if (! function_exists('register_post_type')) {
            return;
        }

        if (function_exists('post_type_exists') && post_type_exists(self::POST_TYPE)) {
            return;
        }

        register_post_type(
            self::POST_TYPE,
            array(
                'labels' => array(
                    'name'          => 'City guides',
                    'singular_name' => 'City guide',
                ),
                'public'       => false,
                'show_ui'      => true,
                'show_in_menu' => false,
                'supports'     => array('title'),
            )
        );
    }

    /**
     * @return array<int, CityGuideProfile>
     */
    public function all(): array
    {
        if (! function_exists('get_posts')) {
            return array();
        }

        $posts = get_posts(
            array(
                'post_type'      => self::POST_TYPE,
                'post_status'    => array('publish', 'private', 'draft'),
                'posts_per_page' => -1,
                'orderby'        => 'menu_order title',
                'order'          => 'ASC',
            )
        );

        if (! is_array($posts)) {
            return array();
        }

        $profiles = array();
        foreach ($posts as $post) {
            if (! $post instanceof WP_Post) {
                continue;
            }

            $profile = $this->fromPost($post);
            if ($profile !== null) {
                $profiles[] = $profile;
            }
        }

        return $profiles;
    }

    private function fromPost(WP_Post $post): ?CityGuideProfile
    {
        $id = (int) $post->ID;
        if ($id <= 0) {
            return null;
        }

        $name = trim((string) $post->post_title);
        if ($name === '') {
            $name = 'Gids #' . $id;
        }

        return new CityGuideProfile(
            $id,
            $name,
            $this->metaString($id, '_bsp_cityguide_status', $post->post_status ?: 'active'),
            $this->metaString($id, '_bsp_cityguide_timezone', 'Europe/Amsterdam'),
            $this->metaBool($id, '_bsp_cityguide_allow_nl_tours'),
            $this->metaString($id, '_bsp_cityguide_ical', ''),
            $this->metaString($id, '_bsp_cityguide_note', ''),
            $this->metaString($id, '_bsp_cityguide_last_sync', ''),
            $this->metaList($id, '_bsp_cityguide_languages', array('nl')),
            $this->metaList($id, '_bsp_cityguide_protected_languages', array())
        );
    }

    private function metaString(int $postId, string $key, string $default): string
    {
        if (! function_exists('get_post_meta')) {
            return $default;
        }

        $value = get_post_meta($postId, $key, true);
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value !== '' ? $value : $default;
    }

    private function metaBool(int $postId, string $key): bool
    {
        if (! function_exists('get_post_meta')) {
            return false;
        }

        $value = get_post_meta($postId, $key, true);

        return in_array($value, array(true, 1, '1', 'yes', 'true', 'on'), true);
    }

    /**
     * @param array<int, string> $default
     * @return array<int, string>
     */
    private function metaList(int $postId, string $key, array $default): array
    {
        if (! function_exists('get_post_meta')) {
            return $default;
        }

        $value = get_post_meta($postId, $key, true);
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = preg_split('/[,;\s]+/', $value) ?: array();
            }
        }

        if (! is_array($value)) {
            return $default;
        }

        $normalized = array_values(
            array_filter(
                array_map(
                    static fn ($item): string => strtolower(trim((string) $item)),
                    $value
                ),
                static fn (string $item): bool => $item !== ''
            )
        );

        return $normalized !== array() ? array_values(array_unique($normalized)) : $default;
    }
}

