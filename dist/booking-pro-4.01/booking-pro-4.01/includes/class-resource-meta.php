<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SBDP_DIR . 'booking-core/src/Resource/ResourceMeta.php';

if ( ! class_exists( 'SBDP_Resource_Meta', false ) ) {
	class_alias( \BSPModule\Core\Resource\ResourceMeta::class, 'SBDP_Resource_Meta' );
}
