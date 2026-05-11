<?php

/**
 * Elementor integration for private tours.
 *
 * @package Booking_Pro_Module
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Hooks Elementor support into SBDP private tours.
 */
class SBDP_Private_Tours_Elementor
{
    /**
     * Register the Elementor integration hooks.
     */
    public static function init(): void
    {
        // CRITICAL: Clean up template type IMMEDIATELY before anything else
        add_action('plugins_loaded', array( __CLASS__, 'emergency_cleanup_template_type' ), 1);

        add_filter('elementor_cpt_support_types', array( __CLASS__, 'register_cpt_support' ));
        add_filter('elementor/settings/controls/checkbox_list_cpt/post_type_objects', array( __CLASS__, 'register_elementor_settings_post_types' ));
        add_action('elementor/init', array( __CLASS__, 'bootstrap_widgets' ));
        add_action('elementor/documents/register', array( __CLASS__, 'register_documents' ));
        add_action('init', array( __CLASS__, 'ensure_cpt_support' ), 20);
        add_filter('elementor_pro/utils/get_public_post_types', array( __CLASS__, 'register_theme_builder_post_types' ));
        add_action('elementor/theme/register_conditions', array( __CLASS__, 'register_theme_builder_conditions' ));
        add_action('init', array( __CLASS__, 'ensure_editor_meta_defaults' ), 25);
        add_filter('elementor/editor/localize_settings', array( __CLASS__, 'fix_editor_config' ), 10);
        add_action('elementor/editor/before_enqueue_scripts', array( __CLASS__, 'enqueue_editor_fix' ));

        // Force private tours to use wp-post document type instead of custom theme builder
        add_filter('elementor/document/config', array( __CLASS__, 'force_document_type' ), 999);
        add_filter('update_post_metadata', array( __CLASS__, 'prevent_custom_template_type' ), 10, 5);

        // Override document class detection
        add_filter('elementor/documents/get/post', array( __CLASS__, 'override_document_class' ), 999, 2);

        // Clean up on every editor load
        add_action('elementor/editor/before_enqueue_scripts', array( __CLASS__, 'cleanup_template_type' ));
    }

    /**
     * Enqueue JavaScript fix for Elementor Pro theme_builder error.
     */
    public static function enqueue_editor_fix(): void
    {
        wp_add_inline_script(
            'elementor-editor',
            "(function(){
                function ensureDocumentConfig() {
                    if (typeof elementor === 'undefined' || !elementor.config) {
                        return null;
                    }

                    if (!elementor.config.document || typeof elementor.config.document !== 'object') {
                        elementor.config.document = {};
                    }

                    var doc = elementor.config.document;
                    if (typeof doc.theme_builder === 'undefined' || doc.theme_builder === null || doc.theme_builder === false) {
                        doc.theme_builder = { settings: { conditions: [] } };
                    } else if (typeof doc.theme_builder === 'object') {
                        if (!doc.theme_builder.settings || typeof doc.theme_builder.settings !== 'object') {
                            doc.theme_builder.settings = {};
                        }
                        if (!Array.isArray(doc.theme_builder.settings.conditions)) {
                            doc.theme_builder.settings.conditions = [];
                        }
                    }

                    return doc;
                }

                function shouldAllowLoopBuilder(doc) {
                    if (!doc || typeof doc !== 'object') {
                        return false;
                    }

                    var postType = doc.post_type || doc.postType || '';
                    return postType === 'sbdp_private_tour' || postType === 'sbdp_tour_step' || postType === 'sbdp_private_tour_step';
                }

                function patchLoopBuilder() {
                    try {
                        if (typeof elementorPro === 'undefined' || !elementorPro.modules || !elementorPro.modules.loopBuilder) {
                            return;
                        }

                        var loopBuilder = elementorPro.modules.loopBuilder;
                        if (!loopBuilder || typeof loopBuilder.createDocumentSaveHandles !== 'function' || loopBuilder.__ddbSafePatched) {
                            return;
                        }

                        var originalFunc = loopBuilder.createDocumentSaveHandles;
                        loopBuilder.createDocumentSaveHandles = function() {
                            try {
                                var doc = ensureDocumentConfig();
                                if (!shouldAllowLoopBuilder(doc)) {
                                    return;
                                }
                                return originalFunc.apply(this, arguments);
                            } catch (error) {
                                if (window && window.console && typeof window.console.warn === 'function') {
                                    window.console.warn('[SBDP] Skipping loopBuilder save handles due to config mismatch.', error);
                                }
                                return;
                            }
                        };

                        loopBuilder.__ddbSafePatched = true;
                    } catch (error) {
                        if (window && window.console && typeof window.console.warn === 'function') {
                            window.console.warn('[SBDP] Failed to patch loopBuilder safely.', error);
                        }
                    }
                }

                ensureDocumentConfig();
                jQuery(window).on('elementor:init', function() {
                    ensureDocumentConfig();
                    patchLoopBuilder();
                });
            })();",
            'before'
        );
    }

    /**
     * Ensure the Elementor widget registration happens only when Elementor is active.
     */
    public static function bootstrap_widgets(): void
    {
        add_action('elementor/widgets/register', array( __CLASS__, 'register_widgets' ));
    }

    /**
     * Register custom Elementor documents for the Site Editor.
     *
     * @param \Elementor\Core\Documents_Manager $documents_manager Documents manager.
     */
    public static function register_documents($documents_manager): void
    {
        // Disabled: The theme builder document causes issues with regular posts
        // It expects theme_builder config that doesn't exist for normal posts
        // Private tours can use regular Elementor editing without theme builder

        /*
        if ( ! class_exists( '\ElementorPro\Modules\ThemeBuilder\Documents\Single_Base' ) ) {
            return;
        }

        require_once SBDP_DIR . 'includes/elementor/class-sbdp-elementor-private-tour-document.php';

        if ( class_exists( 'SBDP_Elementor_Private_Tour_Document' ) ) {
            $documents_manager->register_document_type(
                SBDP_Elementor_Private_Tour_Document::get_type(),
                SBDP_Elementor_Private_Tour_Document::class
            );
        }
        */
    }

    /**
     * Register the custom Elementor widgets.
     *
     * @param \Elementor\Widgets_Manager $widgets_manager Elementor widgets manager.
     */
    public static function register_widgets($widgets_manager): void
    {
        if (! class_exists('\Elementor\Widget_Base')) {
            return;
        }

        require_once SBDP_DIR . 'includes/elementor/class-sbdp-elementor-tour-meta-widget.php';
        require_once SBDP_DIR . 'includes/elementor/class-sbdp-elementor-tour-steps-widget.php';
        require_once SBDP_DIR . 'includes/elementor/class-sbdp-elementor-tour-navigation-widget.php';

        $widgets_manager->register(new SBDP_Elementor_Tour_Meta_Widget());
        $widgets_manager->register(new SBDP_Elementor_Tour_Steps_Widget());
        $widgets_manager->register(new SBDP_Elementor_Tour_Navigation_Widget());
    }

    /**
     * Add private tour post types to Elementor-supported CPTs.
     *
     * @param array<int, string> $types Supported types.
     *
     * @return array<int, string>
     */
    public static function register_cpt_support(array $types): array
    {
        $types[] = SBDP_Private_Tours::POST_TYPE_TOUR;
        $types[] = SBDP_Private_Tours::POST_TYPE_TOUR_STEP;

        return array_values(array_unique($types));
    }

    /**
     * Ensure Elementor can edit the private tour post types in the backend.
     */
    public static function ensure_cpt_support(): void
    {
        $types = array(
            SBDP_Private_Tours::POST_TYPE_TOUR,
            SBDP_Private_Tours::POST_TYPE_TOUR_STEP,
        );

        foreach ($types as $type) {
            if (post_type_exists($type)) {
                add_post_type_support($type, 'elementor');
            }
        }
    }

    /**
     * Backfill missing Elementor edit mode metadata for private tours.
     *
     * Some older tour posts were created before Elementor support was normalized,
     * which makes the editor entrypoint inconsistent even though the CPT is supported.
     */
    public static function ensure_editor_meta_defaults(): void
    {
        $types = array(
            SBDP_Private_Tours::POST_TYPE_TOUR,
            SBDP_Private_Tours::POST_TYPE_TOUR_STEP,
        );

        foreach ($types as $type) {
            if (! post_type_exists($type)) {
                continue;
            }

            $posts = get_posts(array(
                'post_type'      => $type,
                'post_status'    => array('publish', 'draft', 'pending', 'private'),
                'numberposts'    => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'cache_results'  => false,
                'suppress_filters' => true,
            ));

            foreach ($posts as $post_id) {
                $post_id = (int) $post_id;
                if ($post_id <= 0) {
                    continue;
                }

                $edit_mode = get_post_meta($post_id, '_elementor_edit_mode', true);
                if ($edit_mode === '') {
                    update_post_meta($post_id, '_elementor_edit_mode', 'builder');
                }
            }
        }
    }

    /**
     * Include private tours in Elementor settings post type list.
     *
     * @param array<string, \WP_Post_Type> $post_types_objects Post type objects.
     *
     * @return array<string, \WP_Post_Type>
     */
    public static function register_elementor_settings_post_types(array $post_types_objects): array
    {
        $types = array(
            SBDP_Private_Tours::POST_TYPE_TOUR,
            SBDP_Private_Tours::POST_TYPE_TOUR_STEP,
        );

        foreach ($types as $type) {
            if (! isset($post_types_objects[ $type ])) {
                $object = get_post_type_object($type);
                if ($object) {
                    $post_types_objects[ $type ] = $object;
                }
            }
        }

        return $post_types_objects;
    }

    /**
     * Ensure private tours appear in Elementor Pro Theme Builder conditions.
     *
     * @param array<string, string> $post_types Public post type labels.
     *
     * @return array<string, string>
     */
    public static function register_theme_builder_post_types(array $post_types): array
    {
        $post_type = SBDP_Private_Tours::POST_TYPE_TOUR;
        if (! isset($post_types[ $post_type ])) {
            $object = get_post_type_object($post_type);
            $post_types[ $post_type ] = $object && isset($object->label)
                ? $object->label
                : __('Private Tours', 'sbdp');
        }

        return $post_types;
    }

    /**
     * Register explicit Theme Builder conditions for private tour content types.
     *
     * @param object $conditions_manager Elementor Pro conditions manager.
     */
    public static function register_theme_builder_conditions($conditions_manager): void
    {
        if (! class_exists('\ElementorPro\Modules\ThemeBuilder\Conditions\Post')) {
            return;
        }

        if (! is_object($conditions_manager) || ! method_exists($conditions_manager, 'get_condition')) {
            return;
        }

        $singular = $conditions_manager->get_condition('singular');
        if (! $singular || ! method_exists($singular, 'register_sub_condition')) {
            return;
        }

        $types = array(
            SBDP_Private_Tours::POST_TYPE_TOUR,
            SBDP_Private_Tours::POST_TYPE_TOUR_STEP,
        );

        foreach ($types as $type) {
            if (! post_type_exists($type)) {
                continue;
            }

            $condition = new \ElementorPro\Modules\ThemeBuilder\Conditions\Post(
                array(
                    'post_type' => $type,
                )
            );
            $singular->register_sub_condition($condition);
        }
    }

    /**
     * Fix Elementor editor config to prevent theme_builder undefined errors.
     *
     * @param array $settings Editor settings.
     * @return array
     */
    public static function fix_editor_config(array $settings): array
    {
        // Ensure document config exists
        if (! isset($settings['document']) || ! is_array($settings['document'])) {
            return $settings;
        }

        // If editing a private tour post (not a theme builder template)
        if (isset($settings['document']['id'])) {
            $post_id = (int) $settings['document']['id'];
            $post_type = get_post_type($post_id);

            // Only fix for private tour posts
            if ($post_type === SBDP_Private_Tours::POST_TYPE_TOUR) {
                // ALWAYS set theme_builder to false for private tours
                // This prevents Elementor Pro from trying to access theme_builder properties
                $settings['document']['theme_builder'] = false;

                // Also ensure document type is wp-post
                $settings['document']['type'] = 'wp-post';
            }
        }

        return $settings;
    }

    /**
     * Force private tours to use wp-post document type.
     *
     * @param array $config Document config.
     * @return array
     */
    public static function force_document_type(array $config): array
    {
        if (! isset($config['id'])) {
            return $config;
        }

        $post_type = get_post_type($config['id']);

        // For private tour posts, force document type to wp-post
        if ($post_type === SBDP_Private_Tours::POST_TYPE_TOUR) {
            $config['type'] = 'wp-post';

            // Remove theme_builder config entirely
            unset($config['theme_builder']);
        }

        return $config;
    }

    /**
     * Prevent saving custom template type for private tours.
     *
     * @param null|bool $check      Whether to allow updating metadata.
     * @param int       $object_id  Post ID.
     * @param string    $meta_key   Metadata key.
     * @param mixed     $meta_value Metadata value.
     * @param mixed     $prev_value Previous value.
     * @return null|bool
     */
    public static function prevent_custom_template_type($check, $object_id, $meta_key, $meta_value, $prev_value)
    {
        // Only intercept _elementor_template_type updates
        if ($meta_key !== '_elementor_template_type') {
            return $check;
        }

        $post_type = get_post_type($object_id);

        // For private tour posts, force wp-post type
        if ($post_type === SBDP_Private_Tours::POST_TYPE_TOUR && $meta_value === 'sbdp_private_tour') {
            // Change the value to wp-post
            update_metadata('post', $object_id, '_elementor_template_type', 'wp-post');
            return true; // Prevent the original update
        }

        return $check;
    }

    /**
     * Override document class to prevent theme builder document usage.
     *
     * @param \Elementor\Core\Base\Document|null $document Document instance.
     * @param int                                 $post_id  Post ID.
     * @return \Elementor\Core\Base\Document|null
     */
    public static function override_document_class($document, $post_id)
    {
        $post_type = get_post_type($post_id);

        // For private tour posts, force WP_Post document class
        if ($post_type === SBDP_Private_Tours::POST_TYPE_TOUR) {
            // Delete any custom template type
            delete_post_meta($post_id, '_elementor_template_type');

            // Return null to let Elementor create default WP_Post document
            return null;
        }

        return $document;
    }

    /**
     * Cleanup template type on editor load.
     */
    public static function cleanup_template_type(): void
    {
        if (! isset($_GET['post'])) {
            return;
        }

        $post_id = (int) $_GET['post'];
        $post_type = get_post_type($post_id);

        // For private tour posts, ensure correct template type
        if ($post_type === SBDP_Private_Tours::POST_TYPE_TOUR) {
            $template_type = get_post_meta($post_id, '_elementor_template_type', true);

            // Remove incorrect template types
            if ($template_type === 'sbdp_private_tour' || $template_type === 'single') {
                delete_post_meta($post_id, '_elementor_template_type');
            }
        }
    }

    /**
     * Emergency cleanup - runs VERY early before Elementor loads.
     */
    public static function emergency_cleanup_template_type(): void
    {
        global $wpdb;

        // Only run in admin or when Elementor is loading
        if (! is_admin() && ! isset($_GET['elementor-preview']) && ! isset($_GET['action'])) {
            return;
        }

        // Delete ALL incorrect template types for private tours in one query
        $wpdb->query($wpdb->prepare(
            "DELETE pm FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			WHERE p.post_type = %s
			AND pm.meta_key = '_elementor_template_type'
			AND pm.meta_value != ''",
            SBDP_Private_Tours::POST_TYPE_TOUR
        ));
    }
}
