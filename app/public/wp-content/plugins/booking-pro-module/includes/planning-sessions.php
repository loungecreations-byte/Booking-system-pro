<?php

declare(strict_types=1);

namespace SBDP\PlanningSessions;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (! defined('ABSPATH')) {
    exit;
}

final class Controller
{
    private static bool $assetsEnqueued = false;
    private const SESSION_TTL = 86400;
    private const MAX_PAYLOAD_BYTES = 16384;
    private const CREATE_RATE_LIMIT = 30;
    private const UPDATE_RATE_LIMIT = 120;
    private const EVENT_RATE_LIMIT = 60;
    private const RATE_LIMIT_WINDOW = 60;
    public static function init(): void
    {
        \add_action('rest_api_init', [self::class, 'register_routes']);
        \add_action('init', [self::class, 'register_shortcode'], 20);
        \add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets']);
        \add_filter('the_content', [self::class, 'normalize_home_widget_publication_content'], 8);
        \add_filter('wp_insert_post_data', [self::class, 'normalize_home_widget_post_data'], 20, 2);
        // Fallback: register immediately in case init hook is missed in certain contexts.
        if (! shortcode_exists('ddb_home_widget')) {
            \add_shortcode('ddb_home_widget', [self::class, 'render_home_widget']);
        }
    }

    public static function register_routes(): void
    {
        \register_rest_route('ddb/v1', '/session', [
                'methods'             => 'POST',
                'callback'            => [self::class, 'create_session'],
                'permission_callback' => [self::class, 'can_manage_sessions'],
            ]);
        \register_rest_route('ddb/v1', '/session/(?P<key>[A-Za-z0-9\\-]+)', [
                'methods'             => ['GET', 'PUT', 'PATCH'],
                'callback'            => [self::class, 'upsert_session'],
                'permission_callback' => [self::class, 'can_manage_sessions'],
                'args'                => [
                    'key' => [
                        'required' => true,
                    ],
                ],
            ]);
        \register_rest_route('ddb/v1', '/event', [
                'methods'             => 'POST',
                'callback'            => [self::class, 'log_event'],
                'permission_callback' => [self::class, 'can_manage_sessions'],
            ]);
    }

    public static function can_manage_sessions(): bool|WP_Error
    {
        if (\function_exists('current_user_can') && \current_user_can('edit_posts')) {
            return true;
        }

        return new WP_Error('ddb_session_forbidden', 'Je hebt geen toegang tot planningssessies.', ['status' => 403]);
    }
    public static function register_shortcode(): void
    {
        \add_shortcode('ddb_home_widget', [self::class, 'render_home_widget']);
    }

    /**
     * Replace any stored home widget snapshot markup with the canonical shortcode.
     * This keeps live CTA shape code-owned instead of content-owned.
     */
    public static function normalize_home_widget_publication_content($content)
    {
        if (! is_string($content) || $content === '') {
            return $content;
        }

        if (! self::content_contains_home_widget_publication($content)) {
            return $content;
        }

        return self::replace_home_widget_snapshot_markup($content);
    }

    /**
     * Normalize snapshots before persistence so post_content cannot become live widget truth.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $postarr
     * @return array<string, mixed>
     */
    public static function normalize_home_widget_post_data(array $data, array $postarr): array
    {
        unset($postarr);

        if (! isset($data['post_content']) || ! is_string($data['post_content'])) {
            return $data;
        }

        $data['post_content'] = self::normalize_home_widget_publication_content($data['post_content']);

        return $data;
    }

    public static function content_contains_home_widget_publication(string $content): bool
    {
        return strpos($content, '[ddb_home_widget') !== false
            || strpos($content, 'data-ui-planner-widget') !== false;
    }

    private static function replace_home_widget_snapshot_markup(string $content): string
    {
        if (strpos($content, 'data-ui-planner-widget') === false) {
            return $content;
        }

        $normalized = '';
        $offset = 0;
        $contentLength = strlen($content);

        while (($markerOffset = strpos($content, 'data-ui-planner-widget', $offset)) !== false) {
            $prefix = substr($content, 0, $markerOffset);
            $sectionStart = strripos($prefix, '<section');
            if ($sectionStart === false) {
                break;
            }

            $sectionEnd = self::find_home_widget_section_end($content, $sectionStart);
            if ($sectionEnd === null) {
                break;
            }

            $snapshot = substr($content, $sectionStart, $sectionEnd - $sectionStart);
            $normalized .= substr($content, $offset, $sectionStart - $offset);
            $normalized .= self::build_home_widget_shortcode_from_snapshot($snapshot);
            $offset = $sectionEnd;
        }

        if ($offset === 0) {
            return $content;
        }

        if ($offset < $contentLength) {
            $normalized .= substr($content, $offset);
        }

        return $normalized;
    }

    private static function find_home_widget_section_end(string $content, int $sectionStart): ?int
    {
        if ($sectionStart < 0 || substr($content, $sectionStart, 8) !== '<section') {
            return null;
        }

        $depth = 0;
        $cursor = $sectionStart;
        while (preg_match('/<\/?section\b[^>]*>/i', $content, $matches, PREG_OFFSET_CAPTURE, $cursor) === 1) {
            $tag = $matches[0][0];
            $tagOffset = (int) $matches[0][1];
            $cursor = $tagOffset + strlen($tag);

            if (stripos($tag, '</section') === 0) {
                $depth--;
                if ($depth === 0) {
                    return $cursor;
                }
                continue;
            }

            $depth++;
        }

        return null;
    }

    private static function build_home_widget_shortcode_from_snapshot(string $snapshot): string
    {
        $style = stripos($snapshot, 'ui-planner-widget--light') !== false ? 'light' : 'dark';
        $count = 6;

        if (
            preg_match('/<input\b[^>]*data-ui-count[^>]*value="(\d+)"/i', $snapshot, $matches) === 1
            || preg_match('/<input\b[^>]*value="(\d+)"[^>]*data-ui-count/i', $snapshot, $matches) === 1
        ) {
            $count = max(1, (int) $matches[1]);
        }

        return sprintf('[ddb_home_widget style="%s" count="%d"]', $style, $count);
    }

    /**
     * Enqueue CSS/JS once.
     */
    public static function enqueue_assets(): void
    {
        if (!self::should_enqueue_assets()) {
            return;
        }

        if (self::$assetsEnqueued) {
            return;
        }
        self::$assetsEnqueued = true;

        $style_handle = 'ui-planner-widget-style';
        $script_handle = 'ui-planner-widget-script';
        wp_enqueue_script('sbdp-shared-helpers', SBDP_URL . 'assets/js/shared-helpers.js', array(), SBDP_VER, true);


        // Shared UI tokens to align widget/productplanner/planner chips/buttons/counts.
        $shared_handle = 'sbdp-shared-ui';
        if (! \wp_style_is($shared_handle, 'registered')) {
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
            \wp_register_style($shared_handle, false, array(), '1.0');
            \wp_add_inline_style($shared_handle, $sharedCss);
        }
        \wp_enqueue_style($shared_handle);

        $css = <<<'CSS'
.ui-planner-widget {
    --sbdp-color-primary: var(--ui-color-primary);
    --sbdp-color-primary-soft: color-mix(in srgb, var(--ui-color-primary) 16%, var(--ui-color-surface));
    --sbdp-color-text: var(--ui-color-text);
    --sbdp-color-text-soft: var(--ui-color-text-muted);
    --sbdp-color-border: var(--ui-color-border);
    --bg: linear-gradient(140deg, var(--ui-color-bg) 0%, color-mix(in srgb, var(--ui-color-surface) 92%, var(--ui-color-primary) 8%) 45%, var(--ui-color-bg) 100%);
    --fg: var(--ui-color-text);
    --muted: var(--ui-color-text-muted);
    --card: var(--ui-color-surface);
    --border: var(--ui-color-border);
    --accent: linear-gradient(120deg, var(--ui-color-primary) 0%, var(--ui-color-primary-hover) 100%);
    --accent-strong: var(--ui-color-primary);
    --ghost: color-mix(in srgb, var(--ui-color-primary) 6%, transparent);
    --pill: color-mix(in srgb, var(--ui-color-primary) 12%, transparent);
    --shadow: var(--ui-shadow-md);
    --chip-bg: var(--ui-color-surface-2);
    --chip-border: var(--ui-color-border);
    --chip-hover: var(--ui-color-surface);
    --chip-hover-border: var(--ui-color-primary);
    --chip-active-bg: var(--ui-color-primary);
    --chip-active-border: var(--ui-color-primary);
    --chip-active-fg: var(--ui-color-primary-contrast);
    --modal-overlay: color-mix(in srgb, var(--ui-color-bg) 88%, transparent);
    --modal-card-bg: var(--ui-color-surface);
    --modal-card-border: var(--ui-color-border);
    --modal-card-shadow: var(--ui-shadow-lg);
    background: var(--bg);
    color: var(--fg);
    border-radius: 18px;
    padding: 16px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
    position: relative;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.ui-planner-widget--light {
    --sbdp-color-primary: var(--ui-color-primary);
    --sbdp-color-primary-soft: color-mix(in srgb, var(--ui-color-primary) 14%, var(--ui-color-surface));
    --sbdp-color-text: var(--ui-color-text);
    --sbdp-color-text-soft: var(--ui-color-text-muted);
    --sbdp-color-border: var(--ui-color-border);
    --bg: linear-gradient(135deg, var(--ui-color-bg) 0%, color-mix(in srgb, var(--ui-color-surface) 94%, var(--ui-color-primary) 6%) 50%, var(--ui-color-surface-2) 100%);
    --fg: var(--ui-color-text);
    --muted: var(--ui-color-text-muted);
    --card: var(--ui-color-surface);
    --border: var(--ui-color-border);
    --ghost: color-mix(in srgb, var(--ui-color-primary) 4%, transparent);
    --pill: color-mix(in srgb, var(--ui-color-primary) 8%, transparent);
    --shadow: var(--ui-shadow-sm);
    --chip-bg: var(--ui-color-surface);
    --chip-border: var(--ui-color-border);
    --chip-hover: var(--ui-color-surface);
    --chip-hover-border: var(--ui-color-primary);
    --chip-active-bg: var(--sbdp-color-primary-soft);
    --chip-active-border: var(--ui-color-primary);
    --chip-active-fg: var(--ui-color-text);
    --modal-overlay: color-mix(in srgb, var(--ui-color-bg) 70%, transparent);
    --modal-card-bg: var(--ui-color-surface);
    --modal-card-border: var(--ui-color-border);
    --modal-card-shadow: var(--ui-shadow-md);
}
/* Respect global dark/light toggle (sbdp-dark-toggle) */
body.sbdp-theme-light .ui-planner-widget {
    --sbdp-color-primary: var(--ui-color-primary);
    --sbdp-color-primary-soft: color-mix(in srgb, var(--ui-color-primary) 14%, var(--ui-color-surface));
    --sbdp-color-text: var(--ui-color-text);
    --sbdp-color-text-soft: var(--ui-color-text-muted);
    --sbdp-color-border: var(--ui-color-border);
    --bg: linear-gradient(135deg, var(--ui-color-bg) 0%, color-mix(in srgb, var(--ui-color-surface) 94%, var(--ui-color-primary) 6%) 50%, var(--ui-color-surface-2) 100%);
    --fg: var(--ui-color-text);
    --muted: var(--ui-color-text-muted);
    --card: var(--ui-color-surface);
    --border: var(--ui-color-border);
    --ghost: color-mix(in srgb, var(--ui-color-primary) 4%, transparent);
    --pill: color-mix(in srgb, var(--ui-color-primary) 8%, transparent);
    --shadow: var(--ui-shadow-sm);
    --chip-bg: var(--ui-color-surface);
    --chip-border: var(--ui-color-border);
    --chip-hover: var(--ui-color-surface);
    --chip-hover-border: var(--ui-color-primary);
    --chip-active-bg: var(--sbdp-color-primary-soft);
    --chip-active-border: var(--ui-color-primary);
    --chip-active-fg: var(--ui-color-text);
    --modal-overlay: color-mix(in srgb, var(--ui-color-bg) 70%, transparent);
    --modal-card-bg: var(--ui-color-surface);
    --modal-card-border: var(--ui-color-border);
    --modal-card-shadow: var(--ui-shadow-md);
}
body.sbdp-theme-dark .ui-planner-widget {
    --sbdp-color-primary: var(--ui-color-primary);
    --sbdp-color-primary-soft: color-mix(in srgb, var(--ui-color-primary) 16%, var(--ui-color-surface));
    --sbdp-color-text: var(--ui-color-text);
    --sbdp-color-text-soft: var(--ui-color-text-muted);
    --sbdp-color-border: var(--ui-color-border);
    --bg: linear-gradient(140deg, var(--ui-color-bg) 0%, color-mix(in srgb, var(--ui-color-surface) 92%, var(--ui-color-primary) 8%) 45%, var(--ui-color-bg) 100%);
    --fg: var(--ui-color-text);
    --muted: var(--ui-color-text-muted);
    --card: var(--ui-color-surface);
    --border: var(--ui-color-border);
    --accent: linear-gradient(120deg, var(--ui-color-primary) 0%, var(--ui-color-primary-hover) 100%);
    --accent-strong: var(--ui-color-primary);
    --ghost: color-mix(in srgb, var(--ui-color-primary) 6%, transparent);
    --pill: color-mix(in srgb, var(--ui-color-primary) 12%, transparent);
    --shadow: var(--ui-shadow-lg);
    --chip-bg: var(--ui-color-surface-2);
    --chip-border: var(--ui-color-border);
    --chip-hover: var(--ui-color-surface);
    --chip-hover-border: var(--ui-color-primary);
    --chip-active-bg: var(--ui-color-primary);
    --chip-active-border: var(--ui-color-primary);
    --chip-active-fg: var(--ui-color-primary-contrast);
    --modal-overlay: color-mix(in srgb, var(--ui-color-bg) 88%, transparent);
    --modal-card-bg: var(--ui-color-surface);
    --modal-card-border: var(--ui-color-border);
    --modal-card-shadow: var(--ui-shadow-lg);
}
.ui-planner-widget__summary {
    display: flex;
    gap: 16px;
    justify-content: space-between;
    flex-wrap: wrap;
    align-items: flex-start;
}
.ui-planner-widget__summary-meta {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 200px;
}
.ui-planner-widget__summary-row {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}
.ui-planner-widget__pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 10px;
    background: var(--pill);
    border-radius: 999px;
    font-size: 13px;
    color: var(--fg);
}
.ui-planner-widget__link {
    background: none;
    border: none;
    color: var(--accent-strong);
    font-weight: 600;
    cursor: pointer;
    text-decoration: underline;
}
.ui-planner-widget__title {
    margin: 0;
    font-size: 22px;
    line-height: 1.25;
}
.ui-planner-widget__micro {
    margin: 0;
    color: var(--muted);
    font-size: 14px;
}
.ui-planner-widget__steps {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 8px;
}
.ui-planner-widget__step {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 10px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    opacity: 1;
    transform: translateY(0);
    transition: opacity 200ms ease, transform 200ms ease;
}
.ui-planner-widget__step.is-visible {
    opacity: 1;
    transform: translateY(0);
}
.ui-planner-widget__label {
    margin: 0;
    font-weight: 700;
    font-size: 13px;
}
.ui-planner-widget__chipset {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.ui-planner-widget .ui-chip {
    border: 1px solid var(--chip-border) ;
    background: var(--chip-bg) ;
    color: var(--fg) ;
    border-radius: 999px;
    padding: 7px 10px;
    margin: 0;
    font-size: 13px;
    cursor: pointer;
    transition: all 140ms ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    min-height: 32px;
    box-shadow: none ;
    background-clip: padding-box ;
}
.ui-planner-widget .ui-chip:hover {
    background: var(--chip-hover) ;
    border-color: var(--chip-hover-border) ;
    color: var(--fg) ;
}
.ui-planner-widget .ui-chip:focus-visible {
    outline: 2px solid var(--accent-strong) ;
    outline-offset: 2px ;
}
.ui-planner-widget .ui-chip.is-active {
    background: var(--chip-active-bg) ;
    border-color: var(--chip-active-border) ;
    color: var(--chip-active-fg) ;
    box-shadow: 0 4px 10px color-mix(in srgb, var(--ui-color-primary) 25%, transparent) ;
    background-clip: padding-box ;
}
.ui-chip.is-ghost {
    background: var(--ghost);
    color: var(--muted);
}
.ui-planner-widget__inline {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.ui-planner-widget__count-control {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.ui-planner-widget__count-btn {
    width: 34px;
    height: 34px;
    border-radius: 999px ;
    border: 1px solid var(--ui-color-primary) ;
    background: var(--ui-color-primary) ;
    color: var(--ui-color-primary-contrast) ;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    font-weight: 700;
    line-height: 1;
    text-align: center;
    cursor: pointer;
    transition: all 140ms ease;
    box-shadow: 0 8px 18px color-mix(in srgb, var(--ui-color-primary) 18%, transparent);
}
.ui-planner-widget__count-btn:hover {
    border-color: var(--ui-color-primary-hover) ;
    background: var(--ui-color-primary-hover) ;
    color: var(--ui-color-primary-contrast) ;
}
.ui-planner-widget__count-btn:focus-visible {
    outline: 2px solid var(--accent-strong);
    outline-offset: 2px;
}
.ui-planner-widget__inline input {
    max-width: 80px;
    background: var(--card);
    border: 1px solid var(--border);
    color: var(--fg);
    border-radius: 10px;
    padding: 8px 10px;
    font-size: 14px;
}
.ui-planner-widget__field {
    display: flex;
    flex-direction: column;
    gap: 6px;
    font-size: 13px;
    color: var(--muted);
}
.ui-planner-widget__field input {
    background: var(--card);
    border: 1px solid var(--border);
    color: var(--fg);
    border-radius: 10px;
    padding: 8px 10px;
    font-size: 14px;
    outline: none;
}
.ui-planner-widget__footer {
    display: flex;
    flex-direction: row;
    justify-content: space-between;
    gap: 8px;
    align-items: center;
}
.ui-planner-widget__footer .ui-planner-widget__btn {
    flex: 0 0 auto;
    width: auto;
    max-width: none;
}
.ui-planner-widget__btn-icon {
    display: inline-flex;
    width: 16px;
    height: 16px;
}
.ui-planner-widget__btn-icon svg {
    width: 16px;
    height: 16px;
    fill: currentColor;
}
@media (max-width: 640px) {
    .ui-planner-widget__footer {
        flex-direction: column;
        align-items: stretch;
    }
    .ui-planner-widget__footer .ui-planner-widget__btn {
        width: 100%;
    }
}
.ui-planner-widget .ui-planner-widget__btn {
    border: 1px solid var(--chip-hover-border) ;
    cursor: pointer;
    border-radius: 12px;
    padding: 10px 13px;
    font-size: 14px;
    font-weight: 700;
    transition: all 140ms ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    text-decoration: none;
    background: var(--chip-active-bg) ;
    color: var(--chip-active-fg) ;
    box-shadow: 0 10px 26px color-mix(in srgb, var(--ui-color-primary) 32%, transparent) ;
}
.ui-planner-widget .ui-planner-widget__btn:hover {
    background: var(--chip-hover-border) ;
    border-color: var(--chip-hover-border) ;
    color: var(--chip-active-fg) ;
}
.ui-planner-widget .ui-planner-widget__btn:focus-visible {
    outline: 2px solid var(--accent-strong) ;
    outline-offset: 2px ;
}
.ui-planner-widget .ui-planner-widget__btn:active {
    background: var(--chip-active-bg) ;
    border-color: var(--chip-active-border) ;
    color: var(--chip-active-fg) ;
    box-shadow: 0 6px 16px color-mix(in srgb, var(--ui-color-primary) 30%, transparent) ;
}
.ui-planner-widget .ui-planner-widget__btn[disabled] {
    opacity: 0.55;
    cursor: not-allowed;
    box-shadow: none ;
}
.ui-planner-widget .ui-planner-widget__btn--ghost {
    background: transparent ;
    color: var(--sbdp-color-primary) ;
    border-color: var(--sbdp-color-primary) ;
    box-shadow: none ;
}
.ui-planner-widget .ui-planner-widget__btn--ghost:hover {
    background: color-mix(in srgb, var(--ui-color-primary) 10%, transparent) ;
    color: var(--sbdp-color-primary) ;
    border-color: var(--sbdp-color-primary) ;
}
.ui-planner-widget__inline-hint {
    font-size: 13px;
    color: #fca5a5;
    margin: 0;
    min-height: 16px;
}
.ui-planner-widget__loader {
    width: 14px;
    height: 14px;
    border-radius: 50%;
    border: 2px solid rgba(255, 255, 255, 0.5);
    border-top-color: #fff;
    animation: ddb-spin 1s linear infinite;
}
.ui-planner-widget__modal {
    position: fixed;
    inset: 0;
    background: var(--modal-overlay);
    backdrop-filter: blur(8px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 16px;
}
.ui-planner-widget__modal-card {
    width: min(720px, calc(100% - 12px));
    background: var(--modal-card-bg);
    border: 1px solid var(--modal-card-border);
    border-radius: 18px;
    padding: 16px;
    box-shadow: var(--modal-card-shadow);
    display: flex;
    flex-direction: column;
    gap: 10px;
    position: relative;
    max-height: 90vh;
    overflow-y: auto;
    color: var(--fg);
}
.ui-planner-widget__badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 5px 9px;
    background: var(--pill);
    border-radius: 10px;
    font-weight: 700;
    font-size: 13px;
    color: var(--fg);
}
.ui-planner-widget__close {
    position: absolute;
    top: 10px;
    right: 10px;
    background: var(--ghost);
    border: 1px solid var(--border);
    color: var(--fg);
    border-radius: 10px;
    width: 32px;
    height: 32px;
    cursor: pointer;
    font-size: 18px;
    line-height: 1;
}
.ui-planner-widget__question {
    background: var(--ghost);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 10px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.ui-planner-widget__question-label {
    margin: 0;
    font-weight: 700;
    font-size: 14px;
}
.ui-planner-widget__hint {
    margin: 0;
    color: var(--muted);
    font-size: 13px;
}
.ui-planner-widget__modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    flex-wrap: wrap;
}
.ui-planner-widget__modal-actions .ui-planner-widget__btn {
    min-width: 180px;
    justify-content: center;
}
.ui-planner-widget__discovery {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding-top: 8px;
    border-top: 1px solid var(--border);
}
.ui-planner-widget__discovery-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.ui-planner-widget__discovery-title {
    margin: 0;
    font-size: 15px;
    font-weight: 700;
}
.ui-planner-widget__discovery-empty {
    margin: 0;
    color: var(--muted);
    font-size: 13px;
}
.ui-planner-widget__results {
    display: grid;
    gap: 8px;
}
.ui-planner-widget__result {
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 12px;
    background: var(--card);
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.ui-planner-widget__result-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    color: var(--muted);
    font-size: 13px;
}
.ui-planner-widget__result-title {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
}
.ui-planner-widget__result-copy {
    margin: 0;
    color: var(--muted);
    font-size: 14px;
}
.ui-planner-widget__result-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.ui-planner-widget__discovery-footer {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}
.ui-planner-widget [hidden] {
    display: none ;
}
@keyframes ddb-spin {
    to {
        transform: rotate(360deg);
    }
}
@media (max-width: 720px) {
    .ui-planner-widget {
        padding: 14px;
    }
    .ui-planner-widget__title {
        font-size: 20px;
    }
    .ui-planner-widget__steps {
        grid-template-columns: 1fr;
    }
    .ui-planner-widget__summary {
        flex-direction: column;
    }
    .ui-planner-widget__modal-card {
        width: 100%;
    }
}
CSS;

        \wp_register_style($style_handle, false, array(), '1.0');
        \wp_add_inline_style($style_handle, $css);
        \wp_enqueue_style($style_handle);

        $js = <<<'JS'
// Compact home widget flow (3 stappen, 30s)
(function bootstrapDdbHomeWidget() {
    const MAX_PREFS = 2;
    const HELP = window.SBDP_SHARED_HELPERS || {};
    const MAX_FUTURE_DAYS = HELP.MAX_FUTURE_DAYS || 365;
    const DEFAULTS = {
        start: 'vrij',
        duration: 'hele-dag',
        group: 'vrienden',
        preferences: [],
        verras: false,
    };

    const normalizeDate = HELP.normalizeDate || function (raw) { return ''; };
    const clampCount = HELP.clampCount || function (v) { const n = parseInt(v, 10); return Number.isFinite(n) && n > 0 ? Math.min(100, n) : 0; };
    const canonicalDuration = function (state) {
        if (HELP.canonicalDuration) return HELP.canonicalDuration(state.duration);
        const base = (state.duration || '').toLowerCase();
        if (base === 'hele-dag' || base === 'weekend') return base;
        if (base === 'avond') return 'avond';
        if (base === 'ochtend' || base === '3-4u' || base === '34u') return 'ochtend';
        if (base === 'middag' || base === '6u' || base === '5-6u' || base === '56u') return 'middag';
        return 'hele-dag';
    };
    const deriveAudience = function (state) {
        if (HELP.deriveAudience) return HELP.deriveAudience(state.group);
        const map = {
            vrienden: 'vrienden',
            familie: 'gezin',
            gemengd: 'vrienden',
            collegas: 'collegas',
            "collega's": 'collegas',
            bedrijf: 'collegas',
            school: 'collegas',
            partner: 'partner',
            romantisch: 'partner',
            solo: 'solo',
        };
        return map[state.group] || 'vrienden';
    };
    const deriveVibeTokens = function (state) {
        if (HELP.deriveVibes) return HELP.deriveVibes(state.preferences, state.verras);
        if (state.verras) return ['verrassend'];
        const tokens = [];
        (state.preferences || []).forEach(function (val) {
            const value = (val || '').toLowerCase();
            if (!value || value === 'verras') {
                return;
            }
            if (!tokens.includes(value)) {
                tokens.push(value);
            }
            if (value === 'winkelen' && !tokens.includes('shoppen')) {
                tokens.push('shoppen');
            }
            if (value === 'buitenlucht' && !tokens.includes('actief')) {
                tokens.push('actief');
            }
            if (value === 'verrassing' && !tokens.includes('verrassend')) {
                tokens.push('verrassend');
            }
            if (value === 'food' && !tokens.includes('bourgondisch')) {
                tokens.push('bourgondisch');
            }
        });
        return tokens;
    };

    function toLocalISO(date) {
        const base = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
        return base.toISOString().split('T')[0];
    }

    function formatDateLabel(iso) {
        if (!iso) return '';
        try {
            const date = new Date(`${iso}T00:00:00`);
            return date.toLocaleDateString('nl-NL', { day: 'numeric', month: 'short' });
        } catch (err) {
            return iso;
        }
    }

    var PREFILL_QUEUE_KEY = 'sbdpPlannerPrefillQueue';
    function readPlannerQueue() {
        try {
            const raw = window.sessionStorage.getItem(PREFILL_QUEUE_KEY);
            const parsed = raw ? JSON.parse(raw) : [];
            return Array.isArray(parsed) ? parsed : [];
        } catch (err) {
            return [];
        }
    }

    function writePlannerQueue(queue) {
        try {
            window.sessionStorage.setItem(PREFILL_QUEUE_KEY, JSON.stringify(queue || []));
        } catch (err) {
            // ignore storage issues
        }
    }

    function firstPositiveCount() {
        for (var index = 0; index < arguments.length; index += 1) {
            const parsed = parseInt(arguments[index], 10);
            if (Number.isFinite(parsed) && parsed > 0) {
                return parsed;
            }
        }
        return null;
    }

    function persistPrefill(payload) {
        const manager = window.PreferenceManager;
        const normalized = manager && typeof manager.normalize === 'function'
            ? manager.normalize(payload)
            : payload;

        if (manager && typeof manager.save === 'function') {
            manager.save(normalized);
        } else {
            try {
                window.sessionStorage.setItem('sbdp_home_widget_prefill', JSON.stringify(normalized));
                window.SBDP_HOME_WIDGET_PREFILL = normalized;
            } catch (err) {
                // ignore storage issues
            }
        }

        if (normalized && (normalized.visitDate || normalized.date || normalized.count || normalized.participants)) {
            const participants = firstPositiveCount(normalized.count, normalized.participants);
            const entry = {
                date: normalized.visitDate || normalized.date || null,
                participants: participants,
                people: participants,
                audience: normalized.audience || null,
                vibe: normalized.vibe || null,
                duration: normalized.duration || null,
                source: 'home',
            };
            const queue = readPlannerQueue();
            queue.push(entry);
            writePlannerQueue(queue);
        }
    }

    function storeWidgetPayload(payload) {
        const manager = window.PreferenceManager;
        const normalized = manager && typeof manager.normalize === 'function'
            ? manager.normalize(payload)
            : payload;

        if (manager && typeof manager.save === 'function') {
            manager.save(normalized);
            return;
        }

        try {
            window.sessionStorage.setItem('sbdp_home_widget_prefill', JSON.stringify(normalized));
            window.SBDP_HOME_WIDGET_PREFILL = normalized;
        } catch (err) {
            // ignore storage issues
        }
    }
    function buildUrl(path, payload) {
        if (!path || typeof path !== 'string') path = '/plan-je-dag';
        const url = new URL(path, window.location.origin);
        url.searchParams.set('visitDate', payload.visitDate);
        url.searchParams.set('count', String(payload.count));
        url.searchParams.set('participants', String(payload.count));
        if (payload.duration) url.searchParams.set('duration', payload.duration);
        if (payload.start && payload.start !== 'vrij') url.searchParams.set('start', payload.start);
        if (payload.audience) url.searchParams.set('audience', payload.audience);
        if (payload.vibe) url.searchParams.set('vibe', payload.vibe);
        const preferenceValue = normalizePreferenceQueryValue(payload.preferences);
        if (preferenceValue) {
            url.searchParams.set('preferences', preferenceValue);
        }
        return url;
    }

    function normalizePreferenceQueryValue(value) {
        if (!value) return '';
        if (Array.isArray(value)) {
            return value
                .map(function (entry) { return String(entry || '').trim(); })
                .filter(function (entry) { return entry !== ''; })
                .join(',');
        }
        if (typeof value === 'string') {
            return value.trim();
        }
        if (typeof value === 'object') {
            return Object.values(value)
                .map(function (entry) { return String(entry || '').trim(); })
                .filter(function (entry) { return entry !== ''; })
                .join(',');
        }
        return String(value || '').trim();
    }

    function buildDiscoveryUrl(payload, apiBase) {
        const base = apiBase
            || (window.sbdpConfig && window.sbdpConfig.apiBase)
            || window.SBDP_API_BASE
            || '/wp-json/planner/v1';
        const url = new URL(String(base).replace(/\/+$/, '') + '/activities', window.location.origin);
        url.searchParams.set('date', payload.visitDate);
        url.searchParams.set('participants', String(payload.count));
        url.searchParams.set('exclude_unavailable', '1');
        url.searchParams.set('per_page', '12');
        return url;
    }

    function buildActivityOverviewUrl(payload) {
        const url = new URL('/activiteiten/', window.location.origin);
        url.searchParams.set('date', payload.visitDate);
        url.searchParams.set('visitDate', payload.visitDate);
        url.searchParams.set('participants', String(payload.count));
        url.searchParams.set('count', String(payload.count));
        return url;
    }

    function buildOverviewContextPayload(dateValue, countValue) {
        const visitDate = normalizeDate(dateValue);
        const count = clampCount(countValue);
        if (!visitDate || !count) {
            return null;
        }
        return {
            visitDate: visitDate,
            date: visitDate,
            count: count,
        };
    }

    function syncActivitiesOverviewLinks(payload) {
        const links = document.querySelectorAll('a[href]');
        links.forEach(function (link) {
            if (!(link instanceof HTMLAnchorElement)) {
                return;
            }

            const rawHref = link.getAttribute('href');
            if (!rawHref) {
                return;
            }

            if (!link.dataset.sbdpOriginalHref) {
                link.dataset.sbdpOriginalHref = rawHref;
            }

            const originalHref = link.dataset.sbdpOriginalHref || rawHref;
            let url;
            try {
                url = new URL(originalHref, window.location.origin);
            } catch (err) {
                return;
            }

            const pathname = String(url.pathname || '').replace(/\/+$/, '');
            if (pathname !== '/activiteiten') {
                return;
            }

            if (!payload) {
                link.setAttribute('href', originalHref);
            } else {
                const nextUrl = buildActivityOverviewUrl(payload);
                link.setAttribute('href', nextUrl.pathname + nextUrl.search);
            }

            if (link.dataset.sbdpActivitiesContextBound === '1') {
                return;
            }

            link.dataset.sbdpActivitiesContextBound = '1';
            link.addEventListener('click', function () {
                const livePayload = buildOverviewContextPayload(
                    dateInput ? dateInput.value : state.date,
                    countInput ? countInput.value : state.count
                );

                if (!livePayload) {
                    link.setAttribute('href', originalHref);
                    return;
                }

                const liveUrl = buildActivityOverviewUrl(livePayload);
                link.setAttribute('href', liveUrl.pathname + liveUrl.search);
            });
        });
    }

    function createPlannerEntry(activity, payload) {
        const prefill = activity && activity.planner_prefill && typeof activity.planner_prefill === 'object'
            ? { ...activity.planner_prefill }
            : {};
        if (!prefill.visitDate) {
            prefill.visitDate = payload.visitDate || payload.date || '';
        }
        if (!prefill.count) {
            prefill.count = payload.count;
        }
        if (!prefill.date) {
            prefill.date = payload.visitDate;
        }
        if (!prefill.participants) {
            prefill.participants = payload.count;
        }
        if (!prefill.people) {
            prefill.people = payload.count;
        }
        if (!prefill.duration && payload.duration) {
            prefill.duration = payload.duration;
        }
        if (!prefill.audience && payload.audience) {
            prefill.audience = payload.audience;
        }
        if (!prefill.vibe && payload.vibe) {
            prefill.vibe = payload.vibe;
        }
        if (!prefill.preferences) {
            const preferenceValue = normalizePreferenceQueryValue(payload.preferences);
            if (preferenceValue) {
                prefill.preferences = preferenceValue;
            }
        }
        prefill.source = 'home_widget_discovery';
        return prefill;
    }

    function buildPlannerUrlFromEntry(activity, payload) {
        const entry = createPlannerEntry(activity, payload);
        const fallbackStart = activity && activity.slug ? activity.slug : '';
        if (fallbackStart && !entry.start) {
            entry.start = fallbackStart;
        }
        return buildUrl('/plan-je-dag', entry);
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = String(value || '');
        return div.innerHTML;
    }

    function run() {
        const widgets = document.querySelectorAll('[data-ui-planner-widget]');
        if (!widgets.length) {
            return;
        }

        widgets.forEach(function (root) {
            if (root.dataset.ddbInit === '1') {
                return;
            }
            root.dataset.ddbInit = '1';

            const dateInput = root.querySelector('[data-ui-date]');
            const countInput = root.querySelector('[data-ui-count]');
            const countMinus = root.querySelector('[data-ui-count-minus]');
            const countPlus = root.querySelector('[data-ui-count-plus]');
            const summaryDateEl = root.querySelector('[data-ui-summary-date]');
            const summaryPeopleEl = root.querySelector('[data-ui-summary-people]');
            const summaryChange = root.querySelector('[data-ui-summary-change]');
            const inlineHint = root.querySelector('[data-ui-inline-hint]');
            const footnote = root.querySelector('[data-ui-footnote]');
            const submitBtn = root.querySelector('[data-ui-submit]');
            const submitLabel = root.querySelector('[data-ui-submit-label]');
            const submitLoader = root.querySelector('[data-ui-submit-loader]');
            const openModalBtn = root.querySelector('[data-ui-open-modal]');
            const modal = root.querySelector('[data-ui-modal]');
            const closeBtns = root.querySelectorAll('[data-ui-close]');
            const discoveryPanel = root.querySelector('[data-ui-discovery]');
            const discoveryDirect = root.querySelector('[data-ui-discovery-direct]');
            const discoveryRequest = root.querySelector('[data-ui-discovery-request]');
            const discoveryDirectEmpty = root.querySelector('[data-ui-discovery-direct-empty]');
            const discoveryRequestEmpty = root.querySelector('[data-ui-discovery-request-empty]');
            const discoveryBrowse = root.querySelector('[data-ui-discovery-browse]');
            const discoveryPlanner = root.querySelector('[data-ui-discovery-planner]');
            const stepCount = root.querySelector('[data-ui-step-count]');
            const dateChips = root.querySelectorAll('[data-ui-date-chip]');
            const prefPanels = root.querySelectorAll('[data-ui-pref-panel]');
            const prefToggle = root.querySelector('[data-ui-pref-toggle]');
            const prefButtons = root.querySelectorAll('[data-ui-chip-group="preferences"] .ui-chip[data-value]');
            const groupButtons = root.querySelectorAll('[data-ui-chip-group="group"] .ui-chip');
            const verrasBtn = root.querySelector('[data-ui-chip-group="preferences"] .ui-chip[data-value="verras"]');
            const durationButtons = root.querySelectorAll('[data-ui-chip-group="duration"] .ui-chip');

            const state = { ...DEFAULTS };

            function resetDiscovery() {
                if (discoveryDirect) discoveryDirect.innerHTML = '';
                if (discoveryRequest) discoveryRequest.innerHTML = '';
                if (discoveryDirectEmpty) discoveryDirectEmpty.hidden = true;
                if (discoveryRequestEmpty) discoveryRequestEmpty.hidden = true;
                if (discoveryPanel) discoveryPanel.hidden = true;
            }

            function renderDiscoveryGroup(target, emptyNode, items, payload) {
                if (!target || !emptyNode) {
                    return;
                }

                target.innerHTML = '';
                if (!Array.isArray(items) || !items.length) {
                    emptyNode.hidden = false;
                    return;
                }

                emptyNode.hidden = true;
                items.forEach(function (item) {
                    const article = document.createElement('article');
                    article.className = 'ui-planner-widget__result';

                    const title = typeof item.title === 'string' && item.title.trim() ? item.title.trim() : 'Activiteit';
                    const excerpt = typeof item.excerpt === 'string' ? item.excerpt.trim() : '';
                    const price = item.price && typeof item.price.formatted === 'string' ? item.price.formatted : '';
                    const duration = item.duration && typeof item.duration.formatted === 'string' ? item.duration.formatted : '';
                    const capability = String(item.booking_capability || item.bookingCapability || '').toLowerCase();
                    const statusLabel =
                        capability === 'request'
                            ? 'Op aanvraag'
                            : capability === 'direct_limited'
                                ? 'Direct boekbaar na check'
                                : 'Direct boekbaar';

                    article.innerHTML = [
                        `<div class="ui-planner-widget__result-meta"><span>${escapeHtml(statusLabel)}</span><span>${escapeHtml(duration || 'Flexibel')}</span><span>${escapeHtml(price || 'Prijs op aanvraag')}</span></div>`,
                        `<h4 class="ui-planner-widget__result-title">${escapeHtml(title)}</h4>`,
                        excerpt ? `<p class="ui-planner-widget__result-copy">${escapeHtml(excerpt)}</p>` : '',
                        '<div class="ui-planner-widget__result-actions"></div>',
                    ].join('');

                    const actions = article.querySelector('.ui-planner-widget__result-actions');
                    const plannerLink = document.createElement('a');
                    const plannerUrl = buildPlannerUrlFromEntry(item, payload);
                    plannerLink.className = 'ui-planner-widget__btn';
                    plannerLink.textContent = capability === 'request' ? 'Plan aanvraag' : 'Plan direct';
                    plannerLink.href = plannerUrl.pathname + plannerUrl.search;
                    plannerLink.addEventListener('click', function () {
                        persistPrefill(createPlannerEntry(item, payload));
                    });
                    actions.appendChild(plannerLink);

                    if (item.permalink) {
                        const detailLink = document.createElement('a');
                        detailLink.className = 'ui-planner-widget__btn ui-planner-widget__btn--ghost';
                        detailLink.textContent = capability === 'request' ? 'Bekijk aanvraag' : 'Bekijk activiteit';
                        detailLink.href = String(item.permalink);
                        actions.appendChild(detailLink);
                    }

                    target.appendChild(article);
                });
            }

            function renderDiscovery(items, payload) {
                const directItems = [];
                const requestItems = [];

                (Array.isArray(items) ? items : []).forEach(function (item) {
                    const capability = String(item.booking_capability || item.bookingCapability || '').toLowerCase();
                    if (capability === 'request') {
                        requestItems.push(item);
                    } else if (capability === 'direct' || capability === 'direct_limited') {
                        directItems.push(item);
                    }
                });

                renderDiscoveryGroup(discoveryDirect, discoveryDirectEmpty, directItems, payload);
                renderDiscoveryGroup(discoveryRequest, discoveryRequestEmpty, requestItems, payload);

                if (discoveryBrowse) {
                    const browseUrl = buildActivityOverviewUrl(payload);
                    discoveryBrowse.href = browseUrl.pathname + browseUrl.search;
                }

                if (discoveryPlanner) {
                    const plannerUrl = buildUrl('/plan-je-dag', payload);
                    discoveryPlanner.href = plannerUrl.pathname + plannerUrl.search;
                    discoveryPlanner.onclick = function () {
                        persistPrefill(payload);
                    };
                }

                if (discoveryPanel) {
                    discoveryPanel.hidden = false;
                }
            }

            const todayIso = toLocalISO(new Date());
            const maxIso = toLocalISO(new Date(Date.now() + MAX_FUTURE_DAYS * 24 * 60 * 60 * 1000));

            if (dateInput) {
                dateInput.min = todayIso;
                dateInput.max = maxIso;
                if (!dateInput.value) {
                    dateInput.value = todayIso;
                }
                state.date = normalizeDate(dateInput.value) || todayIso;
            } else {
                state.date = todayIso;
            }

            if (countInput) {
                countInput.step = '1';
                countInput.inputMode = 'numeric';
                countInput.max = '100';
                countInput.min = '1';
                if (!countInput.value) {
                    countInput.value = '6';
                }
                state.count = clampCount(countInput.value) || 6;
            } else {
                state.count = 6;
            }

            function showStep() {
                if (stepCount) {
                    stepCount.hidden = false;
                    stepCount.classList.add('is-visible');
                }
            }

            function setHint(message) {
                if (!inlineHint) return;
                if (!message) {
                    inlineHint.textContent = '';
                    inlineHint.hidden = true;
                    return;
                }
                inlineHint.hidden = false;
                inlineHint.textContent = message;
            }

            function updateSummary() {
                if (summaryDateEl) {
                    summaryDateEl.textContent = formatDateLabel(state.date);
                }
                if (summaryPeopleEl) {
                    summaryPeopleEl.textContent = state.count ? `${state.count} pers` : 'Aantal nog niet gekozen';
                }
                if (footnote) {
                    footnote.textContent = '';
                    footnote.hidden = true;
                }
                syncActivitiesOverviewLinks(buildOverviewContextPayload(state.date, state.count));
            }

            function selectGroup(value) {
                state.group = value;
                groupButtons.forEach(function (btn) {
                    btn.classList.toggle('is-active', btn.dataset.value === value);
                });
            }

            function selectDuration(value) {
                state.duration = value;
                durationButtons.forEach(function (btn) {
                    btn.classList.toggle('is-active', btn.dataset.value === value);
                });
            }

            function showPrefPanel(show) {
                prefPanels.forEach(function (panel) {
                    panel.hidden = !show;
                });
                if (prefToggle) {
                    prefToggle.classList.toggle('is-active', !!show);
                }
            }

            function resetPreferencesToVerras() {
                state.verras = true;
                state.preferences = ['verras'];
                if (verrasBtn) {
                    verrasBtn.classList.add('is-active');
                }
                prefButtons.forEach(function (btn) {
                    if (btn !== verrasBtn) {
                        btn.classList.remove('is-active');
                    }
                });
                showPrefPanel(false);
            }

            function syncCtaState() {
                const payload = buildPayload(false);
                const valid = !!payload && deriveVibeTokens(state).length > 0;
                if (submitBtn) {
                    submitBtn.disabled = !valid;
                }
                return valid;
            }

            function buildPayload(requireValid) {
                const isoDate = normalizeDate(dateInput ? dateInput.value : state.date);
                if (!isoDate) {
                    if (requireValid) setHint('Kies een datum.');
                    return null;
                }
                state.date = isoDate;

                const count = clampCount(countInput ? countInput.value : state.count);
                if (!count) {
                    if (requireValid) setHint('Vul het aantal personen in.');
                    return null;
                }
                state.count = count;

                const vibeTokens = deriveVibeTokens(state);
                if (requireValid && vibeTokens.length === 0) {
                    setHint('Kies minimaal 1 voorkeur (max 2) of Verras me.');
                    return null;
                }

                const payload = {
                    visitDate: isoDate,
                    date: isoDate,
                    count: count,
                    start: state.start,
                    duration: canonicalDuration(state),
                    duration_raw: state.duration,
                    audience: deriveAudience(state),
                    audience_raw: state.group,
                    vibe: vibeTokens.join(' '),
                    preferences: [...(state.preferences || [])],
                };
                if (state.start && state.start !== 'vrij') {
                    payload.startTime = state.start;
                }
                return payload;
            }

            state.verras = false;
            state.preferences = [];
            if (verrasBtn) {
                verrasBtn.classList.remove('is-active');
            }
            prefButtons.forEach(function (btn) {
                if (btn !== verrasBtn) {
                    btn.classList.remove('is-active');
                }
            });
            showPrefPanel(true);
            selectGroup(state.group);
            selectDuration(state.duration);
            updateSummary();
            showStep();
            syncCtaState();
            resetDiscovery();
            if (dateInput) {
                const handleDateUpdate = function () {
                    const norm = normalizeDate(dateInput.value);
                    if (norm) {
                        state.date = norm;
                        dateInput.value = norm;
                        setHint('');
                        showStep();
                    }
                    updateSummary();
                    syncCtaState();
                };
                dateInput.addEventListener('change', handleDateUpdate);
                dateInput.addEventListener('input', handleDateUpdate);
            }

            dateChips.forEach(function (chip) {
                chip.addEventListener('click', function () {
                    const type = chip.getAttribute('data-ui-date-chip');
                    let target = state.date;
                    if (type === 'today') {
                        target = todayIso;
                    } else if (type === 'tomorrow') {
                        const t = new Date();
                        t.setDate(t.getDate() + 1);
                        target = toLocalISO(t);
                    }
                    state.date = target;
                    if (dateInput) {
                        dateInput.value = target;
                    }
                    setHint('');
                    updateSummary();
                    showStep();
                    syncCtaState();
                });
            });

            if (countInput) {
                countInput.addEventListener('input', function () {
                    state.count = clampCount(countInput.value);
                    if (state.count > 0) {
                        countInput.value = String(state.count);
                        setHint('');
                    }
                    updateSummary();
                    syncCtaState();
                });
            }

            function applyCountDelta(delta) {
                state.count = clampCount((countInput ? countInput.value : state.count) || state.count);
                const next = clampCount((state.count ?? 0) + delta);
                if (next > 0) {
                    state.count = next;
                    if (countInput) {
                        countInput.value = String(next);
                    }
                    updateSummary();
                    syncCtaState();
                }
            }

            if (countMinus) {
                countMinus.addEventListener('click', function () {
                    applyCountDelta(-1);
                });
            }
            if (countPlus) {
                countPlus.addEventListener('click', function () {
                    applyCountDelta(1);
                });
            }

            if (summaryChange) {
                summaryChange.addEventListener('click', function () {
                    if (dateInput) {
                        dateInput.focus();
                    }
                    root.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            }

            groupButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    selectGroup(btn.dataset.value || '');
                    setHint('');
                    syncCtaState();
                });
            });

            durationButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    selectDuration(btn.dataset.value || '');
                    setHint('');
                    syncCtaState();
                });
            });

            if (prefToggle) {
                prefToggle.addEventListener('click', function () {
                    state.verras = false;
                    state.preferences = [];
                    if (verrasBtn) {
                        verrasBtn.classList.remove('is-active');
                    }
                    prefButtons.forEach(function (btn) {
                        if (btn !== verrasBtn) {
                            btn.classList.remove('is-active');
                        }
                    });
                    showPrefPanel(true);
                    syncCtaState();
                });
            }

            prefButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const value = btn.dataset.value || '';
                    if (value === 'verras') {
                        resetPreferencesToVerras();
                        setHint('');
                        syncCtaState();
                        return;
                    }

                    state.verras = false;
                    showPrefPanel(true);
                    if (verrasBtn) {
                        verrasBtn.classList.remove('is-active');
                    }

                    const isActive = btn.classList.contains('is-active');
                    state.preferences = (state.preferences || []).filter(function (val) {
                        return val !== 'verras';
                    });

                    if (isActive) {
                        btn.classList.remove('is-active');
                        state.preferences = state.preferences.filter(function (val) {
                            return val !== value;
                        });
                    } else {
                        state.preferences = state.preferences.filter(function (val) {
                            return val !== value;
                        });
                        state.preferences.push(value);
                        if (state.preferences.length > MAX_PREFS) {
                            const removed = state.preferences.shift();
                            if (removed && removed !== value) {
                                const toClear = root.querySelector(`[data-ui-chip-group="preferences"] .ui-chip[data-value="${removed}"]`);
                                if (toClear) {
                                    toClear.classList.remove('is-active');
                                }
                            }
                        }
                        btn.classList.add('is-active');
                    }
                    setHint('');
                    syncCtaState();
                });
            });

            if (openModalBtn) {
                openModalBtn.addEventListener('click', function () {
                    if (!state.date || !state.count) {
                        setHint('Kies datum en aantal personen.');
                        return;
                    }
                    if (modal) {
                        resetDiscovery();
                        modal.hidden = false;
                        document.body.style.overflow = 'hidden';
                        setHint('');
                    }
                });
            }

            closeBtns.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (modal) {
                        modal.hidden = true;
                        document.body.style.overflow = '';
                        resetDiscovery();
                    }
                });
            });

            if (submitBtn) {
                submitBtn.addEventListener('click', async function () {
                    const payload = buildPayload(true);
                    if (!payload) {
                        return;
                    }
                    storeWidgetPayload(payload);
                    const plannerUrl = buildUrl('/plan-je-dag', payload);
                    window.location.href = plannerUrl.pathname + plannerUrl.search;
                });
            }

            syncCtaState();
            updateSummary();
            showStep();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }

    setTimeout(run, 500);
    setTimeout(run, 2000);

    (function dispatchPlannerPrefillFromQuery() {
        try {
            if (!window || !window.location || !window.dispatchEvent) {
                return;
            }
            var path = (window.location.pathname || '').toLowerCase();
            if (path.indexOf('plan-je-dag') === -1) {
                return;
            }
            var params = new URLSearchParams(window.location.search || '');
            if (!params || Array.from(params.keys()).length === 0) {
                return;
            }
            var detail = {};
            var date = params.get('visitDate') || params.get('date');
            if (date) detail.date = date;
            var people = params.get('count') || params.get('participants');
            if (people) detail.participants = people;
            var duration = params.get('duration');
            if (duration) detail.duration = duration;
            var audience = params.get('audience');
            if (audience) detail.audience = audience;
            var vibe = params.get('vibe');
            if (vibe) detail.vibe = vibe;
            var start = params.get('start');
            if (start) detail.time = start;
            if (Object.keys(detail).length > 0) {
                window.dispatchEvent(new CustomEvent('sbdp:planner/prefill', { detail: detail }));
            }
        } catch (err) {
            if (console && console.warn) {
                console.warn('[DDB Planning Sessions] Prefill dispatch failed', err);
            }
        }
    })();
})();
JS;


        \wp_register_script($script_handle, false, array(), '1.0', true);
        \wp_add_inline_script($script_handle, $js);
        \wp_enqueue_script($script_handle);
    }

    private static function should_enqueue_assets(): bool
    {
        if (\is_admin() || \wp_doing_ajax() || \wp_doing_cron()) {
            return false;
        }

        $forced = \apply_filters('ddb_home_widget_enqueue_assets', null);
        if ($forced !== null) {
            return (bool) $forced;
        }

        $post = \get_post();
        if (!($post instanceof \WP_Post)) {
            return false;
        }

        if (self::content_contains_home_widget_publication($post->post_content)) {
            return true;
        }

        if (self::elementor_document_contains_shortcode($post->ID, 'ddb_home_widget')) {
            return true;
        }

        if (self::elementor_document_contains_home_widget_bridge($post->ID)) {
            return true;
        }

        return self::elementor_document_contains_home_widget_snapshot($post->ID);
    }

    private static function elementor_document_contains_shortcode(int $postId, string $shortcode): bool
    {
        if ($postId <= 0) {
            return false;
        }

        $rawData = \get_post_meta($postId, '_elementor_data', true);
        if (!is_string($rawData) || $rawData === '') {
            return false;
        }

        return strpos($rawData, $shortcode) !== false;
    }

    private static function elementor_document_contains_home_widget_bridge(int $postId): bool
    {
        if ($postId <= 0) {
            return false;
        }

        $rawData = \get_post_meta($postId, '_elementor_data', true);
        if (!is_string($rawData) || $rawData === '') {
            return false;
        }

        return strpos($rawData, '"widgetType":"sbdp_homepage_block"') !== false
            && strpos($rawData, '"variant":"composer"') !== false;
    }

    private static function elementor_document_contains_home_widget_snapshot(int $postId): bool
    {
        if ($postId <= 0) {
            return false;
        }

        $rawData = \get_post_meta($postId, '_elementor_data', true);
        if (!is_string($rawData) || $rawData === '') {
            return false;
        }

        return strpos($rawData, 'data-ui-planner-widget') !== false;
    }

    public static function create_session(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $guard = self::guard_mutation_request($request, 'create', self::CREATE_RATE_LIMIT);
        if ($guard instanceof WP_Error) {
            return $guard;
        }

        $data = self::sanitize_payload($request->get_json_params());
        $key  = self::generate_key();
        $record = [
            'key'        => $key,
            'data'       => $data,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        self::save_session($key, $record);

        return new WP_REST_Response($record, 201);
    }

    public static function upsert_session(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $key = $request->get_param('key');
        if (! self::is_valid_key($key)) {
            return new WP_Error('ddb_invalid_key', 'Ongeldige sessiesleutel.', ['status' => 400]);
        }

        $existing = self::load_session($key);
        if (! $existing) {
            return new WP_Error('ddb_not_found', 'Sessie niet gevonden.', ['status' => 404]);
        }

        if (in_array($request->get_method(), ['PUT', 'PATCH'], true)) {
            $guard = self::guard_mutation_request($request, 'update', self::UPDATE_RATE_LIMIT);
            if ($guard instanceof WP_Error) {
                return $guard;
            }

            $payload            = self::sanitize_payload($request->get_json_params());
            $existing['data']   = $request->get_method() === 'PATCH'
                ? self::merge_payload($existing['data'] ?? [], $payload)
                : $payload;
            $existing['updated_at'] = current_time('mysql');
            self::save_session($key, $existing);
        }
        return new WP_REST_Response($existing);
    }

    public static function log_event(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $guard = self::guard_mutation_request($request, 'event', self::EVENT_RATE_LIMIT);
        if ($guard instanceof WP_Error) {
            return $guard;
        }

        $payload = self::sanitize_payload($request->get_json_params());
        $eventName = isset($payload['event']) ? sanitize_key((string) $payload['event']) : '';
        if ($eventName === '') {
            return new WP_Error('ddb_invalid_event', 'Eventnaam ontbreekt.', ['status' => 400]);
        }

        $severity = isset($payload['severity']) ? sanitize_key((string) $payload['severity']) : 'info';
        if (! in_array($severity, ['info', 'warning', 'error', 'success'], true)) {
            $severity = 'info';
        }

        $context = [
            'scope'      => 'day_planner_decision',
            'session_id' => isset($payload['session_id']) ? (string) $payload['session_id'] : '',
            'trace_id'   => isset($payload['trace_id']) ? (string) $payload['trace_id'] : '',
        ];
        if (function_exists('do_action')) {
            do_action('sbdp/audit/log', $eventName, $context, $payload, $severity);
        }

        if (function_exists('error_log')) {
            $entry = [
                'event'     => $eventName,
                'severity'  => $severity,
                'timestamp' => gmdate('c'),
                'payload'   => $payload,
            ];
            $encoded = wp_json_encode($entry);
            if (is_string($encoded) && $encoded !== '') {
                error_log('[SBDP][DDBEvent] ' . $encoded);
            }
        }

        return new WP_REST_Response([
            'status'    => 'ok',
            'event'     => $eventName,
            'severity'  => $severity,
            'timestamp' => gmdate('c'),
        ], 201);
    }
    private static function is_valid_key(string $key): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9\\-]{8,64}$/', $key);
    }

    private static function generate_key(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (\Throwable) {
            return wp_generate_uuid4();
        }
    }

    private static function option_name(string $key): string
    {
        return 'ddb_planning_session_' . $key;
    }

    private static function save_session(string $key, array $record): void
    {
        $encoded = wp_json_encode($record);
        if (! is_string($encoded) || $encoded === '') {
            return;
        }

        set_transient(self::option_name($key), $encoded, self::SESSION_TTL);
        delete_option(self::option_name($key));
    }

    private static function load_session(string $key): ?array
    {
        $storageKey = self::option_name($key);
        $raw = get_transient($storageKey);
        if (! $raw) {
            $raw = get_option($storageKey);
            if ($raw) {
                set_transient($storageKey, (string) $raw, self::SESSION_TTL);
                delete_option($storageKey);
            }
        }

        if (! $raw || ! is_string($raw)) {
            return null;
        }

        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param mixed $existing
     * @param mixed $payload
     * @return array<string,mixed>
     */
    private static function merge_payload($existing, $payload): array
    {
        if (! is_array($payload)) {
            return is_array($existing) ? $existing : [];
        }

        if (! is_array($existing)) {
            return $payload;
        }

        if (self::is_list_array($existing) || self::is_list_array($payload)) {
            return $payload;
        }

        $merged = $existing;
        foreach ($payload as $key => $value) {
            if (
                array_key_exists($key, $merged) &&
                is_array($merged[$key]) &&
                is_array($value) &&
                ! self::is_list_array($merged[$key]) &&
                ! self::is_list_array($value)
            ) {
                $merged[$key] = self::merge_payload($merged[$key], $value);
                continue;
            }

            $merged[$key] = $value;
        }

        return $merged;
    }

    /**
     * @param mixed $value
     */
    private static function is_list_array($value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    private static function guard_mutation_request(WP_REST_Request $request, string $scope, int $limit): ?WP_Error
    {
        $rawBody = $request->get_body();
        if (is_string($rawBody) && strlen($rawBody) > self::MAX_PAYLOAD_BYTES) {
            return new WP_Error('ddb_payload_too_large', 'Payload is te groot voor deze sessie-aanvraag.', ['status' => 413]);
        }

        $actorKey = self::rate_limit_actor();
        $bucketKey = 'ddb_planning_rate_' . md5($scope . '|' . $actorKey);
        $bucket = get_transient($bucketKey);
        $count = is_array($bucket) ? (int) ($bucket['count'] ?? 0) : 0;
        if ($count >= $limit) {
            return new WP_Error('ddb_rate_limited', 'Te veel sessie-aanvragen in korte tijd.', ['status' => 429]);
        }

        set_transient($bucketKey, ['count' => $count + 1], self::RATE_LIMIT_WINDOW);
        return null;
    }

    private static function rate_limit_actor(): string
    {
        if (\function_exists('get_current_user_id')) {
            $userId = (int) \get_current_user_id();
            if ($userId > 0) {
                return 'user:' . $userId;
            }
        }

        $remoteAddr = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $remoteAddr = preg_replace('/[^0-9A-Fa-f:\.]/', '', $remoteAddr);
        return $remoteAddr !== '' ? 'ip:' . $remoteAddr : 'ip:unknown';
    }
    /**
     * Best-effort sanitize of JSON payload while keeping structure.
     *
     * @param mixed $payload
     * @return array<string,mixed>
     */
    private static function sanitize_payload($payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $walk = static function ($value) use (&$walk) {
            if (is_array($value)) {
                $out = [];
                foreach ($value as $k => $v) {
                    $out[$k] = $walk($v);
                }
                return $out;
            }
            if (is_string($value)) {
                return sanitize_text_field($value);
            }
            return $value;
        };

            return $walk($payload);
    }

    public static function render_home_widget(array $atts = []): string
    {
        $atts = shortcode_atts(
            [
            'style' => 'dark',
            'count' => '6',
            ],
            $atts,
            'ddb_home_widget'
        );

        $mode       = strtolower($atts['style']) === 'light' ? 'light' : 'dark';
        $count      = is_numeric($atts['count']) ? (int) $atts['count'] : 6;
        $count_attr = $count > 0 ? $count : 6;
        $activities_url = home_url('/activiteiten/');
        $today_attr = wp_date('Y-m-d');
        ob_start();
        ?>
<section
    class="ui-planner-widget ui-planner-widget--<?php echo esc_attr($mode); ?>"
    data-ui-planner-widget
    data-ui-api-base="<?php echo esc_attr(home_url('/wp-json/planner/v1')); ?>"
    data-ui-api-nonce="<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>"
>
    <div class="ui-planner-widget__steps">
        <div class="ui-planner-widget__step">
            <p class="ui-planner-widget__label">1) Wanneer wilt gij op avontuur?</p>
            <div class="ui-planner-widget__chipset" data-ui-chip-group="datepick">
                <button type="button" class="ui-chip" data-ui-date-chip="today">Vandaag</button>
                <button type="button" class="ui-chip" data-ui-date-chip="tomorrow">Morgen</button>
                <label class="ui-planner-widget__field" style="margin:0;padding:0;">
                    <input type="date" name="visitDate" value="<?php echo esc_attr($today_attr); ?>" placeholder="yyyy-mm-dd" data-ui-date>
                </label>
            </div>
        </div>

        <div class="ui-planner-widget__step" data-ui-step-count>
            <p class="ui-planner-widget__label">2) Hoelang?</p>
            <div class="ui-planner-widget__chipset" data-ui-chip-group="duration">
                <button type="button" class="ui-chip" data-value="hele-dag">Dag</button>
                <button type="button" class="ui-chip" data-value="ochtend">Ochtend</button>
                <button type="button" class="ui-chip" data-value="middag">Middag</button>
                <button type="button" class="ui-chip" data-value="avond">Avond</button>
            </div>
        </div>

        <div class="ui-planner-widget__step">
            <p class="ui-planner-widget__label">3) Met hoeveel kome gullie?</p>
            <label class="ui-planner-widget__field">
                <span>Aantal</span>
                <div class="ui-planner-widget__count-control">
                    <button type="button" class="ui-planner-widget__count-btn" data-ui-count-minus aria-label="Minder personen">-</button>
                    <input type="number" name="count" min="1" max="100" step="1" inputmode="numeric" value="<?php echo esc_attr($count_attr); ?>" data-ui-count style="max-width:90px;">
                    <button type="button" class="ui-planner-widget__count-btn" data-ui-count-plus aria-label="Meer personen">+</button>
                </div>
            </label>
        </div>
    </div>

    <div class="ui-planner-widget__footer">
        <button type="button" class="ui-planner-widget__btn" data-ui-open-modal>
            <span class="ui-planner-widget__btn-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" role="img" focusable="false">
                    <path d="M7 2a1 1 0 0 1 1 1v1h8V3a1 1 0 1 1 2 0v1h1a3 3 0 0 1 3 3v12a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3V7a3 3 0 0 1 3-3h1V3a1 1 0 0 1 1-1Zm12 8H5v9a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-9ZM6 8h12V7a1 1 0 0 0-1-1h-1v1a1 1 0 1 1-2 0V6H8v1a1 1 0 0 1-2 0V6H5a1 1 0 0 0-1 1v1Z"/>
                </svg>
            </span>
            <span>Dag samenstellen</span>
        </button>
        <a class="ui-planner-widget__btn ui-planner-widget__btn--ghost" href="<?php echo esc_url($activities_url); ?>">
            <span class="ui-planner-widget__btn-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" role="img" focusable="false">
                    <path d="M12 2c4.98 0 9 3.582 9 8 0 3.168-2.09 5.893-5.142 7.197L12 22l-3.858-4.803C5.09 15.893 3 13.168 3 10c0-4.418 4.02-8 9-8Zm0 2c-3.86 0-7 2.69-7 6 0 2.44 1.62 4.6 4.03 5.63l.47.2L12 18.62l2.5-2.79.47-.2C17.38 14.6 19 12.44 19 10c0-3.31-3.14-6-7-6Zm0 2.5a3.5 3.5 0 1 1 0 7 3.5 3.5 0 0 1 0-7Zm0 2a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Z"/>
                </svg>
            </span>
            <span>Bekijk activiteiten</span>
        </a>
    </div>
    <div class="ui-planner-widget__modal" hidden data-ui-modal>
        <div class="ui-planner-widget__modal-card" role="dialog" aria-modal="true" aria-label="Plan je dag">
            <button type="button" class="ui-planner-widget__close" aria-label="Sluiten" data-ui-close>&times;</button>
            <p class="ui-planner-widget__badge">Stap 2: Jouw ideale dag samenstellen</p>
            <div class="ui-planner-widget__question">
                <p class="ui-planner-widget__question-label">We nemen je voorkeuren mee voor de beste start.</p>
                <p class="ui-planner-widget__hint">Je basis is al ingevuld. Kies hieronder of we je verrassen (random mix), of beantwoord 2 korte vragen voor een betere match.</p>
                <div class="ui-planner-widget__chipset" data-ui-chip-group="preferences" data-ui-pref-primary>
                    <button type="button" class="ui-chip" data-value="verras">Verras me (random)</button>
                    <button type="button" class="ui-chip" data-ui-pref-toggle>Beantwoord 2 vragen</button>
                </div>
            </div>
            <div class="ui-planner-widget__question" data-ui-pref-panel hidden>
                <p class="ui-planner-widget__question-label">Met wie kom je op pad?</p>
                <div class="ui-planner-widget__chipset" data-ui-chip-group="group">
                    <button type="button" class="ui-chip" data-value="familie">Familie</button>
                    <button type="button" class="ui-chip" data-value="vrienden">Vrienden</button>
                    <button type="button" class="ui-chip" data-value="bedrijf">Bedrijf</button>
                    <button type="button" class="ui-chip" data-value="school">School</button>
                    <button type="button" class="ui-chip" data-value="romantisch">Romantisch</button>
                    <button type="button" class="ui-chip" data-value="solo">Solo</button>
                </div>
            </div>

            <div class="ui-planner-widget__question" data-ui-pref-panel hidden>
                <p class="ui-planner-widget__question-label">Waar heb je zin in vandaag? (kies max 2)</p>
                <div class="ui-planner-widget__chipset" data-ui-chip-group="preferences">
                    <button type="button" class="ui-chip" data-value="cultuur">Cultuur</button>
                    <button type="button" class="ui-chip" data-value="bourgondisch">Bourgondisch</button>
                    <button type="button" class="ui-chip" data-value="food">Food</button>
                    <button type="button" class="ui-chip" data-value="actief">Actief</button>
                    <button type="button" class="ui-chip" data-value="verrassing">Verrassing</button>
                </div>
            </div>

            <section class="ui-planner-widget__discovery" data-ui-discovery hidden>
                <div class="ui-planner-widget__discovery-group">
                    <h3 class="ui-planner-widget__discovery-title">Direct boekbaar</h3>
                    <div class="ui-planner-widget__results" data-ui-discovery-direct></div>
                    <p class="ui-planner-widget__discovery-empty" data-ui-discovery-direct-empty hidden>Geen directe opties voor deze selectie.</p>
                </div>
                <div class="ui-planner-widget__discovery-group">
                    <h3 class="ui-planner-widget__discovery-title">Op aanvraag</h3>
                    <div class="ui-planner-widget__results" data-ui-discovery-request></div>
                    <p class="ui-planner-widget__discovery-empty" data-ui-discovery-request-empty hidden>Geen aanvraagopties voor deze selectie.</p>
                </div>
                <div class="ui-planner-widget__discovery-footer">
                    <a class="ui-planner-widget__btn ui-planner-widget__btn--ghost" href="<?php echo esc_url($activities_url); ?>" data-ui-discovery-browse>
                        Bekijk volledig overzicht
                    </a>
                    <a class="ui-planner-widget__btn" href="<?php echo esc_url(home_url('/plan-je-dag')); ?>" data-ui-discovery-planner>
                        Open planner
                    </a>
                </div>
            </section>

            <div class="ui-planner-widget__modal-actions">
                <button type="button" class="ui-planner-widget__btn ui-planner-widget__btn--ghost" data-ui-close>Annuleren</button>
                <button type="button" class="ui-planner-widget__btn" data-ui-submit>
                    <span class="ui-planner-widget__loader" data-ui-submit-loader hidden></span>
                    <span data-ui-submit-label>Toon activiteiten</span>
                </button>
            </div>
            <p class="ui-planner-widget__inline-hint" hidden data-ui-inline-hint>Kies minimaal 1 voorkeur of Verras me.</p>
        </div>
    </div>
</section>
            <?php

            return trim(ob_get_clean());
    }
}

Controller::init();

