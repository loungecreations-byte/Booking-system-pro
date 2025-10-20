<?php
declare(strict_types=1);

namespace BSP\Planner\Vendor\Admin;

use BSP\Planner\Vendor\CityGuideProfileStore;

final class ProfileAdmin
{
    public function __construct(private CityGuideProfileStore $store)
    {
    }

    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_post_bsp_save_city_guide', [$this, 'handleSubmit']);
    }

    public function registerMenu(): void
    {
        add_submenu_page(
            'options-general.php',
            __('City Guide Availability', 'bsp'),
            __('City Guide Availability', 'bsp'),
            'manage_options',
            'bsp-city-guides',
            [$this, 'renderPage']
        );
    }

    public function renderPage(): void
    {
        $profiles = $this->store->all();
        $action   = admin_url('admin-post.php');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('City Guide Availability', 'bsp'); ?></h1>
            <form method="post" action="<?php echo esc_url($action); ?>">
                <?php wp_nonce_field('bsp_save_city_guide', '_bsp_nonce'); ?>
                <input type="hidden" name="action" value="bsp_save_city_guide" />
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="bsp-guide-name"><?php esc_html_e('Name', 'bsp'); ?></label></th>
                            <td><input name="name" id="bsp-guide-name" type="text" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bsp-guide-ical"><?php esc_html_e('iCal URL', 'bsp'); ?></label></th>
                            <td><input name="ical_url" id="bsp-guide-ical" type="url" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bsp-guide-timezone"><?php esc_html_e('Timezone', 'bsp'); ?></label></th>
                            <td><input name="timezone" id="bsp-guide-timezone" type="text" class="regular-text" value="UTC"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bsp-guide-note"><?php esc_html_e('Notes', 'bsp'); ?></label></th>
                            <td><textarea name="note" id="bsp-guide-note" class="large-text"></textarea></td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button(__('Add City Guide', 'bsp')); ?>
            </form>

            <h2><?php esc_html_e('Existing Guides', 'bsp'); ?></h2>
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Name', 'bsp'); ?></th>
                        <th><?php esc_html_e('Timezone', 'bsp'); ?></th>
                        <th><?php esc_html_e('iCal', 'bsp'); ?></th>
                        <th><?php esc_html_e('Status', 'bsp'); ?></th>
                        <th><?php esc_html_e('Last Sync', 'bsp'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($profiles)) : ?>
                        <tr><td colspan="5"><?php esc_html_e('No guides found.', 'bsp'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($profiles as $profile) : ?>
                            <tr>
                                <td><?php echo esc_html($profile->name); ?></td>
                                <td><?php echo esc_html($profile->timezone); ?></td>
                                <td><a href="<?php echo esc_url($profile->icalUrl); ?>" target="_blank" rel="noopener">iCal</a></td>
                                <td><?php echo esc_html($profile->status); ?></td>
                                <td><?php echo esc_html($profile->lastSync ?? __('Never', 'bsp')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function handleSubmit(): void
    {
        if ( ! current_user_can('manage_options') ) {
            wp_die(__('You are not allowed to perform this action.', 'bsp'));
        }

        check_admin_referer('bsp_save_city_guide', '_bsp_nonce');

        $name     = sanitize_text_field((string) (\filter_input( \INPUT_POST, 'name', \FILTER_SANITIZE_FULL_SPECIAL_CHARS ) ?? ''));
        $ical     = esc_url_raw((string) (\filter_input( \INPUT_POST, 'ical_url', \FILTER_SANITIZE_URL ) ?? ''));
        $timezone = sanitize_text_field((string) (\filter_input( \INPUT_POST, 'timezone', \FILTER_SANITIZE_FULL_SPECIAL_CHARS ) ?? 'UTC'));
        $note     = sanitize_text_field((string) (\filter_input( \INPUT_POST, 'note', \FILTER_SANITIZE_FULL_SPECIAL_CHARS ) ?? ''));

        $data = [
            'name'     => $name,
            'ical_url' => $ical,
            'timezone' => $timezone !== '' ? $timezone : 'UTC',
            'note'     => $note,
            'status'   => 'idle',
        ];

        $this->store->save( $data );

        wp_safe_redirect(add_query_arg('updated', '1', wp_get_referer() ?: admin_url()));
        exit;
    }
}
