<?php
/**
 * Hotfix for invalid WooCommerce Blocks callback on after_setup_theme.
 *
 * @package SBDP
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('after_setup_theme', static function (): void {
    global $wp_filter;

    if (
        ! isset($wp_filter['after_setup_theme'])
        || ! is_object($wp_filter['after_setup_theme'])
        || ! isset($wp_filter['after_setup_theme']->callbacks)
        || ! is_array($wp_filter['after_setup_theme']->callbacks)
    ) {
        return;
    }

    foreach ($wp_filter['after_setup_theme']->callbacks as $priority => $callbacks) {
        if (! is_array($callbacks)) {
            continue;
        }

        foreach ($callbacks as $callback) {
            if (! isset($callback['function']) || ! is_array($callback['function'])) {
                continue;
            }

            $fn = $callback['function'];
            if (
                count($fn) < 2
                || ! is_object($fn[0])
                || ! is_string($fn[1])
                || 'init' !== $fn[1]
                || ! ($fn[0] instanceof \Automattic\WooCommerce\Blocks\Templates\ClassicTemplatesCompatibility)
            ) {
                continue;
            }

            if (is_callable($fn)) {
                continue;
            }

            remove_action('after_setup_theme', $fn, (int) $priority);
        }
    }
}, 0);
