<?php

declare(strict_types=1);

namespace SBDP\ProductPageRefresh;

use BSP\Core\Interfaces\ModuleInterface;
use BSPModule\Core\Rest\RestService;
use BSPModule\Core\WooCommerce\Display\ProductForm;
use BSPModule\Core\WooCommerce\ProductPageContext;
use BSPModule\Core\Product\ProductMeta;
use BSPModule\Core\WooCommerce\ProductType\BookableServiceProductType;
use BSPModule\Core\Services\BookingModeService;
use BSPModule\Core\Services\BookingTruthRuntimeService;
use BPM\Core\ProductSettings;
use BSP\DayPlanner\Module as DayPlannerModule;
use SBDP\Pricing\SelectionPricing;
use WC_Product;
use WP_Post;
use WP_REST_Request;

final class Module implements ModuleInterface
{
    private const OPTION_FLAG = 'sbdp_product_layout_enabled';
    private const STYLE_HANDLE = 'sbdp-product-summary';
    private const SCRIPT_HANDLE = 'sbdp-product-summary';

    public function init(): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        if (function_exists('add_action')) {
            add_action('init', [$this, 'disableLegacyProductForm'], 25);
            add_action('init', [$this, 'registerShortcodes']);
            add_action('rest_api_init', [$this, 'registerRestRoutes']);
            add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
            add_action('woocommerce_before_single_product', [$this, 'prepareProductSummary'], 5);
            add_action('woocommerce_single_product_summary', [$this, 'renderSummaryCard'], 25);
            add_action('wp_footer', [$this, 'renderStickyCta']);
            add_action('woocommerce_checkout_create_order_line_item', [$this, 'persistOrderItemMeta'], 10, 4);
            add_action('woocommerce_before_calculate_totals', [$this, 'recalculateCartTotals'], 5, 1);
            add_action('woocommerce_add_to_cart', [$this, 'syncCartQuantity'], 20, 6);
            add_action('woocommerce_cart_loaded_from_session', [$this, 'invalidateAggregateSessionTotals'], 5);
            if (method_exists($this, 'sortCartItemsChronologically')) {
                add_action('woocommerce_cart_loaded_from_session', [$this, 'sortCartItemsChronologically'], 100);
            }
        }

        if (function_exists('add_filter')) {
            add_filter('woocommerce_add_to_cart_validation', [$this, 'validateCanonicalBookingTruth'], 10, 6);
            add_filter('woocommerce_add_cart_item_data', [$this, 'captureCartItemData'], 10, 3);
            add_filter('woocommerce_add_cart_item', [$this, 'hydrateProjectedCartItem'], 10, 2);
            add_filter('woocommerce_get_cart_item_from_session', [$this, 'hydrateProjectedCartItemFromSession'], 10, 3);
            add_filter('woocommerce_get_item_data', [$this, 'exposeCartItemData'], 10, 2);
            add_filter('woocommerce_cart_item_price', [$this, 'filterCartItemPriceHtml'], 10, 3);
            add_filter('woocommerce_cart_item_subtotal', [$this, 'filterCartItemSubtotalHtml'], 10, 3);
            add_filter('woocommerce_tax_display_cart', [$this, 'forceInclusiveCartTaxDisplay'], 20);
            add_filter('option_woocommerce_tax_display_cart', [$this, 'forceInclusiveCartTaxDisplayForOption'], 20);
            add_filter('option_woocommerce_tax_display_shop', [$this, 'forceInclusiveShopTaxDisplayForOrderAdmin'], 20);
            add_filter('woocommerce_order_formatted_line_subtotal', [$this, 'filterOrderFormattedLineSubtotal'], 20, 3);
        }
    }

    public function forceInclusiveCartTaxDisplay(string $displayMode): string
    {
        unset($displayMode);

        return 'incl';
    }

    public function forceInclusiveCartTaxDisplayForOption(string $displayMode): string
    {
        if ($this->shouldForceInclusiveTaxDisplayContext()) {
            return 'incl';
        }

        return $displayMode;
    }

    public function forceInclusiveShopTaxDisplayForOrderAdmin(string $displayMode): string
    {
        if ($this->shouldForceInclusiveTaxDisplayContext()) {
            return 'incl';
        }

        return $displayMode;
    }

    private function shouldForceInclusiveTaxDisplayContext(): bool
    {
        if (is_admin()) {
            return true;
        }

        if (function_exists('is_checkout') && is_checkout()) {
            return true;
        }

        if (function_exists('is_wc_endpoint_url') && (is_wc_endpoint_url('order-pay') || is_wc_endpoint_url('order-received') || is_wc_endpoint_url('view-order'))) {
            return true;
        }

        $wcAjax = isset($_REQUEST['wc-ajax']) ? sanitize_text_field((string) $_REQUEST['wc-ajax']) : '';
        if (in_array($wcAjax, ['update_order_review', 'get_refreshed_fragments'], true)) {
            return true;
        }

        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if ($screen && isset($screen->id)) {
                $screenId = (string) $screen->id;
                if (in_array($screenId, ['shop_order', 'woocommerce_page_wc-orders'], true)) {
                    return true;
                }
            }
        }

        $postType = isset($_REQUEST['post_type']) ? sanitize_text_field((string) $_REQUEST['post_type']) : '';
        $page = isset($_REQUEST['page']) ? sanitize_text_field((string) $_REQUEST['page']) : '';
        if ($postType === 'shop_order' || $page === 'wc-orders') {
            return true;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            $route = '';
            if (function_exists('rest_get_url_prefix')) {
                $route = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
            }

            if ($route !== '' && strpos($route, '/wc/store/') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Render order line subtotal from CSOT display values so checkout/order/admin surfaces stay incl. VAT.
     *
     * @param string $formattedSubtotal
     * @param mixed  $item
     * @param mixed  $order
     */
    public function filterOrderFormattedLineSubtotal(string $formattedSubtotal, $item, $order): string
    {
        unset($order);

        if (! is_object($item) || ! method_exists($item, 'get_meta')) {
            return $formattedSubtotal;
        }

        $displayTotal = (float) $item->get_meta('sbdp_display_total', true);
        if ($displayTotal <= 0.0) {
            $pricingMeta = $item->get_meta('_sbdp_pricing', true);
            if (is_array($pricingMeta)) {
                if (isset($pricingMeta['display_total'])) {
                    $displayTotal = (float) $pricingMeta['display_total'];
                } elseif (isset($pricingMeta['total'])) {
                    $displayTotal = (float) $pricingMeta['total'];
                }
            }
        }

        if ($displayTotal <= 0.0 || ! function_exists('wc_price')) {
            return $formattedSubtotal;
        }

        return wc_price($displayTotal);
    }

    public function disableLegacyProductForm(): void
    {
        if (! class_exists(ProductForm::class)) {
            return;
        }

        remove_action('wp_enqueue_scripts', [ProductForm::class, 'maybe_enqueue_assets']);
        remove_action('woocommerce_before_single_product', [ProductForm::class, 'prepare_single_product']);
        remove_action('woocommerce_single_product_summary', [ProductForm::class, 'render'], 25);
        remove_filter('body_class', [ProductForm::class, 'filter_body_class']);
        remove_filter('sbdp/product_form/combi_options', [ProductForm::class, 'build_combi_options'], 10);
    }

    public function registerShortcodes(): void
    {
        if (! function_exists('add_shortcode')) {
            return;
        }

        add_shortcode('sbdp_summary_card', [$this, 'handleShortcode']);
    }

    public function registerRestRoutes(): void
    {
        if (! function_exists('register_rest_route')) {
            return;
        }

        register_rest_route(
            'sbdp/v1',
            '/product/price',
            [
                'methods'             => 'GET',
                'permission_callback' => [$this, 'authorizeRestRequest'],
                'callback'            => [$this, 'handlePriceRequest'],
                'args'                => [
                    'product_id'   => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'date'         => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'time'         => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'participants' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'resource_id'  => [
                        'required'          => false,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'combi_id'     => [
                        'required'          => false,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );
    }

    public function authorizeRestRequest(WP_REST_Request $request): bool
    {
        $nonce = $request->get_header('X-WP-Nonce');
        if ($nonce && \function_exists('wp_verify_nonce') && wp_verify_nonce($nonce, 'wp_rest')) {
            return true;
        }

        $publicNonce = $request->get_header('x-sbdp-nonce');
        if ($publicNonce && \function_exists('wp_verify_nonce') && wp_verify_nonce($publicNonce, RestService::PUBLIC_NONCE_ACTION)) {
            return true;
        }

        return \function_exists('is_user_logged_in') && \is_user_logged_in();
    }

    public function enqueueAssets(): void
    {
        if (! $this->shouldRenderSummaryExperience()) {
            return;
        }

        wp_enqueue_style(
            self::STYLE_HANDLE,
            SBDP_URL . 'assets/product-summary.css',
            [],
            SBDP_VER . '.' . time()
        );

        DayPlannerModule::enqueuePricingHelpers();

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            SBDP_URL . 'assets/product-summary.js',
            ['jquery', 'sbdp-day-planner-helpers'],
            SBDP_VER,
            true
        );

        $settings = [
            'restUrl'    => esc_url_raw(rest_url('sbdp/v1/product/price')),
            'availabilityUrl' => esc_url_raw(rest_url('sbdp/v1/availability/slots')),
            'nonce'      => wp_create_nonce(RestService::PUBLIC_NONCE_ACTION),
            'plannerUrl' => $this->getPlannerUrl(),
            'strings'    => [
                'selectDate'  => __('Kies eerst een datum.', 'sbdp'),
                'selectTime'  => __('Kies een tijdstip.', 'sbdp'),
                'selectPax'   => __('Vul het aantal deelnemers in.', 'sbdp'),
                'loading'     => __('Prijs wordt berekend…', 'sbdp'),
                'unavailable' => __('Geen prijs beschikbaar voor deze combinatie.', 'sbdp'),
                'planError'   => __('Plannerpagina niet gevonden.', 'sbdp'),
                'participantsPlural'   => __('personen', 'sbdp'),
                'participantsSingular' => __('persoon', 'sbdp'),
            ],
        ];

        wp_localize_script(
            self::SCRIPT_HANDLE,
            'SBDP_ProductSummarySettings',
            $settings
        );
    }

    public function prepareProductSummary(): void
    {
        if (! $this->shouldRenderSummaryExperience()) {
            return;
        }

        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_price', 10);
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40);
    }

    public function renderSummaryCard(): void
    {
        $product = $this->getCurrentProduct();
        if (! $this->shouldRenderSummaryExperience() || ! $this->isTargetProduct($product)) {
            return;
        }

        echo $this->captureSummaryMarkup($product); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function renderStickyCta(): void
    {
        $product = $this->getCurrentProduct();
        if (! $this->shouldRenderSummaryExperience() || ! $this->isTargetProduct($product)) {
            return;
        }

        ?>
        <div
            class="sbdp-sticky-cta"
            data-sbdp-sticky
            data-product-id="<?php echo esc_attr((string) $product->get_id()); ?>"
            hidden
        >
            <div class="sbdp-sticky-cta__meta">
                <span class="sbdp-sticky-cta__time" data-sbdp-sticky-time>--:--</span>
                <span class="sbdp-sticky-cta__people" data-sbdp-sticky-people>0</span>
            </div>
            <div class="sbdp-sticky-cta__price" data-sbdp-sticky-price>&mdash;</div>
            <button type="button" class="sbdp-sticky-cta__book" data-sbdp-sticky-book>
                <?php esc_html_e('Leg in winkelwagen', 'sbdp'); ?>
            </button>
        </div>
        <?php
    }

    /**
     * @param array<string,mixed> $cartItemData
     * @return array<string,mixed>
     */
    public function captureCartItemData(array $cartItemData, int $productId, int $variationId): array
    {
        unset($variationId);

        if (! function_exists('wc_get_product')) {
            return $cartItemData;
        }

        $product = wc_get_product($productId);
        if (! $this->isTargetProduct($product)) {
            return $cartItemData;
        }

        $nonce = isset($_POST['_sbdp_summary_nonce'])
            ? sanitize_text_field(wp_unslash((string) $_POST['_sbdp_summary_nonce']))
            : '';

        $has_valid_nonce = $nonce !== '' && wp_verify_nonce($nonce, 'sbdp_summary_add_to_cart');
        $legacyNonce = isset($_POST['sbdp_booking_nonce'])
            ? sanitize_text_field(wp_unslash((string) $_POST['sbdp_booking_nonce']))
            : '';
        $has_valid_legacy_nonce = $legacyNonce !== '' && wp_verify_nonce($legacyNonce, 'sbdp_booking');
        $activeCombisRaw = isset($_POST['sbdp_active_combis']) && ! is_array($_POST['sbdp_active_combis'])
            ? json_decode(wp_unslash((string) $_POST['sbdp_active_combis']), true)
            : array();
        $activeCombis = is_array($activeCombisRaw) ? $activeCombisRaw : array();

        $date = isset($_POST['sbdp_summary_date'])
            ? $this->sanitizeDate(wp_unslash((string) $_POST['sbdp_summary_date']))
            : (isset($_POST['sbdp_date']) ? $this->sanitizeDate(wp_unslash((string) $_POST['sbdp_date'])) : null);
        $time = isset($_POST['sbdp_summary_time'])
            ? $this->sanitizeTime(wp_unslash((string) $_POST['sbdp_summary_time']))
            : (isset($_POST['sbdp_time']) ? $this->sanitizeTime(wp_unslash((string) $_POST['sbdp_time'])) : null);
        $participantsRaw = isset($_POST['sbdp_summary_participants'])
            ? (int) wp_unslash($_POST['sbdp_summary_participants'])
            : (isset($_POST['sbdp_participants']) ? (int) wp_unslash($_POST['sbdp_participants']) : 0);
        $participants = max(1, min(999, $participantsRaw));
        $resourceId = isset($_POST['sbdp_summary_resource'])
            ? (int) wp_unslash($_POST['sbdp_summary_resource'])
            : (isset($_POST['sbdp_resource']) ? (int) wp_unslash($_POST['sbdp_resource']) : 0);
        $planner_input = class_exists(\SBDP_Planner_Domain_Service::class) && isset($_POST['sbdp_planner_input'])
            ? \SBDP_Planner_Domain_Service::decode_json_array((string) $_POST['sbdp_planner_input'])
            : array();
        $plan_item = class_exists(\SBDP_Planner_Domain_Service::class) && isset($_POST['sbdp_plan_item'])
            ? \SBDP_Planner_Domain_Service::decode_json_array((string) $_POST['sbdp_plan_item'])
            : array();
        if (! $has_valid_nonce && ! $has_valid_legacy_nonce) {
            return $cartItemData;
        }

            if ($date === null || $time === null) {
                if ($plan_item !== array() && class_exists(\SBDP_Planner_Domain_Service::class)) {
                    return $this->finalizeCartPayload(
                        array_merge(
                            $cartItemData,
                            \SBDP_Planner_Domain_Service::build_cart_payload_from_plan_item($plan_item, $planner_input)
                        ),
                        $productId
                    );
                }

                return $cartItemData;
            }

        if (class_exists(\SBDP_Planner_Domain_Service::class)) {
            $combi_ids_raw = isset($_POST['sbdp_combi_ids']) ? (array) wp_unslash($_POST['sbdp_combi_ids']) : array();
            $combi_ids = array_values(array_filter(array_map('absint', $combi_ids_raw)));
            $combi_timing_map = isset($_POST['sbdp_combi_timing']) ? (array) wp_unslash($_POST['sbdp_combi_timing']) : array();

            $summaryCombi = isset($_POST['sbdp_summary_combi'])
                ? absint(wp_unslash($_POST['sbdp_summary_combi']))
                : 0;
            $formCombi = isset($_POST['sbdp_combi'])
                ? absint(wp_unslash($_POST['sbdp_combi']))
                : 0;
            $combi = $summaryCombi > 0 ? $summaryCombi : $formCombi;
            if (empty($combi_ids) && $combi > 0) {
                $combi_ids = array($combi);
            }

            $combi_items = [];
            foreach ($combi_ids as $combi_id) {
                if ($combi_id <= 0) {
                    continue;
                }

                $combi_product = wc_get_product($combi_id);
                $timing_raw = $combi_timing_map[$combi_id] ?? $combi_timing_map[(string) $combi_id] ?? 'before';
                $timing = $timing_raw === 'after' ? 'after' : 'before';
                $activeCombiEntry = $this->findPostedActiveCombi($activeCombis, $combi_id);

                // Look up the combi label if it was explicitly submitted from the planner UI.
                $form_combi_labels = isset($_POST['sbdp_combi_label']) && is_array($_POST['sbdp_combi_label'])
                    ? array_map('sanitize_text_field', wp_unslash($_POST['sbdp_combi_label']))
                    : array();
                
                $explicit_label = $form_combi_labels[$combi_id] ?? $form_combi_labels[(string) $combi_id] ?? '';
                
                // Fallback to singular form label if we only have one ID and array mapping failed
                if ($explicit_label === '' && count($combi_ids) === 1) {
                    $summaryCombiLabelRaw = isset($_POST['sbdp_summary_combi_label']) && !is_array($_POST['sbdp_summary_combi_label'])
                        ? sanitize_text_field(wp_unslash((string) $_POST['sbdp_summary_combi_label']))
                        : '';
                    $formCombiLabelRaw = isset($_POST['sbdp_combi_label']) && !is_array($_POST['sbdp_combi_label'])
                        ? sanitize_text_field(wp_unslash((string) $_POST['sbdp_combi_label']))
                        : '';
                    $explicit_label = $summaryCombiLabelRaw !== '' ? $summaryCombiLabelRaw : $formCombiLabelRaw;
                }

                $label_to_use = $explicit_label !== ''
                    ? $explicit_label
                    : (($activeCombiEntry['label'] ?? '') !== ''
                        ? sanitize_text_field((string) $activeCombiEntry['label'])
                        : ($combi_product instanceof \WC_Product ? $combi_product->get_name() : ''));
                $duration = isset($activeCombiEntry['durationMinutes'])
                    ? (int) $activeCombiEntry['durationMinutes']
                    : $this->getDurationMinutes($combi_id);

                $combi_items[] = [
                    'id'       => $combi_id,
                    'label'    => $label_to_use,
                    'timing'   => $timing,
                    'role'     => $timing === 'after' ? 'post' : 'pre',
                    'order'    => isset($activeCombiEntry['order']) ? max(0, (int) $activeCombiEntry['order']) : count($combi_items),
                    'duration' => $duration,
                    'durationMinutes' => $duration,
                ];
            }

            if ($planner_input === array()) {
                $planner_input = \SBDP_Planner_Domain_Service::normalize_input([
                    'product_id'   => $productId,
                    'date'         => $date,
                    'time'         => $time,
                    'participants' => $participants,
                    'resource_id'  => $resourceId,
                    'source'       => 'product_page_refresh',
                    'options'      => [
                        'combiItems' => $combi_items,
                    ],
                ]);
            }

            if ($plan_item === array()) {
                $evaluation = \SBDP_Planner_Domain_Service::evaluate_input($planner_input);
                if (isset($evaluation['planItem']) && is_array($evaluation['planItem'])) {
                    $plan_item = $evaluation['planItem'];
                }
            }

            if ($plan_item !== array()) {
                return $this->finalizeCartPayload(
                    array_merge(
                        $cartItemData,
                        \SBDP_Planner_Domain_Service::build_cart_payload_from_plan_item($plan_item, $planner_input)
                    ),
                    $productId
                );
            }
        }

        $combi_ids_raw = isset($_POST['sbdp_combi_ids']) ? (array) wp_unslash($_POST['sbdp_combi_ids']) : array();
        $combi_ids = array_values(array_filter(array_map('absint', $combi_ids_raw)));
        $combi_timing_map = isset($_POST['sbdp_combi_timing']) ? (array) wp_unslash($_POST['sbdp_combi_timing']) : array();

        $summaryCombi = isset($_POST['sbdp_summary_combi'])
            ? absint(wp_unslash($_POST['sbdp_summary_combi']))
            : 0;
        $formCombi = isset($_POST['sbdp_combi'])
            ? absint(wp_unslash($_POST['sbdp_combi']))
            : 0;
        $combi = $summaryCombi > 0 ? $summaryCombi : $formCombi;

        $summaryCombiLabel = isset($_POST['sbdp_summary_combi_label'])
            ? sanitize_text_field(wp_unslash((string) $_POST['sbdp_summary_combi_label']))
            : '';
        $formCombiLabel = isset($_POST['sbdp_combi_label']) && !is_array($_POST['sbdp_combi_label'])
            ? sanitize_text_field(wp_unslash((string) $_POST['sbdp_combi_label']))
            : '';
        $combi_label = $summaryCombiLabel !== '' ? $summaryCombiLabel : $formCombiLabel;

        if (empty($combi_ids) && $combi > 0) {
            $combi_ids = array($combi);
        }

        $start = $this->composeStartIso($date, $time);
        $durationMinutes = $this->getDurationMinutes($productId);
        $endTime = $this->composeEndTime($date, $time, $durationMinutes);
        $combi_entries = [];
        foreach ($combi_ids as $combi_id) {
            if ($combi_id <= 0) {
                continue;
            }
            $timing_raw = $combi_timing_map[$combi_id] ?? $combi_timing_map[(string) $combi_id] ?? 'before';
            $timing = $timing_raw === 'after' ? 'after' : 'before';
            $activeCombiEntry = $this->findPostedActiveCombi($activeCombis, $combi_id);
            $duration = isset($activeCombiEntry['durationMinutes'])
                ? (int) $activeCombiEntry['durationMinutes']
                : $this->getDurationMinutes($combi_id);
            $startTime = '';
            $endTimeCombi = '';
            if ($duration > 0 && $date && $time) {
                if ($timing === 'after' && $endTime) {
                    $startTime = $endTime;
                    $endTimeCombi = $this->composeEndTime($date, $startTime, $duration);
                } else {
                    $startTime = $this->composeStartWithOffset($date, $time, -$duration);
                    $endTimeCombi = $time;
                }
            }
            $combi_product = wc_get_product($combi_id);
            
            $form_combi_labels = isset($_POST['sbdp_combi_label']) && is_array($_POST['sbdp_combi_label'])
                ? array_map('sanitize_text_field', wp_unslash($_POST['sbdp_combi_label']))
                : array();
            $explicit_label_entry = $form_combi_labels[$combi_id] ?? $form_combi_labels[(string) $combi_id] ?? '';

            if ($explicit_label_entry === '' && count($combi_ids) === 1) {
                $explicit_label_entry = $combi_label; // use the singular sanitized variable resolved earlier in this function
            }

            $label = $explicit_label_entry !== ''
                ? $explicit_label_entry
                : (($activeCombiEntry['label'] ?? '') !== ''
                    ? sanitize_text_field((string) $activeCombiEntry['label'])
                    : ($combi_product instanceof \WC_Product ? $combi_product->get_name() : ''));

            $combi_entries[] = [
                'id'       => $combi_id,
                'label'    => $label,
                'timing'   => $timing,
                'role'     => $timing === 'after' ? 'post' : 'pre',
                'order'    => isset($activeCombiEntry['order']) ? max(0, (int) $activeCombiEntry['order']) : count($combi_entries),
                'duration' => $duration,
                'durationMinutes' => $duration,
                'start'    => $startTime,
                'end'      => $endTimeCombi,
            ];
        }

        $plannerInputCombiItems = isset($planner_input['options']) && is_array($planner_input['options'])
            ? ($planner_input['options']['combiItems'] ?? array())
            : array();
        if (empty($combi_entries) && ! empty($plannerInputCombiItems)) {
            foreach ($plannerInputCombiItems as $cItem) {
                $cId = (int) ($cItem['id'] ?? 0);
                if ($cId > 0) {
                    $combi_entries[] = [
                        'id' => $cId,
                        'label' => $cItem['label'] ?? '',
                        'timing' => $cItem['timing'] ?? 'before',
                        'role' => $cItem['role'] ?? (($cItem['timing'] ?? 'before') === 'after' ? 'post' : 'pre'),
                        'order' => isset($cItem['order']) ? max(0, (int) $cItem['order']) : count($combi_entries),
                        'duration' => $cItem['durationMinutes'] ?? 0,
                        'durationMinutes' => $cItem['durationMinutes'] ?? 0,
                        'start' => $cItem['timeslot']['start'] ?? '',
                        'end' => $cItem['timeslot']['end'] ?? '',
                    ];
                }
            }
            if (!empty($combi_entries)) {
                $combi_label = $combi_entries[0]['label'];
            }
        }

        $primary_combi = $combi_entries[0]['id'] ?? 0;
        $primary_label = $combi_entries[0]['label'] ?? $combi_label;
        $pricing = $this->quotePricing(
            $productId,
            $start,
            $participants,
            $resourceId,
            array(
                'channel' => 'product_page_refresh',
                'source'  => 'capture_cart_item',
            ),
            $combi_entries
        );
        $pricingSource = isset($pricing['pricing_source']) && is_string($pricing['pricing_source']) && $pricing['pricing_source'] !== ''
            ? $pricing['pricing_source']
            : 'capture_cart_item';
        $resourceLabel = $this->resolveResourceLabel($productId, $resourceId);
        $endIso = $this->composeEndIso($date, $time, $durationMinutes);

        $cartItemData['sbdp_summary'] = [
            'date'         => $date,
            'time'         => $time,
            'participants' => $participants,
            'resource_id'  => $resourceId,
            'start'        => $start,
            'duration'     => $durationMinutes,
            'end'          => $endTime,
            'combi_duration' => $combi_entries[0]['duration'] ?? 0,
            'combi_start'    => $combi_entries[0]['start'] ?? '',
            'combi_end'      => $combi_entries[0]['end'] ?? '',
            'pricing'      => $pricing,
            'combi'        => $primary_combi,
            'combi_label'  => $primary_label,
            'combi_timing' => $combi_entries[0]['timing'] ?? 'before',
            'combi_multi'  => $combi_entries,
        ];
        $cartItemData['sbdp_pricing'] = $pricing;
        $cartItemData['sbdp_date'] = $date;
        $cartItemData['sbdp_time'] = $time;
        $cartItemData['sbdp_participants'] = $participants;
        $cartItemData['sbdp_quantity'] = $participants;
        $cartItemData['sbdp_start'] = $start;
        if ($endIso !== '') {
            $cartItemData['sbdp_end'] = $endIso;
        }
        if ($resourceId > 0) {
            $cartItemData['sbdp_resource_id'] = $resourceId;
        }
        if ($resourceLabel !== '') {
            $cartItemData['sbdp_resource_label'] = $resourceLabel;
        }
        $cartItemData['sbdp_pricing_source'] = $pricingSource;
        $pricingForCompat = isset($cartItemData['sbdp_pricing']) && is_array($cartItemData['sbdp_pricing'])
            ? $cartItemData['sbdp_pricing']
            : array();
        $authoritativeTotal = isset($pricingForCompat['display_total'])
            ? (float) $pricingForCompat['display_total']
            : (isset($pricingForCompat['total']) ? (float) $pricingForCompat['total'] : 0.0);
        if ($authoritativeTotal > 0.0) {
            $cartItemData['sbdp_calculated_price'] = $authoritativeTotal;
            $cartItemData['sbdp_authoritative_total'] = $authoritativeTotal;
            $cartItemData['sbdp_total_kind'] = 'authoritative_total';
        } else {
            $unitForEstimate = isset($pricingForCompat['display_unit_price'])
                ? (float) $pricingForCompat['display_unit_price']
                : (isset($pricingForCompat['unit_price']) ? (float) $pricingForCompat['unit_price'] : 0.0);
            if ($unitForEstimate > 0.0) {
                $cartItemData['sbdp_estimated_total'] = round($unitForEstimate * $participants, 2);
                $cartItemData['sbdp_total_kind'] = 'estimated_total';
            }
        }
        $cartItemData['sbdp_meta'] = isset($cartItemData['sbdp_meta']) && is_array($cartItemData['sbdp_meta'])
            ? $cartItemData['sbdp_meta']
            : array();
        $cartItemData['sbdp_meta']['sbdp_canonical_participants'] = $participants;
        $cartItemData['sbdp_meta']['sbdp_participants'] = $participants;
        if ($plan_item !== array()) {
            $cartItemData['sbdp_plan_item'] = $plan_item;
        }
        if ($planner_input !== array()) {
            $cartItemData['sbdp_planner_input'] = $planner_input;
        }
        if (! empty($_POST['sbdp_plan_item_key'])) {
            $cartItemData['sbdp_plan_item_key'] = sanitize_text_field(wp_unslash((string) $_POST['sbdp_plan_item_key']));
        }

        return $this->finalizeCartPayload($cartItemData, $productId);
    }

    /**
     * @param mixed $passed
     * @param mixed $quantity
     * @param mixed $variationId
     * @param mixed $variations
     * @param mixed $cartItemData
     */
    public function validateCanonicalBookingTruth(
        $passed,
        int $productId,
        $quantity,
        $variationId = 0,
        $variations = array(),
        $cartItemData = array()
    ): bool {
        unset($quantity, $variations);

        if (! $passed || ! function_exists('wc_get_product')) {
            return (bool) $passed;
        }

        $product = wc_get_product($productId);
        if (! $this->isTargetProduct($product)) {
            return (bool) $passed;
        }

        $candidate = is_array($cartItemData) ? $cartItemData : array();
        $candidate = $this->captureCartItemData($candidate, $productId, (int) $variationId);
        $candidate = $this->finalizeCartPayload($candidate, $productId);

        $selection = $this->extractSelectionFromPayload($candidate, $productId);
        if ($selection === array()) {
            if (function_exists('wc_add_notice')) {
                wc_add_notice(
                    __('Deze activiteit mist datum, tijd of deelnemers en kan niet direct aan de winkelwagen worden toegevoegd.', 'sbdp'),
                    'error'
                );
            }

            return false;
        }

        $profile = $this->resolveSelectionBookingTruthProfile($candidate, $selection);
        if (($profile['status'] ?? '') === BookingTruthRuntimeService::CAPABILITY_STATUS_DIRECT) {
            return true;
        }

        if (function_exists('wc_add_notice')) {
            wc_add_notice(
                __('Deze selectie kan niet direct worden afgerekend. Kies een geldig tijdslot of vraag een offerte aan.', 'sbdp'),
                'error'
            );
        }

        return false;
    }

    public function syncCartQuantity($cartItemKey, $productId, $quantity, $variationId, $variation, $cartItemData): void // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
    {
        if (! function_exists('WC') || ! WC()->cart) {
            return;
        }

        $cart = WC()->cart;
        $item = $cart->get_cart_item($cartItemKey);
        if (! is_array($item)) {
            return;
        }

        $summary = isset($item['sbdp_summary']) && is_array($item['sbdp_summary']) ? $item['sbdp_summary'] : array();
        $participants = (int) ($summary['participants'] ?? 0);
        if ($participants <= 0) {
            return;
        }

        if ((int) $quantity !== $participants) {
            $cart->set_quantity($cartItemKey, $participants, true);
        }
    }

    /**
     * @param array<int, array<string,string>> $cartData
     * @return array<int, array<string,string>>
     */
    public function exposeCartItemData(array $cartData, array $cartItem): array
    {
        if (! isset($cartItem['sbdp_summary']) || ! is_array($cartItem['sbdp_summary'])) {
            return $cartData;
        }

        $summary = $cartItem['sbdp_summary'];
        $currentPricing = isset($cartItem['sbdp_pricing']) && is_array($cartItem['sbdp_pricing'])
            ? $cartItem['sbdp_pricing']
            : (isset($summary['pricing']) && is_array($summary['pricing']) ? $summary['pricing'] : array());
        $aggregate = isset($cartItem['sbdp_plan_aggregate']) && is_array($cartItem['sbdp_plan_aggregate'])
            ? $cartItem['sbdp_plan_aggregate']
            : (isset($cartItem['sbdp_plan_item']['aggregate']) && is_array($cartItem['sbdp_plan_item']['aggregate'])
                ? $cartItem['sbdp_plan_item']['aggregate']
                : array());
        $primarySelection = $this->extractCartSelection($cartItem);

        if ($aggregate !== array()) {
            $timeline = isset($aggregate['timeline']) && is_array($aggregate['timeline']) ? $aggregate['timeline'] : array();
            $aggregatePricing = isset($aggregate['pricing']) && is_array($aggregate['pricing']) ? $aggregate['pricing'] : array();
            $segments = $this->extractDisplayProgramSegments($cartItem);
            $currentSegment = $this->resolveCurrentDisplayProgramSegment($cartItem, $segments);
            $productName = isset($cartItem['data']) && $cartItem['data'] instanceof \WC_Product
                ? trim((string) $cartItem['data']->get_name())
                : '';
            $primaryDate = (string) ($primarySelection['date'] ?? ($summary['date'] ?? ($aggregate['date'] ?? '')));
            $primaryTime = (string) ($primarySelection['time'] ?? ($summary['time'] ?? ''));
            $primaryEnd = (string) ($primarySelection['display_end'] ?? ($summary['end'] ?? ''));
            $segmentTime = trim(((string) ($timeline['startTime'] ?? '')) . ' - ' . ((string) ($timeline['endTime'] ?? '')), ' -');
            if ($segmentTime === '' && $currentSegment !== array()) {
                $segmentTime = trim(((string) ($currentSegment['startTime'] ?? '')) . ' - ' . ((string) ($currentSegment['endTime'] ?? '')), ' -');
            }
            $segmentRole = $currentSegment !== array()
                ? $this->formatProgramSegmentRole($currentSegment)
                : '';
            $segmentTitle = $currentSegment !== array() && isset($currentSegment['title'])
                ? trim((string) $currentSegment['title'])
                : '';
            if ($productName !== '' && ($segmentTitle === '' || strcasecmp($segmentTitle, $productName) !== 0)) {
                $segmentTitle = $productName;
            }
            $aggregateTitle = isset($timeline['title']) ? trim((string) $timeline['title']) : '';
            if ($aggregateTitle === '' && isset($aggregate['title'])) {
                $aggregateTitle = trim((string) $aggregate['title']);
            }

            if ($primaryDate !== '') {
                $cartData[] = [
                    'name'  => __('Datum', 'sbdp'),
                    'value' => $primaryDate,
                ];
            }

            if ($segmentTime !== '') {
                $cartData[] = [
                    'name'  => __('Tijd', 'sbdp'),
                    'value' => $segmentTime,
                ];
            }

            if ($segmentRole !== '') {
                $cartData[] = [
                    'name'  => __('Rol', 'sbdp'),
                    'value' => $segmentRole,
                ];
            }

            if ($segmentTitle !== '' && ($productName === '' || strcasecmp($segmentTitle, $productName) !== 0)) {
                $cartData[] = [
                    'name'  => __('Activiteit', 'sbdp'),
                    'value' => $segmentTitle,
                ];
            }

            $aggregatePlanning = trim(((string) ($timeline['startTime'] ?? '')) . ' - ' . ((string) ($timeline['endTime'] ?? '')), ' -');
            if ($aggregatePlanning === '' && $primaryTime !== '' && $primaryEnd !== '') {
                $aggregatePlanning = trim($primaryTime . ' - ' . $primaryEnd, ' -');
            }
            if ($aggregatePlanning !== '') {
                $cartData[] = [
                    'name'  => __('Programma', 'sbdp'),
                    'value' => $aggregatePlanning,
                ];
            }

            if ($segmentTitle !== '' && $aggregateTitle !== '' && strcasecmp($segmentTitle, $aggregateTitle) !== 0) {
                $cartData[] = [
                    'name'  => __('Arrangement', 'sbdp'),
                    'value' => $aggregateTitle,
                ];
            }

            if ($currentSegment !== array() && (($currentSegment['role'] ?? '') === 'anchor') && count($segments) > 1) {
                $summaryCombiItems = isset($summary['combi_multi']) && is_array($summary['combi_multi'])
                    ? $summary['combi_multi']
                    : array();
                if ($summaryCombiItems !== array()) {
                    foreach ($summaryCombiItems as $entry) {
                        if (! is_array($entry)) {
                            continue;
                        }

                        $entryStart = isset($entry['start']) ? trim((string) $entry['start']) : '';
                        $entryEnd = isset($entry['end']) ? trim((string) $entry['end']) : '';
                        $entryLabel = isset($entry['label']) ? trim((string) $entry['label']) : '';
                        if ($entryLabel === '') {
                            continue;
                        }

                        $entryRole = (($entry['timing'] ?? 'before') === 'after')
                            ? (string) __('Achteraf', 'sbdp')
                            : (string) __('Vooraf', 'sbdp');
                        $valueParts = array_values(array_filter([
                            trim($entryStart . ' - ' . $entryEnd, ' -'),
                            $entryRole,
                            $entryLabel,
                        ], static fn ($value): bool => $value !== ''));
                        if ($valueParts === array()) {
                            continue;
                        }

                        $cartData[] = [
                            'name'  => __('Programma onderdeel', 'sbdp'),
                            'value' => implode(' · ', $valueParts),
                        ];
                    }
                } else {
                    foreach ($segments as $segment) {
                        if (! is_array($segment)) {
                            continue;
                        }

                        $segmentRoleValue = isset($segment['role']) ? trim((string) $segment['role']) : '';
                        if ($segmentRoleValue === 'anchor') {
                            continue;
                        }

                        $cartData[] = [
                            'name'  => __('Programma onderdeel', 'sbdp'),
                            'value' => $this->formatProgramSegmentLabel($segment),
                        ];
                    }
                }
            }

            if (! empty($summary['participants'])) {
                $cartData[] = [
                    'name'  => __('Deelnemers', 'sbdp'),
                    'value' => (string) $summary['participants'],
                ];
            }

            $aggregateDisplayTotal = isset($aggregatePricing['display_total'])
                ? (float) $aggregatePricing['display_total']
                : (float) ($aggregatePricing['total'] ?? 0.0);
            if ($aggregateDisplayTotal > 0.0) {
                $cartData[] = [
                    'name'  => __('Arrangement totaal', 'sbdp'),
                    'value' => function_exists('wc_price') ? wp_strip_all_tags(wc_price($aggregateDisplayTotal)) : number_format_i18n($aggregateDisplayTotal, 2),
                ];
            }

            return $cartData;
        }

        if (! empty($summary['date'])) {
            $cartData[] = [
                'name'  => __('Datum', 'sbdp'),
                'value' => $summary['date'],
            ];
        }

        if (! empty($summary['time'])) {
            $cartData[] = [
                'name'  => __('Tijd', 'sbdp'),
                'value' => $summary['time'],
            ];
        }

        if (! empty($summary['duration'])) {
            $cartData[] = [
                'name'  => __('Planning', 'sbdp'),
                'value' => sprintf(
                    /* translators: 1: date, 2: start time, 3: end time */
                    __('Datum: %1$s - Tijd: %2$s - %3$s uur', 'sbdp'),
                    $summary['date'] ?? '',
                    $summary['time'] ?? '',
                    $summary['end'] ?? ''
                ),
            ];
        }

        if (! empty($summary['combi_multi']) && is_array($summary['combi_multi'])) {
            foreach ($summary['combi_multi'] as $entry) {
                if (empty($entry['id']) || empty($entry['end'])) {
                    continue;
                }
                $cartData[] = [
                    'name'  => __('Combi planning', 'sbdp'),
                    'value' => sprintf(
                        /* translators: 1: label, 2: date, 3: start time, 4: end time */
                        __('%1$s - Datum: %2$s - Tijd: %3$s - %4$s uur', 'sbdp'),
                        $entry['label'] ?? '',
                        $summary['date'] ?? '',
                        $entry['start'] ?? ($summary['time'] ?? ''),
                        $entry['end'] ?? ''
                    ),
                ];
                $cartData[] = [
                    'name'  => __('Combi timing', 'sbdp'),
                    'value' => ($entry['timing'] ?? 'before') === 'after'
                        ? __('Achteraf', 'sbdp')
                        : __('Vooraf', 'sbdp'),
                ];
            }
        }

        $pricing = array();
        if (isset($cartItem['sbdp_pricing']) && is_array($cartItem['sbdp_pricing'])) {
            $pricing = $cartItem['sbdp_pricing'];
        } elseif (isset($summary['pricing']) && is_array($summary['pricing'])) {
            $pricing = $summary['pricing'];
        }
        if (! empty($pricing['combi_multi']) && is_array($pricing['combi_multi'])) {
            foreach ($pricing['combi_multi'] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $label = isset($entry['label']) ? (string) $entry['label'] : '';
                $unit = isset($entry['display_unit_price']) ? (float) $entry['display_unit_price'] : (isset($entry['unit_price']) ? (float) $entry['unit_price'] : 0.0);
                $total = isset($entry['display_total']) ? (float) $entry['display_total'] : (isset($entry['total']) ? (float) $entry['total'] : 0.0);
                if ($unit <= 0.0 && $total <= 0.0) {
                    continue;
                }
                $unitLabel = function_exists('wc_price') ? wc_price($unit) : number_format_i18n($unit, 2);
                $totalLabel = function_exists('wc_price') ? wc_price($total) : number_format_i18n($total, 2);
                $value = $label !== '' ? $label . ' - ' : '';
                $value .= $unitLabel;
                if ($total > 0.0) {
                    $value .= ' (' . $totalLabel . ')';
                }
                $cartData[] = [
                    'name'  => __('Combi prijs', 'sbdp'),
                    'value' => wp_strip_all_tags($value),
                ];
            }
        }

        if (! empty($summary['participants'])) {
            $cartData[] = [
                'name'  => __('Deelnemers', 'sbdp'),
                'value' => (string) $summary['participants'],
            ];
        }

        if (! empty($summary['combi_multi']) && is_array($summary['combi_multi'])) {
            $labels = array();
            foreach ($summary['combi_multi'] as $entry) {
                if (! empty($entry['label'])) {
                    $labels[] = $entry['label'];
                }
            }
            if ($labels) {
                $cartData[] = [
                    'name'  => __('Combi-deal', 'sbdp'),
                    'value' => implode(', ', $labels),
                ];
            }
        } elseif (! empty($summary['combi_label']) || ! empty($summary['combi'])) {
            $label = $summary['combi_label'] ?? '';
            if ($label === '') {
                $label = (string) $summary['combi'];
            }
            $cartData[] = [
                'name'  => __('Combi-deal', 'sbdp'),
                'value' => $label,
            ];
        }

        return $cartData;
    }

    /**
     * @param array<string, mixed> $cartItem
     * @return array<int, array<string, mixed>>
     */
    private function extractDisplayProgramSegments(array $cartItem): array
    {
        $planItem = isset($cartItem['sbdp_plan_item']) && is_array($cartItem['sbdp_plan_item'])
            ? $cartItem['sbdp_plan_item']
            : array();
        $aggregate = isset($cartItem['sbdp_plan_aggregate']) && is_array($cartItem['sbdp_plan_aggregate'])
            ? $cartItem['sbdp_plan_aggregate']
            : (isset($planItem['aggregate']) && is_array($planItem['aggregate']) ? $planItem['aggregate'] : array());
        $bookingResolution = isset($planItem['bookingResolution']) && is_array($planItem['bookingResolution'])
            ? $planItem['bookingResolution']
            : array();

        $candidates = array(
            isset($bookingResolution['confirmedSegments']) && is_array($bookingResolution['confirmedSegments'])
                ? $bookingResolution['confirmedSegments']
                : null,
            isset($bookingResolution['segments']) && is_array($bookingResolution['segments'])
                ? $bookingResolution['segments']
                : null,
            isset($aggregate['segments']) && is_array($aggregate['segments'])
                ? $aggregate['segments']
                : null,
        );

        foreach ($candidates as $segments) {
            if (! is_array($segments) || $segments === array()) {
                continue;
            }

            $normalized = array();
            foreach ($segments as $segment) {
                if (! is_array($segment)) {
                    continue;
                }

                $start = $this->extractDisplayTime((string) ($segment['startTime'] ?? $segment['start'] ?? ''));
                $end = $this->extractDisplayTime((string) ($segment['endTime'] ?? $segment['end'] ?? ''));
                $title = isset($segment['title']) ? trim((string) $segment['title']) : '';
                if ($start === '' && $end === '' && $title === '') {
                    continue;
                }

                $segmentProductId = isset($segment['productId'])
                    ? (int) $segment['productId']
                    : (isset($segment['product_id']) ? (int) $segment['product_id'] : 0);
                $segmentTiming = isset($segment['timing']) ? trim((string) $segment['timing']) : '';
                $segmentKind = isset($segment['kind']) ? trim((string) $segment['kind']) : '';
                $segmentRole = isset($segment['role']) ? trim((string) $segment['role']) : '';
                if ($segmentRole === '') {
                    if ($segmentKind === 'anchor' || $segmentTiming === 'anchor') {
                        $segmentRole = 'anchor';
                    } elseif ($segmentTiming === 'after') {
                        $segmentRole = 'post';
                    } else {
                        $segmentRole = 'pre';
                    }
                }

                $segment['product_id'] = $segmentProductId;
                $segment['startTime'] = $start;
                $segment['endTime'] = $end;
                $segment['title'] = $title;
                $segment['role'] = $segmentRole;
                $normalized[] = $segment;
            }

            if ($normalized !== array()) {
                usort(
                    $normalized,
                    static function (array $left, array $right): int {
                        $leftStart = (string) ($left['startTime'] ?? '');
                        $rightStart = (string) ($right['startTime'] ?? '');
                        if ($leftStart !== $rightStart) {
                            return strcmp($leftStart, $rightStart);
                        }

                        $leftEnd = (string) ($left['endTime'] ?? '');
                        $rightEnd = (string) ($right['endTime'] ?? '');

                        return strcmp($leftEnd, $rightEnd);
                    }
                );

                return $normalized;
            }
        }

        return array();
    }

    /**
     * @param array<string, mixed> $segment
     */
    private function formatProgramSegmentLabel(array $segment): string
    {
        $start = isset($segment['startTime']) ? trim((string) $segment['startTime']) : '';
        $end = isset($segment['endTime']) ? trim((string) $segment['endTime']) : '';
        $title = isset($segment['title']) ? trim((string) $segment['title']) : '';
        $timeLabel = trim($start . ' - ' . $end, ' -');
        $roleLabel = $this->formatProgramSegmentRole($segment);

        $parts = array_values(array_filter([$timeLabel, $roleLabel, $title], static fn ($value): bool => $value !== ''));

        return implode(' · ', $parts);
    }

    /**
     * @param array<string, mixed> $segment
     */
    private function formatProgramSegmentRole(array $segment): string
    {
        $role = isset($segment['role']) ? trim((string) $segment['role']) : '';
        if ($role === 'pre') {
            return (string) __('Vooraf', 'sbdp');
        }
        if ($role === 'post') {
            return (string) __('Achteraf', 'sbdp');
        }

        return (string) __('Hoofdactiviteit', 'sbdp');
    }

    /**
     * @param array<string, mixed> $cartItem
     * @param array<int, array<string, mixed>> $segments
     * @return array<string, mixed>
     */
    private function resolveCurrentDisplayProgramSegment(array $cartItem, array $segments): array
    {
        if ($segments === array()) {
            return array();
        }

        $productId = 0;
        if (isset($cartItem['product_id'])) {
            $productId = (int) $cartItem['product_id'];
        } elseif (isset($cartItem['data']) && $cartItem['data'] instanceof \WC_Product) {
            $productId = (int) $cartItem['data']->get_id();
        }

        $planItem = isset($cartItem['sbdp_plan_item']) && is_array($cartItem['sbdp_plan_item'])
            ? $cartItem['sbdp_plan_item']
            : array();
        $expectedRole = isset($planItem['role']) ? trim((string) $planItem['role']) : '';
        $expectedTitle = isset($planItem['title']) ? trim((string) $planItem['title']) : '';

        foreach ($segments as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            $segmentProductId = isset($segment['product_id'])
                ? (int) $segment['product_id']
                : (isset($segment['productId']) ? (int) $segment['productId'] : 0);
            $segmentRole = isset($segment['role']) ? trim((string) $segment['role']) : '';
            $segmentTitle = isset($segment['title']) ? trim((string) $segment['title']) : '';

            if ($productId > 0 && $segmentProductId !== $productId) {
                continue;
            }
            if ($expectedRole !== '' && $segmentRole !== '' && $segmentRole !== $expectedRole) {
                continue;
            }
            if ($expectedTitle !== '' && $segmentTitle !== '' && strcasecmp($segmentTitle, $expectedTitle) !== 0) {
                continue;
            }

            return $segment;
        }

        foreach ($segments as $segment) {
            if (! is_array($segment)) {
                continue;
            }
            if ($productId > 0 && isset($segment['product_id']) && (int) $segment['product_id'] === $productId) {
                return $segment;
            }
        }

        return $segments[0];
    }

    /**
     * @param string $priceHtml
     * @param array<string,mixed> $cartItem
     */
    public function filterCartItemPriceHtml(string $priceHtml, array $cartItem, string $cartItemKey): string // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
    {
        $selection = $this->extractCartSelection($cartItem);
        $hasStructuredPricing = isset($cartItem['sbdp_pricing']) && is_array($cartItem['sbdp_pricing']);
        if ($selection === array() && ! $hasStructuredPricing) {
            return $priceHtml;
        }

        $pricing = isset($cartItem['sbdp_pricing']) && is_array($cartItem['sbdp_pricing'])
            ? $cartItem['sbdp_pricing']
            : (isset($cartItem['sbdp_summary']['pricing']) && is_array($cartItem['sbdp_summary']['pricing'])
                ? $cartItem['sbdp_summary']['pricing']
                : array());
        $displayUnit = isset($pricing['display_unit_price']) ? (float) $pricing['display_unit_price'] : 0.0;

        if ($displayUnit > 0.0 && function_exists('wc_price')) {
            return wp_strip_all_tags(wc_price($displayUnit));
        }

        $product = isset($cartItem['data']) && $cartItem['data'] instanceof \WC_Product ? $cartItem['data'] : null;
        if (! $product) {
            return $priceHtml;
        }

        return $this->formatCartDisplayAmount($product, 1, $priceHtml);
    }

    /**
     * @param string $subtotalHtml
     * @param array<string,mixed> $cartItem
     */
    public function filterCartItemSubtotalHtml(string $subtotalHtml, array $cartItem, string $cartItemKey): string // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
    {
        $selection = $this->extractCartSelection($cartItem);
        $hasStructuredPricing = isset($cartItem['sbdp_pricing']) && is_array($cartItem['sbdp_pricing']);
        if ($selection === array() && ! $hasStructuredPricing) {
            return $subtotalHtml;
        }

        $pricing = isset($cartItem['sbdp_pricing']) && is_array($cartItem['sbdp_pricing'])
            ? $cartItem['sbdp_pricing']
            : (isset($cartItem['sbdp_summary']['pricing']) && is_array($cartItem['sbdp_summary']['pricing'])
                ? $cartItem['sbdp_summary']['pricing']
                : array());
        $displayTotal = isset($pricing['display_total']) ? (float) $pricing['display_total'] : 0.0;

        if ($displayTotal <= 0.0) {
            $displayUnit = isset($pricing['display_unit_price']) ? (float) $pricing['display_unit_price'] : 0.0;
            $quantity = max(1, (int) ($cartItem['quantity'] ?? ($selection['participants'] ?? 1)));
            if ($displayUnit > 0.0) {
                $estimatedTotal = round($displayUnit * $quantity, 2);
                if ($estimatedTotal > 0.0 && function_exists('wc_price')) {
                    return wp_strip_all_tags(wc_price($estimatedTotal)) . ' ' . __('(indicatie)', 'sbdp');
                }
            }
        }

        if ($displayTotal > 0.0 && function_exists('wc_price')) {
            return wp_strip_all_tags(wc_price($displayTotal));
        }

        $product = isset($cartItem['data']) && $cartItem['data'] instanceof \WC_Product ? $cartItem['data'] : null;
        if (! $product) {
            return $subtotalHtml;
        }

        $quantity = max(1, (int) ($cartItem['quantity'] ?? ($selection['participants'] ?? 1)));

        return $this->formatCartDisplayAmount($product, $quantity, $subtotalHtml);
    }

    private function formatCartDisplayAmount(\WC_Product $product, int $quantity, string $fallbackHtml): string
    {
        $quantity = max(1, $quantity);

        if (function_exists('wc_get_price_to_display')) {
            $displayAmount = (float) wc_get_price_to_display(
                $product,
                array(
                    'qty' => $quantity,
                    'price' => (float) $product->get_price(),
                    'display_context' => 'cart',
                )
            );

            if ($displayAmount > 0.0 && function_exists('wc_price')) {
                return wp_strip_all_tags(wc_price($displayAmount));
            }
        }

        $rawPrice = (float) $product->get_price();
        if ($rawPrice > 0.0 && function_exists('wc_price')) {
            return wp_strip_all_tags(wc_price($rawPrice * $quantity));
        }

        return $fallbackHtml;
    }

    private function getDurationMinutes(int $productId): int
    {
        if (class_exists(\SBDP\Core\ProductSettings::class)) {
            try {
                $settings = \SBDP\Core\ProductSettings::get($productId);
                $duration = (int) ($settings['duration_minutes'] ?? 0);
                if ($duration > 0) {
                    return $duration;
                }
            } catch (\Throwable $exception) {
                // Fall through to meta-based fallback.
            }
        }

        $duration = (int) get_post_meta($productId, '_sbdp_duration', true);
        if ($duration <= 0) {
            return 0;
        }
        $unit = (string) get_post_meta($productId, '_sbdp_duration_unit', true);
        $unit = strtolower($unit);
        if (in_array($unit, ['hour', 'hours', 'uur', 'uren'], true)) {
            return $duration * 60;
        }
        if (in_array($unit, ['day', 'days', 'dag', 'dagen'], true)) {
            return $duration * 1440;
        }
        return $duration;
    }

    private function composeEndTime(?string $date, ?string $time, int $durationMinutes): string
    {
        if (! $date || ! $time || $durationMinutes <= 0) {
            return '';
        }

        try {
            $timezone = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
            $start = new \DateTimeImmutable($date . ' ' . $time, $timezone);
            $end = $start->add(new \DateInterval('PT' . $durationMinutes . 'M'));
            return $end->format('H:i');
        } catch (\Throwable $exception) {
            return '';
        }
    }

    private function composeEndIso(?string $date, ?string $time, int $durationMinutes): string
    {
        if (! $date || ! $time || $durationMinutes <= 0) {
            return '';
        }

        try {
            $timezone = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
            $start = new \DateTimeImmutable($date . ' ' . $time, $timezone);
            $end = $start->add(new \DateInterval('PT' . $durationMinutes . 'M'));
            return $end->format('Y-m-d\TH:i:s');
        } catch (\Throwable $exception) {
            return '';
        }
    }

    private function composeStartWithOffset(?string $date, ?string $time, int $offsetMinutes): string
    {
        if (! $date || ! $time) {
            return '';
        }

        try {
            $timezone = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
            $start = new \DateTimeImmutable($date . ' ' . $time, $timezone);
            $end = $start->add(new \DateInterval('PT' . abs($offsetMinutes) . 'M'));
            if ($offsetMinutes < 0) {
                $end = $start->sub(new \DateInterval('PT' . abs($offsetMinutes) . 'M'));
            }
            return $end->format('H:i');
        } catch (\Throwable $exception) {
            return '';
        }
    }

    private function resolveResourceLabel(int $productId, int $resourceId): string
    {
        if ($resourceId <= 0) {
            return '';
        }

        $payload = ProductMeta::get_resources_payload($productId);
        foreach ($payload as $entry) {
            if ((int) ($entry['id'] ?? 0) !== $resourceId) {
                continue;
            }

            $title = isset($entry['title']) ? sanitize_text_field((string) $entry['title']) : '';
            if ($title !== '') {
                return $title;
            }
        }

        $fallback = get_the_title($resourceId);

        return is_string($fallback) ? sanitize_text_field($fallback) : '';
    }

    /**
     * @param \WC_Order_Item $item
     * @param string         $cartItemKey
     * @param array<string,mixed> $values
     * @param \WC_Order      $order
     */
    public function persistOrderItemMeta($item, $cartItemKey, $values, $order): void // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
    {
        unset($cartItemKey, $order);

        if (! isset($values['sbdp_summary']) || ! is_array($values['sbdp_summary'])) {
            return;
        }

        $summary = $values['sbdp_summary'];
        $pricing = isset($values['sbdp_pricing']) && is_array($values['sbdp_pricing']) ? $values['sbdp_pricing'] : array();
        $aggregate = isset($values['sbdp_plan_aggregate']) && is_array($values['sbdp_plan_aggregate'])
            ? $values['sbdp_plan_aggregate']
            : (isset($values['sbdp_plan_item']['aggregate']) && is_array($values['sbdp_plan_item']['aggregate'])
                ? $values['sbdp_plan_item']['aggregate']
                : array());
        $selection = $this->derivePrimarySelection(
            $summary,
            isset($values['sbdp_meta']) && is_array($values['sbdp_meta']) ? $values['sbdp_meta'] : array(),
            isset($values['sbdp_plan_item']) && is_array($values['sbdp_plan_item']) ? $values['sbdp_plan_item'] : array(),
            isset($values['sbdp_planner_input']) && is_array($values['sbdp_planner_input']) ? $values['sbdp_planner_input'] : array(),
            isset($values['quantity']) ? (int) $values['quantity'] : 0
        );
        $productId = is_object($item) && method_exists($item, 'get_product_id') ? (int) $item->get_product_id() : 0;
        $resourceId = (int) ($selection['resource_id'] ?? ($summary['resource_id'] ?? ($values['sbdp_resource_id'] ?? 0)));
        $resourceLabel = $this->resolveResourceLabel($productId, $resourceId);
        $startIso = isset($selection['start']) && $selection['start'] !== ''
            ? (string) $selection['start']
            : (! empty($summary['start'])
                ? (string) $summary['start']
                : $this->composeStartIso((string) ($summary['date'] ?? ''), (string) ($summary['time'] ?? '')));
        $endIso = isset($selection['end_iso']) && $selection['end_iso'] !== ''
            ? (string) $selection['end_iso']
            : $this->composeEndIso(
                (string) ($summary['date'] ?? ''),
                (string) ($summary['time'] ?? ''),
                (int) ($summary['duration'] ?? 0)
            );

        if ($startIso !== '') {
            $item->add_meta_data('sbdp_start', $startIso, true);
        }
        if ($endIso !== '') {
            $item->add_meta_data('sbdp_end', $endIso, true);
        }

        $dateValue = isset($summary['date']) ? sanitize_text_field((string) $summary['date']) : '';
        if ($dateValue !== '') {
            $item->add_meta_data('sbdp_date', $dateValue, true);
        }

        $timeValue = '';
        if (isset($summary['time']) && (string) $summary['time'] !== '') {
            $timeValue = sanitize_text_field((string) $summary['time']);
        } elseif (isset($selection['time']) && (string) $selection['time'] !== '') {
            $timeValue = sanitize_text_field((string) $selection['time']);
        } elseif ($startIso !== '') {
            $timeValue = substr($startIso, 11, 5);
        }
        if ($timeValue !== '') {
            $item->add_meta_data('sbdp_time', $timeValue, true);
        }

        if (! empty($selection['participants'])) {
            $item->add_meta_data('sbdp_participants', (int) $selection['participants'], true);
            $item->add_meta_data('sbdp_canonical_participants', (int) $selection['participants'], true);
        } elseif (! empty($summary['participants'])) {
            $item->add_meta_data('sbdp_participants', (int) $summary['participants'], true);
            $item->add_meta_data('sbdp_canonical_participants', (int) $summary['participants'], true);
        }
        if (! empty($values['sbdp_route_intent'])) {
            $item->add_meta_data('sbdp_route_intent', sanitize_text_field((string) $values['sbdp_route_intent']), true);
        } elseif (isset($values['sbdp_meta']) && is_array($values['sbdp_meta']) && ! empty($values['sbdp_meta']['sbdp_route_intent'])) {
            $item->add_meta_data('sbdp_route_intent', sanitize_text_field((string) $values['sbdp_meta']['sbdp_route_intent']), true);
        }
        if (! empty($values['sbdp_booking_capability'])) {
            $item->add_meta_data('sbdp_booking_capability', sanitize_text_field((string) $values['sbdp_booking_capability']), true);
        } elseif (isset($values['sbdp_meta']) && is_array($values['sbdp_meta']) && ! empty($values['sbdp_meta']['sbdp_booking_capability'])) {
            $item->add_meta_data('sbdp_booking_capability', sanitize_text_field((string) $values['sbdp_meta']['sbdp_booking_capability']), true);
        }
        if ($resourceId > 0) {
            $item->add_meta_data('sbdp_resource_id', $resourceId, true);
        }
        if ($resourceLabel !== '') {
            $item->add_meta_data('sbdp_resource_label', $resourceLabel, true);
        }
        if (! empty($values['sbdp_pricing_source'])) {
            $item->add_meta_data('sbdp_pricing_source', sanitize_text_field((string) $values['sbdp_pricing_source']), true);
        }

        $displayUnit = isset($pricing['display_unit_price']) ? (float) $pricing['display_unit_price'] : 0.0;
        $displayTotal = isset($pricing['display_total']) ? (float) $pricing['display_total'] : 0.0;
        if ($displayUnit > 0.0) {
            $item->add_meta_data('sbdp_display_unit_price', round($displayUnit, 2), true);
        }
        if ($displayTotal > 0.0) {
            $item->add_meta_data('sbdp_display_total', round($displayTotal, 2), true);
        }

        if (! empty($summary['combi'])) {
            $item->add_meta_data('sbdp_combi', (int) $summary['combi']);
        }

        if (! empty($summary['combi_multi']) && is_array($summary['combi_multi'])) {
            $labels = array();
            foreach ($summary['combi_multi'] as $entry) {
                if (! empty($entry['label'])) {
                    $labels[] = $entry['label'] . ' (' . (($entry['timing'] ?? 'before') === 'after' ? __('Achteraf', 'sbdp') : __('Vooraf', 'sbdp')) . ')';
                }
            }
            if ($labels) {
                $item->add_meta_data(__('Combi-deals', 'sbdp'), implode(', ', $labels));
            }
        } elseif (! empty($summary['combi_label'])) {
            $item->add_meta_data(__('Combi-deal', 'sbdp'), (string) $summary['combi_label']);
        }

        if ($pricing !== array()) {
            $item->add_meta_data('_sbdp_pricing', $pricing);
        }
        if ($aggregate !== array()) {
            $item->add_meta_data('_sbdp_plan_aggregate', wp_json_encode($aggregate));
        }
        if (isset($values['sbdp_plan_item']) && is_array($values['sbdp_plan_item'])) {
            $item->add_meta_data('_sbdp_plan_item', wp_json_encode($values['sbdp_plan_item']));
        }
        if (isset($values['sbdp_planner_input']) && is_array($values['sbdp_planner_input'])) {
            $item->add_meta_data('_sbdp_planner_input', wp_json_encode($values['sbdp_planner_input']));
        }
        if (! empty($values['sbdp_plan_item_key'])) {
            $item->add_meta_data('_sbdp_plan_item_key', (string) $values['sbdp_plan_item_key']);
        }

        $this->persistReadableProgramMeta($item, $values);
    }

    /**
     * Persist readable program metadata so order/customer/admin surfaces show the
     * same program truth as checkout cart item details.
     *
     * @param \WC_Order_Item $item
     * @param array<string,mixed> $cartItem
     */
    private function persistReadableProgramMeta($item, array $cartItem): void
    {
        $displayData = $this->exposeCartItemData(array(), $cartItem);
        if ($displayData === array()) {
            return;
        }

        $allowedNames = array(
            (string) __('Programma', 'sbdp'),
            (string) __('Programma onderdeel', 'sbdp'),
            (string) __('Combi planning', 'sbdp'),
            (string) __('Combi timing', 'sbdp'),
        );

        $written = array();
        foreach ($displayData as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $name = isset($entry['name']) ? sanitize_text_field((string) $entry['name']) : '';
            $value = isset($entry['value']) ? sanitize_text_field((string) $entry['value']) : '';

            if ($name === '' || $value === '' || ! in_array($name, $allowedNames, true)) {
                continue;
            }

            $dedupeKey = $name . '|' . $value;
            if (isset($written[$dedupeKey])) {
                continue;
            }

            $written[$dedupeKey] = true;
            $item->add_meta_data($name, $value, false);
        }
    }

    public function handlePriceRequest(WP_REST_Request $request)
    {
        $productId = (int) $request->get_param('product_id');
        $date = $this->sanitizeDate((string) $request->get_param('date'));
        $time = $this->sanitizeTime((string) $request->get_param('time'));
        $participants = max(1, (int) $request->get_param('participants'));
        $resourceId = (int) $request->get_param('resource_id');
        $combiId = (int) $request->get_param('combi_id');

        if ($productId <= 0 || $date === null || $time === null) {
            return new \WP_Error(
                'sbdp_invalid_request',
                __('Ongeldige prijsaanvraag.', 'sbdp'),
                ['status' => 400]
            );
        }

        $product = wc_get_product($productId);
        if (! $product instanceof WC_Product) {
            return new \WP_Error(
                'sbdp_invalid_product',
                __('Product niet gevonden.', 'sbdp'),
                ['status' => 404]
            );
        }

        $start = sprintf('%sT%s:00', $date, $time);
        $combiItems = array();
        if ($combiId > 0) {
            $combiProduct = wc_get_product($combiId);
            $combiDuration = $this->getDurationMinutes($combiId);
            if ($combiDuration > 0) {
                $combiItems[] = [
                    'id'              => $combiId,
                    'label'           => $combiProduct instanceof WC_Product ? $combiProduct->get_name() : '',
                    'timing'          => 'before',
                    'role'            => 'pre',
                    'order'           => 0,
                    'duration'        => $combiDuration,
                    'durationMinutes' => $combiDuration,
                ];
            }
        }

        $pricing = $this->quotePricing(
            $product->get_id(),
            $start,
            $participants,
            $resourceId,
            [
                'channel' => 'product_page_preview',
                'source'  => 'product_page_refresh',
                'price_mode' => 'gross',
            ],
            $combiItems
        );

        $displayTotal = isset($pricing['display_total'])
            ? (float) $pricing['display_total']
            : (isset($pricing['total']) ? (float) $pricing['total'] : 0.0);
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR';

        $normalizedInput = array(
            'product_id'   => $productId,
            'date'         => $date,
            'time'         => $time,
            'participants' => $participants,
            'resource_id'  => $resourceId,
            'source'       => 'product-summary',
            'options'      => array(
                'combiItems' => $combiItems,
            ),
        );
        $planItem = array();

        if (class_exists('\SBDP_Planner_Domain_Service')) {
            $normalizedInput = \SBDP_Planner_Domain_Service::normalize_input($normalizedInput);
            $evaluation = \SBDP_Planner_Domain_Service::evaluate_input($normalizedInput);
            if (isset($evaluation['planItem']) && is_array($evaluation['planItem'])) {
                $planItem = $evaluation['planItem'];
            }
        }

        return [
            'product_id' => $productId,
            'resource_id'=> $resourceId,
            'total'      => $displayTotal,
            'display_total' => $displayTotal,
            'formatted'  => wc_price($displayTotal),
            'currency'   => $currency,
            'line_item'  => isset($pricing['line_item']) && is_array($pricing['line_item']) ? $pricing['line_item'] : array(),
            'pricing'    => $pricing,
            'adjustments'=> isset($pricing['adjustments']) && is_array($pricing['adjustments']) ? $pricing['adjustments'] : array(),
            'discounts'  => isset($pricing['discounts']) && is_array($pricing['discounts']) ? $pricing['discounts'] : array(),
            'taxes'      => isset($pricing['taxes']) && is_array($pricing['taxes']) ? $pricing['taxes'] : array(),
            'normalizedInput' => $normalizedInput,
            'planItem' => $planItem,
        ];
    }

    public function recalculateCartTotals($cart): void
    {
        if (! $cart || ! is_object($cart) || ! property_exists($cart, 'cart_contents')) {
            return;
        }

        foreach ($cart->get_cart() as $cartKey => $cartItem) {
            if ($this->hasStructuredCartSelection($cartItem)) {
                try {
                    $cart->cart_contents[$cartKey] = $this->projectCartItemPricing($cartItem);
                } catch (\Throwable $exception) {
                    // Leave the cart item unchanged rather than crashing the totals recalculation.
                    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                        error_log(sprintf('SBDP recalculateCartTotals: projectCartItemPricing failed for key %s: %s', $cartKey, $exception->getMessage()));
                    }
                }
                continue;
            }

            $product = isset($cartItem['data']) && $cartItem['data'] instanceof \WC_Product ? $cartItem['data'] : null;
            if (! $product) {
                continue;
            }

            $summary = isset($cartItem['sbdp_summary']) && is_array($cartItem['sbdp_summary'])
                ? $cartItem['sbdp_summary']
                : array();

            $planMeta = isset($cartItem['sbdp_meta']) && is_array($cartItem['sbdp_meta'])
                ? $cartItem['sbdp_meta']
                : array();

            $participants = $this->resolveCanonicalParticipants(
                $summary,
                $planMeta,
                isset($cartItem['sbdp_plan_item']) && is_array($cartItem['sbdp_plan_item']) ? $cartItem['sbdp_plan_item'] : array(),
                isset($cartItem['sbdp_planner_input']) && is_array($cartItem['sbdp_planner_input']) ? $cartItem['sbdp_planner_input'] : array(),
                isset($cartItem['quantity']) ? (int) $cartItem['quantity'] : 0
            );

            $start = (string) ($summary['start'] ?? $planMeta['sbdp_start'] ?? '');
            if ($start === '' && isset($summary['date'], $summary['time'])) {
                $start = $this->composeStartIso((string) $summary['date'], (string) $summary['time']);
            }

            $resourceId = (int) ($planMeta['sbdp_resource_id'] ?? 0);
            // Pass plan_item and planner_input so combi items survive session restores.
            $planItemForRecalc = isset($cartItem['sbdp_plan_item']) && is_array($cartItem['sbdp_plan_item']) ? $cartItem['sbdp_plan_item'] : array();
            $plannerInputForRecalc = isset($cartItem['sbdp_planner_input']) && is_array($cartItem['sbdp_planner_input']) ? $cartItem['sbdp_planner_input'] : array();
            $combiItems = $this->extractCartCombiItems($summary, $planItemForRecalc, $plannerInputForRecalc);
            $pricing = $this->quotePricing(
                $product->get_id(),
                $start,
                $participants,
                $resourceId,
                [
                    'channel' => isset($planMeta['sbdp_plan_id']) ? 'planner_checkout' : 'product_page_refresh',
                    'plan_id' => $planMeta['sbdp_plan_id'] ?? null,
                ],
                $combiItems
            );
            if ($pricing === array() && class_exists(\SBDP\Pricing\PricingService::class)) {
                try {
                    $pricing = \SBDP\Pricing\PricingService::instance()->quote(
                        $product->get_id(),
                        $participants,
                        [
                            'channel' => 'product_page_refresh',
                            'source'  => 'cart_recalc',
                            'price_mode' => 'gross',
                        ]
                    );
                } catch (\Throwable $exception) {
                    $pricing = array();
                }
            }
            $pricingSource = isset($pricing['pricing_source']) && is_string($pricing['pricing_source']) && $pricing['pricing_source'] !== ''
                ? $pricing['pricing_source']
                : 'cart_recalc';

            if ($pricing === array()) {
                $fallbackUnit = 0.0;
                // CSOT: _sbdp_base_price is the canonical incl-BTW price — always try before WC index price.
                $basePriceMeta = get_post_meta($product->get_id(), '_sbdp_base_price', true);
                if (is_numeric($basePriceMeta) && (float) $basePriceMeta > 0.0) {
                    $fallbackUnit = round((float) $basePriceMeta, 2);
                }
                if ($fallbackUnit <= 0.0) {
                    $fallbackUnit = function_exists('wc_get_price_including_tax')
                        ? (float) wc_get_price_including_tax($product, array('qty' => 1))
                        : (float) $product->get_price();
                    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG && $fallbackUnit > 0.0) {
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                        error_log(sprintf(
                            'SBDP CSOT WARNING: product %d has no _sbdp_base_price and PricingService returned empty. Falling back to WC price in recalculateCartTotals.',
                            $product->get_id()
                        ));
                    }
                }
                $pricing = [
                    'total' => $fallbackUnit > 0 ? ($fallbackUnit * $participants) : 0.0,
                    'unit_price' => $fallbackUnit,
                    'pricing_source' => 'woocommerce_taxed_fallback',
                ];
                $pricingSource = 'woocommerce_taxed_fallback';
            }

            $cartQty   = max(1, (int) ($cartItem['quantity'] ?? 1));
            $total     = isset($pricing['display_total']) ? (float) $pricing['display_total'] : (isset($pricing['total']) ? (float) $pricing['total'] : 0.0);
            $unitPrice = isset($pricing['display_unit_price']) ? (float) $pricing['display_unit_price'] : 0.0;

            if ($total > 0.0 && $participants > 0) {
                $unitPrice = round($total / $participants, 2);
            }

            if ($unitPrice <= 0.0 && isset($pricing['unit_price'])) {
                $unitPrice = (float) $pricing['unit_price'];
            }

            if ($unitPrice <= 0.0 && method_exists($product, 'get_price')) {
                $unitPrice = function_exists('wc_get_price_including_tax')
                    ? (float) wc_get_price_including_tax($product, array('qty' => 1))
                    : (float) $product->get_price();
            }

            $product->set_price($unitPrice);
            $cartItem['data'] = $product;
            $cartItem['sbdp_pricing'] = $pricing;
            $cartItem['sbdp_summary']['pricing'] = $pricing;
            $cartItem['sbdp_pricing_source'] = $pricingSource;

            $cart->cart_contents[ $cartKey ] = $cartItem;
        }
    }

    /**
     * @param array<string,mixed> $cartItem
     * @return array<string,mixed>
     */
    public function hydrateProjectedCartItem(array $cartItem, string $cartItemKey): array
    {
        unset($cartItemKey);

        return $this->projectCartItemPricing($cartItem);
    }

    /**
     * @param array<string,mixed> $sessionData
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    public function hydrateProjectedCartItemFromSession(array $sessionData, array $values, string $cartItemKey): array
    {
        unset($values, $cartItemKey);

        return $this->projectCartItemPricing($sessionData);
    }

    public function sortCartItemsChronologically($cart): void
    {
        if (
            ! is_object($cart)
            || ! property_exists($cart, 'cart_contents')
            || ! is_array($cart->cart_contents)
            || $cart->cart_contents === array()
        ) {
            return;
        }

        uasort($cart->cart_contents, function($a, $b) {
            $startA = '';
            if (isset($a['sbdp_start']) && (string) $a['sbdp_start'] !== '') {
                $startA = (string) $a['sbdp_start'];
            } elseif (isset($a['sbdp_date']) && (string) $a['sbdp_date'] !== '') {
                $startA = (string) $a['sbdp_date'];
                if (isset($a['sbdp_time']) && (string) $a['sbdp_time'] !== '') {
                    $startA .= ' ' . (string) $a['sbdp_time'];
                }
            }

            $startB = '';
            if (isset($b['sbdp_start']) && (string) $b['sbdp_start'] !== '') {
                $startB = (string) $b['sbdp_start'];
            } elseif (isset($b['sbdp_date']) && (string) $b['sbdp_date'] !== '') {
                $startB = (string) $b['sbdp_date'];
                if (isset($b['sbdp_time']) && (string) $b['sbdp_time'] !== '') {
                    $startB .= ' ' . (string) $b['sbdp_time'];
                }
            }

            if ($startA === '' && $startB === '') {
                return 0;
            }
            if ($startA === '') {
                return 1; // Put ones without time at the end
            }
            if ($startB === '') {
                return -1;
            }

            return strtotime($startA) <=> strtotime($startB);
        });
    }

    public function invalidateAggregateSessionTotals($cart): void
    {
        if (
            ! function_exists('WC')
            || ! WC()
            || ! WC()->session
            || ! $cart
            || ! is_object($cart)
            || ! method_exists($cart, 'get_cart')
        ) {
            return;
        }

        $shouldInvalidate = false;

        foreach ($cart->get_cart() as $cartItem) {
            if (! is_array($cartItem)) {
                continue;
            }

            $aggregate = $this->extractAggregateFromCartItem($cartItem);
            if ($aggregate === array()) {
                continue;
            }

            $aggregatePricing = isset($aggregate['pricing']) && is_array($aggregate['pricing']) ? $aggregate['pricing'] : array();
            $expectedGross = isset($aggregatePricing['total']) ? (float) $aggregatePricing['total'] : 0.0;
            $storedGross = (float) ($cartItem['line_total'] ?? 0.0) + (float) ($cartItem['line_tax'] ?? 0.0);

            if ($expectedGross > 0.0 && abs($expectedGross - $storedGross) > 0.01) {
                $shouldInvalidate = true;
                break;
            }
        }

        if ($shouldInvalidate) {
            WC()->session->set('cart_totals', null);
        }
    }

    /**
     * @param array<string,mixed> $cartItem
     * @return array<string,mixed>
     */
    private function projectCartItemPricing(array $cartItem): array
    {
        $product = isset($cartItem['data']) && $cartItem['data'] instanceof \WC_Product ? $cartItem['data'] : null;
        if (! $product) {
            return $cartItem;
        }

        $selection = $this->extractCartSelection($cartItem);
        if ($selection === array()) {
            return $cartItem;
        }

        $participants = max(1, (int) ($selection['participants'] ?? 1));
        $aggregate = $this->extractAggregateFromCartItem($cartItem);
        if ($aggregate !== array()) {
            $aggregatePricing = isset($aggregate['pricing']) && is_array($aggregate['pricing']) ? $aggregate['pricing'] : array();
            $pricing = $aggregatePricing !== array()
                ? $this->buildAggregatePricingProjection($aggregatePricing, $participants)
                : array();
            $pricingSource = 'aggregate_projection';
        } else {
            $pricing = $this->quotePricing(
                $product->get_id(),
                (string) ($selection['start'] ?? ''),
                $participants,
                (int) ($selection['resource_id'] ?? 0),
                array(
                    'channel' => isset($selection['plan_id']) ? 'planner_checkout' : 'product_page_refresh',
                    'source'  => 'cart_projection',
                    'plan_id' => $selection['plan_id'] ?? null,
                ),
                isset($selection['combi_multi']) && is_array($selection['combi_multi']) ? $selection['combi_multi'] : array()
            );
            if ($pricing === array()) {
                return $cartItem;
            }
            $pricingSource = isset($pricing['pricing_source']) && is_string($pricing['pricing_source']) && $pricing['pricing_source'] !== ''
                ? $pricing['pricing_source']
                : 'cart_projection';
        }

        $unitPrice = isset($pricing['display_unit_price']) ? (float) $pricing['display_unit_price'] : (isset($pricing['unit_price']) ? (float) $pricing['unit_price'] : 0.0);
        $totalPrice = isset($pricing['display_total']) ? (float) $pricing['display_total'] : (isset($pricing['total']) ? (float) $pricing['total'] : 0.0);

        if ($unitPrice > 0.0) {
            if (method_exists($product, 'update_meta_data')) {
                $product->update_meta_data('_sbdp_runtime_price_override', (string) $unitPrice);
                $product->update_meta_data('_sbdp_runtime_price_locked', 'yes');
            }
            $product->set_price((string) $unitPrice);
            if (method_exists($product, 'set_regular_price')) {
                $product->set_regular_price((string) $unitPrice);
            }
            $cartItem['data'] = $product;
        }

        $cartItem['sbdp_pricing'] = $pricing;
        $cartItem['sbdp_pricing_source'] = $pricingSource;
        $cartItem['sbdp_calculated_price'] = $totalPrice;
        $cartItem['sbdp_quantity'] = $participants;
        $cartItem['sbdp_participants'] = $participants;
        $cartItem['sbdp_authoritative_total'] = $totalPrice > 0.0 ? $totalPrice : 0.0;
        if ($totalPrice > 0.0) {
            $cartItem['sbdp_total_kind'] = 'authoritative_total';
        } else {
            $estimatedTotal = $unitPrice > 0.0 ? round($unitPrice * $participants, 2) : 0.0;
            if ($estimatedTotal > 0.0) {
                $cartItem['sbdp_estimated_total'] = $estimatedTotal;
                $cartItem['sbdp_total_kind'] = 'estimated_total';
            }
        }
        $cartItem['sbdp_resource_id'] = (int) ($selection['resource_id'] ?? 0);
        $cartItem['sbdp_start'] = (string) ($selection['start'] ?? '');
        if (! empty($selection['end_iso'])) {
            $cartItem['sbdp_end'] = (string) $selection['end_iso'];
        }
        $cartItem['sbdp_meta'] = isset($cartItem['sbdp_meta']) && is_array($cartItem['sbdp_meta'])
            ? $cartItem['sbdp_meta']
            : array();
        $cartItem['sbdp_meta']['sbdp_canonical_participants'] = $participants;
        $cartItem['sbdp_meta']['sbdp_participants'] = $participants;

        $cartItem['sbdp_summary'] = array_merge(
            isset($cartItem['sbdp_summary']) && is_array($cartItem['sbdp_summary']) ? $cartItem['sbdp_summary'] : array(),
            array(
                'date'         => $selection['date'] ?? '',
                'time'         => $selection['time'] ?? '',
                'participants' => $participants,
                'resource_id'  => (int) ($selection['resource_id'] ?? 0),
                'start'        => (string) ($selection['start'] ?? ''),
                'end'          => $selection['display_end'] ?? '',
                'pricing'      => $pricing,
                'combi_multi'  => isset($selection['combi_multi']) && is_array($selection['combi_multi']) ? $selection['combi_multi'] : array(),
            )
        );
        if (! empty($selection['duration'])) {
            $cartItem['sbdp_summary']['duration'] = (int) $selection['duration'];
        }

        return $cartItem;
    }

    private function hasStructuredCartSelection(array $cartItem): bool
    {
        return $this->extractCartSelection($cartItem) !== array();
    }

    /**
     * @param array<string, mixed> $cartItem
     * @return array<string, mixed>
     */
    private function extractCartSelection(array $cartItem): array
    {
        $product = isset($cartItem['data']) && $cartItem['data'] instanceof \WC_Product ? $cartItem['data'] : null;
        if (! $product) {
            return array();
        }

        $summary = isset($cartItem['sbdp_summary']) && is_array($cartItem['sbdp_summary'])
            ? $cartItem['sbdp_summary']
            : array();
        $planMeta = isset($cartItem['sbdp_meta']) && is_array($cartItem['sbdp_meta'])
            ? $cartItem['sbdp_meta']
            : array();
        $planItem = isset($cartItem['sbdp_plan_item']) && is_array($cartItem['sbdp_plan_item'])
            ? $cartItem['sbdp_plan_item']
            : array();
        $plannerInput = isset($cartItem['sbdp_planner_input']) && is_array($cartItem['sbdp_planner_input'])
            ? $cartItem['sbdp_planner_input']
            : array();
        $selection = $this->derivePrimarySelection(
            $summary,
            $planMeta,
            $planItem,
            $plannerInput,
            isset($cartItem['quantity']) ? (int) $cartItem['quantity'] : 0
        );
        if ($selection === array()) {
            return array();
        }

        return array(
            'product_id'   => $product->get_id(),
            'participants' => (int) ($selection['participants'] ?? 1),
            'resource_id'  => (int) ($selection['resource_id'] ?? 0),
            'date'         => (string) ($selection['date'] ?? ''),
            'time'         => (string) ($selection['time'] ?? ''),
            'start'        => (string) ($selection['start'] ?? ''),
            'end'          => (string) ($selection['end_iso'] ?? ''),
            'end_iso'      => (string) ($selection['end_iso'] ?? ''),
            'display_end'  => (string) ($selection['display_end'] ?? ''),
            'duration'     => (int) ($selection['duration'] ?? 0),
            'plan_id'      => $planMeta['sbdp_plan_id'] ?? null,
            'combi_multi'  => $this->extractCartCombiItems($summary, $planItem, $plannerInput),
        );
    }

    /**
     * @param array<string,mixed> $cartItemData
     * @return array<string,mixed>
     */
    private function normaliseStructuredCartPayload(array $cartItemData): array
    {
        $summary = isset($cartItemData['sbdp_summary']) && is_array($cartItemData['sbdp_summary'])
            ? $cartItemData['sbdp_summary']
            : array();
        $planMeta = isset($cartItemData['sbdp_meta']) && is_array($cartItemData['sbdp_meta'])
            ? $cartItemData['sbdp_meta']
            : array();
        $planItem = isset($cartItemData['sbdp_plan_item']) && is_array($cartItemData['sbdp_plan_item'])
            ? $cartItemData['sbdp_plan_item']
            : array();
        $plannerInput = isset($cartItemData['sbdp_planner_input']) && is_array($cartItemData['sbdp_planner_input'])
            ? $cartItemData['sbdp_planner_input']
            : array();

        $selection = $this->derivePrimarySelection(
            $summary,
            $planMeta,
            $planItem,
            $plannerInput,
            isset($cartItemData['quantity']) ? (int) $cartItemData['quantity'] : 0
        );
        if ($selection === array()) {
            return $cartItemData;
        }

        $combiItems = $this->extractCartCombiItems($summary, $planItem, $plannerInput);
        $pricing = isset($cartItemData['sbdp_pricing']) && is_array($cartItemData['sbdp_pricing'])
            ? $cartItemData['sbdp_pricing']
            : (isset($summary['pricing']) && is_array($summary['pricing']) ? $summary['pricing'] : array());

        $cartItemData['sbdp_summary'] = array_merge(
            $summary,
            array(
                'date'         => (string) ($selection['date'] ?? ''),
                'time'         => (string) ($selection['time'] ?? ''),
                'participants' => (int) ($selection['participants'] ?? 1),
                'resource_id'  => (int) ($selection['resource_id'] ?? 0),
                'start'        => (string) ($selection['start'] ?? ''),
                'end'          => (string) ($selection['display_end'] ?? ''),
                'pricing'      => $pricing,
                'combi_multi'  => $combiItems,
            )
        );
        if (! empty($selection['duration'])) {
            $cartItemData['sbdp_summary']['duration'] = (int) $selection['duration'];
        }

        $cartItemData['sbdp_date'] = (string) ($selection['date'] ?? '');
        $cartItemData['sbdp_time'] = (string) ($selection['time'] ?? '');
        $cartItemData['sbdp_canonical_participants'] = (int) ($selection['participants'] ?? 1);
        $cartItemData['sbdp_participants'] = (int) ($selection['participants'] ?? 1);
        $cartItemData['sbdp_quantity'] = (int) ($selection['participants'] ?? 1);
        $cartItemData['sbdp_resource_id'] = (int) ($selection['resource_id'] ?? 0);
        $cartItemData['sbdp_start'] = (string) ($selection['start'] ?? '');
        if (isset($cartItemData['sbdp_route_intent'])) {
            $cartItemData['sbdp_route_intent'] = sanitize_text_field((string) $cartItemData['sbdp_route_intent']);
        }
        if (isset($cartItemData['sbdp_booking_capability'])) {
            $cartItemData['sbdp_booking_capability'] = sanitize_text_field((string) $cartItemData['sbdp_booking_capability']);
        }
        $cartItemData['sbdp_meta'] = array_merge(
            $planMeta,
            array(
                'sbdp_canonical_participants' => (int) ($selection['participants'] ?? 1),
                'sbdp_participants' => (int) ($selection['participants'] ?? 1),
            )
        );
        if (! empty($selection['end_iso'])) {
            $cartItemData['sbdp_end'] = (string) $selection['end_iso'];
        }

        $productId = 0;
        if (isset($cartItemData['product_id'])) {
            $productId = (int) $cartItemData['product_id'];
        } elseif (isset($cartItemData['data']) && $cartItemData['data'] instanceof \WC_Product) {
            $productId = (int) $cartItemData['data']->get_id();
        }
        $resourceLabel = $this->resolveResourceLabel($productId, (int) ($selection['resource_id'] ?? 0));
        if ($resourceLabel !== '') {
            $cartItemData['sbdp_resource_label'] = $resourceLabel;
        }

        return $cartItemData;
    }

    /**
     * @param array<string, mixed> $cartItemData
     * @return array<string, mixed>
     */
    private function finalizeCartPayload(array $cartItemData, int $productId): array
    {
        if (! isset($cartItemData['product_id'])) {
            $cartItemData['product_id'] = $productId;
        }

        if (! isset($cartItemData['data']) && function_exists('wc_get_product')) {
            $product = wc_get_product($productId);
            if ($product instanceof \WC_Product) {
                $cartItemData['data'] = $product;
            }
        }

        $cartItemData = $this->normaliseStructuredCartPayload($cartItemData);
        $selection = $this->extractSelectionFromPayload($cartItemData, $productId);
        if ($selection === array()) {
            return $cartItemData;
        }

        $profile = $this->resolveSelectionBookingTruthProfile($cartItemData, $selection);
        $meta = (new BookingTruthRuntimeService())->buildCanonicalMeta($selection, $profile);

        $cartItemData['sbdp_start'] = $meta['sbdp_start'];
        $cartItemData['sbdp_end'] = $meta['sbdp_end'];
        $cartItemData['sbdp_participants'] = $meta['sbdp_participants'];
        $cartItemData['sbdp_canonical_participants'] = $meta['sbdp_canonical_participants'];
        $cartItemData['sbdp_resource_id'] = $meta['sbdp_resource_id'];
        $cartItemData['sbdp_route_intent'] = $meta['sbdp_route_intent'];
        $cartItemData['sbdp_booking_capability'] = $meta['sbdp_booking_capability'];
        $cartItemData['sbdp_meta'] = array_merge(
            isset($cartItemData['sbdp_meta']) && is_array($cartItemData['sbdp_meta']) ? $cartItemData['sbdp_meta'] : array(),
            $meta
        );

        return $cartItemData;
    }

    /**
     * @param array<string, mixed> $cartItemData
     * @return array<string, mixed>
     */
    private function extractSelectionFromPayload(array $cartItemData, int $productId): array
    {
        if (! isset($cartItemData['data']) && function_exists('wc_get_product')) {
            $product = wc_get_product($productId);
            if ($product instanceof \WC_Product) {
                $cartItemData['data'] = $product;
            }
        }

        return $this->extractCartSelection($cartItemData);
    }

    /**
     * @param array<string, mixed> $cartItemData
     * @param array<string, mixed> $selection
     * @return array<string, mixed>
     */
    private function resolveSelectionBookingTruthProfile(array $cartItemData, array $selection): array
    {
        $planItem = isset($cartItemData['sbdp_plan_item']) && is_array($cartItemData['sbdp_plan_item'])
            ? $cartItemData['sbdp_plan_item']
            : array();
        $bookingResolution = isset($planItem['bookingResolution']) && is_array($planItem['bookingResolution'])
            ? $planItem['bookingResolution']
            : array();

        return (new BookingTruthRuntimeService())->resolveBookingCapabilityProfile(
            $selection,
            array(
                'explicit_capability' => $planItem['bookingCapability'] ?? $planItem['booking_capability'] ?? null,
                'booking_resolution_status' => $bookingResolution['status'] ?? null,
            )
        );
    }

    /**
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $planMeta
     * @param array<string,mixed> $planItem
     * @param array<string,mixed> $plannerInput
     * @return array<string,mixed>
     */
    private function derivePrimarySelection(
        array $summary,
        array $planMeta,
        array $planItem,
        array $plannerInput,
        int $fallbackQuantity = 0
    ): array {
        $bookingResolution = isset($planItem['bookingResolution']) && is_array($planItem['bookingResolution'])
            ? $planItem['bookingResolution']
            : array();
        $aggregate = isset($planItem['aggregate']) && is_array($planItem['aggregate'])
            ? $planItem['aggregate']
            : array();
        $anchor = isset($aggregate['anchor']) && is_array($aggregate['anchor'])
            ? $aggregate['anchor']
            : array();
        $bookingTimeline = isset($bookingResolution['summary']) && is_array($bookingResolution['summary']) && isset($bookingResolution['summary']['timeline']) && is_array($bookingResolution['summary']['timeline'])
            ? $bookingResolution['summary']['timeline']
            : array();
        $bookingSegments = isset($bookingResolution['confirmedSegments']) && is_array($bookingResolution['confirmedSegments'])
            ? $bookingResolution['confirmedSegments']
            : (isset($bookingResolution['segments']) && is_array($bookingResolution['segments']) ? $bookingResolution['segments'] : array());
        $firstBookingSegment = $bookingSegments !== array() ? (isset($bookingSegments[0]) && is_array($bookingSegments[0]) ? $bookingSegments[0] : array()) : array();
        $lastBookingSegment = $bookingSegments !== array() ? (is_array($bookingSegments[array_key_last($bookingSegments)]) ? $bookingSegments[array_key_last($bookingSegments)] : array()) : array();
        $plannerTimeslot = isset($plannerInput['timeslot']) && is_array($plannerInput['timeslot'])
            ? $plannerInput['timeslot']
            : array();

        $participants = $this->resolveCanonicalParticipants(
            $summary,
            $planMeta,
            $planItem,
            $plannerInput,
            $fallbackQuantity
        );
        if ($participants <= 0) {
            return array();
        }

        $resourceId = (int) (
            $planItem['resourceId']
            ?? $planItem['resource_id']
            ?? $plannerInput['resourceId']
            ?? $plannerInput['resource_id']
            ?? $summary['resource_id']
            ?? $planMeta['sbdp_resource_id']
            ?? 0
        );

        $date = isset($planItem['date']) ? (string) $planItem['date'] : '';
        if ($date === '') {
            $date = isset($plannerInput['date']) ? (string) $plannerInput['date'] : '';
        }
        if ($date === '') {
            $date = isset($summary['date']) ? (string) $summary['date'] : '';
        }
        if ($date === '') {
            $date = isset($planMeta['sbdp_plan_date']) ? (string) $planMeta['sbdp_plan_date'] : '';
        }

        $time = isset($bookingTimeline['startTime']) ? (string) $bookingTimeline['startTime'] : '';
        if ($time === '' && isset($firstBookingSegment['startTime']) && is_string($firstBookingSegment['startTime'])) {
            $time = (string) $firstBookingSegment['startTime'];
        }
        if ($time === '') {
            $time = isset($anchor['startTime']) ? (string) $anchor['startTime'] : '';
        }
        if ($time === '') {
            $time = isset($planItem['startTime']) ? (string) $planItem['startTime'] : '';
        }
        if ($time === '') {
            $time = isset($plannerTimeslot['start']) ? (string) $plannerTimeslot['start'] : (string) ($plannerInput['time'] ?? '');
        }
        if ($time === '') {
            $time = isset($summary['time']) ? (string) $summary['time'] : '';
        }
        if ($time === '' && ! empty($planMeta['sbdp_start'])) {
            $time = substr((string) $planMeta['sbdp_start'], 11, 5);
        }

        $displayEnd = isset($bookingTimeline['endTime']) ? (string) $bookingTimeline['endTime'] : '';
        if ($displayEnd === '' && isset($lastBookingSegment['endTime']) && is_string($lastBookingSegment['endTime'])) {
            $displayEnd = (string) $lastBookingSegment['endTime'];
        }
        if ($displayEnd === '') {
            $displayEnd = isset($anchor['endTime']) ? (string) $anchor['endTime'] : '';
        }
        if ($displayEnd === '') {
            $displayEnd = isset($planItem['endTime']) ? (string) $planItem['endTime'] : '';
        }
        if ($displayEnd === '') {
            $displayEnd = isset($plannerTimeslot['end']) ? (string) $plannerTimeslot['end'] : '';
        }
        if ($displayEnd === '') {
            $displayEnd = $this->extractDisplayTime((string) ($summary['end'] ?? ''));
        }
        if ($displayEnd === '' && ! empty($planMeta['sbdp_end'])) {
            $displayEnd = $this->extractDisplayTime((string) $planMeta['sbdp_end']);
        }

        $duration = 0;
        if (isset($bookingTimeline['durationMinutes'])) {
            $duration = max(0, (int) $bookingTimeline['durationMinutes']);
        } elseif (isset($anchor['durationMinutes'])) {
            $duration = max(0, (int) $anchor['durationMinutes']);
        } elseif (isset($firstBookingSegment['startMinutes'], $lastBookingSegment['endMinutes'])) {
            $duration = max(0, (int) $lastBookingSegment['endMinutes'] - (int) $firstBookingSegment['startMinutes']);
        } elseif (isset($planItem['durationMinutes'])) {
            $duration = max(0, (int) $planItem['durationMinutes']);
        } elseif (isset($summary['duration'])) {
            $duration = max(0, (int) $summary['duration']);
        }

        $start = isset($anchor['start']) ? (string) $anchor['start'] : '';
        if ($start === '') {
            $start = isset($planItem['start']) ? (string) $planItem['start'] : '';
        }
        if ($start === '') {
            $start = isset($summary['start']) ? (string) $summary['start'] : '';
        }
        if ($start === '') {
            $start = isset($planMeta['sbdp_start']) ? (string) $planMeta['sbdp_start'] : '';
        }
        if ($start === '' && $date !== '' && $time !== '') {
            $start = $this->composeStartIso($date, $time);
        }
        if ($start === '') {
            return array();
        }

        $endIso = isset($anchor['end']) ? (string) $anchor['end'] : '';
        if ($endIso === '' && isset($planItem['end']) && is_string($planItem['end'])) {
            $endIso = (string) $planItem['end'];
        }
        if ($endIso === '' && ! empty($planMeta['sbdp_end']) && strpos((string) $planMeta['sbdp_end'], 'T') !== false) {
            $endIso = (string) $planMeta['sbdp_end'];
        }
        if ($endIso === '' && $date !== '' && $displayEnd !== '') {
            $endIso = $this->composeStartIso($date, $displayEnd);
        }
        if ($endIso === '' && $date !== '' && $time !== '' && $duration > 0) {
            $endIso = $this->composeEndIso($date, $time, $duration);
        }
        if ($displayEnd === '' && $endIso !== '') {
            $displayEnd = $this->extractDisplayTime($endIso);
        }

        return array(
            'participants' => $participants,
            'resource_id'  => $resourceId,
            'date'         => $date,
            'time'         => $time,
            'start'        => $start,
            'end_iso'      => $endIso,
            'display_end'  => $displayEnd,
            'duration'     => $duration,
        );
    }

    /**
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $planMeta
     * @param array<string,mixed> $planItem
     * @param array<string,mixed> $plannerInput
     */
    private function resolveCanonicalParticipants(
        array $summary,
        array $planMeta,
        array $planItem,
        array $plannerInput,
        int $fallbackQuantity = 0
    ): int {
        $candidates = array(
            $planItem['participants'] ?? null,
            $plannerInput['participants'] ?? null,
            $plannerInput['people'] ?? null,
            $summary['participants'] ?? null,
            $planMeta['sbdp_canonical_participants'] ?? null,
            $planMeta['sbdp_participants'] ?? null,
        );

        foreach ($candidates as $candidate) {
            $count = (int) $candidate;
            if ($count > 0) {
                return $count;
            }
        }

        $hasHandoffSource = $this->hasParticipantsHandoffSource($summary, $planMeta, $planItem, $plannerInput);
        if (! $hasHandoffSource && $fallbackQuantity > 0) {
            return $fallbackQuantity;
        }

        return 0;
    }

    /**
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $planMeta
     * @param array<string,mixed> $planItem
     * @param array<string,mixed> $plannerInput
     */
    private function hasParticipantsHandoffSource(
        array $summary,
        array $planMeta,
        array $planItem,
        array $plannerInput
    ): bool {
        return array_key_exists('participants', $planItem)
            || array_key_exists('participants', $plannerInput)
            || array_key_exists('people', $plannerInput)
            || array_key_exists('participants', $summary)
            || array_key_exists('sbdp_canonical_participants', $planMeta)
            || array_key_exists('sbdp_participants', $planMeta);
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $planItem
     * @param array<string, mixed> $plannerInput
     * @return array<int, array<string, mixed>>
     */
    private function extractCartCombiItems(array $summary, array $planItem, array $plannerInput): array
    {
        $candidates = array(
            $summary['combi_multi'] ?? null,
            isset($planItem['options']) && is_array($planItem['options']) ? ($planItem['options']['combiItems'] ?? null) : null,
            isset($plannerInput['options']) && is_array($plannerInput['options']) ? ($plannerInput['options']['combiItems'] ?? null) : null,
        );

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            return SelectionPricing::normaliseCombiItems($candidate);
        }

        return array();
    }

    /**
     * @param array<int, array<string, mixed>> $activeCombis
     * @return array<string, mixed>
     */
    private function findPostedActiveCombi(array $activeCombis, int $combiId): array
    {
        foreach ($activeCombis as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $entryId = isset($entry['id']) ? (int) $entry['id'] : 0;
            if ($entryId === $combiId) {
                return $entry;
            }
        }

        return array();
    }

    private function extractDisplayTime(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/^\d{2}:\d{2}$/', $value) === 1) {
            return $value;
        }

        return substr($value, 11, 5);
    }

    /**
     * @param array<string,mixed> $cartItem
     * @return array<string,mixed>
     */
    private function extractAggregateFromCartItem(array $cartItem): array
    {
        if (isset($cartItem['sbdp_plan_aggregate']) && is_array($cartItem['sbdp_plan_aggregate'])) {
            return $cartItem['sbdp_plan_aggregate'];
        }

        if (isset($cartItem['sbdp_plan_item']['aggregate']) && is_array($cartItem['sbdp_plan_item']['aggregate'])) {
            return $cartItem['sbdp_plan_item']['aggregate'];
        }

        return array();
    }

    /**
     * @param array<string,mixed> $aggregatePricing
     * @return array<string,mixed>
     */
    private function buildAggregatePricingProjection(array $aggregatePricing, int $participants): array
    {
        $total = isset($aggregatePricing['total']) ? (float) $aggregatePricing['total'] : 0.0;
        $unitPrice = isset($aggregatePricing['unit_price']) ? (float) $aggregatePricing['unit_price'] : ($participants > 0 ? round($total / $participants, 2) : $total);
        $displayTotal = isset($aggregatePricing['display_total']) ? (float) $aggregatePricing['display_total'] : $total;
        $displayUnit = isset($aggregatePricing['display_unit_price']) ? (float) $aggregatePricing['display_unit_price'] : $unitPrice;

        return [
            'currency'          => (string) ($aggregatePricing['currency'] ?? get_woocommerce_currency()),
            'subtotal'          => (float) ($aggregatePricing['subtotal'] ?? $total),
            'tax'               => (float) ($aggregatePricing['tax'] ?? ($aggregatePricing['tax_total'] ?? 0.0)),
            'tax_total'         => (float) ($aggregatePricing['tax_total'] ?? ($aggregatePricing['tax'] ?? 0.0)),
            'total'             => $total,
            'unit_price'        => $unitPrice,
            'unitPrice'         => $unitPrice,
            'per_person'        => $unitPrice,
            'display_total'     => $displayTotal,
            'display_unit_price'=> $displayUnit,
            'display_per_person' => $displayUnit,
            'bookingAdjustment' => (float) ($aggregatePricing['adjustments_total'] ?? 0.0),
            'adjustments'       => is_array($aggregatePricing['adjustments'] ?? null) ? $aggregatePricing['adjustments'] : array(),
            'discounts'         => is_array($aggregatePricing['discounts'] ?? null) ? $aggregatePricing['discounts'] : array(),
            'taxes'             => is_array($aggregatePricing['taxes'] ?? null) ? $aggregatePricing['taxes'] : array(),
            'segments'          => is_array($aggregatePricing['items'] ?? null) ? $aggregatePricing['items'] : array(),
            'dynamic'           => ['total' => $total],
        ];
    }

    /**
     * Build ISO8601 start datetime from date and time.
     */
    private function composeStartIso(?string $date, ?string $time): string
    {
        if ($date && $time) {
            return sprintf('%sT%s:00', $date, $time);
        }

        return $date ?? '';
    }

    /**
     * Quote pricing consistently for product page and cart flows.
     *
     * @param array<int, array<string, mixed>> $combiItems
     */
    private function quotePricing(
        int $productId,
        string $start,
        int $participants,
        int $resourceId = 0,
        array $context = array(),
        array $combiItems = array()
    ): array
    {
        if ($productId <= 0) {
            return array();
        }

        if (! class_exists(RestService::class) || ! class_exists(SelectionPricing::class)) {
            return array();
        }

        $product = function_exists('wc_get_product') ? wc_get_product($productId) : null;
        if (! $product) {
            return array();
        }

        return SelectionPricing::quote(
            $product->get_id(),
            $participants,
            $start,
            $resourceId,
            $combiItems,
            array_merge(
                [
                'channel' => 'product_page_refresh',
                'source'  => 'cart_recalc',
                'price_mode' => 'gross',
                ],
                $context
            )
        );
    }

    /**
     * @param array<string, mixed> $atts
     */
    public function handleShortcode(array $atts): string
    {
        $atts = shortcode_atts(
            [
                'product_id' => 0,
            ],
            $atts,
            'sbdp_summary_card'
        );

        $productId = (int) $atts['product_id'];
        $product = $productId > 0 ? wc_get_product($productId) : $this->getCurrentProduct();

        if (! $this->isTargetProduct($product)) {
            return '';
        }

        return $this->captureSummaryMarkup($product);
    }

    private function captureSummaryMarkup(WC_Product $product): string
    {
        $config = $this->buildCardConfig($product);
        $configJson = wp_json_encode($config);
        if (! is_string($configJson)) {
            $configJson = '{}';
        }

        $today = $config['defaults']['date'];
        $plannerUrl = $config['plannerUrl'];
        $timeOptions = $config['timeSlots'];
        $min = $config['constraints']['min'];
        $max = $config['constraints']['max'];

        ob_start();
        ?>
        <form
            class="sbdp-summary-card"
            method="post"
            action="<?php echo esc_url(wc_get_cart_url()); ?>"
            data-sbdp-summary
            data-sbdp-summary-card="true"
            data-product-id="<?php echo esc_attr((string) $product->get_id()); ?>"
            data-sbdp-summary-config="<?php echo esc_attr($configJson); ?>"
        >
            <?php wp_nonce_field('sbdp_summary_add_to_cart', '_sbdp_summary_nonce'); ?>
            <header class="sbdp-summary-card__header">
                <strong class="sbdp-summary-card__title"><?php echo esc_html($product->get_name()); ?></strong>
                <span class="sbdp-summary-card__meta"><?php echo esc_html($config['meta']); ?></span>
            </header>
            <div class="sbdp-summary-card__body">
                <label class="sbdp-summary-card__field">
                    <span class="sbdp-summary-card__label"><?php esc_html_e('Datum', 'sbdp'); ?></span>
                    <input
                        type="date"
                        name="sbdp_summary_date"
                        value="<?php echo esc_attr($today); ?>"
                        min="<?php echo esc_attr($today); ?>"
                        required
                        data-sbdp-summary-date
                    />
                </label>
                <label class="sbdp-summary-card__field">
                    <span class="sbdp-summary-card__label"><?php esc_html_e('Tijd', 'sbdp'); ?></span>
                    <select name="sbdp_summary_time" required data-sbdp-summary-time>
                        <option value=""><?php esc_html_e('Selecteer een tijd', 'sbdp'); ?></option>
                        <?php foreach ($timeOptions as $slot) : ?>
                            <option value="<?php echo esc_attr($slot['start']); ?>">
                                <?php echo esc_html($slot['start']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="sbdp-summary-card__field">
                    <span class="sbdp-summary-card__label"><?php esc_html_e('Deelnemers', 'sbdp'); ?></span>
                    <input
                        type="number"
                        name="sbdp_summary_participants"
                        value="<?php echo esc_attr((string) max(1, $min)); ?>"
                        min="<?php echo esc_attr((string) max(1, $min)); ?>"
                        <?php if ($max > 0) : ?>
                            max="<?php echo esc_attr((string) $max); ?>"
                        <?php endif; ?>
                        required
                        data-sbdp-summary-participants
                    />
                </label>
                <input type="hidden" name="add-to-cart" value="<?php echo esc_attr((string) $product->get_id()); ?>" />
                <input type="hidden" name="sbdp_planner_input" value="" data-sbdp-summary-planner-input />
                <input type="hidden" name="sbdp_plan_item" value="" data-sbdp-summary-plan-item />
            </div>
            <?php if (! empty($config['combiOptions']) || ! empty($config['resources'])) : ?>
                <details class="sbdp-summary-card__extras">
                    <summary><?php esc_html_e('Optionele keuzes', 'sbdp'); ?></summary>
                    <div class="sbdp-summary-card__extras-grid">
                        <?php if (! empty($config['resources'])) : ?>
                            <label class="sbdp-summary-card__field">
                                <span class="sbdp-summary-card__label"><?php esc_html_e('Resource', 'sbdp'); ?></span>
                                <select name="sbdp_summary_resource" data-sbdp-summary-resource>
                                    <?php foreach ($config['resources'] as $resource) :
                                        $resource_id = isset($resource['id']) ? (int) $resource['id'] : 0;
                                        $resource_label = isset($resource['title']) ? (string) $resource['title'] : '';
                                        if ($resource_id <= 0 || $resource_label === '') {
                                            continue;
                                        }
                                        ?>
                                        <option value="<?php echo esc_attr((string) $resource_id); ?>">
                                            <?php echo esc_html($resource_label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="sbdp_resource" value="<?php echo esc_attr((string) $config['resourceDefault']); ?>" data-sbdp-summary-resource-input />
                            </label>
                        <?php endif; ?>
                        <?php if (! empty($config['combiOptions'])) : ?>
                            <label class="sbdp-summary-card__field">
                                <span class="sbdp-summary-card__label"><?php esc_html_e('Combi-deal', 'sbdp'); ?></span>
                                <select name="sbdp_summary_combi" data-sbdp-summary-combi>
                                    <option value=""><?php esc_html_e('Geen combi geselecteerd', 'sbdp'); ?></option>
                                    <?php foreach ($config['combiOptions'] as $option) : ?>
                                        <option
                                            value="<?php echo esc_attr((string) ($option['value'] ?? '')); ?>"
                                            data-name="<?php echo esc_attr((string) ($option['name'] ?? '')); ?>"
                                            data-duration="<?php echo esc_attr((string) ($option['duration'] ?? 0)); ?>"
                                            data-adjustment="<?php echo esc_attr((string) ($option['adjustment'] ?? 0)); ?>"
                                            data-supports-persons="<?php echo ! empty($option['supportsPersons']) ? '1' : '0'; ?>"
                                        >
                                            <?php echo esc_html((string) ($option['label'] ?? '')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="sbdp_summary_combi_label" value="" data-sbdp-summary-combi-label />
                            </label>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endif; ?>
            <div class="sbdp-summary-card__price" aria-live="polite">
                <span class="sbdp-summary-card__price-label"><?php esc_html_e('Totaal', 'sbdp'); ?></span>
                <span class="sbdp-summary-card__price-value" data-sbdp-summary-total>&mdash;</span>
            </div>
            <div class="sbdp-summary-card__breakdown" data-sbdp-summary-breakdown hidden>
                <div class="sbdp-summary-card__breakdown-row">
                    <span><?php esc_html_e('Basisprijs', 'sbdp'); ?></span>
                    <span data-sbdp-summary-base>&mdash;</span>
                </div>
                <div class="sbdp-summary-card__breakdown-row">
                    <span><?php esc_html_e('Per deelnemer', 'sbdp'); ?></span>
                    <span data-sbdp-summary-per-person>&mdash;</span>
                </div>
            </div>
            <div class="sbdp-summary-card__actions">
                <button
                    type="submit"
                    class="sbdp-summary-card__book"
                    disabled
                    data-sbdp-summary-book
                >
                    <?php esc_html_e('Leg in winkelwagen', 'sbdp'); ?>
                </button>
                <button
                    type="button"
                    class="sbdp-summary-card__plan"
                    data-sbdp-summary-plan
                    data-planner-url="<?php echo esc_attr($plannerUrl); ?>"
                >
                    <?php esc_html_e('Plan je dag', 'sbdp'); ?>
                </button>
            </div>
            <a class="sbdp-summary-card__link" href="<?php echo esc_url($plannerUrl); ?>">
                <?php esc_html_e('Bekijk activiteiten', 'sbdp'); ?>
            </a>
        </form>
        <?php

        return (string) ob_get_clean();
    }

    private function buildCardConfig(WC_Product $product): array
    {
        $settings = ProductSettings::get($product->get_id());
        $resources = ProductMeta::get_resources_payload($product->get_id());
        $resourceDefault = $resources !== [] ? (int) ($resources[0]['id'] ?? 0) : 0;
        $timeSlots = [];

        foreach ((array) ($settings['time_slots'] ?? []) as $slot) {
            if (! is_array($slot)) {
                continue;
            }

            $start = isset($slot['start']) ? $this->sanitizeTime((string) $slot['start']) : null;
            if ($start === null) {
                continue;
            }

            $timeSlots[] = [
                'start' => $start,
                'end'   => isset($slot['end']) ? (string) $slot['end'] : '',
            ];
        }

        if ($timeSlots === []) {
            $timeSlots = array();
        }

        $today = function_exists('wp_date') ? wp_date('Y-m-d') : gmdate('Y-m-d');
        $min = (int) get_post_meta($product->get_id(), '_sbdp_min_people', true);
        $max = (int) ($settings['capacity'] ?? 0);
        $duration = (int) ($settings['duration_minutes'] ?? 0);

        $metaParts = [];
        if ($duration > 0) {
            $metaParts[] = sprintf(
                /* translators: %d: duration in minutes */
                __('%d minuten', 'sbdp'),
                $duration
            );
        }

        if ($max > 0) {
            $metaParts[] = sprintf(
                /* translators: %d: maximum participants */
                __('Tot %d personen', 'sbdp'),
                $max
            );
        }

        $meta = implode(' • ', array_filter($metaParts));

        $combi_options = $this->buildCombiOptions($product);
        $supplierProvider = strtolower(trim((string) get_post_meta($product->get_id(), '_ddb_supplier_provider', true)));
        $availabilityMode = strtolower(trim((string) get_post_meta($product->get_id(), '_ddb_supplier_availability_mode', true)));
        $bookingMode = class_exists(BookingModeService::class)
            ? (new BookingModeService())->resolve((int) $product->get_id())
            : array(
                'bookingMode' => 'direct',
                'routeIntent' => 'checkout',
                'directBookable' => true,
                'supplierConfirmationRequired' => false,
            );
        $isEliio = $supplierProvider === 'eliio' || (int) $product->get_id() === 115;
        $routeIntent = (string) ($bookingMode['routeIntent'] ?? 'checkout');

        return [
            'productId'  => $product->get_id(),
            'defaults'   => [
                'date'         => $today,
                'time'         => $timeSlots[0]['start'] ?? '',
                'participants' => max(1, $min),
            ],
            'timeSlots'  => $timeSlots,
            'resources'  => $resources,
            'resourceDefault' => $resourceDefault,
            'durationMinutes' => $duration > 0 ? $duration : null,
            'constraints' => [
                'min' => max(1, $min),
                'max' => max(0, $max),
            ],
            'plannerUrl' => $this->getPlannerUrl(),
            'meta'       => $meta,
            'combiOptions' => $combi_options,
            'supplier' => [
                'provider' => $supplierProvider,
                'availabilityMode' => $availabilityMode,
                'bookingMode' => (string) ($bookingMode['bookingMode'] ?? ''),
                'routeIntent' => $routeIntent,
                'directBookable' => ! empty($bookingMode['directBookable']),
                'supplierConfirmationRequired' => ! empty($bookingMode['supplierConfirmationRequired']),
                'requestOnly' => $routeIntent === 'quote' || $isEliio,
            ],
        ];
    }

    /**
     * @return array<int, array{value:int,label:string}>
     */
    private function buildCombiOptions(WC_Product $product): array
    {
        if (! function_exists('wc_get_product')) {
            return array();
        }

        $stored = get_post_meta($product->get_id(), '_sbdp_combi_deals', true);
        if (is_string($stored)) {
            $trimmed = trim($stored);
            if ($trimmed !== '' && $trimmed[0] === '[') {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    $stored = $decoded;
                }
            } elseif (strpos($trimmed, ',') !== false) {
                $stored = array_map('trim', explode(',', $trimmed));
            }
        }

        $combi_ids = array_filter(
            array_map(
                static fn($value) => (int) $value,
                is_array($stored) ? $stored : (array) $stored
            ),
            static fn(int $value): bool => $value > 0
        );
        $combi_ids = $this->uniqueOrderedIds($combi_ids);

        if (empty($combi_ids)) {
            return array();
        }

        $options = array();

        foreach ($combi_ids as $combi_id) {
            $related = wc_get_product($combi_id);
            if (! $related instanceof WC_Product) {
                continue;
            }

            $duration_minutes = null;
            if (class_exists(\SBDP\Core\ProductSettings::class)) {
                try {
                    $settings = \SBDP\Core\ProductSettings::get($related->get_id());
                    $candidateDuration = (int) ($settings['duration_minutes'] ?? 0);
                    if ($candidateDuration > 0) {
                        $duration_minutes = $candidateDuration;
                    }
                } catch (\Throwable $exception) {
                    $duration_minutes = null;
                }
            } else {
                $raw_duration = (int) get_post_meta($related->get_id(), '_sbdp_duration', true);
                $duration_unit = (string) get_post_meta($related->get_id(), '_sbdp_duration_unit', true);
                $duration_unit = strtolower($duration_unit);
                if ($raw_duration > 0) {
                    if (in_array($duration_unit, ['hour', 'hours', 'uur', 'uren'], true)) {
                        $duration_minutes = $raw_duration * 60;
                    } elseif (in_array($duration_unit, ['day', 'days', 'dag', 'dagen'], true)) {
                        $duration_minutes = $raw_duration * 1440;
                    } else {
                        $duration_minutes = $raw_duration;
                    }
                }
            }

            $name = $related->get_name();
            $label = $name;
            $quote = SelectionPricing::quote(
                $related->get_id(),
                1,
                '',
                0,
                array(),
                array(
                    'channel' => 'product_page_refresh',
                    'source'  => 'build_combi_options',
                    'price_mode' => 'gross',
                )
            );
            $price_incl = isset($quote['display_unit_price']) ? (float) $quote['display_unit_price'] : (isset($quote['unit_price']) ? (float) $quote['unit_price'] : 0.0);
            if ($price_incl <= 0.0 && isset($quote['total'])) {
                $price_incl = (float) $quote['total'];
            }
            if ($price_incl <= 0.0) {
                $price_incl = function_exists('wc_get_price_including_tax')
                    ? (float) wc_get_price_including_tax($related, array('qty' => 1))
                    : (float) $related->get_price();
            }

            $supportsPersonsForOption = false;
            if (isset($quote['line_item']['pricing']) && is_array($quote['line_item']['pricing']) && array_key_exists('supports_persons', $quote['line_item']['pricing'])) {
                $supportsPersonsForOption = (bool) $quote['line_item']['pricing']['supports_persons'];
            } elseif (class_exists(\SBDP\Pricing\PricingService::class)) {
                try {
                    $pricingData = \SBDP\Pricing\PricingService::instance()->getProductPricing(
                        $related->get_id(),
                        array(
                            'channel'    => 'product_page_refresh',
                            'source'     => 'build_combi_options',
                            'price_mode' => 'gross',
                        )
                    );
                    $supportsPersonsForOption = ! empty($pricingData['supports_persons']);
                } catch (\Throwable $exception) {
                    $supportsPersonsForOption = false;
                }
            }

            $adjustment = round($price_incl, 2);
            $price_label = function_exists('wc_price') ? wc_price($price_incl) : number_format_i18n($price_incl, 2);
            /* translators: %1$s product title, %2$s formatted price */
            $label = sprintf(__('%1$s - %2$s', 'sbdp'), $label, wp_strip_all_tags($price_label));
            $label = html_entity_decode($label, ENT_QUOTES, 'UTF-8');

            $image_url = '';
            if (function_exists('wp_get_attachment_image_url')) {
                $image_id = $related->get_image_id();
                if ($image_id) {
                    $image_url = (string) wp_get_attachment_image_url($image_id, 'thumbnail');
                }
            }

            $options[] = [
                'value' => $related->get_id(),
                'label' => $label,
                'image' => $image_url,
                'adjustment' => $adjustment,
                'supportsPersons' => $supportsPersonsForOption,
                'name'  => html_entity_decode($name, ENT_QUOTES, 'UTF-8'),
                'duration' => $duration_minutes,
            ];
        }

        return $options;
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, int>
     */
    private function uniqueOrderedIds(array $ids): array
    {
        $ordered = array();
        $seen = array();

        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $ordered[] = $id;
        }

        return $ordered;
    }

    private function shouldHandleRequest(): bool
    {
        $product = $this->getCurrentProduct();

        return $this->isTargetProduct($product);
    }

    private function shouldRenderSummaryExperience(): bool
    {
        if (! $this->shouldHandleRequest()) {
            return false;
        }

        if (class_exists(ProductPageContext::class) && ProductPageContext::shouldUseLegacyPlannerOverrides()) {
            return false;
        }

        if (function_exists('sbdp_should_output_product_planner_overrides') && sbdp_should_output_product_planner_overrides()) {
            return false;
        }

        return true;
    }

    private function isTargetProduct(?WC_Product $product): bool
    {
        if (! $product instanceof WC_Product) {
            return false;
        }

        return $product->get_type() === BookableServiceProductType::PRODUCT_TYPE;
    }

    private function getCurrentProduct(): ?WC_Product
    {
        if (class_exists(ProductPageContext::class)) {
            $currentProduct = ProductPageContext::getCurrentProduct();
            if ($currentProduct instanceof WC_Product) {
                return $currentProduct;
            }
        }

        global $product;

        if ($product instanceof WC_Product) {
            return $product;
        }

        if (function_exists('wc_get_product')) {
            $maybe = wc_get_product(get_the_ID());
            if ($maybe instanceof WC_Product) {
                return $maybe;
            }
        }

        return null;
    }

    private function isEnabled(): bool
    {
        $stored = get_option(self::OPTION_FLAG, '1');
        $enabled = $stored === '1' || $stored === 1 || $stored === true;

        return (bool) apply_filters('sbdp/product_summary/enabled', $enabled);
    }

    private function getPlannerUrl(): string
    {
        $pageId = (int) get_option('sbdp_planner_page_id', 0);
        if ($pageId > 0) {
            $link = get_permalink($pageId);
            if (is_string($link) && $link !== '') {
                return $link;
            }
        }

        $page = get_page_by_path('plan-je-dag');
        if ($page instanceof WP_Post) {
            $link = get_permalink($page);
            if (is_string($link) && $link !== '') {
                return $link;
            }
        }

        return '';
    }

    private function sanitizeDate(string $date): ?string
    {
        $trimmed = trim($date);
        if ($trimmed === '') {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) ? $trimmed : null;
    }

    private function sanitizeTime(string $time): ?string
    {
        $trimmed = trim($time);
        if ($trimmed === '') {
            return null;
        }

        return preg_match('/^\d{2}:\d{2}$/', $trimmed) ? $trimmed : null;
    }
}
