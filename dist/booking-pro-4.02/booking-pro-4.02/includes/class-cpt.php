<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BSPModule\Core\PostType\BookablePostTypes;

if ( ! class_exists( 'SBDP_CPT', false ) ) {
	class_alias( BookablePostTypes::class, 'SBDP_CPT' );
}
