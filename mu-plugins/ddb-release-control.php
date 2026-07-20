<?php
/**
 * Plugin Name: DDB Release Control
 * Description: Exposes the immutable deployment manifest for release traceability.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Return a normalized release manifest without inventing deployment truth.
 *
 * @return array<string, mixed>
 */
function ddb_release_control_manifest(): array
{
    $path = WP_CONTENT_DIR . '/ddb-release.json';
    $fallback = array(
        'status'      => 'unknown',
        'environment' => wp_get_environment_type(),
        'release_id'  => null,
        'commit'      => null,
        'deployed_at' => null,
    );

    if (!is_readable($path)) {
        return $fallback;
    }

    $contents = file_get_contents($path);
    $decoded = is_string($contents) ? json_decode($contents, true) : null;
    if (!is_array($decoded)) {
        $fallback['status'] = 'invalid_manifest';
        return $fallback;
    }

    $commit = isset($decoded['commit']) ? strtolower((string) $decoded['commit']) : '';
    $deployedAt = isset($decoded['deployed_at']) ? (string) $decoded['deployed_at'] : '';
    if (!preg_match('/^[a-f0-9]{40}$/', $commit) || false === strtotime($deployedAt)) {
        $fallback['status'] = 'invalid_manifest';
        return $fallback;
    }

    return array(
        'status'       => 'deployed',
        'environment'  => sanitize_key((string) ($decoded['environment'] ?? wp_get_environment_type())),
        'release_id'   => sanitize_text_field((string) ($decoded['release_id'] ?? substr($commit, 0, 12))),
        'commit'       => $commit,
        'deployed_at'  => gmdate('c', (int) strtotime($deployedAt)),
        'deployed_by'  => sanitize_text_field((string) ($decoded['deployed_by'] ?? 'unknown')),
        'source_branch' => sanitize_text_field((string) ($decoded['source_branch'] ?? 'deploy/wp-content')),
    );
}

add_action('rest_api_init', static function (): void {
    register_rest_route('ddb/v1', '/release', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => static function (): WP_REST_Response {
            $manifest = ddb_release_control_manifest();
            $response = rest_ensure_response($manifest);
            $response->header('Cache-Control', 'no-store, max-age=0');
            return $response;
        },
        'permission_callback' => '__return_true',
    ));
});

add_action('admin_menu', static function (): void {
    add_management_page(
        __('Release status', 'ddb'),
        __('Release status', 'ddb'),
        'manage_options',
        'ddb-release-status',
        static function (): void {
            $manifest = ddb_release_control_manifest();
            echo '<div class="wrap"><h1>' . esc_html__('Release status', 'ddb') . '</h1>';
            echo '<table class="widefat striped"><tbody>';
            foreach ($manifest as $key => $value) {
                echo '<tr><th scope="row">' . esc_html((string) $key) . '</th><td><code>'
                    . esc_html(null === $value ? '—' : (string) $value)
                    . '</code></td></tr>';
            }
            echo '</tbody></table></div>';
        }
    );
});

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('ddb release status', static function (): void {
        WP_CLI::line((string) wp_json_encode(ddb_release_control_manifest(), JSON_PRETTY_PRINT));
    });
}
