<?php

/**
 * Align the uploads base URL with the active site host to avoid CORS issues on staging.
 *
 * This prevents WordPress from serving assets (fonts, images) from the production domain
 * when the database hasn't been fully rewritten for the current environment.
 */

if (! defined('ABSPATH')) {
    return;
}

add_filter(
    'upload_dir',
    static function (array $dirs): array {
        $current_host = wp_parse_url(home_url(), PHP_URL_HOST);
        $base_host    = wp_parse_url($dirs['baseurl'] ?? '', PHP_URL_HOST);

        if (! $current_host || ! $base_host || $current_host === $base_host) {
            return $dirs;
        }

        $scheme = wp_parse_url(home_url(), PHP_URL_SCHEME) ?: 'https';
        $prefix = $scheme . '://' . $current_host;

        foreach (array( 'baseurl', 'url' ) as $key) {
            if (! empty($dirs[ $key ])) {
                $dirs[ $key ] = preg_replace('#^https?://[^/]+#', $prefix, $dirs[ $key ]);
            }
        }

        return $dirs;
    },
    20
);
