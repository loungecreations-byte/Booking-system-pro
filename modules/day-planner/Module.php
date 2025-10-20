<?php

declare(strict_types=1);

namespace BSP\DayPlanner;

use BSP\Core\CoreServiceProvider;
use BSP\Core\Interfaces\ModuleInterface;
use BSP\DayPlanner\PostType\PlanPostType;
use BSP\DayPlanner\Service\PlanService;

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

final class Module implements ModuleInterface
{
    private ?PlanService $service = null;

    public function init(): void
    {
        CoreServiceProvider::logger()->log('Day Planner module initialised');

        if (\function_exists('add_action')) {
            \add_action('init', [$this, 'registerPostType']);
            \add_action('init', [$this, 'registerSettings']);
            \add_action('rest_api_init', [$this, 'registerRestRoutes']);
            \add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
            \add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        }

        if (\function_exists('add_filter')) {
            \add_filter('script_loader_tag', [$this, 'markScriptAsModule'], 10, 3);
        }
    }

    public function registerPostType(): void
    {
        PlanPostType::register();
    }

    public function registerSettings(): void
    {
        \register_setting(
            'sbdp_day_planner',
            'sbdp_day_planner_settings',
            [
                'type'              => 'array',
                'sanitize_callback' => [PlanService::class, 'sanitizeSettings'],
                'default'           => [
                    'time_step_minutes' => 15,
                    'open_hours'        => [
                        'start' => '08:00',
                        'end'   => '22:00',
                    ],
                    'allow_multi_day'   => true,
                    'default_day_count' => 1,
                    'autosave'          => true,
                    'currency'          => 'EUR',
                    'locale'            => 'nl-NL',
                ],
            ]
        );
    }

    public function service(): PlanService
    {
        if ($this->service === null) {
            $this->service = new PlanService();
        }

        return $this->service;
    }

    public function registerRestRoutes(): void
    {
        Rest\PlansController::register($this->service());
    }

    public function enqueueAssets(?string $hook = null): void
    {
        unset($hook);

        if (! \function_exists('wp_enqueue_script')) {
            return;
        }

        if (! \is_admin() && ! $this->shouldEnqueueFrontend()) {
            return;
        }

        $asset = $this->resolveAsset('assets/js/day-planner/index.jsx');

        \wp_enqueue_script(
            'sbdp-day-planner-app',
            $asset['script'],
            array('wp-element'),
            SBDP_VER,
            true
        );

        foreach ($asset['styles'] as $handle => $url) {
            \wp_enqueue_style($handle, $url, array(), SBDP_VER);
        }

        \wp_enqueue_style(
            'sbdp-day-planner-base',
            SBDP_URL . 'assets/css/day-planner.css',
            array(),
            SBDP_VER
        );

        \wp_localize_script(
            'sbdp-day-planner-app',
            'SBDP_DAY_PLANNER',
            array(
                'restBase' => \rest_url('planner/v1'),
                'nonce'    => \wp_create_nonce('wp_rest'),
                'config'   => \get_option('sbdp_day_planner_settings', array()),
            )
        );
    }

    private function shouldEnqueueFrontend(): bool
    {
        /**
         * Allow forcing day planner assets to load on all pages.
         *
         * @param bool $force Force enqueue.
         */
        $force = (bool) \apply_filters('sbdp_day_planner_force_enqueue', false);
        if ($force) {
            return true;
        }

        if (! \is_singular()) {
            return false;
        }

        $post = \get_post();
        if (! $post || ! isset($post->post_content)) {
            return false;
        }

        $containsShortcode = \has_shortcode($post->post_content, 'sbdp_dayplanner');

        /**
         * Filter whether the day planner assets should be enqueued on the current frontend request.
         *
         * @param bool   $shouldEnqueue Default decision derived from shortcode detection.
         * @param \WP_Post $post        Current post object.
         */
        return (bool) \apply_filters('sbdp_day_planner_enqueue_assets', $containsShortcode, $post);
    }

    /**
     * @return array{script:string,styles:array<string,string>}
     */
    private function resolveAsset(string $entry): array
    {
        $manifestPath = SBDP_DIR . 'build/.vite/manifest.json';

        if (! is_readable($manifestPath)) {
            return array(
                'script' => SBDP_URL . $entry,
                'styles' => array(),
            );
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($manifest) || ! isset($manifest[$entry])) {
            return array(
                'script' => SBDP_URL . $entry,
                'styles' => array(),
            );
        }

        $entryData = $manifest[$entry];
        $script    = isset($entryData['file'])
            ? SBDP_URL . 'build/' . $entryData['file']
            : SBDP_URL . $entry;
        $styles    = array();

        if (! empty($entryData['css']) && is_array($entryData['css'])) {
            foreach ($entryData['css'] as $index => $cssFile) {
                $handle = 'sbdp-day-planner-style-' . $index;
                $styles[$handle] = SBDP_URL . 'build/' . ltrim((string) $cssFile, '/');
            }
        }

        return array(
            'script' => $script,
            'styles' => $styles,
        );
    }

    /**
     * Ensure the day planner bundle is executed as an ES module.
     */
    public function markScriptAsModule(string $tag, string $handle, string $src): string
    {
        unset($src);

        if ($handle !== 'sbdp-day-planner-app') {
            return $tag;
        }

        if (strpos($tag, 'type=') === false) {
            return str_replace('<script ', '<script type="module" ', $tag);
        }

        return (string) preg_replace('/type=(["\']).*?\\1/', 'type="module"', $tag, 1);
    }
}

if (! \class_exists('BSPModule\\DayPlanner\\Module', false)) {
    \class_alias(Module::class, 'BSPModule\\DayPlanner\\Module');
}

// phpcs:enable PSR1.Files.SideEffects.FoundWithSymbols
