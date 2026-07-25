<?php

declare(strict_types=1);

namespace BSP\DiscoveryCamera\Assets;

final class AssetService
{
    public static function register(): void
    {
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue'), 30);
    }

    public static function enqueue(): void
    {
        if (is_admin() || ! self::isTourSurface()) {
            return;
        }

        $script = dirname(__DIR__) . '/Assets/discovery-camera.js';
        $style = dirname(__DIR__) . '/Assets/discovery-camera.css';

        wp_enqueue_style(
            'ddb-discovery-camera',
            SBDP_URL . 'modules/discovery-camera/Assets/discovery-camera.css',
            array('sbdp-tour-navigation'),
            is_readable($style) ? (string) filemtime($style) : SBDP_VERSION
        );
        wp_enqueue_script(
            'ddb-discovery-camera',
            SBDP_URL . 'modules/discovery-camera/Assets/discovery-camera.js',
            array('sbdp-tour-navigation'),
            is_readable($script) ? (string) filemtime($script) : SBDP_VERSION,
            true
        );
        wp_localize_script('ddb-discovery-camera', 'ddbDiscoveryCamera', array(
            'restBase' => esc_url_raw(rest_url('bsp/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'maxUploadBytes' => 8 * MB_IN_BYTES,
            'featureEnabled' => (string) get_option('ddb_discovery_camera_enabled', '0') === '1',
        ));
    }

    private static function isTourSurface(): bool
    {
        if (is_singular('sbdp_private_tour')) {
            return true;
        }

        $post = get_post();
        return $post && (
            has_shortcode((string) $post->post_content, 'sbdp_private_tour_portal')
            || has_shortcode((string) $post->post_content, 'private_tour_portal')
        );
    }
}
