<?php

declare(strict_types=1);

namespace BSP\Spots;

/**
 * SpotPartnerMetaModule — Patch A
 *
 * Registers the "DDB partnerprofiel" metabox on the ddb_spot edit screen.
 * Handles secure save (nonce + capability + sanitize) of all partner meta fields.
 *
 * SCOPE: admin-only, purely additive.
 * NO booking flow, NO BookingModeService, NO Quote OS, NO Vendor Portal touched.
 *
 * Meta fields managed:
 *   _ddb_spot_role               content|partner|supplier|venue
 *   _ddb_partner_status          lead|prospect|active|preferred|paused|blocked|archived
 *   _ddb_supplier_provider       none|manual|eliio|recras|leisureking|custom
 *   _ddb_supplier_email          sanitized email
 *   _ddb_supplier_phone          sanitized text
 *   _ddb_supplier_contact_name   sanitized text
 *   _ddb_resource_owner          ddb|partner
 *   _ddb_resource_control        owned|allocated|external_live_check|external_confirmed_only|manual
 *   _ddb_booking_authority       ddb|supplier
 *   _ddb_cancellation_authority  ddb|supplier_manual|supplier_api
 *   _ddb_default_option_days     int 0–30
 *   _ddb_vendor_portal_enabled   yes|no
 */
final class SpotPartnerMetaModule
{
    private const POST_TYPE   = 'ddb_spot';
    private const NONCE_NAME  = 'ddb_spot_partner_nonce';
    private const NONCE_ACTION = 'ddb_spot_partner_save';
    private const METABOX_ID  = 'ddb_spot_partner_profile';

    /**
     * Allowed values for enum-type meta keys.
     * Used for both the select options and server-side sanitization.
     */
    private const ALLOWED = [
        '_ddb_spot_role' => [
            'content'  => 'Content / locatie',
            'partner'  => 'Partner',
            'supplier' => 'Supplier',
            'venue'    => 'Venue',
        ],
        '_ddb_partner_status' => [
            'lead'      => 'Lead',
            'prospect'  => 'Prospect',
            'active'    => 'Actief',
            'preferred' => 'Preferred',
            'paused'    => 'Gepauzeerd',
            'blocked'   => 'Geblokkeerd',
            'archived'  => 'Gearchiveerd',
        ],
        '_ddb_supplier_provider' => [
            'none'        => '— Geen',
            'manual'      => 'Handmatig',
            'eliio'       => 'Eliio / Eropuitje',
            'recras'      => 'Recras',
            'leisureking' => 'LeisureKing',
            'custom'      => 'Custom (API)',
        ],
        '_ddb_resource_owner' => [
            'ddb'     => 'DDB',
            'partner' => 'Partner / Supplier',
        ],
        '_ddb_resource_control' => [
            'owned'                    => 'Owned (DDB beheer)',
            'allocated'                => 'Allocated (vaste toewijzing)',
            'external_live_check'      => 'External — live beschikbaarheidscheck',
            'external_confirmed_only'  => 'External — alleen na bevestiging',
            'manual'                   => 'Handmatig',
        ],
        '_ddb_booking_authority' => [
            'ddb'      => 'DDB',
            'supplier' => 'Supplier',
        ],
        '_ddb_cancellation_authority' => [
            'ddb'           => 'DDB',
            'supplier_manual' => 'Supplier — handmatig',
            'supplier_api'    => 'Supplier — API',
        ],
        '_ddb_vendor_portal_enabled' => [
            'yes' => 'Ja — portal actief',
            'no'  => 'Nee',
        ],
    ];

    public function init(): void
    {
        add_action('add_meta_boxes',  [$this, 'registerMetabox']);
        add_action('save_post',       [$this, 'saveMeta'], 10, 2);
        add_action('init',            [$this, 'registerMeta'], 30);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Meta registration (REST + block editor support)
    // ─────────────────────────────────────────────────────────────────────────

    public function registerMeta(): void
    {
        $enumArgs = static function (array $allowed): array {
            return [
                'object_subtype'    => self::POST_TYPE,
                'type'              => 'string',
                'single'            => true,
                'show_in_rest'      => false, // admin-only, no public REST exposure
                'sanitize_callback' => static function (string $v) use ($allowed): string {
                    return array_key_exists($v, $allowed) ? $v : '';
                },
                'auth_callback'     => static fn (): bool => current_user_can('edit_posts'),
            ];
        };

        foreach (self::ALLOWED as $key => $values) {
            register_post_meta(self::POST_TYPE, $key, $enumArgs($values));
        }

        // Free-text fields
        $textArgs = [
            'object_subtype' => self::POST_TYPE,
            'type'           => 'string',
            'single'         => true,
            'show_in_rest'   => false,
            'auth_callback'  => static fn (): bool => current_user_can('edit_posts'),
        ];
        register_post_meta(self::POST_TYPE, '_ddb_supplier_email', $textArgs);
        register_post_meta(self::POST_TYPE, '_ddb_supplier_phone', $textArgs);
        register_post_meta(self::POST_TYPE, '_ddb_supplier_contact_name', $textArgs);

        // Integer field
        register_post_meta(self::POST_TYPE, '_ddb_default_option_days', [
            'object_subtype' => self::POST_TYPE,
            'type'           => 'integer',
            'single'         => true,
            'show_in_rest'   => false,
            'auth_callback'  => static fn (): bool => current_user_can('edit_posts'),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Metabox registration
    // ─────────────────────────────────────────────────────────────────────────

    public function registerMetabox(): void
    {
        add_meta_box(
            self::METABOX_ID,
            __('DDB partnerprofiel', 'sbdp'),
            [$this, 'renderMetabox'],
            self::POST_TYPE,
            'normal',
            'high'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Metabox render
    // ─────────────────────────────────────────────────────────────────────────

    public function renderMetabox(\WP_Post $post): void
    {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);

        $get = static function (string $key) use ($post): string {
            return (string) get_post_meta($post->ID, $key, true);
        };

        $role     = $get('_ddb_spot_role');
        $status   = $get('_ddb_partner_status');
        $provider = $get('_ddb_supplier_provider');
        $email    = $get('_ddb_supplier_email');
        $phone    = $get('_ddb_supplier_phone');
        $contact  = $get('_ddb_supplier_contact_name');
        $resOwner = $get('_ddb_resource_owner');
        $resCtrl  = $get('_ddb_resource_control');
        $bookAuth = $get('_ddb_booking_authority');
        $cancelAuth = $get('_ddb_cancellation_authority');
        $optDays  = $get('_ddb_default_option_days');
        $vpEnabled = $get('_ddb_vendor_portal_enabled');

        ?>
        <style>
        .ddb-partner-metabox { font-family: -apple-system, "Segoe UI", sans-serif; }
        .ddb-partner-metabox h4 { margin: 16px 0 8px; padding-bottom: 6px; border-bottom: 1px solid #e0dbd4; font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: #78716c; font-weight: 600; }
        .ddb-partner-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px; }
        .ddb-partner-field label { display: block; font-size: 12px; font-weight: 500; margin-bottom: 4px; color: #44403c; }
        .ddb-partner-field select,
        .ddb-partner-field input[type="text"],
        .ddb-partner-field input[type="email"],
        .ddb-partner-field input[type="number"] { width: 100%; max-width: 360px; }
        </style>
        <div class="ddb-partner-metabox">

            <h4><?php esc_html_e('Partner profiel', 'sbdp'); ?></h4>
            <div class="ddb-partner-grid">
                <div class="ddb-partner-field">
                    <label for="ddb_spot_role"><?php esc_html_e('Spot rol', 'sbdp'); ?></label>
                    <?php $this->renderSelect('_ddb_spot_role', 'ddb_spot_role', $role); ?>
                </div>
                <div class="ddb-partner-field">
                    <label for="ddb_partner_status"><?php esc_html_e('Partnerstatus', 'sbdp'); ?></label>
                    <?php $this->renderSelect('_ddb_partner_status', 'ddb_partner_status', $status); ?>
                </div>
                <div class="ddb-partner-field">
                    <label for="ddb_supplier_contact_name"><?php esc_html_e('Contactpersoon', 'sbdp'); ?></label>
                    <input type="text" id="ddb_supplier_contact_name" name="ddb_supplier_contact_name"
                           value="<?php echo esc_attr($contact); ?>" class="regular-text">
                </div>
                <div class="ddb-partner-field">
                    <label for="ddb_supplier_email"><?php esc_html_e('E-mail supplier', 'sbdp'); ?></label>
                    <input type="email" id="ddb_supplier_email" name="ddb_supplier_email"
                           value="<?php echo esc_attr($email); ?>" class="regular-text">
                </div>
                <div class="ddb-partner-field">
                    <label for="ddb_supplier_phone"><?php esc_html_e('Telefoon', 'sbdp'); ?></label>
                    <input type="text" id="ddb_supplier_phone" name="ddb_supplier_phone"
                           value="<?php echo esc_attr($phone); ?>" class="regular-text">
                </div>
            </div>

            <h4><?php esc_html_e('Supplier configuratie', 'sbdp'); ?></h4>
            <div class="ddb-partner-grid">
                <div class="ddb-partner-field">
                    <label for="ddb_supplier_provider"><?php esc_html_e('Provider', 'sbdp'); ?></label>
                    <?php $this->renderSelect('_ddb_supplier_provider', 'ddb_supplier_provider', $provider); ?>
                </div>
                <div class="ddb-partner-field">
                    <label for="ddb_resource_owner"><?php esc_html_e('Resource owner', 'sbdp'); ?></label>
                    <?php $this->renderSelect('_ddb_resource_owner', 'ddb_resource_owner', $resOwner); ?>
                </div>
                <div class="ddb-partner-field">
                    <label for="ddb_resource_control"><?php esc_html_e('Resource control', 'sbdp'); ?></label>
                    <?php $this->renderSelect('_ddb_resource_control', 'ddb_resource_control', $resCtrl); ?>
                </div>
                <div class="ddb-partner-field">
                    <label for="ddb_booking_authority"><?php esc_html_e('Booking authority', 'sbdp'); ?></label>
                    <?php $this->renderSelect('_ddb_booking_authority', 'ddb_booking_authority', $bookAuth); ?>
                </div>
                <div class="ddb-partner-field">
                    <label for="ddb_cancellation_authority"><?php esc_html_e('Cancellation authority', 'sbdp'); ?></label>
                    <?php $this->renderSelect('_ddb_cancellation_authority', 'ddb_cancellation_authority', $cancelAuth); ?>
                </div>
                <div class="ddb-partner-field">
                    <label for="ddb_default_option_days"><?php esc_html_e('Default optiedagen (0–30)', 'sbdp'); ?></label>
                    <input type="number" id="ddb_default_option_days" name="ddb_default_option_days"
                           value="<?php echo esc_attr($optDays !== '' ? $optDays : '3'); ?>"
                           min="0" max="30" step="1" class="small-text">
                </div>
                <div class="ddb-partner-field">
                    <label for="ddb_vendor_portal_enabled"><?php esc_html_e('Vendor portal', 'sbdp'); ?></label>
                    <?php $this->renderSelect('_ddb_vendor_portal_enabled', 'ddb_vendor_portal_enabled', $vpEnabled); ?>
                </div>
            </div>

        </div>
        <?php
    }

    /**
     * Render a <select> from the ALLOWED map for $metaKey.
     */
    private function renderSelect(string $metaKey, string $htmlId, string $current): void
    {
        $options = self::ALLOWED[$metaKey] ?? [];
        $name    = ltrim($metaKey, '_'); // strip leading underscore for POST key
        printf('<select id="%s" name="%s">', esc_attr($htmlId), esc_attr($name));
        printf('<option value=""></option>');
        foreach ($options as $value => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr((string) $value),
                selected($current, $value, false),
                esc_html($label)
            );
        }
        echo '</select>';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Save meta
    // ─────────────────────────────────────────────────────────────────────────

    public function saveMeta(int $postId, \WP_Post $post): void
    {
        // 1. Autosave guard
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // 2. Post type guard
        if ($post->post_type !== self::POST_TYPE) {
            return;
        }

        // 3. Nonce check
        $nonce = isset($_POST[self::NONCE_NAME]) ? sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])) : '';
        if (! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return;
        }

        // 4. Capability check
        if (! current_user_can('edit_post', $postId)) {
            return;
        }

        // 5. Revision guard
        if (wp_is_post_revision($postId)) {
            return;
        }

        // 6. Save enum fields (whitelist-only)
        foreach (self::ALLOWED as $metaKey => $allowed) {
            $postKey = ltrim($metaKey, '_');
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above
            $raw   = isset($_POST[$postKey]) ? sanitize_text_field(wp_unslash((string) $_POST[$postKey])) : '';
            $value = array_key_exists($raw, $allowed) ? $raw : '';
            update_post_meta($postId, $metaKey, $value);
        }

        // 7. Free-text fields
        $textFields = [
            '_ddb_supplier_email'        => 'ddb_supplier_email',
            '_ddb_supplier_phone'        => 'ddb_supplier_phone',
            '_ddb_supplier_contact_name' => 'ddb_supplier_contact_name',
        ];
        foreach ($textFields as $metaKey => $postKey) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above
            $raw = isset($_POST[$postKey]) ? wp_unslash((string) $_POST[$postKey]) : '';
            if ($metaKey === '_ddb_supplier_email') {
                $value = sanitize_email($raw);
            } else {
                $value = sanitize_text_field($raw);
            }
            update_post_meta($postId, $metaKey, $value);
        }

        // 8. Integer field (0–30)
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above
        $rawDays = isset($_POST['ddb_default_option_days']) ? (int) $_POST['ddb_default_option_days'] : 3;
        $days    = max(0, min(30, $rawDays));
        update_post_meta($postId, '_ddb_default_option_days', $days);
    }
}
