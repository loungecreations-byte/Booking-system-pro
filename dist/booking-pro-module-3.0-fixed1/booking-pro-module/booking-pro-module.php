<?php
/**
 * Plugin Name: Booking Pro Module
 * Plugin URI: https://owncreations.com
 * Description: WooCommerce dagplanner en boekingsmodule met resources, capaciteiten, prijsregels en verbeterde e-mailflows.
 * Version: 3.0
 * Author: Own Creations
 * Text Domain: sbdp
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SBDP_FILE', __FILE__ );
define( 'SBDP_DIR', plugin_dir_path( __FILE__ ) );
define( 'SBDP_URL', plugin_dir_url( __FILE__ ) );
define( 'SBDP_VER', '3.0' );

require_once SBDP_DIR . 'includes/class-modules-autoloader.php';
SBDP_Modules_Autoloader::register();

require_once SBDP_DIR . 'includes/class-core-agent.php';
require_once SBDP_DIR . 'includes/class-modules-manager.php';
require_once SBDP_DIR . 'includes/class-plugin.php';
require_once SBDP_DIR . 'includes/class-activation.php';

SBDP_Activation::bootstrap();

register_activation_hook(SBDP_FILE, ['SBDP_Activation','activate']);
register_deactivation_hook(SBDP_FILE, ['SBDP_Activation','deactivate']);
register_uninstall_hook(SBDP_FILE, ['SBDP_Activation','uninstall']);

SBDP_Plugin::boot();
