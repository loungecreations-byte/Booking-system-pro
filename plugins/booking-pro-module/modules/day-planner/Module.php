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
    private const EMPTY_NOTICE_TRANSIENT = 'sbdp_planner_catalog_empty';
    private const ROUTER_REWRITE_VERSION = 2;

    private ?PlanService $service = null;

    public function init(): void
    {
        CoreServiceProvider::logger()->log('Day Planner module initialised');

        if (\function_exists('add_action')) {
            \add_action('init', [$this, 'registerPostType']);
            \add_action('init', [$this, 'registerSettings']);
            \add_action('init', [$this, 'registerShortcodes']);
            \add_action('rest_api_init', [$this, 'registerRestRoutes']);
            \add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
            \add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
            \add_action('admin_notices', [$this, 'maybeRenderEmptyNotice']);
            \add_action('init', [$this, 'registerRouterRewrite'], 11);
            \add_action('send_headers', [$this, 'sendPlannerReferrerPolicy']);
            \add_action('wp_head', [$this, 'renderPlannerPrefillUrlCleaner'], 0);
        }

        if (\function_exists('add_filter')) {
            \add_filter('script_loader_tag', [$this, 'markScriptAsModule'], 10, 3);
            \add_filter('query_vars', [$this, 'registerQueryVars']);
            \add_filter('request', [$this, 'forcePlannerRouteRequest'], 5);
        }
    }

    public function registerShortcodes(): void
    {
        if (! \function_exists('add_shortcode')) {
            return;
        }

        \add_shortcode('sbdp_dayplanner', [$this, 'renderShortcode']);
        \add_shortcode('sbdp_day_planner', [$this, 'renderShortcode']);
    }

    /**
     * Render the public planner mount point.
     *
     * The React planner owns orchestration after this container mounts. This
     * method intentionally avoids pricing, availability, cart, checkout, or
     * participant truth.
     *
     * @param array<string, mixed>|string $atts
     */
    public function renderShortcode($atts = []): string
    {
        unset($atts);

        if (! \function_exists('esc_attr__') || ! \function_exists('esc_html_e')) {
            return '<section class="sbdp-day-planner-shell"><div class="sbdp-day-planner-shell__mounts"><div id="sbdp-day-planner-root" data-component="sbdp-day-planner" aria-hidden="true"></div></div><noscript><p class="sbdp-day-planner__noscript">Schakel JavaScript in om te plannen.</p></noscript></section>';
        }

        \ob_start();
        ?>
        <section class="sbdp-day-planner-shell" aria-label="<?php echo \esc_attr__('Plan je dag', 'sbdp'); ?>">
            <div class="sbdp-day-planner-shell__mounts">
                <div id="sbdp-day-planner-root" data-component="sbdp-day-planner" aria-hidden="true"></div>
            </div>
            <noscript>
                <p class="sbdp-day-planner__noscript">
                    <?php \esc_html_e('Schakel JavaScript in om te plannen.', 'sbdp'); ?>
                </p>
            </noscript>
        </section>
        <?php
        return \trim((string) \ob_get_clean());
    }

    public function sendPlannerReferrerPolicy(): void
    {
        if (\headers_sent()) {
            return;
        }

        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $isPlannerRequest = strpos($requestUri, '/plan-je-dag') !== false || isset($_GET['sbdp_prefill']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (! $isPlannerRequest) {
            return;
        }

        \header('Referrer-Policy: origin');
    }

    public function renderPlannerPrefillUrlCleaner(): void
    {
        if (! $this->hasPlannerPrefillQuery()) {
            return;
        }

        ?>
        <script>
        (function(){
          try {
            var url = new URL(window.location.href);
            var changed = false;
            ["sbdp_prefill", "sbdp_product", "sbdp_date", "sbdp_time", "sbdp_participants", "sbdp_resource"].forEach(function(key){
              if (url.searchParams.has(key)) {
                url.searchParams.delete(key);
                changed = true;
              }
            });
            if (changed && window.history && window.history.replaceState) {
              window.history.replaceState(null, document.title, url.pathname + url.search + url.hash);
            }
            ["sbjs_migrations","sbjs_current_add","sbjs_first_add","sbjs_current","sbjs_first","sbjs_udata","sbjs_session"].forEach(function(name){
              document.cookie = name + "=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/";
            });
          } catch (error) {}
        })();
        </script>
        <?php
    }

    private function hasPlannerPrefillQuery(): bool
    {
        foreach (array('sbdp_prefill', 'sbdp_product', 'sbdp_date', 'sbdp_time', 'sbdp_participants', 'sbdp_resource') as $key) {
            if (isset($_GET[$key])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                return true;
            }
        }

        return false;
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

        self::enqueuePricingHelpers();
        $this->enqueueSharedUi();
        wp_enqueue_script('sbdp-shared-helpers', SBDP_URL . 'assets/js/shared-helpers.js', array(), SBDP_VER, true);

        $asset = $this->resolveAsset('assets/js/day-planner/index.jsx');

        // The primary planner app is now responsive across all breakpoints.
        // Keep the legacy mobile bundle disabled to prevent duplicate/inconsistent renders.
        $enqueueMobileBundle = false;

        \wp_enqueue_script(
            'sbdp-day-planner-app',
            $asset['script']['src'],
            array('wp-element', 'sbdp-day-planner-helpers'),
            $asset['script']['version'] ?? SBDP_VER,
            true
        );

        foreach ($asset['styles'] as $handle => $style) {
            \wp_enqueue_style($handle, $style['src'], array(), $style['version']);
        }

        if ($enqueueMobileBundle) {
            $mobileAsset = $this->resolveAsset('assets/js/mobile-dayplanner/index.jsx');

            \wp_enqueue_script(
                'sbdp-mobile-day-planner-app',
                $mobileAsset['script']['src'],
                array('wp-element', 'sbdp-day-planner-helpers'),
                $mobileAsset['script']['version'],
                true
            );

            foreach ($mobileAsset['styles'] as $handle => $style) {
                \wp_enqueue_style($handle, $style['src'], array(), $style['version']);
            }
        }

        $baseStyleUrl = SBDP_URL . 'assets/css/day-planner.css';
        \wp_enqueue_style(
            'sbdp-day-planner-base',
            $baseStyleUrl,
            array(),
            $this->determineAssetVersion($baseStyleUrl)
        );

        $refreshStyleUrl = SBDP_URL . 'assets/css/day-planner-refresh.css';
        \wp_enqueue_style(
            'sbdp-day-planner-refresh',
            $refreshStyleUrl,
            array('sbdp-day-planner-base'),
            $this->determineAssetVersion($refreshStyleUrl)
        );

        $listingCardStyleUrl = SBDP_URL . 'assets/css/ddb-shared-listing-card.css';
        \wp_enqueue_style(
            'ddb-shared-listing-card',
            $listingCardStyleUrl,
            array('sbdp-day-planner-refresh'),
            $this->determineAssetVersion($listingCardStyleUrl)
        );

        $planner_service = class_exists('\SBDP\Modules\Planner\Services\PlannerService')
            ? new \SBDP\Modules\Planner\Services\PlannerService()
            : null;

        $restNonce = null;
        $restNonceAction = \BSPModule\Core\Rest\RestService::PUBLIC_NONCE_ACTION;
        if (\function_exists('is_user_logged_in') && \is_user_logged_in()) {
            $restNonce = \wp_create_nonce('wp_rest');
            $restNonceAction = 'wp_rest';
        }

        $planContext = $this->buildPlanContext();
        $bootProducts = $this->buildBootProducts();

        // Always create a public nonce for frontend API calls
        if ($restNonce === null) {
            $restNonce = \wp_create_nonce(\BSPModule\Core\Rest\RestService::PUBLIC_NONCE_ACTION);
        }

        $desktopConfig = array(
            'restBase'     => trailingslashit(\rest_url('planner/v1')),
            'nonce'        => $restNonce,
            'nonceAction'  => $restNonceAction,
            'bookingIntent'=> \BSP\Bookings\Rest\Controller::createBookingIntent(array('source' => 'day-planner')),
            'config'       => $planner_service ? $planner_service->getPlannerConfig() : array(),
            'experiments'  => $this->getFrontendExperiments(),
            'router'       => $this->getRouterConfig(),
            'prefill'      => $this->buildPrefillPayload(),
            'products'     => $bootProducts,
            // booking_flow: 'pay' (direct payment) | 'request' (offerte — pay after availability
            // confirmation) | 'both' (show both CTAs). Set via WP option sbdp_booking_flow.
            'booking_flow' => \get_option('sbdp_booking_flow', 'pay'),
        );

        if ($planContext !== array()) {
            $desktopConfig = array_merge($desktopConfig, $planContext);
        }

        \wp_localize_script(
            'sbdp-day-planner-app',
            'SBDP_DAY_PLANNER',
            $desktopConfig
        );
        $this->enqueueSingleViewSummaryFix('sbdp-day-planner-app');

        $bootPrefillSessionSeed = <<<'JS'
(function(){
  if (typeof window === 'undefined' || typeof window.sessionStorage === 'undefined') {
    return;
  }

  try {
    var cfg = window.SBDP_DAY_PLANNER;
    var prefill = cfg && cfg.prefill && typeof cfg.prefill === 'object' ? cfg.prefill : null;
    if (!prefill) {
      return;
    }

    var planId = cfg && (cfg.planId || cfg.plan_id || (cfg.plan && cfg.plan.id));
    if (planId) {
      return;
    }

    var rawProductId = prefill.product_id != null ? prefill.product_id : prefill.productId;
    var productId = Number.parseInt(rawProductId, 10);
    if (!Number.isFinite(productId) || productId <= 0) {
      return;
    }

    var detail = Object.assign({}, prefill, {
      product_id: productId,
      productId: productId,
      append: false
    });

    if (detail.people != null && detail.participants == null) {
      detail.participants = detail.people;
    }
    if (detail.participants != null && detail.people == null) {
      detail.people = detail.participants;
    }
    if (Array.isArray(prefill.combi_items) && !Array.isArray(detail.combiItems)) {
      detail.combiItems = prefill.combi_items;
    }
    if (prefill.lock_first_slot === true && detail.lockFirstSlot == null) {
      detail.lockFirstSlot = true;
    }

    window.sessionStorage.setItem('sbdpPlannerPrefillQueue', JSON.stringify([detail]));
  } catch (error) {
    // ignore storage bootstrap errors
  }
})();
JS;
        \wp_add_inline_script('sbdp-day-planner-app', $bootPrefillSessionSeed, 'before');

        $readonlyGuard = <<<JS
(function(){try{var cfg=window.SBDP_DAY_PLANNER;if(!cfg||!cfg.readOnly)return;var b=document.body;if(!b)return;b.setAttribute('data-sbdp-readonly','1');var css='[data-sbdp-readonly="1"] .sbdp-day-planner button,[data-sbdp-readonly="1"] .sbdp-day-planner .sbdp-chip,[data-sbdp-readonly="1"] .sbdp-day-planner input,[data-sbdp-readonly="1"] .sbdp-day-planner select{pointer-events:none;opacity:0.55;}[data-sbdp-readonly="1"] .sbdp-summary-bar__actions button{pointer-events:none;opacity:0.5;}';var s=document.createElement('style');s.textContent=css;document.head.appendChild(s);}catch(e){}})();
// Extra UI guard for read-only: disable controls and mark buttons
(function(){try{var cfg=window.SBDP_DAY_PLANNER;if(!cfg||!cfg.readOnly)return;function disableAll(root){if(!root)return;root.querySelectorAll('button, a, input, select, textarea').forEach(function(el){if(el.tagName==='A'){el.setAttribute('aria-disabled','true');el.addEventListener('click',function(ev){ev.preventDefault();ev.stopPropagation();});}else{el.setAttribute('disabled','true');}el.classList.add('is-readonly');});}var container=document.querySelector('.sbdp-day-planner');disableAll(container);var summary=document.querySelector('.sbdp-summary-bar');disableAll(summary);}catch(e){}})();
JS;
        \wp_add_inline_script('sbdp-day-planner-app', $readonlyGuard, 'after');

        $mobileConfig = array(
            'restBase'     => trailingslashit(\rest_url('planner/v1')),
            'nonce'        => $restNonce,
            'nonceAction'  => $restNonceAction,
            'config'       => $planner_service ? $planner_service->getPlannerConfig() : array(),
            'experiments'  => $this->getFrontendExperiments(),
            'router'       => $this->getRouterConfig(),
            'prefill'      => $this->buildPrefillPayload(),
            'products'     => $bootProducts,
            'booking_flow' => \get_option('sbdp_booking_flow', 'pay'),
        );

        if ($planContext !== array()) {
            $mobileConfig = array_merge($mobileConfig, $planContext);
        }

        if ($enqueueMobileBundle) {
            \wp_localize_script(
                'sbdp-mobile-day-planner-app',
                'SBDP_DAY_PLANNER',
                $mobileConfig
            );
            $this->enqueueSingleViewSummaryFix('sbdp-mobile-day-planner-app');
            \wp_add_inline_script('sbdp-mobile-day-planner-app', $readonlyGuard, 'after');
        }
    }

    private function enqueueSingleViewSummaryFix(string $handle): void
    {
        $summaryFix = <<<'JS'
(function(){
  if (window.__sbdpSummaryBarFixBound) {
    return;
  }
  window.__sbdpSummaryBarFixBound = true;
  function fixSummaryBar(){
    var root = document.querySelector('.sbdp-day-planner--single-view');
    if(!root) return;
    var bars = root.querySelectorAll('.sbdp-summary-bar');
    if(!bars.length) return;
    bars.forEach(function(bar, idx){
      if(idx !== bars.length - 1){
        bar.style.display = 'none';
        return;
      }
      bar.style.display = 'flex';
      bar.style.position = 'fixed';
      bar.style.bottom = '12px';
      bar.style.right = '12px';
      bar.style.left = 'auto';
      bar.style.top = 'auto';
      bar.style.maxWidth = '320px';
      bar.style.width = '90vw';
      bar.style.zIndex = '9999';
      bar.style.margin = '0';
      bar.style.padding = '12px';
      bar.style.gap = '0.55rem';
    });
  }
  if(document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', fixSummaryBar, {once:true});
  } else {
    fixSummaryBar();
  }
  setTimeout(fixSummaryBar, 500);
  setTimeout(fixSummaryBar, 2000);
})();
JS;

        \wp_add_inline_script($handle, $summaryFix, 'after');
    }

    /**
     * Preload the planner catalog into the boot payload so the UI does not
     * transiently fall back to an empty-state when the async products fetch stalls.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildBootProducts(): array
    {
        try {
            $products = $this->service()->listProducts(array());
            return \array_values(\is_array($products) ? $products : array());
        } catch (\Throwable $exception) {
            CoreServiceProvider::logger()->warning(
                'Planner boot catalog preload failed',
                array('error' => $exception->getMessage())
            );

            return array();
        }
    }

    private function enqueueSharedUi(): void
    {
        $handle = 'sbdp-shared-ui';
        if (! wp_style_is($handle, 'registered')) {
            $sharedCss = <<<'CSS'
.sbdp-chip, .ui-chip {
    border-radius: 999px;
    border: 1px solid var(--chip-border, var(--ui-color-border));
    padding: 8px 12px;
    background: var(--chip-bg, var(--ui-color-surface-2));
    color: var(--fg, var(--ui-color-text));
    transition: all 140ms ease;
}
.sbdp-chip:hover, .ui-chip:hover {
    border-color: var(--chip-hover-border, var(--ui-color-primary));
    background: var(--chip-hover, var(--ui-color-surface));
}
.sbdp-chip.is-active, .ui-chip.is-active {
    border-color: var(--chip-active-border, var(--ui-color-primary));
    background: var(--chip-active-bg, var(--ui-color-primary));
    color: var(--chip-active-fg, var(--ui-color-primary-contrast));
}
.sbdp-count-btn, .ui-planner-widget__count-btn {
    border-radius: 999px;
    border: 1px solid var(--ui-color-primary);
    width: 38px;
    height: 38px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    line-height: 1;
    background: var(--ui-color-primary);
    color: var(--ui-color-primary-contrast);
    transition: all 140ms ease;
    box-shadow: 0 8px 18px color-mix(in srgb, var(--ui-color-primary) 18%, transparent);
}
.sbdp-count-btn:hover, .ui-planner-widget__count-btn:hover {
    background: var(--ui-color-primary-hover);
    color: var(--ui-color-primary-contrast);
    border-color: var(--ui-color-primary-hover);
}
.sbdp-button--primary, .ui-planner-widget__btn {
    border-radius: 12px;
    border: 1px solid var(--chip-hover-border, var(--ui-color-primary));
    background: var(--chip-active-bg, var(--ui-color-primary));
    color: var(--chip-active-fg, var(--ui-color-primary-contrast));
    transition: all 140ms ease;
    box-shadow: 0 10px 26px color-mix(in srgb, var(--ui-color-primary) 32%, transparent);
}
.sbdp-button--primary:hover, .ui-planner-widget__btn:hover {
    background: var(--chip-hover-border, var(--ui-color-primary-hover));
    border-color: var(--chip-hover-border, var(--ui-color-primary-hover));
}
CSS;
            wp_register_style($handle, false, array(), SBDP_VER);
            wp_add_inline_style($handle, $sharedCss);
        }

        wp_enqueue_style($handle);
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

        $containsShortcode = \has_shortcode($post->post_content, 'sbdp_dayplanner')
            || \has_shortcode($post->post_content, 'sbdp_day_planner');

        /**
         * Filter whether the day planner assets should be enqueued on the current frontend request.
         *
         * @param bool   $shouldEnqueue Default decision derived from shortcode detection.
         * @param \WP_Post $post        Current post object.
         */
        return (bool) \apply_filters('sbdp_day_planner_enqueue_assets', $containsShortcode, $post);
    }

    /**
     * @return array{script:array{src:string,version:string},styles:array<string,array{src:string,version:string}>}
     */
    private function resolveAsset(string $entry): array
    {
        $manifestPath = SBDP_DIR . 'build/.vite/manifest.json';
        $fallback     = $this->resolveFallbackAsset($entry);

        if (! is_readable($manifestPath)) {
            return $fallback;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($manifest) || ! isset($manifest[$entry])) {
            return $fallback;
        }

        $entryData = $manifest[$entry];
        $styles    = array();
        $scriptUrl = isset($entryData['file'])
            ? SBDP_URL . 'build/' . ltrim((string) $entryData['file'], '/')
            : $fallback['script']['src'];

        if (! empty($entryData['css']) && is_array($entryData['css'])) {
            foreach ($entryData['css'] as $index => $cssFile) {
                $handle = $this->buildStyleHandle($entry, (int) $index);
                $styleUrl = SBDP_URL . 'build/' . ltrim((string) $cssFile, '/');
                $styles[ $handle ] = array(
                    'src'     => $styleUrl,
                    'version' => $this->determineAssetVersion($styleUrl),
                );
            }
        }

        return array(
            'script' => array(
                'src'     => $scriptUrl,
                'version' => $this->determineAssetVersion($scriptUrl),
            ),
            'styles' => $styles ?: $fallback['styles'],
        );
    }

    /**
     * Provide a production-safe fallback when the Vite manifest is missing or unreadable.
     *
     * @return array{script:array{src:string,version:string},styles:array<string,array{src:string,version:string}>}
     */
    private function resolveFallbackAsset(string $entry): array
    {
        $fallbackMap = array(
            'assets/js/day-planner/index.jsx'         => 'assets/js/day-planner/dist/dayPlanner.js',
            'assets/js/mobile-dayplanner/index.jsx'   => 'assets/js/mobile-dayplanner/dist/mobileDayPlanner.js',
            'assets/js/admin/booking-board/index.jsx' => 'assets/js/admin/booking-board/dist/bookingBoard.js',
        );

        if (isset($fallbackMap[$entry])) {
            $path = $fallbackMap[$entry];
            if (is_readable(SBDP_DIR . $path)) {
                $url = SBDP_URL . $path;

                return array(
                    'script' => array(
                        'src'     => $url,
                        'version' => $this->determineAssetVersion($url),
                    ),
                    'styles' => array(),
                );
            }
        }

        $url = SBDP_URL . $entry;

        return array(
            'script' => array(
                'src'     => $url,
                'version' => $this->determineAssetVersion($url),
            ),
            'styles' => array(),
        );
    }

    private function determineAssetVersion(string $url): string
    {
        $baseUrl = rtrim(SBDP_URL, '/') . '/';
        $baseDir = rtrim(SBDP_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path) && strpos($url, $baseUrl) === 0) {
            $basePath = parse_url($baseUrl, PHP_URL_PATH);
            if (is_string($basePath) && strpos($path, $basePath) === 0) {
                $relative = ltrim(substr($path, strlen($basePath)), '/');
                $file     = $baseDir . str_replace('/', DIRECTORY_SEPARATOR, $relative);

                if (is_readable($file)) {
                    return (string) filemtime($file);
                }
            }
        }

        return defined('SBDP_VER') ? SBDP_VER : (string) time();
    }

    private function buildStyleHandle(string $entry, int $index): string
    {
        $normalized = \strtolower($entry);
        $normalized = (string) \preg_replace('/[^a-z0-9]+/', '-', $normalized);
        $normalized = \trim($normalized, '-');

        if ($normalized === '') {
            $normalized = 'asset';
        }

        $hash = \substr(\md5($entry), 0, 8);

        return \sprintf('sbdp-%s-%s-style-%d', $normalized, $hash, $index);
    }

    /**
     * Ensure the day planner bundle is executed as an ES module.
     */
    public function markScriptAsModule(string $tag, string $handle, string $src): string
    {
        $moduleHandles = array(
            'sbdp-day-planner-app',
            'sbdp-mobile-day-planner-app',
        );

        if (! in_array($handle, $moduleHandles, true)) {
            return $tag;
        }

        // Add type="module" for ES module bundles
        if (strpos($tag, 'type=') === false) {
            return str_replace('<script ', '<script type="module" ', $tag);
        }

        return (string) preg_replace('/type=(["\']).*?\\1/', 'type="module"', $tag, 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function getRouterConfig(): array
    {
        return array(
            'route'         => '/planner/start',
            'accepts'       => array('product_id', 'date', 'time', 'people', 'resource_id'),
            'redirects_to'  => '/planner/edit/:plan_id',
        );
    }

    /**
     * @return array<string, string>
     */
    private function getFrontendExperiments(): array
    {
        if (! \function_exists('apply_filters')) {
            return array();
        }

        /**
         * Configure front-end planner experiments.
         *
         * Example:
         * add_filter('sbdp_day_planner_experiments', function(array $experiments): array {
         *     $experiments['top_recommendations_v1'] = 'badge';
         *     return $experiments;
         * });
         *
         * @param array<string, mixed> $experiments
         */
        $rawExperiments = \apply_filters('sbdp_day_planner_experiments', array());
        if (! \is_array($rawExperiments)) {
            return array();
        }

        $experiments = array();

        foreach ($rawExperiments as $key => $value) {
            if (! \is_scalar($key) || ! \is_scalar($value)) {
                continue;
            }

            $experimentKey = \strtolower(\trim((string) $key));
            $experimentValue = \strtolower(\trim((string) $value));

            if ($experimentKey === '' || $experimentValue === '') {
                continue;
            }

            $experimentKey = (string) \preg_replace('/[^a-z0-9_\\-]/', '', $experimentKey);
            $experimentValue = (string) \preg_replace('/[^a-z0-9_\\-]/', '', $experimentValue);

            if ($experimentKey === '' || $experimentValue === '') {
                continue;
            }

            $experiments[$experimentKey] = $experimentValue;
        }

        return $experiments;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPrefillPayload(): array
    {
        if (empty($_GET)) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return array();
        }

        $querySource = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $decodedSource = $this->decodePrefillSource($querySource['sbdp_prefill'] ?? null);
        $source        = $decodedSource === array()
            ? $querySource
            : array_merge($querySource, $decodedSource);

        $prefill = array();

        $productId = isset($source['product_id'])
            ? (int) $source['product_id']
            : (isset($source['productId']) ? (int) $source['productId'] : (isset($source['sbdp_product']) ? (int) $source['sbdp_product'] : 0));
        if ($productId > 0) {
            $prefill['product_id'] = $productId;
        }

        $date = $this->sanitizeDate(
            $source['sbdp_date'] ?? ($source['visitDate'] ?? ($source['date'] ?? null))
        );
        if ($date !== null) {
            $prefill['date'] = $date;
        }

        $time = $this->sanitizeTime($source['sbdp_time'] ?? ($source['start'] ?? null));
        if ($time === null) {
            $time = $this->sanitizeTime(
                $source['time'] ?? ($source['start_time'] ?? ($source['slot']['time'] ?? null))
            );
        }
        if ($time !== null) {
            $prefill['time'] = $time;
        }

        $peopleRaw = $source['sbdp_participants'] ?? ($source['count'] ?? ($source['people'] ?? ($source['participants'] ?? null)));
        $people    = is_numeric($peopleRaw) ? (int) $peopleRaw : 0;
        // Validate participant count: 1-100
        if ($people > 0 && $people <= 100) {
            $prefill['people'] = $people;
        }

        $resource = isset($source['resource_id'])
            ? (int) $source['resource_id']
            : (isset($source['resourceId']) ? (int) $source['resourceId'] : (isset($source['sbdp_resource']) ? (int) $source['sbdp_resource'] : 0));
        if ($resource > 0) {
            $prefill['resource_id'] = $resource;
        }

        $duration = isset($source['durationMinutes'])
            ? (int) $source['durationMinutes']
            : (isset($source['duration_minutes']) ? (int) $source['duration_minutes'] : (isset($source['duration']) ? (int) $source['duration'] : 0));
        if ($duration > 0) {
            $prefill['durationMinutes'] = $duration;
        }

        $combiItems = $this->extractPrefillCombiItems($source, $querySource);
        if ($combiItems !== array()) {
            $prefill['combi_items'] = $combiItems;
            $prefill['options'] = isset($prefill['options']) && is_array($prefill['options']) ? $prefill['options'] : array();
            $prefill['options']['combiItems'] = $combiItems;
            $prefill['combi_ids'] = array_values(array_map(
                static function (array $item): int {
                    return (int) $item['id'];
                },
                $combiItems
            ));
            $prefill['combi_timing_map'] = array_reduce(
                $combiItems,
                static function (array $carry, array $item): array {
                    $carry[(string) $item['id']] = (string) $item['timing'];

                    return $carry;
                },
                array()
            );

            if (count($combiItems) === 1) {
                $prefill['combi'] = (string) $combiItems[0]['id'];
                if (! empty($combiItems[0]['label'])) {
                    $prefill['combi_label'] = (string) $combiItems[0]['label'];
                }
            }
        }

        // Optional experience hints from the home widget - with strict validation.
        $validDurations = array('ochtend', 'middag', 'avond', 'hele-dag', 'weekend');
        $durationAliases = array(
            '3-4u'      => 'ochtend',
            '34u'       => 'ochtend',
            '3-4'       => 'ochtend',
            '4u'        => 'ochtend',
            '4h'        => 'ochtend',
            '4 uur'     => 'ochtend',
            '5-6u'      => 'middag',
            '56u'       => 'middag',
            '5-6'       => 'middag',
            '6u'        => 'middag',
            '6h'        => 'middag',
            '6 uur'     => 'middag',
            'hele dag'  => 'hele-dag',
            'hele-dag'  => 'hele-dag',
            'avond'     => 'avond',
            'weekend'   => 'weekend',
            'vrij'      => null,
        );
        if (isset($source['duration'])) {
            $durationRaw = strtolower($this->sanitizeText($source['duration']));
            $mapped      = $durationAliases[$durationRaw] ?? $durationRaw;
            if ($mapped !== null && in_array($mapped, $validDurations, true)) {
                $prefill['duration'] = $mapped;
            }
        }

        $validAudiences = array('partner', 'gezin', 'familie', 'vrienden', 'collegas', 'solo', 'gemengd', 'romantisch');
        if (isset($source['audience'])) {
            $audienceRaw = strtolower($this->sanitizeText($source['audience']));
            $audience    = $audienceRaw === 'familie' ? 'gezin' : $audienceRaw;
            $audience    = $audience === 'gemengd' ? 'vrienden' : $audience;
            $audience    = $audience === 'romantisch' ? 'partner' : $audience;
            $audience    = $audience === 'bedrijf' ? 'collegas' : $audience;
            $audience    = $audience === 'school' ? 'collegas' : $audience;
            if (in_array($audience, $validAudiences, true)) {
                $prefill['audience'] = $audience;
            }
        }

        $validVibes = array('cultuur', 'shoppen', 'kidsproof', 'bourgondisch', 'verrassend', 'actief', 'klassiek', 'relaxed', 'winkelen', 'buitenlucht', 'verrassing', 'food');
        if (isset($source['vibe'])) {
            $rawVibe = strtolower($this->sanitizeText($source['vibe']));
            $tokens  = preg_split('/[\s,]+/', $rawVibe) ?: array();
            $filtered = array();
            foreach ($tokens as $token) {
                if ($token === '') {
                    continue;
                }
                $normalised = $token;
                if ($token === 'winkelen') {
                    $normalised = 'shoppen';
                } elseif ($token === 'buitenlucht') {
                    $normalised = 'actief';
                } elseif ($token === 'verrassing') {
                    $normalised = 'verrassend';
                } elseif ($token === 'food') {
                    $normalised = 'bourgondisch';
                }
                if (in_array($normalised, $validVibes, true) && ! in_array($normalised, $filtered, true)) {
                    $filtered[] = $normalised;
                }
            }
            if ($filtered !== array()) {
                $prefill['vibe'] = implode(' ', $filtered);
            }
        }

        if ($prefill === array()) {
            return array();
        }

        $prefill['lock_first_slot'] = isset($source['lock_first_slot']) || isset($source['lockFirstSlot'])
            ? filter_var($source['lock_first_slot'] ?? $source['lockFirstSlot'], FILTER_VALIDATE_BOOLEAN)
            : false;
        $prefill['source']          = isset($source['source']) && is_string($source['source']) && trim($source['source']) !== ''
            ? $this->sanitizeText($source['source'])
            : ($productId > 0 ? 'product' : 'home_widget');

        return $prefill;
    }

    private function buildPlanContext(): array
    {
        $planId = $this->resolvePlanId();
        if ($planId === null) {
            return array();
        }

        $context = array(
            'planId' => $planId,
        );

        $queryArgs = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $providedToken = $this->sanitizeToken($queryArgs['edit_token'] ?? ($queryArgs['token'] ?? null));
        if ($providedToken !== null) {
            $context['planToken'] = $providedToken;
        }

        $shareKey = $this->sanitizeToken($queryArgs['key'] ?? ($queryArgs['shared_key'] ?? null));
        if ($shareKey !== null) {
            $context['planShareKey'] = $shareKey;
        }

        try {
            $plan = $this->service()->getPlan($planId);
        } catch (\Throwable $exception) {
            unset($exception);

            return $context;
        }

        $planToken = $this->extractPlanEditToken($plan);
        $canEdit   = $this->canEditPlan($plan, $providedToken);
        $shareMatches = $this->shareKeyMatches($plan, $shareKey);

        if ($canEdit && $planToken !== null) {
            $context['planToken'] = $planToken;
        }

        if ($canEdit) {
            $context['plan'] = $plan;
        } elseif ($shareMatches) {
            $context['plan']     = $this->sanitizePlanForShare($plan);
            $context['readOnly'] = true;
        }

        return $context;
    }

    /**
     * Strip edit tokens and sensitive meta for shared/view-only plans.
     *
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private function sanitizePlanForShare(array $plan): array
    {
        unset($plan['edit_token'], $plan['editToken'], $plan['share_key'], $plan['shared_key'], $plan['token'], $plan['owner']);

        if (isset($plan['meta']) && is_array($plan['meta'])) {
            unset($plan['meta']['edit_token'], $plan['meta']['editToken'], $plan['meta']['share_key'], $plan['meta']['shared_key'], $plan['meta']['token']);
        }

        return $plan;
    }

    private function resolvePlanId(): ?int
    {
        $planId = null;

        if (\function_exists('get_query_var')) {
            $queryVar = \get_query_var('sbdp_plan_id', null);
            if ($queryVar !== null && $queryVar !== '') {
                $planId = (int) $queryVar;
            }
        }

        if ($planId === null || $planId <= 0) {
            $source = $_GET['sbdp_plan_id'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ($source !== null) {
                $planId = (int) $source;
            }
        }

        if ($planId === null || $planId <= 0) {
            $plannerParam = $_GET['planner_plan'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ($plannerParam !== null) {
                $planId = (int) $plannerParam;
            }
        }

        return $planId !== null && $planId > 0 ? $planId : null;
    }

    private function sanitizeToken($value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return function_exists('sanitize_text_field')
            ? sanitize_text_field($value)
            : $value;
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function canEditPlan(array $plan, ?string $providedToken): bool
    {
        $planToken = $this->extractPlanEditToken($plan);

        if ($planToken !== null && $providedToken !== null && hash_equals($planToken, $providedToken)) {
            return true;
        }

        if (! function_exists('is_user_logged_in') || ! \is_user_logged_in()) {
            return false;
        }

        $currentUser = (int) \get_current_user_id();
        $ownerId     = isset($plan['owner']) ? (int) $plan['owner'] : 0;

        if ($ownerId > 0 && $ownerId === $currentUser) {
            return true;
        }

        return \current_user_can('edit_others_posts') || \current_user_can('planner_manage');
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function shareKeyMatches(array $plan, ?string $shareKey): bool
    {
        if ($shareKey === null) {
            return false;
        }

        $planKey = '';

        if (isset($plan['shared_key']) && is_string($plan['shared_key'])) {
            $planKey = trim($plan['shared_key']);
        } elseif (isset($plan['meta']) && is_array($plan['meta']) && isset($plan['meta']['shared_key'])) {
            $metaKey = $plan['meta']['shared_key'];
            if (is_string($metaKey)) {
                $planKey = trim($metaKey);
            }
        }

        return $planKey !== '' && hash_equals($planKey, $shareKey);
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function extractPlanEditToken(array $plan): ?string
    {
        if (isset($plan['meta']) && is_array($plan['meta']) && isset($plan['meta']['edit_token'])) {
            $token = $plan['meta']['edit_token'];
            if (is_string($token)) {
                $token = trim($token);
                if ($token !== '') {
                    return $token;
                }
            }
        }

        if (isset($plan['edit_token']) && is_string($plan['edit_token'])) {
            $token = trim($plan['edit_token']);
            if ($token !== '') {
                return $token;
            }
        }

        return null;
    }

    /**
     * Ensure planner routes (e.g. /plan-je-dag/planner/start) resolve to the planner page.
     */
    public function registerRouterRewrite(): void
    {
        if (! \function_exists('add_rewrite_rule')) {
            return;
        }

        $pageId = $this->resolvePlannerPageId();
        if ($pageId <= 0) {
            return;
        }

        $permalink = \get_permalink($pageId);
        if (! $permalink) {
            return;
        }

        $path = \parse_url($permalink, \PHP_URL_PATH);
        if (! \is_string($path) || $path === '') {
            return;
        }

        $path = \trim($path, '/');
        if ($path === '') {
            return;
        }

        \add_rewrite_rule($path . '/planner/start/?$', 'index.php?page_id=' . $pageId, 'top');
        \add_rewrite_rule($path . '/planner/edit/([^/]+)/?$', 'index.php?page_id=' . $pageId . '&sbdp_plan_id=$matches[1]', 'top');

        $storedVersion = (int) \get_option('sbdp_planner_rewrite_version', 0);
        if ($storedVersion < self::ROUTER_REWRITE_VERSION) {
            \update_option('sbdp_planner_rewrite_version', self::ROUTER_REWRITE_VERSION);
            if (\function_exists('flush_rewrite_rules')) {
                \flush_rewrite_rules(false);
            }
        }
    }

    /**
     * @param array<int, string> $vars
     *
     * @return array<int, string>
     */
    public function registerQueryVars(array $vars): array
    {
        if (! \in_array('sbdp_plan_id', $vars, true)) {
            $vars[] = 'sbdp_plan_id';
        }

        return $vars;
    }

    /**
     * Force planner subroutes to resolve to the canonical planner page, even when rewrite rules are stale.
     *
     * @param array<string, mixed> $vars
     *
     * @return array<string, mixed>
     */
    public function forcePlannerRouteRequest(array $vars): array
    {
        if (\is_admin()) {
            return $vars;
        }

        $route = $this->matchPlannerRouteFromRequest();
        if ($route === null) {
            return $vars;
        }

        $pageId = $this->resolvePlannerPageId();
        if ($pageId <= 0) {
            return $vars;
        }

        $vars['page_id'] = $pageId;

        if ($route['plan_id'] !== null) {
            $vars['sbdp_plan_id'] = $route['plan_id'];
        }

        unset($vars['error'], $vars['name'], $vars['pagename'], $vars['attachment'], $vars['attachment_id']);

        return $vars;
    }

    private function resolvePlannerPageId(): int
    {
        $pageId = (int) \get_option('sbdp_planner_page_id', 0);
        if ($pageId > 0 && \get_post_status($pageId)) {
            return $pageId;
        }

        $page = \get_page_by_path('plan-je-dag');
        if ($page instanceof \WP_Post) {
            return (int) $page->ID;
        }

        return 0;
    }

    /**
     * @return array{type:string, plan_id:?string}|null
     */
    private function matchPlannerRouteFromRequest(): ?array
    {
        $pageId = $this->resolvePlannerPageId();
        if ($pageId <= 0) {
            return null;
        }

        $permalink = \get_permalink($pageId);
        if (! \is_string($permalink) || $permalink === '') {
            return null;
        }

        $plannerPath = \parse_url($permalink, \PHP_URL_PATH);
        if (! \is_string($plannerPath) || $plannerPath === '') {
            return null;
        }

        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) \wp_unslash($_SERVER['REQUEST_URI']) : '';
        $requestPath = (string) \parse_url($requestUri, \PHP_URL_PATH);
        if ($requestPath === '') {
            return null;
        }

        $plannerPath = '/' . \trim($plannerPath, '/');
        $requestPath = '/' . \trim($requestPath, '/');

        if ($requestPath === $plannerPath . '/planner/start') {
            return [
                'type'    => 'start',
                'plan_id' => null,
            ];
        }

        $editPrefix = $plannerPath . '/planner/edit/';
        if (\str_starts_with($requestPath, $editPrefix)) {
            $planId = \trim((string) \substr($requestPath, \strlen($editPrefix)), '/');
            if ($planId === '') {
                return null;
            }

            return [
                'type'    => 'edit',
                'plan_id' => $planId,
            ];
        }

        return null;
    }

    private function sanitizeDate($value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = \date_create_immutable($value);
        if ($date === false) {
            return null;
        }

        // Validate date is not in the past (allow today)
        $today = new \DateTime('today');
        $inputDate = new \DateTime($value);
        
        if ($inputDate < $today) {
            return null;
        }

        // Validate date is not more than 1 year in the future
        $maxDate = (new \DateTime('today'))->modify('+365 days');
        if ($inputDate > $maxDate) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    private function sanitizeTime($value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (! preg_match('/^(2[0-3]|[01]\d):[0-5]\d$/', $value)) {
            return null;
        }

        return $value;
    }

    private function sanitizeText($value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return function_exists('sanitize_text_field')
            ? sanitize_text_field($value)
            : trim($value);
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function decodePrefillSource($value): array
    {
        if (! is_string($value)) {
            return array();
        }

        $decoded = json_decode(wp_unslash($value), true);

        return is_array($decoded) ? $decoded : array();
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $querySource
     * @return array<int, array<string, mixed>>
     */
    private function extractPrefillCombiItems(array $source, array $querySource): array
    {
        $candidates = array(
            $source['combi_items'] ?? null,
            $source['combiItems'] ?? null,
            isset($source['options']) && is_array($source['options']) ? ($source['options']['combiItems'] ?? null) : null,
        );

        foreach ($candidates as $candidate) {
            $items = $this->sanitizePrefillCombiItems($candidate);
            if ($items !== array()) {
                return $items;
            }
        }

        $legacyIds = isset($querySource['sbdp_combi_ids']) && is_array($querySource['sbdp_combi_ids'])
            ? $querySource['sbdp_combi_ids']
            : array();
        $legacyTiming = isset($querySource['sbdp_combi_timing']) && is_array($querySource['sbdp_combi_timing'])
            ? $querySource['sbdp_combi_timing']
            : array();

        if ($legacyIds !== array()) {
            $legacyItems = array();
            foreach ($legacyIds as $index => $rawId) {
                $combiId = (int) $rawId;
                if ($combiId <= 0) {
                    continue;
                }

                $product = function_exists('wc_get_product') ? wc_get_product($combiId) : null;
                $timing  = $this->normalisePrefillTiming($legacyTiming[$combiId] ?? ($legacyTiming[(string) $combiId] ?? 'before'));
                $label   = $product instanceof \WC_Product ? (string) $product->get_name() : '';

                $legacyItems[] = array(
                    'id'              => $combiId,
                    'label'           => $label,
                    'timing'          => $timing,
                    'role'            => $timing === 'after' ? 'post' : 'pre',
                    'order'           => $index,
                    'duration'        => 0,
                    'durationMinutes' => 0,
                );
            }

            if ($legacyItems !== array()) {
                return $legacyItems;
            }
        }

        $singleCombi = isset($source['combi']) ? $source['combi'] : ($source['sbdp_combi'] ?? null);
        $singleId    = (int) $singleCombi;
        if ($singleId <= 0) {
            return array();
        }

        return array(
            array(
                'id'              => $singleId,
                'label'           => $this->sanitizeText($source['combi_label'] ?? ($source['sbdp_combi_label'] ?? '')),
                'timing'          => $this->normalisePrefillTiming($source['combi_timing'] ?? 'before'),
                'role'            => $this->normalisePrefillTiming($source['combi_timing'] ?? 'before') === 'after' ? 'post' : 'pre',
                'order'           => 0,
                'duration'        => 0,
                'durationMinutes' => 0,
            ),
        );
    }

    /**
     * @param mixed $items
     * @return array<int, array<string, mixed>>
     */
    private function sanitizePrefillCombiItems($items): array
    {
        if (! is_array($items)) {
            return array();
        }

        $normalised = array();
        $index      = 0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = isset($item['id'])
                ? (int) $item['id']
                : (isset($item['product_id']) ? (int) $item['product_id'] : (isset($item['productId']) ? (int) $item['productId'] : 0));
            if ($id <= 0) {
                continue;
            }

            $timing   = $this->normalisePrefillTiming($item['timing'] ?? ($item['role'] ?? 'before'));
            $duration = isset($item['durationMinutes'])
                ? max(0, (int) $item['durationMinutes'])
                : (isset($item['duration']) ? max(0, (int) $item['duration']) : 0);

            $normalised[] = array(
                'id'              => $id,
                'label'           => $this->sanitizeText($item['label'] ?? ''),
                'timing'          => $timing,
                'role'            => $timing === 'after' ? 'post' : 'pre',
                'order'           => isset($item['order']) ? max(0, (int) $item['order']) : $index,
                'duration'        => $duration,
                'durationMinutes' => $duration,
            );

            $index++;
        }

        usort(
            $normalised,
            static function (array $left, array $right): int {
                return ((int) ($left['order'] ?? 0)) <=> ((int) ($right['order'] ?? 0));
            }
        );

        return array_values($normalised);
    }

    /**
     * @param mixed $value
     */
    private function normalisePrefillTiming($value): string
    {
        if (! is_string($value)) {
            return 'before';
        }

        $value = strtolower(trim($value));

        return in_array($value, array('after', 'post'), true) ? 'after' : 'before';
    }

    public static function enqueuePricingHelpers(): void
    {
        if (! \function_exists('wp_enqueue_script')) {
            return;
        }

        $handle = 'sbdp-day-planner-helpers';
        if (! wp_script_is($handle, 'registered')) {
            // Register an empty inline script - the actual helpers are bundled in dayPlanner.js
            // We need to register this handle because sbdp-day-planner-app depends on it
            wp_register_script(
                $handle,
                '', // Empty src
                array(),
                SBDP_VER,
                true
            );
            // Add inline script with empty helpers object to satisfy any code expecting it
            wp_add_inline_script($handle, 'window.SBDP_DAY_PLANNER_HELPERS = window.SBDP_DAY_PLANNER_HELPERS || {};', 'before');
        }

        wp_enqueue_script($handle);
    }

    public function maybeRenderEmptyNotice(): void
    {
        if (! \current_user_can('manage_options')) {
            return;
        }

        if (\get_transient(self::EMPTY_NOTICE_TRANSIENT) !== '1') {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p>%s</p></div>',
            \esc_html__(
                'De planning vond geen boekbare activiteiten. Controleer een productconfiguratie of voer de demo-seed uit met: wp eval "do_action(\'sbdp_seed_demo_data\');".',
                'sbdp'
            )
        );
    }
}

if (! \class_exists('BSPModule\\DayPlanner\\Module', false)) {
    \class_alias(Module::class, 'BSPModule\\DayPlanner\\Module');
}

// phpcs:enable PSR1.Files.SideEffects.FoundWithSymbols

