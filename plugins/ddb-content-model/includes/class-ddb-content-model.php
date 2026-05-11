<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DDB_Content_Model
{
    private const POST_TYPES = [
        'spots' => [
            'singular' => 'Spot',
            'plural' => 'Spots',
            'slug' => 'spots',
        ],
        'activiteiten' => [
            'singular' => 'Activiteit',
            'plural' => 'Activiteiten',
            'slug' => 'activiteiten',
        ],
        'restaurants' => [
            'singular' => 'Restaurant',
            'plural' => 'Restaurants',
            'slug' => 'restaurants',
        ],
        'deals' => [
            'singular' => 'Deal',
            'plural' => 'Deals',
            'slug' => 'deals',
        ],
        'groepen' => [
            'singular' => 'Groep',
            'plural' => 'Groepen',
            'slug' => 'groepen',
        ],
    ];

    private const META_FIELDS = [
        'ddb_hero_image' => 'Hero image URL',
        'ddb_intro' => 'Intro',
        'ddb_highlights_json' => 'Highlights (JSON)',
        'ddb_usp_blocks_json' => 'USP blocks (JSON)',
        'ddb_faq_json' => 'FAQ (JSON)',
        'ddb_gallery_json' => 'Gallery (JSON)',
        'ddb_cta_json' => 'CTA (JSON)',
        'ddb_reviews_json' => 'Reviews (JSON)',
    ];

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'register_content_menu']);
        add_action('init', [self::class, 'register_post_types']);
        add_action('init', [self::class, 'register_taxonomies']);
        add_action('init', [self::class, 'register_meta_fields']);
        add_action('add_meta_boxes', [self::class, 'register_meta_boxes']);
        add_action('save_post', [self::class, 'save_meta_boxes']);
        add_action('admin_notices', [self::class, 'render_admin_notices']);
        add_filter('redirect_post_location', [self::class, 'append_validation_notice'], 10, 2);
        add_filter('request', [self::class, 'prefer_pages_for_base_slugs']);
    }

    /**
     * Register a parent "Inhoud" menu so all content CPTs are grouped under one sidebar item.
     */
    public static function register_content_menu(): void
    {
        add_menu_page(
            __('DDB Inhoud', 'ddb-content-model'),
            __('Inhoud', 'ddb-content-model'),
            'edit_posts',
            'ddb-content',
            '',
            'dashicons-category',
            22
        );
    }

    public static function register_post_types(): void
    {
        foreach (self::enabled_post_types() as $post_type => $cfg) {
            register_post_type(
                $post_type,
                [
                    'labels' => [
                        'name' => __($cfg['plural'], 'ddb-content-model'),
                        'singular_name' => __($cfg['singular'], 'ddb-content-model'),
                        'add_new_item' => sprintf(__('Nieuwe %s', 'ddb-content-model'), strtolower($cfg['singular'])),
                        'edit_item' => sprintf(__('%s bewerken', 'ddb-content-model'), $cfg['singular']),
                    ],
                    'public' => true,
                    'show_ui' => true,
                    'show_in_menu' => 'ddb-content',
                    'show_in_rest' => true,
                    'menu_position' => 22,
                    'menu_icon' => 'dashicons-admin-post',
                    'has_archive' => true,
                    'rewrite' => ['slug' => $cfg['slug']],
                    'supports' => ['title', 'editor', 'excerpt', 'thumbnail', 'revisions'],
                ]
            );
        }
    }

    public static function register_taxonomies(): void
    {
        $post_types = array_keys(self::enabled_post_types());

        register_taxonomy(
            'categorie',
            array_values(array_intersect(['spots', 'activiteiten', 'restaurants', 'deals', 'groepen'], $post_types)),
            self::taxonomy_args('Categorie', 'Categorieen', 'categorie')
        );

        register_taxonomy(
            'sfeer',
            array_values(array_intersect(['spots', 'activiteiten', 'restaurants'], $post_types)),
            self::taxonomy_args('Sfeer', 'Sferen', 'sfeer')
        );

        register_taxonomy(
            'locatie',
            array_values(array_intersect(['spots', 'activiteiten', 'restaurants', 'deals'], $post_types)),
            self::taxonomy_args('Locatie', 'Locaties', 'locatie')
        );

        register_taxonomy(
            'type_activiteit',
            ['activiteiten', 'groepen'],
            self::taxonomy_args('Type activiteit', 'Type activiteiten', 'type-activiteit')
        );
    }

    private static function taxonomy_args(string $singular, string $plural, string $slug): array
    {
        return [
            'labels' => [
                'name' => __($plural, 'ddb-content-model'),
                'singular_name' => __($singular, 'ddb-content-model'),
                'search_items' => sprintf(__('Zoek %s', 'ddb-content-model'), strtolower($plural)),
                'all_items' => sprintf(__('Alle %s', 'ddb-content-model'), strtolower($plural)),
                'edit_item' => sprintf(__('Bewerk %s', 'ddb-content-model'), strtolower($singular)),
            ],
            'hierarchical' => true,
            'public' => true,
            'show_ui' => true,
            'show_admin_column' => true,
            'show_in_rest' => true,
            'rewrite' => ['slug' => $slug],
        ];
    }

    public static function register_meta_fields(): void
    {
        foreach (array_keys(self::enabled_post_types()) as $post_type) {
            foreach (array_keys(self::META_FIELDS) as $meta_key) {
                register_post_meta(
                    $post_type,
                    $meta_key,
                    [
                        'single' => true,
                        'type' => 'string',
                        'show_in_rest' => true,
                        'sanitize_callback' => static function (mixed $value) use ($meta_key): string {
                            return self::sanitize_meta_value($value, $meta_key) ?? '';
                        },
                        'auth_callback' => static function (): bool {
                            return current_user_can('edit_posts');
                        },
                    ]
                );
            }
        }
    }

    public static function sanitize_meta_value(mixed $value, string $meta_key = ''): ?string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $string_value = (string) $value;
        if (self::is_json_meta_key($meta_key)) {
            return self::sanitize_json_meta_value($string_value);
        }

        return wp_kses_post($string_value);
    }

    public static function register_meta_boxes(): void
    {
        foreach (array_keys(self::enabled_post_types()) as $post_type) {
            add_meta_box(
                'ddb_content_model_meta',
                __('DDB Content Fields', 'ddb-content-model'),
                [self::class, 'render_meta_box'],
                $post_type,
                'normal',
                'high'
            );
        }
    }

    public static function render_meta_box(WP_Post $post): void
    {
        wp_nonce_field('ddb_content_model_meta', 'ddb_content_model_nonce');

        echo '<p>' . esc_html__('Gebruik JSON voor highlights/USP/FAQ/gallery/CTA/reviews.', 'ddb-content-model') . '</p>';
        echo '<table class="form-table" role="presentation"><tbody>';

        foreach (self::META_FIELDS as $key => $label) {
            $value = get_post_meta($post->ID, $key, true);
            echo '<tr>';
            echo '<th scope="row"><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label></th>';
            echo '<td>';
            if ($key === 'ddb_intro') {
                echo '<textarea id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" rows="4" class="large-text">' . esc_textarea((string) $value) . '</textarea>';
            } elseif (self::is_json_meta_key($key)) {
                echo '<textarea id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" rows="8" class="large-text code" spellcheck="false">' . esc_textarea((string) $value) . '</textarea>';
                echo '<p class="description">' . esc_html__('Gebruik geldig JSON. Bij opslaan wordt dit compact genormaliseerd.', 'ddb-content-model') . '</p>';
            } else {
                echo '<input id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" type="text" class="large-text" value="' . esc_attr((string) $value) . '" />';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    public static function save_meta_boxes(int $post_id): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!isset($_POST['ddb_content_model_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ddb_content_model_nonce'])), 'ddb_content_model_meta')) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $post_type = get_post_type($post_id);
        if (!is_string($post_type) || !array_key_exists($post_type, self::POST_TYPES)) {
            return;
        }

        $invalid_fields = [];
        foreach (array_keys(self::META_FIELDS) as $meta_key) {
            $raw = isset($_POST[$meta_key]) ? wp_unslash((string) $_POST[$meta_key]) : '';
            $sanitized = self::sanitize_meta_value($raw, $meta_key);
            if (self::is_json_meta_key($meta_key) && null === $sanitized) {
                $invalid_fields[] = $meta_key;
                continue;
            }
            update_post_meta($post_id, $meta_key, $sanitized);
        }

        self::store_invalid_json_notice($post_id, $invalid_fields);
    }

    public static function prefer_pages_for_base_slugs(array $query_vars): array
    {
        if (!isset($query_vars['post_type']) || !is_string($query_vars['post_type'])) {
            return $query_vars;
        }

        $post_type = $query_vars['post_type'];
        if (!isset(self::POST_TYPES[$post_type])) {
            return $query_vars;
        }

        $request_uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])
            ? (string) wp_unslash($_SERVER['REQUEST_URI'])
            : '';
        if ($request_uri === '') {
            return $query_vars;
        }

        $path = wp_parse_url($request_uri, PHP_URL_PATH);
        if (!is_string($path)) {
            return $query_vars;
        }

        $base_path = trim((string) wp_parse_url(home_url('/' . self::POST_TYPES[$post_type]['slug'] . '/'), PHP_URL_PATH), '/');
        if (trim($path, '/') !== $base_path) {
            return $query_vars;
        }

        $page = get_page_by_path(self::POST_TYPES[$post_type]['slug']);
        if (!$page instanceof WP_Post || $page->post_status !== 'publish') {
            return $query_vars;
        }

        return [
            'page_id' => $page->ID,
        ];
    }

    public static function append_validation_notice(string $location, int $post_id): string
    {
        $invalid_fields = self::consume_invalid_json_notice($post_id, false);
        if ($invalid_fields === []) {
            return $location;
        }

        return add_query_arg('ddb_content_model_json_error', '1', $location);
    }

    public static function render_admin_notices(): void
    {
        if (!isset($_GET['ddb_content_model_json_error']) || !isset($_GET['post'])) {
            return;
        }

        $post_id = absint((string) wp_unslash($_GET['post']));
        if ($post_id <= 0) {
            return;
        }

        $invalid_fields = self::consume_invalid_json_notice($post_id, true);
        if ($invalid_fields === []) {
            return;
        }

        $labels = array_map(
            static function (string $meta_key): string {
                return (string) (self::META_FIELDS[$meta_key] ?? $meta_key);
            },
            $invalid_fields
        );

        echo '<div class="notice notice-warning is-dismissible"><p>' .
            esc_html(sprintf(__('Deze JSON-velden zijn niet opgeslagen omdat de JSON ongeldig is: %s', 'ddb-content-model'), implode(', ', $labels))) .
            '</p></div>';
    }

    private static function enabled_post_types(): array
    {
        $post_types = self::POST_TYPES;

        if (self::should_delegate_spots_to_canonical_model()) {
            unset($post_types['spots']);
        }

        return $post_types;
    }

    private static function should_delegate_spots_to_canonical_model(): bool
    {
        if (!defined('DDB_SPOTS_VERSION') && !class_exists('DDB_Spots')) {
            return false;
        }

        $counts = wp_count_posts('spots');
        if (!is_object($counts)) {
            return true;
        }

        foreach (get_object_vars($counts) as $count) {
            if ((int) $count > 0) {
                return false;
            }
        }

        return true;
    }

    private static function is_json_meta_key(string $meta_key): bool
    {
        return str_ends_with($meta_key, '_json');
    }

    private static function sanitize_json_meta_value(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return null;
        }

        return wp_json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    private static function store_invalid_json_notice(int $post_id, array $invalid_fields): void
    {
        if ($post_id <= 0) {
            return;
        }

        $transient_key = self::invalid_json_transient_key($post_id);
        if ($invalid_fields === []) {
            delete_transient($transient_key);
            return;
        }

        set_transient($transient_key, array_values(array_unique($invalid_fields)), MINUTE_IN_SECONDS * 5);
    }

    private static function consume_invalid_json_notice(int $post_id, bool $delete = true): array
    {
        if ($post_id <= 0) {
            return [];
        }

        $transient_key = self::invalid_json_transient_key($post_id);
        $value = get_transient($transient_key);
        if ($delete) {
            delete_transient($transient_key);
        }

        return is_array($value) ? array_values(array_filter(array_map('strval', $value))) : [];
    }

    private static function invalid_json_transient_key(int $post_id): string
    {
        return 'ddb_content_model_invalid_json_' . get_current_user_id() . '_' . $post_id;
    }
}
