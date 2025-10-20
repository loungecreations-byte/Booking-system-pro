<?php

namespace BSPModule\Core\Admin;

use WP_Post;
use WP_Query;
use BSPModule\Core\Audit\AuditLogger;
use wpdb;

use function _n;
use function absint;
use function add_action;
use function add_menu_page;
use function add_submenu_page;
use function admin_url;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_url;
use function get_option;
use function get_permalink;
use function get_post_status;
use function get_posts;
use function get_the_title;
use function gmdate;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function maybe_unserialize;
use function number_format_i18n;
use function ob_get_clean;
use function ob_start;
use function post_type_exists;
use function sanitize_key;
use function sanitize_title;
use function sprintf;
use function ucfirst;
use function strtotime;
use function wp_count_posts;
use function wp_die;
use function wp_json_encode;
use function wp_reset_postdata;
use function __;

use const ARRAY_A;

/**
 * Admin menu registration and dashboard helpers.
 */
final class AdminMenu
{
    private const CAPABILITY = 'manage_woocommerce';
    private const FALLBACK_CAPABILITY = 'manage_options';
    /**
     * Cache for table existence checks during a single request.
     *
     * @var array<string, bool>
     */
    private static $table_exists_cache = array();

    public static function init(): void
    {
        add_action('admin_menu', array( __CLASS__, 'menu' ));
    }

    public static function menu(): void
    {
        $capability = self::resolveCapability();

        add_menu_page(
            __('Bookings', 'sbdp'),
            __('Bookings', 'sbdp'),
            $capability,
            'sbdp_bookings',
            array( __CLASS__, 'render_overview' ),
            'dashicons-calendar-alt',
            56
        );

        add_submenu_page(
            'sbdp_bookings',
            __('Bookable Items', 'sbdp'),
            __('Bookable Items', 'sbdp'),
            $capability,
            'edit.php?post_type=bookable_item'
        );

        add_submenu_page(
            'sbdp_bookings',
            __('Resources', 'sbdp'),
            __('Resources', 'sbdp'),
            $capability,
            'edit.php?post_type=bookable_resource'
        );

        add_submenu_page(
            'sbdp_bookings',
            __('Availability', 'sbdp'),
            __('Availability', 'sbdp'),
            $capability,
            'sbdp_availability',
            array( __CLASS__, 'render_availability' )
        );

        add_submenu_page(
            'sbdp_bookings',
            __('Pricing & Rules', 'sbdp'),
            __('Pricing & Rules', 'sbdp'),
            $capability,
            'sbdp_pricing',
            array( __CLASS__, 'render_pricing' )
        );

        add_submenu_page(
            'sbdp_bookings',
            __('Planner Frontend', 'sbdp'),
            __('Planner Frontend', 'sbdp'),
            $capability,
            'sbdp_plan_link',
            array( __CLASS__, 'render_plan_link' )
        );

        add_submenu_page(
            'sbdp_bookings',
            __('Auditlog', 'sbdp'),
            __('Auditlog', 'sbdp'),
            $capability,
            'sbdp_audit_log',
            array( __CLASS__, 'render_audit_log' )
        );
    }
    public static function capability(): string
    {
        if (! function_exists('current_user_can')) {
            return self::FALLBACK_CAPABILITY;
        }

        if (current_user_can(self::CAPABILITY)) {
            return self::CAPABILITY;
        }

        return self::FALLBACK_CAPABILITY;
    }

    private static function resolveCapability(): string
    {
        return self::capability();
    }
    public static function render_overview(): void
    {
        $bootstrap = self::get_dashboard_bootstrap();

        echo '<div class="wrap sbdp-dashboard">';
        echo '<h1>' . esc_html__('Bookings dashboard', 'sbdp') . '</h1>';
        echo '<p class="description">' . esc_html__('Realtime overzicht van plannerstatistieken, kanaalprestaties en snelle acties.', 'sbdp') . '</p>';
        echo '<div id="sbdp-dashboard-root"></div>';
        echo '<noscript>';
        echo '<div class="notice notice-info"><p>' . esc_html__('JavaScript is vereist om het interactieve dashboard te tonen. Hieronder vind je een beknopte samenvatting.', 'sbdp') . '</p></div>';
        echo self::render_fallback_markup($bootstrap['metrics'], $bootstrap['quickLinks']);
        echo '</noscript>';
        echo '</div>';
    }

    /**
     * Return dashboard bootstrap payload for scripts and fallbacks.
     */
    public static function get_dashboard_bootstrap(int $revenue_days = 7, int $upcoming_days = 14): array
    {
        $planner_page = self::locate_planner_page();

        return array(
            'metrics'            => self::collect_dashboard_metrics($revenue_days, $upcoming_days),
            'quickLinks'         => self::get_quick_links_config($planner_page),
            'availabilityWindow' => max(1, $upcoming_days),
            'plannerPageUrl'     => $planner_page instanceof WP_Post ? get_permalink($planner_page) : '',
        );
    }

    /**
     * Collect rich dashboard metrics for admin UI and REST responses.
     */
    public static function collect_dashboard_metrics(int $revenue_days = 7, int $upcoming_days = 14): array
    {
        $product_count  = self::count_bookable_products();
        $resource_count = self::count_resources();
        $orders_today   = self::summarise_today_orders();

        $bookings_table = self::get_bookings_table();

        $revenue_window = array(
            'start'    => gmdate('Y-m-d 00:00:00'),
            'end'      => gmdate('Y-m-d 23:59:59'),
            'days'     => max(1, $revenue_days),
            'count'    => 0,
            'revenue'  => 0.0,
            'currency' => (string) get_option('woocommerce_currency', 'EUR'),
        );
        $month_revenue = array(
            'start'       => gmdate('Y-m-01 00:00:00'),
            'end'         => gmdate('Y-m-d 23:59:59'),
            'count'       => 0,
            'revenue'     => 0.0,
            'currency'    => (string) get_option('woocommerce_currency', 'EUR'),
            'daysElapsed' => max(1, (int) gmdate('j')),
            'daysInMonth' => max(1, (int) gmdate('t')),
        );
        $pipeline = array(
            'windowDays'       => max(1, $upcoming_days),
            'upcomingTotal'    => 0,
            'pendingApprovals' => 0,
            'upcomingByDay'    => array(),
        );
        $channels = array();

        if ($bookings_table) {
            $revenue_window = self::calculate_revenue_window($bookings_table, $revenue_days);
            $month_revenue  = self::calculate_month_revenue($bookings_table);
            $pipeline       = self::calculate_upcoming_pipeline($bookings_table, $upcoming_days);
            $channels       = self::calculate_channel_breakdown($bookings_table, $revenue_days);
        }

        $average_order_value = $revenue_window['count'] > 0
            ? $revenue_window['revenue'] / $revenue_window['count']
            : 0.0;

        $projection = self::project_month_revenue(
            (float) $month_revenue['revenue'],
            (int) $month_revenue['daysElapsed'],
            (int) $month_revenue['daysInMonth']
        );

        return array(
            'summary'  => array(
                'productCount'      => $product_count,
                'resourceCount'     => $resource_count,
                'bookingsToday'     => array(
                    'total'     => $orders_today['total'],
                    'breakdown' => $orders_today['breakdown'],
                ),
                'revenueLastNDays'  => array(
                    'days'     => $revenue_window['days'],
                    'count'    => $revenue_window['count'],
                    'total'    => $revenue_window['revenue'],
                    'currency' => $revenue_window['currency'],
                    'start'    => $revenue_window['start'],
                    'end'      => $revenue_window['end'],
                ),
                'revenueThisMonth'  => array(
                    'total'    => $month_revenue['revenue'],
                    'count'    => $month_revenue['count'],
                    'currency' => $month_revenue['currency'],
                ),
                'averageOrderValue' => $average_order_value,
                'revenueProjection' => $projection,
            ),
            'pipeline' => $pipeline,
            'channels' => $channels,
            'updatedAt' => gmdate('c'),
        );
    }

    public static function render_availability(): void
    {
        echo '<div class="wrap sbdp-admin-availability">';
        echo '<h1>' . esc_html__('Availability & Calendar Editor', 'sbdp') . '</h1>';
        echo '<p class="description">' . esc_html__('Beheer beschikbaarheidsregels per resource direct in de kalender.', 'sbdp') . '</p>';
        echo '<style>.sbdp-admin-calendar{min-height:480px;border:1px dashed #c3c4c7;background:#fff;padding:16px;margin-top:1em;}</style>';
        echo '<div class="notice notice-info inline"><p>' . esc_html__('Kies een resource in de linkerzijbalk en voeg blokken toe via de kalender. Publiceer om wijzigingen op te slaan.', 'sbdp') . '</p></div>';
        echo '<div id="sbdp-av-app" class="sbdp-admin-calendar" aria-live="polite"></div>';
        echo '<noscript><div class="notice notice-error inline"><p>' . esc_html__('JavaScript is vereist om de beschikbaarheidseditor te gebruiken.', 'sbdp') . '</p></div></noscript>';
        echo '</div>';
    }

    public static function render_pricing(): void
    {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Pricing Rules & Fees', 'sbdp') . '</h1>';
        echo '<div id="sbdp-pricing-app"></div>';
        echo '</div>';
    }

    public static function render_plan_link(): void
    {
        $planner_page = self::locate_planner_page();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Planner Frontend', 'sbdp') . '</h1>';

        if ($planner_page instanceof WP_Post) {
            printf(
                '<a class="button button-primary" target="_blank" rel="noopener" href="%1$s">%2$s</a>',
                esc_url(get_permalink($planner_page)),
                esc_html__('Open planner', 'sbdp')
            );
        } else {
            echo '<p>' . esc_html__('Geen plannerpagina gevonden. Maak een pagina met de shortcode [sbdp_dayplanner].', 'sbdp') . '</p>';
        }

        echo '</div>';
    }
    public static function render_audit_log(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Je hebt geen toegang tot dit scherm.', 'sbdp'));
        }

        $entries = AuditLogger::recent(100);

        echo '<div class="wrap sbdp-audit-log">';
        echo '<h1>' . esc_html__('Auditlogboek', 'sbdp') . '</h1>';

        if ($entries === array()) {
            echo '<p>' . esc_html__('Er zijn nog geen auditmeldingen geregistreerd.', 'sbdp') . '</p>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Tijd', 'sbdp') . '</th>';
        echo '<th>' . esc_html__('Actie', 'sbdp') . '</th>';
        echo '<th>' . esc_html__('Context', 'sbdp') . '</th>';
        echo '<th>' . esc_html__('Gebruiker', 'sbdp') . '</th>';
        echo '<th>' . esc_html__('Niveau', 'sbdp') . '</th>';
        echo '<th>' . esc_html__('Details', 'sbdp') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($entries as $entry) {
            $context  = $entry['context'] !== '' ? $entry['context'] : __('Algemeen', 'sbdp');
            $actor    = $entry['actor_name'] !== '' ? $entry['actor_name'] : ( $entry['actor_id'] > 0 ? '#' . $entry['actor_id'] : __('Systeem', 'sbdp') );
            $severity = ucfirst((string) $entry['severity']);

            $details = '';
            if (isset($entry['payload']['data']) && $entry['payload']['data'] !== array()) {
                $details = wp_json_encode($entry['payload']['data']);
            } elseif (isset($entry['payload']['context']) && $entry['payload']['context'] !== array()) {
                $details = wp_json_encode($entry['payload']['context']);
            }

            echo '<tr>';
            echo '<td>' . esc_html($entry['created_at']) . '</td>';
            echo '<td>' . esc_html($entry['action']) . '</td>';
            echo '<td>' . esc_html($context) . '</td>';
            echo '<td>' . esc_html($actor) . '</td>';
            echo '<td>' . esc_html($severity) . '</td>';
            echo '<td>' . ( $details ? '<code>' . esc_html($details) . '</code>' : '&mdash;' ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }


    private static function render_fallback_markup(array $metrics, array $quick_links): string
    {
        ob_start();
        echo '<div class="sbdp-dashboard-fallback">';

        if (! empty($metrics['summary'])) {
            $summary = $metrics['summary'];
            echo '<ul class="sbdp-dashboard-fallback__metrics">';
            echo '<li><strong>' . esc_html__('Boekbare activiteiten', 'sbdp') . ':</strong> ' . esc_html(number_format_i18n((int) ( $summary['productCount'] ?? 0 ))) . '</li>';
            echo '<li><strong>' . esc_html__('Resources', 'sbdp') . ':</strong> ' . esc_html(number_format_i18n((int) ( $summary['resourceCount'] ?? 0 ))) . '</li>';

            if (isset($summary['bookingsToday']['total'])) {
                $label = _n('Boeking vandaag', 'Boekingen vandaag', (int) $summary['bookingsToday']['total'], 'sbdp');
                $status = self::format_status_breakdown($summary['bookingsToday']['breakdown'] ?? array());
                echo '<li><strong>' . esc_html($label) . ':</strong> ' . esc_html(number_format_i18n((int) $summary['bookingsToday']['total']));
                if ('' !== $status) {
                    echo ' (' . esc_html($status) . ')';
                }
                echo '</li>';
            }

            if (isset($summary['revenueLastNDays']['total'])) {
                $currency = $summary['revenueLastNDays']['currency'] ?? (string) get_option('woocommerce_currency', 'EUR');
                echo '<li><strong>' . esc_html__('Omzet (laatste periode)', 'sbdp') . ':</strong> ' . esc_html(number_format_i18n((float) $summary['revenueLastNDays']['total'], 2)) . ' ' . esc_html($currency) . '</li>';
            }

            echo '</ul>';
        }

        if (! empty($quick_links)) {
            echo '<ul class="sbdp-dashboard-fallback__links">';
            foreach ($quick_links as $link) {
                $label       = isset($link['label']) ? (string) $link['label'] : '';
                $url         = isset($link['url']) ? (string) $link['url'] : '';
                $description = isset($link['description']) ? (string) $link['description'] : '';
                $target      = isset($link['target']) ? (string) $link['target'] : '';

                if ('' === $url) {
                    echo '<li>' . esc_html($label);
                    if ('' !== $description) {
                        echo ' - ' . esc_html($description);
                    }
                    echo '</li>';
                    continue;
                }

                $attrs = '';
                if ('' !== $target) {
                    $attrs = ' target="' . esc_attr($target) . '" rel="noopener noreferrer"';
                }
                echo '<li><a href="' . esc_url($url) . '"' . $attrs . '>' . esc_html($label) . '</a>';
                if ('' !== $description) {
                    echo ' - ' . esc_html($description);
                }
                echo '</li>';
            }
            echo '</ul>';
        }

        echo '</div>';

        $html = ob_get_clean();
        return is_string($html) ? $html : '';
    }

    private static function get_quick_links_config(?WP_Post $planner_page): array
    {
        $links = array(
            array(
                'label' => __('Nieuwe activiteit maken', 'sbdp'),
                'url'   => admin_url('post-new.php?post_type=product'),
                'type'  => 'secondary',
                'target' => '',
                'description' => '',
            ),
            array(
                'label' => __('Resources beheren', 'sbdp'),
                'url'   => admin_url('edit.php?post_type=bookable_resource'),
                'type'  => 'secondary',
                'target' => '',
                'description' => '',
            ),
            array(
                'label' => __('Beschikbaarheids- en prijsregels', 'sbdp'),
                'url'   => admin_url('admin.php?page=sbdp_pricing'),
                'type'  => 'secondary',
                'target' => '',
                'description' => '',
            ),
        );

        if ($planner_page instanceof WP_Post) {
            $links[] = array(
                'label' => __('Plannerpagina bekijken', 'sbdp'),
                'url'   => get_permalink($planner_page),
                'type'  => 'primary',
                'target' => '_blank',
                'description' => '',
            );
        } else {
            $links[] = array(
                'label'       => __('Plannerpagina ontbreekt', 'sbdp'),
                'url'         => '',
                'type'        => 'notice',
                'target'      => '',
                'description' => __('Maak een pagina met shortcode [sbdp_dayplanner] en publiceer deze.', 'sbdp'),
            );
        }

        return $links;
    }

    private static function count_bookable_products(): int
    {
        $query = new WP_Query(
            array(
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'tax_query'      => array(
                    array(
                        'taxonomy' => 'product_type',
                        'field'    => 'slug',
                        'terms'    => array( 'bookable_service' ),
                    ),
                ),
            )
        );

        $count = (int) $query->found_posts;
        wp_reset_postdata();

        return $count;
    }

    private static function count_resources(): int
    {
        $counts = wp_count_posts('bookable_resource');

        return isset($counts->publish) ? (int) $counts->publish : 0;
    }

    private static function summarise_today_orders(): array
    {
        $summary = array(
            'total'     => 0,
            'breakdown' => array(),
        );

        if (! post_type_exists('shop_order')) {
            return $summary;
        }

        $statuses = (array) apply_filters(
            'sbdp_dashboard_order_statuses',
            array( 'wc-processing', 'wc-on-hold', 'wc-pending', 'wc-completed' )
        );

        $args = array(
            'post_type'      => 'shop_order',
            'post_status'    => $statuses,
            'fields'         => 'ids',
            'posts_per_page' => (int) apply_filters('sbdp_dashboard_order_limit', 200),
            'orderby'        => 'date',
            'order'          => 'DESC',
            'date_query'     => array(
                array(
                    'after'     => gmdate('Y-m-d 00:00:00'),
                    'before'    => gmdate('Y-m-d 23:59:59'),
                    'inclusive' => true,
                ),
            ),
        );

        $orders = get_posts($args);

        if (empty($orders)) {
            return $summary;
        }

        $summary['total'] = count($orders);

        foreach ($orders as $order_id) {
            $status = get_post_status($order_id);
            if (! $status) {
                $status = 'wc-unknown';
            }

            if (! isset($summary['breakdown'][ $status ])) {
                $summary['breakdown'][ $status ] = 0;
            }

            $summary['breakdown'][ $status ]++;
        }

        return $summary;
    }

    private static function format_status_breakdown(array $breakdown): string
    {
        if (empty($breakdown)) {
            return '';
        }

        $parts = array();

        foreach ($breakdown as $status => $count) {
            if (function_exists('wc_get_order_status_name')) {
                $label = wc_get_order_status_name($status);
            } else {
                $label = $status;
            }

            $parts[] = sprintf(
                '%s: %s',
                $label,
                number_format_i18n((int) $count)
            );
        }

        return implode(' | ', $parts);
    }

    private static function calculate_revenue_window(string $table, int $days): array
    {
        global $wpdb;

        $days  = max(1, $days);
        $end   = gmdate('Y-m-d 23:59:59');
        $start = gmdate('Y-m-d 00:00:00', strtotime('-' . ( $days - 1 ) . ' days'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT currency, COUNT(*) AS total, COALESCE(SUM(total), 0) AS revenue FROM {$table} WHERE start_datetime BETWEEN %s AND %s GROUP BY currency",
                $start,
                $end
            ),
            ARRAY_A
        );

        $count    = 0;
        $revenue  = 0.0;
        $currency = '';

        foreach ($rows as $row) {
            $count   += (int) ( $row['total'] ?? 0 );
            $revenue += (float) ( $row['revenue'] ?? 0 );
            $row_currency = isset($row['currency']) ? (string) $row['currency'] : '';

            if ('' === $row_currency) {
                continue;
            }

            if ('' === $currency) {
                $currency = $row_currency;
            } elseif ($currency !== $row_currency) {
                $currency = 'multi';
            }
        }

        if ('' === $currency) {
            $currency = (string) get_option('woocommerce_currency', 'EUR');
        }

        return array(
            'start'    => $start,
            'end'      => $end,
            'days'     => $days,
            'count'    => $count,
            'revenue'  => $revenue,
            'currency' => $currency,
        );
    }

    private static function calculate_month_revenue(string $table): array
    {
        global $wpdb;

        $start = gmdate('Y-m-01 00:00:00');
        $end   = gmdate('Y-m-d 23:59:59');

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT currency, COUNT(*) AS total, COALESCE(SUM(total), 0) AS revenue FROM {$table} WHERE start_datetime BETWEEN %s AND %s GROUP BY currency",
                $start,
                $end
            ),
            ARRAY_A
        );

        $count    = 0;
        $revenue  = 0.0;
        $currency = '';

        foreach ($rows as $row) {
            $count   += (int) ( $row['total'] ?? 0 );
            $revenue += (float) ( $row['revenue'] ?? 0 );
            $row_currency = isset($row['currency']) ? (string) $row['currency'] : '';

            if ('' === $row_currency) {
                continue;
            }

            if ('' === $currency) {
                $currency = $row_currency;
            } elseif ($currency !== $row_currency) {
                $currency = 'multi';
            }
        }

        if ('' === $currency) {
            $currency = (string) get_option('woocommerce_currency', 'EUR');
        }

        return array(
            'start'       => $start,
            'end'         => $end,
            'count'       => $count,
            'revenue'     => $revenue,
            'currency'    => $currency,
            'daysElapsed' => max(1, (int) gmdate('j')),
            'daysInMonth' => max(1, (int) gmdate('t')),
        );
    }

    private static function calculate_upcoming_pipeline(string $table, int $days): array
    {
        global $wpdb;

        $days  = max(1, $days);
        $start = gmdate('Y-m-d 00:00:00');
        $end   = gmdate('Y-m-d 23:59:59', strtotime('+' . ( $days - 1 ) . ' days'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(start_datetime) AS day, status, COUNT(*) AS total FROM {$table} WHERE start_datetime BETWEEN %s AND %s GROUP BY day, status ORDER BY day ASC",
                $start,
                $end
            ),
            ARRAY_A
        );

        $pending_statuses = array( 'draft', 'pending', 'awaiting_payment', 'awaiting_confirmation', 'quote' );
        $by_day           = array();
        $total            = 0;
        $pending          = 0;

        foreach ($rows as $row) {
            $day    = isset($row['day']) ? (string) $row['day'] : '';
            $status = isset($row['status']) ? (string) $row['status'] : 'unknown';
            $count  = (int) ( $row['total'] ?? 0 );

            if ('' === $day) {
                continue;
            }

            if (! isset($by_day[ $day ])) {
                $by_day[ $day ] = array(
                    'date'     => $day,
                    'total'    => 0,
                    'statuses' => array(),
                );
            }

            $by_day[ $day ]['total'] += $count;
            $by_day[ $day ]['statuses'][ $status ] = ( $by_day[ $day ]['statuses'][ $status ] ?? 0 ) + $count;

            $total += $count;
            if (in_array($status, $pending_statuses, true)) {
                $pending += $count;
            }
        }

        return array(
            'windowDays'       => $days,
            'upcomingTotal'    => $total,
            'pendingApprovals' => $pending,
            'upcomingByDay'    => array_values($by_day),
        );
    }

    private static function calculate_channel_breakdown(string $table, int $days): array
    {
        global $wpdb;

        $days  = max(1, $days);
        $end   = gmdate('Y-m-d 23:59:59');
        $start = gmdate('Y-m-d 00:00:00', strtotime('-' . ( $days - 1 ) . ' days'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta, total, currency FROM {$table} WHERE start_datetime BETWEEN %s AND %s",
                $start,
                $end
            ),
            ARRAY_A
        );

        $channels = array();

        foreach ($rows as $row) {
            $meta = self::decode_booking_meta($row['meta'] ?? null);

            $slug = '';
            if (isset($meta['channel_slug']) && '' !== $meta['channel_slug']) {
                $slug = sanitize_key((string) $meta['channel_slug']);
            } elseif (isset($meta['channel']) && '' !== $meta['channel']) {
                $slug = sanitize_key((string) $meta['channel']);
            } elseif (isset($meta['source']) && '' !== $meta['source']) {
                $slug = sanitize_key((string) $meta['source']);
            }

            if ('' === $slug) {
                $slug = 'direct';
            }

            $name = '';
            if (isset($meta['channel_name']) && '' !== $meta['channel_name']) {
                $name = (string) $meta['channel_name'];
            } elseif (isset($meta['channel_label']) && '' !== $meta['channel_label']) {
                $name = (string) $meta['channel_label'];
            } elseif (isset($meta['channel']) && '' !== $meta['channel']) {
                $name = (string) $meta['channel'];
            }

            if ('' === $name) {
                $name = __('Direct', 'sbdp');
            }

            if (! isset($channels[ $slug ])) {
                $channels[ $slug ] = array(
                    'slug'     => $slug,
                    'name'     => $name,
                    'bookings' => 0,
                    'revenue'  => 0.0,
                    'currency' => '',
                );
            }

            $channels[ $slug ]['bookings']++;

            $amount   = isset($row['total']) ? (float) $row['total'] : 0.0;
            $currency = isset($row['currency']) ? (string) $row['currency'] : '';
            $channels[ $slug ]['revenue'] += $amount;

            if ('' !== $currency) {
                if ('' === $channels[ $slug ]['currency']) {
                    $channels[ $slug ]['currency'] = $currency;
                } elseif ($channels[ $slug ]['currency'] !== $currency) {
                    $channels[ $slug ]['currency'] = 'multi';
                }
            }
        }

        return array_values($channels);
    }

    private static function decode_booking_meta($raw): array
    {
        if (empty($raw)) {
            return array();
        }

        $meta = maybe_unserialize($raw);
        if (is_string($meta) && '' !== $meta) {
            $decoded = json_decode($meta, true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }

        return is_array($meta) ? $meta : array();
    }

    private static function project_month_revenue(float $revenue, int $days_elapsed, int $days_in_month): float
    {
        if ($days_elapsed <= 0 || $days_in_month <= 0) {
            return 0.0;
        }

        $average = $revenue / $days_elapsed;
        return $average * $days_in_month;
    }

    private static function get_bookings_table(): ?string
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return null;
        }

        $table = $wpdb->prefix . 'sbdp_bookings';
        return self::table_exists($table) ? $table : null;
    }

    private static function table_exists(string $table): bool
    {
        if (isset(self::$table_exists_cache[ $table ])) {
            return self::$table_exists_cache[ $table ];
        }

        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            self::$table_exists_cache[ $table ] = false;
            return false;
        }

        $result = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        $exists = ( $result === $table );
        self::$table_exists_cache[ $table ] = $exists;

        return $exists;
    }

    private static function locate_planner_page(): ?WP_Post
    {
        $target_slug = sanitize_title(__('Plan je dag', 'sbdp'));

        $query = new WP_Query(
            array(
                'post_type'      => 'page',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'name'           => $target_slug,
                'orderby'        => 'ID',
                'order'          => 'ASC',
            )
        );

        if ($query->have_posts()) {
            $post = $query->posts[0];
            wp_reset_postdata();

            return $post instanceof WP_Post ? $post : null;
        }

        wp_reset_postdata();

        $query = new WP_Query(
            array(
                'post_type'      => 'page',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                's'              => '[sbdp_dayplanner]',
            )
        );

        if ($query->have_posts()) {
            $post = $query->posts[0];
            wp_reset_postdata();

            return $post instanceof WP_Post ? $post : null;
        }

        wp_reset_postdata();

        return null;
    }
}
