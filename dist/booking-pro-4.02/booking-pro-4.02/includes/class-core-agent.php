<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\BSPModule\Shared\Agents\CoreAgent' ) ) {
	return;
}

if ( ! class_exists( 'BSP_Core_Agent', false ) ) {
	class_alias( \BSPModule\Shared\Agents\CoreAgent::class, 'BSP_Core_Agent' );
}

if ( ! interface_exists( 'BSP_Module_Agent_Interface', false ) && interface_exists( \BSPModule\Shared\Agents\ModuleAgentInterface::class ) ) {
	class_alias( \BSPModule\Shared\Agents\ModuleAgentInterface::class, 'BSP_Module_Agent_Interface' );
}
