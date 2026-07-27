<?php

declare(strict_types=1);

namespace BSP\ExperienceBuilder\Admin;

final class ChapterBuilderMetaBox
{
    private const HANDLE = 'sbdp-experience-builder';

    public static function register(): void
    {
        add_action('add_meta_boxes_sbdp_tour_step', array(__CLASS__, 'add'), 1);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
    }

    public static function add(): void
    {
        add_meta_box(
            'sbdp_experience_builder',
            __('Stapinhoud · modules', 'sbdp'),
            array(__CLASS__, 'render'),
            'sbdp_tour_step',
            'normal',
            'high'
        );
    }

    public static function render(\WP_Post $post): void
    {
        echo '<div class="sbdp-experience-builder-intro">';
        echo '<strong>' . esc_html__('Bouw deze stap op uit meerdere onderdelen', 'sbdp') . '</strong>';
        echo '<p>' . esc_html__('Voeg tekst, media, 3D, quiz, camera en beloningen toe en sleep ze in de gewenste volgorde. Het oude hoofdstuktype blijft alleen voor bestaande tours behouden.', 'sbdp') . '</p>';
        echo '</div>';
        echo '<div id="sbdp-experience-builder-root" data-chapter-id="' . esc_attr((string) $post->ID) . '">';
        echo '<p class="sbdp-experience-builder__loading">' . esc_html__('Experience Builder wordt geladen…', 'sbdp') . '</p>';
        echo '</div>';
        echo '<noscript><p>' . esc_html__('JavaScript is nodig om Experience modules te bewerken.', 'sbdp') . '</p></noscript>';
    }

    public static function enqueue(string $hook): void
    {
        if (! in_array($hook, array('post.php', 'post-new.php'), true)) {
            return;
        }
        $screen = get_current_screen();
        if (! $screen || $screen->post_type !== 'sbdp_tour_step') {
            return;
        }

        $entry = 'modules/experience-builder/assets/admin/index.jsx';
        $asset = self::manifestAsset($entry);
        if ($asset === null) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_style(
            self::HANDLE,
            SBDP_URL . 'modules/experience-builder/assets/admin/builder.css',
            array(),
            (string) filemtime(SBDP_DIR . 'modules/experience-builder/assets/admin/builder.css')
        );
        $src = SBDP_URL . 'build/' . ltrim((string) $asset['file'], '/');
        $version = is_readable(SBDP_DIR . 'build/' . $asset['file'])
            ? (string) filemtime(SBDP_DIR . 'build/' . $asset['file'])
            : (defined('SBDP_VERSION') ? SBDP_VERSION : '1');

        wp_enqueue_script(self::HANDLE, $src, array(), $version, true);
        wp_localize_script(self::HANDLE, 'sbdpExperienceBuilder', array(
            'endpoint' => esc_url_raw(rest_url('bsp/v1/experience-builder/chapters/')),
            'nonce' => wp_create_nonce('wp_rest'),
        ));
        add_filter('script_loader_tag', array(__CLASS__, 'moduleTag'), 10, 2);
    }

    public static function moduleTag(string $tag, string $handle): string
    {
        return $handle === self::HANDLE
            ? str_replace('<script ', '<script type="module" ', $tag)
            : $tag;
    }

    /** @return array<string,mixed>|null */
    private static function manifestAsset(string $entry): ?array
    {
        $path = SBDP_DIR . 'build/.vite/manifest.json';
        if (! is_readable($path)) {
            return null;
        }
        $manifest = json_decode((string) file_get_contents($path), true);

        return is_array($manifest) && is_array($manifest[$entry] ?? null) ? $manifest[$entry] : null;
    }
}
