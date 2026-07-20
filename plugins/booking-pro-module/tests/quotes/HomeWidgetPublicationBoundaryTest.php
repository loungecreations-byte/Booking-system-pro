<?php

declare(strict_types=1);

namespace {
    if (! function_exists('add_action')) {
        function add_action(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
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

    if (! function_exists('shortcode_exists')) {
        function shortcode_exists(string $tag): bool
        {
            unset($tag);
            return false;
        }
    }
}

namespace BSP\Tests\Quotes {

use PHPUnit\Framework\TestCase;
use SBDP\PlanningSessions\Controller;

require_once dirname(__DIR__, 2) . '/includes/planning-sessions.php';

final class HomeWidgetPublicationBoundaryTest extends TestCase
{
    public function testSnapshotMarkupNormalizesToCanonicalShortcode(): void
    {
        $content = <<<'HTML'
<div class="hero-copy">Welkom</div>
<section class="ui-planner-widget ui-planner-widget--light" data-ui-planner-widget>
  <div class="ui-planner-widget__steps">
    <label><input type="number" value="10" data-ui-count></label>
  </div>
  <section class="ui-planner-widget__discovery" data-ui-discovery hidden>
    <div>nested section</div>
  </section>
</section>
<p>Na de widget</p>
HTML;

        $normalized = Controller::normalize_home_widget_publication_content($content);

        $this->assertSame(
            "<div class=\"hero-copy\">Welkom</div>\n[ddb_home_widget style=\"light\" count=\"10\"]\n<p>Na de widget</p>",
            $normalized
        );
        $this->assertStringNotContainsString('data-ui-planner-widget', $normalized);
    }

    public function testExistingShortcodePublicationRemainsUntouched(): void
    {
        $content = '[ddb_home_widget style="dark" count="12"]';

        $normalized = Controller::normalize_home_widget_publication_content($content);

        $this->assertSame($content, $normalized);
    }

    public function testPostDataNormalizationPreventsSnapshotPersistence(): void
    {
        $data = [
            'post_type' => 'page',
            'post_content' => '<section class="ui-planner-widget ui-planner-widget--dark" data-ui-planner-widget><input value="6" data-ui-count></section>',
        ];

        $normalized = Controller::normalize_home_widget_post_data($data, $data);

        $this->assertSame('[ddb_home_widget style="dark" count="6"]', $normalized['post_content']);
    }

    public function testHomepagePage296StoredContentUsesCanonicalShortcode(): void
    {
        if (getenv('DDB_RUN_WPCLI_CONTENT_CHECK') !== '1') {
            $this->markTestSkipped('Set DDB_RUN_WPCLI_CONTENT_CHECK=1 to run the local WP-CLI page 296 publication hygiene check.');
        }

        $pluginRoot = dirname(__DIR__, 2);
        $wpRoot = dirname($pluginRoot, 3);
        $wpCli = $wpRoot . DIRECTORY_SEPARATOR . 'wp-cli.phar';

        if (! is_file($wpCli)) {
            $this->markTestSkipped('Local wp-cli.phar not available for page 296 publication hygiene check.');
        }

        $command = sprintf(
            'php %s post get 296 --field=post_content --path=%s',
            escapeshellarg($wpCli),
            escapeshellarg($wpRoot)
        );

        $output = shell_exec($command);
        if (! is_string($output) || trim($output) === '') {
            $this->markTestSkipped('Unable to read homepage page 296 content through WP-CLI.');
        }

        $this->assertStringContainsString('[ddb_home_widget', $output);
        $this->assertStringNotContainsString('data-ui-planner-widget', $output);
        $this->assertStringNotContainsString('ui-planner-widget__modal-actions', $output);
        $this->assertStringNotContainsString('data-ui-close', $output);
    }
}
}
