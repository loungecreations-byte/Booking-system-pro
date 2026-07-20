<?php

declare(strict_types=1);

namespace {
    if (! function_exists('is_checkout')) {
        function is_checkout(): bool
        {
            return true;
        }
    }

    if (! function_exists('is_wc_endpoint_url')) {
        function is_wc_endpoint_url(string $endpoint = ''): bool
        {
            unset($endpoint);
            return false;
        }
    }

    if (! function_exists('remove_action')) {
        function remove_action(string $tag, $callback, int $priority = 10): bool
        {
            unset($tag, $callback, $priority);
            return true;
        }
    }

    if (! function_exists('sanitize_text_field')) {
        function sanitize_text_field($value): string
        {
            return trim((string) $value);
        }
    }

    if (! function_exists('esc_html')) {
        function esc_html(string $text): string
        {
            return $text;
        }
    }

    if (! function_exists('wp_kses_post')) {
        function wp_kses_post(string $text): string
        {
            return $text;
        }
    }

    if (! function_exists('wc_price')) {
        function wc_price($amount): string
        {
            return 'EUR ' . number_format((float) $amount, 2, '.', '');
        }
    }

    if (! function_exists('_n')) {
        function _n(string $single, string $plural, int $number, string $domain = 'default'): string
        {
            unset($domain);
            return $number === 1 ? $single : $plural;
        }
    }

    if (! function_exists('wp_date')) {
        function wp_date(string $format, int $timestamp): string
        {
            return gmdate($format, $timestamp);
        }
    }

    if (! function_exists('wp_timezone')) {
        function wp_timezone(): \DateTimeZone
        {
            return new \DateTimeZone('UTC');
        }
    }

    if (! function_exists('WC')) {
        function WC()
        {
            return $GLOBALS['__test_wc_instance'] ?? null;
        }
    }

    final class CheckoutProgramPresenterTestProductStub
    {
        public function __construct(private string $name)
        {
        }

        public function get_name(): string
        {
            return $this->name;
        }
    }

    final class CheckoutProgramPresenterTestCartStub
    {
        /**
         * @param array<int, array<string, mixed>> $items
         * @param array<int, object> $taxTotals
         */
        public function __construct(
            private array $items,
            private array $taxTotals,
            private float $grandTotal
        ) {
        }

        public function get_cart(): array
        {
            return $this->items;
        }

        public function get_total(string $context = ''): float
        {
            unset($context);
            return $this->grandTotal;
        }

        public function get_tax_totals(): array
        {
            return $this->taxTotals;
        }
    }

    final class CheckoutProgramPresenterTestWooStub
    {
        public function __construct(public CheckoutProgramPresenterTestCartStub $cart)
        {
        }
    }
}

namespace BSP\Tests\Quotes {

    use BSPModule\Core\WooCommerce\Display\CheckoutProgramPresenter;
    use PHPUnit\Framework\TestCase;

    require_once dirname(__DIR__, 2) . '/modules/core/WooCommerce/Display/CheckoutProgramPresenter.php';

    final class CheckoutProgramPresenterTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            $GLOBALS['__test_wc_instance'] = null;
        }

        public function testCheckoutProgramShowsLineTotalsIncludingVatAndCustomerLabels(): void
        {
            $cartItems = array(
                array(
                    'data' => new \CheckoutProgramPresenterTestProductStub('Workshop worstenbroodjes'),
                    'quantity' => 12,
                    'line_total' => 981.82,
                    'line_tax' => 206.18,
                    'sbdp_meta' => array(
                        'sbdp_date' => '2026-05-12',
                        'sbdp_start' => '13:00',
                        'sbdp_end' => '15:00',
                        'sbdp_participants' => 12,
                        'sbdp_route_intent' => 'checkout',
                        'sbdp_booking_capability' => 'DIRECT',
                    ),
                ),
            );

            $tax = (object) array(
                'label' => 'Tax',
                'amount' => 206.18,
            );

            $GLOBALS['__test_wc_instance'] = new \CheckoutProgramPresenterTestWooStub(
                new \CheckoutProgramPresenterTestCartStub($cartItems, array($tax), 1188.00)
            );

            ob_start();
            CheckoutProgramPresenter::render_checkout_program_block();
            $output = (string) ob_get_clean();

            $this->assertStringContainsString('Overzicht van jullie dag', $output);
            $this->assertStringContainsString('Workshop worstenbroodjes', $output);
            $this->assertStringContainsString('x12', $output);
            $this->assertStringContainsString('Direct boekbaar', $output);
            $this->assertStringContainsString('EUR 1188.00', $output);
            $this->assertStringContainsString('Totaal incl. btw', $output);
            $this->assertStringContainsString('Waarvan btw', $output);
            $this->assertStringNotContainsString('Subtotaal', $output);
        }

        public function testCheckoutProgramShowsPriceOnRequestForZeroPricedRequestItem(): void
        {
            $cartItems = array(
                array(
                    'data' => new \CheckoutProgramPresenterTestProductStub('Workshop worstenbroodjes'),
                    'quantity' => 12,
                    'line_total' => 0.0,
                    'line_tax' => 0.0,
                    'sbdp_meta' => array(
                        'sbdp_date' => '2026-05-12',
                        'sbdp_participants' => 12,
                        'sbdp_route_intent' => 'quote',
                        'sbdp_booking_capability' => 'REQUEST',
                    ),
                ),
            );

            $GLOBALS['__test_wc_instance'] = new \CheckoutProgramPresenterTestWooStub(
                new \CheckoutProgramPresenterTestCartStub($cartItems, array(), 0.0)
            );

            ob_start();
            CheckoutProgramPresenter::render_checkout_program_block();
            $output = (string) ob_get_clean();

            $this->assertStringContainsString('x12', $output);
            $this->assertStringContainsString('Prijs op aanvraag', $output);
        }
    }
}
