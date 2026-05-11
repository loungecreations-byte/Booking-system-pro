<?php

declare(strict_types=1);

namespace BSP\PartnerProgram\Frontend;

use BSP\PartnerProgram\Service\PartnerVendorIdentityService;

/**
 * PayoutProfileHandler — shortcode + POST handler for partner payout profile.
 *
 * Shortcode: [bsp_payout_profile]
 * Lets a logged-in partner view and update their IBAN / payout details.
 * Vendor scope is resolved through PartnerVendorIdentityService.
 */
final class PayoutProfileHandler
{
    private const NONCE_ACTION = 'bsp_payout_profile_save';
    private const NONCE_FIELD  = '_bsp_payout_nonce';

    public static function init(): void
    {
        add_shortcode('bsp_payout_profile', [self::class, 'renderShortcode']);
        add_action('init', [self::class, 'handlePost'], 5);
    }

    public static function handlePost(): void
    {
        if (
            ! isset($_POST['bsp_payout_action'])
            || $_POST['bsp_payout_action'] !== 'save_profile'
        ) {
            return;
        }

        if (! is_user_logged_in()) {
            wp_die('Niet ingelogd.', 403);
        }

        $nonce = isset($_POST[self::NONCE_FIELD]) ? sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD])) : '';
        if (! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_die('Beveiligingstoken verlopen. Ga terug en probeer opnieuw.', 'Fout', ['response' => 403]);
        }

        $userId   = get_current_user_id();
        $vendorId = PartnerVendorIdentityService::resolveVendorIdByUserId($userId);

        if (! $vendorId) {
            wp_die('Geen vendor account gevonden.');
        }

        $iban         = strtoupper(preg_replace('/\s+/', '', sanitize_text_field(wp_unslash($_POST['iban'] ?? ''))));
        $accountName  = sanitize_text_field(wp_unslash($_POST['account_name'] ?? ''));
        $payoutEmail  = sanitize_email(wp_unslash($_POST['payout_email'] ?? ''));
        $notes        = sanitize_textarea_field(wp_unslash($_POST['notes'] ?? ''));

        if (! self::validateIban($iban)) {
            // Redirect back with error.
            $redirect = add_query_arg('bsp_payout_error', 'iban_invalid', wp_get_referer() ?: home_url('/'));
            wp_safe_redirect($redirect);
            exit;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bsp_payout_profiles';

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE vendor_id = %d LIMIT 1",
            $vendorId
        ));

        $data = [
            'account_holder_name' => $accountName,
            'iban'                => $iban,
            'payout_email'        => $payoutEmail ?: null,
            'notes'               => $notes ?: null,
            'updated_at'          => current_time('mysql', true),
        ];

        if ($existing) {
            $wpdb->update($table, $data, ['vendor_id' => $vendorId], ['%s', '%s', '%s', '%s', '%s'], ['%d']);
        } else {
            $data['vendor_id']   = $vendorId;
            $data['created_at']  = current_time('mysql', true);
            $data['status']      = 'active';
            $wpdb->insert($table, $data, ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
        }

        $redirect = add_query_arg('bsp_payout_saved', '1', wp_get_referer() ?: home_url('/'));
        wp_safe_redirect($redirect);
        exit;
    }

    public static function renderShortcode(array $atts): string
    {
        if (! is_user_logged_in()) {
            return '<p class="bsp-portal-notice">Log in om je uitbetalingsprofiel te beheren.</p>';
        }

        $userId   = get_current_user_id();
        $vendorId = PartnerVendorIdentityService::resolveVendorIdByUserId($userId);

        if (! $vendorId) {
            return '<p class="bsp-portal-notice">Geen partneraccount gekoppeld aan dit account.</p>';
        }

        global $wpdb;
        $profile = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bsp_payout_profiles WHERE vendor_id = %d LIMIT 1",
            $vendorId
        ), ARRAY_A);

        $saved = isset($_GET['bsp_payout_saved']) && $_GET['bsp_payout_saved'] === '1';
        $error = isset($_GET['bsp_payout_error']) ? sanitize_key($_GET['bsp_payout_error']) : '';

        ob_start();
        ?>
        <div class="bsp-payout-profile">
            <h3 class="bsp-payout-profile__title">Uitbetalingsgegevens</h3>

            <?php if ($saved): ?>
                <div class="bsp-payout-profile__notice bsp-payout-profile__notice--success">
                    Uitbetalingsgegevens opgeslagen.
                </div>
            <?php endif; ?>

            <?php if ($error === 'iban_invalid'): ?>
                <div class="bsp-payout-profile__notice bsp-payout-profile__notice--error">
                    Het ingevoerde IBAN-nummer is ongeldig. Controleer het formaat (bijv. NL91ABNA0417164300).
                </div>
            <?php endif; ?>

            <form class="bsp-payout-profile__form"
                  method="POST"
                  action="<?php echo esc_url(home_url('/')); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                <input type="hidden" name="bsp_payout_action" value="save_profile">

                <div class="bsp-payout-profile__field">
                    <label for="bsp_account_name">Naam rekeninghouder</label>
                    <input type="text"
                           id="bsp_account_name"
                           name="account_name"
                           value="<?php echo esc_attr($profile['account_holder_name'] ?? ''); ?>"
                           required
                           maxlength="200">
                </div>

                <div class="bsp-payout-profile__field">
                    <label for="bsp_iban">IBAN-nummer</label>
                    <input type="text"
                           id="bsp_iban"
                           name="iban"
                           value="<?php echo esc_attr($profile['iban'] ?? ''); ?>"
                           placeholder="NL91ABNA0417164300"
                           required
                           maxlength="34"
                           autocomplete="off">
                    <small>Alleen NL en BE IBAN nummers worden ondersteund.</small>
                </div>

                <div class="bsp-payout-profile__field">
                    <label for="bsp_payout_email">Uitbetalings-e-mail (optioneel)</label>
                    <input type="email"
                           id="bsp_payout_email"
                           name="payout_email"
                           value="<?php echo esc_attr($profile['payout_email'] ?? ''); ?>"
                           maxlength="200">
                </div>

                <div class="bsp-payout-profile__field">
                    <label for="bsp_payout_notes">Opmerkingen (optioneel)</label>
                    <textarea id="bsp_payout_notes"
                              name="notes"
                              rows="3"
                              maxlength="1000"><?php echo esc_textarea($profile['notes'] ?? ''); ?></textarea>
                </div>

                <div class="bsp-payout-profile__actions">
                    <button type="submit" class="bsp-btn bsp-btn--primary">
                        Opslaan
                    </button>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Basic IBAN format validation (structure check, not checksum).
     * Accepts NL and BE format IBANs that are most commonly used.
     */
    private static function validateIban(string $iban): bool
    {
        // Remove any remaining whitespace.
        $iban = preg_replace('/\s+/', '', $iban);

        if (strlen($iban) < 15 || strlen($iban) > 34) {
            return false;
        }

        // Must start with 2-letter country code, 2 digits, then alphanumeric.
        if (! preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/', $iban)) {
            return false;
        }

        // IBAN checksum (mod-97).
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $numeric    = '';
        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        // bcmod is best, fall back to iterative modulo.
        if (function_exists('bcmod')) {
            return bcmod($numeric, '97') === '1';
        }

        $remainder = 0;
        foreach (str_split($numeric, 9) as $chunk) {
            $remainder = (int) (($remainder . $chunk) % 97);
        }

        return $remainder === 1;
    }
}
