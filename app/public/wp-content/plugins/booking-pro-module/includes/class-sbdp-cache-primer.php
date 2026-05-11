<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

if (! defined('ABSPATH')) {
    exit;
}

if (! class_exists('SBDP_Cache_Primer')) {
    final class SBDP_Cache_Primer
    {
        public static function init(): void
        {
            if (function_exists('add_action')) {
                add_action('init', array(self::class, 'prime'), 5);
            }
        }

        public static function prime(): void
        {
            if (function_exists('do_action')) {
                do_action('sbdp/cache/prime');
            }
        }
    }

    SBDP_Cache_Primer::init();
}
