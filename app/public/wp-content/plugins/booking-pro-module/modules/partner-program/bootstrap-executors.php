<?php

declare(strict_types=1);

use BSP\PartnerProgram\Service\LocalSubscriptionExecutor;

if (! function_exists('ddb_subscriptions_register_subscription_executor')) {
    function ddb_subscriptions_register_subscription_executor(): bool
    {
        return class_exists(LocalSubscriptionExecutor::class)
            && LocalSubscriptionExecutor::isRuntimeAvailable();
    }
}

if (! function_exists('ddb_subscriptions_register_mollie_executor')) {
    function ddb_subscriptions_register_mollie_executor(): bool
    {
        return function_exists('mollieWooCommerce')
            || function_exists('mollieWooCommerceSession')
            || class_exists('Mollie\\Inpsyde\\PaymentGateway\\PaymentGateway');
    }
}
