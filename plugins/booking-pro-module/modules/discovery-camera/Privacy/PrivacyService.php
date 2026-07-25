<?php

declare(strict_types=1);

namespace BSP\DiscoveryCamera\Privacy;

final class PrivacyService
{
    public static function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', array(__CLASS__, 'exporters'));
        add_filter('wp_privacy_personal_data_erasers', array(__CLASS__, 'erasers'));
    }

    public static function exporters(array $items): array
    {
        $items['bsp-discovery-camera'] = array(
            'exporter_friendly_name' => 'DagjeDenBosch Discovery Camera',
            'callback' => array(__CLASS__, 'export'),
        );

        return $items;
    }

    public static function erasers(array $items): array
    {
        $items['bsp-discovery-camera'] = array(
            'eraser_friendly_name' => 'DagjeDenBosch Discovery Camera',
            'callback' => array(__CLASS__, 'erase'),
        );

        return $items;
    }

    public static function export(string $email, int $page = 1): array
    {
        unset($page);
        $user = get_user_by('email', $email);
        if (! $user) {
            return array('data' => array(), 'done' => true);
        }

        global $wpdb;
        $attempts = $wpdb->get_results($wpdb->prepare(
            "SELECT attempt_uuid,tour_id,step_id,status,challenge_revision,consent_version,captured_at,expires_at,created_at,updated_at FROM {$wpdb->prefix}bsp_photo_attempts WHERE user_id=%d",
            $user->ID
        ), ARRAY_A);

        return array(
            'data' => array(array(
                'group_id' => 'bsp-discovery-camera',
                'group_label' => 'Discovery Camera',
                'item_id' => 'discovery-camera-' . $user->ID,
                'data' => array(array('name' => 'Fotopogingen', 'value' => wp_json_encode($attempts))),
            )),
            'done' => true,
        );
    }

    public static function erase(string $email, int $page = 1): array
    {
        unset($page);
        $user = get_user_by('email', $email);
        if (! $user) {
            return array('items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true);
        }

        global $wpdb;
        $attemptIds = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}bsp_photo_attempts WHERE user_id=%d",
            $user->ID
        ));
        foreach (array_map('absint', (array) $attemptIds) as $attemptId) {
            $wpdb->delete($wpdb->prefix . 'bsp_photo_analyses', array('attempt_id' => $attemptId), array('%d'));
        }
        $wpdb->delete($wpdb->prefix . 'bsp_photo_attempts', array('user_id' => $user->ID), array('%d'));

        return array('items_removed' => true, 'items_retained' => false, 'messages' => array(), 'done' => true);
    }
}
