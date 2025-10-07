<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SBDP_DIR . 'booking-core/src/Product/ProductMeta.php';

if ( ! class_exists( 'SBDP_Product_Meta', false ) ) {
	class_alias( \BSPModule\Core\Product\ProductMeta::class, 'SBDP_Product_Meta' );
}
