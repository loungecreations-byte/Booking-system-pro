<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DDB_MegaMenu_Admin
{
    private DDB_MegaMenu $plugin;

    public function __construct(DDB_MegaMenu $plugin)
    {
        $this->plugin = $plugin;

        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function register_menu(): void
    {
        add_options_page(
            'DDB Mega Menu',
            'DDB Mega Menu',
            'manage_options',
            'ddb-mega-menu',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings(): void
    {
        register_setting(
            'ddb_megamenu_settings_group',
            DDB_MEGAMENU_OPTION_KEY,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default' => $this->plugin->get_defaults(),
            ]
        );

        add_settings_section(
            'ddb_megamenu_main_section',
            'Mega Menu instellingen',
            static function (): void {
                echo '<p>Configureer logo, CTA, defaults en optionele menu overrides.</p>';
            },
            'ddb-mega-menu'
        );

        $this->add_text_field('logo_url', 'Logo URL', 'https://.../logo.svg');
        $this->add_text_field('cta_label', 'CTA label', 'Plan je dag');
        $this->add_text_field('cta_url', 'CTA URL', '/plan-je-dag/');

        $this->add_checkbox_field('enable_sticky_header', 'Enable sticky header');
        $this->add_checkbox_field('enable_transparent_header_home', 'Enable transparent header on homepage');
        $this->add_checkbox_field('enable_mobile_bottom_bar', 'Enable mobile bottom bar');

        add_settings_field(
            'default_theme_mode',
            'Default theme mode',
            [$this, 'render_theme_mode_field'],
            'ddb-mega-menu',
            'ddb_megamenu_main_section'
        );

        add_settings_field(
            'custom_menu_structure_json',
            'Mega menu structuur (visual builder)',
            [$this, 'render_menu_structure_field'],
            'ddb-mega-menu',
            'ddb_megamenu_main_section'
        );

        add_settings_field(
            'custom_menu_json',
            'Custom menu labels/URLs (JSON)',
            [$this, 'render_custom_json_field'],
            'ddb-mega-menu',
            'ddb_megamenu_main_section'
        );
    }

    private function add_text_field(string $key, string $label, string $placeholder = ''): void
    {
        add_settings_field(
            $key,
            $label,
            [$this, 'render_text_field'],
            'ddb-mega-menu',
            'ddb_megamenu_main_section',
            [
                'key' => $key,
                'placeholder' => $placeholder,
            ]
        );
    }

    private function add_checkbox_field(string $key, string $label): void
    {
        add_settings_field(
            $key,
            $label,
            [$this, 'render_checkbox_field'],
            'ddb-mega-menu',
            'ddb_megamenu_main_section',
            [
                'key' => $key,
            ]
        );
    }

    public function sanitize_settings(mixed $input): array
    {
        $current = $this->plugin->get_settings();
        $defaults = $this->plugin->get_defaults();

        if (!is_array($input)) {
            $input = [];
        }

        $output = wp_parse_args($input, $defaults);

        $output['logo_url'] = esc_url_raw((string) ($output['logo_url'] ?? ''));
        $output['cta_label'] = sanitize_text_field((string) ($output['cta_label'] ?? $defaults['cta_label']));
        $output['cta_url'] = esc_url_raw((string) ($output['cta_url'] ?? $defaults['cta_url']));

        $output['enable_sticky_header'] = !empty($input['enable_sticky_header']) ? 1 : 0;
        $output['enable_transparent_header_home'] = !empty($input['enable_transparent_header_home']) ? 1 : 0;
        $output['enable_mobile_bottom_bar'] = !empty($input['enable_mobile_bottom_bar']) ? 1 : 0;

        $theme_mode = sanitize_key((string) ($output['default_theme_mode'] ?? 'auto'));
        $output['default_theme_mode'] = in_array($theme_mode, ['auto', 'light', 'dark'], true) ? $theme_mode : 'auto';

        $custom_structure_json = trim((string) ($output['custom_menu_structure_json'] ?? ''));
        if ($custom_structure_json !== '') {
            $decoded_structure = json_decode($custom_structure_json, true);
            if (!is_array($decoded_structure)) {
                add_settings_error(
                    DDB_MEGAMENU_OPTION_KEY,
                    'ddb_megamenu_structure_json_invalid',
                    'Mega menu structuur JSON is ongeldig. De vorige geldige waarde is behouden.',
                    'error'
                );
                $custom_structure_json = (string) ($current['custom_menu_structure_json'] ?? '');
            } else {
                $sanitized_structure = DDB_MegaMenu_Data::sanitize_menu_items($decoded_structure);
                if (empty($sanitized_structure)) {
                    add_settings_error(
                        DDB_MEGAMENU_OPTION_KEY,
                        'ddb_megamenu_structure_json_empty',
                        'Mega menu structuur bevat geen geldige items. De vorige waarde is behouden.',
                        'error'
                    );
                    $custom_structure_json = (string) ($current['custom_menu_structure_json'] ?? '');
                } else {
                    $custom_structure_json = (string) wp_json_encode($sanitized_structure, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
        }
        $output['custom_menu_structure_json'] = $custom_structure_json;

        $custom_json = trim((string) ($output['custom_menu_json'] ?? ''));
        if ($custom_json !== '') {
            json_decode($custom_json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                add_settings_error(
                    DDB_MEGAMENU_OPTION_KEY,
                    'ddb_megamenu_json_invalid',
                    'Custom menu JSON is ongeldig. De vorige geldige waarde is behouden.',
                    'error'
                );
                $custom_json = (string) ($current['custom_menu_json'] ?? '');
            }
        }

        $output['custom_menu_json'] = $custom_json;

        return $output;
    }

    public function render_text_field(array $args): void
    {
        $settings = $this->plugin->get_settings();
        $key = (string) ($args['key'] ?? '');
        $placeholder = (string) ($args['placeholder'] ?? '');
        $value = (string) ($settings[$key] ?? '');

        printf(
            '<input type="text" class="regular-text" name="%1$s[%2$s]" id="%2$s" value="%3$s" placeholder="%4$s" />',
            esc_attr(DDB_MEGAMENU_OPTION_KEY),
            esc_attr($key),
            esc_attr($value),
            esc_attr($placeholder)
        );
    }

    public function render_checkbox_field(array $args): void
    {
        $settings = $this->plugin->get_settings();
        $key = (string) ($args['key'] ?? '');
        $checked = !empty($settings[$key]);

        printf(
            '<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> Ingeschakeld</label>',
            esc_attr(DDB_MEGAMENU_OPTION_KEY),
            esc_attr($key),
            checked(true, $checked, false)
        );
    }

    public function render_theme_mode_field(): void
    {
        $settings = $this->plugin->get_settings();
        $value = (string) ($settings['default_theme_mode'] ?? 'auto');
        ?>
        <select name="<?php echo esc_attr(DDB_MEGAMENU_OPTION_KEY); ?>[default_theme_mode]" id="default_theme_mode">
            <option value="auto" <?php selected($value, 'auto'); ?>>Auto</option>
            <option value="light" <?php selected($value, 'light'); ?>>Light</option>
            <option value="dark" <?php selected($value, 'dark'); ?>>Dark</option>
        </select>
        <?php
    }

    public function render_menu_structure_field(): void
    {
        $settings = $this->plugin->get_settings();
        $value = (string) ($settings['custom_menu_structure_json'] ?? '');
        ?>
        <div id="ddb-mega-menu-builder" class="ddb-mm-builder" data-field-id="custom_menu_structure_json"></div>
        <textarea
            name="<?php echo esc_attr(DDB_MEGAMENU_OPTION_KEY); ?>[custom_menu_structure_json]"
            id="custom_menu_structure_json"
            rows="14"
            class="large-text code ddb-mm-builder__json"
            placeholder='[{"id":"ontdek","label":"Ontdek","url":"/ontdek/","kind":"mega","columns":[{"title":"Highlights","links":[{"label":"Top Spots","url":"/ontdek/top-spots/"}]}]}]'
        ><?php echo esc_textarea($value); ?></textarea>
        <p class="description">Gebruik de visual builder hierboven. De JSON blijft zichtbaar als fallback/export.</p>
        <?php
    }

    public function render_custom_json_field(): void
    {
        $settings = $this->plugin->get_settings();
        $value = (string) ($settings['custom_menu_json'] ?? '');
        ?>
        <textarea
            name="<?php echo esc_attr(DDB_MEGAMENU_OPTION_KEY); ?>[custom_menu_json]"
            id="custom_menu_json"
            rows="8"
            class="large-text code"
            placeholder='{"activiteiten": {"label": "Doen", "url": "/doen/"}}'
        ><?php echo esc_textarea($value); ?></textarea>
        <p class="description">Gebruik JSON om top-level menu labels/URLs te overriden op basis van item-id.</p>
        <?php
    }

    public function enqueue_assets(string $hook): void
    {
        if ($hook !== 'settings_page_ddb-mega-menu') {
            return;
        }

        wp_enqueue_media();

        $css_path = DDB_MEGAMENU_PATH . 'assets/admin/megamenu-admin.css';
        $js_path = DDB_MEGAMENU_PATH . 'assets/admin/megamenu-admin.js';
        $css_ver = file_exists($css_path) ? (string) filemtime($css_path) : DDB_MEGAMENU_VERSION;
        $js_ver = file_exists($js_path) ? (string) filemtime($js_path) : DDB_MEGAMENU_VERSION;

        wp_enqueue_style(
            'ddb-mega-menu-admin',
            DDB_MEGAMENU_URL . 'assets/admin/megamenu-admin.css',
            [],
            $css_ver
        );
        wp_enqueue_script(
            'ddb-mega-menu-admin',
            DDB_MEGAMENU_URL . 'assets/admin/megamenu-admin.js',
            [],
            $js_ver,
            true
        );

        $settings = $this->plugin->get_settings();
        $defaults = DDB_MegaMenu_Data::get_default_menu_items();

        wp_localize_script(
            'ddb-mega-menu-admin',
            'DDBMegaMenuAdminConfig',
            [
                'defaultItems' => $defaults,
                'storedItems' => DDB_MegaMenu_Data::sanitize_menu_items(
                    json_decode((string) ($settings['custom_menu_structure_json'] ?? ''), true) ?: []
                ),
            ]
        );
    }

    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        ?>
        <div class="wrap">
            <h1>DDB Mega Menu</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('ddb_megamenu_settings_group');
                do_settings_sections('ddb-mega-menu');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}
