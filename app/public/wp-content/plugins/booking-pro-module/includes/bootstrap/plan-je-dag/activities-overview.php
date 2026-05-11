<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keep the Elementor page-transition neutralization local to the page shell,
 * but publish the activities overview through the canonical discovery surface.
 */
function sbdp_render_canonical_activities_overview(): string
{
    $canonicalShortcode = '[bmp_product_overview type="activiteiten"]';

    ob_start();
    ?>
    <script>
      (() => {
        const clearPageTransition = () => {
          const pageTransition = document.querySelector('e-page-transition');
          if (!(pageTransition instanceof HTMLElement)) {
            return;
          }

          pageTransition.setAttribute('disabled', '');
          pageTransition.classList.remove('e-page-transition--entering', 'e-page-transition--exiting');
          pageTransition.classList.add('e-page-transition--entered');
          pageTransition.style.display = 'none';
          pageTransition.style.pointerEvents = 'none';
        };

        clearPageTransition();
        document.addEventListener('DOMContentLoaded', clearPageTransition, { once: true });
        window.addEventListener('load', clearPageTransition, { once: true });
      })();
    </script>
    <?php
    echo do_shortcode($canonicalShortcode);

    return (string) ob_get_clean();
}

function sbdp_has_elementor_footer_location(): bool
{
    if (!defined('ELEMENTOR_PRO_VERSION')) {
        return false;
    }

    if (!class_exists('\ElementorPro\Modules\ThemeBuilder\Module')) {
        return false;
    }

    try {
        $module = \ElementorPro\Modules\ThemeBuilder\Module::instance();
        if (!method_exists($module, 'get_conditions_manager')) {
            return false;
        }

        $conditionsManager = $module->get_conditions_manager();
        if (!is_object($conditionsManager) || !method_exists($conditionsManager, 'get_documents_for_location')) {
            return false;
        }

        $documents = $conditionsManager->get_documents_for_location('footer');

        return is_array($documents) && $documents !== array();
    } catch (\Throwable $exception) {
        return false;
    }
}

function sbdp_render_theme_footer_markup(): string
{
    ob_start();
    get_template_part('template-parts/footer');
    $markup = trim((string) ob_get_clean());

    if ($markup !== '') {
        return $markup;
    }

    ob_start();
    ?>
    <footer id="site-footer" class="site-footer">
      <div class="site-footer__fallback">
        <a href="<?php echo esc_url(home_url('/')); ?>"><?php bloginfo('name'); ?></a>
      </div>
    </footer>
    <?php

    return trim((string) ob_get_clean());
}

function sbdp_should_force_canonical_activities_page(): bool
{
    if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (function_exists('wp_is_json_request') && wp_is_json_request())) {
        return false;
    }

    if (function_exists('is_page') && is_page('activiteiten')) {
        return true;
    }

    $queriedId = function_exists('get_queried_object_id') ? (int) get_queried_object_id() : 0;
    if ($queriedId === 266) {
        return true;
    }

    $requestPath = isset($_SERVER['REQUEST_URI']) ? (string) wp_parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
    if (untrailingslashit($requestPath) === '/activiteiten') {
        return true;
    }

    $post = function_exists('get_post') ? get_post($queriedId) : null;
    if ($post instanceof WP_Post && $post->post_name === 'activiteiten') {
        return true;
    }

    return false;
}

function sbdp_should_skip_activities_template_override(): bool
{
    if (!defined('ELEMENTOR_VERSION')) {
        return false;
    }

    if (
        isset($_GET['elementor-preview']) ||
        isset($_GET['elementor_library']) ||
        isset($_GET['elementor']) ||
        isset($_REQUEST['elementor']) ||
        (isset($_GET['action']) && $_GET['action'] === 'elementor') ||
        (defined('DOING_AJAX') && DOING_AJAX && isset($_REQUEST['action']) && strpos((string) $_REQUEST['action'], 'elementor') !== false)
    ) {
        return true;
    }

    return false;
}

function sbdp_page_has_user_managed_content(int $post_id): bool
{
    if ($post_id <= 0) {
        return false;
    }

    $elementor_data = get_post_meta($post_id, '_elementor_data', true);
    if (is_string($elementor_data) && trim($elementor_data) !== '') {
        $decoded = json_decode($elementor_data, true);
        if (is_array($decoded) && $decoded !== []) {
            return true;
        }
    }

    $post = get_post($post_id);
    if (!$post instanceof WP_Post) {
        return false;
    }

    return trim((string) $post->post_content) !== '';
}

add_shortcode('sbdp_activities_overview', function($atts = []): string {
    unset($atts);

    return sbdp_render_canonical_activities_overview();
});

if (!shortcode_exists('ddb_activiteiten')) {
    add_shortcode('ddb_activiteiten', function($atts = []): string {
        unset($atts);

        return sbdp_render_canonical_activities_overview();
    });
}

add_filter('the_content', function(string $content): string {
    static $isRendering = false;

    if ($isRendering) {
        return $content;
    }

    if (
        !sbdp_should_force_canonical_activities_page()
        || sbdp_should_skip_activities_template_override()
        || !function_exists('is_main_query')
        || !function_exists('in_the_loop')
        || !is_main_query()
        || !in_the_loop()
    ) {
        return $content;
    }

    $queriedId = function_exists('get_queried_object_id') ? (int) get_queried_object_id() : 0;
    $postId = function_exists('get_the_ID') ? (int) get_the_ID() : 0;
    if ($queriedId > 0 && $postId > 0 && $queriedId !== $postId) {
        return $content;
    }

    $contentOwnerId = $queriedId > 0 ? $queriedId : $postId;
    if (sbdp_page_has_user_managed_content($contentOwnerId)) {
        return $content;
    }

    $isRendering = true;
    try {
        return sbdp_render_canonical_activities_overview();
    } finally {
        $isRendering = false;
    }
}, 999);

add_action('wp_footer', function(): void {
    static $rendered = false;

    if ($rendered || !sbdp_should_force_canonical_activities_page() || sbdp_should_skip_activities_template_override()) {
        return;
    }

    if (sbdp_has_elementor_footer_location()) {
        return;
    }

    $queriedId = function_exists('get_queried_object_id') ? (int) get_queried_object_id() : 0;
    if (sbdp_page_has_user_managed_content($queriedId)) {
        return;
    }

    $rendered = true;
    $markup = sbdp_render_theme_footer_markup();
    if ($markup !== '') {
        echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}, 1);
