<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SBDP_DIR . 'booking-core/src/Admin/AdminScheduler.php';

if ( ! class_exists( 'SBDP_Admin_Scheduler', false ) ) {
	class_alias( \BSPModule\Core\Admin\AdminScheduler::class, 'SBDP_Admin_Scheduler' );
}
