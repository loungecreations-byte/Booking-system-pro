<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SBDP_DIR . 'booking-core/src/Product/ProductMeta.php';
require_once SBDP_DIR . 'booking-core/src/WooCommerce/Display/MetaDisplay.php';

if ( ! class_exists( 'SBDP_Meta_Display', false ) ) {
	class_alias( \BSPModule\Core\WooCommerce\Display\MetaDisplay::class, 'SBDP_Meta_Display' );
}
