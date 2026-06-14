<?php

declare(strict_types=1);

namespace SBDP\Modules\ProductOverview;

use BSP\DayPlanner\Service\ActivityService;
use SBDP\BookingEngine;
use WP_Post;
use WP_Query;
use WP_Term;

final class ProductOverviewComponent
{
    private const SHORTCODE = 'bmp_product_overview';
    private const AJAX_ACTION = 'bmp_fetch_products';
    private const DEFAULT_VIEW = 'grid';
    private const MODULE_SLUG = 'product-overview';

    private ProductOverviewRepository $repository;
    private bool $bootstrapped = false;
    /** @var array<int, string> */
    private array $activityStyleHandles = array();

    public function __construct(?ProductOverviewRepository $repository = null)
    {
        $this->repository = $repository ?? new ProductOverviewRepository();
    }

    public function bootstrap(): void
    {
        if ($this->bootstrapped) {
            return;
        }

        $this->bootstrapped = true;

        if (function_exists('add_action')) {
            add_action('init', [$this, 'registerShortcode']);
            add_action('wp_enqueue_scripts', [$this, 'registerAssets']);
            add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'handleAjax']);
            add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, [$this, 'handleAjax']);
        }

        if (function_exists('add_filter')) {
            add_filter('script_loader_tag', [$this, 'markScriptAsModule'], 10, 3);
            add_filter('template_include', [$this, 'maybeUseShopTemplate'], 99);
        }
    }

    public function registerShortcode(): void
    {
        if (! function_exists('add_shortcode')) {
            return;
        }

        add_shortcode(self::SHORTCODE, [$this, 'renderShortcode']);
    }

    public function registerAssets(): void
    {
        if (! function_exists('wp_register_style')) {
            return;
        }

        $version = defined('SBDP_VER') ? SBDP_VER : '1.0.0';

        $hasLeafletAssets = $this->registerLeafletAssets();

        wp_register_style(
            'sbdp-product-overview',
            $this->assetUrl('assets/css/product-overview.css'),
            array(),
            $version
        );

        wp_register_style(
            'sbdp-product-map',
            $this->assetUrl('assets/css/product-map.css'),
            $hasLeafletAssets ? array('sbdp-product-overview', 'leaflet') : array('sbdp-product-overview'),
            $version
        );

        if (function_exists('wp_register_script')) {
            wp_register_script(
                'sbdp-product-overview',
                $this->assetUrl('assets/js/product-overview.js'),
                array(),
                $version,
                true
            );

            $asset = $this->resolveAsset('modules/product-overview/assets/js/activity-overview/overzicht-activiteiten.tsx');

            wp_register_script(
                'sbdp-activity-overview',
                $asset['script']['src'],
                array('wp-element'),
                $asset['script']['version'],
                true
            );

            $this->activityStyleHandles = array();

            foreach ($asset['styles'] as $handle => $style) {
                wp_register_style($handle, $style['src'], array(), $style['version']);
                $this->activityStyleHandles[] = $handle;
            }

            if ($hasLeafletAssets) {
                wp_register_script(
                    'sbdp-product-map',
                    $this->assetUrl('assets/js/product-map.js'),
                    array('sbdp-product-overview', 'leaflet'),
                    $version,
                    true
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $atts
     */
    public function renderShortcode($atts = array(), string $content = '', string $tag = ''): string
    {
        unset($content, $tag);

        $attributes = $this->normaliseShortcodeAttributes(is_array($atts) ? $atts : array());
        $filters    = $this->repository->normaliseFilters($attributes['filters']);
        $initial = $this->repository->getProducts($filters);
        $meta    = isset($initial['meta']) && is_array($initial['meta']) ? $initial['meta'] : array();
        $hasMapData = (bool) ($meta['hasCoordinates'] ?? false);

        $config = array(
            'componentId' => $attributes['id'],
            'defaultView' => $attributes['view'],
            'filters'     => $filters,
            'products'    => $initial['products'],
            'pagination'  => $initial['pagination'],
            'mapEnabled'  => $attributes['mapEnabled'] && $hasMapData,
            'meta'        => $meta,
            'experience'  => 'activity-overview',
            'map'         => array(
                'enabled'       => $attributes['mapEnabled'],
                'hasCoordinates' => $hasMapData,
            ),
            'ajax'        => array(
                'url'    => function_exists('admin_url') ? admin_url('admin-ajax.php') : '/wp-admin/admin-ajax.php',
                'action' => self::AJAX_ACTION,
                'nonce'  => function_exists('wp_create_nonce') ? wp_create_nonce(self::AJAX_ACTION) : '',
            ),
            'discovery'   => array(
                'restBase' => function_exists('home_url') ? home_url('/wp-json/planner/v1') : '/wp-json/planner/v1',
                'nonce'    => function_exists('wp_create_nonce') ? wp_create_nonce('wp_rest') : '',
            ),
            'strings'     => $this->frontendStrings(),
        );

        $mapReady = $attributes['mapEnabled'] && $hasMapData;

        $context = array(
            'component'        => $config,
            'types'            => $this->getBookingTypes(),
            'showFilters'      => $attributes['showFilters'],
            'displayMapToggle' => $mapReady,
            'mapConfigured'    => $attributes['mapEnabled'],
            'mapHasData'       => $hasMapData,
        );

        $this->ensureAssets($attributes['mapEnabled'], $attributes['view']);

        $template = $attributes['view'] === 'map'
            ? 'map-view.php'
            : 'overview-grid.php';

        return $this->renderTemplate($template, $context);
    }

    public function handleAjax(): void
    {
        if (! function_exists('check_ajax_referer')) {
            wp_send_json_error(array('message' => __('Beveiligingscontrole is niet beschikbaar.', 'sbdp')), 400);
        }

        check_ajax_referer(self::AJAX_ACTION, 'nonce');

        $filters = $this->repository->normaliseFilters(
            array(
                'type'      => isset($_REQUEST['type']) ? wp_unslash((string) $_REQUEST['type']) : '',
                'date'      => isset($_REQUEST['date']) ? wp_unslash((string) $_REQUEST['date']) : '',
                'min_price' => isset($_REQUEST['min_price']) ? wp_unslash((string) $_REQUEST['min_price']) : null,
                'max_price' => isset($_REQUEST['max_price']) ? wp_unslash((string) $_REQUEST['max_price']) : null,
                'per_page'  => isset($_REQUEST['per_page']) ? (int) $_REQUEST['per_page'] : null,
                'page'      => isset($_REQUEST['page']) ? (int) $_REQUEST['page'] : 1,
            )
        );

        $component      = $this;
        $filtersForAjax = $filters;

        $payload = include __DIR__ . '/ajax/fetch-products.php';

        if (! is_array($payload)) {
            $payload = $this->repository->emptyPayload($filters);
        }

        wp_send_json_success($payload);
    }

    /**
     * @return array<int, WP_Term>
     */
    private function getBookingTypes(): array
    {
        if (! function_exists('get_terms')) {
            return array();
        }

        $terms = get_terms(
            array(
                'taxonomy'   => ProductOverviewRepository::TAXONOMY,
                'hide_empty' => false,
            )
        );

        if (is_wp_error($terms)) {
            return array();
        }

        return array_filter(
            array_map(
                static function ($term): ?WP_Term {
                    return $term instanceof WP_Term ? $term : null;
                },
                $terms
            )
        );
    }

    /**
     * @param array<string, mixed> $atts
     *
     * @return array{
     *     id:string,
     *     view:string,
     *     mapEnabled:bool,
     *     showFilters:bool,
     *     filters:array<string, mixed>
     * }
     */
    private function normaliseShortcodeAttributes(array $atts): array
    {
        $view = isset($atts['view']) ? strtolower((string) $atts['view']) : self::DEFAULT_VIEW;
        if (! in_array($view, array('grid', 'map'), true)) {
            $view = self::DEFAULT_VIEW;
        }

        $mapEnabled = $this->truthy($atts['map'] ?? null, true);

        return array(
            'id'          => $this->buildComponentId(),
            'view'        => $view,
            'mapEnabled'  => $mapEnabled,
            'showFilters' => $this->truthy($atts['show_filters'] ?? null, true),
            'filters'     => array(
                'type'      => isset($atts['type']) ? (string) $atts['type'] : '',
                'date'      => isset($atts['date']) ? (string) $atts['date'] : '',
                'min_price' => isset($atts['min_price']) ? (string) $atts['min_price'] : null,
                'max_price' => isset($atts['max_price']) ? (string) $atts['max_price'] : null,
                'per_page'  => isset($atts['per_page']) ? (int) $atts['per_page'] : null,
                'page'      => 1,
            ),
        );
    }

    private function ensureAssets(bool $mapEnabled, string $view): void
    {
        if (function_exists('wp_enqueue_style')) {
            wp_enqueue_style('sbdp-product-overview');
            if ($view !== 'map') {
                foreach ($this->activityStyleHandles as $handle) {
                    wp_enqueue_style($handle);
                }
                wp_enqueue_style(
                    'ddb-shared-listing-card',
                    SBDP_URL . 'assets/css/ddb-shared-listing-card.css',
                    $this->activityStyleHandles,
                    SBDP_VER
                );
            }
            $hasLeafletAssets = $this->hasLeafletAssets();
            if ($mapEnabled && $view === 'map' && $hasLeafletAssets) {
                wp_enqueue_style('sbdp-product-map');
            }
            if ($mapEnabled && $hasLeafletAssets) {
                wp_enqueue_style('leaflet');
            }
        }

        if (function_exists('wp_enqueue_script')) {
            if ($view === 'map') {
                wp_enqueue_script('sbdp-product-overview');
            } else {
                wp_enqueue_script('sbdp-activity-overview');
            }
            $hasLeafletAssets = $this->hasLeafletAssets();
            if ($mapEnabled && $hasLeafletAssets) {
                wp_enqueue_script('leaflet');
            }
            if ($mapEnabled && $view === 'map' && $hasLeafletAssets && wp_script_is('sbdp-product-map', 'registered')) {
                wp_enqueue_script('sbdp-product-map');
            }
        }
    }

    private function registerLeafletAssets(): bool
    {
        if (! function_exists('wp_register_script')) {
            return false;
        }

        $localJsPath = SBDP_DIR . 'assets/js/vendor/leaflet.min.js';
        $localJsUrl  = SBDP_URL . 'assets/js/vendor/leaflet.min.js';
        $localCssPath = SBDP_DIR . 'assets/css/vendor/leaflet.css';
        $localCssUrl  = SBDP_URL . 'assets/css/vendor/leaflet.css';

        $hasLocalAssets = is_readable($localJsPath) && is_readable($localCssPath);

        if (! $hasLocalAssets) {
            return false;
        }

        if (! wp_script_is('leaflet', 'registered')) {
            wp_register_script(
                'leaflet',
                $localJsUrl,
                array(),
                (string) filemtime($localJsPath),
                true
            );
        }

        if (function_exists('wp_register_style') && ! wp_style_is('leaflet', 'registered')) {
            wp_register_style(
                'leaflet',
                $localCssUrl,
                array(),
                (string) filemtime($localCssPath)
            );
        }

        return true;
    }

    private function hasLeafletAssets(): bool
    {
        return is_readable(SBDP_DIR . 'assets/js/vendor/leaflet.min.js')
            && is_readable(SBDP_DIR . 'assets/css/vendor/leaflet.css')
            && function_exists('wp_script_is')
            && function_exists('wp_style_is')
            && wp_script_is('leaflet', 'registered')
            && wp_style_is('leaflet', 'registered');
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
     * @return array{script:array{src:string,version:string},styles:array<string,array{src:string,version:string}>}
     */
    private function resolveFallbackAsset(string $entry): array
    {
        $fallbackMap = array(
            'modules/product-overview/assets/js/activity-overview/overzicht-activiteiten.tsx' => 'modules/product-overview/assets/js/dist/activityOverview.js',
        );

        if (isset($fallbackMap[$entry])) {
            $path = $fallbackMap[$entry];
            if (is_readable(SBDP_DIR . $path)) {
                $url = SBDP_URL . $path;
                $styles = array();

                if ($entry === 'modules/product-overview/assets/js/activity-overview/overzicht-activiteiten.tsx') {
                    $cssPath = 'modules/product-overview/assets/js/dist/activityOverview.css';
                    if (is_readable(SBDP_DIR . $cssPath)) {
                        $styleHandle = $this->buildStyleHandle($entry . '-fallback', 0);
                        $styles[ $styleHandle ] = array(
                            'src'     => SBDP_URL . $cssPath,
                            'version' => $this->determineAssetVersion(SBDP_URL . $cssPath),
                        );
                    }
                }

                return array(
                    'script' => array(
                        'src'     => $url,
                        'version' => $this->determineAssetVersion($url),
                    ),
                    'styles' => $styles,
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
        $normalized = strtolower($entry);
        $normalized = (string) preg_replace('/[^a-z0-9]+/', '-', $normalized);
        $normalized = trim($normalized, '-');

        if ($normalized === '') {
            $normalized = 'asset';
        }

        $hash = substr(md5($entry), 0, 8);

        return sprintf('sbdp-%s-%s-style-%d', $normalized, $hash, $index);
    }

    public function markScriptAsModule(string $tag, string $handle, string $src): string
    {
        unset($src);

        if ($handle !== 'sbdp-activity-overview') {
            return $tag;
        }

        if (strpos($tag, 'type=') === false) {
            return str_replace('<script ', '<script type="module" ', $tag);
        }

        return (string) preg_replace('/type=(["\']).*?\\1/', 'type="module"', $tag, 1);
    }

    public function maybeUseShopTemplate(string $template): string
    {
        // CRITICAL: Completely disable in any Elementor context
        if (defined('ELEMENTOR_VERSION')) {
            // Check for any Elementor editor/preview mode
            if (isset($_GET['elementor-preview']) || 
                isset($_GET['elementor_library']) ||
                isset($_GET['action']) && $_GET['action'] === 'elementor' ||
                isset($_GET['elementor']) ||
                isset($_REQUEST['elementor']) ||
                (function_exists('is_admin') && is_admin()) ||
                (defined('DOING_AJAX') && DOING_AJAX && isset($_REQUEST['action']) && 
                 strpos($_REQUEST['action'], 'elementor') !== false)) {
                return $template;
            }
        }

        // Don't override template for single posts (including private tours, products, etc.)
        if (function_exists('is_singular') && is_singular()) {
            return $template;
        }

        if (! function_exists('is_shop') || ! is_shop()) {
            return $template;
        }

        $shopTemplate = $this->moduleDir() . '/templates/shop-overview.php';
        if (! is_readable($shopTemplate)) {
            return $template;
        }

        return $shopTemplate;
    }

    private function buildComponentId(): string
    {
        return 'sbdp-po-' . substr(md5(uniqid(self::MODULE_SLUG, true)), 0, 8);
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function buildProductResponse(array $filters): array
    {
        return $this->repository->getProducts($filters);
    }

    private function renderTemplate(string $template, array $context): string
    {
        $file = $this->moduleDir() . '/templates/' . ltrim($template, '/');
        if (! file_exists($file)) {
            return '';
        }

        extract($context, EXTR_SKIP);

        ob_start();
        include $file;

        return (string) ob_get_clean();
    }

    private function moduleDir(): string
    {
        return __DIR__;
    }

    private function assetUrl(string $relative): string
    {
        $relative = ltrim($relative, '/');

        if (defined('SBDP_URL')) {
            return $this->trailingslash(SBDP_URL . 'modules/' . self::MODULE_SLUG) . $relative;
        }

        return $this->trailingslash(plugin_dir_url(__FILE__)) . $relative;
    }

    private function trailingslash(string $value): string
    {
        return rtrim($value, "/\\") . '/';
    }

    private function truthy($value, bool $default = false): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (bool) (int) $value;
        }

        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return $default;
        }

        return in_array($value, array('1', 'true', 'yes', 'on', 'map', 'show'), true);
    }

    /**
     * @return array<string, string>
     */
    private function frontendStrings(): array
    {
        return array(
            'filters'        => __('Filters', 'sbdp'),
            'applyFilters'   => __('Filters toepassen', 'sbdp'),
            'clearFilters'   => __('Reset', 'sbdp'),
            'gridLabel'      => __('Grid', 'sbdp'),
            'mapLabel'       => __('Kaart', 'sbdp'),
            'loading'        => __('Producten laden…', 'sbdp'),
            'empty'          => __('Geen resultaten voor deze filters.', 'sbdp'),
            'priceLabel'     => __('Vanaf', 'sbdp'),
            'durationLabel'  => __('Duur', 'sbdp'),
            'minutesSuffix'  => __('min', 'sbdp'),
            'viewDetails'    => __('Bekijk product', 'sbdp'),
            'participants'   => __('Personen', 'sbdp'),
            'dateLabel'      => __('Datum', 'sbdp'),
            'typeLabel'      => __('Type', 'sbdp'),
            'priceRange'     => __('Prijsrange', 'sbdp'),
            'locationLabel'  => __('Locatie', 'sbdp'),
            'noMapData'      => __('Kaartweergave niet mogelijk: geen locaties met coördinaten gevonden.', 'sbdp'),
        );
    }
}

final class ProductOverviewRepository
{
    public const POST_TYPE = 'product';
    public const TAXONOMY = 'product_cat';

    private const DEFAULT_PER_PAGE = 12;
    private const MAX_PER_PAGE = 24;

    private ?ActivityService $activityService;
    private ?BookingEngine $engine;

    public function __construct(?ActivityService $activityService = null)
    {
        $this->activityService = $activityService;

        if ($this->activityService === null && class_exists(ActivityService::class)) {
            $this->activityService = new ActivityService();
        }

        $this->engine = function_exists('sbdp_booking_engine')
            ? sbdp_booking_engine()
            : null;
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    public function normaliseFilters(array $filters): array
    {
        $type     = isset($filters['type']) ? sanitize_title((string) $filters['type']) : '';
        $date     = isset($filters['date']) ? $this->sanitizeDate((string) $filters['date']) : null;
        $minPrice = isset($filters['min_price']) ? $this->sanitizeFloat($filters['min_price']) : null;
        $maxPrice = isset($filters['max_price']) ? $this->sanitizeFloat($filters['max_price']) : null;
        $search   = isset($filters['search']) ? sanitize_text_field((string) $filters['search']) : '';
        $participants = isset($filters['participants']) ? max(1, (int) $filters['participants']) : 1;

        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : self::DEFAULT_PER_PAGE;
        if ($perPage <= 0) {
            $perPage = self::DEFAULT_PER_PAGE;
        }

        $perPage = min(self::MAX_PER_PAGE, $perPage);

        $page = isset($filters['page']) ? (int) $filters['page'] : 1;
        if ($page <= 0) {
            $page = 1;
        }

        return array(
            'type'         => $type,
            'date'         => $date,
            'min_price'    => $minPrice,
            'max_price'    => $maxPrice,
            'search'       => $search,
            'per_page'     => $perPage,
            'page'         => $page,
            'participants' => $participants,
        );
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{
     *     products:array<int, array<string, mixed>>,
     *     pagination:array<string, int>
     * }
     */
    public function getProducts(array $filters): array
    {
        $filters = $this->normaliseFilters($filters);

        if ($this->activityService !== null) {
            $collection = $this->collectFromActivityService($filters);
        } else {
            $collection = $this->collectFromQuery($filters);
        }

        $collection = $this->filterByDate($collection, $filters);
        $collection = $this->filterByPrice($collection, $filters);

        $total   = count($collection);
        $perPage = $filters['per_page'];
        $page    = $filters['page'];
        $offset  = ($page - 1) * $perPage;
        $offset  = $offset < 0 ? 0 : $offset;

        $products = array_slice($collection, $offset, $perPage);
        $args = array();

        $payload = array(
            'products'   => array_values($products),
            'pagination' => array(
                'page'       => $page,
                'perPage'    => $perPage,
                'total'      => $total,
                'totalPages' => max(1, (int) ceil($total / max(1, $perPage))),
            ),
            'meta'       => array(
                'hasCoordinates' => $this->collectionHasCoordinates($collection),
            ),
        );

        /**
         * Filter the response payload.
         *
         * @param array<string, mixed> $payload
         * @param array<string, mixed> $filters
         * @param array<string, mixed> $args
         */
        return apply_filters('sbdp/product_overview/results', $payload, $filters, $args);
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<int, array<string, mixed>>
     */
    private function collectFromActivityService(array $filters): array
    {
        if ($this->activityService === null) {
            return array();
        }

        $activityFilters = array(
            'search'    => $filters['search'],
            'category'  => $filters['type'] !== '' ? array($filters['type']) : array(),
            'price_min' => $filters['min_price'],
            'price_max' => $filters['max_price'],
        );

        $items = $this->activityService->listActivities($activityFilters, false);

        $products = array();
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $payload = $this->buildProductPayload(
                (int) ($item['product_id'] ?? $item['id'] ?? 0),
                $item
            );

            if ($payload !== null) {
                $products[] = $payload;
            }
        }

        return $products;
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<int, array<string, mixed>>
     */
    private function collectFromQuery(array $filters): array
    {
        if (! class_exists(WP_Query::class)) {
            return array();
        }

        $args = array(
            'post_type'      => self::POST_TYPE,
            'post_status'    => array('publish'),
            'posts_per_page' => $filters['per_page'] * 4,
            'orderby'        => array(
                'menu_order' => 'ASC',
                'title'      => 'ASC',
            ),
        );

        if ($filters['type'] !== '') {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => self::TAXONOMY,
                    'field'    => 'slug',
                    'terms'    => array($filters['type']),
                ),
            );
        }

        if ($filters['search'] !== '') {
            $args['s'] = $filters['search'];
        }

        $metaQuery = array();

        if ($filters['min_price'] !== null) {
            $metaQuery[] = array(
                'key'     => '_price',
                'compare' => '>=',
                'value'   => $filters['min_price'],
                'type'    => 'NUMERIC',
            );
        }

        if ($filters['max_price'] !== null) {
            $metaQuery[] = array(
                'key'     => '_price',
                'compare' => '<=',
                'value'   => $filters['max_price'],
                'type'    => 'NUMERIC',
            );
        }

        if ($metaQuery !== array()) {
            $metaQuery['relation'] = 'AND';
            $args['meta_query']    = $metaQuery;
        }

        $args = apply_filters('sbdp/product_overview/query_args', $args, $filters);

        $query = new WP_Query($args);
        $products = array();

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post = $query->post;

                if (! $post instanceof WP_Post) {
                    continue;
                }

                $payload = $this->buildProductPayload((int) $post->ID, array());
                if ($payload !== null) {
                    $products[] = $payload;
                }
            }

            wp_reset_postdata();
        }

        return $products;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function buildProductPayload(int $productId, array $context): ?array
    {
        if ($productId <= 0) {
            return null;
        }

        $post = function_exists('get_post') ? get_post($productId) : null;

        $title = (string) ($context['title'] ?? '');
        if ($title === '' && $post instanceof WP_Post) {
            $title = $post->post_title;
        }

        $permalink = (string) ($context['permalink'] ?? '');
        if ($permalink === '' && function_exists('get_permalink')) {
            $permalink = get_permalink($productId);
        }

        $image = $context['image'] ?? '';
        if ($image === false || ! is_string($image)) {
            $image = '';
        }

        if ($image === '' && function_exists('get_the_post_thumbnail_url')) {
            $image = get_the_post_thumbnail_url($productId, 'large') ?: '';
        }

        $durationMinutes = (int) ($context['duration_minutes'] ?? ($context['duration']['minutes'] ?? 0));
        if ($durationMinutes <= 0) {
            $durationMinutes = $this->resolveDurationMinutes($productId);
        }

        $price         = $this->resolvePricePayload($productId, $context);
        $categories    = $this->resolveCategories($productId, $context);
        $categoryNames = $this->resolveCategoryNames($productId, $context);
        $categorySlugs = $this->resolveCategorySlugs($productId, $context);
        $location      = $this->resolveLocationString($productId, $context);
        $coordinates = $this->resolveCoordinates($productId, $location, $context);
        $availabilitySummary = $context['availability']['summary'] ?? array();
        $slug       = $this->resolveProductSlug($productId, $context, $permalink);
        $isBookable = $this->isProductBookable($post, $context, $permalink);

        $product = array(
            'id'        => $productId,
            'title'     => $title,
            'permalink' => is_string($permalink) ? $permalink : '',
            'excerpt'   => $this->resolveExcerpt($post),
            'image'     => $image,
            'duration'  => array(
                'value'     => $durationMinutes,
                'formatted' => $this->formatDuration($durationMinutes),
            ),
            'price'     => $price,
            'type'      => $categories,
            'slug'      => $slug,
            'is_bookable' => $isBookable,
            'categories'  => $categoryNames,
            'category_slugs' => $categorySlugs,
            'location'  => $location,
            'coordinates' => $coordinates,
            'availability' => array(
                'dates'   => array(),
                'summary' => $availabilitySummary,
            ),
        );

        /**
         * Allow downstream customization of the product payload.
         *
         * @param array<string, mixed> $product
         * @param WP_Post|null         $post
         * @param array<string, mixed> $context
         */
        return apply_filters('sbdp/product_overview/product', $product, $post, $context);
    }

    private function resolveExcerpt(?WP_Post $post): string
    {
        if ($post instanceof WP_Post) {
            if (function_exists('has_excerpt') && has_excerpt($post)) {
                return (string) get_the_excerpt($post);
            }

            if (function_exists('wp_trim_words')) {
                return wp_trim_words(strip_tags((string) $post->post_content), 28);
            }

            return substr(strip_tags((string) $post->post_content), 0, 160);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolvePricePayload(int $productId, array $context): array
    {
        $currency = $context['pricing']['currency'] ?? get_option('woocommerce_currency', 'EUR');
        $pricing = $context['pricing'] ?? array();
        $raw = null;
        $needsTaxProjection = false;

        if (isset($pricing['display_total']) && is_numeric($pricing['display_total'])) {
            $raw = (float) $pricing['display_total'];
        } elseif (isset($pricing['dynamic']['total_adjusted']) && is_numeric($pricing['dynamic']['total_adjusted'])) {
            $raw = (float) $pricing['dynamic']['total_adjusted'];
        } elseif (isset($pricing['base']) && is_numeric($pricing['base'])) {
            $raw = (float) $pricing['base'];
        } elseif (isset($pricing['per_person']) && is_numeric($pricing['per_person'])) {
            $raw = (float) $pricing['per_person'];
        }

        if ($raw === null) {
            $metaPrice = get_post_meta($productId, '_price', true);
            if (! is_numeric($metaPrice)) {
                $metaPrice = get_post_meta($productId, '_regular_price', true);
            }
            $raw = is_numeric($metaPrice) ? (float) $metaPrice : 0.0;
            $needsTaxProjection = $raw > 0;
        }

        if ($needsTaxProjection && $raw > 0 && function_exists('wc_get_product')) {
            $product = wc_get_product($productId);
            if ($product) {
                $raw = (float) wc_get_price_including_tax($product, array('price' => $raw));
            }
        }

        return array(
            'raw'       => $raw,
            'formatted' => $this->formatCurrency($raw, $currency),
            'currency'  => $currency,
        );
    }

    private function resolveCategories(int $productId, array $context): array
    {
        $label = '';
        $slug  = '';

        if (! empty($context['categories']) && is_array($context['categories'])) {
            $label = (string) reset($context['categories']);
        }

        if (! empty($context['category_slugs']) && is_array($context['category_slugs'])) {
            $slug = (string) reset($context['category_slugs']);
        }

        if ($label !== '' || $slug !== '') {
            return array(
                'label' => $label,
                'slug'  => $slug,
            );
        }

        if (! function_exists('wp_get_post_terms')) {
            return array(
                'label' => '',
                'slug'  => '',
            );
        }

        $terms = wp_get_post_terms(
            $productId,
            self::TAXONOMY,
            array(
                'number' => 1,
            )
        );

        if (is_wp_error($terms) || $terms === array()) {
            return array(
                'label' => '',
                'slug'  => '',
            );
        }

        $term = $terms[0];

        return array(
            'label' => $term instanceof WP_Term ? (string) $term->name : '',
            'slug'  => $term instanceof WP_Term ? (string) $term->slug : '',
        );
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int, string>
     */
    private function resolveCategoryNames(int $productId, array $context): array
    {
        $names = $this->normaliseStringList($context['categories'] ?? array());
        if ($names !== array()) {
            return $names;
        }

        if (! function_exists('wp_get_post_terms')) {
            return array();
        }

        $terms = wp_get_post_terms($productId, self::TAXONOMY);
        if (is_wp_error($terms) || $terms === array()) {
            return array();
        }

        $resolved = array();
        foreach ($terms as $term) {
            if ($term instanceof WP_Term && isset($term->name)) {
                $label = trim((string) $term->name);
                if ($label !== '') {
                    $resolved[] = $label;
                }
            }
        }

        return array_values(array_unique($resolved));
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int, string>
     */
    private function resolveCategorySlugs(int $productId, array $context): array
    {
        $slugs = $this->normaliseStringList($context['category_slugs'] ?? array(), true);
        if ($slugs !== array()) {
            return $slugs;
        }

        if (! function_exists('wp_get_post_terms')) {
            return array();
        }

        $terms = wp_get_post_terms($productId, self::TAXONOMY);
        if (is_wp_error($terms) || $terms === array()) {
            return array();
        }

        $resolved = array();
        foreach ($terms as $term) {
            if ($term instanceof WP_Term) {
                $slug = sanitize_title((string) $term->slug);
                if ($slug !== '') {
                    $resolved[] = $slug;
                }
            }
        }

        return array_values(array_unique($resolved));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveProductSlug(int $productId, array $context, string $permalink): string
    {
        if (isset($context['slug']) && is_string($context['slug'])) {
            $slug = sanitize_title($context['slug']);
            if ($slug !== '') {
                return $slug;
            }
        }

        if ($permalink !== '') {
            $path = parse_url($permalink, PHP_URL_PATH);
            if (is_string($path)) {
                $basename = trim((string) basename($path), '/');
                $slug = sanitize_title($basename);
                if ($slug !== '') {
                    return $slug;
                }
            }
        }

        if (function_exists('get_post_field')) {
            $postSlug = get_post_field('post_name', $productId);
            if (is_string($postSlug)) {
                $slug = sanitize_title($postSlug);
                if ($slug !== '') {
                    return $slug;
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function isProductBookable(?WP_Post $post, array $context, string $permalink): bool
    {
        if (isset($context['is_bookable'])) {
            return (bool) $context['is_bookable'];
        }

        if (isset($context['stock_status']) && $context['stock_status'] === 'outofstock') {
            return false;
        }

        if ($permalink !== '') {
            return true;
        }

        if ($post instanceof WP_Post) {
            return $post->post_status === 'publish';
        }

        return false;
    }

    /**
     * @param mixed $value
     *
     * @return array<int, string>
     */
    private function normaliseStringList($value, bool $sanitizeSlug = false): array
    {
        if (! is_array($value)) {
            return array();
        }

        $list = array();

        foreach ($value as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $normalized = trim($entry);
            if ($normalized === '') {
                continue;
            }

            $list[] = $sanitizeSlug ? sanitize_title($normalized) : $normalized;
        }

        return array_values(array_unique($list));
    }

    private function resolveDurationMinutes(int $productId): int
    {
        $value = get_post_meta($productId, '_sbdp_duration', true);
        $unit  = (string) get_post_meta($productId, '_sbdp_duration_unit', true);
        $value = is_numeric($value) ? (float) $value : 0.0;

        if ($value <= 0) {
            return 90;
        }

        if ($unit === '' || strtolower($unit) === 'minutes') {
            return (int) $value;
        }

        if (in_array(strtolower($unit), array('hour', 'hours'), true)) {
            return (int) round($value * 60);
        }

        return (int) $value;
    }

    private function resolveLocationString(int $productId, array $context): string
    {
        if (! empty($context['location']) && is_string($context['location'])) {
            return trim($context['location']);
        }

        $stored = get_post_meta($productId, '_sbdp_booking_location', true);

        if (is_array($stored)) {
            $stored = $stored['address'] ?? $stored['label'] ?? reset($stored);
        }

        return is_string($stored) ? trim($stored) : '';
    }

    private function resolveCoordinates(int $productId, string $location, array $context): array
    {
        if (! empty($context['coordinates']) && is_array($context['coordinates'])) {
            $lat = $context['coordinates']['lat'] ?? null;
            $lng = $context['coordinates']['lng'] ?? null;

            if (is_numeric($lat) && is_numeric($lng)) {
                return array(
                    'lat' => (float) $lat,
                    'lng' => (float) $lng,
                );
            }
        }

        if ($location !== '') {
            $parsed = $this->parseCoordinateString($location);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        $lat = get_post_meta($productId, '_sbdp_location_lat', true);
        $lng = get_post_meta($productId, '_sbdp_location_lng', true);

        if (! is_numeric($lat) || ! is_numeric($lng)) {
            $lat = get_post_meta($productId, 'latitude', true);
            $lng = get_post_meta($productId, 'longitude', true);
        }

        if (is_numeric($lat) && is_numeric($lng)) {
            return array(
                'lat' => (float) $lat,
                'lng' => (float) $lng,
            );
        }

        return array(
            'lat' => null,
            'lng' => null,
        );
    }

    private function parseCoordinateString(string $value): ?array
    {
        $json = json_decode($value, true);
        if (is_array($json)) {
            $lat = $json['lat'] ?? $json['latitude'] ?? null;
            $lng = $json['lng'] ?? $json['longitude'] ?? null;

            if (is_numeric($lat) && is_numeric($lng)) {
                return array(
                    'lat' => (float) $lat,
                    'lng' => (float) $lng,
                );
            }
        }

        if (preg_match('/(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)/', $value, $matches) === 1) {
            return array(
                'lat' => (float) $matches[1],
                'lng' => (float) $matches[2],
            );
        }

        if (preg_match('/(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)/', $value, $matches) === 1) {
            return array(
                'lat' => (float) $matches[1],
                'lng' => (float) $matches[2],
            );
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $products
     * @param array<string, mixed>             $filters
     *
     * @return array<int, array<string, mixed>>
     */
    private function filterByDate(array $products, array $filters): array
    {
        if (! $filters['date'] || ! $this->engine instanceof BookingEngine) {
            return $products;
        }

        $date = $filters['date'];
        $participants = $filters['participants'];

        return array_values(
            array_filter(
                $products,
                function (array $product) use ($date, $participants): bool {
                    $productId = isset($product['id']) ? (int) $product['id'] : 0;
                    if ($productId <= 0) {
                        return false;
                    }

                    $result = $this->engine->checkAvailability($productId, $date, null, null, $participants);

                    return $result === true;
                }
            )
        );
    }

    /**
     * @param array<int, array<string, mixed>> $products
     * @param array<string, mixed>             $filters
     *
     * @return array<int, array<string, mixed>>
     */
    private function filterByPrice(array $products, array $filters): array
    {
        if ($filters['min_price'] === null && $filters['max_price'] === null) {
            return $products;
        }

        return array_values(
            array_filter(
                $products,
                function (array $product) use ($filters): bool {
                    $price = isset($product['price']['raw']) ? (float) $product['price']['raw'] : 0.0;
                    if ($filters['min_price'] !== null && $price < (float) $filters['min_price']) {
                        return false;
                    }

                    if ($filters['max_price'] !== null && $price > (float) $filters['max_price']) {
                        return false;
                    }

                    return true;
                }
            )
        );
    }

    /**
     * @param array<int, array<string, mixed>> $products
     */
    private function collectionHasCoordinates(array $products): bool
    {
        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }

            $lat = $product['coordinates']['lat'] ?? null;
            $lng = $product['coordinates']['lng'] ?? null;

            if (is_numeric($lat) && is_numeric($lng)) {
                return true;
            }
        }

        return false;
    }

    private function formatCurrency(?float $amount, string $currency = ''): string
    {
        if ($amount === null || $amount <= 0.0) {
            return '';
        }

        $currency = $currency ?: get_option('woocommerce_currency', 'EUR');

        if (function_exists('wc_price')) {
            return (string) wc_price($amount, array('currency' => $currency));
        }

        if (function_exists('number_format_i18n')) {
            return sprintf('%s %s', $currency, number_format_i18n($amount, 2));
        }

        return sprintf('%s %.2f', $currency, $amount);
    }

    private function formatDuration(int $minutes): string
    {
        if ($minutes <= 0) {
            return '';
        }

        if ($minutes < 60) {
            return sprintf(
                /* translators: %d: minutes */
                __('%d min', 'sbdp'),
                $minutes
            );
        }

        $hours = floor($minutes / 60);
        $mins  = $minutes % 60;

        if ($mins === 0) {
            return sprintf(
                /* translators: %d: hours */
                __('%d uur', 'sbdp'),
                (int) $hours
            );
        }

        return sprintf(
            /* translators: 1: hours, 2: minutes */
            __('%1$d u %2$d min', 'sbdp'),
            (int) $hours,
            (int) $mins
        );
    }

    private function sanitizeDate(string $date): ?string
    {
        $date = trim($date);
        if ($date === '') {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
    }

    /**
     * @param mixed $value
     */
    private function sanitizeFloat($value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $filtered = filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            if ($filtered === '' || $filtered === false || $filtered === null) {
                return null;
            }

            return (float) $filtered;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{
     *     products:array<int, array<string, mixed>>,
     *     pagination:array<string, int>,
     *     meta:array<string, mixed>
     * }
     */
    public function emptyPayload(array $filters): array
    {
        $filters = $this->normaliseFilters($filters);

        return array(
            'products'   => array(),
            'pagination' => array(
                'page'       => $filters['page'],
                'perPage'    => $filters['per_page'],
                'total'      => 0,
                'totalPages' => 1,
            ),
            'meta'       => array(
                'hasCoordinates' => false,
            ),
        );
    }
}
