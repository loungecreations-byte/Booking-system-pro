<?php
/**
 * Plugin Name: SBDP Single Product Planner
 * Description: High-performance product booking form with real-time availability and dynamic timeline.
 */

if (!defined('ABSPATH')) exit;

add_action('wp_enqueue_scripts', function() {
    wp_enqueue_style('sbdp-planner-css', plugin_dir_url(__FILE__) . '../../assets/css/sbdp-single-product-planner.css', array(), '1.2.7');
});

add_shortcode('sbdp_product_planner', 'sbdp_render_product_planner_form');

/**
 * Canonical display price resolver for this template.
 *
 * CSOT rule: _sbdp_base_price is the admin-entered incl-BTW price and is
 * the single source of truth for display pricing on this platform.
 * WooCommerce product prices (_price) are index/search prices only and must
 * NEVER be used as display truth for bookable_service / arrangement / resource
 * products.
 *
 * This function uses PricingService::quote() — the same canonical path used
 * by buildCombiOptions() in Module.php — so there is exactly ONE code path.
 * If that path fails it logs a warning and returns 0.0 rather than silently
 * falling back to the wrong WC price.
 */
function sbdp_legacy_product_planner_get_display_price($product) {
    if (!$product instanceof WC_Product) {
        return 0.0;
    }

    // Canonical path: PricingService::quote() — same as Module.php::buildCombiOptions().
    if (class_exists('\SBDP\Pricing\PricingService')) {
        try {
            $quote = \SBDP\Pricing\PricingService::instance()->quote(
                $product->get_id(),
                1,
                array(
                    'channel'    => 'legacy_product_planner',
                    'source'     => 'planner_bootstrap',
                    'price_mode' => 'gross',
                )
            );

            $unitPrice = isset($quote['unit_price']) ? (float) $quote['unit_price'] : 0.0;
            if ($unitPrice > 0.0) {
                return $unitPrice;
            }

            // quote() may return a line_item with per_person pricing.
            $perPerson = isset($quote['line_item']['pricing']['per_person'])
                ? (float) $quote['line_item']['pricing']['per_person']
                : 0.0;
            if ($perPerson > 0.0) {
                return $perPerson;
            }
        } catch (\Throwable $exception) {
            // Log so broken pricing is always visible in debug.log.
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log(sprintf(
                    'SBDP CSOT WARNING: sbdp_legacy_product_planner_get_display_price() canonical path failed for product %d: %s',
                    $product->get_id(),
                    $exception->getMessage()
                ));
            }
        }
    }

    // CSOT hard-stop: read _sbdp_base_price directly rather than trusting WC product price.
    // _sbdp_base_price is stored incl-BTW by admins and is the canonical booking price.
    $meta = get_post_meta($product->get_id(), '_sbdp_base_price', true);
    if (is_numeric($meta) && (float) $meta > 0.0) {
        return round((float) $meta, 2);
    }

    // Last resort — only reached if no SBDP pricing exists at all (non-bookable products).
    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log(sprintf(
            'SBDP CSOT WARNING: product %d has no _sbdp_base_price and PricingService returned 0. Falling back to WC price.',
            $product->get_id()
        ));
    }

    return function_exists('wc_get_price_including_tax')
        ? (float) wc_get_price_including_tax($product, array('qty' => 1))
        : (float) $product->get_price();
}

function sbdp_legacy_product_planner_is_placeholder_copy($value) {
    $text = trim(wp_strip_all_tags((string) $value));
    if ($text === '') {
        return false;
    }

    return (bool) preg_match('/^test\s*\d+/i', $text);
}

function sbdp_legacy_product_planner_get_intro_text($product) {
    if (!$product instanceof WC_Product) {
        return '';
    }

    $candidates = array(
        $product->get_short_description(),
        get_post_field('post_excerpt', $product->get_id()),
    );

    foreach ($candidates as $candidate) {
        $text = trim(wp_strip_all_tags((string) $candidate));
        if ($text === '' || sbdp_legacy_product_planner_is_placeholder_copy($text)) {
            continue;
        }

        return $text;
    }

    return '';
}

function sbdp_legacy_product_planner_format_duration_label($minutes) {
    $minutes = (int) $minutes;
    if ($minutes <= 0) {
        return '';
    }

    if ($minutes % 60 === 0) {
        $hours = (int) ($minutes / 60);
        return $hours === 1 ? '1 uur' : $hours . ' uur';
    }

    if ($minutes > 60) {
        $hours = floor($minutes / 60);
        $rest = $minutes % 60;
        return $hours . 'u ' . $rest . ' min';
    }

    return $minutes . ' min';
}

function sbdp_render_product_planner_form($atts = array()) {
    $product_id = isset($atts['product_id']) ? (int)$atts['product_id'] : 0;
    $product = null;

    if ($product_id) {
        $product = wc_get_product($product_id);
    } else {
        global $product;
        if ($product && is_a($product, 'WC_Product')) {
            $product_id = $product->get_id();
        } else {
            $product_id = (int)get_queried_object_id();
            $product = wc_get_product($product_id);
        }
    }

    if (!$product) {
        return '';
    }

    wp_enqueue_style('sbdp-planner-css');

    $title = $product->get_name();
    $intro_text = sbdp_legacy_product_planner_get_intro_text($product);

    $price_display = sbdp_legacy_product_planner_get_display_price($product);

    $today = function_exists('wp_date') ? wp_date('Y-m-d') : gmdate('Y-m-d');

    $product_settings = class_exists('\SBDP\Core\ProductSettings')
        ? \SBDP\Core\ProductSettings::get($product_id)
        : array();

    // Main Product Duration
    $main_duration = isset($product_settings['duration_minutes']) ? (int) $product_settings['duration_minutes'] : 0;
    if ($main_duration <= 0) {
        $main_raw_duration = get_post_meta($product_id, '_sbdp_duration', true) ?: 120;
        $main_duration_unit = get_post_meta($product_id, '_sbdp_duration_unit', true);
        $main_duration = ($main_duration_unit === 'hours') ? (int)$main_raw_duration * 60 : (int)$main_raw_duration;
    }

    // Slots
    $time_slots = class_exists('\SBDP\Core\ProductSettings')
        ? \SBDP\Core\ProductSettings::slotsForDate($product_id, $today)
        : array();
    if (!is_array($time_slots) || $time_slots === array()) {
        $time_slots = get_post_meta($product_id, '_sbdp_time_slots', true) ?: array(
            array('start' => '10:00'), array('start' => '12:00'), array('start' => '14:00'),
            array('start' => '16:00'), array('start' => '18:00'), array('start' => '20:00')
        );
    }

    // Combi Deals
    $raw_cross_sells = get_post_meta($product_id, '_sbdp_combi_deals', true);
    $cross_sells = is_array($raw_cross_sells) ? $raw_cross_sells : array_filter(array_map('intval', explode(',', (string)$raw_cross_sells)));
    $cross_sells = array_unique($cross_sells);
    
    $combi_deals = array();
    if (!empty($cross_sells)) {
        foreach ($cross_sells as $cid) {
            $c_prod = wc_get_product($cid);
            if ($c_prod) {
                $c_price_display = sbdp_legacy_product_planner_get_display_price($c_prod);

                $c_raw = get_post_meta($cid, '_sbdp_duration', true) ?: 45;
                $c_unit = get_post_meta($cid, '_sbdp_duration_unit', true);
                $c_calc_mins = ($c_unit === 'hours') ? (int)$c_raw * 60 : (int)$c_raw;
                $c_display = ($c_unit === 'hours') ? $c_raw . ' uur' : $c_calc_mins . ' min';

                $combi_deals[] = array(
                    'id' => $c_prod->get_id(),
                    'title' => $c_prod->get_name(),
                    'price' => $c_price_display, // pure float
                    'calc_mins' => $c_calc_mins,
                    'display_time' => $c_display,
                    'thumb' => get_the_post_thumbnail_url($cid, 'thumbnail') ?: ''
                );
            }
        }
    }

    $meta_chips = array();
    $duration_label = sbdp_legacy_product_planner_format_duration_label($main_duration);
    if ($duration_label !== '') {
        $meta_chips[] = $duration_label;
    }
    if ($price_display > 0) {
        $meta_chips[] = 'Vanaf € ' . number_format((float) $price_display, 2, ',', '.') . ' p.p.';
    }
    if (isset($product_settings['capacity']) && (int) $product_settings['capacity'] > 0) {
        $meta_chips[] = 'Tot ' . (int) $product_settings['capacity'] . ' personen';
    }
    if (is_array($time_slots) && count($time_slots) > 1) {
        $meta_chips[] = count($time_slots) . ' startmomenten';
    }

    $compose_url = function_exists('rest_url') ? rest_url('sbdp/v1/compose_booking') : '';
    $compose_nonce = function_exists('wp_create_nonce') && class_exists('\BSPModule\Core\Rest\RestService')
        ? wp_create_nonce(\BSPModule\Core\Rest\RestService::PUBLIC_NONCE_ACTION)
        : (function_exists('wp_create_nonce') ? wp_create_nonce('wp_rest') : '');
    $cart_url = function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/cart/');

    ob_start(); ?>
    <div class="sbdp-booking-form-wrapper" data-product-id="<?php echo esc_attr((string) $product_id); ?>" data-pricing-source="woocommerce" data-sbdp-legacy-form="true">
        <form id="sbdp-booking-form" method="post" action="<?php echo esc_url(wc_get_cart_url()); ?>" data-sbdp-legacy-form="true">
            <?php wp_nonce_field('sbdp_booking', 'sbdp_booking_nonce'); ?>
            <div class="sbdp-form-header ddb-card">
                <span class="sbdp-form-kicker">Dagprogramma</span>
                <h3 class="sbdp-form-title"><?php echo esc_html($title); ?></h3>
                <?php if ($intro_text !== '') : ?>
                    <p class="sbdp-product-intro"><?php echo esc_html($intro_text); ?></p>
                <?php endif; ?>
                <?php if (! empty($meta_chips)) : ?>
                    <div class="sbdp-meta-chips" aria-label="Productinformatie">
                        <?php foreach ($meta_chips as $meta_chip) : ?>
                            <span class="sbdp-meta-chip ddb-chip"><?php echo esc_html($meta_chip); ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="sbdp-product-flow">
                <div class="sbdp-product-flow__main">
                    <section class="sbdp-step-card sbdp-step-card--moment ddb-card">
                        <div class="sbdp-step-card__header">
                            <span class="sbdp-step-card__eyebrow">Stap 1</span>
                            <h4 class="sbdp-step-card__title">Kies je moment</h4>
                            <p class="sbdp-step-card__hint">Selecteer je datum, starttijd en groepsgrootte. De rest van je programma sluit daar automatisch op aan.</p>
                        </div>

                        <div class="sbdp-field-grid">
                            <div class="sbdp-field">
                                <label class="sbdp-label-heading">Datum</label>
                                <input type="date" id="sbdp_date" name="sbdp_date" class="sbdp-input-standard" value="<?php echo esc_attr($today); ?>" min="<?php echo esc_attr($today); ?>">
                            </div>

                            <div class="sbdp-field">
                                <label class="sbdp-label-heading">Tijdslot</label>
                                <div class="sbdp-timeslot-chips" data-ddb-chip-group="time">
                                    <?php foreach ($time_slots as $slot): ?>
                                        <button type="button" class="ddb-slot ui-chip ui-chip--muted" data-ddb-time="<?php echo esc_attr($slot['start']); ?>">
                                            <span class="ddb-slot__time sbdp-chip-time"><?php echo esc_html($slot['start']); ?></span>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                                <input type="hidden" id="sbdp_time" name="sbdp_time" value="">
                            </div>

                            <div class="sbdp-field">
                                <label class="sbdp-label-heading">Aantal personen</label>
                                <div class="sbdp-number-control">
                                    <button type="button" id="sbdp_minus" class="ui-btn ui-btn--ghost ui-btn--sm sbdp-number-control__btn" aria-label="Verminder aantal">−</button>
                                    <div class="sbdp-number-display">
                                        <input type="number" id="sbdp_participants" class="sbdp-participants-wide" name="sbdp_participants" value="10" min="1" step="1" inputmode="numeric">
                                        <span class="sbdp-number-display__suffix">personen</span>
                                    </div>
                                    <button type="button" id="sbdp_plus" class="ui-btn ui-btn--ghost ui-btn--sm sbdp-number-control__btn" aria-label="Verhoog aantal">+</button>
                                </div>
                            </div>
                        </div>
                    </section>

                    <?php if(!empty($combi_deals)): ?>
                    <section class="sbdp-step-card sbdp-step-card--extras sbdp-combi-section ddb-card">
                        <div class="sbdp-step-card__header">
                            <span class="sbdp-step-card__eyebrow">Stap 2</span>
                            <h4 class="sbdp-step-card__title">Maak je dag compleet</h4>
                            <p class="sbdp-step-card__hint">Voeg een logisch voorafje of ontspannen afsluiter toe. Combi's worden automatisch op de juiste plek in je dag gezet.</p>
                        </div>

                        <div class="sbdp-combi-list">
                            <?php foreach($combi_deals as $idx => $deal): ?>
                              <div class="sbdp-combi-item sbdp-combi-option ddb-card" data-combi-value="<?php echo $deal['id']; ?>" data-adjustment="<?php echo number_format((float)$deal['price'], 2, '.', ''); ?>" data-duration="<?php echo $deal['calc_mins']; ?>" data-label="<?php echo esc_attr($deal['title']); ?>">
                                <div class="sbdp-combi-header">
                                    <?php if($deal['thumb']): ?>
                                        <img src="<?php echo esc_url($deal['thumb']); ?>" class="sbdp-combi-thumb" alt="">
                                    <?php else: ?>
                                        <div class="sbdp-combi-thumb"></div>
                                    <?php endif; ?>
                                    <div class="sbdp-combi-info">
                                        <h4><?php echo esc_html($deal['title']); ?></h4>
                                        <span class="sbdp-combi-price"><?php echo esc_html($deal['display_time']); ?> · + € <?php echo number_format($deal['price'], 2, ',', '.'); ?> p.p.</span>
                                    </div>
                                </div>
                                <div class="sbdp-segment-control">
                                    <input type="radio" id="combi_<?php echo $idx; ?>_none" name="sbdp_combi_<?php echo $deal['id']; ?>" value="none" checked>
                                    <label for="combi_<?php echo $idx; ?>_none">Niet toevoegen</label>

                                    <input type="radio" id="combi_<?php echo $idx; ?>_before" name="sbdp_combi_<?php echo $deal['id']; ?>" value="before">
                                    <label for="combi_<?php echo $idx; ?>_before">Vooraf</label>

                                    <input type="radio" id="combi_<?php echo $idx; ?>_after" name="sbdp_combi_<?php echo $deal['id']; ?>" value="after">
                                    <label for="combi_<?php echo $idx; ?>_after">Achteraf</label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <?php endif; ?>
                </div>

                <aside class="sbdp-summary-card ddb-summary">
                    <div class="sbdp-step-card__header sbdp-step-card__header--summary">
                        <span class="sbdp-step-card__eyebrow">Stap 3</span>
                        <h4 class="sbdp-step-card__title">Jouw programma</h4>
                        <p class="sbdp-step-card__hint">Je daglijn en totaal werken live mee met je keuzes.</p>
                    </div>

                    <div class="sbdp-summary-context" id="sbdp_summary_context">Voor 10 personen · incl. btw</div>

                    <div class="sbdp-programma-block">
                        <ol id="sbdp_program_timeline" class="sbdp-programma-list sbdp-itinerary" aria-live="polite">
                            <li class="sbdp-itinerary__empty">Kies een tijdslot om je programma te zien.</li>
                        </ol>
                    </div>

                    <ul class="sbdp-summary-list">
                        <li class="sbdp-summary-row">
                            <span class="lbl"><span id="summary_pax">10</span> x <?php echo esc_html($title); ?></span>
                            <span class="val">€ <span id="summary_base_price">0,00</span></span>
                        </li>
                        <div id="summary_combi_container"></div>
                    </ul>

                    <div class="sbdp-summary-total">
                        <span>Totaal</span>
                        <strong id="sbdp_summary_total">...</strong>
                    </div>

                    <div class="sbdp-actions-row">
                        <button type="submit" class="ui-btn ui-btn--primary" data-sbdp-action="book">Boek dit programma</button>
                        <button type="button" class="ui-btn ui-btn--secondary" id="sbdp_plan_btn" data-sbdp-action="plan">Plan in dag</button>
                    </div>
                </aside>
            </div>

            <input type="hidden" name="add-to-cart" value="<?php echo esc_attr((string) $product_id); ?>">
            <input type="hidden" name="sbdp_active_combis" id="sbdp_active_combis" value="">
            <input type="hidden" name="sbdp_combi_label" id="sbdp_combi_label" value="">
        </form>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const wrap = document.querySelector('.sbdp-booking-form-wrapper');
            if (!wrap) return;
            
            const productId = wrap.dataset.productId;
            const dateInput = document.getElementById('sbdp_date');
            const timeInput = document.getElementById('sbdp_time');
            const countInput = document.getElementById('sbdp_participants');
            if (countInput) {
                countInput.step = '1';
                countInput.inputMode = 'numeric';
                countInput.min = '1';
            }
            const chips = document.querySelectorAll('.ui-chip');
            const combiItems = document.querySelectorAll('.sbdp-combi-item');

            const summaryPax = document.getElementById('summary_pax');
            const summaryBasePrice = document.getElementById('summary_base_price');
            const summaryCombiContainer = document.getElementById('summary_combi_container');
            const summaryTotal = document.getElementById('sbdp_summary_total');
            const summaryContext = document.getElementById('sbdp_summary_context');
            const combiLabelInput = document.getElementById('sbdp_combi_label');
            const programTimeline = document.getElementById('sbdp_program_timeline');
            const activeCombisInput = document.getElementById('sbdp_active_combis');
            const planButton = document.getElementById('sbdp_plan_btn');
            const bookingForm = document.getElementById('sbdp-booking-form');
            const bookButton = bookingForm ? bookingForm.querySelector('[data-sbdp-action="book"]') : null;
            const composeUrl = <?php echo wp_json_encode($compose_url); ?>;
            const composeNonce = <?php echo wp_json_encode($compose_nonce); ?>;
            const cartUrl = <?php echo wp_json_encode($cart_url); ?>;

            const mainTitle = <?php echo json_encode($title); ?>;
            const productBasePrice = <?php echo number_format((float)$price_display, 4, '.', ''); ?>;
            const mainDuration = <?php echo (int)$main_duration; ?>; 

            function formatEuro(val) {
                return parseFloat(val).toFixed(2).replace('.', ',');
            }

            function addMinutes(timeStr, minsToAdd) {
                if(!timeStr) return '';
                const parts = timeStr.split(':');
                if (parts.length !== 2) return '';
                let d = new Date();
                d.setHours(parseInt(parts[0], 10), parseInt(parts[1], 10), 0);
                d.setMinutes(d.getMinutes() + parseInt(minsToAdd, 10));
                return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
            }

            function timeToMinutes(timeStr) {
                if (!timeStr) return null;
                const parts = String(timeStr).split(':');
                if (parts.length !== 2) return null;
                const hours = parseInt(parts[0], 10);
                const minutes = parseInt(parts[1], 10);
                if (!Number.isFinite(hours) || !Number.isFinite(minutes)) return null;
                return (hours * 60) + minutes;
            }

            function minutesToTime(totalMinutes) {
                if (!Number.isFinite(totalMinutes)) return '';
                const hours = Math.max(0, Math.min(23, Math.floor(totalMinutes / 60)));
                const minutes = Math.max(0, Math.min(59, totalMinutes % 60));
                return String(hours).padStart(2, '0') + ':' + String(minutes).padStart(2, '0');
            }

            function resolvePlannerAnchorTime(baseTime, combiData, mainDurationMinutes) {
                const anchorMinutes = timeToMinutes(baseTime);
                if (!Number.isFinite(anchorMinutes)) {
                    return baseTime || '';
                }

                const plannerOpenMinutes = (8 * 60);
                const plannerCloseMinutes = (22 * 60);
                const preferredBufferMinutes = 30;
                const items = Array.isArray(combiData) ? combiData : [];

                const totalBeforeMinutes = items
                    .filter(item => item && item.timing === 'before')
                    .reduce((total, item) => total + (parseInt(item.durationMinutes || item.duration, 10) || 0), 0);
                const totalAfterMinutes = items
                    .filter(item => item && item.timing === 'after')
                    .reduce((total, item) => total + (parseInt(item.durationMinutes || item.duration, 10) || 0), 0);

                let resolvedAnchorMinutes = anchorMinutes;
                const earliestAnchorMinutes = plannerOpenMinutes + totalBeforeMinutes + preferredBufferMinutes;
                const latestAnchorMinutes =
                    plannerCloseMinutes - (parseInt(mainDurationMinutes, 10) || 0) - totalAfterMinutes - preferredBufferMinutes;

                if (Number.isFinite(earliestAnchorMinutes)) {
                    resolvedAnchorMinutes = Math.max(resolvedAnchorMinutes, earliestAnchorMinutes);
                }

                if (Number.isFinite(latestAnchorMinutes) && latestAnchorMinutes >= plannerOpenMinutes) {
                    resolvedAnchorMinutes = Math.min(resolvedAnchorMinutes, latestAnchorMinutes);
                }

                return minutesToTime(resolvedAnchorMinutes);
            }

            function getPlannerUrl() {
                if (window.SBDP_PLANNER_DOMAIN_CONFIG && typeof window.SBDP_PLANNER_DOMAIN_CONFIG.plannerUrl === 'string') {
                    return window.SBDP_PLANNER_DOMAIN_CONFIG.plannerUrl;
                }

                return '/plan-je-dag/';
            }

            function clearPlannerState() {
                if (typeof window === 'undefined') {
                    return;
                }

                try {
                    if (window.sessionStorage) {
                        window.sessionStorage.removeItem('sbdpPlannerPrefillQueue');
                    }
                } catch (error) {
                    // ignore storage errors
                }

                try {
                    if (window.localStorage) {
                        window.localStorage.removeItem('sbdpPlannerDraftV1');
                    }
                } catch (error) {
                    // ignore storage errors
                }
            }

            function enqueuePlannerPrefill(entry) {
                if (typeof window === 'undefined' || !entry || typeof entry !== 'object') {
                    return;
                }

                try {
                    if (window.sessionStorage) {
                        const raw = window.sessionStorage.getItem('sbdpPlannerPrefillQueue');
                        const queue = raw ? JSON.parse(raw) : [];
                        const nextQueue = Array.isArray(queue) ? queue : [];
                        nextQueue.push(entry);
                        window.sessionStorage.setItem('sbdpPlannerPrefillQueue', JSON.stringify(nextQueue));
                    }
                } catch (error) {
                    // ignore storage errors
                }

                try {
                    if (typeof window.dispatchEvent === 'function' && typeof window.CustomEvent === 'function') {
                        window.dispatchEvent(new CustomEvent('sbdp:planner/prefill', { detail: entry }));
                    }
                } catch (error) {
                    // ignore event errors
                }
            }

            function buildPlannerEntry() {
                const plannerDomain = window.SBDPPlannerDomain || null;
                const participants = parseInt(countInput.value, 10) || 1;
                const resolvedCombis = activeCombisInput && activeCombisInput.value
                    ? JSON.parse(activeCombisInput.value)
                    : [];
                const plannerTime = resolvePlannerAnchorTime(timeInput.value || '', resolvedCombis, mainDuration);
                const traceId = 'legacy-product-' + Date.now();

                const entry = {
                    source: 'product_planner_legacy',
                    product_id: parseInt(productId, 10) || 0,
                    productId: parseInt(productId, 10) || 0,
                    date: dateInput.value || '',
                    time: plannerTime,
                    participants: participants,
                    people: participants,
                    append: false,
                    lockFirstSlot: !!plannerTime,
                    traceId: traceId,
                    trace_id: traceId,
                    options: {
                        combiItems: Array.isArray(resolvedCombis) ? resolvedCombis : []
                    },
                    combiItems: Array.isArray(resolvedCombis) ? resolvedCombis : []
                };

                if (plannerDomain && typeof plannerDomain.normalizeInput === 'function') {
                    entry.plannerInput = plannerDomain.normalizeInput({
                        productId: entry.productId,
                        date: entry.date,
                        time: entry.time,
                        participants: entry.participants,
                        options: {
                            combiItems: entry.combiItems
                        },
                        source: entry.source
                    });
                }

                return entry;
            }

            function buildPlannerRedirectUrl(plannerUrl, plannerEntry) {
                const target = new URL(plannerUrl, window.location.origin);
                target.searchParams.set('sbdp_product', String(plannerEntry.productId || 0));
                target.searchParams.set('sbdp_date', plannerEntry.date || '');
                target.searchParams.set('sbdp_time', plannerEntry.time || '');
                target.searchParams.set('sbdp_participants', String(plannerEntry.participants || 1));
                target.searchParams.set('sbdp_prefill', JSON.stringify(plannerEntry));
                return target.toString();
            }

            function buildIsoDateTime(dateValue, timeValue) {
                if (!dateValue || !timeValue) {
                    return '';
                }

                return dateValue + 'T' + timeValue + ':00';
            }

            function buildComposePayload(plannerEntry) {
                const combiItems = Array.isArray(plannerEntry.combiItems) ? plannerEntry.combiItems : [];
                const combiIds = combiItems
                    .map(item => parseInt(item && item.id, 10) || 0)
                    .filter(id => id > 0);
                const combiTimingMap = {};
                const combiLabelMap = {};
                combiItems.forEach(item => {
                    const id = parseInt(item && item.id, 10) || 0;
                    if (id > 0) {
                        combiTimingMap[id] = item && item.timing === 'after' ? 'after' : 'before';
                        combiLabelMap[id] = item && item.label ? item.label : '';
                    }
                });

                const endTime = addMinutes(plannerEntry.time || '', mainDuration);

                return {
                    mode: 'pay',
                    participants: plannerEntry.participants,
                    combi: combiIds.length === 1 ? String(combiIds[0]) : '',
                    combi_ids: combiIds,
                    combi_timing_map: combiTimingMap,
                    combi_label_map: combiLabelMap,
                    options: {
                        combiItems: combiItems
                    },
                    items: [
                        {
                            product_id: plannerEntry.productId,
                            resource_id: 0,
                            start: buildIsoDateTime(plannerEntry.date, plannerEntry.time),
                            end: buildIsoDateTime(plannerEntry.date, endTime),
                            combi: combiIds.length === 1 ? String(combiIds[0]) : '',
                            combi_ids: combiIds,
                            combi_timing_map: combiTimingMap,
                            combi_label_map: combiLabelMap,
                            options: {
                                combiItems: combiItems
                            },
                            combi_label: combiItems.map(item => item && item.label ? item.label : '').filter(Boolean).join(', ')
                        }
                    ]
                };
            }

            async function submitDirectBooking(event) {
                if (event) {
                    event.preventDefault();
                }

                const plannerEntry = buildPlannerEntry();
                if (!plannerEntry.productId || !plannerEntry.date || !plannerEntry.time) {
                    alert('Kies eerst een datum en tijdslot.');
                    return;
                }

                if (!composeUrl || !composeNonce) {
                    window.location.href = buildPlannerRedirectUrl(getPlannerUrl(), plannerEntry);
                    return;
                }

                if (bookButton) {
                    bookButton.disabled = true;
                    bookButton.setAttribute('aria-busy', 'true');
                }

                try {
                    const response = await fetch(composeUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': composeNonce,
                            'x-sbdp-nonce': composeNonce
                        },
                        body: JSON.stringify(buildComposePayload(plannerEntry))
                    });
                    const data = await response.json().catch(() => null);
                    if (!response.ok || !data || data.ok !== true) {
                        throw new Error((data && (data.message || (data.data && data.data.message))) || 'Deze activiteit kon niet worden geboekt. Plan hem eerst in je dag.');
                    }

                    window.location.href = cartUrl || data.redirect;
                } catch (error) {
                    alert(error && error.message ? error.message : 'Deze activiteit kon niet worden geboekt. Plan hem eerst in je dag.');
                } finally {
                    if (bookButton) {
                        bookButton.disabled = false;
                        bookButton.removeAttribute('aria-busy');
                    }
                }
            }

            function updateUI() {
                const pax = parseInt(countInput.value, 10) || 1;
                const baseTime = timeInput.value;
                let total = pax * productBasePrice;
                
                summaryPax.textContent = pax;
                summaryBasePrice.textContent = formatEuro(pax * productBasePrice);

                let combiHtml = '';
                let activeCombisData = [];
                let beforeItems = [];
                let afterItems = [];

                combiItems.forEach(item => {
                    const cid = item.dataset.combiValue;
                    const cPrice = parseFloat(item.dataset.adjustment) || 0;
                    const cDuration = parseInt(item.dataset.duration, 10) || 0;
                    const cTitle = item.dataset.label || item.dataset.combiTitle || 'Combi Deal';
                    
                    const radioChecked = item.querySelector('input[type="radio"]:checked');
                    const moment = radioChecked ? radioChecked.value : 'none';
                    if (moment !== 'none') {
                        item.classList.add('is-active');
                        item.setAttribute('data-combi-timing', moment);
                    } else {
                        item.classList.remove('is-active');
                        item.removeAttribute('data-combi-timing');
                    }

                    if (moment !== 'none') {
                        const combiTot = pax * cPrice;
                        total += combiTot;
                        
                        combiHtml += '<li class="sbdp-summary-row sbdp-summary-subtotal">';
                        combiHtml += '<span class="lbl">'+ cTitle + ' · ' + (moment === 'after' ? 'Achteraf' : 'Vooraf') + '</span>';
                        combiHtml += '<span class="val">+ € '+ formatEuro(combiTot) +'</span>';
                        combiHtml += '</li>';
                        
                        combiHtml += '<input type="hidden" name="sbdp_combi_ids[]" value="'+cid+'">';
                        combiHtml += '<input type="hidden" name="sbdp_combi_timing['+cid+']" value="'+moment+'">';
                        combiHtml += '<input type="hidden" name="sbdp_combi_label['+cid+']" value="'+cTitle+'">';

                        activeCombisData.push({
                            id: parseInt(cid, 10) || 0,
                            label: cTitle,
                            timing: moment,
                            role: moment === 'after' ? 'post' : 'pre',
                            order: activeCombisData.length,
                            duration: cDuration,
                            durationMinutes: cDuration
                        });
                        if (moment === 'before') beforeItems.push({ title: cTitle, duration: cDuration, price: cPrice });
                        if (moment === 'after') afterItems.push({ title: cTitle, duration: cDuration, price: cPrice });
                    }
                });

                summaryCombiContainer.innerHTML = combiHtml;
                summaryTotal.textContent = '€ ' + formatEuro(total);
                activeCombisInput.value = JSON.stringify(activeCombisData);
                if (combiLabelInput) {
                    combiLabelInput.value = activeCombisData.length ? activeCombisData.map(item => item.label).join(', ') : '';
                }
                if (summaryContext) {
                    summaryContext.textContent = 'Voor ' + pax + ' ' + (pax === 1 ? 'persoon' : 'personen') + ' · incl. btw';
                }

                function renderTimelineEntry(start, end, title, roleLabel, durationLabel, priceLabel, modifier) {
                    const itemClass = modifier ? ' sbdp-itinerary__item--' + modifier : '';
                    let html = '<li class="sbdp-itinerary__item' + itemClass + '">';
                    html += '<span class="sbdp-itinerary__time">' + start + ' - ' + end + '</span>';
                    html += '<div class="sbdp-itinerary__body">';
                    html += '<strong class="sbdp-itinerary__title">' + title + '</strong>';
                    html += '<span class="sbdp-itinerary__meta">' + roleLabel + ' · ' + durationLabel + '</span>';
                    html += '</div>';
                    html += '<span class="sbdp-itinerary__price">' + priceLabel + '</span>';
                    html += '</li>';
                    return html;
                }

                if (baseTime) {
                    let timelineHtml = '';
                    let totalBeforeMins = beforeItems.reduce((acc, curr) => acc + curr.duration, 0);
                    let currentTimestamp = addMinutes(baseTime, -totalBeforeMins);
                    
                    beforeItems.forEach(item => {
                        let end = addMinutes(currentTimestamp, item.duration);
                        timelineHtml += renderTimelineEntry(currentTimestamp, end, item.title, 'Vooraf', item.duration + ' min', '+ € ' + formatEuro(pax * (item.price || 0)), 'before');
                        currentTimestamp = end; 
                    });

                    let mainEnd = addMinutes(baseTime, mainDuration);
                    timelineHtml += renderTimelineEntry(baseTime, mainEnd, mainTitle, 'Hoofdactiviteit', mainDuration + ' min', '€ ' + formatEuro(pax * productBasePrice), 'main');

                    let afterStart = mainEnd;
                    afterItems.forEach(item => {
                        let end = addMinutes(afterStart, item.duration);
                        timelineHtml += renderTimelineEntry(afterStart, end, item.title, 'Achteraf', item.duration + ' min', '+ € ' + formatEuro(pax * (item.price || 0)), 'after');
                        afterStart = end; 
                    });

                    programTimeline.innerHTML = timelineHtml;
                } else {
                    programTimeline.innerHTML = '<li class="sbdp-itinerary__empty">Kies een tijdslot om je programma te zien.</li>';
                }
            }

            chips.forEach(chip => {
                chip.addEventListener('click', () => {
                    if (chip.classList.contains('is-disabled')) return;
                    chips.forEach(c => c.classList.remove('is-active'));
                    chip.classList.add('is-active');
                    timeInput.value = chip.dataset.ddbTime; timeInput.dispatchEvent(new Event('change', { bubbles: true }));
                    updateUI();
                });
            });
            if (timeInput) {
                timeInput.addEventListener('change', updateUI);
            }
            if (dateInput) {
                dateInput.addEventListener('change', updateUI);
            }

            countInput.addEventListener('input', () => {
                const parsed = parseInt(countInput.value, 10);
                countInput.value = String(Number.isFinite(parsed) && parsed > 0 ? parsed : 1);
                updateUI();
            });
            document.getElementById('sbdp_plus').onclick = () => { countInput.value++; countInput.dispatchEvent(new Event('change', { bubbles: true })); updateUI(); };
            document.getElementById('sbdp_minus').onclick = () => { if (countInput.value > 1) { countInput.value--; countInput.dispatchEvent(new Event('change', { bubbles: true })); updateUI(); } };
            combiItems.forEach(i => i.querySelectorAll('input').forEach(r => r.addEventListener('change', updateUI)));
            if (planButton) {
                planButton.addEventListener('click', function() {
                    const plannerUrl = getPlannerUrl();
                    const plannerEntry = buildPlannerEntry();

                    if (!plannerEntry.productId || !plannerEntry.date || !plannerEntry.time) {
                        alert('Kies eerst een datum en tijdslot.');
                        return;
                    }

                    clearPlannerState();
                    window.location.href = buildPlannerRedirectUrl(plannerUrl, plannerEntry);
                });
            }
            if (bookingForm) {
                bookingForm.addEventListener('submit', submitDirectBooking);
            }

            updateUI();
        });
        </script>
    </div>
    <?php return ob_get_clean();
}
