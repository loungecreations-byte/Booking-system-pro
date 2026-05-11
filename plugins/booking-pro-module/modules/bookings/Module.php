<?php

declare(strict_types=1);

namespace BSP\Bookings;

if (class_exists(__NAMESPACE__ . '\\Module', false)) {
    return;
}

use BSP\Bookings\Rest\Controller;
use BSP\Bookings\Rest\CustomerDietaryController;
use BSP\Bookings\Rest\AdminDietaryController;
use BSP\Bookings\Rest\AccountController;
use BSP\Bookings\Shortcodes\OfferteForm;
use BSP\Bookings\Support\Installer;
use BSP\Bookings\WooCommerce\DietaryProductMeta;
use BSP\Bookings\WooCommerce\PaymentSync;
use BSP\Core\CoreServiceProvider;
use BSP\Core\Interfaces\ModuleInterface;

final class Module implements ModuleInterface
{
    public function init(): void
    {
        Installer::maybeInstall();

        CoreServiceProvider::logger()->log('Bookings module initialized');

        if (function_exists('add_action')) {
            add_action('init', [CustomerDietaryController::class, 'registerShortcode']);
            add_action('init', [AccountController::class, 'registerShortcode']);
            add_action('init', [DietaryProductMeta::class, 'register']);
            add_action('init', [OfferteForm::class, 'register']);
            add_action('rest_api_init', [Controller::class, 'register']);
            add_action('rest_api_init', [CustomerDietaryController::class, 'register']);
            add_action('rest_api_init', [AdminDietaryController::class, 'register']);
            add_action('rest_api_init', [AccountController::class, 'register']);
            add_action('woocommerce_payment_complete', [PaymentSync::class, 'handle'], 10, 1);
            add_action('woocommerce_order_status_processing', [PaymentSync::class, 'sendDietaryIntakeEmail'], 10, 1);
            // Offerte/aanvraag flow: send "Aanvraag ontvangen" email when a planner
            // request order is set to on-hold (no payment taken yet).
            add_action('woocommerce_order_status_on-hold', [self::class, 'sendAanvraagOntvangen'], 20, 1);
        }
    }

    /**
     * Send a "Uw aanvraag is ontvangen" confirmation email to the customer when
     * a planner-request order transitions to on-hold.
     * Only fires when the order carries sbdp_mode=request meta, so existing
     * on-hold orders from other sources are not affected.
     */
    public static function sendAanvraagOntvangen(int $orderId): void
    {
        if (! function_exists('wc_get_order') || ! function_exists('wp_mail')) {
            return;
        }

        $order = wc_get_order($orderId);
        if (! $order instanceof \WC_Order) {
            return;
        }

        // Only trigger for planner-originated requests.
        $mode = $order->get_meta('sbdp_mode', true);
        if ($mode !== 'request') {
            return;
        }

        $email = $order->get_billing_email();
        if ($email === '') {
            return;
        }

        $firstName = $order->get_billing_first_name();
        $siteName  = function_exists('get_bloginfo') ? get_bloginfo('name') : 'DagjeDenBosch';
        $viewUrl   = method_exists($order, 'get_view_order_url') ? $order->get_view_order_url() : '';
        $adminEmail = function_exists('get_option') ? (string) get_option('admin_email', '') : '';

        $subject = sprintf(
            /* translators: site name */
            __('Uw aanvraag is ontvangen – %s', 'sbdp'),
            $siteName
        );

        $message  = sprintf(__('Beste %s,', 'sbdp'), $firstName !== '' ? $firstName : __('gast', 'sbdp'));
        $message .= "\n\n";
        $message .= __('Bedankt voor uw aanvraag! We hebben uw dagplanning ontvangen en controleren nu de beschikbaarheid.', 'sbdp');
        $message .= "\n";
        $message .= __('Zodra we de beschikbaarheid hebben bevestigd, sturen we u een betaallink toe.', 'sbdp');
        $message .= "\n\n";
        if ($viewUrl !== '') {
            $message .= sprintf(__('U kunt uw aanvraag bekijken via: %s', 'sbdp'), $viewUrl);
            $message .= "\n\n";
        }
        $message .= sprintf(__('Met vriendelijke groet,\nHet team van %s', 'sbdp'), $siteName);

        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        if ($adminEmail !== '') {
            $headers[] = 'From: ' . $siteName . ' <' . $adminEmail . '>';
        }

        wp_mail($email, $subject, $message, $headers);

        // Also notify admin so they can confirm availability.
        if ($adminEmail !== '') {
            $adminSubject = sprintf(
                /* translators: order ID */
                __('Nieuwe planningsaanvraag #%d – beschikbaarheid bevestigen', 'sbdp'),
                $orderId
            );
            $adminMessage  = sprintf(__('Aanvraag #%d ontvangen van %s (%s).', 'sbdp'), $orderId, $order->get_formatted_billing_full_name(), $email);
            $adminMessage .= "\n";
            $adminMessage .= sprintf(__('Stuur betaallink via: %s', 'sbdp'), admin_url('admin.php?page=sbdp_bookings'));
            wp_mail($adminEmail, $adminSubject, $adminMessage, ['Content-Type: text/plain; charset=UTF-8']);
        }
    }
}

if (! class_exists('BSPModule\\Bookings\\Module', false)) {
    class_alias(Module::class, 'BSPModule\\Bookings\\Module');
}
