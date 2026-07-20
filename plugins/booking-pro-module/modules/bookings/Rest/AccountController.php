<?php

declare(strict_types=1);

namespace BSP\Bookings\Rest;

use BSP\Bookings\Service\BookingService;
use BSP\Quotes\Service\PublicQuoteProposalTokenService;
use WP_REST_Request;
use function function_exists;
use function register_rest_route;
use function is_user_logged_in;
use function get_current_user_id;
use function add_shortcode;
use function rest_url;
use function esc_attr;
use function esc_js;
use function esc_html;
use function ob_start;
use function ob_get_clean;

final class AccountController
{
    public static function registerShortcode(): void
    {
        if (function_exists('add_shortcode')) {
            add_shortcode('bsp_account_bookings', array(__CLASS__, 'renderShortcode'));
            add_shortcode('ddb_account_hub', array(__CLASS__, 'renderHubShortcode'));
        }

        if (function_exists('add_action')) {
            add_action('woocommerce_account_dashboard', array(__CLASS__, 'renderWooAccountDashboardHub'), 1);
        }
    }

    public static function renderWooAccountDashboardHub(): void
    {
        echo self::renderHubShortcode(array('variant' => 'auto')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * @param array<string,mixed> $atts
     */
    public static function renderHubShortcode(array $atts = array()): string
    {
        $variant = isset($atts['variant']) ? self::normalizeHubVariant((string) $atts['variant']) : 'auto';
        $experience = $variant === 'auto' ? self::resolveHubExperience() : $variant;
        $isLoggedIn = is_user_logged_in();
        $loginUrl = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/');
        $ordersUrl = function_exists('wc_get_endpoint_url') ? wc_get_endpoint_url('orders', '', $loginUrl) : home_url('/my-account/orders/');
        $accountUrl = function_exists('wc_get_endpoint_url') ? wc_get_endpoint_url('edit-account', '', $loginUrl) : home_url('/my-account/edit-account/');
        $downloadsUrl = function_exists('wc_get_endpoint_url') ? wc_get_endpoint_url('downloads', '', $loginUrl) : home_url('/my-account/downloads/');
        $logoutUrl = function_exists('wc_logout_url') ? wc_logout_url() : wp_logout_url(home_url('/'));
        $planUrl = home_url('/plan-je-dag/');
        $contactUrl = home_url('/contact/');
        $faqUrl = home_url('/veel-gestelde-vragen/');

        $data = array(
            'eyebrow' => $experience === 'partner' ? 'Partner hub' : 'Mijn account',
            'title' => $experience === 'partner' ? 'Alles voor jouw partneropvolging' : 'Alles voor je DagjeDenBosch op één plek',
            'summary' => $experience === 'partner'
                ? 'Bekijk aanvragen, bevestigingen, boekingen en partnergegevens zonder een apart portaal.'
                : 'Bekijk boekingen, betalingen, tickets, tours en open acties vanuit één rustig overzicht.',
            'primary' => array('label' => $experience === 'partner' ? 'Aanvragen bekijken' : 'Boekingen bekijken', 'url' => $ordersUrl),
            'secondary' => array('label' => 'Account bewerken', 'url' => $accountUrl),
            'cards' => array(
                array('title' => 'Boekingen', 'description' => 'Open je bestellingen, betalingstatus en boekingsdetails.', 'label' => 'Boekingen openen', 'url' => $ordersUrl),
                array('title' => 'Tickets', 'description' => 'Bekijk downloads en persoonlijke tourlinks wanneer die beschikbaar zijn.', 'label' => 'Tickets openen', 'url' => $downloadsUrl),
                array('title' => 'Gegevens', 'description' => 'Beheer profiel, e-mail, wachtwoord en adressen.', 'label' => 'Gegevens beheren', 'url' => $accountUrl),
                array('title' => 'Support', 'description' => 'Neem contact op als een boeking of aanvraag handmatig gecontroleerd moet worden.', 'label' => 'Support contacteren', 'url' => $contactUrl),
            ),
        );

        if (! $isLoggedIn) {
            return self::renderLoggedOutHub($data, (string) $loginUrl, $planUrl);
        }

        $user = wp_get_current_user();
        $orders = $user instanceof \WP_User ? self::recentWooOrders($user, 20) : array();
        $quotes = $user instanceof \WP_User ? self::recentCustomerQuotes($user, 20) : array();
        $tickets = $user instanceof \WP_User ? self::privateTourTickets($user, 20) : array();
        $summaryCards = self::dashboardSummaryCards($orders, $quotes, $tickets);
        $actionCards = self::dashboardActionCards($orders, $quotes, $tickets, $ordersUrl, $planUrl);
        $nextItem = self::dashboardNextItem($orders, $tickets, $planUrl);
        $recentOrders = array_slice($orders, 0, 5);
        $recentQuotes = array_slice($quotes, 0, 5);
        $activeTickets = array_values(array_filter($tickets, static fn (array $ticket): bool => (string) ($ticket['status'] ?? '') === 'active'));

        ob_start();
        ?>
        <section class="ddb-account-dashboard" data-account-variant="<?php echo esc_attr($experience); ?>">
            <div class="ddb-account-dashboard__metrics" aria-label="<?php echo esc_attr('Account status'); ?>">
                <?php foreach ($summaryCards as $metric) : ?>
                    <article class="ddb-account-dashboard__metric ddb-account-dashboard__metric--<?php echo esc_attr((string) ($metric['tone'] ?? 'neutral')); ?>">
                        <strong><?php echo esc_html((string) ($metric['value'] ?? '0')); ?></strong>
                        <span><?php echo esc_html((string) ($metric['label'] ?? '')); ?></span>
                        <p><?php echo esc_html((string) ($metric['detail'] ?? '')); ?></p>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="ddb-account-dashboard__grid">
                <?php if ($nextItem !== array()) : ?>
                    <section class="ddb-account-dashboard__panel ddb-account-dashboard__next" aria-labelledby="ddb-account-next-title">
                        <div>
                            <p class="ddb-account-dashboard__eyebrow"><?php echo esc_html('Volgende stap'); ?></p>
                            <h2 id="ddb-account-next-title"><?php echo esc_html((string) ($nextItem['title'] ?? 'Je volgende boeking')); ?></h2>
                            <p><?php echo esc_html((string) ($nextItem['description'] ?? '')); ?></p>
                        </div>
                        <a class="ddb-account-dashboard__button ddb-account-dashboard__button--primary" href="<?php echo esc_url((string) ($nextItem['url'] ?? $planUrl)); ?>"><?php echo esc_html((string) ($nextItem['label'] ?? 'Bekijken')); ?></a>
                    </section>
                <?php endif; ?>

                <section class="ddb-account-dashboard__panel ddb-account-dashboard__actions" aria-labelledby="ddb-account-actions-title">
                    <div class="ddb-account-dashboard__panel-head">
                        <div>
                            <p class="ddb-account-dashboard__eyebrow"><?php echo esc_html('Nu belangrijk'); ?></p>
                            <h2 id="ddb-account-actions-title"><?php echo esc_html('Open acties'); ?></h2>
                        </div>
                    </div>
                    <div class="ddb-account-dashboard__action-list">
                        <?php foreach ($actionCards as $card) : ?>
                            <article class="ddb-account-dashboard__action ddb-account-dashboard__action--<?php echo esc_attr((string) ($card['tone'] ?? 'neutral')); ?>">
                                <span><?php echo esc_html((string) ($card['priority'] ?? 'Status')); ?></span>
                                <h3><?php echo esc_html((string) ($card['title'] ?? 'Actie')); ?></h3>
                                <p><?php echo esc_html((string) ($card['description'] ?? '')); ?></p>
                                <a class="ddb-account-dashboard__button ddb-account-dashboard__button--primary" href="<?php echo esc_url((string) ($card['url'] ?? $ordersUrl)); ?>"><?php echo esc_html((string) ($card['label'] ?? 'Openen')); ?></a>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="ddb-account-dashboard__panel ddb-account-dashboard__orders" aria-labelledby="ddb-account-orders-title">
                    <div class="ddb-account-dashboard__panel-head">
                        <div>
                            <p class="ddb-account-dashboard__eyebrow"><?php echo esc_html('Boekingen'); ?></p>
                            <h2 id="ddb-account-orders-title"><?php echo esc_html('Recente boekingen'); ?></h2>
                        </div>
                        <a href="<?php echo esc_url($ordersUrl); ?>"><?php echo esc_html('Alle boekingen bekijken'); ?></a>
                    </div>
                    <?php echo self::renderOrdersTable($recentOrders, $ordersUrl); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </section>

                <?php if ($activeTickets !== array()) : ?>
                    <?php $firstTicket = $activeTickets[0]; ?>
                    <section class="ddb-account-dashboard__panel ddb-account-dashboard__tickets" aria-labelledby="ddb-account-tickets-title">
                        <p class="ddb-account-dashboard__eyebrow"><?php echo esc_html('Tickets'); ?></p>
                        <h2 id="ddb-account-tickets-title"><?php echo esc_html('Tickets beschikbaar'); ?></h2>
                        <p><?php echo esc_html(sprintf('%d ticket(s) klaar. Eerstvolgend: %s', count($activeTickets), (string) ($firstTicket['tour_title'] ?? 'Private tour'))); ?></p>
                        <a class="ddb-account-dashboard__button ddb-account-dashboard__button--secondary" href="<?php echo esc_url((string) ($firstTicket['portal_url'] ?? $downloadsUrl)); ?>"><?php echo esc_html('Tickets openen'); ?></a>
                    </section>
                <?php endif; ?>

                <section class="ddb-account-dashboard__panel ddb-account-dashboard__quotes" aria-labelledby="ddb-account-quotes-title">
                    <div class="ddb-account-dashboard__panel-head">
                        <div>
                            <p class="ddb-account-dashboard__eyebrow"><?php echo esc_html('Aanvragen'); ?></p>
                            <h2 id="ddb-account-quotes-title"><?php echo esc_html('Recente aanvragen en offertes'); ?></h2>
                        </div>
                    </div>
                    <?php echo self::renderQuotesList($recentQuotes); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </section>

                <section class="ddb-account-dashboard__panel ddb-account-dashboard__support" aria-labelledby="ddb-account-support-title">
                    <p class="ddb-account-dashboard__eyebrow"><?php echo esc_html('Hulp'); ?></p>
                    <h2 id="ddb-account-support-title"><?php echo esc_html('Snel regelen'); ?></h2>
                    <div class="ddb-account-dashboard__quicklinks">
                        <a href="<?php echo esc_url($planUrl); ?>"><?php echo esc_html('Nieuwe activiteit boeken'); ?></a>
                        <a href="<?php echo esc_url($contactUrl); ?>"><?php echo esc_html('Contact opnemen'); ?></a>
                        <a href="<?php echo esc_url($faqUrl); ?>"><?php echo esc_html('Veelgestelde vragen'); ?></a>
                    </div>
                </section>
            </div>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    private static function normalizeHubVariant(string $variant): string
    {
        $variant = strtolower(trim($variant));
        return in_array($variant, array('auto', 'customer', 'partner', 'premium'), true) ? $variant : 'auto';
    }

    private static function resolveHubExperience(): string
    {
        $user = wp_get_current_user();
        if (! $user instanceof \WP_User || ! $user->exists()) {
            return 'customer';
        }

        foreach (array_map('strval', (array) $user->roles) as $role) {
            if (in_array($role, array('partner', 'partner_manager', 'partner-manager', 'account_partner', 'ddb_spots_analyst'), true)) {
                return 'partner';
            }
            if (in_array($role, array('premium', 'premium_member', 'premium-member', 'member', 'premium_customer'), true)) {
                return 'premium';
            }
        }

        return 'customer';
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function renderLoggedOutHub(array $data, string $loginUrl, string $planUrl): string
    {
        ob_start();
        ?>
        <section class="ui-section ui-section--tight ddb-account-hub" data-account-variant="guest">
            <div class="ui-container ui-container--lg">
                <div class="ui-card ui-card--featured ddb-account-hub__hero">
                    <div class="ui-card__body">
                        <p class="ddb-account-hub__eyebrow"><?php echo esc_html('Mijn account'); ?></p>
                        <h1 class="ui-card__title"><?php echo esc_html('Log in om je boekingen en tickets te bekijken'); ?></h1>
                        <p class="ui-card__desc"><?php echo esc_html('Na het inloggen zie je hier je open acties, boekingen, betalingen, tourtickets en partnerinformatie wanneer dat voor jouw account geldt.'); ?></p>
                        <div class="ddb-account-hub__actions">
                            <a class="ui-btn ui-btn--primary" href="<?php echo esc_url($loginUrl); ?>"><?php echo esc_html('Inloggen'); ?></a>
                            <a class="ui-btn ui-btn--secondary" href="<?php echo esc_url($planUrl); ?>"><?php echo esc_html('Plan je dag'); ?></a>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * @param array<int,array<string,mixed>> $orders
     * @param array<int,array<string,mixed>> $quotes
     * @param array<int,array<string,mixed>> $tickets
     * @return array<int,array<string,string>>
     */
    private static function dashboardSummaryCards(array $orders, array $quotes, array $tickets): array
    {
        $activeOrders = 0;
        $openPayments = 0;
        foreach ($orders as $order) {
            $status = (string) ($order['status'] ?? '');
            if (! in_array($status, array('cancelled', 'refunded', 'failed'), true)) {
                $activeOrders++;
            }
            if (! empty($order['needs_payment']) || in_array($status, array('pending', 'on-hold', 'failed'), true)) {
                $openPayments++;
            }
        }

        $openQuotes = 0;
        foreach ($quotes as $quote) {
            if (! in_array((string) ($quote['status'] ?? ''), array('accepted', 'declined', 'cancelled', 'closed'), true)) {
                $openQuotes++;
            }
        }

        $activeTickets = 0;
        foreach ($tickets as $ticket) {
            if ((string) ($ticket['status'] ?? '') === 'active') {
                $activeTickets++;
            }
        }

        $openActions = $openPayments + $openQuotes + $activeTickets;

        return array(
            array('label' => 'Actieve boekingen', 'value' => (string) $activeOrders, 'detail' => $activeOrders === 1 ? 'lopende boeking' : 'lopende boekingen', 'tone' => $activeOrders > 0 ? 'good' : 'neutral'),
            array('label' => 'Open acties', 'value' => (string) $openActions, 'detail' => $openActions > 0 ? 'aandacht nodig' : 'alles bijgewerkt', 'tone' => $openActions > 0 ? 'warning' : 'good'),
            array('label' => 'Tickets', 'value' => (string) $activeTickets, 'detail' => $activeTickets > 0 ? 'direct beschikbaar' : 'geen tickets open', 'tone' => $activeTickets > 0 ? 'good' : 'neutral'),
            array('label' => 'Aanvragen/offertes', 'value' => (string) count($quotes), 'detail' => $openQuotes > 0 ? $openQuotes . ' open' : 'geen open aanvraag', 'tone' => $openQuotes > 0 ? 'attention' : 'neutral'),
        );
    }

    /**
     * @param array<int,array<string,mixed>> $orders
     * @param array<int,array<string,mixed>> $quotes
     * @param array<int,array<string,mixed>> $tickets
     * @return array<int,array<string,string>>
     */
    private static function dashboardActionCards(array $orders, array $quotes, array $tickets, string $ordersUrl, string $planUrl): array
    {
        $actions = array();

        foreach ($orders as $order) {
            if (! empty($order['needs_payment'])) {
                $actions[] = array(
                    'title' => 'Betaling afronden',
                    'description' => sprintf('Bestelling #%s wacht nog op betaling. %s', (string) ($order['number'] ?? ''), (string) ($order['total'] ?? '')),
                    'label' => 'Betalen',
                    'url' => (string) (($order['pay_url'] ?? '') ?: ($order['url'] ?? $ordersUrl)),
                    'tone' => 'critical',
                    'priority' => 'Actie vereist',
                );
                break;
            }
        }

        foreach ($quotes as $quote) {
            $status = (string) ($quote['status'] ?? '');
            $url = (string) ($quote['url'] ?? '');
            if ($status === 'sent' && $url !== '') {
                $actions[] = array(
                    'title' => 'Voorstel bekijken',
                    'description' => sprintf('%s wacht op akkoord of wijziging.', (string) ($quote['reference'] ?? 'Je offerte')),
                    'label' => 'Voorstel openen',
                    'url' => $url,
                    'tone' => 'warning',
                    'priority' => 'Reactie nodig',
                );
                break;
            }
            if ($status === 'revision_requested' && $url !== '') {
                $actions[] = array(
                    'title' => 'Wijziging beoordelen',
                    'description' => sprintf('%s is bijgewerkt of staat ter controle.', (string) ($quote['reference'] ?? 'Je aanvraag')),
                    'label' => 'Status bekijken',
                    'url' => $url,
                    'tone' => 'attention',
                    'priority' => 'Opvolging',
                );
                break;
            }
        }

        foreach ($tickets as $ticket) {
            if ((string) ($ticket['status'] ?? '') === 'active' && ! empty($ticket['portal_url'])) {
                $actions[] = array(
                    'title' => 'Tour of tickets openen',
                    'description' => sprintf('%s staat klaar voor gebruik.', (string) ($ticket['tour_title'] ?? 'Je ticket')),
                    'label' => 'Openen',
                    'url' => (string) $ticket['portal_url'],
                    'tone' => 'good',
                    'priority' => 'Beschikbaar',
                );
                break;
            }
        }

        if ($actions === array()) {
            $actions[] = array(
                'title' => 'Geen open acties',
                'description' => 'Je hoeft nu niets te doen. Je kunt verder plannen of later terugkomen.',
                'label' => 'Plan je dag',
                'url' => $planUrl,
                'tone' => 'neutral',
                'priority' => 'Rustig',
            );
        }

        return array_slice($actions, 0, 4);
    }

    /**
     * @param array<int,array<string,mixed>> $orders
     * @param array<int,array<string,mixed>> $tickets
     * @return array<string,string>
     */
    private static function dashboardNextItem(array $orders, array $tickets, string $planUrl): array
    {
        foreach ($tickets as $ticket) {
            if ((string) ($ticket['status'] ?? '') === 'active') {
                return array(
                    'title' => (string) ($ticket['tour_title'] ?? 'Je tour staat klaar'),
                    'description' => (string) ($ticket['access_label'] ?? 'Je persoonlijke ticket is beschikbaar.'),
                    'label' => 'Tour openen',
                    'url' => (string) ($ticket['portal_url'] ?? $planUrl),
                );
            }
        }

        foreach ($orders as $order) {
            if (! in_array((string) ($order['status'] ?? ''), array('cancelled', 'refunded', 'failed'), true)) {
                return array(
                    'title' => sprintf('Bestelling #%s', (string) ($order['number'] ?? '')),
                    'description' => sprintf('%s · %s', (string) ($order['status_label'] ?? 'Status onbekend'), (string) ($order['total'] ?? '')),
                    'label' => 'Boeking bekijken',
                    'url' => (string) ($order['url'] ?? $planUrl),
                );
            }
        }

        return array(
            'title' => 'Nog geen boeking gepland',
            'description' => 'Kies een activiteit en stel je dag samen.',
            'label' => 'Activiteit boeken',
            'url' => $planUrl,
        );
    }

    /**
     * @param array<int,array<string,mixed>> $orders
     */
    private static function renderOrdersTable(array $orders, string $ordersUrl): string
    {
        if ($orders === array()) {
            return '<div class="ddb-account-dashboard__empty"><p>' . esc_html('Je hebt nog geen boekingen.') . '</p><a href="' . esc_url(home_url('/activiteiten-den-bosch/')) . '">' . esc_html('Bekijk activiteiten') . '</a></div>';
        }

        ob_start();
        ?>
        <div class="ddb-account-dashboard__table-wrap">
            <table class="ddb-account-dashboard__table">
                <thead>
                    <tr>
                        <th scope="col"><?php echo esc_html('Datum'); ?></th>
                        <th scope="col"><?php echo esc_html('Boeking'); ?></th>
                        <th scope="col"><?php echo esc_html('Type'); ?></th>
                        <th scope="col"><?php echo esc_html('Status'); ?></th>
                        <th scope="col"><?php echo esc_html('Bedrag'); ?></th>
                        <th scope="col"><?php echo esc_html('Actie'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order) : ?>
                        <tr>
                            <td data-label="<?php echo esc_attr('Datum'); ?>"><?php echo esc_html((string) ($order['date'] ?? '')); ?></td>
                            <td data-label="<?php echo esc_attr('Boeking'); ?>"><strong><?php echo esc_html(sprintf('Bestelling #%s', (string) ($order['number'] ?? ''))); ?></strong></td>
                            <td data-label="<?php echo esc_attr('Type'); ?>"><?php echo esc_html('Boeking'); ?></td>
                            <td data-label="<?php echo esc_attr('Status'); ?>"><span class="ddb-account-dashboard__status"><?php echo esc_html((string) ($order['status_label'] ?? 'Onbekend')); ?></span></td>
                            <td data-label="<?php echo esc_attr('Bedrag'); ?>" class="ddb-account-dashboard__amount"><?php echo esc_html((string) ($order['total'] ?? '')); ?></td>
                            <td data-label="<?php echo esc_attr('Actie'); ?>"><a href="<?php echo esc_url((string) ($order['url'] ?? $ordersUrl)); ?>"><?php echo esc_html('Bekijken'); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * @param array<int,array<string,mixed>> $quotes
     */
    private static function renderQuotesList(array $quotes): string
    {
        if ($quotes === array()) {
            return '<div class="ddb-account-dashboard__empty"><p>' . esc_html('Er zijn geen aanvragen of offertes gevonden.') . '</p></div>';
        }

        ob_start();
        ?>
        <div class="ddb-account-dashboard__quote-list">
            <?php foreach ($quotes as $quote) : ?>
                <article class="ddb-account-dashboard__quote">
                    <div>
                        <span><?php echo esc_html((string) ($quote['reference'] ?? 'Offerte')); ?></span>
                        <h3><?php echo esc_html((string) ($quote['title'] ?? 'Aanvraag of offerte')); ?></h3>
                        <p><?php echo esc_html(trim((string) ($quote['date'] ?? '') . ' · ' . (string) ($quote['status_label'] ?? 'Status onbekend'), ' ·')); ?></p>
                    </div>
                    <?php if ((string) ($quote['url'] ?? '') !== '') : ?>
                        <a href="<?php echo esc_url((string) $quote['url']); ?>"><?php echo esc_html('Bekijken'); ?></a>
                    <?php else : ?>
                        <span class="ddb-account-dashboard__status"><?php echo esc_html('In behandeling'); ?></span>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * @return array<int,array<string,string>>
     */
    private static function hubSummaryCards(\WP_User $user, string $experience): array
    {
        $orders = self::recentWooOrders($user, 20);
        $tickets = self::privateTourTickets($user, 20);
        $quotes = self::recentCustomerQuotes($user, 20);
        $partner = $experience === 'partner' ? self::partnerConfirmationSummary($user) : array('open' => 0);
        $activeOrders = 0;
        $pendingPayments = 0;
        $activeTickets = 0;
        $openQuotes = 0;

        foreach ($orders as $order) {
            $status = (string) ($order['status'] ?? '');
            if (! in_array($status, array('cancelled', 'refunded', 'failed'), true)) {
                $activeOrders++;
            }
            if (in_array($status, array('pending', 'on-hold', 'failed'), true)) {
                $pendingPayments++;
            }
        }

        foreach ($tickets as $ticket) {
            if ((string) ($ticket['status'] ?? '') === 'active') {
                $activeTickets++;
            }
        }

        foreach ($quotes as $quote) {
            if (! in_array((string) ($quote['status'] ?? ''), array('accepted', 'declined', 'cancelled', 'closed'), true)) {
                $openQuotes++;
            }
        }

        $cards = array(
            array('label' => 'Actieve boekingen', 'value' => (string) $activeOrders, 'detail' => $activeOrders === 1 ? 'lopende bestelling' : 'lopende bestellingen', 'tone' => $activeOrders > 0 ? 'good' : 'neutral'),
            array('label' => 'Aanvragen/offertes', 'value' => (string) count($quotes), 'detail' => $openQuotes > 0 ? $openQuotes . ' open' : 'geen open offerteactie', 'tone' => $openQuotes > 0 ? 'warning' : 'neutral'),
            array('label' => 'Open betaling', 'value' => (string) $pendingPayments, 'detail' => $pendingPayments > 0 ? 'actie nodig' : 'geen betaalactie', 'tone' => $pendingPayments > 0 ? 'warning' : 'neutral'),
            array('label' => 'Tour tickets', 'value' => (string) $activeTickets, 'detail' => $activeTickets > 0 ? 'klaar voor gebruik' : 'geen actieve tickets', 'tone' => $activeTickets > 0 ? 'good' : 'neutral'),
        );

        if ($experience === 'partner') {
            $open = (int) ($partner['open'] ?? 0);
            $cards[] = array('label' => 'Partneracties', 'value' => (string) $open, 'detail' => $open > 0 ? 'wachten op reactie' : 'geen open bevestigingen', 'tone' => $open > 0 ? 'warning' : 'good');
        }

        return $cards;
    }

    /**
     * @return array<int,array<string,string>>
     */
    private static function hubActionCards(\WP_User $user, string $experience, string $ordersUrl, string $planUrl): array
    {
        $cards = array();
        foreach (self::recentWooOrders($user, 10) as $order) {
            if (in_array((string) ($order['status'] ?? ''), array('pending', 'on-hold', 'failed'), true)) {
                $cards[] = array('title' => 'Betaling afronden', 'description' => sprintf('Bestelling #%s wacht nog op betaling of controle.', (string) ($order['number'] ?? '')), 'label' => 'Bestelling openen', 'url' => (string) ($order['url'] ?? $ordersUrl), 'tone' => 'warning');
                break;
            }
        }
        foreach (self::recentCustomerQuotes($user, 10) as $quote) {
            $status = (string) ($quote['status'] ?? '');
            $url = (string) ($quote['url'] ?? '');
            if ($status === 'sent' && $url !== '') {
                $cards[] = array('title' => 'Offerte bekijken', 'description' => sprintf('%s wacht op akkoord of wijziging.', (string) ($quote['reference'] ?? 'Je offerte')), 'label' => 'Voorstel openen', 'url' => $url, 'tone' => 'warning');
                break;
            }
            if ($status === 'revision_requested' && $url !== '') {
                $cards[] = array('title' => 'Wijziging ontvangen', 'description' => sprintf('%s staat bij ons op de opvolglijst.', (string) ($quote['reference'] ?? 'Je offerte')), 'label' => 'Status bekijken', 'url' => $url, 'tone' => 'neutral');
                break;
            }
            if ($status === 'accepted' && $url !== '' && (int) ($quote['order_id'] ?? 0) <= 0) {
                $cards[] = array('title' => 'Akkoord verwerkt', 'description' => 'Je akkoord is ontvangen. We zetten de volgende stap klaar.', 'label' => 'Status bekijken', 'url' => $url, 'tone' => 'good');
                break;
            }
        }
        foreach (self::privateTourTickets($user, 10) as $ticket) {
            if ((string) ($ticket['status'] ?? '') === 'active' && ! empty($ticket['portal_url'])) {
                $cards[] = array('title' => 'Tour starten', 'description' => sprintf('%s is beschikbaar met je persoonlijke ticket.', (string) ($ticket['tour_title'] ?? 'Je tour')), 'label' => 'Open tour', 'url' => (string) $ticket['portal_url'], 'tone' => 'good');
                break;
            }
        }
        if ($experience === 'partner') {
            $partner = self::partnerConfirmationSummary($user);
            if ((int) ($partner['open'] ?? 0) > 0) {
                $cards[] = array('title' => 'Partnerbevestigingen', 'description' => sprintf('%d aanvraag(en) wachten op bevestiging of alternatief.', (int) $partner['open']), 'label' => 'Aanvragen bekijken', 'url' => $ordersUrl, 'tone' => 'warning');
            }
        }
        if ($cards === array()) {
            $cards[] = array('title' => 'Geen open acties', 'description' => 'Alles wat nu bekend is staat klaar. Je kunt verder plannen of je gegevens beheren.', 'label' => 'Plan je dag', 'url' => $planUrl, 'tone' => 'neutral');
        }
        return array_slice($cards, 0, 4);
    }

    /**
     * @return array<int,array<string,string>>
     */
    private static function hubActivityCards(\WP_User $user, string $ordersUrl): array
    {
        $cards = array();
        foreach (self::recentWooOrders($user, 3) as $order) {
            $cards[] = array('title' => sprintf('Bestelling #%s', (string) ($order['number'] ?? '')), 'meta' => (string) ($order['date'] ?? ''), 'description' => sprintf('%s · %s', (string) ($order['status_label'] ?? 'Status onbekend'), (string) ($order['total'] ?? '')), 'label' => 'Bekijken', 'url' => (string) ($order['url'] ?? $ordersUrl));
        }
        foreach (self::recentCustomerQuotes($user, 3) as $quote) {
            $cards[] = array('title' => (string) ($quote['title'] ?? 'Aanvraag of offerte'), 'meta' => (string) ($quote['reference'] ?? 'Offerte'), 'description' => (string) ($quote['status_label'] ?? 'Status onbekend'), 'label' => (string) ($quote['url'] ?? '') !== '' ? 'Status bekijken' : 'In behandeling', 'url' => (string) (($quote['url'] ?? '') ?: $ordersUrl));
        }
        foreach (self::privateTourTickets($user, 3) as $ticket) {
            $cards[] = array('title' => (string) ($ticket['tour_title'] ?? 'Private tour'), 'meta' => (string) ($ticket['status_label'] ?? 'Ticket'), 'description' => (string) ($ticket['access_label'] ?? 'Persoonlijke tourlink beschikbaar.'), 'label' => 'Tour openen', 'url' => (string) ($ticket['portal_url'] ?? home_url('/private-tour-portal/')));
        }
        return array_slice($cards, 0, 6);
    }

    /**
     * @return array<int,array<string,string|int>>
     */
    private static function recentCustomerQuotes(\WP_User $user, int $limit): array
    {
        global $wpdb;
        if (! $wpdb instanceof \wpdb || ! $user->exists()) {
            return array();
        }

        $quotesTable = $wpdb->prefix . 'bsp_quotes';
        $requestsTable = $wpdb->prefix . 'bsp_quote_requests';
        $versionsTable = $wpdb->prefix . 'bsp_quote_versions';
        if (! self::tableExists($quotesTable) || ! self::tableExists($requestsTable)) {
            return array();
        }

        $email = strtolower(trim((string) $user->user_email));
        if ($email === '') {
            return array();
        }

        $hasVersions = self::tableExists($versionsTable);
        $titleSelect = $hasVersions ? ', v.proposal_title AS proposal_title' : ", '' AS proposal_title";
        $titleJoin = $hasVersions ? " LEFT JOIN {$versionsTable} v ON v.id = q.current_version_id" : '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT q.id, q.quote_reference, q.status, q.send_status, q.current_version_id, q.approved_version_id, q.woo_order_id, q.updated_at, r.request_summary, r.preferred_date{$titleSelect}
            FROM {$quotesTable} q
            INNER JOIN {$requestsTable} r ON r.id = q.quote_request_id{$titleJoin}
            WHERE (q.customer_id = %d OR r.customer_id = %d OR LOWER(r.requester_email) = %s)
            ORDER BY q.updated_at DESC, q.id DESC
            LIMIT %d",
            (int) $user->ID,
            (int) $user->ID,
            $email,
            max(1, $limit)
        ), ARRAY_A);

        $items = array();
        foreach ((array) $rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $quoteId = (int) ($row['id'] ?? 0);
            $reference = trim((string) ($row['quote_reference'] ?? ''));
            $status = (string) ($row['status'] ?? '');
            $versionId = self::publicQuoteVersionId($row);
            $url = $versionId > 0 && $reference !== '' ? self::customerWorkspaceUrl($quoteId, $versionId, $reference) : '';
            $title = trim((string) ($row['proposal_title'] ?? ''));
            if ($title === '') {
                $title = trim((string) ($row['request_summary'] ?? ''));
            }
            $items[] = array(
                'id' => $quoteId,
                'reference' => $reference !== '' ? $reference : 'Offerte',
                'status' => $status,
                'status_label' => self::customerQuoteStatusLabel($status, (string) ($row['send_status'] ?? '')),
                'title' => $title !== '' ? $title : 'Aanvraag of offerte',
                'date' => self::quoteDateLabel((string) ($row['preferred_date'] ?? '')),
                'order_id' => (int) ($row['woo_order_id'] ?? 0),
                'url' => $url,
            );
        }

        return $items;
    }

    private static function tableExists(string $table): bool
    {
        global $wpdb;
        return $wpdb instanceof \wpdb && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    /**
     * @param array<string,mixed> $quote
     */
    private static function publicQuoteVersionId(array $quote): int
    {
        $status = (string) ($quote['status'] ?? '');
        if ($status === 'accepted') {
            return (int) ($quote['approved_version_id'] ?? 0);
        }
        if (in_array($status, array('sent', 'revision_requested', 'declined'), true)) {
            return self::latestSentProposalVersionId((int) ($quote['id'] ?? 0));
        }
        return 0;
    }

    private static function latestSentProposalVersionId(int $quoteId): int
    {
        global $wpdb;
        if (! $wpdb instanceof \wpdb || $quoteId <= 0) {
            return 0;
        }

        $messagesTable = $wpdb->prefix . 'bsp_quote_messages';
        if (! self::tableExists($messagesTable)) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT quote_version_id FROM {$messagesTable}
            WHERE quote_id = %d AND direction = 'outbound' AND message_type = 'proposal' AND status = 'sent' AND quote_version_id IS NOT NULL
            ORDER BY created_at DESC, id DESC
            LIMIT 1",
            $quoteId
        ));
    }

    private static function customerWorkspaceUrl(int $quoteId, int $versionId, string $quoteReference): string
    {
        if ($quoteId <= 0 || $versionId <= 0 || $quoteReference === '') {
            return '';
        }

        $token = (new PublicQuoteProposalTokenService())->create($quoteId, $versionId, $quoteReference);
        $base = function_exists('home_url') ? (string) home_url('/') : '/';
        return function_exists('add_query_arg')
            ? (string) add_query_arg(array('ddb_customer_workspace' => $token), $base)
            : $base . (str_contains($base, '?') ? '&' : '?') . http_build_query(array('ddb_customer_workspace' => $token));
    }

    private static function customerQuoteStatusLabel(string $status, string $sendStatus): string
    {
        if ($status === 'sent') {
            return 'Voorstel wacht op reactie';
        }
        if ($status === 'accepted') {
            return 'Akkoord ontvangen';
        }
        if ($status === 'revision_requested') {
            return 'Wijziging aangevraagd';
        }
        if ($status === 'declined') {
            return 'Afgewezen';
        }
        if ($sendStatus === 'ready_to_send') {
            return 'Offerte klaar voor verzending';
        }
        return 'Aanvraag in behandeling';
    }

    private static function quoteDateLabel(string $date): string
    {
        $date = trim($date);
        if ($date === '') {
            return 'Datum in overleg';
        }

        $timestamp = strtotime($date);
        if (! $timestamp) {
            return $date;
        }

        return function_exists('date_i18n') ? (string) date_i18n('j M Y', $timestamp) : date('j M Y', $timestamp);
    }

    /**
     * @return array<int,array<string,string|int>>
     */
    private static function recentWooOrders(\WP_User $user, int $limit): array
    {
        if (! function_exists('wc_get_orders') || ! $user->exists()) {
            return array();
        }
        $orders = wc_get_orders(array('customer_id' => (int) $user->ID, 'limit' => max(1, $limit), 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects'));
        $items = array();
        foreach ((array) $orders as $order) {
            if (! $order instanceof \WC_Order) {
                continue;
            }
            $status = (string) $order->get_status();
            $date = $order->get_date_created() ? $order->get_date_created()->date_i18n('j M Y') : '';
            $needsPayment = method_exists($order, 'needs_payment') ? (bool) $order->needs_payment() : in_array($status, array('pending', 'failed'), true);
            $payUrl = $needsPayment && method_exists($order, 'get_checkout_payment_url')
                ? (string) $order->get_checkout_payment_url()
                : '';
            $items[] = array(
                'id' => (int) $order->get_id(),
                'number' => (string) $order->get_order_number(),
                'status' => $status,
                'status_label' => function_exists('wc_get_order_status_name') ? wc_get_order_status_name($status) : $status,
                'date' => $date,
                'total' => wp_strip_all_tags((string) $order->get_formatted_order_total()),
                'url' => (string) $order->get_view_order_url(),
                'pay_url' => $payUrl,
                'needs_payment' => $needsPayment,
            );
        }
        return $items;
    }

    /**
     * @return array<int,array<string,string|int>>
     */
    private static function privateTourTickets(\WP_User $user, int $limit): array
    {
        global $wpdb;
        if (! $wpdb instanceof \wpdb || ! $user->exists() || (string) $user->user_email === '') {
            return array();
        }
        $table = $wpdb->prefix . 'sbdp_private_tour_tickets';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return array();
        }
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, tour_id, order_id, token, status, access_expires_at, created_at FROM {$table} WHERE email = %s ORDER BY created_at DESC LIMIT %d", (string) $user->user_email, max(1, $limit)), ARRAY_A);
        $tickets = array();
        foreach ((array) $rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $tourId = (int) ($row['tour_id'] ?? 0);
            $token = (string) ($row['token'] ?? '');
            $status = (string) ($row['status'] ?? '');
            $accessExpires = (string) ($row['access_expires_at'] ?? '');
            $tickets[] = array('id' => (int) ($row['id'] ?? 0), 'tour_id' => $tourId, 'order_id' => (int) ($row['order_id'] ?? 0), 'status' => $status, 'status_label' => $status === 'active' ? 'Actief ticket' : ucfirst($status ?: 'ticket'), 'tour_title' => $tourId > 0 ? get_the_title($tourId) : 'Private tour', 'portal_url' => $token !== '' ? add_query_arg('ticket', $token, home_url('/private-tour-portal/')) : home_url('/private-tour-portal/'), 'access_label' => $accessExpires !== '' ? sprintf('Toegang actief tot %s', mysql2date('j M Y H:i', $accessExpires)) : 'Nog niet gestart of geen actief venster.');
        }
        return $tickets;
    }

    /**
     * @return array{open:int,confirmed:int,declined:int}
     */
    private static function partnerConfirmationSummary(\WP_User $user): array
    {
        global $wpdb;
        $summary = array('open' => 0, 'confirmed' => 0, 'declined' => 0);
        if (! $wpdb instanceof \wpdb || ! $user->exists()) {
            return $summary;
        }
        $accountsTable = $wpdb->prefix . 'bsp_partner_accounts';
        $confirmationsTable = $wpdb->prefix . 'bsp_partner_confirmations';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $accountsTable)) !== $accountsTable || $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $confirmationsTable)) !== $confirmationsTable) {
            return $summary;
        }
        $vendorId = (int) $wpdb->get_var($wpdb->prepare("SELECT vendor_id FROM {$accountsTable} WHERE wp_user_id = %d AND account_status != 'archived' ORDER BY id DESC LIMIT 1", (int) $user->ID));
        if ($vendorId <= 0) {
            return $summary;
        }
        $rows = $wpdb->get_results($wpdb->prepare("SELECT status, COUNT(*) AS total FROM {$confirmationsTable} WHERE supplier_id = %d GROUP BY status", $vendorId), ARRAY_A);
        foreach ((array) $rows as $row) {
            $status = (string) ($row['status'] ?? '');
            $total = (int) ($row['total'] ?? 0);
            if (in_array($status, array('confirmed', 'supplier_booking_confirmed'), true)) {
                $summary['confirmed'] += $total;
            } elseif (in_array($status, array('declined', 'supplier_declined', 'unavailable'), true)) {
                $summary['declined'] += $total;
            } else {
                $summary['open'] += $total;
            }
        }
        return $summary;
    }

    public static function register(): void
    {
        if (! function_exists('register_rest_route')) {
            return;
        }

        register_rest_route('bsp/v1', '/account/bookings', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'getMyBookings'),
            'permission_callback' => array(__CLASS__, 'authorizeUser'),
        ));
    }

    public static function authorizeUser(): bool
    {
        return is_user_logged_in();
    }

    public static function renderShortcode(): string
    {
        if (! is_user_logged_in()) {
            return '<div class="sbdp-account-bookings__gate"><p>' . esc_html('Log in om uw boekingen te bekijken.') . '</p></div>';
        }

        $apiUrl = function_exists('rest_url') ? esc_js(rest_url('bsp/v1/account/bookings')) : '';
        $nonce  = function_exists('wp_create_nonce') ? esc_attr(wp_create_nonce('wp_rest')) : '';

        ob_start();
        ?>
        <div id="sbdp-account-bookings-root" class="sbdp-account-bookings"
             data-api-url="<?php echo $apiUrl; ?>"
             data-nonce="<?php echo $nonce; ?>">
            <div class="sbdp-account-bookings__loading">Boekingen laden&hellip;</div>
        </div>
        <script>
        (function () {
            const root = document.getElementById('sbdp-account-bookings-root');
            if (!root) return;

            const apiUrl = root.dataset.apiUrl;
            const nonce  = root.dataset.nonce;

            function esc(str) {
                const d = document.createElement('div');
                d.textContent = String(str || '');
                return d.innerHTML;
            }

            function statusLabel(status) {
                const labels = { paid: 'Betaald', pending: 'In behandeling', cancelled: 'Geannuleerd', completed: 'Voltooid', draft: 'Concept' };
                return labels[status] || esc(status);
            }

            function render(bookings) {
                if (!bookings.length) {
                    root.innerHTML = '<div class="sbdp-account-bookings__empty"><p>Geen boekingen gevonden.</p></div>';
                    return;
                }

                let rows = bookings.map(function (b) {
                    const participants = b.participants ? b.participants : '&mdash;';
                    return '<tr>'
                        + '<td>' + esc(b.date || '&mdash;') + '</td>'
                        + '<td>' + esc(b.time || '&mdash;') + '</td>'
                        + '<td>' + esc(b.resource || b.notes || '&mdash;') + '</td>'
                        + '<td>' + esc(participants) + '</td>'
                        + '<td><span class="sbdp-status sbdp-status--' + esc(b.status) + '">' + statusLabel(b.status) + '</span></td>'
                        + '</tr>';
                }).join('');

                root.innerHTML = '<div class="sbdp-account-bookings__table-wrapper">'
                    + '<h2>Mijn Boekingen</h2>'
                    + '<table class="sbdp-account-bookings__table"><thead><tr>'
                    + '<th>Datum</th><th>Tijd</th><th>Activiteit</th><th>Deelnemers</th><th>Status</th>'
                    + '</tr></thead><tbody>' + rows + '</tbody></table>'
                    + '</div>';
            }

            fetch(apiUrl, {
                headers: { 'X-WP-Nonce': nonce }
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    render(Array.isArray(data.bookings) ? data.bookings : []);
                })
                .catch(function () {
                    root.innerHTML = '<div class="sbdp-account-bookings__error">Er is een fout opgetreden. Probeer het opnieuw.</div>';
                });
        })();
        </script>
        <?php
        return (string) ob_get_clean();
    }

    public static function getMyBookings(WP_REST_Request $request)
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return new \WP_Error('unauthorized', 'User not logged in', array('status' => 401));
        }

        $user  = get_userdata($userId);
        $email = $user ? $user->user_email : '';

        $bookings = BookingService::getBookings(array('customer_email' => $email));

        return rest_ensure_response(array(
            'bookings' => array_values($bookings),
            'success'  => true,
        ));
    }
}
