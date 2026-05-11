<?php

declare(strict_types=1);

namespace BSPModule\Core\WooCommerce;

use WC_Order;

final class CommercialFlowService
{
    private static bool $booted = false;
    private static bool $cartWrapperOpen = false;
    private static bool $checkoutWrapperOpen = false;
    private static bool $thankyouWrapperOpen = false;
    private static bool $accountWrapperOpen = false;

    public static function init(): void
    {
        if (self::$booted || ! function_exists('add_action')) {
            return;
        }

        self::$booted = true;

        add_filter('body_class', [__CLASS__, 'filterBodyClass']);
        add_filter('woocommerce_thankyou_order_received_text', [__CLASS__, 'filterOrderReceivedText'], 10, 2);
        add_filter('woocommerce_order_details_status', [__CLASS__, 'filterOrderDetailsStatus'], 10, 2);
        add_filter('woocommerce_cart_item_name', [__CLASS__, 'filterCartItemName'], 10, 3);
        add_filter('woocommerce_order_item_name', [__CLASS__, 'filterOrderItemName'], 10, 3);

        add_action('woocommerce_before_cart', [__CLASS__, 'renderCartStart'], 5);
        add_action('woocommerce_after_cart', [__CLASS__, 'renderCartEnd'], 50);

        add_action('woocommerce_before_checkout_form', [__CLASS__, 'renderCheckoutStart'], 5);
        add_action('woocommerce_after_checkout_form', [__CLASS__, 'renderCheckoutEnd'], 50);

        add_action('woocommerce_before_thankyou', [__CLASS__, 'renderThankyouStart'], 5);
        add_action('woocommerce_thankyou', [__CLASS__, 'renderThankyouEnd'], 999);
        add_action('woocommerce_view_order', [__CLASS__, 'renderViewOrderCard'], 5);

        add_action('woocommerce_before_account_navigation', [__CLASS__, 'renderAccountStart'], 5);
        add_action('woocommerce_after_account_content', [__CLASS__, 'renderAccountEnd'], 50);

        // Add checkout bridge script to footer on checkout
        add_action('wp_footer', [__CLASS__, 'injectCheckoutBridge'], 20);
    }

    // Inject a JS bridge to fix checkout split (place_order outside form)
    public static function injectCheckoutBridge(): void
    {
        if (! function_exists('is_checkout') || ! is_checkout() || self::isOrderReceivedEndpoint()) {
            return;
        }

        ?>
        <script id="sbdp-checkout-bridge">
        (function($){
            $(function(){
                var debug = false;
                try {
                    debug = new URL(window.location.href).searchParams.get('sbdpCheckoutDebug') === '1';
                } catch (err) {
                    debug = false;
                }

                function resolveCheckoutForm() {
                    var $form = $('form.woocommerce-checkout');
                    if (!$form.length) {
                        $form = $('form.checkout');
                    }
                    if (!$form.length) {
                        $form = $('form[name=\"checkout\"]');
                    }
                    return $form;
                }

                function bridgePlaceOrder($button) {
                    var $form = resolveCheckoutForm();
                    if (!$form.length || !$button || !$button.length) {
                        if (debug) { console.warn('[sbdp-checkout-bridge] missing form or button'); }
                        return false;
                    }
                    if ($form.find($button).length) {
                        if (debug) { console.info('[sbdp-checkout-bridge] button is inside form; no bridge needed'); }
                        return false;
                    }

                    var $payment = $('#payment');
                    if ($payment.length) {
                        $payment
                            .find('input[type="hidden"], input[type="radio"]:checked, select, textarea')
                            .each(function(){
                                var $input = $(this);
                                var name = $input.attr('name');
                                if (!name) { return; }
                                if (!$form.find('[name="' + name + '"]').length) {
                                    $form.append($input.clone());
                                }
                            });
                    }

                    if (debug) { console.info('[sbdp-checkout-bridge] submitting checkout form'); }
                    $form.trigger('submit');
                    return true;
                }

                // Use delegated handler so it survives Woo/Elementor fragment updates.
                $(document).off('click.sbdp-bridge', '#place_order');
                $(document).on('click.sbdp-bridge', '#place_order', function(e){
                    var $btn = $(this);
                    if (bridgePlaceOrder($btn)) {
                        e.preventDefault();
                        e.stopImmediatePropagation();
                    }
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    /**
     * @param array<int, string> $classes
     * @return array<int, string>
     */
    public static function filterBodyClass(array $classes): array
    {
        if (! self::isCommercialContext()) {
            return $classes;
        }

        $classes[] = 'sbdp-commercial-flow';

        if (function_exists('is_cart') && is_cart()) {
            $classes[] = 'sbdp-commercial-flow--cart';
        }

        if (function_exists('is_checkout') && is_checkout()) {
            $classes[] = 'sbdp-commercial-flow--checkout';
            if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received')) {
                $classes[] = 'sbdp-commercial-flow--thankyou';
            }
        }

        if (function_exists('is_account_page') && is_account_page()) {
            $classes[] = 'sbdp-commercial-flow--account';
            if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('view-order')) {
                $classes[] = 'sbdp-commercial-flow--view-order';
            }
        }

        return array_values(array_unique($classes));
    }

    public static function renderCartStart(): void
    {
        if (! function_exists('is_cart') || ! is_cart() || self::$cartWrapperOpen) {
            return;
        }

        self::$cartWrapperOpen = true;

        echo '<section class="ddb-commercial-flow ddb-commercial-flow--cart">';
        echo self::renderIntro(
            __('Besteloverzicht', 'sbdp'),
            __('Controleer hieronder jullie planning en pas waar nodig nog aantallen of keuzes aan voordat je verdergaat naar afrekenen.', 'sbdp')
        ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<div class="ddb-commercial-flow__content">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public static function renderCartEnd(): void
    {
        if (! self::$cartWrapperOpen) {
            return;
        }

        self::$cartWrapperOpen = false;
        echo '</div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public static function renderCheckoutStart($checkout): void // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
    {
        if (! function_exists('is_checkout') || ! is_checkout() || self::isOrderReceivedEndpoint() || self::$checkoutWrapperOpen) {
            return;
        }

        if (
            is_object($checkout)
            && method_exists($checkout, 'is_registration_enabled')
            && method_exists($checkout, 'is_registration_required')
            && ! $checkout->is_registration_enabled()
            && $checkout->is_registration_required()
            && function_exists('is_user_logged_in')
            && ! is_user_logged_in()
        ) {
            return;
        }

        self::$checkoutWrapperOpen = true;

        echo '<section class="ddb-commercial-flow ddb-commercial-flow--checkout">';
        echo '<div class="ddb-commercial-flow__content">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public static function renderCheckoutEnd($checkout): void // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
    {
        if (! self::$checkoutWrapperOpen) {
            return;
        }

        self::$checkoutWrapperOpen = false;
        echo '</div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public static function renderThankyouStart(int $orderId): void
    {
        if (self::$thankyouWrapperOpen) {
            return;
        }

        $order = function_exists('wc_get_order') ? wc_get_order($orderId) : null;
        if (! $order instanceof WC_Order) {
            return;
        }

        self::$thankyouWrapperOpen = true;

        $dateLabel = $order->get_date_created() ? wc_format_datetime($order->get_date_created()) : '';
        $payment = $order->get_payment_method_title();
        $nextStep = $order->needs_payment()
            ? __('Volgende stap: rond de betaling af om jullie dag definitief vast te zetten.', 'sbdp')
            : __('Volgende stap: jullie ontvangen per e-mail de bevestiging en verdere informatie.', 'sbdp');

        echo '<section class="ddb-commercial-flow ddb-commercial-flow--thankyou">';
        echo self::renderIntro(
            __('Bedankt, je bestelling is ontvangen', 'sbdp'),
            __('We hebben jullie bestelling goed ontvangen en gaan nu verder met de verwerking van jullie dag in Den Bosch.', 'sbdp')
        ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<div class="ddb-commercial-flow__content">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<section class="ddb-commercial-status-card ddb-commercial-status-card--success">';
        echo '<div class="ddb-commercial-status-card__header">';
        echo '<p class="ddb-commercial-status-card__eyebrow">' . esc_html__('Bevestiging', 'sbdp') . '</p>';
        echo '<h2 class="ddb-commercial-status-card__title">' . esc_html__('Bestelling ontvangen', 'sbdp') . '</h2>';
        echo '</div>';
        echo '<div class="ddb-commercial-status-card__grid">';
        echo self::renderStatusItem(__('Bestelnummer', 'sbdp'), '#' . $order->get_order_number());
        echo self::renderStatusItem(__('Datum', 'sbdp'), $dateLabel);
        echo self::renderStatusItem(__('Totaal', 'sbdp'), wp_strip_all_tags($order->get_formatted_order_total()));
        echo self::renderStatusItem(__('Betaalmethode', 'sbdp'), is_string($payment) ? $payment : '');
        echo '</div>';
        echo '<p class="ddb-commercial-status-card__next-step">' . esc_html($nextStep) . '</p>';
        echo '</section>';
    }

    public static function renderThankyouEnd(int $orderId): void // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
    {
        if (! self::$thankyouWrapperOpen) {
            return;
        }

        self::$thankyouWrapperOpen = false;
        echo '</div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public static function filterOrderReceivedText(string $text, $order): string
    {
        unset($text);

        if (! $order instanceof WC_Order) {
            return __('Bedankt. Je bestelling is ontvangen.', 'sbdp');
        }

        if ($order->needs_payment()) {
            return __('Je bestelling is ontvangen. Rond hieronder de betaling af om jullie dag definitief te bevestigen.', 'sbdp');
        }

        return __('Je bestelling is ontvangen. We sturen de bevestiging en verdere details ook per e-mail.', 'sbdp');
    }

    public static function filterOrderDetailsStatus(string $text, $order): string
    {
        if (! $order instanceof WC_Order || ! function_exists('is_wc_endpoint_url') || ! is_wc_endpoint_url('view-order')) {
            return $text;
        }

        return sprintf(
            __('Bestelling #%1$s is geplaatst op %2$s en staat momenteel op %3$s.', 'sbdp'),
            $order->get_order_number(),
            $order->get_date_created() ? wc_format_datetime($order->get_date_created()) : '',
            wc_get_order_status_name($order->get_status())
        );
    }

    /**
     * @param array<string, mixed> $cartItem
     */
    public static function filterCartItemName(string $name, array $cartItem, string $cartItemKey): string // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
    {
        unset($cartItem, $cartItemKey);

        return self::normalizeDisplayTitle($name);
    }

    public static function filterOrderItemName(string $name, $item, bool $isVisible): string // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
    {
        unset($item, $isVisible);

        return self::normalizeDisplayTitle($name);
    }

    public static function renderViewOrderCard(int $orderId): void
    {
        if (! function_exists('is_wc_endpoint_url') || ! is_wc_endpoint_url('view-order')) {
            return;
        }

        $order = function_exists('wc_get_order') ? wc_get_order($orderId) : null;
        if (! $order instanceof WC_Order) {
            return;
        }

        echo '<section class="ddb-commercial-status-card ddb-commercial-status-card--neutral">';
        echo '<div class="ddb-commercial-status-card__header">';
        echo '<p class="ddb-commercial-status-card__eyebrow">' . esc_html__('Bestelstatus', 'sbdp') . '</p>';
        echo '<h2 class="ddb-commercial-status-card__title">' . esc_html__('Jullie boekingsdetails', 'sbdp') . '</h2>';
        echo '</div>';
        echo '<div class="ddb-commercial-status-card__grid">';
        echo self::renderStatusItem(__('Bestelnummer', 'sbdp'), '#' . $order->get_order_number());
        echo self::renderStatusItem(__('Datum', 'sbdp'), $order->get_date_created() ? wc_format_datetime($order->get_date_created()) : '');
        echo self::renderStatusItem(__('Status', 'sbdp'), wc_get_order_status_name($order->get_status()));
        echo self::renderStatusItem(__('Totaal', 'sbdp'), wp_strip_all_tags($order->get_formatted_order_total()));
        echo '</div>';
        echo '<p class="ddb-commercial-status-card__next-step">' . esc_html__('Volgende stap: gebruik dit overzicht voor wijzigingen, updates en bevestigingsinformatie.', 'sbdp') . '</p>';
        echo '</section>';
    }

    public static function renderAccountStart(): void
    {
        if (! function_exists('is_account_page') || ! is_account_page() || self::$accountWrapperOpen) {
            return;
        }

        self::$accountWrapperOpen = true;

        echo '<section class="ddb-commercial-flow ddb-commercial-flow--account">';
        echo self::renderIntro(
            __('Mijn account', 'sbdp'),
            __('Bekijk jullie boekingen, openstaande acties en accountgegevens op een plek. Vanuit hier ga je direct door naar de juiste vervolgstap.', 'sbdp')
        ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<div class="ddb-commercial-flow__content">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public static function renderAccountEnd(): void
    {
        if (! self::$accountWrapperOpen) {
            return;
        }

        self::$accountWrapperOpen = false;
        echo '</div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    private static function isCommercialContext(): bool
    {
        if (function_exists('is_cart') && is_cart()) {
            return true;
        }

        if (function_exists('is_checkout') && is_checkout()) {
            return true;
        }

        return function_exists('is_account_page') && is_account_page();
    }

    private static function isOrderReceivedEndpoint(): bool
    {
        return function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received');
    }

    private static function renderIntro(string $title, string $text): string
    {
        return sprintf(
            '<header class="ddb-commercial-flow__intro"><p class="ddb-commercial-flow__eyebrow">%s</p><h1 class="ddb-commercial-flow__title">%s</h1><p class="ddb-commercial-flow__text">%s</p></header>',
            esc_html__('Dagje Den Bosch', 'sbdp'),
            esc_html($title),
            esc_html($text)
        );
    }

    private static function renderStatusItem(string $label, string $value): string
    {
        if ($value === '') {
            return '';
        }

        return sprintf(
            '<div class="ddb-commercial-status-card__item"><span class="ddb-commercial-status-card__label">%s</span><strong class="ddb-commercial-status-card__value">%s</strong></div>',
            esc_html($label),
            esc_html($value)
        );
    }

    private static function normalizeDisplayTitle(string $title): string
    {
        return preg_replace('/\bwaling dinner\b/i', 'Walking Dinner', $title) ?? $title;
    }
}
