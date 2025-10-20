<?php
declare(strict_types=1);

namespace BSP\Tests\Support {

final class WooCommerceStubRegistry
{
    public static bool $enabled = false;

    /**
     * @var array<int, \WC_Order>
     */
    private static array $orders = [];

    private static int $increment = 1;

    private static ?WooCommerceMailerStub $mailer = null;

    private static string $mollieMode = 'none';

    public static function reset(): void
    {
        self::$enabled  = false;
        self::$orders   = [];
        self::$increment = 1;
        self::$mailer   = null;
        self::$mollieMode = 'none';
    }

    public static function enable(): void
    {
        self::$enabled = true;
    }

    public static function disable(): void
    {
        self::$enabled = false;
    }

    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * @param array<string, mixed> $args
     */
    public static function createOrder(array $args = []): ?\WC_Order
    {
        if (! self::$enabled) {
            return null;
        }

        $status = isset($args['status']) ? (string) $args['status'] : 'pending';
        $order  = new \WC_Order(self::$increment++, $status);
        self::$orders[$order->get_id()] = $order;

        return $order;
    }

    public static function registerOrder(\WC_Order $order): void
    {
        self::$orders[$order->get_id()] = $order;
    }

    public static function getOrder(int $orderId): ?\WC_Order
    {
        return self::$orders[$orderId] ?? null;
    }

    /**
     * @return array<int, \WC_Order>
     */
    public static function allOrders(): array
    {
        return array_values(self::$orders);
    }

    /**
     * @param mixed $value
     *
     * @return array<int, \WC_Order>
     */
    public static function findOrdersByMeta(string $key, $value): array
    {
        $matches = [];

        foreach (self::$orders as $order) {
            if ($order->get_meta($key) === $value) {
                $matches[] = $order;
            }
        }

        return $matches;
    }

    public static function mailer(): WooCommerceMailerStub
    {
        if (self::$mailer === null) {
            self::$mailer = new WooCommerceMailerStub();
        }

        return self::$mailer;
    }

    public static function logInvoice(int $orderId): void
    {
        self::mailer()->logInvoice($orderId);
    }

    /**
     * @return array<int, int>
     */
    public static function getInvoices(): array
    {
        return self::mailer()->getLog();
    }

    public static function setMollieMode(string $mode): void
    {
        self::$mollieMode = $mode;
    }

    public static function isMollieEnabled(): bool
    {
        return self::$mollieMode !== 'none';
    }

    public static function mollieResponse(\WC_Order $order): ?array
    {
        if (self::$mollieMode === 'none') {
            return null;
        }

        $url = 'https://mollie.test/pay/' . $order->get_id();

        return [
            'url'    => $url,
            'id'     => 'tr_' . $order->get_id(),
            'status' => 'sent',
        ];
    }
}

final class WooCommerceMailerStub
{
    /**
     * @var array<int, int>
     */
    private array $log = [];

    /**
     * @var array<string, object>
     */
    public array $emails;

    public function __construct()
    {
        $this->emails = [
            'WC_Email_Customer_Invoice' => new WooCommerceInvoiceEmailStub($this),
        ];
    }

    public function logInvoice(int $orderId): void
    {
        $this->log[] = $orderId;
    }

    /**
     * @return array<int, int>
     */
    public function getLog(): array
    {
        return $this->log;
    }
}

final class WooCommerceInvoiceEmailStub
{
    public function __construct(private WooCommerceMailerStub $mailer)
    {
    }

    public function trigger($orderId, $order): void // phpcs:ignore
    {
        unset($order);
        $this->mailer->logInvoice((int) $orderId);
    }
}

final class MolliePluginStub
{
    public function paymentRequest(): MolliePaymentRequestServiceStub
    {
        return new MolliePaymentRequestServiceStub();
    }

    public function orderPaymentRequest(): MolliePaymentRequestServiceStub
    {
        return $this->paymentRequest();
    }

    public function getPaymentRequestService(): MolliePaymentRequestServiceStub
    {
        return $this->paymentRequest();
    }
}

final class MolliePaymentRequestServiceStub
{
    public function createPaymentLinkForOrder($order) // phpcs:ignore
    {
        return WooCommerceStubRegistry::mollieResponse($order);
    }

    public function createPaymentRequestForOrder($order) // phpcs:ignore
    {
        return $this->createPaymentLinkForOrder($order);
    }
}
}

namespace {

    use BSP\Tests\Support\MolliePluginStub;
    use BSP\Tests\Support\WooCommerceStubRegistry;

    if (! class_exists('WC_Order')) {
        class WC_Order
        {
            private int $id;

            private string $status;

            /**
             * @var array<string, mixed>
             */
            private array $meta = [];

            /**
             * @var array<string, mixed>
             */
            private array $billing = [];

            /**
             * @var array<string, mixed>
             */
            private array $shipping = [];

            /**
             * @var array<int, mixed>
             */
            private array $items = [];

            private int $itemIncrement = 1;

            private ?int $customerId = null;

            private string $paymentUrl;

            public function __construct(int $id, string $status = 'pending')
            {
                $this->id        = $id;
                $this->status    = $status;
                $this->paymentUrl = 'https://woocommerce.test/pay/' . $id;
            }

            public function get_id(): int
            {
                return $this->id;
            }

            public function get_status(): string
            {
                return $this->status;
            }

            public function set_currency(string $currency): void
            {
                $this->meta['_order_currency'] = $currency;
            }

            public function set_customer_id(int $customerId): void
            {
                $this->customerId = $customerId;
            }

            public function set_billing_first_name(string $value): void
            {
                $this->billing['first_name'] = $value;
            }

            public function set_billing_last_name(string $value): void
            {
                $this->billing['last_name'] = $value;
            }

            public function set_billing_email(string $value): void
            {
                $this->billing['email'] = $value;
            }

            public function set_billing_phone(string $value): void
            {
                $this->billing['phone'] = $value;
            }

            public function set_billing_company(string $value): void
            {
                $this->billing['company'] = $value;
            }

            public function __call($name, $arguments)
            {
                if (strpos($name, 'set_billing_') === 0) {
                    $key = substr($name, 12);
                    $this->billing[$key] = $arguments[0] ?? '';

                    return;
                }

                if (strpos($name, 'set_shipping_') === 0) {
                    $key = substr($name, 13);
                    $this->shipping[$key] = $arguments[0] ?? '';

                    return;
                }

                throw new \BadMethodCallException(sprintf('Unknown method %s', $name));
            }

            public function add_meta_data(string $key, $value, bool $unique = false): void // phpcs:ignore
            {
                if ($unique) {
                    $this->meta[$key] = $value;

                    return;
                }

                if (! isset($this->meta[$key])) {
                    $this->meta[$key] = [];
                }

                if (is_array($this->meta[$key])) {
                    $this->meta[$key][] = $value;
                } else {
                    $this->meta[$key] = [$this->meta[$key], $value];
                }
            }

            public function update_meta_data(string $key, $value): void // phpcs:ignore
            {
                $this->meta[$key] = $value;
            }

            public function get_meta(string $key)
            {
                return $this->meta[$key] ?? null;
            }

        public function add_order_note(string $note): void
        {
            $this->meta['_sbdp_notes'][] = $note;
        }

            public function add_product($product, int $quantity, array $args = []): void // phpcs:ignore
            {
                $item = new \WC_Order_Item_Product();
                $item->set_product_id(method_exists($product, 'get_id') ? $product->get_id() : 0);
                $item->set_name(method_exists($product, 'get_name') ? $product->get_name() : 'Product');
                $item->set_quantity($quantity);
                $item->set_total(isset($args['total']) ? (float) $args['total'] : 0.0);
                $this->add_item($item);
            }

            public function add_item($item): void // phpcs:ignore
            {
                $this->items[$this->itemIncrement++] = $item;
            }

            public function get_items(): array
            {
                return $this->items;
            }

            public function remove_item($itemId): void // phpcs:ignore
            {
                unset($this->items[$itemId]);
            }

            public function calculate_totals(bool $and_taxes = false): void
            {
                unset($and_taxes);
                $total = 0.0;

                foreach ($this->items as $item) {
                    if (method_exists($item, 'get_total')) {
                        $total += (float) $item->get_total();
                    }
                }

                $this->meta['_order_total'] = $total;
            }

            public function save(): void
            {
                WooCommerceStubRegistry::registerOrder($this);
            }

            public function get_checkout_payment_url($force = false): string // phpcs:ignore
            {
                unset($force);

                return $this->paymentUrl;
            }

            public function update_status(string $status): void
            {
                $this->status = $status;
            }

            public function payment_complete($transactionId = null): void // phpcs:ignore
            {
                $this->status = 'processing';
                $this->meta['_transaction_id'] = $transactionId;
            }

            public function get_order_number(): string
            {
                return 'ORDER-' . $this->id;
            }
        }
    }

    if (! class_exists('WC_Order_Item_Product')) {
        class WC_Order_Item_Product
        {
            private int $productId = 0;

            private string $name = '';

            private int $quantity = 1;

            private float $total = 0.0;

            public function set_product_id(int $productId): void // phpcs:ignore
            {
                $this->productId = $productId;
            }

            public function set_name(string $name): void // phpcs:ignore
            {
                $this->name = $name;
            }

            public function set_quantity(int $quantity): void // phpcs:ignore
            {
                $this->quantity = $quantity;
            }

            public function set_total(float $total): void // phpcs:ignore
            {
                $this->total = $total;
            }

            public function get_total(): float
            {
                return $this->total;
            }

            public function get_product_id(): int
            {
                return $this->productId;
            }

            public function get_quantity(): int
            {
                return $this->quantity;
            }

            public function get_name(): string
            {
                return $this->name;
            }
        }
    }

    if (! function_exists('wc_create_order')) {
        /**
         * @param array<string, mixed> $args
         */
        function wc_create_order(array $args = [])
        {
            return WooCommerceStubRegistry::createOrder($args);
        }
    }

    if (! function_exists('wc_get_order')) {
        function wc_get_order($orderId)
        {
            if (is_object($orderId) && $orderId instanceof \WC_Order) {
                return $orderId;
            }

            return WooCommerceStubRegistry::getOrder((int) $orderId);
        }
    }

    if (! function_exists('wc_get_orders')) {
        /**
         * @param array<string, mixed> $args
         */
        function wc_get_orders(array $args = [])
        {
            if (! WooCommerceStubRegistry::isEnabled()) {
                return [];
            }

            $metaKey   = isset($args['meta_key']) ? (string) $args['meta_key'] : '';
            $metaValue = $args['meta_value'] ?? null;

            if ($metaKey !== '' && $metaValue !== null) {
                return WooCommerceStubRegistry::findOrdersByMeta($metaKey, $metaValue);
            }

            return WooCommerceStubRegistry::allOrders();
        }
    }

    if (! function_exists('wc_get_product')) {
        function wc_get_product($productId)
        {
            unset($productId);

            return null;
        }
    }

    if (! function_exists('WC')) {
        function WC()
        {
            return new class {
                public function mailer()
                {
                    return WooCommerceStubRegistry::mailer();
                }
            };
        }
    }

    if (! function_exists('mollieWooCommerce')) {
        function mollieWooCommerce()
        {
            if (! WooCommerceStubRegistry::isMollieEnabled()) {
                return null;
            }

            return new MolliePluginStub();
        }
    }
}
