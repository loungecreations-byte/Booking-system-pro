<?php

declare(strict_types=1);

namespace BSP\DiscoveryCamera\Admin;

use BSP\DiscoveryCamera\Support\FeatureFlags;
use BSP\DiscoveryCamera\Service\PhotoAttemptService;

final class SettingsPage
{
    public static function register(): void
    {
        add_action('admin_menu', array(__CLASS__, 'menu'), 30);
        add_action('admin_init', array(__CLASS__, 'settings'));
        add_action('admin_post_ddb_photo_manual_review', array(__CLASS__, 'manualReview'));
        add_action('admin_post_ddb_photo_private_image', array(__CLASS__, 'privateImage'));
    }

    public static function menu(): void
    {
        add_submenu_page(
            'edit.php?post_type=sbdp_private_tour',
            __('Discovery Camera', 'sbdp'),
            __('Discovery Camera', 'sbdp'),
            'manage_options',
            'ddb-discovery-camera',
            array(__CLASS__, 'render')
        );
    }

    public static function settings(): void
    {
        register_setting('ddb_discovery_camera', FeatureFlags::OPTION_ENABLED, array(
            'type' => 'string',
            'sanitize_callback' => static fn ($value): string => ! empty($value) ? '1' : '0',
            'default' => '0',
        ));
        register_setting('ddb_discovery_camera', FeatureFlags::OPTION_PROVIDER_MODE, array(
            'type' => 'string',
            'sanitize_callback' => static function ($value): string {
                $mode = sanitize_key((string) $value);
                return in_array($mode, array('fake', 'shadow', 'live'), true) ? $mode : 'fake';
            },
            'default' => 'fake',
        ));
        register_setting('ddb_discovery_camera', FeatureFlags::OPTION_TOUR_ALLOWLIST, array(
            'type' => 'array',
            'sanitize_callback' => static fn ($value): array => array_values(array_unique(array_filter(array_map('absint', preg_split('/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY))))),
            'default' => array(),
        ));
        register_setting('ddb_discovery_camera', 'ddb_discovery_camera_model', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'gpt-4o',
        ));
    }

    public static function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $allowlist = implode(', ', array_map('absint', (array) get_option(FeatureFlags::OPTION_TOUR_ALLOWLIST, array())));
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Discovery Camera', 'sbdp'); ?></h1>
            <p><?php esc_html_e('Beheer de server-side AI-modus. Fake bewaart alleen, Shadow analyseert zonder rewards, Live kan progressie en rewards toekennen.', 'sbdp'); ?></p>
            <form action="options.php" method="post">
                <?php settings_fields('ddb_discovery_camera'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Ingeschakeld', 'sbdp'); ?></th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(FeatureFlags::OPTION_ENABLED); ?>" value="1" <?php checked(FeatureFlags::enabled()); ?>> <?php esc_html_e('Camera UI en REST activeren', 'sbdp'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ddb-camera-mode"><?php esc_html_e('Provider-modus', 'sbdp'); ?></label></th>
                        <td>
                            <select id="ddb-camera-mode" name="<?php echo esc_attr(FeatureFlags::OPTION_PROVIDER_MODE); ?>">
                                <?php foreach (array('fake' => 'Fake', 'shadow' => 'Shadow', 'live' => 'Live') as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected(FeatureFlags::providerMode(), $value); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ddb-camera-model"><?php esc_html_e('Vision-model', 'sbdp'); ?></label></th>
                        <td><input id="ddb-camera-model" class="regular-text" name="ddb_discovery_camera_model" value="<?php echo esc_attr((string) get_option('ddb_discovery_camera_model', 'gpt-4o')); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ddb-camera-allowlist"><?php esc_html_e('Tour allowlist', 'sbdp'); ?></label></th>
                        <td><input id="ddb-camera-allowlist" class="regular-text" name="<?php echo esc_attr(FeatureFlags::OPTION_TOUR_ALLOWLIST); ?>" value="<?php echo esc_attr($allowlist); ?>"><p class="description"><?php esc_html_e('Komma-gescheiden tour-ID’s. Leeg betekent alle tours.', 'sbdp'); ?></p></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <?php self::renderReviews(); ?>
        </div>
        <?php
    }

    public static function manualReview(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Geen toegang.', 'sbdp'), '', array('response' => 403));
        }
        $uuid = sanitize_text_field((string) ($_POST['attempt_uuid'] ?? ''));
        $decision = sanitize_key((string) ($_POST['decision'] ?? ''));
        check_admin_referer('ddb_photo_review_' . $uuid);
        if (! preg_match('/^[a-f0-9-]{36}$/', $uuid) || ! in_array($decision, array('approve', 'reject'), true)) {
            wp_die(esc_html__('Ongeldige review.', 'sbdp'), '', array('response' => 400));
        }
        (new PhotoAttemptService())->manualReview($uuid, $decision === 'approve', get_current_user_id());
        wp_safe_redirect(add_query_arg(array('post_type' => 'sbdp_private_tour', 'page' => 'ddb-discovery-camera', 'reviewed' => 1), admin_url('edit.php')));
        exit;
    }

    public static function privateImage(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('', '', array('response' => 403));
        }
        $uuid = sanitize_text_field((string) ($_GET['attempt_uuid'] ?? ''));
        check_admin_referer('ddb_photo_image_' . $uuid);
        $row = null;
        foreach ((new PhotoAttemptService())->recentForAdmin(100) as $attempt) {
            if (hash_equals((string) ($attempt['attempt_uuid'] ?? ''), $uuid)) {
                $row = $attempt;
                break;
            }
        }
        $key = sanitize_file_name((string) ($row['private_object_key'] ?? ''));
        $directory = (string) apply_filters('ddb/discovery_camera/private_directory', dirname(rtrim(ABSPATH, '/\\')) . DIRECTORY_SEPARATOR . 'ddb-private-media');
        $path = $key !== '' && $key === basename($key) ? trailingslashit($directory) . $key : '';
        if ($path === '' || ! is_readable($path)) {
            wp_die('', '', array('response' => 404));
        }
        nocache_headers();
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
    }

    private static function renderReviews(): void
    {
        $attempts = (new PhotoAttemptService())->recentForAdmin();
        ?>
        <hr>
        <h2><?php esc_html_e('Recente fotopogingen', 'sbdp'); ?></h2>
        <?php if ($attempts === array()) : ?>
            <p><?php esc_html_e('Nog geen fotopogingen.', 'sbdp'); ?></p>
            <?php return; ?>
        <?php endif; ?>
        <table class="widefat striped">
            <thead><tr><th><?php esc_html_e('Foto', 'sbdp'); ?></th><th><?php esc_html_e('Tour / hoofdstuk', 'sbdp'); ?></th><th><?php esc_html_e('Status', 'sbdp'); ?></th><th><?php esc_html_e('Score', 'sbdp'); ?></th><th><?php esc_html_e('Actie', 'sbdp'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($attempts as $attempt) :
                $uuid = (string) $attempt['attempt_uuid'];
                $imageUrl = wp_nonce_url(add_query_arg(array('action' => 'ddb_photo_private_image', 'attempt_uuid' => $uuid), admin_url('admin-post.php')), 'ddb_photo_image_' . $uuid);
                ?>
                <tr>
                    <td><?php if (! empty($attempt['private_object_key'])) : ?><img class="ddb-photo-admin-thumb" src="<?php echo esc_url($imageUrl); ?>" alt=""><?php else : ?>—<?php endif; ?></td>
                    <td><?php echo esc_html(get_the_title((int) $attempt['tour_id']) . ' / ' . get_the_title((int) $attempt['step_id'])); ?></td>
                    <td><?php echo esc_html((string) $attempt['status']); ?></td>
                    <td><?php echo $attempt['total_score'] !== null ? esc_html((string) round((float) $attempt['total_score'])) : '—'; ?></td>
                    <td>
                        <?php if (! in_array((string) $attempt['status'], array('passed'), true)) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ddb-photo-review-actions">
                            <input type="hidden" name="action" value="ddb_photo_manual_review">
                            <input type="hidden" name="attempt_uuid" value="<?php echo esc_attr($uuid); ?>">
                            <?php wp_nonce_field('ddb_photo_review_' . $uuid); ?>
                            <button class="button button-primary" name="decision" value="approve"><?php esc_html_e('Goedkeuren', 'sbdp'); ?></button>
                            <button class="button" name="decision" value="reject"><?php esc_html_e('Afwijzen', 'sbdp'); ?></button>
                        </form>
                        <?php else : ?>✓<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
