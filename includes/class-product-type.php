<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BSPModule\Core\WooCommerce\ProductType\BookableServiceProductType;

if ( ! class_exists( 'SBDP_Product_Type', false ) ) {
	class_alias( BookableServiceProductType::class, 'SBDP_Product_Type' );
}
