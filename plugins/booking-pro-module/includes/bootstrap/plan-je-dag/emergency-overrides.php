<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('sbdp_plan_ultimate_emergency_overrides_enabled')) {
    function sbdp_plan_ultimate_emergency_overrides_enabled(): bool
    {
        return defined('SBDP_ENABLE_PLAN_ULTIMATE_EMERGENCY_OVERRIDES')
            && SBDP_ENABLE_PLAN_ULTIMATE_EMERGENCY_OVERRIDES;
    }
}

// Emergency HTML cleanup for legacy dark-toggle markup.
add_action('template_redirect', function() {
    if (!sbdp_plan_ultimate_emergency_overrides_enabled()) {
        return;
    }

    ob_start(function($html) {
        $html = preg_replace(
            '/(<button[^>]*data-sbdp-dark-toggle[^>]*>)[^<]*(<\/button>)\s*/i',
            '$1$2',
            $html
        );

        $html = preg_replace(
            '/(<div[^>]*elementor-widget-html[^>]*>)\s*(<button[^>]*data-sbdp-dark-toggle)/i',
            '$1<div class="elementor-widget-container" style="width:44px;height:44px;overflow:hidden;display:flex;align-items:center;justify-content:center;">$2',
            $html
        );

        return $html;
    });
}, 1);

// Emergency fallback page for /offerte when the page object is missing.
add_action('template_redirect', function() {
    if (!sbdp_plan_ultimate_emergency_overrides_enabled()) {
        return;
    }

    if (is_admin()) {
        return;
    }

    $request_path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
    if ($request_path !== 'offerte') {
        return;
    }

    if (function_exists('get_page_by_path') && get_page_by_path('offerte')) {
        return;
    }

    nocache_headers();
    status_header(200);

    get_header();
    ?>
    <main class="sbdp-offerte-page" style="max-width:960px;margin:0 auto;padding:clamp(24px,4vw,56px) 20px;">
        <section style="background:var(--ddb-bg-surface, #fff);border:1px solid var(--ddb-border, #e5e7eb);border-radius:24px;padding:clamp(24px,4vw,40px);box-shadow:var(--ddb-shadow, 0 18px 40px rgba(0,0,0,.12));">
            <p style="margin:0 0 12px;font-size:12px;letter-spacing:.18em;text-transform:uppercase;color:var(--ddb-text-secondary, #6b7280);">Offerte aanvragen</p>
            <h1 style="margin:0 0 12px;font-size:clamp(28px,4vw,44px);line-height:1.05;">Je offerteaanvraag is opgeslagen</h1>
            <p style="margin:0 0 24px;font-size:16px;line-height:1.7;color:var(--ddb-text-primary, #111827);max-width:70ch;">
                We nemen contact op met een voorstel op basis van je planning. Je kunt intussen terug naar de planner of direct verder met een andere aanvraag.
            </p>
            <div style="display:flex;flex-wrap:wrap;gap:12px;">
                <a href="<?php echo esc_url(home_url('/plan-je-dag/')); ?>" style="display:inline-flex;align-items:center;justify-content:center;padding:12px 18px;border-radius:999px;background:var(--ddb-accent-primary, #3352e0);color:#fff;text-decoration:none;font-weight:600;">Terug naar planner</a>
                <a href="<?php echo esc_url(home_url('/contact/')); ?>" style="display:inline-flex;align-items:center;justify-content:center;padding:12px 18px;border-radius:999px;border:1px solid var(--ddb-border, #e5e7eb);color:var(--ddb-text-primary, #111827);text-decoration:none;font-weight:600;">Neem contact op</a>
            </div>
        </section>
    </main>
    <?php
    get_footer();
    exit;
}, 5);

// Emergency OPcache invalidation hook for local troubleshooting only.
add_action('init', function() {
    if (!sbdp_plan_ultimate_emergency_overrides_enabled()) {
        return;
    }

    if (!function_exists('opcache_invalidate')) {
        return;
    }

    $should_invalidate = false;

    if (!empty($_GET['sbdp_flush_opcache']) && function_exists('current_user_can') && current_user_can('manage_options')) {
        $should_invalidate = true;
    }

    $should_invalidate = (bool) apply_filters('sbdp_invalidate_opcache', $should_invalidate);

    if (!$should_invalidate) {
        return;
    }

    opcache_invalidate(__FILE__, true);

    $files_to_invalidate = [
        WP_PLUGIN_DIR . '/booking-pro-module/modules/day-planner/Module.php',
        WP_PLUGIN_DIR . '/booking-pro-module/modules/day-planner/Service/ActivityService.php',
        WP_PLUGIN_DIR . '/booking-pro-module/modules/day-planner/Service/PlanService.php',
        WP_PLUGIN_DIR . '/booking-pro-module/modules/day-planner/Service/ProductCatalogService.php',
        WP_PLUGIN_DIR . '/booking-pro-module/modules/day-planner/Rest/PlansController.php',
    ];

    foreach ($files_to_invalidate as $file) {
        if (file_exists($file)) {
            opcache_invalidate($file, true);
        }
    }
}, 5);

// Emergency REST patch for legacy people-limit responses.
add_filter('rest_post_dispatch', function($response, $server, $request) {
    unset($server);

    if (!sbdp_plan_ultimate_emergency_overrides_enabled()) {
        return $response;
    }

    $route = $request->get_route();
    if (strpos($route, '/planner/v1/products') === false) {
        return $response;
    }

    $data = $response->get_data();
    if (!isset($data['products']) || !is_array($data['products'])) {
        return $response;
    }

    $products = $data['products'];
    $product_ids = [];

    foreach ($products as $product) {
        if (isset($product['id'])) {
            $product_ids[] = (int) $product['id'];
        }
    }

    if ($product_ids) {
        $product_ids = array_values(array_unique($product_ids));
        update_meta_cache('post', $product_ids);
    }

    foreach ($products as $key => $product) {
        if (!isset($product['id'])) {
            continue;
        }

        $product_id = (int) $product['id'];
        $max_people = (int) get_post_meta($product_id, '_sbdp_max_people', true);
        if ($max_people <= 0) {
            $max_people = (int) get_post_meta($product_id, '_sbdp_people_max', true);
        }
        if ($max_people <= 0) {
            $max_people = 50;
        }

        $min_people = (int) get_post_meta($product_id, '_sbdp_min_people', true);
        if ($min_people <= 0) {
            $min_people = (int) get_post_meta($product_id, '_sbdp_people_min', true);
        }
        if ($min_people <= 0) {
            $min_people = 1;
        }

        if (!isset($products[$key]['people']) || !is_array($products[$key]['people'])) {
            $products[$key]['people'] = [];
        }

        $products[$key]['people']['min'] = $min_people;
        $products[$key]['people']['max'] = $max_people;
        $products[$key]['people']['enabled'] = true;
    }

    $data['products'] = $products;
    $response->set_data($data);

    return $response;
}, 10, 3);
