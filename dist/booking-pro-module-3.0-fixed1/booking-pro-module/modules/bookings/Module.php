<?php

declare(strict_types=1);

namespace BSP\Bookings;

use BSP\Bookings\Rest\Controller;
use BSP\Bookings\WooCommerce\PaymentSync;
use BSP\Core\CoreServiceProvider;
use BSP\Core\Interfaces\ModuleInterface;

final class Module implements ModuleInterface
{
    public function init(): void
    {
        CoreServiceProvider::logger()->log('Bookings module initialized');

        if (function_exists('add_action')) {
            add_action('rest_api_init', [Controller::class, 'register']);
            add_action('woocommerce_payment_complete', [PaymentSync::class, 'handle'], 10, 1);
        }
    }
}
