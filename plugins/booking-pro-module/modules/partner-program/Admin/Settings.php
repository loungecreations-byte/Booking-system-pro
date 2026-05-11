<?php

declare(strict_types=1);

namespace BSP\PartnerProgram\Admin;

use function add_action;
use function add_submenu_page;
use function register_setting;
use function add_settings_section;
use function add_settings_field;
use function settings_fields;
use function do_settings_sections;
use function get_option;
use function esc_html;
use function esc_html_e;
use function esc_attr;
use function sanitize_text_field;
use function current_user_can;
use function current_time;

/**
 * Settings — admin settings page for the Partner Program.
 *
 * Registered under sbdp_bookings parent.
 * Options stored in WP options table under 'bsp_partner_program_settings'.
 *
 * Currently exposes:
 *   - Google Places API key
 *   - Default search radius
 *   - Default search location (lat/lng for Den Bosch)
 *   - Claim verification TTL
 *   - Grace period days
 *   - Platform name used in emails
 */
final class Settings
{
    private const OPTION_KEY   = 'bsp_partner_program_settings';
    private const PARENT_SLUG  = 'sbdp_bookings';
    private const PAGE_SLUG    = 'sbdp_partner_settings';
    private const CAPABILITY   = 'manage_options';

    public static function init(): void
    {
        // Menu registration removed — settings are rendered as a tab inside the Partners page.
        add_action('admin_init', [self::class, 'registerSettings']);
    }

    public static function registerMenu(): void
    {
        add_submenu_page(
            self::PARENT_SLUG,
            __('Partner Instellingen', 'sbdp'),
            __('Partner Instellingen', 'sbdp'),
            self::CAPABILITY,
            self::PAGE_SLUG,
            [self::class, 'renderPage']
        );
    }

    public static function registerSettings(): void
    {
        register_setting(self::PAGE_SLUG, self::OPTION_KEY, [
            'type'              => 'array',
            'sanitize_callback' => [self::class, 'sanitize'],
            'default'           => self::defaults(),
        ]);

        add_settings_section('bsp_google_places', __('Google Places API', 'sbdp'), '__return_false', self::PAGE_SLUG);
        add_settings_section('bsp_partner_claims', __('Claim Instellingen', 'sbdp'), '__return_false', self::PAGE_SLUG);
        add_settings_section('bsp_partner_mail', __('E-mail Instellingen', 'sbdp'), '__return_false', self::PAGE_SLUG);

        // Google Places fields.
        add_settings_field('google_api_key', __('Google Places API Key', 'sbdp'), [self::class, 'fieldGoogleApiKey'], self::PAGE_SLUG, 'bsp_google_places');
        add_settings_field('default_lat', __('Standaard Breedtegraad', 'sbdp'), [self::class, 'fieldDefaultLat'], self::PAGE_SLUG, 'bsp_google_places');
        add_settings_field('default_lng', __('Standaard Lengtegraad', 'sbdp'), [self::class, 'fieldDefaultLng'], self::PAGE_SLUG, 'bsp_google_places');
        add_settings_field('default_radius', __('Standaard zoekstraal (m)', 'sbdp'), [self::class, 'fieldDefaultRadius'], self::PAGE_SLUG, 'bsp_google_places');

        // Claim settings.
        add_settings_field('claim_token_ttl_hours', __('Verificatielink geldigheid (uren)', 'sbdp'), [self::class, 'fieldTokenTtl'], self::PAGE_SLUG, 'bsp_partner_claims');
        add_settings_field('grace_period_days', __('Graceperiode (dagen)', 'sbdp'), [self::class, 'fieldGraceDays'], self::PAGE_SLUG, 'bsp_partner_claims');

        // Mail settings.
        add_settings_field('platform_name', __('Platformnaam in e-mails', 'sbdp'), [self::class, 'fieldPlatformName'], self::PAGE_SLUG, 'bsp_partner_mail');
        add_settings_field('admin_email', __('Admin e-mailadres voor meldingen', 'sbdp'), [self::class, 'fieldAdminEmail'], self::PAGE_SLUG, 'bsp_partner_mail');
        add_settings_field('payout_profile_page_url', __('URL uitbetalingsprofiel pagina', 'sbdp'), [self::class, 'fieldPayoutProfilePageUrl'], self::PAGE_SLUG, 'bsp_partner_mail');
    }

    public static function renderPage(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die('Toegang geweigerd.');
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Partner Programma Instellingen', 'sbdp'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields(self::PAGE_SLUG); ?>
                <?php do_settings_sections(self::PAGE_SLUG); ?>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Field renderers
    // -------------------------------------------------------------------------

    public static function fieldGoogleApiKey(): void
    {
        $v = self::get('google_api_key');
        ?>
        <input type="password" name="<?php echo esc_attr(self::OPTION_KEY); ?>[google_api_key]"
               value="<?php echo esc_attr($v); ?>" class="regular-text" autocomplete="new-password">
        <p class="description">
            <?php esc_html_e('Google Places API key. Bewaar deze veilig — wordt ook gelezen via de GOOGLE_PLACES_API_KEY constante in wp-config.php.', 'sbdp'); ?>
        </p>
        <?php
    }

    public static function fieldDefaultLat(): void
    {
        $v = self::get('default_lat', '51.6978');
        ?>
        <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[default_lat]"
               value="<?php echo esc_attr($v); ?>" class="small-text">
        <p class="description"><?php esc_html_e('Standaard: 51.6978 (Den Bosch centrum)', 'sbdp'); ?></p>
        <?php
    }

    public static function fieldDefaultLng(): void
    {
        $v = self::get('default_lng', '5.3037');
        ?>
        <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[default_lng]"
               value="<?php echo esc_attr($v); ?>" class="small-text">
        <p class="description"><?php esc_html_e('Standaard: 5.3037 (Den Bosch centrum)', 'sbdp'); ?></p>
        <?php
    }

    public static function fieldDefaultRadius(): void
    {
        $v = self::get('default_radius', '5000');
        ?>
        <input type="number" name="<?php echo esc_attr(self::OPTION_KEY); ?>[default_radius]"
               value="<?php echo esc_attr($v); ?>" class="small-text" min="100" max="50000" step="100">
        <p class="description"><?php esc_html_e('Meter. Standaard: 5000 (5km).', 'sbdp'); ?></p>
        <?php
    }

    public static function fieldTokenTtl(): void
    {
        $v = self::get('claim_token_ttl_hours', '48');
        ?>
        <input type="number" name="<?php echo esc_attr(self::OPTION_KEY); ?>[claim_token_ttl_hours]"
               value="<?php echo esc_attr($v); ?>" class="small-text" min="1" max="168">
        <?php
    }

    public static function fieldGraceDays(): void
    {
        $v = self::get('grace_period_days', '7');
        ?>
        <input type="number" name="<?php echo esc_attr(self::OPTION_KEY); ?>[grace_period_days]"
               value="<?php echo esc_attr($v); ?>" class="small-text" min="1" max="30">
        <p class="description"><?php esc_html_e('Dagen dat een partner nog toegang houdt na abonnement-probleem.', 'sbdp'); ?></p>
        <?php
    }

    public static function fieldPlatformName(): void
    {
        $v = self::get('platform_name', 'DagjeDenBosch');
        ?>
        <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[platform_name]"
               value="<?php echo esc_attr($v); ?>" class="regular-text">
        <?php
    }

    public static function fieldAdminEmail(): void
    {
        $v = self::get('admin_email', get_option('admin_email'));
        ?>
        <input type="email" name="<?php echo esc_attr(self::OPTION_KEY); ?>[admin_email]"
               value="<?php echo esc_attr($v); ?>" class="regular-text">
        <?php
    }

    public static function fieldPayoutProfilePageUrl(): void
    {
        $v = self::get('payout_profile_page_url', '');
        ?>
        <input type="url" name="<?php echo esc_attr(self::OPTION_KEY); ?>[payout_profile_page_url]"
               value="<?php echo esc_attr($v); ?>" class="regular-text"
               placeholder="<?php echo esc_attr(home_url('/partner-uitbetaling/')); ?>">
        <p class="description"><?php esc_html_e('URL van de pagina met het [bsp_payout_profile] shortcode. Wordt getoond als CTA op het partner dashboard.', 'sbdp'); ?></p>
        <?php
    }

    // -------------------------------------------------------------------------
    // Static accessor — use anywhere in the module
    // -------------------------------------------------------------------------

    /**
     * Get a single settings value.
     */
    public static function get(string $key, mixed $default = ''): mixed
    {
        $all = get_option(self::OPTION_KEY, self::defaults());
        return $all[$key] ?? $default;
    }

    /**
     * Get the Google API key, respecting the constant override in wp-config.php.
     */
    public static function googleApiKey(): string
    {
        if (defined('GOOGLE_PLACES_API_KEY') && GOOGLE_PLACES_API_KEY) {
            return (string) GOOGLE_PLACES_API_KEY;
        }
        return (string) self::get('google_api_key', '');
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    private static function defaults(): array
    {
        return [
            'google_api_key'        => '',
            'default_lat'           => '51.6978',
            'default_lng'           => '5.3037',
            'default_radius'        => '5000',
            'claim_token_ttl_hours' => '48',
            'grace_period_days'     => '7',
            'platform_name'              => 'DagjeDenBosch',
            'admin_email'                => get_option('admin_email', ''),
            'payout_profile_page_url'    => '',
        ];
    }

    public static function sanitize(array $input): array
    {
        $clean = self::defaults();

        // Never store raw API key — only update if non-empty.
        if (! empty($input['google_api_key'])) {
            $clean['google_api_key'] = sanitize_text_field($input['google_api_key']);
        } else {
            // Keep existing value.
            $clean['google_api_key'] = (string) self::get('google_api_key', '');
        }

        $clean['default_lat']           = sanitize_text_field($input['default_lat'] ?? '51.6978');
        $clean['default_lng']           = sanitize_text_field($input['default_lng'] ?? '5.3037');
        $clean['default_radius']        = (string) absint($input['default_radius'] ?? 5000);
        $clean['claim_token_ttl_hours'] = (string) absint($input['claim_token_ttl_hours'] ?? 48);
        $clean['grace_period_days']     = (string) absint($input['grace_period_days'] ?? 7);
        $clean['platform_name']           = sanitize_text_field($input['platform_name'] ?? 'DagjeDenBosch');
        $clean['admin_email']             = sanitize_email($input['admin_email'] ?? '');
        $clean['payout_profile_page_url'] = esc_url_raw($input['payout_profile_page_url'] ?? '');

        return $clean;
    }
}
