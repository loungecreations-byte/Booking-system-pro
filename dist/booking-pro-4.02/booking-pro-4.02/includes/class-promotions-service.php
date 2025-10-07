<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BSP\Sales\Promotions\PromotionsService;

if ( ! class_exists( 'SBDP_Promotions_Service', false ) ) {
	class_alias( PromotionsService::class, 'SBDP_Promotions_Service' );
}
