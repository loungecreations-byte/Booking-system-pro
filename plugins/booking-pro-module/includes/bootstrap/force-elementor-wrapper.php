<?php
/**
 * Force Elementor wrapper class for private tours in preview mode.
 *
 * The old version injected inline scripts through wp_head and output buffering.
 * This version keeps the behavior but moves the DOM work into a dedicated asset.
 */

add_action('wp_enqueue_scripts', function () {
    if (!isset($_GET['elementor-preview'])) {
        return;
    }

    $post_id = intval($_GET['elementor-preview']);
    if (!$post_id) {
        return;
    }

    $asset_path = WP_CONTENT_DIR . '/plugins/booking-pro-module/assets/js/force-elementor-wrapper.js';
    $asset_url = WP_CONTENT_URL . '/plugins/booking-pro-module/assets/js/force-elementor-wrapper.js';

    if (!file_exists($asset_path)) {
        return;
    }

    wp_enqueue_script(
        'force-elementor-wrapper',
        $asset_url,
        array(),
        filemtime($asset_path),
        true
    );

    wp_localize_script(
        'force-elementor-wrapper',
        'ForceElementorWrapperConfig',
        array(
            'postId' => $post_id,
        )
    );
}, 20);
