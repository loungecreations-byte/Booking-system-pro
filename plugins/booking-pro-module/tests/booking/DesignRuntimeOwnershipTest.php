<?php

declare(strict_types=1);

namespace {
    if (! defined('ABSPATH')) {
        define('ABSPATH', dirname(__DIR__, 2) . '/');
    }

    if (! defined('WP_CONTENT_DIR')) {
        define('WP_CONTENT_DIR', dirname(__DIR__, 5));
    }

    if (! function_exists('add_action')) {
        function add_action(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
        {
            unset($tag, $callback, $priority, $acceptedArgs);
            return true;
        }
    }

    if (! function_exists('add_filter')) {
        function add_filter(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
        {
            unset($tag, $callback, $priority, $acceptedArgs);
            return true;
        }
    }

    if (! function_exists('add_shortcode')) {
        function add_shortcode(string $tag, callable $callback): bool
        {
            unset($tag, $callback);
            return true;
        }
    }

    if (! function_exists('apply_filters')) {
        function apply_filters(string $tag, $value)
        {
            unset($tag);
            return $value;
        }
    }

    if (! function_exists('plugin_dir_path')) {
        function plugin_dir_path(string $file): string
        {
            return rtrim(dirname($file), '\\/') . DIRECTORY_SEPARATOR;
        }
    }

    if (! function_exists('plugin_dir_url')) {
        function plugin_dir_url(string $file): string
        {
            return 'https://dagjedenbosch.test/wp-content/plugins/' . basename(dirname($file)) . '/';
        }
    }

    if (! function_exists('content_url')) {
        function content_url(string $path = ''): string
        {
            return 'https://dagjedenbosch.test/wp-content' . $path;
        }
    }

    if (! function_exists('is_admin')) {
        function is_admin(): bool
        {
            return false;
        }
    }

    if (! function_exists('wp_doing_ajax')) {
        function wp_doing_ajax(): bool
        {
            return false;
        }
    }

    if (! function_exists('wp_doing_cron')) {
        function wp_doing_cron(): bool
        {
            return false;
        }
    }

    if (! function_exists('is_feed')) {
        function is_feed(): bool
        {
            return false;
        }
    }

    if (! function_exists('is_trackback')) {
        function is_trackback(): bool
        {
            return false;
        }
    }

    if (! function_exists('is_robots')) {
        function is_robots(): bool
        {
            return false;
        }
    }

    if (! function_exists('wp_script_add_data')) {
        function wp_script_add_data(string $handle, string $key, string $value): bool
        {
            unset($handle, $key, $value);
            return true;
        }
    }

    if (! function_exists('wp_localize_script')) {
        function wp_localize_script(string $handle, string $objectName, array $l10n): bool
        {
            unset($handle, $objectName, $l10n);
            return true;
        }
    }

    if (! function_exists('wp_register_script')) {
        function wp_register_script(string $handle, string $src = '', array $deps = [], $ver = false, bool $inFooter = false): bool
        {
            unset($src, $deps, $ver, $inFooter);
            $GLOBALS['__design_test_scripts_registered'][$handle] = true;
            return true;
        }
    }

    if (! function_exists('wp_enqueue_script')) {
        function wp_enqueue_script(string $handle): bool
        {
            $GLOBALS['__design_test_scripts_enqueued'][$handle] = true;
            return true;
        }
    }

    if (! function_exists('wp_register_style')) {
        function wp_register_style(string $handle, string $src = '', array $deps = [], $ver = false): bool
        {
            unset($src, $deps, $ver);
            $GLOBALS['__design_test_styles_registered'][$handle] = true;
            return true;
        }
    }

    if (! function_exists('wp_enqueue_style')) {
        function wp_enqueue_style(string $handle): bool
        {
            $GLOBALS['__design_test_styles_enqueued'][$handle] = true;
            return true;
        }
    }

    if (! function_exists('wp_dequeue_style')) {
        function wp_dequeue_style(string $handle): bool
        {
            unset($GLOBALS['__design_test_styles_enqueued'][$handle]);
            $GLOBALS['__design_test_styles_dequeued'][$handle] = true;
            return true;
        }
    }

    if (! function_exists('wp_style_is')) {
        function wp_style_is(string $handle, string $list = 'enqueued'): bool
        {
            if ($list === 'registered') {
                return ! empty($GLOBALS['__design_test_styles_registered'][$handle]);
            }

            return ! empty($GLOBALS['__design_test_styles_enqueued'][$handle]);
        }
    }

    if (! function_exists('wp_script_is')) {
        function wp_script_is(string $handle, string $list = 'enqueued'): bool
        {
            if ($list === 'registered') {
                return ! empty($GLOBALS['__design_test_scripts_registered'][$handle]);
            }

            return ! empty($GLOBALS['__design_test_scripts_enqueued'][$handle]);
        }
    }

    if (! function_exists('home_url')) {
        function home_url(string $path = ''): string
        {
            return 'https://dagjedenbosch.test' . $path;
        }
    }

    if (! function_exists('get_option')) {
        function get_option(string $option, $default = false)
        {
            unset($option);
            return $default;
        }
    }

    if (! class_exists('WP_Post')) {
        class WP_Post
        {
            public int $ID = 0;
            public string $post_content = '';
        }
    }

    if (! function_exists('get_queried_object')) {
        function get_queried_object()
        {
            return null;
        }
    }

    if (! function_exists('get_post')) {
        function get_post($post = null)
        {
            unset($post);
            return null;
        }
    }

    if (! function_exists('is_front_page')) {
        function is_front_page(): bool
        {
            return false;
        }
    }

    if (! function_exists('is_home')) {
        function is_home(): bool
        {
            return false;
        }
    }

    if (! function_exists('is_singular')) {
        function is_singular($postTypes = null): bool
        {
            unset($postTypes);
            return false;
        }
    }

    if (! function_exists('is_page')) {
        function is_page($page = null): bool
        {
            unset($page);
            return false;
        }
    }

    if (! function_exists('is_post_type_archive')) {
        function is_post_type_archive($postTypes = null): bool
        {
            unset($postTypes);
            return false;
        }
    }

    if (! function_exists('wp_add_inline_style')) {
        function wp_add_inline_style(string $handle, string $data): bool
        {
            $GLOBALS['__design_test_inline_styles'][$handle][] = $data;
            return true;
        }
    }

    if (! function_exists('__return_false')) {
        function __return_false(): bool
        {
            return false;
        }
    }
}

namespace BSP\Tests\BookingTruth {

use PHPUnit\Framework\TestCase;
use ReflectionProperty;

require_once dirname(__DIR__, 3) . '/ddb-core-ui/core-ui.php';
require_once dirname(__DIR__, 4) . '/mu-plugins/ddb-core-design-system.php';

final class DesignRuntimeOwnershipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__design_test_styles_registered'] = [];
        $GLOBALS['__design_test_styles_enqueued'] = [];
        $GLOBALS['__design_test_styles_dequeued'] = [];
        $GLOBALS['__design_test_scripts_registered'] = [];
        $GLOBALS['__design_test_scripts_enqueued'] = [];
        $GLOBALS['__design_test_inline_styles'] = [];

        $this->setStaticProperty(\DDB_Core_UI::class, 'should_enqueue_frontend_assets', null);
        $this->setStaticProperty(\DDB_Core_UI::class, 'current_request_sources', null);
        $this->setStaticProperty(\DDB_Core_Design_System::class, 'did_enqueue_site_assets', false);
        $this->setStaticProperty(\DDB_Core_Design_System::class, 'did_enqueue_front_assets', false);
    }

    public function testCoreUiRemainsPublicDesignEmitterWhenMuBridgeRuns(): void
    {
        \DDB_Core_UI::enqueue_assets();
        \DDB_Core_Design_System::enqueue_site_assets();

        $enqueued = array_keys($GLOBALS['__design_test_styles_enqueued']);

        $this->assertContains('ddb-core-ui', $enqueued);
        $this->assertContains('ddb-core-ui-light', $enqueued);
        $this->assertContains('ddb-core-ui-dark', $enqueued);
        $this->assertNotContains('ddb-ui', $enqueued);
        $this->assertNotContains('ddb-design-system', $enqueued);
        $this->assertNotContains('ddb-platform-normalization', $enqueued);
    }

    public function testMuBridgeDoesNotRewriteFrontendHtmlWhenCoreUiIsActive(): void
    {
        $html = '<html><head><link rel="stylesheet" href="/legacy.css"></head><body><main>Content</main></body></html>';

        $result = \DDB_Core_Design_System::sanitize_front_html($html);

        $this->assertSame($html, $result);
    }

    private function setStaticProperty(string $className, string $propertyName, $value): void
    {
        $property = new ReflectionProperty($className, $propertyName);
        $property->setAccessible(true);
        $property->setValue($value);
    }
}
}
