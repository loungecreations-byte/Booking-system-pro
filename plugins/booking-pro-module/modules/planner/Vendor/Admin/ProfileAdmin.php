<?php

declare(strict_types=1);

namespace BSP\Planner\Vendor\Admin;

use BSP\Planner\Vendor\CityGuideProfileStore;
use WP_Post;

final class ProfileAdmin
{
    public const POST_TYPE = 'bsp_city_guide';
    public const NONCE_ACTION = 'bsp_cityguide_profile_save';
    public const NONCE_FIELD = 'bsp_cityguide_profile_nonce';

    public const META_STATUS = '_bsp_cityguide_status';
    public const META_TIMEZONE = '_bsp_cityguide_timezone';
    public const META_ALLOW_NL_TOURS = '_bsp_cityguide_allow_nl_tours';
    public const META_ICAL = '_bsp_cityguide_ical';
    public const META_NOTE = '_bsp_cityguide_note';
    public const META_LAST_SYNC = '_bsp_cityguide_last_sync';
    public const META_LANGUAGES = '_bsp_cityguide_languages';
    public const META_PROTECTED_LANGUAGES = '_bsp_cityguide_protected_languages';

    /** @var array<string, string> */
    private const STATUS_OPTIONS = array(
        'active'   => 'Actief',
        'inactive' => 'Inactief',
        'draft'    => 'Concept / intern',
    );

    /** @var array<string, string> */
    private const LANGUAGE_OPTIONS = array(
        'nl' => 'Nederlands',
        'en' => 'Engels',
        'de' => 'Duits',
        'fr' => 'Frans',
    );

    public function __construct(private CityGuideProfileStore $store)
    {
    }

    public function hooks(): void
    {
        if (! function_exists('add_action')) {
            return;
        }

        add_action('admin_menu', array($this, 'registerMenu'), 30);
        add_action('add_meta_boxes_' . self::POST_TYPE, array($this, 'registerMetaBox'));
        add_action('save_post_' . self::POST_TYPE, array($this, 'save'), 10, 2);
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', array($this, 'columns'));
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', array($this, 'renderColumn'), 10, 2);
    }

    public function registerMenu(): void
    {
        if (! function_exists('add_submenu_page')) {
            return;
        }

        add_submenu_page(
            'sbdp_bookings',
            __('Gidsen', 'sbdp'),
            __('Gidsen', 'sbdp'),
            $this->capability(),
            'edit.php?post_type=' . self::POST_TYPE
        );
    }

    public function registerMetaBox(): void
    {
        if (! function_exists('add_meta_box')) {
            return;
        }

        add_meta_box(
            'bsp-cityguide-profile',
            __('Gidsprofiel', 'sbdp'),
            array($this, 'renderMetaBox'),
            self::POST_TYPE,
            'normal',
            'high'
        );
    }

    public function renderMetaBox(WP_Post $post): void
    {
        if (function_exists('wp_nonce_field')) {
            wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        }

        $status = $this->metaString((int) $post->ID, self::META_STATUS, 'active');
        $timezone = $this->metaString((int) $post->ID, self::META_TIMEZONE, 'Europe/Amsterdam');
        $icalUrl = $this->metaString((int) $post->ID, self::META_ICAL, '');
        $note = $this->metaString((int) $post->ID, self::META_NOTE, '');
        $lastSync = $this->metaString((int) $post->ID, self::META_LAST_SYNC, '');
        $languages = $this->metaList((int) $post->ID, self::META_LANGUAGES, array('nl'));
        $protectedLanguages = $this->metaList((int) $post->ID, self::META_PROTECTED_LANGUAGES, array());
        $allowNlTours = $this->metaBool((int) $post->ID, self::META_ALLOW_NL_TOURS);

        echo '<p>' . esc_html__('Beheer hier alleen de profieldata die de gids-toewijzing leest. Dit start geen iCal-import en maakt geen boekingen.', 'sbdp') . '</p>';

        echo '<p><label for="bsp_cityguide_status"><strong>' . esc_html__('Status', 'sbdp') . '</strong></label><br>';
        echo '<select id="bsp_cityguide_status" name="bsp_cityguide_status">';
        foreach (self::STATUS_OPTIONS as $value => $label) {
            printf(
                '<option value="%1$s"%3$s>%2$s</option>',
                esc_attr($value),
                esc_html__($label, 'sbdp'),
                selected($status, $value, false)
            );
        }
        echo '</select></p>';

        echo '<fieldset><legend><strong>' . esc_html__('Talen', 'sbdp') . '</strong></legend>';
        foreach (self::LANGUAGE_OPTIONS as $value => $label) {
            printf(
                '<label style="display:inline-block;margin:0 16px 8px 0;"><input type="checkbox" name="bsp_cityguide_languages[]" value="%1$s"%3$s> %2$s</label>',
                esc_attr($value),
                esc_html__($label, 'sbdp'),
                checked(in_array($value, $languages, true), true, false)
            );
        }
        echo '</fieldset>';

        echo '<fieldset style="margin-top:12px;"><legend><strong>' . esc_html__('Beschermde talen', 'sbdp') . '</strong></legend>';
        foreach (self::LANGUAGE_OPTIONS as $value => $label) {
            printf(
                '<label style="display:inline-block;margin:0 16px 8px 0;"><input type="checkbox" name="bsp_cityguide_protected_languages[]" value="%1$s"%3$s> %2$s</label>',
                esc_attr($value),
                esc_html__($label, 'sbdp'),
                checked(in_array($value, $protectedLanguages, true), true, false)
            );
        }
        echo '</fieldset>';

        printf(
            '<p><label><input type="checkbox" name="bsp_cityguide_allow_nl_tours" value="1"%2$s> %1$s</label></p>',
            esc_html__('Mag ook algemene NL-tours ontvangen wanneer beschermde talen zijn ingesteld.', 'sbdp'),
            checked($allowNlTours, true, false)
        );

        printf(
            '<p><label for="bsp_cityguide_timezone"><strong>%1$s</strong></label><br><input type="text" class="regular-text" id="bsp_cityguide_timezone" name="bsp_cityguide_timezone" value="%2$s" placeholder="Europe/Amsterdam"></p>',
            esc_html__('Timezone', 'sbdp'),
            esc_attr($timezone)
        );

        printf(
            '<p><label for="bsp_cityguide_ical"><strong>%1$s</strong></label><br><input type="url" class="large-text" id="bsp_cityguide_ical" name="bsp_cityguide_ical" value="%2$s" placeholder="https://..."></p>',
            esc_html__('iCal URL', 'sbdp'),
            esc_attr($icalUrl)
        );

        printf(
            '<p><label for="bsp_cityguide_note"><strong>%1$s</strong></label><br><textarea class="large-text" rows="4" id="bsp_cityguide_note" name="bsp_cityguide_note">%2$s</textarea></p>',
            esc_html__('Interne notitie', 'sbdp'),
            esc_textarea($note)
        );

        if ($lastSync !== '') {
            printf(
                '<p><strong>%1$s</strong><br><code>%2$s</code></p>',
                esc_html__('Laatst gesynchroniseerd', 'sbdp'),
                esc_html($lastSync)
            );
        }
    }

    public function save(int $postId, WP_Post $post): void
    {
        if ($post->post_type !== self::POST_TYPE || ! $this->canSave($postId)) {
            return;
        }

        update_post_meta($postId, self::META_STATUS, $this->sanitizeStatus($_POST['bsp_cityguide_status'] ?? 'active'));
        update_post_meta($postId, self::META_TIMEZONE, $this->sanitizeTimezone($_POST['bsp_cityguide_timezone'] ?? 'Europe/Amsterdam'));
        update_post_meta($postId, self::META_ICAL, $this->sanitizeUrl($_POST['bsp_cityguide_ical'] ?? ''));
        update_post_meta($postId, self::META_NOTE, $this->sanitizeTextarea($_POST['bsp_cityguide_note'] ?? ''));
        update_post_meta($postId, self::META_LANGUAGES, $this->sanitizeLanguages($_POST['bsp_cityguide_languages'] ?? array(), array('nl')));
        update_post_meta($postId, self::META_PROTECTED_LANGUAGES, $this->sanitizeLanguages($_POST['bsp_cityguide_protected_languages'] ?? array(), array()));

        if (! empty($_POST['bsp_cityguide_allow_nl_tours'])) {
            update_post_meta($postId, self::META_ALLOW_NL_TOURS, '1');
        } else {
            delete_post_meta($postId, self::META_ALLOW_NL_TOURS);
        }
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function columns(array $columns): array
    {
        $title = $columns['title'] ?? __('Naam', 'sbdp');
        $date = $columns['date'] ?? __('Datum', 'sbdp');

        return array(
            'cb' => $columns['cb'] ?? '<input type="checkbox" />',
            'title' => $title,
            'bsp_cityguide_status' => __('Status', 'sbdp'),
            'bsp_cityguide_languages' => __('Talen', 'sbdp'),
            'bsp_cityguide_timezone' => __('Timezone', 'sbdp'),
            'bsp_cityguide_ical' => __('iCal', 'sbdp'),
            'bsp_cityguide_last_sync' => __('Laatst sync', 'sbdp'),
            'date' => $date,
        );
    }

    public function renderColumn(string $column, int $postId): void
    {
        if ($column === 'bsp_cityguide_status') {
            echo esc_html($this->metaString($postId, self::META_STATUS, 'active'));
            return;
        }

        if ($column === 'bsp_cityguide_languages') {
            echo esc_html(implode(', ', $this->metaList($postId, self::META_LANGUAGES, array('nl'))));
            return;
        }

        if ($column === 'bsp_cityguide_timezone') {
            echo esc_html($this->metaString($postId, self::META_TIMEZONE, 'Europe/Amsterdam'));
            return;
        }

        if ($column === 'bsp_cityguide_ical') {
            echo $this->metaString($postId, self::META_ICAL, '') !== '' ? esc_html__('Ja', 'sbdp') : esc_html__('Nee', 'sbdp');
            return;
        }

        if ($column === 'bsp_cityguide_last_sync') {
            $lastSync = $this->metaString($postId, self::META_LAST_SYNC, '');
            echo $lastSync !== '' ? esc_html($lastSync) : '&#8211;';
        }
    }

    private function canSave(int $postId): bool
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return false;
        }

        if (function_exists('wp_is_post_revision') && wp_is_post_revision($postId)) {
            return false;
        }

        $nonce = isset($_POST[self::NONCE_FIELD]) && is_scalar($_POST[self::NONCE_FIELD])
            ? (string) $_POST[self::NONCE_FIELD]
            : '';
        if ($nonce === '' || ! function_exists('wp_verify_nonce') || ! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return false;
        }

        return ! function_exists('current_user_can') || current_user_can($this->capability(), $postId);
    }

    private function capability(): string
    {
        return 'manage_woocommerce';
    }

    private function sanitizeStatus(mixed $value): string
    {
        $status = is_scalar($value) ? strtolower(trim((string) $value)) : '';

        return isset(self::STATUS_OPTIONS[$status]) ? $status : 'active';
    }

    private function sanitizeTimezone(mixed $value): string
    {
        $timezone = is_scalar($value) ? trim((string) $value) : '';
        $timezone = preg_replace('/[^A-Za-z0-9_+\-\/]/', '', $timezone) ?? '';

        if ($timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
            return $timezone;
        }

        return 'Europe/Amsterdam';
    }

    private function sanitizeUrl(mixed $value): string
    {
        $url = is_scalar($value) ? trim((string) $value) : '';
        if ($url === '') {
            return '';
        }

        return function_exists('esc_url_raw') ? esc_url_raw($url) : filter_var($url, FILTER_SANITIZE_URL);
    }

    private function sanitizeTextarea(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : '';

        return function_exists('sanitize_textarea_field') ? sanitize_textarea_field($text) : trim(strip_tags($text));
    }

    /**
     * @param mixed $value
     * @param array<int, string> $default
     * @return array<int, string>
     */
    private function sanitizeLanguages(mixed $value, array $default): array
    {
        $items = is_array($value) ? $value : array($value);
        $allowed = array_keys(self::LANGUAGE_OPTIONS);
        $languages = array_values(
            array_unique(
                array_filter(
                    array_map(
                        static function ($item) use ($allowed): string {
                            $language = is_scalar($item) ? strtolower(trim((string) $item)) : '';

                            return in_array($language, $allowed, true) ? $language : '';
                        },
                        $items
                    )
                )
            )
        );

        return $languages !== array() ? $languages : $default;
    }

    private function metaString(int $postId, string $key, string $default): string
    {
        $value = function_exists('get_post_meta') ? get_post_meta($postId, $key, true) : '';
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value !== '' ? $value : $default;
    }

    /**
     * @param array<int, string> $default
     * @return array<int, string>
     */
    private function metaList(int $postId, string $key, array $default): array
    {
        $value = function_exists('get_post_meta') ? get_post_meta($postId, $key, true) : array();
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : preg_split('/[,;\s]+/', $value);
        }

        return $this->sanitizeLanguages(is_array($value) ? $value : array(), $default);
    }

    private function metaBool(int $postId, string $key): bool
    {
        $value = function_exists('get_post_meta') ? get_post_meta($postId, $key, true) : '';

        return in_array($value, array(true, 1, '1', 'yes', 'true', 'on'), true);
    }
}
