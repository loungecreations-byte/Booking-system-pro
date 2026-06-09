<?php

declare(strict_types=1);

namespace BSP\Bookings\Shortcodes;

use BSP\Quotes\Repository\QuoteRepository;
use BSP\Quotes\Service\PlannerQuoteIntakeService;
use BSP\Quotes\Service\PlannerQuoteSummaryService;
use BSP\Quotes\Service\QuoteAssumptionService;
use BSP\Quotes\Service\QuoteConversionService;
use BSP\Quotes\Service\QuoteEventLogger;
use BSP\Quotes\Service\QuoteFollowupService;
use BSP\Quotes\Service\QuoteRequestService;
use Throwable;

use function absint;
use function checked;
use function esc_attr;
use function esc_attr_e;
use function esc_html;
use function esc_textarea;
use function esc_url;
use function get_bloginfo;
use function get_current_user_id;
use function get_option;
use function get_post;
use function get_post_meta;
use function get_post_type;
use function home_url;
use function is_array;
use function is_email;
use function maybe_unserialize;
use function number_format;
use function sanitize_email;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function shortcode_atts;
use function wp_create_nonce;
use function wp_mail;
use function wp_nonce_field;
use function wp_unslash;
use function wp_verify_nonce;

/**
 * Shortcode [sbdp_offerte_aanvragen]
 */
final class OfferteForm
{
    private const POST_TYPE    = 'sbdp_plan';
    private const META_KEY     = '_sbdp_plan_payload';
    private const NONCE_KEY    = 'sbdp_offerte_form';
    private const NONCE_ACTION = 'sbdp_offerte_submit';

    public static function register(): void
    {
        if (function_exists('add_shortcode')) {
            add_shortcode('sbdp_offerte_aanvragen', [self::class, 'render']);
        }
    }

    /**
     * @param array<string, mixed>|string $atts
     */
    public static function render($atts): string
    {
        // Enqueue unified offerte stylesheet
        $cssPath = __DIR__ . '/../../../assets/css/sbdp-offerte-form.css';
        wp_enqueue_style(
            'sbdp-offerte-form',
            plugins_url('assets/css/sbdp-offerte-form.css', __DIR__ . '/../../../booking-pro-module.php'),
            [],
            self::resolveAssetVersion($cssPath)
        );

        $atts = shortcode_atts([], is_array($atts) ? $atts : []);
        unset($atts);

        $planId    = absint((int) ($_GET['planner_plan'] ?? 0)); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $editToken = isset($_GET['edit_token']) ? sanitize_text_field(wp_unslash((string) $_GET['edit_token'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ($planId <= 0 || $editToken === '') {
            return self::renderError(__('Ongeldige aanvraaglink. Ga terug naar de planner en probeer opnieuw.', 'sbdp'));
        }

        $plan = get_post($planId);
        if (! $plan || get_post_type($plan) !== self::POST_TYPE) {
            return self::renderError(__('Dagplanning niet gevonden.', 'sbdp'));
        }

        $raw     = get_post_meta($planId, self::META_KEY, true);
        $payload = is_array($raw) ? $raw : (is_string($raw) ? maybe_unserialize($raw) : []);
        if (! is_array($payload)) {
            return self::renderError(__('Planninggegevens konden niet worden geladen.', 'sbdp'));
        }

        $storedToken = (string) ($payload['meta']['edit_token'] ?? '');
        if (! hash_equals($storedToken, $editToken)) {
            return self::renderError(__('Deze link is niet geldig of verlopen.', 'sbdp'));
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST[self::NONCE_KEY])) {
            $result = self::handleSubmit($planId, $payload);
            if (is_array($result) && ! empty($result['ok'])) {
                return self::renderSuccess($result);
            }
            if (is_string($result)) {
                return self::renderForm($plan, $payload, $editToken, $result);
            }
        }

        return self::renderForm($plan, $payload, $editToken, '');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function renderForm(\WP_Post $plan, array $payload, string $editToken, string $error): string
    {
        $summary      = (new PlannerQuoteSummaryService())->buildViewModel($payload);
        $items        = self::buildDisplayItems(isset($summary['items']) && is_array($summary['items']) ? $summary['items'] : array());
        $participants = max(1, (int) ($summary['participants'] ?? 1));
        $date         = self::formatDate((string) ($summary['date'] ?? ''));
        $currency     = (string) ($summary['currency'] ?? 'EUR');
        $totalPrice   = self::sumDisplayItems($items);

        $formUrl = isset($_SERVER['REQUEST_URI'])
            ? esc_url(home_url(wp_unslash((string) $_SERVER['REQUEST_URI'])))
            : '';
        $voornaamValue    = self::postedField('voornaam');
        $achternaamValue = self::postedField('achternaam');
        $emailValue      = self::postedField('email');
        $phoneValue      = self::postedField('phone');
        $woonplaatsValue = self::postedField('woonplaats');
        $opmerkingValue  = self::postedField('opmerking');
        $isCompany       = self::postedField('is_company') === '1';
        $bedrijfValue    = self::postedField('bedrijfsnaam');
        $referentieValue = self::postedField('referentiecode');

        ob_start();
        ?>
        <div class="sbdp-offerte-wrap">
            <header class="sbdp-offerte-hero">
                <p class="sbdp-offerte-hero__eyebrow"><?php esc_html_e('Offerte aanvragen', 'sbdp'); ?></p>
                <h1 class="sbdp-offerte-hero__title"><?php esc_html_e('Offerte aanvragen voor jullie dag in Den Bosch', 'sbdp'); ?></h1>
                <p class="sbdp-offerte-hero__intro"><?php esc_html_e('Jullie selectie staat klaar. Vul hieronder je gegevens in, dan controleren we de beschikbaarheid en sturen we een passend voorstel per e-mail.', 'sbdp'); ?></p>
            </header>

            <?php if ($error !== '') : ?>
            <div class="sbdp-offerte-notice sbdp-offerte-notice--error" role="alert">
                <?php echo esc_html($error); ?>
            </div>
            <?php endif; ?>

            <div class="sbdp-offerte-layout">
                <section class="sbdp-offerte-summary ddb-summary" aria-labelledby="sbdp-offerte-summary-heading">
                    <div class="sbdp-offerte-card ddb-card ddb-card--raised">
                        <div class="sbdp-offerte-card__header">
                            <h2 id="sbdp-offerte-summary-heading" class="sbdp-offerte-card__title ddb-card__title"><?php esc_html_e('Jullie planning', 'sbdp'); ?></h2>
                            <p class="sbdp-offerte-card__subtitle ddb-card__meta"><?php echo esc_html($plan->post_title); ?></p>
                        </div>

                        <dl class="sbdp-offerte-summary__meta">
                            <?php if ($date !== '') : ?>
                            <div>
                                <dt><?php esc_html_e('Datum', 'sbdp'); ?></dt>
                                <dd><?php echo esc_html($date); ?></dd>
                            </div>
                            <?php endif; ?>
                            <div>
                                <dt><?php esc_html_e('Personen', 'sbdp'); ?></dt>
                                <dd><?php echo esc_html(sprintf(_n('%d persoon', '%d personen', $participants, 'sbdp'), $participants)); ?></dd>
                            </div>
                        </dl>

                        <?php if ($items !== array()) : ?>
                        <ul class="sbdp-offerte-summary__items ddb-itinerary">
                            <?php foreach ($items as $item) : ?>
                            <li class="sbdp-offerte-summary__item ddb-itinerary__item">
                                <div class="sbdp-offerte-summary__item-main">
                                    <div class="sbdp-offerte-summary__item-heading">
                                        <span class="sbdp-offerte-summary__item-title ddb-itinerary__title"><?php echo esc_html((string) ($item['title'] ?? '')); ?></span>
                                        <?php if (! empty($item['start_time']) || ! empty($item['end_time'])) : ?>
                                        <span class="sbdp-offerte-summary__item-time ddb-itinerary__time"><?php echo esc_html(self::formatTimeRange((string) ($item['start_time'] ?? ''), (string) ($item['end_time'] ?? ''))); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="sbdp-offerte-summary__item-pricing ddb-itinerary__meta">
                                        <?php
                                        $displayPriceLabel = trim((string) ($item['display_price_label'] ?? ''));
                                        if ($displayPriceLabel !== '') {
                                            echo esc_html($displayPriceLabel);
                                        } else {
                                            echo esc_html(
                                                sprintf(
                                                    '%s %s × %d = %s',
                                                    self::formatCurrency((float) ($item['unit_price'] ?? 0.0), (string) ($item['currency'] ?? $currency)),
                                                    (string) ($item['pricing_basis_label'] ?? 'groepsprijs'),
                                                    max(1, (int) ($item['quantity'] ?? 1)),
                                                    self::formatCurrency((float) ($item['line_total'] ?? 0.0), (string) ($item['currency'] ?? $currency))
                                                )
                                            );
                                        }
                                        ?>
                                    </p>
                                </div>
                                <span class="sbdp-offerte-summary__item-total ddb-itinerary__price ddb-price">
                                    <?php
                                    if ($displayPriceLabel !== '') {
                                        echo esc_html($displayPriceLabel);
                                    } else {
                                        echo esc_html(self::formatCurrency((float) ($item['line_total'] ?? 0.0), (string) ($item['currency'] ?? $currency)));
                                    }
                                    ?>
                                </span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>

                        <div class="sbdp-offerte-summary__footer">
                            <div class="sbdp-offerte-summary__total-row ddb-summary__total">
                                <span><?php esc_html_e('Totaal indicatie', 'sbdp'); ?></span>
                                <strong class="ddb-price"><?php echo esc_html(self::formatCurrency($totalPrice, $currency)); ?></strong>
                            </div>
                            <p class="sbdp-offerte-summary__note"><?php esc_html_e('Definitieve prijs na beschikbaarheidscheck.', 'sbdp'); ?></p>
                        </div>
                    </div>
                </section>

                <section class="sbdp-offerte-form-section" aria-labelledby="sbdp-offerte-form-heading">
                    <div class="sbdp-offerte-card ddb-card ddb-card--raised">
                        <div class="sbdp-offerte-card__header">
                            <h2 id="sbdp-offerte-form-heading" class="sbdp-offerte-card__title ddb-card__title"><?php esc_html_e('Jullie gegevens', 'sbdp'); ?></h2>
                            <p class="sbdp-offerte-card__subtitle ddb-card__meta"><?php esc_html_e('We gebruiken deze gegevens om jullie voorstel te versturen en contact op te nemen als iets verduidelijkt moet worden.', 'sbdp'); ?></p>
                        </div>

                        <form class="sbdp-offerte-form"
                              method="post"
                              action="<?php echo esc_url(add_query_arg(['planner_plan' => $plan->ID, 'edit_token' => $editToken], $formUrl)); ?>"
                              novalidate>

                            <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_KEY); ?>

                            <!-- Bedrijf toggle -->
                            <div class="sbdp-offerte-form__toggle-row">
                                <input
                                    class="sbdp-offerte-form__toggle-input"
                                    type="checkbox"
                                    id="sbdp-offerte-is-company"
                                    name="sbdp_offerte[is_company]"
                                    value="1"
                                    <?php checked($isCompany); ?>
                                />
                                <label class="sbdp-offerte-form__toggle-track" for="sbdp-offerte-is-company"></label>
                                <label class="sbdp-offerte-form__toggle-label" for="sbdp-offerte-is-company">
                                    <?php esc_html_e('Bedrijf', 'sbdp'); ?>
                                </label>
                            </div>

                            <!-- Bedrijfsvelden (alleen zichtbaar als Bedrijf aan staat) -->
                            <div class="sbdp-offerte-form__row-grid sbdp-offerte-company-only" style="<?php echo $isCompany ? '' : 'display:none'; ?>">
                                <div class="sbdp-offerte-form__row ddb-field">
                                    <label class="sbdp-offerte-form__label ddb-field__label" for="sbdp-offerte-bedrijfsnaam">
                                        <?php esc_html_e('Bedrijfsnaam', 'sbdp'); ?> <abbr title="verplicht">*</abbr>
                                    </label>
                                    <input
                                        class="sbdp-offerte-form__input ddb-input"
                                        type="text"
                                        id="sbdp-offerte-bedrijfsnaam"
                                        name="sbdp_offerte[bedrijfsnaam]"
                                        value="<?php echo esc_attr($bedrijfValue); ?>"
                                        autocomplete="organization"
                                    />
                                </div>
                                <div class="sbdp-offerte-form__row ddb-field">
                                    <label class="sbdp-offerte-form__label ddb-field__label" for="sbdp-offerte-referentiecode">
                                        <?php esc_html_e('Referentiecode', 'sbdp'); ?>
                                    </label>
                                    <input
                                        class="sbdp-offerte-form__input ddb-input"
                                        type="text"
                                        id="sbdp-offerte-referentiecode"
                                        name="sbdp_offerte[referentiecode]"
                                        value="<?php echo esc_attr($referentieValue); ?>"
                                        placeholder="bijv. REF-2025-001"
                                    />
                                </div>
                            </div>

                            <!-- Naam (2-col) -->
                            <div class="sbdp-offerte-form__row-grid">
                                <div class="sbdp-offerte-form__row ddb-field">
                                    <label class="sbdp-offerte-form__label ddb-field__label" for="sbdp-offerte-voornaam">
                                        <?php esc_html_e('Voornaam', 'sbdp'); ?> <abbr title="verplicht">*</abbr>
                                    </label>
                                    <input
                                        class="sbdp-offerte-form__input ddb-input"
                                        type="text"
                                        id="sbdp-offerte-voornaam"
                                        name="sbdp_offerte[voornaam]"
                                        value="<?php echo esc_attr($voornaamValue); ?>"
                                        required
                                        autocomplete="given-name"
                                    />
                                </div>
                                <div class="sbdp-offerte-form__row ddb-field">
                                    <label class="sbdp-offerte-form__label ddb-field__label" for="sbdp-offerte-achternaam">
                                        <?php esc_html_e('Achternaam', 'sbdp'); ?> <abbr title="verplicht">*</abbr>
                                    </label>
                                    <input
                                        class="sbdp-offerte-form__input ddb-input"
                                        type="text"
                                        id="sbdp-offerte-achternaam"
                                        name="sbdp_offerte[achternaam]"
                                        value="<?php echo esc_attr($achternaamValue); ?>"
                                        required
                                        autocomplete="family-name"
                                    />
                                </div>
                            </div>

                            <!-- E-mail + Telefoon (2-col) -->
                            <div class="sbdp-offerte-form__row-grid">
                                <div class="sbdp-offerte-form__row ddb-field">
                                    <label class="sbdp-offerte-form__label ddb-field__label" for="sbdp-offerte-email">
                                        <?php esc_html_e('E-mail', 'sbdp'); ?> <abbr title="verplicht">*</abbr>
                                    </label>
                                    <input
                                        class="sbdp-offerte-form__input ddb-input"
                                        type="email"
                                        id="sbdp-offerte-email"
                                        name="sbdp_offerte[email]"
                                        value="<?php echo esc_attr($emailValue); ?>"
                                        required
                                        autocomplete="email"
                                    />
                                </div>
                                <div class="sbdp-offerte-form__row ddb-field">
                                    <label class="sbdp-offerte-form__label ddb-field__label" for="sbdp-offerte-phone">
                                        <?php esc_html_e('Telefoonnummer', 'sbdp'); ?> <abbr title="verplicht">*</abbr>
                                    </label>
                                    <input
                                        class="sbdp-offerte-form__input ddb-input"
                                        type="tel"
                                        id="sbdp-offerte-phone"
                                        name="sbdp_offerte[phone]"
                                        value="<?php echo esc_attr($phoneValue); ?>"
                                        required
                                        autocomplete="tel"
                                    />
                                </div>
                            </div>

                            <!-- Woonplaats -->
                            <div class="sbdp-offerte-form__row ddb-field">
                                <label class="sbdp-offerte-form__label ddb-field__label" for="sbdp-offerte-woonplaats">
                                    <?php esc_html_e('Woonplaats', 'sbdp'); ?>
                                </label>
                                <input
                                    class="sbdp-offerte-form__input ddb-input"
                                    type="text"
                                    id="sbdp-offerte-woonplaats"
                                    name="sbdp_offerte[woonplaats]"
                                    value="<?php echo esc_attr($woonplaatsValue); ?>"
                                    autocomplete="address-level2"
                                />
                            </div>

                            <!-- Opmerking -->
                            <div class="sbdp-offerte-form__row ddb-field">
                                <label class="sbdp-offerte-form__label ddb-field__label" for="sbdp-offerte-opmerking">
                                    <?php esc_html_e('Opmerking', 'sbdp'); ?>
                                </label>
                                <textarea
                                    class="sbdp-offerte-form__input ddb-input"
                                    id="sbdp-offerte-opmerking"
                                    name="sbdp_offerte[opmerking]"
                                    rows="4"
                                    placeholder="<?php esc_attr_e('Heb je speciale wensen of details die je ons wilt laten weten?', 'sbdp'); ?>"
                                ><?php echo esc_textarea($opmerkingValue); ?></textarea>
                            </div>

                            <script>
                            (function(){
                                var toggle = document.getElementById('sbdp-offerte-is-company');
                                var companyFields = document.querySelectorAll('.sbdp-offerte-company-only');
                                function updateVisibility(){
                                    companyFields.forEach(function(el){ el.hidden = !toggle.checked; });
                                }
                                toggle.addEventListener('change', updateVisibility);
                                function updateVisibility(){
                                    companyFields.forEach(function(el){
                                        el.style.display = toggle.checked ? '' : 'none';
                                    });
                                }
                            })();
                            </script>

                            <div class="sbdp-offerte-form__submit">
                                <button type="submit" class="button wp-element-button sbdp-offerte-form__btn ddb-button ddb-button--primary">
                                    <?php esc_html_e('Ontvang mijn offertevoorstel', 'sbdp'); ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </section>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function renderSuccess(array $result): string
    {
        $requestReference = (string) ($result['request_reference'] ?? '');
        $quoteReference   = (string) ($result['quote_reference'] ?? '');

        ob_start();
        ?>
        <div class="sbdp-offerte-wrap sbdp-offerte-wrap--success">
            <div class="sbdp-offerte-notice sbdp-offerte-notice--success" role="status">
                <strong><?php esc_html_e('Jullie aanvraag is ontvangen.', 'sbdp'); ?></strong>
                <p><?php esc_html_e('Dank voor jullie aanvraag. We controleren nu de beschikbaarheid en sturen een passend voorstel per e-mail.', 'sbdp'); ?></p>
                <?php if ($requestReference !== '' || $quoteReference !== '') : ?>
                <p>
                    <?php if ($requestReference !== '') : ?>
                    <strong><?php esc_html_e('Aanvraagreferentie:', 'sbdp'); ?></strong> <?php echo esc_html($requestReference); ?>
                    <?php endif; ?>
                    <?php if ($quoteReference !== '') : ?>
                    <br><strong><?php esc_html_e('Quotereferentie:', 'sbdp'); ?></strong> <?php echo esc_html($quoteReference); ?>
                    <?php endif; ?>
                </p>
                <?php endif; ?>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    private static function renderError(string $message): string
    {
        return '<div class="sbdp-offerte-wrap"><div class="sbdp-offerte-notice sbdp-offerte-notice--error" role="alert">' . esc_html($message) . '</div></div>';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|string
     */
    private static function handleSubmit(int $planId, array $payload)
    {
        if (
            ! isset($_POST[self::NONCE_KEY])
            || ! wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST[self::NONCE_KEY])), self::NONCE_ACTION)
        ) {
            return __('Beveiligingscontrole mislukt. Probeer opnieuw.', 'sbdp');
        }

        $raw     = is_array($_POST['sbdp_offerte'] ?? null) ? $_POST['sbdp_offerte'] : array();
        $contact = self::sanitizeContactInput($raw);
        $error   = self::validateContactInput($contact);
        if ($error !== null) {
            return $error;
        }

        try {
            $result = self::createQuoteFromPlan($planId, $payload, $contact);
        } catch (Throwable $exception) {
            unset($exception);
            return __('Er ging iets mis bij het aanmaken van jullie aanvraag. Probeer het opnieuw.', 'sbdp');
        }

        if (! is_array($result) || empty($result['ok'])) {
            return __('Er ging iets mis. Probeer het opnieuw.', 'sbdp');
        }

        self::sendIntakeAcknowledgement((string) $contact['name'], (string) $contact['email'], $result);

        return $result;
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function sendIntakeAcknowledgement(string $name, string $email, array $result): void
    {
        if (! function_exists('wp_mail')) {
            return;
        }

        $siteName         = function_exists('get_bloginfo') ? (string) get_bloginfo('name') : 'DagjeDenBosch';
        $adminEmail       = function_exists('get_option') ? (string) get_option('admin_email', '') : '';
        $requestReference = (string) ($result['request_reference'] ?? '');
        $quoteReference   = (string) ($result['quote_reference'] ?? '');

        $subject = sprintf(__('Uw offerte-aanvraag is ontvangen – %s', 'sbdp'), $siteName);

        $message  = sprintf(__('Beste %s,', 'sbdp'), $name !== '' ? $name : __('gast', 'sbdp'));
        $message .= "\n\n";
        $message .= __('Bedankt voor uw aanvraag. We controleren nu de beschikbaarheid en werken een passend voorstel uit.', 'sbdp');
        $message .= "\n";
        $message .= __('U ontvangt van ons per e-mail een vervolgbericht met de volgende stap.', 'sbdp');
        $message .= "\n\n";
        if ($requestReference !== '') {
            $message .= sprintf(__('Aanvraagreferentie: %s', 'sbdp'), $requestReference) . "\n";
        }
        if ($quoteReference !== '') {
            $message .= sprintf(__('Quotereferentie: %s', 'sbdp'), $quoteReference) . "\n";
        }
        $message .= "\n";
        $message .= sprintf(__('Met vriendelijke groet,%sHet team van %s', 'sbdp'), "\n", $siteName);

        $headers = array('Content-Type: text/plain; charset=UTF-8');
        if ($adminEmail !== '') {
            $headers[] = 'From: ' . $siteName . ' <' . $adminEmail . '>';
        }

        wp_mail($email, $subject, $message, $headers);

        if ($adminEmail !== '') {
            $adminSubject = sprintf(__('Nieuwe offerte-aanvraag %s', 'sbdp'), $requestReference !== '' ? $requestReference : '#');
            $adminMessage = sprintf(__('Nieuwe offerte-intake van %s (%s).', 'sbdp'), $name !== '' ? $name : __('onbekend', 'sbdp'), $email);
            $contact      = isset($result['requester']) && is_array($result['requester']) ? $result['requester'] : array();
            if (! empty($contact['phone'])) {
                $adminMessage .= "\n" . sprintf(__('Telefoon: %s', 'sbdp'), (string) $contact['phone']);
            }
            if (! empty($contact['company'])) {
                $adminMessage .= "\n" . sprintf(__('Bedrijf: %s', 'sbdp'), (string) $contact['company']);
            }
            $address = isset($contact['address']) && is_array($contact['address']) ? $contact['address'] : array();
            if (! empty($address['city'])) {
                $adminMessage .= "\n" . sprintf(__('Woonplaats: %s', 'sbdp'), (string) $address['city']);
            }
            if ($requestReference !== '') {
                $adminMessage .= "\n" . sprintf(__('Aanvraagreferentie: %s', 'sbdp'), $requestReference);
            }
            if ($quoteReference !== '') {
                $adminMessage .= "\n" . sprintf(__('Quotereferentie: %s', 'sbdp'), $quoteReference);
            }
            if (function_exists('admin_url')) {
                $adminMessage .= "\n" . sprintf(__('Beheer via: %s', 'sbdp'), admin_url('admin.php?page=sbdp-quotes'));
            }
            wp_mail($adminEmail, $adminSubject, $adminMessage, array('Content-Type: text/plain; charset=UTF-8'));
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $contact
     * @return array<string, mixed>
     */
    private static function createQuoteFromPlan(int $planId, array $payload, array $contact): array
    {
        $repository  = new QuoteRepository();
        $events      = new QuoteEventLogger($repository);
        $requests    = new QuoteRequestService($repository, $events);
        $assumptions = new QuoteAssumptionService($repository, $events);
        $conversion  = new QuoteConversionService($repository, $assumptions, $events);
        $followups   = new QuoteFollowupService($repository, $events);
        $intake      = new PlannerQuoteIntakeService($requests, $conversion, $followups);

        $created = $intake->createFromPlannerPlan(
            $planId,
            $payload,
            $contact,
            function_exists('get_current_user_id') ? (int) get_current_user_id() : null
        );

        return array(
            'ok'                => true,
            'quote_id'          => (int) ($created['quote']['id'] ?? 0),
            'quote_reference'   => (string) ($created['quote']['quote_reference'] ?? ''),
            'request_id'        => (int) ($created['request']['id'] ?? 0),
            'request_reference' => (string) ($created['request']['request_reference'] ?? ''),
            'requester'         => isset($created['request']['normalized_payload']['requester']) && is_array($created['request']['normalized_payload']['requester'])
                ? $created['request']['normalized_payload']['requester']
                : array(),
        );
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private static function sanitizeContactInput(array $raw): array
    {
        $voornaam     = sanitize_text_field(wp_unslash((string) ($raw['voornaam'] ?? '')));
        $achternaam   = sanitize_text_field(wp_unslash((string) ($raw['achternaam'] ?? '')));
        $bedrijfsnaam = sanitize_text_field(wp_unslash((string) ($raw['bedrijfsnaam'] ?? '')));
        $referentie   = sanitize_text_field(wp_unslash((string) ($raw['referentiecode'] ?? '')));
        $woonplaats   = sanitize_text_field(wp_unslash((string) ($raw['woonplaats'] ?? '')));
        $opmerking    = sanitize_textarea_field(wp_unslash((string) ($raw['opmerking'] ?? '')));
        $isCompany    = ! empty($raw['is_company']);

        $messageParts = array();
        if ($referentie !== '') {
            $messageParts[] = 'Referentiecode: ' . $referentie;
        }
        if ($opmerking !== '') {
            $messageParts[] = $opmerking;
        }

        return array(
            'name'       => trim($voornaam . ' ' . $achternaam),
            'voornaam'   => $voornaam,
            'achternaam' => $achternaam,
            'email'      => sanitize_email(wp_unslash((string) ($raw['email'] ?? ''))),
            'phone'      => sanitize_text_field(wp_unslash((string) ($raw['phone'] ?? ''))),
            'company'    => $isCompany ? $bedrijfsnaam : '',
            'address'    => $woonplaats !== '' ? array('city' => $woonplaats) : array(),
            'message'    => implode("\n", $messageParts),
            'is_company' => $isCompany,
            'woonplaats' => $woonplaats,
        );
    }

    /**
     * @param array<string, mixed> $contact
     */
    private static function validateContactInput(array $contact): ?string
    {
        $name = trim((string) ($contact['name'] ?? ''));
        if ($name === '') {
            $voornaam = trim((string) ($contact['voornaam'] ?? ''));
            $achternaam = trim((string) ($contact['achternaam'] ?? ''));

            if ($voornaam === '') {
                return __('Vul jullie naam in.', 'sbdp');
            }

            if ($achternaam === '') {
                return __('Vul je achternaam in.', 'sbdp');
            }
        }

        if (! is_email((string) ($contact['email'] ?? ''))) {
            return __('Vul een geldig e-mailadres in.', 'sbdp');
        }

        if (! empty($contact['is_company']) && trim((string) ($contact['company'] ?? '')) === '') {
            return __('Vul de bedrijfsnaam in.', 'sbdp');
        }

        return null;
    }

    private static function postedField(string $key): string
    {
        $raw = is_array($_POST['sbdp_offerte'] ?? null) ? $_POST['sbdp_offerte'] : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        return isset($raw[$key]) ? (string) $raw[$key] : '';
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private static function buildDisplayItems(array $items): array
    {
        $displayItems = array();

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $unitPrice = (float) ($item['unit_price'] ?? 0.0);
            $quantity  = max(1, (int) ($item['quantity'] ?? 1));
            $lineTotal = (float) ($item['line_total'] ?? 0.0);
            if ((string) ($item['pricing_basis'] ?? '') === 'per_person' && $unitPrice > 0.0) {
                $lineTotal = $unitPrice * $quantity;
            } elseif ($lineTotal <= 0.0 && $unitPrice > 0.0) {
                $lineTotal = $unitPrice;
            }

            $item['line_total'] = round($lineTotal, 2);
            $displayItems[] = $item;
        }

        return $displayItems;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private static function sumDisplayItems(array $items): float
    {
        $total = 0.0;
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $total += (float) ($item['line_total'] ?? 0.0);
        }

        return round($total, 2);
    }

    private static function formatDate(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (function_exists('date_i18n')) {
            $timestamp = strtotime($raw);
            if ($timestamp !== false) {
                return (string) date_i18n('l j F Y', $timestamp);
            }
        }

        return $raw;
    }

    private static function formatTimeRange(string $start, string $end): string
    {
        $start = trim($start);
        $end   = trim($end);

        if ($start !== '' && $end !== '') {
            return $start . ' - ' . $end;
        }

        return $start !== '' ? $start : $end;
    }

    private static function formatCurrency(float $amount, string $currency = 'EUR'): string
    {
        if ($currency === 'EUR') {
            return '€ ' . number_format($amount, 2, ',', '.');
        }

        return $currency . ' ' . number_format($amount, 2, ',', '.');
    }

    private static function resolveAssetVersion(string $path): string
    {
        if (is_readable($path)) {
            $mtime = filemtime($path);
            if ($mtime !== false) {
                return (string) $mtime;
            }
        }

        return '4.23.19';
    }

    // renderStyles() has been removed - styles now loaded from sbdp-offerte-form.css
}
