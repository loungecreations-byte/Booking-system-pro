<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (! defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (! defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

if (! defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (! defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(
            public string $code = '',
            public string $message = '',
            public array $data = array()
        ) {
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}

if (! interface_exists('ArrayAccess')) {
    interface ArrayAccess {}
}

if (! class_exists('WP_REST_Request')) {
    class WP_REST_Request implements ArrayAccess
    {
        private array $params = array();
        private array $headers = array();

        public function __construct(string $method = 'GET', string $route = '/')
        {
            unset($method, $route);
        }

        public function get_json_params(): array
        {
            return $this->params;
        }

        public function get_param(string $key)
        {
            return $this->params[$key] ?? null;
        }

        public function get_params(): array
        {
            return $this->params;
        }

        public function set_param(string $key, $value): void
        {
            $this->params[$key] = $value;
        }

        public function get_header(string $key): string
        {
            $normalized = strtolower($key);
            return (string) ($this->headers[$normalized] ?? '');
        }

        public function set_header(string $key, string $value): void
        {
            $this->headers[strtolower($key)] = $value;
        }

        #[\ReturnTypeWillChange]
        public function offsetExists($offset): bool
        {
            return isset($this->params[$offset]);
        }

        #[\ReturnTypeWillChange]
        public function offsetGet($offset)
        {
            return $this->params[$offset] ?? null;
        }

        #[\ReturnTypeWillChange]
        public function offsetSet($offset, $value): void
        {
            $this->params[$offset] = $value;
        }

        #[\ReturnTypeWillChange]
        public function offsetUnset($offset): void
        {
            unset($this->params[$offset]);
        }
    }
}

if (! class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        private int $status;
        private $data;

        public function __construct($data = null, int $status = 200)
        {
            $this->data = $data;
            $this->status = $status;
        }

        public function get_data()
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }
    }
}

if (! class_exists('wpdb')) {
    class wpdb
    {
        public string $prefix = 'wp_';
        public int $insert_id = 0;
        /** @var array<string, array<int, array<string, mixed>>> */
        public array $storage = array();

        public function get_charset_collate(): string
        {
            return '';
        }

        public function prepare(string $query, ...$args): string
        {
            if (count($args) === 1 && is_array($args[0])) {
                $args = $args[0];
            }

            foreach ($args as $arg) {
                $replacement = is_numeric($arg) ? (string) $arg : "'" . addslashes((string) $arg) . "'";
                $query = preg_replace('/%[sd]/', $replacement, $query, 1) ?? $query;
            }

            return $query;
        }

        public function insert(string $table, array $data, ...$formats)
        {
            unset($formats);
            $rows = $this->storage[$table] ?? array();
            $id = count($rows) + 1;
            $data['id'] = $id;
            $rows[] = $data;
            $this->storage[$table] = $rows;
            $this->insert_id = $id;
            return 1;
        }

        public function update(string $table, array $data, array $where, ...$formats)
        {
            unset($formats);
            if (! isset($this->storage[$table])) {
                return false;
            }

            foreach ($this->storage[$table] as $index => $row) {
                $match = true;
                foreach ($where as $key => $value) {
                    if (($row[$key] ?? null) != $value) {
                        $match = false;
                        break;
                    }
                }

                if ($match) {
                    $this->storage[$table][$index] = array_merge($row, $data);
                    return 1;
                }
            }

            return 0;
        }

        public function delete(string $table, array $where)
        {
            if (! isset($this->storage[$table])) {
                return 0;
            }

            $remaining = array();
            $deleted = 0;
            foreach ($this->storage[$table] as $row) {
                $match = true;
                foreach ($where as $key => $value) {
                    if (($row[$key] ?? null) != $value) {
                        $match = false;
                        break;
                    }
                }

                if ($match) {
                    $deleted++;
                    continue;
                }

                $remaining[] = $row;
            }

            $this->storage[$table] = $remaining;
            return $deleted;
        }

        public function get_row(string $query, $output = ARRAY_A)
        {
            unset($output);
            $rows = $this->runSelect($query);
            return $rows[0] ?? null;
        }

        public function get_results(string $query, $output = ARRAY_A): array
        {
            unset($output);
            return $this->runSelect($query);
        }

        public function get_var(string $query)
        {
            if (preg_match("/SHOW TABLES LIKE '([^']+)'/i", $query, $matches)) {
                $table = $matches[1];
                return array_key_exists($table, $this->storage) ? $table : null;
            }

            $rows = $this->runSelect($query);
            if ($rows === array()) {
                return null;
            }

            $first = $rows[0];
            return reset($first);
        }

        /**
         * @return array<int, array<string, mixed>>
         */
        private function runSelect(string $query): array
        {
            if (! preg_match('/FROM\s+([a-zA-Z0-9_]+)/i', $query, $matches)) {
                return array();
            }

            $table = $matches[1];
            $rows = $this->storage[$table] ?? array();

            if (preg_match('/WHERE\s+([a-zA-Z0-9_]+)\s*=\s*(?:\'([^\']*)\'|([0-9]+))/i', $query, $whereMatches)) {
                $column = $whereMatches[1];
                $value = $whereMatches[2] !== '' ? $whereMatches[2] : $whereMatches[3];
                $rows = array_values(array_filter($rows, static function (array $row) use ($column, $value): bool {
                    return (string) ($row[$column] ?? '') === (string) $value;
                }));
            }

            if (str_contains($query, 'ORDER BY') && str_contains($query, 'DESC')) {
                usort($rows, static fn (array $left, array $right): int => ((int) ($right['id'] ?? 0)) <=> ((int) ($left['id'] ?? 0)));
            }

            return $rows;
        }
    }
}

$GLOBALS['wpdb'] = $GLOBALS['wpdb'] ?? new wpdb();
$GLOBALS['__test_current_user_can'] = false;
$GLOBALS['__test_current_user_id'] = 0;
$GLOBALS['__test_options'] = array();
$GLOBALS['__test_transients'] = array();
$GLOBALS['__test_dbdelta_calls'] = array();
$GLOBALS['__test_rest_routes'] = array();
$GLOBALS['__test_filters'] = array();
$GLOBALS['__test_actions'] = array();
$GLOBALS['__test_shortcodes'] = array();
$GLOBALS['__test_wp_mail_calls'] = array();
$GLOBALS['__test_wp_remote_post'] = null;
$GLOBALS['__test_is_account_page'] = false;
$GLOBALS['__test_wc_endpoint'] = '';
$GLOBALS['__test_enqueued_scripts'] = array();
$GLOBALS['__test_localized_scripts'] = array();

function current_time($type = 'mysql', $gmt = false): string
{
    unset($type, $gmt);
    return '2026-04-13 10:00:00';
}

function absint($maybeint): int
{
    return abs((int) $maybeint);
}

function wp_json_encode($value): string
{
    return json_encode($value);
}

function get_option(string $key, $default = false)
{
    return $GLOBALS['__test_options'][$key] ?? $default;
}

function update_option(string $key, $value, bool $autoload = false): bool
{
    unset($autoload);
    $GLOBALS['__test_options'][$key] = $value;
    return true;
}

function dbDelta(string $sql): void
{
    $GLOBALS['__test_dbdelta_calls'][] = $sql;
    if (preg_match('/CREATE TABLE\s+([a-zA-Z0-9_]+)/i', $sql, $matches)) {
        $GLOBALS['wpdb']->storage[$matches[1]] = $GLOBALS['wpdb']->storage[$matches[1]] ?? array();
    }
}

function register_rest_route(string $namespace, string $route, array $args): void
{
    $GLOBALS['__test_rest_routes'][] = array($namespace, $route, $args);
}

function wp_strip_all_tags(string $text): string
{
    return strip_tags($text);
}

function rest_ensure_response($data)
{
    return $data;
}

function current_user_can(string $capability): bool
{
    unset($capability);
    return (bool) $GLOBALS['__test_current_user_can'];
}

function maybe_unserialize($value)
{
    return $value;
}

function get_current_user_id(): int
{
    return (int) $GLOBALS['__test_current_user_id'];
}

function add_filter(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
{
    $GLOBALS['__test_filters'][$tag][$priority][] = array(
        'callback' => $callback,
        'accepted_args' => $acceptedArgs,
    );

    return true;
}

function add_action(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
{
    $GLOBALS['__test_actions'][$tag][$priority][] = array(
        'callback' => $callback,
        'accepted_args' => $acceptedArgs,
    );

    return true;
}

function add_shortcode(string $tag, callable $callback): bool
{
    $GLOBALS['__test_shortcodes'][$tag] = $callback;
    return true;
}

function shortcode_exists(string $tag): bool
{
    return isset($GLOBALS['__test_shortcodes'][$tag]);
}

function do_shortcode(string $content): string
{
    return preg_replace_callback('/\[([a-zA-Z0-9_]+)(?:\s+[^\]]*)?\]/', static function (array $matches): string {
        $tag = $matches[1];
        if (! isset($GLOBALS['__test_shortcodes'][$tag])) {
            return $matches[0];
        }

        return (string) ($GLOBALS['__test_shortcodes'][$tag])(array(), null, $tag);
    }, $content) ?? $content;
}

function has_shortcode(string $content, string $tag): bool
{
    return preg_match('/\[' . preg_quote($tag, '/') . '(?:\s|\])/', $content) === 1;
}

function apply_filters(string $tag, $value, ...$args)
{
    if (empty($GLOBALS['__test_filters'][$tag]) || ! is_array($GLOBALS['__test_filters'][$tag])) {
        return $value;
    }

    ksort($GLOBALS['__test_filters'][$tag]);
    foreach ($GLOBALS['__test_filters'][$tag] as $callbacks) {
        foreach ($callbacks as $entry) {
            $acceptedArgs = max(1, (int) ($entry['accepted_args'] ?? 1));
            $callArgs = array_slice(array_merge(array($value), $args), 0, $acceptedArgs);
            $value = ($entry['callback'])(...$callArgs);
        }
    }

    return $value;
}

function remove_all_filters(string $tag): bool
{
    unset($GLOBALS['__test_filters'][$tag]);
    return true;
}

function do_action(string $tag, ...$args): void
{
    if (empty($GLOBALS['__test_actions'][$tag]) || ! is_array($GLOBALS['__test_actions'][$tag])) {
        return;
    }

    ksort($GLOBALS['__test_actions'][$tag]);
    foreach ($GLOBALS['__test_actions'][$tag] as $callbacks) {
        foreach ($callbacks as $entry) {
            $acceptedArgs = max(0, (int) ($entry['accepted_args'] ?? 0));
            $callArgs = $acceptedArgs > 0 ? array_slice($args, 0, $acceptedArgs) : array();
            ($entry['callback'])(...$callArgs);
        }
    }
}

function wp_mail($to, string $subject, string $message, $headers = array()): bool
{
    $GLOBALS['__test_wp_mail_calls'][] = array(
        'to'      => $to,
        'subject' => $subject,
        'message' => $message,
        'headers' => $headers,
    );

    if (isset($GLOBALS['__test_wp_mail_result']) && $GLOBALS['__test_wp_mail_result'] === false) {
        do_action('wp_mail_failed', new WP_Error('wp_mail_failed', (string) ($GLOBALS['__test_wp_mail_error'] ?? 'Simulated mail failure')));
        return false;
    }

    return true;
}

function set_transient(string $key, $value, int $expiration = 0): bool
{
    if (isset($GLOBALS['__eliio_test_transients']) && is_array($GLOBALS['__eliio_test_transients'])) {
        $GLOBALS['__eliio_test_transients'][$key] = $value;
        $GLOBALS['__eliio_test_transient_ttl'][$key] = $expiration;
    }

    $GLOBALS['__test_transients'][$key] = array(
        'value' => $value,
        'expiration' => $expiration,
    );

    return true;
}

function get_transient(string $key)
{
    if (isset($GLOBALS['__eliio_test_transients']) && is_array($GLOBALS['__eliio_test_transients'])) {
        return array_key_exists($key, $GLOBALS['__eliio_test_transients'])
            ? $GLOBALS['__eliio_test_transients'][$key]
            : false;
    }

    return $GLOBALS['__test_transients'][$key]['value'] ?? false;
}

function home_url(string $path = ''): string
{
    return 'https://example.test' . $path;
}

function get_page_by_path(string $page_path, $output = OBJECT, string $post_type = 'page')
{
    unset($page_path, $output, $post_type);
    return null;
}

function get_permalink($post): string
{
    unset($post);
    return 'https://example.test/private-tour-portal/';
}

function add_query_arg(array $args, string $url): string
{
    $separator = str_contains($url, '?') ? '&' : '?';
    return $url . $separator . http_build_query($args);
}

function __(string $text, string $domain = 'default'): string
{
    unset($domain);
    return $text;
}

function __return_true(): bool
{
    return true;
}

function is_wp_error($value): bool
{
    return $value instanceof WP_Error;
}

function wp_remote_post(string $url, array $args = array())
{
    unset($url, $args);

    return $GLOBALS['__test_wp_remote_post'] ?? new WP_Error('missing_remote_stub', 'missing_remote_stub');
}

function wp_remote_retrieve_response_code($response): int
{
    return (int) ($response['response']['code'] ?? 0);
}

function wp_remote_retrieve_body($response): string
{
    return (string) ($response['body'] ?? '');
}

function esc_html__(string $text, string $domain = 'default'): string
{
    unset($domain);
    return $text;
}

function esc_url_raw(string $url): string
{
    return $url;
}

function rest_url(string $path = ''): string
{
    return 'https://example.test/wp-json/' . ltrim($path, '/');
}

function wp_create_nonce(string $action): string
{
    return 'valid-nonce-' . $action;
}

function is_account_page(): bool
{
    return (bool) $GLOBALS['__test_is_account_page'];
}

function is_wc_endpoint_url(string $endpoint = ''): bool
{
    return $endpoint !== '' && $endpoint === (string) $GLOBALS['__test_wc_endpoint'];
}

function wp_enqueue_script(string $handle, string $src = '', array $dependencies = array(), $version = false, bool $inFooter = false): void
{
    $GLOBALS['__test_enqueued_scripts'][] = compact('handle', 'src', 'dependencies', 'version', 'inFooter');
}

function wp_localize_script(string $handle, string $objectName, array $data): bool
{
    $GLOBALS['__test_localized_scripts'][] = compact('handle', 'objectName', 'data');
    return true;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'BSP\\';
    $baseDir = dirname(__DIR__) . '/modules/';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $parts = explode('\\', $relative);
    $module = strtolower(array_shift($parts));
    $file = $baseDir . $module . '/' . implode('/', $parts) . '.php';
    if (is_readable($file)) {
        require_once $file;
    }
});
