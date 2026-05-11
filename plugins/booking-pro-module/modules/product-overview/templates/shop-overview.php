<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

get_header('shop');

do_action('woocommerce_before_main_content');

if (function_exists('wc_print_notices')) {
    wc_print_notices();
}

echo do_shortcode('[bmp_product_overview type="activiteiten"]');

do_action('woocommerce_after_main_content');

do_action('woocommerce_sidebar');

get_footer('shop');
