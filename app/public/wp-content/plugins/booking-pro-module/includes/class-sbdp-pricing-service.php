<?php

if (! defined('ABSPATH')) {
    exit;
}

if (! class_exists(\SBDP\Pricing\PricingService::class)) {
    require_once __DIR__ . '/Pricing/PricingService.php';
}

if (! class_exists('SBDP_Pricing_Service', false)) {
    class_alias(\SBDP\Pricing\PricingService::class, 'SBDP_Pricing_Service');
}
