<?php

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_readable($autoload)) {
    require $autoload;
}

// Ensure core constants that might be referenced in isolated tests.
define('DAY_IN_SECONDS', 24 * 60 * 60);
define('HOUR_IN_SECONDS', 60 * 60);
define('MINUTE_IN_SECONDS', 60);

// Provide lightweight shims for common WordPress helpers when not running inside WP.
if (!function_exists('__')) {
    function __($text, $domain = null) {
        return $text;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return is_string($str) ? trim($str) : $str;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($value, $options = 0, $depth = 512) {
        return json_encode($value, $options, $depth);
    }
}

if (!function_exists('wp_date')) {
    function wp_date($format, $timestamp = null) {
        return date($format, $timestamp ?? time());
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) {
        return $value;
    }
}

if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return $default;
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key, $single = false) {
        return $single ? '' : array();
    }
}

if (!function_exists('wc_get_product')) {
    function wc_get_product($product_id) {
        return null;
    }
}

if (!function_exists('wp_cache_get')) {
    function wp_cache_get($key, $group = '', $force = false, &$found = null) {
        $found = false;
        return false;
    }
}

if (!function_exists('wp_cache_set')) {
    function wp_cache_set($key, $data, $group = '', $expire = 0) {
        return true;
    }
}

if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete($key, $group = '') {
        return true;
    }
}

if (!class_exists('WC_Product')) {
    class WC_Product {
        private $id;
        private $price;
        private $regular;

        public function __construct($id, $price = 0.0, $regular = 0.0) {
            $this->id = $id;
            $this->price = $price;
            $this->regular = $regular ?: $price;
        }

        public function get_id() {
            return $this->id;
        }

        public function get_price($context = '') {
            return $this->price;
        }

        public function get_regular_price($context = '') {
            return $this->regular;
        }
    }
}