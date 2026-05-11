<?php
/**
 * Plugin Name: Plan je Dag - Ultimate Optimized
 * Description: Loader for planner runtime extensions and emergency overrides.
 * Version: 14.3
 */

if (!defined('ABSPATH')) {
    exit;
}

$base_dir = __DIR__ . '/plan-je-dag';
$runtime_files = [
    $base_dir . '/emergency-overrides.php',
    $base_dir . '/planner-runtime.php',
    $base_dir . '/activities-overview.php',
];

foreach ($runtime_files as $runtime_file) {
    if (is_readable($runtime_file)) {
        require_once $runtime_file;
    }
}
