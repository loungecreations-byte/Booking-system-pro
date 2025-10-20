<?php
declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_readable($autoload)) {
    require $autoload;
}

define('DAY_IN_SECONDS', 24 * 60 * 60);
define('HOUR_IN_SECONDS', 60 * 60);
define('MINUTE_IN_SECONDS', 60);

global $bsp_test_actions, $bsp_test_shortcodes, $bsp_test_posts, $bsp_test_post_meta, $bsp_test_registered_post_types, $bsp_test_submenus, $bsp_test_redirects, $bsp_test_filters, $bsp_test_registered_styles, $bsp_test_registered_scripts;
$bsp_test_actions = $bsp_test_shortcodes = $bsp_test_posts = $bsp_test_post_meta = $bsp_test_registered_post_types = $bsp_test_submenus = $bsp_test_redirects = $bsp_test_registered_styles = $bsp_test_registered_scripts = [];
$bsp_test_filters = [];

if (!function_exists('__')) {
    function __($text, $domain = null)
    {
        return $text;
    }
}

if (!function_exists('__return_empty_array')) {
    function __return_empty_array()
    {
        return [];
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value)
    {
        return is_string($value) ? trim($value) : $value;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($value, $options = 0, $depth = 512)
    {
        return json_encode($value, $options, $depth);
    }
}

if (!function_exists('rest_ensure_response')) {
    function rest_ensure_response($value)
    {
        return $value;
    }
}

if (!function_exists('current_time')) {
    function current_time($type)
    {
        return time();
    }
}

if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
    {
        global $bsp_test_actions;
        $bsp_test_actions[] = [
            'hook'          => $hook,
            'callback'      => $callback,
            'priority'      => $priority,
            'accepted_args' => $accepted_args,
        ];
        return true;
    }
}

if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1)
    {
        global $bsp_test_filters;
        $bsp_test_filters[$hook][] = [
            'callback'      => $callback,
            'priority'      => (int) $priority,
            'accepted_args' => (int) $accepted_args,
        ];
        return true;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value)
    {
        global $bsp_test_filters;
        if (empty($bsp_test_filters[$hook])) {
            return $value;
        }

        usort(
            $bsp_test_filters[$hook],
            static function (array $left, array $right): int {
                return $left['priority'] <=> $right['priority'];
            }
        );

        $args = func_get_args();

        foreach ($bsp_test_filters[$hook] as $filter) {
            $callback  = $filter['callback'];
            $max_args  = max(1, $filter['accepted_args']);
            $arguments = array_slice($args, 1, $max_args);

            if (empty($arguments)) {
                $arguments = [$value];
            } else {
                $arguments[0] = $value;
            }

            $value = call_user_func_array($callback, $arguments);
        }

        return $value;
    }
}

if (!function_exists('add_shortcode')) {
    function add_shortcode($tag, $callback)
    {
        global $bsp_test_shortcodes;
        $bsp_test_shortcodes[$tag] = $callback;
        return true;
    }
}

if (!function_exists('register_rest_route')) {
    function register_rest_route($namespace, $route, $args = [], $override = false)
    {
        global $bsp_test_rest_routes;
        if (!isset($bsp_test_rest_routes)) {
            $bsp_test_rest_routes = [];
        }
        $bsp_test_rest_routes[] = compact('namespace', 'route', 'args', 'override');
        return true;
    }
}

if (!class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID;
        public string $post_title;
        public string $post_status;
        public string $post_type;

        public function __construct(object $data)
        {
            $this->ID          = (int) ($data->ID ?? 0);
            $this->post_title  = (string) ($data->post_title ?? '');
            $this->post_status = (string) ($data->post_status ?? 'draft');
            $this->post_type   = (string) ($data->post_type ?? 'post');
        }
    }
}

if (!function_exists('register_post_type')) {
    function register_post_type($post_type, $args = [])
    {
        global $bsp_test_registered_post_types;
        $bsp_test_registered_post_types[$post_type] = $args;
        return true;
    }
}

if (!function_exists('plugins_url')) {
    function plugins_url($path = '', $plugin = '')
    {
        $base = 'http://example.com/wp-content/plugins/booking-pro-module';
        $path = '' === $path ? '' : '/' . ltrim($path, '/');
        return $base . $path;
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit($string)
    {
        return rtrim((string) $string, '/\\') . '/';
    }
}

if (!function_exists('wp_register_style')) {
    function wp_register_style($handle, $src = '', $deps = [], $ver = false, $media = 'all')
    {
        global $bsp_test_registered_styles;
        $bsp_test_registered_styles[$handle] = [
            'src'    => $src,
            'deps'   => $deps,
            'ver'    => $ver,
            'media'  => $media,
            'status' => 'registered',
        ];
        return true;
    }
}

if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style($handle)
    {
        global $bsp_test_registered_styles;
        if (!isset($bsp_test_registered_styles[$handle])) {
            $bsp_test_registered_styles[$handle] = ['status' => 'enqueued'];
        }
        $bsp_test_registered_styles[$handle]['status'] = 'enqueued';
        return true;
    }
}

if (!function_exists('wp_register_script')) {
    function wp_register_script($handle, $src = '', $deps = [], $ver = false, $in_footer = false)
    {
        global $bsp_test_registered_scripts;
        $bsp_test_registered_scripts[$handle] = [
            'src'       => $src,
            'deps'      => $deps,
            'ver'       => $ver,
            'in_footer' => $in_footer,
            'status'    => 'registered',
        ];
        return true;
    }
}

if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script($handle)
    {
        global $bsp_test_registered_scripts;
        if (!isset($bsp_test_registered_scripts[$handle])) {
            $bsp_test_registered_scripts[$handle] = ['status' => 'enqueued'];
        }
        $bsp_test_registered_scripts[$handle]['status'] = 'enqueued';
        return true;
    }
}

if (!function_exists('wp_localize_script')) {
    function wp_localize_script($handle, $object_name, $data)
    {
        global $bsp_test_registered_scripts;
        $bsp_test_registered_scripts[$handle]['localized'][ $object_name ] = $data;
        return true;
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1)
    {
        return md5((string) $action);
    }
}

if (!function_exists('rest_url')) {
    function rest_url($path = '')
    {
        return 'http://example.com/wp-json/' . ltrim((string) $path, '/');
    }
}

if (!function_exists('wp_insert_post')) {
    function wp_insert_post($data)
    {
        global $bsp_test_posts;
        $id = isset($data['ID']) ? (int) $data['ID'] : (count($bsp_test_posts) + 1);
        $post = new WP_Post((object) [
            'ID'          => $id,
            'post_title'  => $data['post_title'] ?? '',
            'post_status' => $data['post_status'] ?? 'draft',
            'post_type'   => $data['post_type'] ?? 'post',
        ]);
        $bsp_test_posts[$id] = $post;
        return $id;
    }
}

if (!function_exists('get_post')) {
    function get_post($post_id)
    {
        global $bsp_test_posts;
        return $bsp_test_posts[$post_id] ?? null;
    }
}

if (!function_exists('get_posts')) {
    function get_posts($args = [])
    {
        global $bsp_test_posts;
        $posts = array_values($bsp_test_posts);
        if (isset($args['post_type'])) {
            $posts = array_filter($posts, static function ($post) use ($args) {
                return $post instanceof WP_Post && $post->post_type === $args['post_type'];
            });
        }
        return array_values($posts);
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $meta_key, $meta_value)
    {
        global $bsp_test_post_meta;
        if (!isset($bsp_test_post_meta[$post_id])) {
            $bsp_test_post_meta[$post_id] = [];
        }
        $bsp_test_post_meta[$post_id][$meta_key] = $meta_value;
        return true;
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $meta_key, $single = false)
    {
        global $bsp_test_post_meta;
        $value = $bsp_test_post_meta[$post_id][$meta_key] ?? ($single ? '' : []);
        return $single ? $value : [$value];
    }
}

if (!function_exists('admin_url')) {
    function admin_url($path = '')
    {
        return 'http://example.com/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field($action, $name)
    {
        echo '<input type="hidden" name="' . $name . '" value="nonce" />';
    }
}

if (!function_exists('submit_button')) {
    function submit_button($text)
    {
        echo '<button type="submit">' . $text . '</button>';
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url)
    {
        return $url;
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url)
    {
        return $url;
    }
}

if (!function_exists('admin_url')) {
    function admin_url($path = '')
    {
        return 'http://example.com/wp-admin/' . ltrim((string) $path, '/');
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return $text;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability)
    {
        return true;
    }
}

if (!function_exists('check_admin_referer')) {
    function check_admin_referer($action, $name = '_wpnonce')
    {
        return true;
    }
}

if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect($location)
    {
        global $bsp_test_redirects;
        $bsp_test_redirects[] = $location;
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg($key, $value, $url = '')
    {
        $url = $url ?: 'http://example.com';
        return $url . '?' . urlencode((string) $key) . '=' . urlencode((string) $value);
    }
}

if (!function_exists('wp_get_referer')) {
    function wp_get_referer()
    {
        return 'http://example.com/referrer';
    }
}

if (!function_exists('is_admin')) {
    function is_admin()
    {
        return defined('BSP_TEST_IS_ADMIN') ? BSP_TEST_IS_ADMIN : false;
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(private string $code = '', private string $message = '', private array $data = [])
        {
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}
if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration = 0)
    {
        global $bsp_test_transients;
        if (!isset($bsp_test_transients)) {
            $bsp_test_transients = [];
        }
        $bsp_test_transients[$transient] = [
            'value'   => $value,
            'expires' => $expiration > 0 ? time() + (int) $expiration : 0,
        ];
        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient($transient)
    {
        global $bsp_test_transients;
        if (!isset($bsp_test_transients[$transient])) {
            return false;
        }

        $entry = $bsp_test_transients[$transient];
        if ($entry['expires'] > 0 && $entry['expires'] < time()) {
            unset($bsp_test_transients[$transient]);
            return false;
        }

        return $entry['value'];
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($transient)
    {
        global $bsp_test_transients;
        if (isset($bsp_test_transients[$transient])) {
            unset($bsp_test_transients[$transient]);
        }
        return true;
    }
}
