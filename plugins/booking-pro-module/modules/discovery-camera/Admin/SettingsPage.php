<?php

declare(strict_types=1);

namespace BSP\DiscoveryCamera\Admin;

use BSP\DiscoveryCamera\Support\FeatureFlags;

final class SettingsPage
{
    public static function register(): void
    {
        add_action('admin_menu', array(__CLASS__, 'menu'), 30);
        add_action('admin_init', array(__CLASS__, 'settings'));
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
        </div>
        <?php
    }
}
