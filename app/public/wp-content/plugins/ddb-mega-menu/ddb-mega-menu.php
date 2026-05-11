<?php
/**
 * Plugin Name: DDB Mega Menu
 * Description: Production-ready mega menu shortcode system for Elementor Pro Theme Builder.
 * Version: 1.0.0
 * Author: DDB Engineering
 * Requires at least: 6.1
 * Requires PHP: 8.0
 * Text Domain: ddb-mega-menu
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('DDB_MEGAMENU_VERSION')) {
    define('DDB_MEGAMENU_VERSION', '1.0.0');
}

if (!defined('DDB_MEGAMENU_OPTION_KEY')) {
    define('DDB_MEGAMENU_OPTION_KEY', 'ddb_megamenu_settings');
}

if (!defined('DDB_MEGAMENU_FILE')) {
    define('DDB_MEGAMENU_FILE', __FILE__);
}

if (!defined('DDB_MEGAMENU_PATH')) {
    define('DDB_MEGAMENU_PATH', plugin_dir_path(__FILE__));
}

if (!defined('DDB_MEGAMENU_URL')) {
    define('DDB_MEGAMENU_URL', plugin_dir_url(__FILE__));
}

require_once DDB_MEGAMENU_PATH . 'includes/class-ddb-megamenu.php';
require_once DDB_MEGAMENU_PATH . 'includes/class-ddb-megamenu-data.php';
require_once DDB_MEGAMENU_PATH . 'includes/class-ddb-megamenu-shortcode.php';
require_once DDB_MEGAMENU_PATH . 'includes/class-ddb-megamenu-admin.php';

DDB_MegaMenu::instance();
