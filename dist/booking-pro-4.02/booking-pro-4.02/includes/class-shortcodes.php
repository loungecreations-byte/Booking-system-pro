<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SBDP_DIR . 'booking-core/src/Shortcodes/Shortcodes.php';

if ( ! class_exists( 'SBDP_Shortcodes', false ) ) {
	class_alias( \BSPModule\Core\Shortcodes\Shortcodes::class, 'SBDP_Shortcodes' );
}
