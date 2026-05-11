<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

use RuntimeException;

final class WooCartLaunchGateway implements WooCartLaunchGatewayInterface
{
    private static bool $hooksRegistered = false;

    public static function registerHooks(): void
    {
        if (self::$hooksRegistered || ! function_exists('add_action')) {
            return;
        }

        \add_action('woocommerce_cart_calculate_fees', array(self::class, 'applySessionQuoteDiscount'), 20, 1);
        self::$hooksRegistered = true;
    }

    /**
     * @param array<string, mixed> $launchPayload
     * @return array<string, mixed>
     */
    public function hydrate(array $launchPayload): array
    {
        if (! function_exists('WC') || ! function_exists('wc_get_product')) {
            throw new RuntimeException('WooCommerce is niet beschikbaar voor cart hydration.');
        }

        self::registerHooks();
        $this->ensureCartSession();
        if (! \WC()->cart) {
            throw new RuntimeException('Woo cart kon niet worden geopend.');
        }

        if (function_exists('wc_clear_notices')) {
            wc_clear_notices();
        }

        \WC()->cart->empty_cart();

        $items = isset($launchPayload['items']) && is_array($launchPayload['items'])
            ? $launchPayload['items']
            : array();
        if ($items === array()) {
            throw new RuntimeException('Launch payload bevat geen items.');
        }

        $added = 0;
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $productId = (int) ($item['product_id'] ?? 0);
            $quantity = max(1, (int) ($item['quantity'] ?? $item['participants'] ?? 1));
            $product = \wc_get_product($productId);
            if (! $product) {
                throw new RuntimeException('Een product uit het launch payload is niet meer beschikbaar.');
            }

            $cartItemData = array(
                'sbdp_meta' => is_array($item['sbdp_meta'] ?? null) ? $item['sbdp_meta'] : array(),
                'sbdp_summary' => is_array($item['sbdp_summary'] ?? null) ? $item['sbdp_summary'] : array(),
                'sbdp_pricing' => is_array($item['sbdp_pricing'] ?? null) ? $item['sbdp_pricing'] : array(),
            );

            $cartKey = \WC()->cart->add_to_cart(
                $productId,
                $quantity,
                0,
                array(),
                $cartItemData
            );

            if (! $cartKey) {
                throw new RuntimeException('Kon launch payload niet naar Woo cart hydrateren.');
            }

            $cartItem = \WC()->cart->cart_contents[$cartKey] ?? null;
            if (is_array($cartItem) && isset($cartItem['data']) && $cartItem['data'] instanceof \WC_Product) {
                $unitAmount = isset($item['sbdp_pricing']['display_unit_price'])
                    ? (float) $item['sbdp_pricing']['display_unit_price']
                    : (isset($item['sbdp_pricing']['display_per_person'])
                        ? (float) $item['sbdp_pricing']['display_per_person']
                        : (isset($item['unit_amount_snapshot']) ? (float) $item['unit_amount_snapshot'] : 0.0));
                if ($unitAmount > 0) {
                    $cartItem['data']->set_price($unitAmount);
                    \WC()->cart->cart_contents[$cartKey] = $cartItem;
                }
            }

            $added++;
        }

        if ($added <= 0) {
            throw new RuntimeException('Geen items aan Woo cart toegevoegd.');
        }

        $this->storeQuoteDiscount($launchPayload);
        \WC()->cart->calculate_totals();
        if (method_exists(\WC()->cart, 'set_session')) {
            \WC()->cart->set_session();
        }
        if (method_exists(\WC()->cart, 'maybe_set_cart_cookies')) {
            \WC()->cart->maybe_set_cart_cookies();
        }

        return array(
            'cart_item_count' => $added,
            'cart_url' => function_exists('wc_get_cart_url') ? \wc_get_cart_url() : '',
            'checkout_url' => function_exists('wc_get_checkout_url') ? \wc_get_checkout_url() : '',
        );
    }

    /**
     * @param object|null $cart
     */
    public static function applySessionQuoteDiscount($cart): void
    {
        if (! function_exists('WC') || ! \WC()->session || ! is_object($cart) || ! method_exists($cart, 'add_fee')) {
            return;
        }

        $discount = \WC()->session->get('sbdp_quote_handoff_discount');
        if (! is_array($discount)) {
            return;
        }

        $quoteId = (int) ($discount['quote_id'] ?? 0);
        $versionId = (int) ($discount['quote_version_id'] ?? 0);
        $discountAmount = isset($discount['discount_amount']) && is_numeric($discount['discount_amount'])
            ? round((float) $discount['discount_amount'], 2)
            : 0.0;
        if ($quoteId <= 0 || $versionId <= 0 || $discountAmount <= 0.0 || ! self::cartContainsQuoteItem($cart, $quoteId, $versionId)) {
            self::clearSessionQuoteDiscount();
            return;
        }

        $label = trim((string) ($discount['discount_label'] ?? 'Offerte korting'));
        $cart->add_fee($label !== '' ? $label : 'Offerte korting', -self::grossDiscountToWooFeeAmount($cart, $discountAmount), true);
    }

    /**
     * @param array<string, mixed> $launchPayload
     */
    private function storeQuoteDiscount(array $launchPayload): void
    {
        if (! \WC()->session) {
            return;
        }

        $totals = is_array($launchPayload['totals'] ?? null) ? $launchPayload['totals'] : array();
        $discountAmount = isset($totals['discount_amount']) && is_numeric($totals['discount_amount'])
            ? round((float) $totals['discount_amount'], 2)
            : 0.0;
        if ($discountAmount <= 0.0) {
            self::clearSessionQuoteDiscount();
            return;
        }

        $label = trim((string) ($totals['discount_label'] ?? 'Offerte korting'));
        \WC()->session->set('sbdp_quote_handoff_discount', array(
            'quote_id' => (int) ($launchPayload['quote_id'] ?? 0),
            'quote_version_id' => (int) ($launchPayload['quote_version_id'] ?? 0),
            'discount_amount' => $discountAmount,
            'discount_label' => $label !== '' ? $label : 'Offerte korting',
        ));
    }

    /**
     * @param object $cart
     */
    private static function cartContainsQuoteItem($cart, int $quoteId, int $versionId): bool
    {
        $contents = method_exists($cart, 'get_cart') ? $cart->get_cart() : ($cart->cart_contents ?? array());
        if (! is_array($contents)) {
            return false;
        }

        foreach ($contents as $item) {
            if (! is_array($item)) {
                continue;
            }
            $meta = is_array($item['sbdp_meta'] ?? null) ? $item['sbdp_meta'] : array();
            if ((int) ($meta['quote_id'] ?? 0) === $quoteId && (int) ($meta['quote_version_id'] ?? 0) === $versionId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Quote totals are customer-facing gross amounts. Woo fee amounts are net inputs;
     * Woo then calculates fee tax itself during totals calculation.
     *
     * @param object $cart
     */
    private static function grossDiscountToWooFeeAmount($cart, float $grossDiscount): float
    {
        $subtotal = method_exists($cart, 'get_subtotal') ? (float) $cart->get_subtotal() : 0.0;
        $subtotalTax = method_exists($cart, 'get_subtotal_tax') ? (float) $cart->get_subtotal_tax() : 0.0;
        $grossSubtotal = $subtotal + $subtotalTax;
        if ($grossDiscount <= 0.0 || $subtotal <= 0.0 || $grossSubtotal <= 0.0 || $subtotalTax <= 0.0) {
            return round($grossDiscount, self::priceDecimals());
        }

        return round($grossDiscount * ($subtotal / $grossSubtotal), self::priceDecimals());
    }

    private static function priceDecimals(): int
    {
        return function_exists('wc_get_price_decimals') ? (int) \wc_get_price_decimals() : 2;
    }

    private static function clearSessionQuoteDiscount(): void
    {
        if (! function_exists('WC') || ! \WC()->session) {
            return;
        }

        if (method_exists(\WC()->session, '__unset')) {
            \WC()->session->__unset('sbdp_quote_handoff_discount');
            return;
        }

        if (method_exists(\WC()->session, 'set')) {
            \WC()->session->set('sbdp_quote_handoff_discount', null);
        }
    }

    private function ensureCartSession(): void
    {
        if (null === \WC()->session && method_exists(\WC(), 'initialize_session')) {
            \WC()->initialize_session();
        }

        if (function_exists('wc_load_cart')) {
            if (null === \WC()->cart || ! \WC()->cart) {
                \wc_load_cart();
            }
            return;
        }

        if (null === \WC()->cart && class_exists('WC_Cart')) {
            \WC()->cart = new \WC_Cart();
        }
    }
}
