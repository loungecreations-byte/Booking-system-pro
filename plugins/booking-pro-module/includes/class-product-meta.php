<?php

if (! defined('ABSPATH')) {
    exit;
}

$productMetaPaths = array(
    SBDP_DIR . 'modules/core/Product/ProductMeta.php',
    SBDP_DIR . 'booking-core/src/Product/ProductMeta.php',
);

foreach ($productMetaPaths as $productMetaPath) {
    if (is_readable($productMetaPath)) {
        require_once $productMetaPath;
        break;
    }
}

if (! class_exists('SBDP_Product_Meta', false)) {
    class_alias(\BSPModule\Core\Product\ProductMeta::class, 'SBDP_Product_Meta');
}
