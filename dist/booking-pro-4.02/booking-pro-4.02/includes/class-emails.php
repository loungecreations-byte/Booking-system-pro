<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SBDP_DIR . 'booking-core/src/Emails/EmailsService.php';

if ( ! class_exists( 'SBDP_Emails', false ) ) {
	class_alias( \BSPModule\Core\Emails\EmailsService::class, 'SBDP_Emails' );
}
