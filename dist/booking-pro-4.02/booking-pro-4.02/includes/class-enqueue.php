<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SBDP_DIR . 'booking-core/src/Assets/EnqueueService.php';

if ( ! class_exists( 'SBDP_Enqueue', false ) ) {
	class_alias( \BSPModule\Core\Assets\EnqueueService::class, 'SBDP_Enqueue' );
}
