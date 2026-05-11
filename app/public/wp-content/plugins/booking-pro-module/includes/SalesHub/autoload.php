<?php

declare(strict_types=1);

namespace SBDP\SalesHub;

use BSP\VendorPortal\Service\SalesHubService;
use WC_Order;

if (class_exists(SalesHubBootstrap::class, false)) {
    return;
}

final class SalesHubBootstrap
{
    private static ?self $instance = null;

    private ?SalesHubService $service = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void
    {
        if ($this->service === null && class_exists(SalesHubService::class) && isset($GLOBALS['wpdb'])) {
            $this->service = new SalesHubService($GLOBALS['wpdb']);
        }

        if (! $this->service) {
            return;
        }

        if (function_exists('add_action')) {
            add_action('woocommerce_checkout_order_processed', array($this, 'queueOrder'), 50, 1);
            add_action('sbdp_saleshub_queue_order', array($this, 'queueOrder'), 10, 1);
        }
    }

    public function queueOrder($order): void
    {
        if (! $this->service) {
            return;
        }

        if (is_numeric($order)) {
            $order = wc_get_order((int) $order);
        }

        if ($order instanceof WC_Order) {
            $this->service->handleOrderQueued($order);
        }
    }
}
