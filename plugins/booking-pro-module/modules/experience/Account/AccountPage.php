<?php
declare(strict_types=1);

namespace BSP\Experience\Account;

final class AccountPage
{
    private const ENDPOINT = 'mijn-dagjedenbosch';
    private const SCRIPT_HANDLE = 'bsp-experience-account';
    private const STYLE_HANDLE = 'bsp-experience-account';

    public static function register(): void
    {
        add_action('init', array(__CLASS__, 'endpoint'));
        add_action('init', array(__CLASS__, 'maybeFlushRewriteRules'), 99);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueueAssets'));
        add_filter('woocommerce_account_menu_items', array(__CLASS__, 'menu'), 35);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', array(__CLASS__, 'render'));
    }

    public static function endpoint(): void { add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES); }
    public static function maybeFlushRewriteRules(): void
    {
        if ((string) get_option('bsp_experience_rewrite_version', '') === '1') { return; }
        flush_rewrite_rules(false);
        update_option('bsp_experience_rewrite_version', '1', false);
    }
    public static function menu(array $items): array { $items[self::ENDPOINT] = __('Mijn DagjeDenBosch', 'sbdp'); return $items; }

    public static function enqueueAssets(): void
    {
        if (
            ! function_exists('is_account_page')
            || ! function_exists('is_wc_endpoint_url')
            || ! is_account_page()
            || ! is_wc_endpoint_url(self::ENDPOINT)
        ) {
            return;
        }

        self::enqueueScript();
    }

    private static function enqueueScript(): void
    {

        $assetPath = SBDP_DIR . 'modules/experience/assets/account.js';
        $version = is_readable($assetPath) ? (string) filemtime($assetPath) : SBDP_VERSION;
        $stylePath = SBDP_DIR . 'modules/experience/assets/account.css';
        $styleVersion = is_readable($stylePath) ? (string) filemtime($stylePath) : SBDP_VERSION;

        wp_enqueue_style(
            self::STYLE_HANDLE,
            SBDP_URL . 'modules/experience/assets/account.css',
            array('sbdp-cart-checkout'),
            $styleVersion
        );

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            SBDP_URL . 'modules/experience/assets/account.js',
            array(),
            $version,
            true
        );
        wp_localize_script(
            self::SCRIPT_HANDLE,
            'bspExperienceAccount',
            array(
                'endpoint'  => esc_url_raw(rest_url('bsp/v1/me/experience')),
                'nonce'     => wp_create_nonce('wp_rest'),
                'timeoutMs' => 10000,
            )
        );
    }

    public static function render(): void
    {
        if (! is_user_logged_in()) { return; }
        self::enqueueScript();
        echo '<section class="bsp-experience">';
        echo '<h2>' . esc_html__('Mijn DagjeDenBosch', 'sbdp') . '</h2><p class="bsp-experience__loading">' . esc_html__('Je ervaringen worden geladen…', 'sbdp') . '</p></section>';
    }
}
