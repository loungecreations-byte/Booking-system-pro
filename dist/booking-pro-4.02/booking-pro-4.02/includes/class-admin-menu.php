<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SBDP_DIR . 'booking-core/src/Admin/AdminMenu.php';

if ( ! class_exists( 'SBDP_Admin_Menu', false ) ) {
	class_alias( \BSPModule\Core\Admin\AdminMenu::class, 'SBDP_Admin_Menu' );
}
