<?php
/**
 * Plugin Name: DDB Content Model
 * Description: Content model for spots, activiteiten, restaurants, deals, and groepen with taxonomies and structured fields.
 * Version: 1.0.0
 * Author: DDB Engineering
 * Requires at least: 6.1
 * Requires PHP: 8.0
 * Text Domain: ddb-content-model
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('DDB_CONTENT_MODEL_PATH')) {
    define('DDB_CONTENT_MODEL_PATH', plugin_dir_path(__FILE__));
}

require_once DDB_CONTENT_MODEL_PATH . 'includes/class-ddb-content-model.php';

DDB_Content_Model::boot();
