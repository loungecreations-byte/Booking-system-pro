<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin optimizer:
 * Keep this module functional-only. No visual overrides here.
 */
add_action('admin_menu', 'ddb_cleanup_admin_menus', 999);
function ddb_cleanup_admin_menus(): void
{
    remove_menu_page('edit-comments.php');
}
