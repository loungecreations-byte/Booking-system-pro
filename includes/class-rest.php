<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SBDP_DIR . 'booking-core/src/Rest/RestService.php';

if ( ! class_exists( 'SBDP_REST', false ) ) {
	class_alias( \BSPModule\Core\Rest\RestService::class, 'SBDP_REST' );
}
