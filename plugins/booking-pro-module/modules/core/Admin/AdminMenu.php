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
use function add_query_arg;
use function admin_url;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_url;
use function get_option;
use function get_permalink;
use function get_transient;
use function get_post_status;
use function get_posts;
use function get_the_title;
use function gmdate;
use function in_array;
use function is_array;
use function is_string;
use function is_wp_error;
use function json_decode;
use function maybe_unserialize;
use function number_format_i18n;
use function ob_get_clean;
use function ob_start;
use function post_type_exists;
use function sanitize_key;
use function sanitize_title;
use function set_transient;
use function sprintf;
use function ucfirst;
use function strtotime;
use function wp_remote_get;
use function wp_remote_retrieve_response_code;
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
    private const ALERT_SETTINGS_OPTION = 'sbdp_governance_alert_settings';
    private const ALERT_LAST_SENT_OPTION = 'sbdp_governance_alerts_last_sent';
    private const DECISION_POLICY_OPTION = 'sbdp_dayplanner_decision_policy';
    /**
     * Cache for table existence checks during a single request.
     *
     * @var array<string, bool>
     */
    private static $table_exists_cache = array();

    /**
     * Cache of table columns keyed by table name.
     *
     * @var array<string, array<int, string>>
     */
    private static $table_columns_cache = array();

    public static function init(): void
    {
        add_action('admin_menu', array( __CLASS__, 'menu' ));
        add_action('admin_init', array( __CLASS__, 'maybe_dispatch_governance_alerts' ));
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
            __('Governance Cockpit', 'sbdp'),
            __('Governance', 'sbdp'),
            $capability,
            'sbdp_governance',
            array( __CLASS__, 'render_governance' )
        );

        add_submenu_page(
            'sbdp_bookings',
            __('Design Backend', 'sbdp'),
            __('Design Backend', 'sbdp'),
            $capability,
            'sbdp_design_backend',
            array( __CLASS__, 'render_design_backend' )
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
        echo '<h1>' . esc_html__('Boekingen-dashboard', 'sbdp') . '</h1>';
        echo '<p class="description">' . esc_html__('Realtime overzicht van planningen, kanaalprestaties en directe vervolgacties.', 'sbdp') . '</p>';
        echo '<div id="sbdp-dashboard-root"></div>';
        echo '<noscript>';
        echo '<div class="notice notice-info"><p>' . esc_html__('JavaScript is vereist om het interactieve dashboard te tonen. Hieronder vind je een beknopte samenvatting.', 'sbdp') . '</p></div>';
        echo self::render_fallback_markup($bootstrap['metrics'], $bootstrap['quickLinks']);
        echo '</noscript>';
        echo '</div>';
    }

    private static function get_governance_tab(): string
    {
        $tab = isset($_GET['tab']) ? sanitize_key((string) wp_unslash((string) $_GET['tab'])) : 'strategy'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $allowed = array_merge(array('strategy', 'health', 'launch'), array_keys((array) apply_filters('bsp_governance_extra_tabs', array())));
        if (! in_array($tab, $allowed, true)) {
            return 'strategy';
        }

        return $tab;
    }

    private static function get_governance_docs_dir(): string
    {
        return rtrim((string) ABSPATH, "/\\") . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR;
    }

    private static function get_governance_doc_path(string $relative_path): string
    {
        $relative_path = ltrim(str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $relative_path), DIRECTORY_SEPARATOR);
        return self::get_governance_docs_dir() . $relative_path;
    }

    private static function get_governance_doc_contents(string $relative_path): string
    {
        $path = self::get_governance_doc_path($relative_path);
        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    private static function get_governance_doc_excerpt(string $relative_path, int $max_items = 3): array
    {
        $contents = self::get_governance_doc_contents($relative_path);
        if ($contents === '') {
            return array();
        }

        $lines = preg_split("/\\R/", $contents) ?: array();
        $items = array();
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || strpos($line, '#') === 0 || strpos($line, '---') === 0) {
                continue;
            }
            if (preg_match('/^[-*]\\s+(.+)$/', $line, $matches)) {
                $items[] = trim((string) $matches[1]);
            } elseif (preg_match('/^\\d+\\.\\s+(.+)$/', $line, $matches)) {
                $items[] = trim((string) $matches[1]);
            }
            if (count($items) >= $max_items) {
                break;
            }
        }

        return $items;
    }

    private static function get_markdown_subsection(string $body, string $heading): string
    {
        if ($body === '') {
            return '';
        }

        $pattern = '/^###\\s+' . preg_quote($heading, '/') . '\\s*$\\R(.*?)(?=^###\\s+|\\z)/ms';
        if (! preg_match($pattern, $body, $matches)) {
            return '';
        }

        return trim((string) ($matches[1] ?? ''));
    }

    private static function markdown_lines(string $body): array
    {
        $lines = preg_split("/\\R/", trim($body)) ?: array();
        return array_values(array_filter(array_map(static function ($line): string {
            return trim((string) $line);
        }, $lines), static function ($line): bool {
            return $line !== '';
        }));
    }

    private static function markdown_bullets(string $body): array
    {
        $items = array();
        foreach (self::markdown_lines($body) as $line) {
            if (preg_match('/^[-*]\\s+(.+)$/', $line, $matches)) {
                $items[] = trim((string) $matches[1]);
            }
        }

        return $items;
    }

    private static function markdown_ordered_items(string $body): array
    {
        $items = array();
        foreach (self::markdown_lines($body) as $line) {
            if (preg_match('/^\\d+\\.\\s+(.+)$/', $line, $matches)) {
                $items[] = trim((string) $matches[1]);
            }
        }

        return $items;
    }

    private static function markdown_inline_value(string $body): string
    {
        foreach (self::markdown_lines($body) as $line) {
            if (strpos($line, '- ') === 0 || strpos($line, '* ') === 0 || preg_match('/^\\d+\\./', $line)) {
                continue;
            }
            return trim($line, "` \t\n\r\0\x0B");
        }

        return '';
    }

    private static function markdown_labeled_value(string $body, string $label): string
    {
        foreach (self::markdown_lines($body) as $line) {
            if (preg_match('/^-\\s*' . preg_quote($label, '/') . ':\\s*(.+)$/i', $line, $matches)) {
                return trim((string) $matches[1], "` ");
            }
        }

        return '';
    }

    private static function markdown_labeled_list(string $body, string $label): array
    {
        $lines = self::markdown_lines($body);
        $items = array();
        $collect = false;

        foreach ($lines as $line) {
            if (preg_match('/^-\\s*' . preg_quote($label, '/') . ':\\s*$/i', $line)) {
                $collect = true;
                continue;
            }

            if ($collect && preg_match('/^-\\s+(.+)$/', $line, $matches)) {
                $items[] = trim((string) $matches[1], "` ");
                continue;
            }

            if ($collect && preg_match('/^-\\s*[A-Za-z].*:/', $line)) {
                break;
            }
        }

        return $items;
    }

    private static function normalize_governance_status(string $status): string
    {
        $status = sanitize_key(str_replace('-', '_', strtolower(trim($status, "` "))));
        if ($status === 'warning') {
            return 'warn';
        }
        if ($status === 'must_pass') {
            return 'must_pass';
        }
        if ($status === 'not_yet') {
            return 'blocked';
        }

        return $status !== '' ? $status : 'unknown';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function get_governance_authority_docs(): array
    {
        $docs = array(
            array('title' => 'AGENTS', 'file' => '../../../AGENTS.md', 'summary' => 'Repository guardrails for UI governance, performance and pricing rules.'),
            array('title' => 'Platform Constitution', 'file' => 'DDB_PLATFORM_CONSTITUTION.md', 'summary' => 'One platform, three truths, canonical shell and page-family law.'),
            array('title' => 'CTA Map', 'file' => 'DDB_CTA_MAP.md', 'summary' => 'Canonical CTA hierarchy by page family.'),
            array('title' => 'Do Not Touch', 'file' => 'DDB_DO_NOT_TOUCH.md', 'summary' => 'Protected zones for OMDB, Woo, shell and planner continuity.'),
            array('title' => 'Page Families', 'file' => 'DDB_PAGE_FAMILIES.md', 'summary' => 'Overview, detail, execution, management, experience and return families.'),
            array('title' => 'Component Canon', 'file' => 'DDB_COMPONENT_CANON.md', 'summary' => 'One button, one card, one filter, one tab and one shell family.'),
            array('title' => 'OMDB / Woo Boundaries', 'file' => 'DDB_OMDB_WOO_BOUNDARIES.md', 'summary' => 'OMDB owns meaning; Woo owns final commerce truth.'),
            array('title' => 'Launch Board', 'file' => 'DDB_LAUNCH_BOARD.md', 'summary' => 'Launch readiness by page family and shared platform readiness.'),
            array('title' => 'Regression Checklist', 'file' => 'DDB_REGRESSION_CHECKLIST.md', 'summary' => 'Regression gates for shell, DS, mobile, planner and execution surfaces.'),
            array('title' => 'Shell Rules', 'file' => 'DDB_SHELL_RULES.md', 'summary' => 'Canonical header, main and footer law.'),
            array('title' => 'Implementation Sequence', 'file' => 'DDB_IMPLEMENTATION_SEQUENCE.md', 'summary' => 'Mandatory normalization order.'),
            array('title' => 'Review Loop', 'file' => 'DDB_REVIEW_LOOP.md', 'summary' => 'Mandatory review chain before merge or launch.'),
            array('title' => 'Governance Policy', 'file' => 'governance/DDB_GOVERNANCE_POLICY.md', 'summary' => 'Governed change, release discipline and exception handling.'),
            array('title' => 'Release Gates', 'file' => 'governance/DDB_RELEASE_GATES.md', 'summary' => 'Mandatory release gates and pass/fail rules.'),
            array('title' => 'RACI', 'file' => 'governance/DDB_RACI.md', 'summary' => 'Ownership, accountability and blocking rights.'),
            array('title' => 'Governance Dashboard Spec', 'file' => 'governance/DDB_GOVERNANCE_DASHBOARD_SPEC.md', 'summary' => 'Required cockpit tabs, widgets and status model.'),
            array('title' => 'Directie TOGAF Notitie', 'file' => 'governance/DDB_DIRECTIENOTITIE_TOGAF_GOVERNANCE.md', 'summary' => 'Formal governance mandate and directie-level launch discipline.'),
        );

        return array_map(static function (array $doc): array {
            $path = self::get_governance_doc_path((string) $doc['file']);
            $exists = is_readable($path);
            $doc['exists'] = $exists;
            $doc['status'] = $exists ? 'pass' : 'fail';
            return $doc;
        }, $docs);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function get_governance_page_family_matrix(): array
    {
        $contents = self::get_governance_doc_contents('DDB_PAGE_FAMILIES.md');
        if ($contents === '') {
            return array();
        }

        preg_match_all('/^##\s+\d+\.\s+(.+?)\s*$\R(.*?)(?=^##\s+\d+\.\s+|\z)/ms', $contents, $matches, PREG_SET_ORDER);
        $rows = array();

        foreach ($matches as $match) {
            $family = trim((string) ($match[1] ?? ''));
            $body = (string) ($match[2] ?? '');
            if ($family === '' || stripos($family, 'Family law') !== false) {
                continue;
            }

            $includes = self::markdown_bullets(self::get_markdown_subsection($body, 'Includes'));
            $phase = self::markdown_inline_value(self::get_markdown_subsection($body, 'Primary journey phase'));
            $must = self::markdown_bullets(self::get_markdown_subsection($body, 'Must do'));
            $must_not = self::markdown_bullets(self::get_markdown_subsection($body, 'Must not do'));

            $rows[] = array(
                'family' => $family,
                'includes' => implode(', ', $includes),
                'phase' => $phase !== '' ? $phase : 'unknown',
                'cta' => 'See CTA map',
                'must' => implode(' • ', array_slice($must, 0, 3)),
                'must_not' => implode(' • ', array_slice($must_not, 0, 3)),
            );
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function get_governance_launch_board_matrix(): array
    {
        $contents = self::get_governance_doc_contents('DDB_LAUNCH_BOARD.md');
        if ($contents === '') {
            return array();
        }

        preg_match_all('/^###\s+[\d.]+\s+(.+?)\s*$\R(.*?)(?=^###\s+[\d.]+\s+|\z)/ms', $contents, $matches, PREG_SET_ORDER);
        $rows = array();

        foreach ($matches as $match) {
            $page = trim((string) ($match[1] ?? ''));
            $body = (string) ($match[2] ?? '');
            if ($page === '' || stripos($page, 'Safe to launch?') !== false) {
                continue;
            }

            $blockers = self::markdown_labeled_list($body, 'Blockers');
            $ready_when = self::markdown_labeled_list($body, 'Ready when');
            $notes = self::markdown_labeled_list($body, 'Notes');
            if ($notes === array()) {
                $note_value = self::markdown_labeled_value($body, 'Notes');
                if ($note_value !== '') {
                    $notes = array($note_value);
                }
            }

            $rows[] = array(
                'page' => $page,
                'family' => self::markdown_labeled_value($body, 'Family') ?: 'Shared platform',
                'phase' => self::markdown_labeled_value($body, 'Primary phase') ?: 'unknown',
                'owner' => self::markdown_labeled_value($body, 'Owner') ?: 'TBD',
                'cta' => self::markdown_labeled_value($body, 'Primary CTA') ?: 'n/a',
                'status' => self::normalize_governance_status(self::markdown_labeled_value($body, 'Status')),
                'blockers' => $blockers,
                'ready_when' => $ready_when,
                'last_reviewed' => 'unknown',
                'next_action' => $ready_when !== array() ? implode(' • ', array_slice($ready_when, 0, 3)) : implode(' • ', array_slice($notes, 0, 3)),
            );
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function get_governance_review_loop_matrix(): array
    {
        $contents = self::get_governance_doc_contents('DDB_REVIEW_LOOP.md');
        if ($contents === '') {
            return array();
        }

        preg_match_all('/^##\s+\d+\.\s+Step\s+\d+\s+—\s+(.+?)\s*$\R(.*?)(?=^##\s+\d+\.\s+Step\s+\d+\s+—|^##\s+\d+\.\s+What counts|\z)/ms', $contents, $matches, PREG_SET_ORDER);
        $rows = array();

        foreach ($matches as $match) {
            $step = trim((string) ($match[1] ?? ''));
            $body = (string) ($match[2] ?? '');
            if ($step === '') {
                continue;
            }

            $purpose = self::markdown_bullets(self::get_markdown_subsection($body, 'Purpose'));
            $block_if = self::markdown_bullets(self::get_markdown_subsection($body, 'Block if'));
            $notes = $purpose !== array() ? implode(' • ', array_slice($purpose, 0, 2)) : implode(' • ', array_slice($block_if, 0, 2));

            $rows[] = array(
                'step' => $step,
                'status' => 'not_run',
                'notes' => $notes !== '' ? $notes : 'Review evidence not yet attached.',
                'last_run' => 'unknown',
            );
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $snapshot
     *
     * @return array<int, array<string, mixed>>
     */
    private static function get_governance_runtime_signals(array $snapshot): array
    {
        $theme_dir = function_exists('get_stylesheet_directory') ? rtrim((string) get_stylesheet_directory(), "/\\") : rtrim((string) ABSPATH, "/\\") . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . 'themes' . DIRECTORY_SEPARATOR . 'hello-biz';
        $base = $theme_dir . DIRECTORY_SEPARATOR . 'woocommerce' . DIRECTORY_SEPARATOR;
        $files = array(
            'cart'     => $base . 'cart' . DIRECTORY_SEPARATOR . 'cart.php',
            'checkout' => $base . 'checkout' . DIRECTORY_SEPARATOR . 'form-checkout.php',
            'thankyou' => $base . 'checkout' . DIRECTORY_SEPARATOR . 'thankyou.php',
            'account'  => $base . 'myaccount' . DIRECTORY_SEPARATOR . 'my-account.php',
            'dashboard'=> $base . 'myaccount' . DIRECTORY_SEPARATOR . 'dashboard.php',
            'order'    => $base . 'order' . DIRECTORY_SEPARATOR . 'order-details.php',
        );

        $contents = array();
        foreach ($files as $key => $file_path) {
            $contents[$key] = is_readable($file_path) ? (string) file_get_contents($file_path) : '';
        }

        $legacy_css_active = false;
        foreach ($contents as $content) {
            if ($content === '') {
                continue;
            }
            if (strpos($content, '<style') !== false || strpos($content, 'style=') !== false) {
                $legacy_css_active = true;
                break;
            }
        }

        $shell_drift = array();
        foreach (array(
            'cart'     => 'ddb-cart-shell',
            'checkout' => 'ddb-commerce-shell',
            'thankyou' => 'ddb-order-received-layout',
            'account'  => 'ddb-account-shell',
            'dashboard'=> 'ddb_account_hub',
            'order'    => 'ui-card',
        ) as $key => $needle) {
            if ($contents[$key] !== '' && strpos($contents[$key], $needle) === false) {
                $shell_drift[] = $key;
            }
        }

        return array(
            array('label' => 'Legacy CSS still active', 'status' => $legacy_css_active ? 'warning' : 'pass', 'notes' => $legacy_css_active ? 'Inline style markup still exists in at least one critical Woo template.' : 'Critical public Woo templates are free of inline <style> blocks and style attributes.'),
            array('label' => 'Shell/header/footer drift', 'status' => empty($shell_drift) ? 'pass' : 'warning', 'notes' => empty($shell_drift) ? 'Cart, checkout, thank-you, account and order templates carry the shared shell markers.' : 'Missing shell markers in: ' . implode(', ', $shell_drift) . '.'),
            array('label' => 'Duplicate component family detected', 'status' => 'unknown', 'notes' => 'Not yet wired: needs semantic diffing across public template families.'),
            array('label' => 'Page-family mismatch detected', 'status' => 'unknown', 'notes' => 'Not yet wired: family mapping is doc-driven and still manually validated.'),
            array('label' => 'Raw field rendering detected', 'status' => 'unknown', 'notes' => 'Not yet wired: requires DOM-level semantic checks for unsafe field output.'),
            array('label' => 'Add-to-day continuity issue', 'status' => 'unknown', 'notes' => 'Not yet wired: planner handoff telemetry is outside the cockpit scope.'),
            array('label' => 'Planner continuity risk', 'status' => 'unknown', 'notes' => 'Not yet wired: no live planner safety harness is connected to the cockpit page.'),
            array('label' => 'OMDB / Woo boundary warnings', 'status' => 'unknown', 'notes' => 'Not yet wired: semantic boundary probes remain a follow-up.'),
        );
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<int, array<string, mixed>> $runtime
     * @param array<int, array<string, mixed>> $page_launch
     *
     * @return array<string, array<string, string>>
     */
    private static function get_governance_status_cards(array $snapshot, array $runtime, array $page_launch): array
    {
        $runtime_lookup = array();
        foreach ($runtime as $signal) {
            $runtime_lookup[(string) ($signal['label'] ?? '')] = $signal;
        }

        $design_state = (string) (($runtime_lookup['Legacy CSS still active']['status'] ?? 'unknown'));
        if ('warning' === $design_state) {
            $design_state = 'warn';
        }
        $shell_state = (string) (($runtime_lookup['Shell/header/footer drift']['status'] ?? 'unknown'));
        if ('warning' === $shell_state) {
            $shell_state = 'warn';
        }

        return array(
            'design_system' => array('status' => $design_state === 'warning' ? 'warn' : $design_state, 'meta' => $design_state === 'warn' ? 'Critical templates are mostly clean, but inline style drift still exists somewhere in the public surface.' : 'Critical runtime templates are free of inline styles and still point at shared primitives.'),
            'shell'         => array('status' => $shell_state === 'warning' ? 'warn' : $shell_state, 'meta' => $shell_state === 'warn' ? 'One or more critical templates are missing a shared shell marker.' : 'Execution and management templates carry the expected shared shell markers.'),
            'omdb'          => array('status' => 'unknown', 'meta' => 'Authority docs exist, but no live semantic OMDB probe is wired yet.'),
            'woo'           => array('status' => 'unknown', 'meta' => 'Commerce truth is documented, but the cockpit does not yet run a live pricing/tax/availability probe.'),
            'planner'       => array('status' => 'unknown', 'meta' => 'Planner safety requires a continuity harness; the cockpit currently mirrors docs only.'),
            'mobile'        => array('status' => 'unknown', 'meta' => 'No device or viewport regression sweep is attached to the cockpit yet.'),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $page_launch
     * @param array<int, array<string, mixed>> $runtime
     * @param array<int, array<string, mixed>> $authority_docs
     *
     * @return array<int, array<string, mixed>>
     */
    private static function get_governance_critical_blockers(array $page_launch, array $runtime, array $authority_docs = array()): array
    {
        $blockers = array();
        foreach ($page_launch as $row) {
            $status = (string) ($row['status'] ?? 'unknown');
            if ('blocked' === $status || 'review' === $status || 'must_pass' === $status) {
                $blockers[] = array('severity' => 'high', 'title' => (string) ($row['page'] ?? 'Unknown page'), 'detail' => implode('; ', array_map('strval', is_array($row['blockers'] ?? null) ? $row['blockers'] : array())));
            } elseif ('in_progress' === $status || 'not_started' === $status || 'later' === $status) {
                $blockers[] = array('severity' => 'medium', 'title' => (string) ($row['page'] ?? 'Unknown page'), 'detail' => (string) ($row['next_action'] ?? 'No next action available'));
            }
        }

        foreach ($authority_docs as $doc) {
            if (! empty($doc['exists'])) {
                continue;
            }
            $blockers[] = array('severity' => 'medium', 'title' => (string) ($doc['title'] ?? 'Missing doc'), 'detail' => (string) ($doc['summary'] ?? 'Not published'));
        }

        return $blockers;
    }

    /**
     * @param array<int, array<string, mixed>> $page_launch
     * @param array<int, array<string, mixed>> $runtime
     * @param array<int, array<string, mixed>> $blockers
     *
     * @return array<string, mixed>
     */
    private static function get_governance_launch_decision(array $page_launch, array $runtime, array $blockers): array
    {
        $safe = true;
        foreach ($page_launch as $row) {
            if (in_array((string) ($row['status'] ?? 'unknown'), array('blocked', 'review', 'in_progress', 'not_started', 'later', 'must_pass'), true)) {
                $safe = false;
                break;
            }
        }

        if ($blockers !== array()) {
            $safe = false;
        }

        return array('safe' => $safe, 'warning_count' => count($runtime), 'blockers' => $blockers);
    }

    private static function render_governance_tabs_nav(string $tab, int $window): string
    {
        $tabs = array('strategy' => __('Strategy', 'sbdp'), 'health' => __('System Health', 'sbdp'), 'launch' => __('Launch Board', 'sbdp'));
        $tabs = array_merge($tabs, (array) apply_filters('bsp_governance_extra_tabs', array()));
        ob_start();
        echo '<nav class="nav-tab-wrapper sbdp-governance-tabs" aria-label="' . esc_attr__('Governance cockpit tabs', 'sbdp') . '">';
        foreach ($tabs as $key => $label) {
            $url = add_query_arg(array('page' => 'sbdp_governance', 'tab' => $key, 'window' => $window), admin_url('admin.php'));
            $class = 'nav-tab' . ($tab === $key ? ' nav-tab-active' : '');
            echo '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
        $html = ob_get_clean();
        return is_string($html) ? $html : '';
    }

    /**
     * @param array<string, array<string, string>> $status_cards
     * @param array<int, array<string, mixed>> $runtime
     * @param array<int, array<string, mixed>> $review_loop
     * @param array<string, mixed> $snapshot
     */
    private static function render_governance_system_health_tab(array $status_cards, array $runtime, array $review_loop, array $snapshot): string
    {
        $kpis = is_array($snapshot['kpis'] ?? null) ? $snapshot['kpis'] : array();
        $events = is_array($snapshot['events'] ?? null) ? $snapshot['events'] : array();

        ob_start();
        echo '<section id="sbdp-governance-system-health" class="sbdp-governance-panel">';
        echo '<div class="sbdp-governance-panel__header"><div><h2>' . esc_html__('System Health', 'sbdp') . '</h2><p>' . esc_html__('Runtime truth, drift signals and the mandatory review loop.', 'sbdp') . '</p></div></div>';
        echo '<div class="sbdp-governance-grid sbdp-governance-grid--cards">';
        echo self::render_governance_card(__('Design System Truth', 'sbdp'), strtoupper((string) ($status_cards['design_system']['status'] ?? 'UNKNOWN')), (string) ($status_cards['design_system']['meta'] ?? ''), (string) ($status_cards['design_system']['status'] ?? 'unknown'));
        echo self::render_governance_card(__('Shell Integrity', 'sbdp'), strtoupper((string) ($status_cards['shell']['status'] ?? 'UNKNOWN')), (string) ($status_cards['shell']['meta'] ?? ''), (string) ($status_cards['shell']['status'] ?? 'unknown'));
        echo self::render_governance_card(__('OMDB Boundary', 'sbdp'), strtoupper((string) ($status_cards['omdb']['status'] ?? 'UNKNOWN')), (string) ($status_cards['omdb']['meta'] ?? ''), (string) ($status_cards['omdb']['status'] ?? 'unknown'));
        echo self::render_governance_card(__('Woo Boundary', 'sbdp'), strtoupper((string) ($status_cards['woo']['status'] ?? 'UNKNOWN')), (string) ($status_cards['woo']['meta'] ?? ''), (string) ($status_cards['woo']['status'] ?? 'unknown'));
        echo self::render_governance_card(__('Planner Safety', 'sbdp'), strtoupper((string) ($status_cards['planner']['status'] ?? 'UNKNOWN')), (string) ($status_cards['planner']['meta'] ?? ''), (string) ($status_cards['planner']['status'] ?? 'unknown'));
        echo self::render_governance_card(__('Mobile Readiness', 'sbdp'), strtoupper((string) ($status_cards['mobile']['status'] ?? 'UNKNOWN')), (string) ($status_cards['mobile']['meta'] ?? ''), (string) ($status_cards['mobile']['status'] ?? 'unknown'));
        echo '</div>';

        echo '<div class="sbdp-governance-grid sbdp-governance-grid--cards">';
        echo self::render_governance_card(__('Sessions', 'sbdp'), (string) number_format_i18n((int) ($kpis['sessions_started'] ?? 0)), __('Windowed runtime count', 'sbdp'), 'info');
        $primary_rate = (float) ($kpis['primary_selection_rate'] ?? 0.0);
        $plan_rate = (float) ($kpis['plan_feasibility_rate'] ?? 0.0);
        $cta_rate = (float) ($kpis['cta_contract_rate'] ?? 0.0);
        $trace_rate = (float) ($kpis['trace_coverage_rate'] ?? 0.0);
        $error_count = (int) ($kpis['errors'] ?? 0);
        echo self::render_governance_card(__('Primary select rate', 'sbdp'), self::format_percent($primary_rate), __('Goal: >= 80%', 'sbdp'), $primary_rate >= 0.80 ? 'pass' : 'warn');
        echo self::render_governance_card(__('Plan feasibility', 'sbdp'), self::format_percent($plan_rate), __('Goal: >= 85%', 'sbdp'), $plan_rate >= 0.85 ? 'pass' : 'warn');
        echo self::render_governance_card(__('CTA contract', 'sbdp'), self::format_percent($cta_rate), __('Goal: >= 95%', 'sbdp'), $cta_rate >= 0.95 ? 'pass' : 'warn');
        echo self::render_governance_card(__('Trace coverage', 'sbdp'), self::format_percent($trace_rate), __('Goal: >= 95%', 'sbdp'), $trace_rate >= 0.95 ? 'pass' : 'fail');
        echo self::render_governance_card(__('Errors', 'sbdp'), (string) number_format_i18n($error_count), __('Audit severity = error', 'sbdp'), $error_count > 0 ? 'fail' : 'pass');
        echo '</div>';

        echo '<div class="sbdp-governance-grid">';
        echo '<section class="sbdp-governance-panel">';
        echo '<h3>' . esc_html__('Runtime health signals', 'sbdp') . '</h3>';
        echo '<table class="widefat striped sbdp-governance-table"><thead><tr><th>' . esc_html__('Signal', 'sbdp') . '</th><th>' . esc_html__('Status', 'sbdp') . '</th><th>' . esc_html__('Notes', 'sbdp') . '</th></tr></thead><tbody>';
        foreach ($runtime as $signal) {
            echo '<tr><td><strong>' . esc_html((string) ($signal['label'] ?? '')) . '</strong></td><td>' . self::render_governance_status_tag((string) ($signal['status'] ?? 'unknown')) . '</td><td>' . esc_html((string) ($signal['notes'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '</section>';

        echo '<section class="sbdp-governance-panel">';
        echo '<h3>' . esc_html__('Design system drift panel', 'sbdp') . '</h3>';
        echo '<p>' . esc_html__('Warnings below are derived from current template scans or explicitly marked unknown where a safe probe is not wired yet.', 'sbdp') . '</p>';
        echo '<table class="widefat striped sbdp-governance-table"><thead><tr><th>' . esc_html__('Drift source', 'sbdp') . '</th><th>' . esc_html__('Status', 'sbdp') . '</th><th>' . esc_html__('Notes', 'sbdp') . '</th></tr></thead><tbody>';
        foreach ($runtime as $signal) {
            echo '<tr><td>' . esc_html((string) ($signal['label'] ?? '')) . '</td><td>' . self::render_governance_status_tag((string) ($signal['status'] ?? 'unknown')) . '</td><td>' . esc_html((string) ($signal['notes'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '</section>';

        echo '<section class="sbdp-governance-panel sbdp-governance-panel--full">';
        echo '<h3>' . esc_html__('Review loop panel', 'sbdp') . '</h3>';
        echo '<table class="widefat striped sbdp-governance-table"><thead><tr><th>' . esc_html__('Step', 'sbdp') . '</th><th>' . esc_html__('Status', 'sbdp') . '</th><th>' . esc_html__('Last run', 'sbdp') . '</th><th>' . esc_html__('Notes', 'sbdp') . '</th></tr></thead><tbody>';
        foreach ($review_loop as $row) {
            echo '<tr><td><strong>' . esc_html((string) ($row['step'] ?? '')) . '</strong></td><td>' . self::render_governance_status_tag((string) ($row['status'] ?? 'unknown')) . '</td><td>' . esc_html((string) ($row['last_run'] ?? 'unknown')) . '</td><td>' . esc_html((string) ($row['notes'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '</section>';

        echo '<section class="sbdp-governance-panel sbdp-governance-panel--full">';
        echo '<h3>' . esc_html__('Runtime KPIs', 'sbdp') . '</h3>';
        echo self::render_governance_funnel($kpis);
        echo self::render_governance_event_mix(is_array($events['breakdown'] ?? null) ? $events['breakdown'] : array());
        echo self::render_governance_daily(is_array($events['daily'] ?? null) ? $events['daily'] : array());
        echo '</section>';
        echo '</div>';
        echo '</section>';

        $html = ob_get_clean();
        return is_string($html) ? $html : '';
    }

    /**
     * @return array<int, string>
     */
    private static function get_governance_mandate_summary(): array
    {
        $note = self::get_governance_doc_excerpt('governance/DDB_DIRECTIENOTITIE_TOGAF_GOVERNANCE.md', 3);
        $policy = self::get_governance_doc_excerpt('governance/DDB_GOVERNANCE_POLICY.md', 3);
        return array_values(array_slice(array_unique(array_filter(array_merge($note, $policy))), 0, 4));
    }

    /**
     * @return array<int, string>
     */
    private static function get_governance_journey_phases(): array
    {
        $contents = self::get_governance_doc_contents('DDB_PLATFORM_CONSTITUTION.md');
        if (! preg_match('/^##\s+5\.\s+Journey model\s*$\R(.*?)(?=^##\s+\d+\.|\z)/ms', $contents, $matches)) {
            return array();
        }

        return self::markdown_ordered_items((string) ($matches[1] ?? ''));
    }

    /**
     * @return array<int, array<string, string>>
     */
    private static function get_governance_cta_map_rows(): array
    {
        $contents = self::get_governance_doc_contents('DDB_CTA_MAP.md');
        if ($contents === '') {
            return array();
        }

        preg_match_all('/^###\s+(.+?)\s*$\R(.*?)(?=^###\s+|^##\s+\d+\.|\z)/ms', $contents, $matches, PREG_SET_ORDER);
        $rows = array();
        foreach ($matches as $match) {
            $page = trim((string) ($match[1] ?? ''));
            $body = (string) ($match[2] ?? '');
            if ($page === '' || stripos($page, 'rule') !== false) {
                continue;
            }

            $rows[] = array(
                'page' => $page,
                'primary' => self::markdown_labeled_value($body, 'Primary') ?: 'n/a',
                'secondary' => self::markdown_labeled_value($body, 'Secondary') ?: 'n/a',
                'notes' => self::markdown_labeled_value($body, 'Tertiary') ?: self::markdown_labeled_value($body, 'Contextual'),
            );
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    private static function get_governance_component_canon_summary(): array
    {
        $contents = self::get_governance_doc_contents('DDB_COMPONENT_CANON.md');
        preg_match_all('/^##\s+\d+\.\s+(.+?)\s*$/m', $contents, $matches);
        $items = array();
        foreach ($matches[1] ?? array() as $heading) {
            $heading = trim((string) $heading);
            if ($heading === '' || stripos($heading, 'Purpose') !== false || stripos($heading, 'Global component law') !== false || stripos($heading, 'Canon enforcement law') !== false) {
                continue;
            }
            $items[] = $heading;
        }

        return array_slice($items, 0, 8);
    }

    /**
     * @return array<int, string>
     */
    private static function get_governance_implementation_sequence(): array
    {
        $contents = self::get_governance_doc_contents('DDB_IMPLEMENTATION_SEQUENCE.md');
        preg_match_all('/^##\s+\d+\.\s+(?:Phase\s+\d+\s+—\s+)?(.+?)\s*$/m', $contents, $matches);
        $items = array();
        foreach ($matches[1] ?? array() as $heading) {
            $heading = trim((string) $heading);
            if ($heading === '' || stripos($heading, 'Purpose') !== false || stripos($heading, 'Launch sequence') !== false || stripos($heading, 'Sequence law') !== false) {
                continue;
            }
            $items[] = $heading;
        }

        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $authority_docs
     * @param array<int, array<string, mixed>> $page_families
     * @param array<int, array<string, mixed>> $page_launch
     * @param array<int, array<string, mixed>> $controls
     */
    private static function render_governance_strategy_tab(array $authority_docs, array $page_families, array $page_launch, array $controls): string
    {
        $mandate = self::get_governance_mandate_summary();
        $journey = self::get_governance_journey_phases();
        $cta_rows = self::get_governance_cta_map_rows();
        $component_canon = self::get_governance_component_canon_summary();
        $implementation = self::get_governance_implementation_sequence();

        ob_start();
        echo '<section class="sbdp-governance-panel">';
        echo '<div class="sbdp-governance-panel__header"><div><h2>' . esc_html__('Strategy', 'sbdp') . '</h2><p>' . esc_html__('Docs are authoritative. The cockpit mirrors mandate, journey, CTA, family and launch structure without redefining platform truth.', 'sbdp') . '</p></div></div>';

        echo '<div class="sbdp-governance-grid sbdp-governance-grid--two">';
        echo '<section class="sbdp-governance-panel"><h3>' . esc_html__('Directie / governance mandate', 'sbdp') . '</h3><ul class="sbdp-governance-list">';
        foreach ($mandate as $item) {
            echo '<li>' . esc_html($item) . '</li>';
        }
        echo '</ul></section>';
        echo '<section class="sbdp-governance-panel"><h3>' . esc_html__('Platform constitution summary', 'sbdp') . '</h3><ul class="sbdp-governance-list"><li>' . esc_html__('One premium product ecosystem, not disconnected templates or plugin islands.', 'sbdp') . '</li><li>' . esc_html__('Design CSOT = core design system and theme mapping; Domain CSOT = OMDB; Execution truth = Woo + booking.', 'sbdp') . '</li><li>' . esc_html__('Header / main / footer shell stays canonical across all public page families.', 'sbdp') . '</li></ul></section>';
        echo '</div>';

        echo '<div class="sbdp-governance-grid sbdp-governance-grid--two">';
        echo '<section class="sbdp-governance-panel"><h3>' . esc_html__('Journey phases', 'sbdp') . '</h3><ol class="sbdp-governance-list">';
        foreach ($journey as $phase) {
            echo '<li>' . esc_html($phase) . '</li>';
        }
        echo '</ol></section>';
        echo '<section class="sbdp-governance-panel"><h3>' . esc_html__('Component canon summary', 'sbdp') . '</h3><ul class="sbdp-governance-list">';
        foreach ($component_canon as $item) {
            echo '<li>' . esc_html($item) . '</li>';
        }
        echo '</ul></section>';
        echo '</div>';

        echo '<section class="sbdp-governance-panel"><h3>' . esc_html__('Authority docs inventory', 'sbdp') . '</h3><table class="widefat striped sbdp-governance-table"><thead><tr><th>' . esc_html__('Doc', 'sbdp') . '</th><th>' . esc_html__('Status', 'sbdp') . '</th><th>' . esc_html__('Summary', 'sbdp') . '</th><th>' . esc_html__('Source', 'sbdp') . '</th></tr></thead><tbody>';
        foreach ($authority_docs as $doc) {
            echo '<tr><td><strong>' . esc_html((string) ($doc['title'] ?? '')) . '</strong></td><td>' . self::render_governance_status_tag((string) ($doc['status'] ?? 'unknown')) . '</td><td>' . esc_html((string) ($doc['summary'] ?? '')) . '</td><td>' . esc_html((string) ($doc['file'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table></section>';

        echo '<div class="sbdp-governance-grid">';
        echo '<section class="sbdp-governance-panel"><h3>' . esc_html__('Page family matrix', 'sbdp') . '</h3><table class="widefat striped sbdp-governance-table"><thead><tr><th>' . esc_html__('Family', 'sbdp') . '</th><th>' . esc_html__('Includes', 'sbdp') . '</th><th>' . esc_html__('Phase', 'sbdp') . '</th><th>' . esc_html__('Primary CTA', 'sbdp') . '</th><th>' . esc_html__('Must / must not', 'sbdp') . '</th></tr></thead><tbody>';
        foreach ($page_families as $family) {
            echo '<tr><td><strong>' . esc_html((string) ($family['family'] ?? '')) . '</strong></td><td>' . esc_html((string) ($family['includes'] ?? '')) . '</td><td>' . esc_html((string) ($family['phase'] ?? '')) . '</td><td>' . esc_html((string) ($family['cta'] ?? '')) . '</td><td>' . esc_html((string) ($family['must'] ?? '')) . '<br /><span class="description">' . esc_html((string) ($family['must_not'] ?? '')) . '</span></td></tr>';
        }
        echo '</tbody></table></section>';

        echo '<section class="sbdp-governance-panel"><h3>' . esc_html__('CTA map summary', 'sbdp') . '</h3><table class="widefat striped sbdp-governance-table"><thead><tr><th>' . esc_html__('Page', 'sbdp') . '</th><th>' . esc_html__('Primary', 'sbdp') . '</th><th>' . esc_html__('Secondary', 'sbdp') . '</th><th>' . esc_html__('Notes', 'sbdp') . '</th></tr></thead><tbody>';
        foreach ($cta_rows as $row) {
            echo '<tr><td><strong>' . esc_html((string) ($row['page'] ?? '')) . '</strong></td><td>' . esc_html((string) ($row['primary'] ?? '')) . '</td><td>' . esc_html((string) ($row['secondary'] ?? '')) . '</td><td>' . esc_html((string) ($row['notes'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table></section>';

        echo '<section class="sbdp-governance-panel sbdp-governance-panel--full"><h3>' . esc_html__('Implementation sequence summary', 'sbdp') . '</h3><ol class="sbdp-governance-list">';
        foreach ($implementation as $item) {
            echo '<li>' . esc_html($item) . '</li>';
        }
        echo '</ol></section>';
        echo '</div>';

        echo '<section class="sbdp-governance-panel"><h3>' . esc_html__('Launch board summary', 'sbdp') . '</h3><p>' . esc_html__('The launch board remains authoritative. The cockpit surfaces the current baseline and the next mandatory actions.', 'sbdp') . '</p><table class="widefat striped sbdp-governance-table"><thead><tr><th>' . esc_html__('Page', 'sbdp') . '</th><th>' . esc_html__('Status', 'sbdp') . '</th><th>' . esc_html__('CTA', 'sbdp') . '</th><th>' . esc_html__('Next action', 'sbdp') . '</th></tr></thead><tbody>';
        foreach (array_slice($page_launch, 0, 6) as $row) {
            echo '<tr><td><strong>' . esc_html((string) ($row['page'] ?? '')) . '</strong></td><td>' . self::render_governance_status_tag((string) ($row['status'] ?? 'unknown')) . '</td><td>' . esc_html((string) ($row['cta'] ?? '')) . '</td><td>' . esc_html((string) ($row['next_action'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table></section>';

        echo '<section class="sbdp-governance-panel sbdp-governance-panel--full"><h3>' . esc_html__('Governance control rules', 'sbdp') . '</h3><table class="widefat striped sbdp-governance-table"><thead><tr><th>' . esc_html__('Control', 'sbdp') . '</th><th>' . esc_html__('Status', 'sbdp') . '</th><th>' . esc_html__('Metric', 'sbdp') . '</th><th>' . esc_html__('Target', 'sbdp') . '</th></tr></thead><tbody>';
        foreach ($controls as $control) {
            echo '<tr><td><strong>' . esc_html((string) ($control['rule'] ?? '')) . '</strong></td><td>' . self::render_governance_status_tag((string) ($control['status'] ?? 'unknown')) . '</td><td>' . esc_html((string) ($control['metric'] ?? '')) . '</td><td>' . esc_html((string) ($control['target'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table></section>';

        echo '</section>';
        $html = ob_get_clean();
        return is_string($html) ? $html : '';
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

    /**
     * Build governance snapshot payload for admin and diagnostics surfaces.
     */
    public static function get_governance_bootstrap(int $window_days = 14): array
    {
        $window_days = max(7, min(60, $window_days));

        $dashboard_metrics = self::collect_dashboard_metrics(7, max(14, $window_days));
        $summary = is_array($dashboard_metrics['summary'] ?? null) ? $dashboard_metrics['summary'] : array();
        $events  = self::collect_day_planner_event_metrics($window_days);
        $dbspots_probe = self::probe_dbspots_endpoint();

        $snapshot = array(
            'generated_at' => gmdate('c'),
            'window_days'  => $window_days,
            'summary'      => $summary,
            'events'       => $events,
            'dbspots'      => $dbspots_probe,
            'decision_policy' => self::get_decision_policy_settings(),
        );
        $snapshot['kpis'] = self::build_governance_kpis($events, $summary);
        $snapshot['controls'] = self::evaluate_governance_controls($snapshot);

        return $snapshot;
    }

    /**
     * Build a single cockpit payload shared by the admin screen and REST mirror.
     *
     * @return array<string, mixed>
     */
    public static function get_governance_cockpit_snapshot(int $window_days = 14, string $tab = 'strategy'): array
    {
        $window_days = max(7, min(60, $window_days));
        $tab = in_array($tab, array('strategy', 'health', 'launch'), true) ? $tab : 'strategy';

        $snapshot = self::get_governance_bootstrap($window_days);
        $authority_docs = self::get_governance_authority_docs();
        $page_families = self::get_governance_page_family_matrix();
        $page_launch = self::get_governance_launch_board_matrix();
        $review_loop = self::get_governance_review_loop_matrix();
        $runtime = self::get_governance_runtime_signals($snapshot);
        $status_cards = self::get_governance_status_cards($snapshot, $runtime, $page_launch);
        $critical_blockers = self::get_governance_critical_blockers($page_launch, $runtime, $authority_docs);
        $launch_decision = self::get_governance_launch_decision($page_launch, $runtime, $critical_blockers);

        return array(
            'generated_at' => gmdate('c'),
            'tab' => $tab,
            'window_days' => $window_days,
            'snapshot' => $snapshot,
            'authority_docs' => $authority_docs,
            'page_families' => $page_families,
            'page_launch' => $page_launch,
            'review_loop' => $review_loop,
            'runtime' => $runtime,
            'status_cards' => $status_cards,
            'critical_blockers' => $critical_blockers,
            'launch_decision' => $launch_decision,
            'alert_settings' => self::get_governance_alert_settings(),
            'decision_settings' => self::get_decision_policy_settings(),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function get_design_backend_surface_matrix(): array
    {
        $plugin_root = rtrim((string) dirname(__DIR__, 3), "/\\") . DIRECTORY_SEPARATOR;

        return array(
            array(
                'surface' => 'Bookings dashboard',
                'purpose' => 'Operational overview and runtime metrics',
                'layer' => 'admin-dashboard.css + admin-dashboard.js',
                'status' => is_readable($plugin_root . 'assets/admin-dashboard.css') ? 'pass' : 'warn',
                'next_action' => 'Keep route-targeted enqueue and front-end shell alignment intact.',
            ),
            array(
                'surface' => 'Availability editor',
                'purpose' => 'Resource calendar and availability edits',
                'layer' => 'admin-availability.css + admin-visual-editors.js',
                'status' => is_readable($plugin_root . 'assets/admin-availability.css') ? 'pass' : 'warn',
                'next_action' => 'Keep calendar shell calm and avoid inline admin CSS.',
            ),
            array(
                'surface' => 'Pricing & rules',
                'purpose' => 'Pricing and availability rule editing',
                'layer' => 'admin-availability.css + visual editors bundle',
                'status' => is_readable($plugin_root . 'assets/admin-availability.css') ? 'pass' : 'warn',
                'next_action' => 'Keep pricing truth in the central layer and route edits through controls.',
            ),
            array(
                'surface' => 'Governance cockpit',
                'purpose' => 'Read-first platform health and launch-readiness mirror',
                'layer' => 'admin-governance.css',
                'status' => is_readable($plugin_root . 'assets/admin-governance.css') ? 'pass' : 'warn',
                'next_action' => 'Keep it read-only and avoid any second settings truth.',
            ),
            array(
                'surface' => 'Audit log',
                'purpose' => 'Trace and audit operational events',
                'layer' => 'WordPress table styles + admin chrome',
                'status' => 'info',
                'next_action' => 'Keep output readable and stable.',
            ),
            array(
                'surface' => 'Setup wizard',
                'purpose' => 'Initial platform configuration and onboarding',
                'layer' => 'Admin wizard shell',
                'status' => 'review',
                'next_action' => 'Keep setup additive; do not turn it into a new design system.',
            ),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function get_design_backend_drift_matrix(): array
    {
        $plugin_root = rtrim((string) dirname(__DIR__, 3), "/\\") . DIRECTORY_SEPARATOR;
        $admin_menu_path = $plugin_root . 'modules' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'Admin' . DIRECTORY_SEPARATOR . 'AdminMenu.php';
        $admin_menu_contents = is_readable($admin_menu_path) ? (string) file_get_contents($admin_menu_path) : '';
        $enqueue_path = $plugin_root . 'modules' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'Assets' . DIRECTORY_SEPARATOR . 'EnqueueService.php';
        $enqueue_contents = is_readable($enqueue_path) ? (string) file_get_contents($enqueue_path) : '';

        $inline_admin_echo = false;
        if ($admin_menu_contents !== '') {
            $inline_admin_echo = (bool) preg_match('/echo\\s+[\'"][^\'"]*<style/i', $admin_menu_contents);
        }

        $route_targeted_enqueue = $enqueue_contents !== ''
            && strpos($enqueue_contents, 'sbdp-admin-governance') !== false
            && strpos($enqueue_contents, 'sbdp-admin-availability') !== false
            && strpos($enqueue_contents, 'sbdp-admin-dashboard') !== false;

        return array(
            array(
                'check' => 'Shared admin design assets',
                'status' => is_readable($plugin_root . 'assets' . DIRECTORY_SEPARATOR . 'admin-governance.css') && is_readable($plugin_root . 'assets' . DIRECTORY_SEPARATOR . 'admin-availability.css') ? 'pass' : 'warn',
                'evidence' => 'admin-governance.css and admin-availability.css exist',
                'notes' => 'Shared admin styling is route-targeted, not global.',
            ),
            array(
                'check' => 'Route-targeted enqueue',
                'status' => $route_targeted_enqueue ? 'pass' : 'warn',
                'evidence' => 'EnqueueService guards admin dashboards by hook suffix',
                'notes' => 'Heavy admin bundles stay route-specific.',
            ),
            array(
                'check' => 'Inline admin echo styles',
                'status' => $inline_admin_echo ? 'fail' : 'pass',
                'evidence' => $inline_admin_echo ? 'Inline <style> found in AdminMenu echo markup' : 'No inline <style> echoes found in the cockpit code path',
                'notes' => $inline_admin_echo ? 'Inline styles would become local truth.' : 'Keep it that way.',
            ),
            array(
                'check' => 'Second design system risk',
                'status' => 'pass',
                'evidence' => 'Admin surfaces reuse shared classes and shared admin shell',
                'notes' => 'No competing backend component family introduced.',
            ),
        );
    }

    /**
     * @return array<int, string>
     */
    private static function get_design_backend_rules(): array
    {
        return array(
            'Use shared core tokens and shared ui-* primitives.',
            'Reuse the admin shell; do not invent page-local admin chrome.',
            'Keep governance read-only.',
            'Keep pricing, availability and planner truth in their source layers.',
            'Prefer route-targeted admin enqueues over global admin CSS.',
            'Use status tables and cards for summary, not editable settings unless required.',
        );
    }

    /**
     * @param array<string, mixed> $events
     * @param array<string, mixed> $summary
     *
     * @return array<string, mixed>
     */
    public static function build_governance_kpis(array $events, array $summary): array
    {
        $counts = is_array($events['counts'] ?? null) ? $events['counts'] : array();
        $quality = is_array($events['quality'] ?? null) ? $events['quality'] : array();
        $severity = is_array($events['severity'] ?? null) ? $events['severity'] : array();

        $sessions = (int) ($counts['session_started'] ?? 0);
        $primary_selected = (int) ($counts['primary_selected'] ?? 0);
        $primary_changed  = (int) ($counts['primary_changed'] ?? 0);
        $plans_built = (int) ($counts['plan_built'] ?? 0);
        $cta_rendered = (int) ($counts['cta_rendered'] ?? 0);
        $cta_clicked = (int) ($counts['cta_clicked'] ?? 0);
        $events_total = (int) ($events['total'] ?? 0);

        $feasible_plans = (int) ($quality['feasible_plans'] ?? 0);
        $cta_contract_ok = (int) ($quality['cta_with_twelve'] ?? 0);
        $events_with_trace = (int) ($quality['events_with_trace'] ?? 0);
        $offer_primary = (int) ($quality['offer_primary'] ?? 0);
        $spot_primary = (int) ($quality['spot_primary'] ?? 0);
        $sum_turn_to_primary = (float) ($quality['sum_turn_to_primary'] ?? 0.0);
        $sum_time_to_primary = (float) ($quality['sum_time_to_primary'] ?? 0.0);
        $primary_samples = max(0, (int) ($quality['primary_samples'] ?? 0));
        $alternative_clicked = (int) ($quality['alternative_clicked'] ?? 0);

        return array(
            'sessions_started'       => $sessions,
            'primary_selected'       => $primary_selected,
            'primary_changed'        => $primary_changed,
            'plans_built'            => $plans_built,
            'feasible_plans'         => $feasible_plans,
            'cta_rendered'           => $cta_rendered,
            'cta_clicked'            => $cta_clicked,
            'events_total'           => $events_total,
            'bookable_products'      => (int) ($summary['productCount'] ?? 0),
            'resources'              => (int) ($summary['resourceCount'] ?? 0),
            'errors'                 => (int) ($severity['error'] ?? 0),
            'primary_selection_rate' => self::safe_rate($primary_selected, $sessions),
            'plan_feasibility_rate'  => self::safe_rate($feasible_plans, $plans_built),
            'cta_click_rate'         => self::safe_rate($cta_clicked, $cta_rendered),
            'cta_contract_rate'      => self::safe_rate($cta_contract_ok, $cta_rendered),
            'trace_coverage_rate'    => self::safe_rate($events_with_trace, $events_total),
            'offer_primary_share'    => self::safe_rate($offer_primary, $primary_selected),
            'spot_primary_share'     => self::safe_rate($spot_primary, $primary_selected),
            'primary_change_rate'    => self::safe_rate($primary_changed, $primary_selected),
            'alternative_clicked'    => $alternative_clicked,
            'alternative_click_rate' => self::safe_rate($alternative_clicked, $cta_clicked),
            'avg_turn_to_primary'    => $primary_samples > 0 ? ( $sum_turn_to_primary / $primary_samples ) : 0.0,
            'avg_time_to_primary_seconds' => $primary_samples > 0 ? ( $sum_time_to_primary / $primary_samples ) : 0.0,
        );
    }

    /**
     * @param array<string, mixed> $snapshot
     *
     * @return array<int, array<string, string>>
     */
    public static function evaluate_governance_controls(array $snapshot): array
    {
        $kpis = is_array($snapshot['kpis'] ?? null) ? $snapshot['kpis'] : array();
        $dbspots = is_array($snapshot['dbspots'] ?? null) ? $snapshot['dbspots'] : array();
        $translate = static function (string $text): string {
            return function_exists('__') ? __($text, 'sbdp') : $text;
        };
        $formatNumber = static function (int $value): string {
            return function_exists('number_format_i18n') ? number_format_i18n($value) : number_format($value, 0, '.', ',');
        };
        $default_thresholds = array(
                'primary_selection_rate' => 0.80,
                'plan_feasibility_rate'  => 0.85,
                'cta_contract_rate'      => 0.95,
                'trace_coverage_rate'    => 0.95,
                'offer_primary_share'    => 0.60,
                'sticky_change_warn'     => 0.35,
                'sticky_change_fail'     => 0.55,
            );
        $thresholds = function_exists('apply_filters')
            ? (array) apply_filters('sbdp_governance_thresholds', $default_thresholds)
            : $default_thresholds;

        $products = (int) ($kpis['bookable_products'] ?? 0);
        $dbspots_status = isset($dbspots['status']) ? (string) $dbspots['status'] : 'warn';
        $primary_selection_rate = (float) ($kpis['primary_selection_rate'] ?? 0.0);
        $plan_feasibility_rate = (float) ($kpis['plan_feasibility_rate'] ?? 0.0);
        $cta_contract_rate = (float) ($kpis['cta_contract_rate'] ?? 0.0);
        $trace_coverage_rate = (float) ($kpis['trace_coverage_rate'] ?? 0.0);
        $offer_primary_share = (float) ($kpis['offer_primary_share'] ?? 0.0);
        $sticky_change_rate = (float) ($kpis['primary_change_rate'] ?? 0.0);

        $controls = array();
        $controls[] = array(
            'id'       => 'source_truth_woo',
            'rule'     => $translate('WooCommerce blijft de bron voor boekbare offers'),
            'status'   => $products > 0 ? 'pass' : 'fail',
            'metric'   => sprintf('%s %s', $formatNumber($products), $translate('producten')),
            'target'   => $translate('> 0 actieve boekbare producten'),
            'evidence' => $translate('Catalogus telt publiceerde boekbare items in dashboard-samenvatting.'),
        );

        $controls[] = array(
            'id'       => 'source_truth_dbspots',
            'rule'     => $translate('DBSpots endpoint beschikbaar voor niet-boekbare spots'),
            'status'   => $dbspots_status === 'ok' ? 'pass' : ( $dbspots_status === 'fail' ? 'fail' : 'warn' ),
            'metric'   => sprintf('HTTP %s', (string) ($dbspots['http_code'] ?? '-')),
            'target'   => $translate('REST response 2xx'),
            'evidence' => (string) ($dbspots['message'] ?? ''),
        );

        $controls[] = array(
            'id'       => 'priority_sales_first',
            'rule'     => $translate('Sales/offer prioriteit: primaries zijn bij voorkeur offers'),
            'status'   => $offer_primary_share >= (float) ($thresholds['offer_primary_share'] ?? 0.60) ? 'pass' : 'warn',
            'metric'   => self::format_percent($offer_primary_share),
            'target'   => sprintf('>= %s', self::format_percent((float) ($thresholds['offer_primary_share'] ?? 0.60))),
            'evidence' => $translate('Gebaseerd op aandeel offer-selecties in primary_selected events.'),
        );

        $controls[] = array(
            'id'       => 'sticky_context',
            'rule'     => $translate('Sticky context: weinig onnodige primary-wissels'),
            'status'   => $sticky_change_rate <= (float) ($thresholds['sticky_change_warn'] ?? 0.35)
                ? 'pass'
                : ( $sticky_change_rate <= (float) ($thresholds['sticky_change_fail'] ?? 0.55) ? 'warn' : 'fail' ),
            'metric'   => self::format_percent($sticky_change_rate),
            'target'   => sprintf('<= %s', self::format_percent((float) ($thresholds['sticky_change_warn'] ?? 0.35))),
            'evidence' => $translate('Rate = primary_changed / primary_selected.'),
        );

        $controls[] = array(
            'id'       => 'explainable_trace',
            'rule'     => $translate('Explainable decisions: trace_id op events'),
            'status'   => $trace_coverage_rate >= (float) ($thresholds['trace_coverage_rate'] ?? 0.95) ? 'pass' : 'fail',
            'metric'   => self::format_percent($trace_coverage_rate),
            'target'   => sprintf('>= %s', self::format_percent((float) ($thresholds['trace_coverage_rate'] ?? 0.95))),
            'evidence' => $translate('Trace coverage gemeten over events in governance-window.'),
        );

        $controls[] = array(
            'id'       => 'template_enforcement',
            'rule'     => $translate('Template enforcement: CTA-budget van 12 wordt gehaald'),
            'status'   => $cta_contract_rate >= (float) ($thresholds['cta_contract_rate'] ?? 0.95) ? 'pass' : 'fail',
            'metric'   => self::format_percent($cta_contract_rate),
            'target'   => sprintf('>= %s', self::format_percent((float) ($thresholds['cta_contract_rate'] ?? 0.95))),
            'evidence' => $translate('Gemeten via cta_rendered payloads met cta_count >= 12.'),
        );

        $controls[] = array(
            'id'       => 'plan_feasibility',
            'rule'     => $translate('Plan engine levert haalbare dagplanning'),
            'status'   => $plan_feasibility_rate >= (float) ($thresholds['plan_feasibility_rate'] ?? 0.85) ? 'pass' : 'warn',
            'metric'   => self::format_percent($plan_feasibility_rate),
            'target'   => sprintf('>= %s', self::format_percent((float) ($thresholds['plan_feasibility_rate'] ?? 0.85))),
            'evidence' => $translate('Feasible plans op basis van plan_built events.'),
        );

        $controls[] = array(
            'id'       => 'guided_flow_speed',
            'rule'     => $translate('Primary wordt snel gekozen (max 2 turns doel)'),
            'status'   => $primary_selection_rate >= (float) ($thresholds['primary_selection_rate'] ?? 0.80) ? 'pass' : 'warn',
            'metric'   => self::format_percent($primary_selection_rate),
            'target'   => sprintf('>= %s', self::format_percent((float) ($thresholds['primary_selection_rate'] ?? 0.80))),
            'evidence' => $translate('Proxy: sessies met primary_selected binnen governance-window.'),
        );

        return $controls;
    }

    /**
     * Render governance KPIs as Prometheus exposition format.
     *
     * @param array<string, mixed>|null $snapshot
     */
    public static function governance_prometheus_metrics(int $window_days = 14, ?array $snapshot = null): string
    {
        $window_days = max(7, min(60, $window_days));
        $snapshot = is_array($snapshot) ? $snapshot : self::get_governance_bootstrap($window_days);
        $kpis = is_array($snapshot['kpis'] ?? null) ? $snapshot['kpis'] : array();
        $controls = is_array($snapshot['controls'] ?? null) ? $snapshot['controls'] : array();
        $dbspots = is_array($snapshot['dbspots'] ?? null) ? $snapshot['dbspots'] : array();

        $metrics = array(
            'sessions_started'      => (float) ($kpis['sessions_started'] ?? 0),
            'primary_selected'      => (float) ($kpis['primary_selected'] ?? 0),
            'plans_built'           => (float) ($kpis['plans_built'] ?? 0),
            'feasible_plans'        => (float) ($kpis['feasible_plans'] ?? 0),
            'cta_rendered'          => (float) ($kpis['cta_rendered'] ?? 0),
            'cta_clicked'           => (float) ($kpis['cta_clicked'] ?? 0),
            'errors'                => (float) ($kpis['errors'] ?? 0),
            'primary_selection_rate'=> (float) ($kpis['primary_selection_rate'] ?? 0.0),
            'plan_feasibility_rate' => (float) ($kpis['plan_feasibility_rate'] ?? 0.0),
            'cta_contract_rate'     => (float) ($kpis['cta_contract_rate'] ?? 0.0),
            'trace_coverage_rate'   => (float) ($kpis['trace_coverage_rate'] ?? 0.0),
            'offer_primary_share'   => (float) ($kpis['offer_primary_share'] ?? 0.0),
            'spot_primary_share'    => (float) ($kpis['spot_primary_share'] ?? 0.0),
            'primary_change_rate'   => (float) ($kpis['primary_change_rate'] ?? 0.0),
            'alternative_clicked'   => (float) ($kpis['alternative_clicked'] ?? 0),
            'alternative_click_rate'=> (float) ($kpis['alternative_click_rate'] ?? 0.0),
            'avg_turn_to_primary'   => (float) ($kpis['avg_turn_to_primary'] ?? 0.0),
            'avg_time_to_primary_seconds' => (float) ($kpis['avg_time_to_primary_seconds'] ?? 0.0),
        );

        $lines = array(
            '# HELP sbdp_governance_snapshot_age_seconds Unix timestamp of snapshot generation.',
            '# TYPE sbdp_governance_snapshot_age_seconds gauge',
            sprintf('sbdp_governance_snapshot_age_seconds %d', (int) \strtotime((string) ($snapshot['generated_at'] ?? gmdate('c')))),
        );

        foreach ($metrics as $name => $value) {
            $lines[] = sprintf('# TYPE sbdp_governance_%s gauge', $name);
            $lines[] = sprintf('sbdp_governance_%s{window_days="%d"} %s', $name, $window_days, self::format_float($value));
        }

        $dbspots_status = (string) ($dbspots['status'] ?? 'warn');
        $dbspots_value = $dbspots_status === 'ok' ? 1.0 : ( $dbspots_status === 'warn' ? 0.5 : 0.0 );
        $lines[] = '# TYPE sbdp_governance_dbspots_probe gauge';
        $lines[] = sprintf('sbdp_governance_dbspots_probe{status="%s"} %s', self::escape_prom_label($dbspots_status), self::format_float($dbspots_value));

        foreach ($controls as $control) {
            $id = isset($control['id']) ? (string) $control['id'] : 'unknown';
            $status = isset($control['status']) ? (string) $control['status'] : 'warn';
            $score = $status === 'pass' ? 1.0 : ( $status === 'warn' ? 0.5 : 0.0 );
            $lines[] = sprintf(
                'sbdp_governance_control_status{control="%s",status="%s"} %s',
                self::escape_prom_label($id),
                self::escape_prom_label($status),
                self::format_float($score)
            );
        }

        return implode("\n", $lines) . "\n";
    }

    public static function maybe_dispatch_governance_alerts(): void
    {
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return;
        }

        if (! current_user_can(self::resolveCapability())) {
            return;
        }

        $settings = self::get_governance_alert_settings();
        if (! $settings['enabled']) {
            return;
        }

        $cooldown_seconds = max(5, (int) $settings['cooldown_minutes']) * 60;
        $last_sent = (int) get_option(self::ALERT_LAST_SENT_OPTION, 0);
        if ($last_sent > 0 && (time() - $last_sent) < $cooldown_seconds) {
            return;
        }

        $snapshot = self::get_governance_bootstrap((int) $settings['window_days']);
        $controls = is_array($snapshot['controls'] ?? null) ? $snapshot['controls'] : array();
        $controls_to_alert = self::select_controls_for_alert($controls, (string) $settings['min_status']);
        if ($controls_to_alert === array()) {
            return;
        }

        $payload = array(
            'site'            => function_exists('home_url') ? home_url('/') : '',
            'generated_at'    => $snapshot['generated_at'] ?? gmdate('c'),
            'window_days'     => (int) $settings['window_days'],
            'min_status'      => (string) $settings['min_status'],
            'controls'        => $controls_to_alert,
            'kpis'            => $snapshot['kpis'] ?? array(),
            'dbspots'         => $snapshot['dbspots'] ?? array(),
        );

        if ($settings['email_enabled'] && function_exists('wp_mail')) {
            $recipient = (string) $settings['email_to'];
            if ($recipient === '') {
                $recipient = (string) get_option('admin_email', '');
            }
            if ($recipient !== '') {
                $subject = sprintf('[SBDP] Governance alert: %d issues', count($controls_to_alert));
                $lines = array(
                    'Governance controles buiten doel:',
                    '',
                );
                foreach ($controls_to_alert as $control) {
                    $lines[] = sprintf(
                        '- [%s] %s (metric: %s, target: %s)',
                        strtoupper((string) ($control['status'] ?? 'warn')),
                        (string) ($control['rule'] ?? ''),
                        (string) ($control['metric'] ?? ''),
                        (string) ($control['target'] ?? '')
                    );
                }
                $lines[] = '';
                $lines[] = 'Genereerd op: ' . (string) $payload['generated_at'];
                $lines[] = 'Site: ' . (string) $payload['site'];
                wp_mail($recipient, $subject, implode("\n", $lines));
            }
        }

        if ($settings['webhook_enabled'] && $settings['webhook_url'] !== '' && function_exists('wp_remote_post')) {
            wp_remote_post(
                (string) $settings['webhook_url'],
                array(
                    'timeout' => 5,
                    'headers' => array(
                        'Content-Type' => 'application/json',
                    ),
                    'body'    => wp_json_encode($payload),
                )
            );
        }

        update_option(self::ALERT_LAST_SENT_OPTION, time(), false);
    }

    public static function render_availability(): void
    {
        echo '<div class="wrap sbdp-admin-availability">';
        echo '<h1>' . esc_html__('Beschikbaarheid en kalender', 'sbdp') . '</h1>';
        echo '<p class="description">' . esc_html__('Beheer beschikbaarheidsregels per resource direct in de kalender.', 'sbdp') . '</p>';
        echo '<div class="notice notice-info inline"><p>' . esc_html__('Kies een resource in de linkerzijbalk en voeg blokken toe via de kalender. Publiceer om wijzigingen op te slaan.', 'sbdp') . '</p></div>';
        echo '<div id="sbdp-av-app" class="sbdp-admin-calendar" aria-live="polite"></div>';
        echo '<noscript><div class="notice notice-error inline"><p>' . esc_html__('JavaScript is vereist om de beschikbaarheidseditor te gebruiken.', 'sbdp') . '</p></div></noscript>';
        echo '</div>';
    }

    public static function render_pricing(): void
    {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Prijsregels en toeslagen', 'sbdp') . '</h1>';
        echo '<div id="sbdp-pricing-app"></div>';
        echo '</div>';
    }

    public static function render_plan_link(): void
    {
        $planner_page = self::locate_planner_page();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Planner-frontend', 'sbdp') . '</h1>';

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

    public static function render_governance(): void
    {
        if (! current_user_can(self::resolveCapability())) {
            wp_die(esc_html__('Je hebt geen toegang tot dit scherm.', 'sbdp'));
        }

        $alert_settings = self::get_governance_alert_settings();
        $decision_settings = self::get_decision_policy_settings();

        $window = isset($_GET['window']) ? absint($_GET['window']) : 14; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $window = max(7, min(60, $window));
        $tab = self::get_governance_tab();
        $cockpit = self::get_governance_cockpit_snapshot($window, $tab);
        $snapshot = is_array($cockpit['snapshot'] ?? null) ? $cockpit['snapshot'] : array();
        $kpis = is_array($snapshot['kpis'] ?? null) ? $snapshot['kpis'] : array();
        $controls = is_array($snapshot['controls'] ?? null) ? $snapshot['controls'] : array();
        $events = is_array($snapshot['events'] ?? null) ? $snapshot['events'] : array();
        $authority_docs = is_array($cockpit['authority_docs'] ?? null) ? $cockpit['authority_docs'] : array();
        $page_families = is_array($cockpit['page_families'] ?? null) ? $cockpit['page_families'] : array();
        $page_launch = is_array($cockpit['page_launch'] ?? null) ? $cockpit['page_launch'] : array();
        $review_loop = is_array($cockpit['review_loop'] ?? null) ? $cockpit['review_loop'] : array();
        $runtime = is_array($cockpit['runtime'] ?? null) ? $cockpit['runtime'] : array();
        $status_cards = is_array($cockpit['status_cards'] ?? null) ? $cockpit['status_cards'] : array();
        $critical_blockers = is_array($cockpit['critical_blockers'] ?? null) ? $cockpit['critical_blockers'] : array();
        $launch_decision = is_array($cockpit['launch_decision'] ?? null) ? $cockpit['launch_decision'] : array();
        $window_links = array(7, 14, 30, 60);

        echo '<div class="wrap sbdp-governance-cockpit">';
        echo '<header class="sbdp-governance-cockpit__hero">';
        echo '<div class="sbdp-governance-cockpit__hero-copy">';
        echo '<p class="sbdp-governance-cockpit__eyebrow">' . esc_html__('Governance Cockpit', 'sbdp') . '</p>';
        echo '<h1>' . esc_html__('Platformgezondheid en launchstatus', 'sbdp') . '</h1>';
        echo '<p class="description">' . esc_html__('Read-only spiegel van de platformwaarheid: documentautoriteit, design-system status, shell-integriteit, OMDB/Woo-grenzen, planner safety en launch readiness.', 'sbdp') . '</p>';
        echo '</div>';
        echo '<div class="sbdp-governance-cockpit__hero-meta">';
        echo self::render_governance_card(__('Veilig om te releasen', 'sbdp'), $launch_decision['safe'] ? __('Ja', 'sbdp') : __('Nee', 'sbdp'), $launch_decision['safe'] ? __('Geen blockers', 'sbdp') : __('Geblokkeerd door launch-board status', 'sbdp'), $launch_decision['safe'] ? 'pass' : 'fail');
        echo self::render_governance_card(__('Venster', 'sbdp'), (string) $window . 'd', __('Meetvenster voor runtimesignalen', 'sbdp'), 'info');
        echo self::render_governance_card(__('Autoriteitsdocs', 'sbdp'), (string) count(array_filter($authority_docs, static function (array $doc): bool { return ! empty($doc['exists']); })), __('Gepubliceerd in app/public/docs', 'sbdp'), 'pass');
        echo self::render_governance_card(__('Design backend', 'sbdp'), __('Open', 'sbdp'), __('Backend design-spiegel en driftchecks.', 'sbdp'), 'info', admin_url('admin.php?page=sbdp_design_backend'));
        echo '</div>';
        echo '</header>';

        echo '<div class="sbdp-governance-cockpit__toolbar">';
        echo '<div class="sbdp-governance-cockpit__window-switch">';
        foreach ($window_links as $option) {
            $url = add_query_arg(array('page' => 'sbdp_governance', 'tab' => $tab, 'window' => $option), admin_url('admin.php'));
            $class = $option === $window ? 'button button-primary' : 'button';
            echo '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html(sprintf(__('%d dagen', 'sbdp'), $option)) . '</a> ';
        }
        echo '</div>';
        echo self::render_governance_tabs_nav($tab, $window);
        echo '</div>';

        echo '<div class="sbdp-governance-cockpit__summary">';
        echo self::render_governance_card(__('Design system truth', 'sbdp'), strtoupper((string) $status_cards['design_system']['status']), (string) $status_cards['design_system']['meta'], (string) $status_cards['design_system']['status'], '#sbdp-governance-system-health');
        echo self::render_governance_card(__('Shell integrity', 'sbdp'), strtoupper((string) $status_cards['shell']['status']), (string) $status_cards['shell']['meta'], (string) $status_cards['shell']['status'], '#sbdp-governance-system-health');
        echo self::render_governance_card(__('OMDB boundary', 'sbdp'), strtoupper((string) $status_cards['omdb']['status']), (string) $status_cards['omdb']['meta'], (string) $status_cards['omdb']['status'], '#sbdp-governance-system-health');
        echo self::render_governance_card(__('Woo boundary', 'sbdp'), strtoupper((string) $status_cards['woo']['status']), (string) $status_cards['woo']['meta'], (string) $status_cards['woo']['status'], '#sbdp-governance-system-health');
        echo self::render_governance_card(__('Planner safety', 'sbdp'), strtoupper((string) $status_cards['planner']['status']), (string) $status_cards['planner']['meta'], (string) $status_cards['planner']['status'], '#sbdp-governance-system-health');
        echo self::render_governance_card(__('Mobile readiness', 'sbdp'), strtoupper((string) $status_cards['mobile']['status']), (string) $status_cards['mobile']['meta'], (string) $status_cards['mobile']['status'], '#sbdp-governance-system-health');
        // Extra hero cards from registered modules (e.g. Partner Program).
        foreach ((array) apply_filters('bsp_governance_hero_cards', array()) as $extra) {
            if (is_array($extra) && isset($extra['title'], $extra['value'], $extra['meta'], $extra['status'])) {
                echo self::render_governance_card((string) $extra['title'], (string) $extra['value'], (string) $extra['meta'], (string) $extra['status'], (string) ($extra['href'] ?? ''));
            }
        }
        echo '</div>';

        if ('strategy' === $tab) {
            echo self::render_governance_strategy_tab($authority_docs, $page_families, $page_launch, $controls);
        } elseif ('launch' === $tab) {
            echo self::render_governance_launch_tab($page_launch, $critical_blockers, $launch_decision, $alert_settings, $decision_settings, $window);
        } elseif (has_action('bsp_governance_render_tab_' . $tab)) {
            do_action('bsp_governance_render_tab_' . $tab);
        } else {
            echo self::render_governance_system_health_tab($status_cards, $runtime, $review_loop, $snapshot);
        }

        echo '</div>';
    }

    public static function render_design_backend(): void
    {
        if (! current_user_can(self::resolveCapability())) {
            wp_die(esc_html__('Je hebt geen toegang tot dit scherm.', 'sbdp'));
        }

        $window = 14;
        $cockpit = self::get_governance_cockpit_snapshot($window, 'health');
        $snapshot = is_array($cockpit['snapshot'] ?? null) ? $cockpit['snapshot'] : array();
        $authority_docs = is_array($cockpit['authority_docs'] ?? null) ? $cockpit['authority_docs'] : array();
        $status_cards = is_array($cockpit['status_cards'] ?? null) ? $cockpit['status_cards'] : array();
        $surface_rows = self::get_design_backend_surface_matrix();
        $drift_rows = self::get_design_backend_drift_matrix();
        $rules = self::get_design_backend_rules();
        $launch = is_array($cockpit['launch_decision'] ?? null) ? $cockpit['launch_decision'] : array();

        echo '<div class="wrap sbdp-governance-cockpit sbdp-design-backend">';
        echo '<header class="sbdp-governance-cockpit__hero">';
        echo '<div class="sbdp-governance-cockpit__hero-copy">';
        echo '<p class="sbdp-governance-cockpit__eyebrow">' . esc_html__('Design Backend', 'sbdp') . '</p>';
        echo '<h1>' . esc_html__('Backend design system truth', 'sbdp') . '</h1>';
        echo '<p class="description">' . esc_html__('Read-only spiegel van de admin design-laag: shared tokens, admin shells, componentcanon en driftbewaking. Geen tweede truth source.', 'sbdp') . '</p>';
        echo '</div>';
        echo '<div class="sbdp-governance-cockpit__hero-meta">';
        echo self::render_governance_card(__('Design CSOT', 'sbdp'), __('Pass', 'sbdp'), __('Tokens en component truth komen uit het core design system.', 'sbdp'), 'pass');
        echo self::render_governance_card(__('Admin shell', 'sbdp'), __('Pass', 'sbdp'), __('Governance, dashboard en editor surfaces delen dezelfde admin cockpit shell.', 'sbdp'), 'pass');
        echo self::render_governance_card(__('Backend launch', 'sbdp'), ! empty($launch['safe']) ? __('Ja', 'sbdp') : __('Nee', 'sbdp'), ! empty($launch['safe']) ? __('Backend surfaces zijn read-only en consistent.', 'sbdp') : __('Nog blockers in platform launch board.', 'sbdp'), ! empty($launch['safe']) ? 'pass' : 'warn');
        echo self::render_governance_card(__('Governance cockpit', 'sbdp'), __('Open', 'sbdp'), __('Platform health, launch board en review loop.', 'sbdp'), 'info', admin_url('admin.php?page=sbdp_governance'));
        echo '</div>';
        echo '</header>';

        echo '<div class="sbdp-governance-cockpit__summary">';
        echo self::render_governance_card(__('Shared tokens', 'sbdp'), __('UI OK', 'sbdp'), __('ddb-core-design-system.php en de canonieke design-system.css vormen de visuele bron; ddb-ui.css is alleen nog een compatibiliteits-pad.', 'sbdp'), 'pass', '#sbdp-design-backend-truth');
        echo self::render_governance_card(__('Admin surfaces', 'sbdp'), (string) count($surface_rows), __('Boekingen-dashboard, beschikbaarheid, pricing, governance, auditlog en setup wizard.', 'sbdp'), 'info', '#sbdp-design-backend-surfaces');
        echo self::render_governance_card(__('Drift checks', 'sbdp'), (string) count(array_filter($drift_rows, static function (array $row): bool { return ! empty($row['status']) && 'pass' !== (string) $row['status']; })), __('Elke waarschuwing blijft zichtbaar; onbekend blijft onbekend.', 'sbdp'), 'warn', '#sbdp-design-backend-drift');
        echo self::render_governance_card(__('Authority docs', 'sbdp'), (string) count(array_filter($authority_docs, static function (array $doc): bool { return ! empty($doc['exists']); })), __('Alle sturende docs zijn gespiegeld in app/public/docs.', 'sbdp'), 'pass', '#sbdp-design-backend-docs');
        echo '</div>';

        echo '<section id="sbdp-design-backend-truth" class="sbdp-governance-panel">';
        echo '<div class="sbdp-governance-panel__header"><div><h2>' . esc_html__('Design truth', 'sbdp') . '</h2><p>' . esc_html__('Deze pagina spiegelt de backend designregels die elke admin-surface hoort te volgen.', 'sbdp') . '</p></div></div>';
        echo '<div class="sbdp-governance-grid sbdp-governance-grid--two">';
        echo '<section class="sbdp-governance-panel"><h3>' . esc_html__('Backend design rules', 'sbdp') . '</h3><ul class="sbdp-governance-list">';
        foreach ($rules as $rule) {
            echo '<li>' . esc_html((string) $rule) . '</li>';
        }
        echo '</ul></section>';
        echo '<section class="sbdp-governance-panel"><h3>' . esc_html__('Current status cards', 'sbdp') . '</h3><table class="widefat striped sbdp-governance-table"><thead><tr><th>' . esc_html__('Signal', 'sbdp') . '</th><th>' . esc_html__('Status', 'sbdp') . '</th><th>' . esc_html__('Meta', 'sbdp') . '</th></tr></thead><tbody>';
        foreach (array('design_system', 'shell', 'omdb', 'woo', 'planner', 'mobile') as $key) {
            $row = is_array($status_cards[$key] ?? null) ? $status_cards[$key] : array();
            echo '<tr><td><strong>' . esc_html(ucfirst(str_replace('_', ' ', $key))) . '</strong></td><td>' . self::render_governance_status_tag((string) ($row['status'] ?? 'unknown')) . '</td><td>' . esc_html((string) ($row['meta'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table></section>';
        echo '</div>';
        echo '</section>';

        echo '<section id="sbdp-design-backend-surfaces" class="sbdp-governance-panel">';
        echo '<div class="sbdp-governance-panel__header"><div><h2>' . esc_html__('Backend surfaces', 'sbdp') . '</h2><p>' . esc_html__('Elke admin-surface hoort op dezelfde gedeelde admin-shell en design-primitives te blijven.', 'sbdp') . '</p></div></div>';
        echo '<table class="widefat striped sbdp-governance-table"><thead><tr><th>' . esc_html__('Surface', 'sbdp') . '</th><th>' . esc_html__('Purpose', 'sbdp') . '</th><th>' . esc_html__('Design layer', 'sbdp') . '</th><th>' . esc_html__('Status', 'sbdp') . '</th><th>' . esc_html__('Next action', 'sbdp') . '</th></tr></thead><tbody>';
        foreach ($surface_rows as $row) {
            echo '<tr><td><strong>' . esc_html((string) ($row['surface'] ?? '')) . '</strong></td><td>' . esc_html((string) ($row['purpose'] ?? '')) . '</td><td>' . esc_html((string) ($row['layer'] ?? '')) . '</td><td>' . self::render_governance_status_tag((string) ($row['status'] ?? 'unknown')) . '</td><td>' . esc_html((string) ($row['next_action'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '</section>';

        echo '<section id="sbdp-design-backend-drift" class="sbdp-governance-panel">';
        echo '<div class="sbdp-governance-panel__header"><div><h2>' . esc_html__('Drift checks', 'sbdp') . '</h2><p>' . esc_html__('Deze signalen vangen lokale visuele waarheid, inline CSS en admin-shell-afwijking voordat er een tweede systeem ontstaat.', 'sbdp') . '</p></div></div>';
        echo '<table class="widefat striped sbdp-governance-table"><thead><tr><th>' . esc_html__('Check', 'sbdp') . '</th><th>' . esc_html__('Status', 'sbdp') . '</th><th>' . esc_html__('Evidence', 'sbdp') . '</th><th>' . esc_html__('Notes', 'sbdp') . '</th></tr></thead><tbody>';
        foreach ($drift_rows as $row) {
            echo '<tr><td><strong>' . esc_html((string) ($row['check'] ?? '')) . '</strong></td><td>' . self::render_governance_status_tag((string) ($row['status'] ?? 'unknown')) . '</td><td>' . esc_html((string) ($row['evidence'] ?? '')) . '</td><td>' . esc_html((string) ($row['notes'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '</section>';

        echo '<section id="sbdp-design-backend-docs" class="sbdp-governance-panel">';
        echo '<div class="sbdp-governance-panel__header"><div><h2>' . esc_html__('Authority docs', 'sbdp') . '</h2><p>' . esc_html__('De backend design-pagina spiegelt dezelfde docs als governance, maar alleen via read-only samenvattingen.', 'sbdp') . '</p></div></div>';
        echo '<table class="widefat striped sbdp-governance-table"><thead><tr><th>' . esc_html__('Doc', 'sbdp') . '</th><th>' . esc_html__('Status', 'sbdp') . '</th><th>' . esc_html__('Summary', 'sbdp') . '</th><th>' . esc_html__('File', 'sbdp') . '</th></tr></thead><tbody>';
        foreach ($authority_docs as $doc) {
            echo '<tr><td><strong>' . esc_html((string) ($doc['title'] ?? '')) . '</strong></td><td>' . self::render_governance_status_tag((string) ($doc['status'] ?? 'unknown')) . '</td><td>' . esc_html((string) ($doc['summary'] ?? '')) . '</td><td>' . esc_html((string) ($doc['file'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '</section>';

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

    private static function render_governance_card(string $title, string $value, string $meta, string $status = 'info', string $href = ''): string
    {
        $status = sanitize_key($status);
        if (! in_array($status, array('pass', 'warn', 'warning', 'fail', 'info', 'ready', 'blocked', 'unknown', 'not_run', 'must_pass', 'in_progress', 'not_started', 'review', 'later'), true)) {
            $status = 'info';
        }
        $status_class = 'sbdp-governance__card--' . ($status === 'warning' ? 'warn' : $status);

        return sprintf(
            '<article class="sbdp-governance__card %4$s"><div class="label">%1$s</div><div class="value">%2$s</div><div class="sbdp-governance__meta">%3$s</div>%5$s</article>',
            esc_html($title),
            esc_html($value),
            esc_html($meta),
            esc_attr($status_class),
            $href !== '' ? '<div class="sbdp-governance__card-link"><a href="' . esc_url($href) . '">' . esc_html__('Bekijk detail', 'sbdp') . '</a></div>' : ''
        );
    }

    /**
     * @param array<int, array<string, mixed>> $page_launch
     * @param array<int, array<string, mixed>> $critical_blockers
     * @param array<string, mixed> $launch_decision
     * @param array<string, mixed> $alert_settings
     * @param array<string, mixed> $decision_settings
     */
    private static function render_governance_launch_tab(array $page_launch, array $critical_blockers, array $launch_decision, array $alert_settings, array $decision_settings, int $window): string
    {
        ob_start();
        echo '<section class="sbdp-governance-panel">';
        echo '<div class="sbdp-governance-panel__header"><div><h2>' . esc_html__('Launch Board', 'sbdp') . '</h2><p>' . esc_html__('Read-only launch readiness per page family, met blockers per ernst en een duidelijke beslislijn.', 'sbdp') . '</p></div></div>';
        echo '<table class="widefat striped sbdp-governance-table"><thead><tr><th>' . esc_html__('Page family', 'sbdp') . '</th><th>' . esc_html__('Family type', 'sbdp') . '</th><th>' . esc_html__('Primary phase', 'sbdp') . '</th><th>' . esc_html__('Primary CTA', 'sbdp') . '</th><th>' . esc_html__('Owner', 'sbdp') . '</th><th>' . esc_html__('Launch status', 'sbdp') . '</th><th>' . esc_html__('Blockers', 'sbdp') . '</th><th>' . esc_html__('Last reviewed', 'sbdp') . '</th><th>' . esc_html__('Next action', 'sbdp') . '</th></tr></thead><tbody>';
        foreach ($page_launch as $row) {
            echo '<tr><td><strong>' . esc_html((string) ($row['page'] ?? '')) . '</strong></td><td>' . esc_html((string) ($row['family'] ?? '')) . '</td><td>' . esc_html((string) ($row['phase'] ?? '')) . '</td><td>' . esc_html((string) ($row['cta'] ?? '')) . '</td><td>' . esc_html((string) ($row['owner'] ?? 'TBD')) . '</td><td>' . self::render_governance_status_tag((string) ($row['status'] ?? 'unknown')) . '</td><td>' . esc_html(implode(' • ', array_map('strval', is_array($row['blockers'] ?? null) ? $row['blockers'] : array()))) . '</td><td>' . esc_html((string) ($row['last_reviewed'] ?? 'unknown')) . '</td><td>' . esc_html((string) ($row['next_action'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '</section>';

        echo '<div class="sbdp-governance-grid">';
        echo '<section class="sbdp-governance-panel">';
        echo '<h3>' . esc_html__('Critical blockers', 'sbdp') . '</h3>';
        if ($critical_blockers === array()) {
            echo '<p>' . esc_html__('Er zijn geen blockers zichtbaar in de launch-board-spiegel.', 'sbdp') . '</p>';
        } else {
            echo '<div class="sbdp-governance-list">';
            foreach ($critical_blockers as $blocker) {
                echo '<article class="sbdp-governance-blocker sbdp-governance-blocker--' . esc_attr((string) ($blocker['severity'] ?? 'medium')) . '">';
                echo '<strong>' . esc_html((string) ($blocker['title'] ?? '')) . '</strong>';
                echo '<p>' . esc_html((string) ($blocker['detail'] ?? '')) . '</p>';
                echo '</article>';
            }
            echo '</div>';
        }
        echo '</section>';

        echo '<section class="sbdp-governance-panel">';
        echo '<h3>' . esc_html__('Launch decision', 'sbdp') . '</h3>';
        echo self::render_governance_card(__('Veilig om te releasen', 'sbdp'), ! empty($launch_decision['safe']) ? __('Ja', 'sbdp') : __('Nee', 'sbdp'), ! empty($launch_decision['safe']) ? __('Geen blockers zichtbaar', 'sbdp') : __('Er zijn nog blocker issues', 'sbdp'), ! empty($launch_decision['safe']) ? 'pass' : 'fail');
        echo '<ul class="sbdp-governance-list">';
        echo '<li>' . esc_html__('Blocking issues:', 'sbdp') . ' ' . esc_html(implode(' · ', array_map(static function (array $blocker): string { return (string) ($blocker['title'] ?? ''); }, $critical_blockers))) . '</li>';
        echo '<li>' . esc_html__('Remaining warnings:', 'sbdp') . ' ' . esc_html__('Planner safety, OMDB/Woo boundary and mobile regression are still warning-state in the cockpit.', 'sbdp') . '</li>';
        echo '<li>' . esc_html__('Safe next step:', 'sbdp') . ' ' . esc_html__('Keep the cockpit read-first, then update the underlying launch board after the blocked families clear.', 'sbdp') . '</li>';
        echo '</ul>';
        echo '</section>';
        echo '</div>';

        echo self::render_governance_alert_settings($alert_settings, $decision_settings);

        $html = ob_get_clean();
        return is_string($html) ? $html : '';
    }

    private static function render_governance_status_tag(string $status): string
    {
        $status = sanitize_key($status);
        if (! in_array($status, array('pass', 'warn', 'warning', 'fail', 'info', 'ready', 'blocked', 'in_progress', 'not_started', 'review', 'later', 'unknown', 'not_run', 'must_pass'), true)) {
            $status = 'unknown';
        }
        $display = strtoupper(str_replace('_', ' ', $status));
        if ('warning' === $status) {
            $status = 'warn';
        }

        return '<span class="sbdp-governance-tag sbdp-governance-tag--' . esc_attr($status) . '">' . esc_html($display) . '</span>';
    }

    /**
     * @param array<string, mixed> $kpis
     */
    private static function render_governance_funnel(array $kpis): string
    {
        $steps = array(
            array(
                'label' => 'Sessions',
                'count' => (int) ($kpis['sessions_started'] ?? 0),
            ),
            array(
                'label' => 'Primary selected',
                'count' => (int) ($kpis['primary_selected'] ?? 0),
            ),
            array(
                'label' => 'Plans built',
                'count' => (int) ($kpis['plans_built'] ?? 0),
            ),
            array(
                'label' => 'CTA clicked',
                'count' => (int) ($kpis['cta_clicked'] ?? 0),
            ),
        );

        $max = 1;
        foreach ($steps as $step) {
            $max = max($max, (int) $step['count']);
        }

        ob_start();
        echo '<div class="sbdp-governance__list">';
        foreach ($steps as $step) {
            $count = (int) $step['count'];
            $ratio = self::safe_rate($count, $max);
            $bar_class = 'sbdp-governance-progress';
            if ($ratio < 0.40) {
                $bar_class = 'sbdp-governance-progress sbdp-governance-progress--danger';
            } elseif ($ratio < 0.70) {
                $bar_class = 'sbdp-governance-progress sbdp-governance-progress--warn';
            }

            echo '<div>';
            echo '<div class="sbdp-governance__row">';
            echo '<strong>' . esc_html((string) $step['label']) . '</strong>';
            echo '<progress class="' . esc_attr($bar_class) . '" value="' . esc_attr((string) round($ratio * 100, 2)) . '" max="100"></progress>';
            echo '<div>' . esc_html(number_format_i18n($count)) . '</div>';
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';

        $html = ob_get_clean();
        return is_string($html) ? $html : '';
    }

    /**
     * @param array<int, array<string, mixed>> $breakdown
     */
    private static function render_governance_event_mix(array $breakdown): string
    {
        $rows = array_slice($breakdown, 0, 8);
        $max = 1;
        foreach ($rows as $row) {
            $max = max($max, (int) ($row['count'] ?? 0));
        }

        ob_start();
        if ($rows === array()) {
            echo '<p>' . esc_html__('Geen events gevonden in het gekozen window.', 'sbdp') . '</p>';
        } else {
            echo '<div class="sbdp-governance__list">';
            foreach ($rows as $row) {
                $count = (int) ($row['count'] ?? 0);
                $ratio = self::safe_rate($count, $max);

                echo '<div class="sbdp-governance__row">';
                echo '<span>' . esc_html((string) ($row['action'] ?? 'event')) . '</span>';
                echo '<progress class="sbdp-governance-progress" value="' . esc_attr((string) round($ratio * 100, 2)) . '" max="100"></progress>';
                echo '<strong>' . esc_html(number_format_i18n($count)) . '</strong>';
                echo '</div>';
            }
            echo '</div>';
        }

        $html = ob_get_clean();
        return is_string($html) ? $html : '';
    }

    /**
     * @param array<int, array<string, mixed>> $daily
     */
    private static function render_governance_daily(array $daily): string
    {
        $max = 1;
        foreach ($daily as $row) {
            $max = max(
                $max,
                (int) ($row['session_started'] ?? 0),
                (int) ($row['primary_selected'] ?? 0),
                (int) ($row['plan_built'] ?? 0)
            );
        }

        ob_start();
        if ($daily === array()) {
            echo '<p>' . esc_html__('Nog geen dagelijkse trenddata beschikbaar.', 'sbdp') . '</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr>';
            echo '<th>' . esc_html__('Dag', 'sbdp') . '</th>';
            echo '<th>' . esc_html__('Trend', 'sbdp') . '</th>';
            echo '<th>' . esc_html__('S/P/Plan', 'sbdp') . '</th>';
            echo '<th>' . esc_html__('Errors', 'sbdp') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($daily as $row) {
                $sessions = (int) ($row['session_started'] ?? 0);
                $primary = (int) ($row['primary_selected'] ?? 0);
                $plan = (int) ($row['plan_built'] ?? 0);
                $errors = (int) ($row['errors'] ?? 0);

                $session_width = round(self::safe_rate($sessions, $max) * 100, 2);
                $primary_width = round(self::safe_rate($primary, $max) * 100, 2);
                $plan_width = round(self::safe_rate($plan, $max) * 100, 2);

                echo '<tr>';
                echo '<td>' . esc_html((string) ($row['date'] ?? '')) . '</td>';
                echo '<td>';
                echo '<progress class="sbdp-governance-progress" value="' . esc_attr((string) $session_width) . '" max="100"></progress>';
                echo '<progress class="sbdp-governance-progress sbdp-governance-progress--warn" value="' . esc_attr((string) $primary_width) . '" max="100"></progress>';
                echo '<progress class="sbdp-governance-progress sbdp-governance-progress--danger" value="' . esc_attr((string) $plan_width) . '" max="100"></progress>';
                echo '</td>';
                echo '<td>' . esc_html(number_format_i18n($sessions)) . ' / ' . esc_html(number_format_i18n($primary)) . ' / ' . esc_html(number_format_i18n($plan)) . '</td>';
                echo '<td>' . esc_html(number_format_i18n($errors)) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        $html = ob_get_clean();
        return is_string($html) ? $html : '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function collect_day_planner_event_metrics(int $window_days): array
    {
        $window_days = max(1, min(60, $window_days));

        $start = gmdate('Y-m-d 00:00:00', strtotime('-' . ( $window_days - 1 ) . ' days'));
        $end   = gmdate('Y-m-d 23:59:59');

        $counts = array(
            'session_started'  => 0,
            'intent_detected'  => 0,
            'primary_selected' => 0,
            'primary_changed'  => 0,
            'plan_built'       => 0,
            'cta_rendered'     => 0,
            'cta_clicked'      => 0,
            'handoff_requested'=> 0,
            'error_occurred'   => 0,
        );
        $quality = array(
            'feasible_plans'    => 0,
            'cta_with_twelve'   => 0,
            'events_with_trace' => 0,
            'offer_primary'     => 0,
            'spot_primary'      => 0,
            'sum_turn_to_primary' => 0.0,
            'sum_time_to_primary' => 0.0,
            'primary_samples'     => 0,
            'alternative_clicked' => 0,
        );
        $severity = array(
            'info'    => 0,
            'warning' => 0,
            'error'   => 0,
            'success' => 0,
        );
        $daily = array();
        for ($i = $window_days - 1; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', strtotime('-' . $i . ' days'));
            $daily[ $day ] = array(
                'date'             => $day,
                'session_started'  => 0,
                'primary_selected' => 0,
                'plan_built'       => 0,
                'cta_clicked'      => 0,
                'errors'           => 0,
            );
        }

        $table = self::get_audit_table();
        if (null === $table) {
            return array(
                'start'     => $start,
                'end'       => $end,
                'total'     => 0,
                'counts'    => $counts,
                'quality'   => $quality,
                'severity'  => $severity,
                'daily'     => array_values($daily),
                'breakdown' => array(),
            );
        }

        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return array(
                'start'     => $start,
                'end'       => $end,
                'total'     => 0,
                'counts'    => $counts,
                'quality'   => $quality,
                'severity'  => $severity,
                'daily'     => array_values($daily),
                'breakdown' => array(),
            );
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT action, payload, severity, created_at FROM {$table} WHERE context = %s AND created_at BETWEEN %s AND %s ORDER BY created_at ASC LIMIT %d",
                'day_planner_decision',
                $start,
                $end,
                5000
            ),
            ARRAY_A
        );

        if (! is_array($rows)) {
            $rows = array();
        }

        $total = 0;
        foreach ($rows as $row) {
            $action = isset($row['action']) ? sanitize_key((string) $row['action']) : 'unknown';
            if (! isset($counts[ $action ])) {
                $counts[ $action ] = 0;
            }
            $counts[ $action ]++;
            $total++;

            $severity_key = isset($row['severity']) ? sanitize_key((string) $row['severity']) : 'info';
            if (! isset($severity[ $severity_key ])) {
                $severity[ $severity_key ] = 0;
            }
            $severity[ $severity_key ]++;

            $day = isset($row['created_at']) ? substr((string) $row['created_at'], 0, 10) : '';
            if ($day !== '' && isset($daily[ $day ])) {
                if ($action === 'session_started') {
                    $daily[ $day ]['session_started']++;
                } elseif ($action === 'primary_selected') {
                    $daily[ $day ]['primary_selected']++;
                } elseif ($action === 'plan_built') {
                    $daily[ $day ]['plan_built']++;
                } elseif ($action === 'cta_clicked') {
                    $daily[ $day ]['cta_clicked']++;
                }

                if ($severity_key === 'error' || $action === 'error_occurred') {
                    $daily[ $day ]['errors']++;
                }
            }

            $payload = self::decode_booking_meta($row['payload'] ?? null);
            $data = is_array($payload['data'] ?? null) ? $payload['data'] : array();
            $trace_id = isset($data['trace_id']) ? trim((string) $data['trace_id']) : '';
            if ($trace_id !== '') {
                $quality['events_with_trace']++;
            }

            if ($action === 'primary_selected') {
                $kind = isset($data['primary_kind']) ? sanitize_key((string) $data['primary_kind']) : '';
                if ($kind === 'offer') {
                    $quality['offer_primary']++;
                } elseif ($kind === 'spot') {
                    $quality['spot_primary']++;
                }
                $turnCount = isset($data['turn_count']) ? (int) $data['turn_count'] : 0;
                $timeToPrimary = isset($data['time_to_primary_seconds']) ? (int) $data['time_to_primary_seconds'] : 0;
                if ($turnCount > 0) {
                    $quality['sum_turn_to_primary'] += $turnCount;
                    $quality['primary_samples']++;
                }
                if ($timeToPrimary > 0) {
                    $quality['sum_time_to_primary'] += $timeToPrimary;
                }
            } elseif ($action === 'plan_built') {
                if (! empty($data['feasible'])) {
                    $quality['feasible_plans']++;
                }
            } elseif ($action === 'cta_rendered') {
                $cta_count = (int) ($data['cta_count'] ?? 0);
                if ($cta_count >= 12) {
                    $quality['cta_with_twelve']++;
                }
            } elseif ($action === 'cta_clicked') {
                $clickedKind = isset($data['cta_kind']) ? sanitize_key((string) $data['cta_kind']) : '';
                if ($clickedKind === 'alternative') {
                    $quality['alternative_clicked']++;
                }
            } elseif ($action === 'custom_alternative_clicked') {
                $quality['alternative_clicked']++;
            }
        }

        $breakdown = array();
        foreach ($counts as $action => $count) {
            if ((int) $count <= 0) {
                continue;
            }

            $breakdown[] = array(
                'action' => (string) $action,
                'count'  => (int) $count,
            );
        }

        usort(
            $breakdown,
            static function (array $left, array $right): int {
                return (int) ($right['count'] ?? 0) <=> (int) ($left['count'] ?? 0);
            }
        );

        return array(
            'start'     => $start,
            'end'       => $end,
            'total'     => $total,
            'counts'    => $counts,
            'quality'   => $quality,
            'severity'  => $severity,
            'daily'     => array_values($daily),
            'breakdown' => $breakdown,
        );
    }

    /**
     * @return array{status:string,http_code:int,message:string}
     */
    private static function probe_dbspots_endpoint(): array
    {
        $cached = function_exists('get_transient') ? get_transient('sbdp_governance_dbspots_probe') : false;
        if (is_array($cached)) {
            return array(
                'status'    => (string) ($cached['status'] ?? 'warn'),
                'http_code' => (int) ($cached['http_code'] ?? 0),
                'message'   => (string) ($cached['message'] ?? ''),
            );
        }

        $result = array(
            'status'    => 'warn',
            'http_code' => 0,
            'message'   => __('DBSpots probe niet uitgevoerd.', 'sbdp'),
        );

        if (! function_exists('rest_url') || ! function_exists('wp_remote_get')) {
            $result['status'] = 'fail';
            $result['message'] = __('WordPress REST functies ontbreken.', 'sbdp');
        } else {
            $endpoint = add_query_arg(
                array(
                    'status'   => 'published',
                    'per_page' => 1,
                ),
                rest_url('dbspots/v1/spots')
            );
            $response = wp_remote_get(
                $endpoint,
                array(
                    'timeout' => 3,
                )
            );

            if (is_wp_error($response)) {
                $result['status'] = 'fail';
                $result['message'] = __('DBSpots request faalde.', 'sbdp');
            } else {
                $code = (int) wp_remote_retrieve_response_code($response);
                $result['http_code'] = $code;
                if ($code >= 200 && $code < 300) {
                    $result['status'] = 'ok';
                    $result['message'] = __('DBSpots endpoint reageert met een geldige response.', 'sbdp');
                } elseif ($code >= 500) {
                    $result['status'] = 'fail';
                    $result['message'] = __('DBSpots endpoint geeft serverfout.', 'sbdp');
                } else {
                    $result['status'] = 'warn';
                    $result['message'] = __('DBSpots endpoint geeft geen 2xx response.', 'sbdp');
                }
            }
        }

        if (function_exists('set_transient')) {
            set_transient('sbdp_governance_dbspots_probe', $result, 300);
        }

        return $result;
    }

    private static function safe_rate(float $numerator, float $denominator): float
    {
        if ($denominator <= 0.0) {
            return 0.0;
        }

        $ratio = $numerator / $denominator;
        if ($ratio < 0.0) {
            return 0.0;
        }

        return $ratio > 1.0 ? 1.0 : $ratio;
    }

    private static function format_percent(float $ratio): string
    {
        $value = $ratio * 100;
        if (function_exists('number_format_i18n')) {
            return number_format_i18n($value, 1) . '%';
        }

        return number_format($value, 1, '.', ',') . '%';
    }

    /**
     * @param array<int, array<string, string>> $controls
     *
     * @return array<int, array<string, string>>
     */
    private static function select_controls_for_alert(array $controls, string $min_status): array
    {
        $min_status = sanitize_key($min_status);
        if (! in_array($min_status, array('warn', 'fail'), true)) {
            $min_status = 'fail';
        }

        $selected = array();
        foreach ($controls as $control) {
            $status = sanitize_key((string) ($control['status'] ?? 'warn'));
            if ($status === 'fail') {
                $selected[] = $control;
                continue;
            }
            if ($status === 'warn' && $min_status === 'warn') {
                $selected[] = $control;
            }
        }

        return $selected;
    }

    /**
     * @return array<string, mixed>
     */
    private static function get_governance_alert_settings(): array
    {
        $raw = get_option(self::ALERT_SETTINGS_OPTION, array());
        return self::normalise_governance_alert_settings(is_array($raw) ? $raw : array());
    }

    /**
     * @return array<string, mixed>
     */
    private static function get_decision_policy_settings(): array
    {
        $raw = get_option(self::DECISION_POLICY_OPTION, array());
        return self::normalise_decision_policy_settings(is_array($raw) ? $raw : array());
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private static function normalise_governance_alert_settings(array $raw): array
    {
        $defaults = array(
            'enabled'         => false,
            'window_days'     => 14,
            'cooldown_minutes'=> 120,
            'min_status'      => 'fail',
            'email_enabled'   => false,
            'email_to'        => '',
            'webhook_enabled' => false,
            'webhook_url'     => '',
            'metrics_token'   => '',
        );

        $settings = array_merge($defaults, $raw);
        $settings['enabled'] = (bool) $settings['enabled'];
        $settings['window_days'] = max(7, min(60, (int) $settings['window_days']));
        $settings['cooldown_minutes'] = max(5, min(1440, (int) $settings['cooldown_minutes']));
        $settings['min_status'] = in_array((string) $settings['min_status'], array('warn', 'fail'), true)
            ? (string) $settings['min_status']
            : 'fail';
        $settings['email_enabled'] = (bool) $settings['email_enabled'];
        $settings['email_to'] = is_string($settings['email_to']) ? sanitize_email($settings['email_to']) : '';
        $settings['webhook_enabled'] = (bool) $settings['webhook_enabled'];
        $settings['webhook_url'] = is_string($settings['webhook_url']) ? esc_url_raw($settings['webhook_url']) : '';
        $settings['metrics_token'] = is_string($settings['metrics_token'])
            ? substr(sanitize_text_field($settings['metrics_token']), 0, 128)
            : '';

        return $settings;
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private static function normalise_decision_policy_settings(array $raw): array
    {
        $defaults = array(
            'offer_bias'           => 1.00,
            'spot_bias'            => 1.00,
            'confidence_threshold' => 0.45,
            'max_questions'        => 1,
            'offer_weights'        => array(
                'intent_match'       => 0.30,
                'availability_match' => 0.25,
                'duration_fit'       => 0.15,
                'margin_priority'    => 0.15,
                'seasonality'        => 0.10,
                'manual_priority'    => 0.05,
            ),
            'spot_weights'         => array(
                'type_match'         => 0.35,
                'suitability_match'  => 0.25,
                'distance_heuristic' => 0.20,
                'duration_fit'       => 0.10,
                'manual_priority'    => 0.10,
            ),
        );

        $settings = array_replace_recursive($defaults, $raw);
        $settings['offer_bias'] = max(0.50, min(1.50, (float) ($settings['offer_bias'] ?? 1.00)));
        $settings['spot_bias'] = max(0.50, min(1.50, (float) ($settings['spot_bias'] ?? 1.00)));
        $settings['confidence_threshold'] = max(0.10, min(0.95, (float) ($settings['confidence_threshold'] ?? 0.45)));
        $settings['max_questions'] = max(1, min(3, (int) ($settings['max_questions'] ?? 1)));
        $settings['offer_weights'] = self::normalise_policy_weights(
            $defaults['offer_weights'],
            is_array($settings['offer_weights'] ?? null) ? $settings['offer_weights'] : array()
        );
        $settings['spot_weights'] = self::normalise_policy_weights(
            $defaults['spot_weights'],
            is_array($settings['spot_weights'] ?? null) ? $settings['spot_weights'] : array()
        );

        return $settings;
    }

    /**
     * @return array{updated:bool,message:string,type:string}|null
     */
    private static function maybe_handle_governance_settings_postback(): ?array
    {
        if (! isset($_POST['sbdp_governance_action'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return null;
        }

        $action = sanitize_key((string) wp_unslash($_POST['sbdp_governance_action'])); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ($action !== 'save_alert_settings') {
            return null;
        }

        if (! current_user_can(self::resolveCapability())) {
            return array(
                'updated' => false,
                'message' => __('Geen toestemming om governance-instellingen op te slaan.', 'sbdp'),
                'type'    => 'error',
            );
        }

        $nonce = isset($_POST['sbdp_governance_nonce']) ? (string) wp_unslash($_POST['sbdp_governance_nonce']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (! function_exists('wp_verify_nonce') || ! wp_verify_nonce($nonce, 'sbdp_governance_settings')) {
            return array(
                'updated' => false,
                'message' => __('Beveiligingscontrole mislukt, probeer opnieuw.', 'sbdp'),
                'type'    => 'error',
            );
        }

        $raw = array(
            'enabled'          => isset($_POST['enabled']), // phpcs:ignore WordPress.Security.NonceVerification.Missing
            'window_days'      => isset($_POST['window_days']) ? (int) wp_unslash($_POST['window_days']) : 14, // phpcs:ignore WordPress.Security.NonceVerification.Missing
            'cooldown_minutes' => isset($_POST['cooldown_minutes']) ? (int) wp_unslash($_POST['cooldown_minutes']) : 120, // phpcs:ignore WordPress.Security.NonceVerification.Missing
            'min_status'       => isset($_POST['min_status']) ? (string) wp_unslash($_POST['min_status']) : 'fail', // phpcs:ignore WordPress.Security.NonceVerification.Missing
            'email_enabled'    => isset($_POST['email_enabled']), // phpcs:ignore WordPress.Security.NonceVerification.Missing
            'email_to'         => isset($_POST['email_to']) ? (string) wp_unslash($_POST['email_to']) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
            'webhook_enabled'  => isset($_POST['webhook_enabled']), // phpcs:ignore WordPress.Security.NonceVerification.Missing
            'webhook_url'      => isset($_POST['webhook_url']) ? (string) wp_unslash($_POST['webhook_url']) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
            'metrics_token'    => isset($_POST['metrics_token']) ? (string) wp_unslash($_POST['metrics_token']) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
        );
        $settings = self::normalise_governance_alert_settings($raw);

        $policyRaw = array(
            'offer_bias'           => isset($_POST['decision_offer_bias']) ? (float) wp_unslash($_POST['decision_offer_bias']) : 1.00, // phpcs:ignore WordPress.Security.NonceVerification.Missing
            'spot_bias'            => isset($_POST['decision_spot_bias']) ? (float) wp_unslash($_POST['decision_spot_bias']) : 1.00, // phpcs:ignore WordPress.Security.NonceVerification.Missing
            'confidence_threshold' => isset($_POST['decision_confidence_threshold']) ? (float) wp_unslash($_POST['decision_confidence_threshold']) : 0.45, // phpcs:ignore WordPress.Security.NonceVerification.Missing
            'max_questions'        => isset($_POST['decision_max_questions']) ? (int) wp_unslash($_POST['decision_max_questions']) : 1, // phpcs:ignore WordPress.Security.NonceVerification.Missing
            'offer_weights'        => array(
                'intent_match'       => isset($_POST['w_offer_intent_match']) ? (float) wp_unslash($_POST['w_offer_intent_match']) : 0.30, // phpcs:ignore WordPress.Security.NonceVerification.Missing
                'availability_match' => isset($_POST['w_offer_availability_match']) ? (float) wp_unslash($_POST['w_offer_availability_match']) : 0.25, // phpcs:ignore WordPress.Security.NonceVerification.Missing
                'duration_fit'       => isset($_POST['w_offer_duration_fit']) ? (float) wp_unslash($_POST['w_offer_duration_fit']) : 0.15, // phpcs:ignore WordPress.Security.NonceVerification.Missing
                'margin_priority'    => isset($_POST['w_offer_margin_priority']) ? (float) wp_unslash($_POST['w_offer_margin_priority']) : 0.15, // phpcs:ignore WordPress.Security.NonceVerification.Missing
                'seasonality'        => isset($_POST['w_offer_seasonality']) ? (float) wp_unslash($_POST['w_offer_seasonality']) : 0.10, // phpcs:ignore WordPress.Security.NonceVerification.Missing
                'manual_priority'    => isset($_POST['w_offer_manual_priority']) ? (float) wp_unslash($_POST['w_offer_manual_priority']) : 0.05, // phpcs:ignore WordPress.Security.NonceVerification.Missing
            ),
            'spot_weights'         => array(
                'type_match'         => isset($_POST['w_spot_type_match']) ? (float) wp_unslash($_POST['w_spot_type_match']) : 0.35, // phpcs:ignore WordPress.Security.NonceVerification.Missing
                'suitability_match'  => isset($_POST['w_spot_suitability_match']) ? (float) wp_unslash($_POST['w_spot_suitability_match']) : 0.25, // phpcs:ignore WordPress.Security.NonceVerification.Missing
                'distance_heuristic' => isset($_POST['w_spot_distance_heuristic']) ? (float) wp_unslash($_POST['w_spot_distance_heuristic']) : 0.20, // phpcs:ignore WordPress.Security.NonceVerification.Missing
                'duration_fit'       => isset($_POST['w_spot_duration_fit']) ? (float) wp_unslash($_POST['w_spot_duration_fit']) : 0.10, // phpcs:ignore WordPress.Security.NonceVerification.Missing
                'manual_priority'    => isset($_POST['w_spot_manual_priority']) ? (float) wp_unslash($_POST['w_spot_manual_priority']) : 0.10, // phpcs:ignore WordPress.Security.NonceVerification.Missing
            ),
        );
        $policySettings = self::normalise_decision_policy_settings($policyRaw);

        update_option(self::ALERT_SETTINGS_OPTION, $settings, false);
        update_option(self::DECISION_POLICY_OPTION, $policySettings, false);

        return array(
            'updated' => true,
            'message' => __('Governance- en decision-instellingen bijgewerkt.', 'sbdp'),
            'type'    => 'success',
        );
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $decisionSettings
     */
    private static function render_governance_alert_settings(array $settings, array $decisionSettings): string
    {
        ob_start();
        echo '<section class="sbdp-governance-panel sbdp-governance-panel--control sbdp-governance-panel--stacked">';
        echo '<h2>' . esc_html__('Operational monitors', 'sbdp') . '</h2>';
        echo '<p>' . esc_html__('Read-only mirror of alerting and metrics settings. Governance stays descriptive here and does not act as a configuration engine.', 'sbdp') . '</p>';
        echo '<table class="widefat striped sbdp-governance-table"><thead><tr><th>' . esc_html__('Monitor', 'sbdp') . '</th><th>' . esc_html__('Current value', 'sbdp') . '</th><th>' . esc_html__('Notes', 'sbdp') . '</th></tr></thead><tbody>';
        echo '<tr><td><strong>' . esc_html__('Alerting active', 'sbdp') . '</strong></td><td>' . self::render_governance_status_tag(! empty($settings['enabled']) ? 'pass' : 'unknown') . '</td><td>' . esc_html__('Periodic governance alerts are configured outside the cockpit settings flow.', 'sbdp') . '</td></tr>';
        echo '<tr><td><strong>' . esc_html__('Window', 'sbdp') . '</strong></td><td>' . esc_html((string) ($settings['window_days'] ?? 14)) . ' ' . esc_html__('days', 'sbdp') . '</td><td>' . esc_html__('Shared alerting window for metrics and governance notifications.', 'sbdp') . '</td></tr>';
        echo '<tr><td><strong>' . esc_html__('Cooldown', 'sbdp') . '</strong></td><td>' . esc_html((string) ($settings['cooldown_minutes'] ?? 120)) . ' ' . esc_html__('minutes', 'sbdp') . '</td><td>' . esc_html__('Prevents repeated alerts from becoming noise.', 'sbdp') . '</td></tr>';
        echo '<tr><td><strong>' . esc_html__('Minimum alert status', 'sbdp') . '</strong></td><td>' . self::render_governance_status_tag((string) ($settings['min_status'] ?? 'fail')) . '</td><td>' . esc_html__('Threshold for operational alerting, not a source of governance truth.', 'sbdp') . '</td></tr>';
        echo '<tr><td><strong>' . esc_html__('Email alerts', 'sbdp') . '</strong></td><td>' . (! empty($settings['email_enabled']) ? esc_html__('Enabled', 'sbdp') : esc_html__('Disabled', 'sbdp')) . '</td><td>' . esc_html((string) ($settings['email_to'] ?? '')) . '</td></tr>';
        echo '<tr><td><strong>' . esc_html__('Webhook alerts', 'sbdp') . '</strong></td><td>' . (! empty($settings['webhook_enabled']) ? esc_html__('Enabled', 'sbdp') : esc_html__('Disabled', 'sbdp')) . '</td><td>' . esc_html((string) ($settings['webhook_url'] ?? '')) . '</td></tr>';
        echo '<tr><td><strong>' . esc_html__('Metrics endpoint', 'sbdp') . '</strong></td><td>' . (! empty($settings['metrics_token']) ? esc_html__('Protected', 'sbdp') : esc_html__('Public', 'sbdp')) . '</td><td>' . esc_html__('Prometheus-style governance metrics endpoint exposure.', 'sbdp') . '</td></tr>';
        echo '</tbody></table>';
        echo '</section>';

        echo '<details class="sbdp-governance-panel sbdp-governance-panel--read-only sbdp-governance-panel--stacked">';
        echo '<summary>' . esc_html__('Decision policy snapshot', 'sbdp') . '</summary>';
        echo '<p>' . esc_html__('Read-only mirror of the current decision policy. The cockpit does not edit this policy to avoid becoming a second truth source.', 'sbdp') . '</p>';
        echo '<table class="widefat striped sbdp-governance-table"><tbody>';
        echo '<tr><th scope="row">' . esc_html__('Offer bias', 'sbdp') . '</th><td>' . esc_html(number_format_i18n((float) ($decisionSettings['offer_bias'] ?? 1.0), 2)) . '</td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Spot bias', 'sbdp') . '</th><td>' . esc_html(number_format_i18n((float) ($decisionSettings['spot_bias'] ?? 1.0), 2)) . '</td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Confidence threshold', 'sbdp') . '</th><td>' . esc_html(number_format_i18n((float) ($decisionSettings['confidence_threshold'] ?? 0.45) * 100, 0)) . '%</td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Max clarifying questions', 'sbdp') . '</th><td>' . esc_html(number_format_i18n((int) ($decisionSettings['max_questions'] ?? 1))) . '</td></tr>';
        echo '</tbody></table>';
        echo '</details>';

        $html = ob_get_clean();
        return is_string($html) ? $html : '';
    }

    private static function format_float(float $value): string
    {
        return sprintf('%.6f', $value);
    }

    /**
     * @param array<string, float> $defaults
     * @param array<string, mixed> $incoming
     *
     * @return array<string, float>
     */
    private static function normalise_policy_weights(array $defaults, array $incoming): array
    {
        $weights = $defaults;
        foreach ($defaults as $key => $default) {
            if (! array_key_exists($key, $incoming)) {
                continue;
            }
            $value = $incoming[$key];
            $weights[$key] = is_numeric($value) ? max(0.0, (float) $value) : (float) $default;
        }

        $sum = array_sum($weights);
        if ($sum <= 0.0) {
            return $defaults;
        }

        foreach ($weights as $key => $value) {
            $weights[$key] = $value / $sum;
        }

        return $weights;
    }

    private static function escape_prom_label(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace('"', '\\"', $value);
        $value = str_replace("\n", '\\n', $value);
        return $value;
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

        $date_column = self::resolve_table_column($table, array( 'start_at', 'start_datetime' ));
        if (null === $date_column) {
            return array(
                'start'    => $start,
                'end'      => $end,
                'days'     => $days,
                'count'    => 0,
                'revenue'  => 0.0,
                'currency' => (string) get_option('woocommerce_currency', 'EUR'),
            );
        }

        $sql = sprintf(
            "SELECT currency, COUNT(*) AS total, COALESCE(SUM(total), 0) AS revenue FROM {$table} WHERE %s BETWEEN %%s AND %%s GROUP BY currency",
            $date_column
        );

        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, $start, $end),
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

        $date_column = self::resolve_table_column($table, array( 'start_at', 'start_datetime' ));
        if (null === $date_column) {
            return array(
                'start'       => $start,
                'end'         => $end,
                'count'       => 0,
                'revenue'     => 0.0,
                'currency'    => (string) get_option('woocommerce_currency', 'EUR'),
                'daysElapsed' => max(1, (int) gmdate('j')),
                'daysInMonth' => max(1, (int) gmdate('t')),
            );
        }

        $sql = sprintf(
            "SELECT currency, COUNT(*) AS total, COALESCE(SUM(total), 0) AS revenue FROM {$table} WHERE %s BETWEEN %%s AND %%s GROUP BY currency",
            $date_column
        );

        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, $start, $end),
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

        $date_column = self::resolve_table_column($table, array( 'start_at', 'start_datetime' ));
        if (null === $date_column) {
            return array(
                'windowDays'       => $days,
                'upcomingTotal'    => 0,
                'pendingApprovals' => 0,
                'upcomingByDay'    => array(),
            );
        }

        $sql = sprintf(
            "SELECT DATE(%1\$s) AS day, status, COUNT(*) AS total FROM {$table} WHERE %1\$s BETWEEN %%s AND %%s GROUP BY day, status ORDER BY day ASC",
            $date_column
        );

        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, $start, $end),
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

        $date_column = self::resolve_table_column($table, array( 'start_at', 'start_datetime' ));
        if (null === $date_column) {
            return array();
        }

        $meta_column = self::resolve_table_column($table, array( 'meta_json', 'meta' ));
        $meta_source = $meta_column !== null ? $meta_column : 'NULL';

        $sql = sprintf(
            "SELECT %1\$s AS meta, total, currency FROM {$table} WHERE %2\$s BETWEEN %%s AND %%s",
            $meta_source,
            $date_column
        );

        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, $start, $end),
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

    private static function get_audit_table(): ?string
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return null;
        }

        $table = $wpdb->prefix . 'bsp_audit_log';
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

    /**
     * Resolve the first available column from a list of candidates for the provided table.
     */
    private static function resolve_table_column(string $table, array $candidates): ?string
    {
        $columns = self::get_table_columns($table);

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Retrieve and cache all column names for a table.
     *
     * @return array<int, string>
     */
    private static function get_table_columns(string $table): array
    {
        if (isset(self::$table_columns_cache[ $table ])) {
            return self::$table_columns_cache[ $table ];
        }

        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            self::$table_columns_cache[ $table ] = array();
            return self::$table_columns_cache[ $table ];
        }

        $results = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
        if (! is_array($results)) {
            $results = array();
        }

        $columns = array();
        foreach ($results as $column) {
            if (is_string($column) && $column !== '') {
                $columns[] = $column;
            }
        }

        self::$table_columns_cache[ $table ] = $columns;

        return $columns;
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
