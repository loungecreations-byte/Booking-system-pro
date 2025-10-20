<?php
/**
 * Core plugin bootstrap.
 *
 * @package SBDP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SBDP_Plugin {

    const MIN_WP_VERSION        = '6.0';
    const MIN_PHP_VERSION       = '7.4';
    const MIN_WC_VERSION        = '8.0';
    const MIN_ELEMENTOR_VERSION = '3.18.0';

    /**
     * Whether the bootstrap ran.
     *
     * @var bool
     */
    private static $booted = false;

    /**
     * Fatal admin notice message.
     *
     * @var string
     */
    private static $notice = '';

    /**
     * Non-fatal warnings to surface in the dashboard.
     *
     * @var string[]
     */
    private static $warnings = [];

    /**
     * Kick off the plugin bootstrap.
     */
    public static function boot() {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;

        require_once SBDP_DIR . 'includes/class-cpt.php';
        if ( class_exists( 'SBDP_CPT' ) ) {
            SBDP_CPT::register();
            SBDP_CPT::init();
        }

        add_action( 'plugins_loaded', [ __CLASS__, 'init' ], 5 );
        add_action( 'admin_notices', [ __CLASS__, 'maybe_render_notice' ] );
        add_filter( 'plugin_action_links_' . plugin_basename( SBDP_FILE ), [ __CLASS__, 'register_plugin_links' ] );
    }

    /**
     * Initialise the plugin once WordPress is ready.
     */
    public static function init() {
        if ( ! self::is_environment_compatible() ) {
            return;
        }

        self::load_textdomain();
        self::include_modules();
        self::register_modules();
        self::bootstrap_integrations();

        add_filter( 'rest_authentication_errors', [ __CLASS__, 'maybe_allow_public_rest' ], 999 );
    }

    /**
     * Load the plugin textdomain.
     */
    private static function load_textdomain() {
        load_plugin_textdomain( 'sbdp', false, dirname( plugin_basename( SBDP_FILE ) ) . '/languages' );
    }

    /**
     * Include all module classes.
     */
    private static function include_modules() {
        $files = [
            'class-cpt.php',
            'class-product-meta.php',
            'class-product-type.php',
            'class-resource-meta.php',
            'class-admin-menu.php',
            'class-admin-scheduler.php',
            'class-rest.php',
            'class-shortcodes.php',
            'class-enqueue.php',
            'class-emails.php',
            'class-meta-display.php',
            'class-elementor.php',
        ];

        foreach ( $files as $relative_path ) {
            $path = SBDP_DIR . 'includes/' . $relative_path;
            if ( file_exists( $path ) ) {
                require_once $path;
            }
        }
    }

    /**
     * Register hooks for the loaded modules.
     */
    private static function register_modules() {
        foreach ( self::modules_map() as $class => $method ) {
            if ( ! class_exists( $class ) ) {
                continue;
            }

            if ( $method && method_exists( $class, $method ) ) {
                call_user_func( [ $class, $method ] );
            }
        }
    }

    /**
     * Register optional integrations (Elementor, etc.).
     */
    private static function bootstrap_integrations() {
        if ( class_exists( 'SBDP_Elementor_Integration' ) && self::is_elementor_ready() ) {
            SBDP_Elementor_Integration::init();
        }
    }

    /**
     * Return the list of module classes to initialise.
     *
     * @return array<string,string|null>
     */
    private static function modules_map() {
        return [
            'SBDP_Product_Meta'    => null,
            'SBDP_Product_Type'    => 'init',
            'SBDP_Resource_Meta'   => 'init',
            'SBDP_Admin_Menu'      => 'init',
            'SBDP_Admin_Scheduler' => 'init',
            'SBDP_Enqueue'         => 'init',
            'SBDP_Shortcodes'      => 'init',
            'SBDP_REST'            => 'init',
            'SBDP_Emails'          => 'init',
            'SBDP_Meta_Display'    => 'init',
        ];
    }

    /**
     * Ensure WordPress, WooCommerce and PHP meet the minimum supported versions.
     *
     * @return bool
     */
    private static function is_environment_compatible() {
        global $wp_version;

        if ( version_compare( PHP_VERSION, self::MIN_PHP_VERSION, '<' ) ) {
            self::$notice = sprintf(
                /* translators: 1: required PHP version, 2: current PHP version */
                __( 'Booking System Pro requires at least PHP %1$s. Current version: %2$s.', 'sbdp' ),
                self::MIN_PHP_VERSION,
                PHP_VERSION
            );
            return false;
        }

        if ( version_compare( $wp_version, self::MIN_WP_VERSION, '<' ) ) {
            self::$notice = sprintf(
                /* translators: 1: required WordPress version, 2: current WordPress version */
                __( 'Booking System Pro requires at least WordPress %1$s. Current version: %2$s.', 'sbdp' ),
                self::MIN_WP_VERSION,
                $wp_version
            );
            return false;
        }

        if ( ! class_exists( 'WooCommerce' ) ) {
            self::$notice = __( 'Booking System Pro requires WooCommerce to be active.', 'sbdp' );
            return false;
        }

        if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, self::MIN_WC_VERSION, '<' ) ) {
            self::$notice = sprintf(
                /* translators: 1: required WooCommerce version, 2: current WooCommerce version */
                __( 'Booking System Pro requires at least WooCommerce %1$s. Current version: %2$s.', 'sbdp' ),
                self::MIN_WC_VERSION,
                WC_VERSION
            );
            return false;
        }

        self::$notice = '';

        if ( self::is_elementor_loaded() && ! self::is_elementor_ready() ) {
            self::add_warning(
                sprintf(
                    /* translators: 1: required Elementor version */
                    __( 'Elementor Pro detected. Please update Elementor to version %s or newer for full planner compatibility.', 'sbdp' ),
                    self::MIN_ELEMENTOR_VERSION
                )
            );
        }

        return true;
    }

    /**
     * Store a non-fatal admin warning.
     *
     * @param string $message Warning message.
     */
    private static function add_warning( $message ) {
        if ( ! in_array( $message, self::$warnings, true ) ) {
            self::$warnings[] = $message;
        }
    }

    /**
     * Print admin notices when required.
     */
    public static function maybe_render_notice() {
        if ( ! empty( self::$notice ) ) {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html( self::$notice )
            );
        }

        foreach ( self::$warnings as $message ) {
            printf(
                '<div class="notice notice-warning"><p>%s</p></div>',
                esc_html( $message )
            );
        }
    }

    /**
     * Register quick links on the plugins screen.
     *
     * @param string[] $links Default links.
     *
     * @return string[]
     */
    public static function register_plugin_links( $links ) {
        $links[] = sprintf(
            '<a href="%1$s">%2$s</a>',
            esc_url( admin_url( 'admin.php?page=sbdp_bookings' ) ),
            esc_html__( 'Planner', 'sbdp' )
        );
        $links[] = sprintf(
            '<a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
            esc_url( 'https://owncreations.com' ),
            esc_html__( 'Support', 'sbdp' )
        );
        return $links;
    }

    /**
     * Allow unauthenticated access to public REST endpoints once sanitised.
     *
     * @param WP_Error|null|true $result Authentication result.
     *
     * @return WP_Error|null|true
     */
    public static function maybe_allow_public_rest( $result ) {
        if ( empty( $result ) || ! ( $result instanceof WP_Error ) ) {
            return $result;
        }

        $route = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        if ( false !== strpos( $route, '/wp-json/sbdp/v1/' ) ) {
            return null;
        }

        return $result;
    }

    /**
     * Check whether Elementor is loaded in the current environment.
     *
     * @return bool
     */
    private static function is_elementor_loaded() {
        return defined( 'ELEMENTOR_VERSION' ) || class_exists( '\\Elementor\\Plugin' );
    }

    /**
     * Check whether Elementor meets our minimum supported version.
     *
     * @return bool
     */
    private static function is_elementor_ready() {
        if ( ! self::is_elementor_loaded() ) {
            return false;
        }

        if ( defined( 'ELEMENTOR_VERSION' ) ) {
            return version_compare( ELEMENTOR_VERSION, self::MIN_ELEMENTOR_VERSION, '>=' );
        }

        return true;
    }
}







