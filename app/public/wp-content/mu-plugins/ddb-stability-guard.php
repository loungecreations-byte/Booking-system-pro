<?php
/**
 * Plugin Name: DDB Stability Guard
 * Description: Runtime hardening for PHP 8.4 compatibility and safe Elementor Notes fallback.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Prevent Elementor Cloud Library screenshot proxy requests with invalid payload
 * from throwing uncaught HTTP exceptions that crash the editor request.
 */
add_action('plugins_loaded', static function (): void {
    if (!isset($_GET['screenshot_proxy'])) {
        return;
    }

    $nonce = '';
    if (isset($_GET['nonce'])) {
        $nonce = (string) sanitize_text_field(wp_unslash((string) $_GET['nonce']));
    }

    $href = '';
    if (isset($_GET['href'])) {
        $href = (string) wp_unslash((string) $_GET['href']);
    }

    if ($nonce !== '' && wp_verify_nonce($nonce, 'screenshot-proxy') && $href !== '') {
        return;
    }

    unset($_GET['screenshot_proxy'], $_GET['nonce'], $_GET['href']);
}, 0);

/**
 * Hide deprecated notices from rendered output while keeping fatal errors visible.
 */
add_action('muplugins_loaded', static function (): void {
    if (defined('WP_CLI') && WP_CLI) {
        return;
    }

    $reporting = error_reporting();
    if (is_int($reporting)) {
        error_reporting($reporting & ~E_DEPRECATED & ~E_USER_DEPRECATED);
    }

    @ini_set('display_errors', '0');
}, 0);

/**
 * Disable Elementor Pro Notes runtime hooks on PHP 8.4+, where old Notes builds can spam deprecations.
 */
add_action('elementor_pro/init', static function (): void {
    if (version_compare(PHP_VERSION, '8.4', '<')) {
        return;
    }

    if (!class_exists('\ElementorPro\Plugin')) {
        return;
    }

    $plugin = \ElementorPro\Plugin::instance();
    if (!isset($plugin->modules_manager) || !is_object($plugin->modules_manager)) {
        return;
    }

    $notes_module = $plugin->modules_manager->get_modules('notes');
    if (!$notes_module || !is_object($notes_module)) {
        return;
    }

    ddb_remove_callbacks_bound_to_object('template_redirect', $notes_module);
    ddb_remove_callbacks_bound_to_object('elementor/frontend/before_enqueue_styles', $notes_module);
    ddb_remove_callbacks_bound_to_object('elementor/frontend/after_register_scripts', $notes_module);
    ddb_remove_callbacks_bound_to_object('elementor/editor/before_enqueue_scripts', $notes_module);
    ddb_remove_callbacks_bound_to_object('elementor-pro/editor/v2/packages', $notes_module);
}, 1000);

/**
 * Ensure editor packages do not include Notes package when guard is active.
 */
add_filter('elementor-pro/editor/v2/packages', static function ($packages) {
    if (!is_array($packages)) {
        return $packages;
    }

    return array_values(array_filter($packages, static function ($item): bool {
        return $item !== 'editor-notes';
    }));
}, 1000);

/**
 * Remove Elementor Notes admin-bar node when present.
 */
add_action('admin_bar_menu', static function ($admin_bar): void {
    if (!is_object($admin_bar) || !method_exists($admin_bar, 'remove_node')) {
        return;
    }

    $admin_bar->remove_node('elementor_notes');
}, 999);

/**
 * Local runtime bridge: rewrite remote agent API calls to same-origin REST routes
 * to prevent CORS errors during local development and Elementor editing.
 */
add_action('wp_head', static function (): void {
    if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
        return;
    }

    $host = isset($_SERVER['HTTP_HOST']) ? (string) wp_unslash($_SERVER['HTTP_HOST']) : '';
    $host = strtolower(trim((string) preg_replace('/:\\d+$/', '', $host)));
    $agent_bridge_hosts = array('localhost', 'staging.dagjedenbosch.nl');
    if (
        $host === ''
        || (
            ! str_ends_with($host, '.local')
            && ! in_array($host, $agent_bridge_hosts, true)
        )
    ) {
        return;
    }

    $local_api = esc_url_raw(rest_url('bsp/v1'));
    if ($local_api === '') {
        $local_api = '/wp-json/bsp/v1';
    }

    $local_api_js = esc_js(rtrim($local_api, '/'));
    ?>
<script id="ddb-local-agent-bridge">
(function(){
    var LOCAL_BASE = '<?php echo $local_api_js; ?>';
    var REMOTE_BASE = 'https://agent.dagjedenbosch.nl';

    function rewriteUrl(input) {
        if (typeof input !== 'string') {
            return input;
        }

        if (input.indexOf(REMOTE_BASE + '/agent') === 0) {
            return input.replace(REMOTE_BASE + '/agent', LOCAL_BASE);
        }

        if (input.indexOf(REMOTE_BASE + '/health') === 0) {
            return input.replace(REMOTE_BASE + '/health', LOCAL_BASE + '/health');
        }

        return input;
    }

    window.DDB_AGENT_API_URL = LOCAL_BASE;

    if (typeof window.fetch === 'function') {
        var originalFetch = window.fetch.bind(window);
        window.fetch = function(input, init) {
            if (typeof input === 'string') {
                input = rewriteUrl(input);
            } else if (input && typeof input.url === 'string' && typeof Request === 'function') {
                var rewritten = rewriteUrl(input.url);
                if (rewritten !== input.url) {
                    input = new Request(rewritten, input);
                }
            }
            return originalFetch(input, init);
        };
    }

    if (window.XMLHttpRequest && window.XMLHttpRequest.prototype) {
        var originalOpen = window.XMLHttpRequest.prototype.open;
        window.XMLHttpRequest.prototype.open = function(method, url) {
            return originalOpen.apply(this, [method, rewriteUrl(url)].concat(Array.prototype.slice.call(arguments, 2)));
        };
    }
})();
</script>
    <?php
}, 0);

/**
 * Remove callbacks from a hook that are bound to a specific object instance.
 *
 * @param string $hook_name
 * @param object $target
 */
function ddb_remove_callbacks_bound_to_object(string $hook_name, object $target): void
{
    global $wp_filter;

    if (!isset($wp_filter[$hook_name]) || !($wp_filter[$hook_name] instanceof WP_Hook)) {
        return;
    }

    $wp_hook = $wp_filter[$hook_name];
    if (!is_array($wp_hook->callbacks)) {
        return;
    }

    foreach ($wp_hook->callbacks as $priority => $callbacks) {
        if (!is_array($callbacks)) {
            continue;
        }

        foreach ($callbacks as $index => $entry) {
            if (!is_array($entry) || !array_key_exists('function', $entry)) {
                continue;
            }

            if (!ddb_callback_bound_to_object($entry['function'], $target)) {
                continue;
            }

            unset($wp_hook->callbacks[$priority][$index]);
        }

        if (empty($wp_hook->callbacks[$priority])) {
            unset($wp_hook->callbacks[$priority]);
        }
    }
}

/**
 * Check if callback is bound to a target object instance.
 *
 * @param mixed  $callback
 * @param object $target
 *
 * @return bool
 */
function ddb_callback_bound_to_object($callback, object $target): bool
{
    if (is_array($callback) && isset($callback[0]) && is_object($callback[0])) {
        return $callback[0] === $target;
    }

    if ($callback instanceof Closure) {
        $reflection = new ReflectionFunction($callback);
        return $reflection->getClosureThis() === $target;
    }

    return false;
}
