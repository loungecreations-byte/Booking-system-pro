<?php

declare(strict_types=1);

namespace SBDP\Modules\Planner;

use BSPModule\Core\Rest\RestService as CoreRestService;
use SBDP\BookingEngine;
use SBDP\Contracts\ModuleInterface;
use SBDP\Modules\Planner\Rest\PlannerRoutes;
use SBDP\Modules\Planner\Rest\PlanboardRoutes;
use SBDP\Modules\Planner\Services\PlannerService;
use BSP\Planner\Services\Planboard\PlanboardFeature;

final class Module implements ModuleInterface
{
    private const GUIDE_SYNC_HOOK = 'sbdp_planner_sync_guides';

    private PlannerService $service;
    private PlannerRoutes $routes;
    private PlanboardRoutes $planboardRoutes;
    private bool $booted = false;
    private static bool $hooks_registered = false;

    public function __construct(?PlannerService $service = null, ?PlannerRoutes $routes = null, ?PlanboardRoutes $planboardRoutes = null)
    {
        if (! class_exists(PlannerService::class, false)) {
            require_once __DIR__ . '/Services/PlannerService.php';
        }

        if (! class_exists(PlannerRoutes::class, false)) {
            require_once __DIR__ . '/Rest/PlannerRoutes.php';
        }

        if (! class_exists(PlanboardRoutes::class, false)) {
            require_once __DIR__ . '/Rest/PlanboardRoutes.php';
        }

        $this->service = $service ?? new PlannerService();
        $this->routes  = $routes ?? new PlannerRoutes($this->service);
        $this->planboardRoutes = $planboardRoutes ?? new PlanboardRoutes($this);
    }

    public function register(BookingEngine $engine): void
    {
        if ($this->booted || self::$hooks_registered) {
            $this->booted = true;

            return;
        }

        $this->booted = true;
        self::$hooks_registered = true;

        $dispatcher = $engine->getDispatcher();

        $dispatcher->on(
            'planner.list_products',
            function (array $payload): array {
                $filters  = $payload;
                $products = $this->service->listProducts($filters);

                return array(
                    'filters'  => $filters,
                    'products' => $products,
                );
            }
        );

        $dispatcher->on(
            'planner.generate_schedule',
            function (array $payload): array {
                $bookings = is_array($payload['bookings'] ?? null) ? $payload['bookings'] : array();

                $analysis = $this->service->buildSchedule($bookings);

                return array(
                    'bookings' => $bookings,
                    'schedule' => $analysis['timeline'],
                    'analysis' => array(
                        'windows'   => $analysis['windows'],
                        'conflicts' => $analysis['conflicts'],
                    ),
                );
            }
        );

        $dispatcher->on(
            'planner.detect_conflicts',
            function (array $payload): array {
                $bookings = is_array($payload['bookings'] ?? null) ? $payload['bookings'] : array();

                return array(
                    'bookings'  => $bookings,
                    'conflicts' => $this->service->detectConflicts($bookings),
                );
            }
        );

        add_action('rest_api_init', array($this->routes, 'register'));
        if (PlanboardFeature::isEnabled()) {
            add_action('rest_api_init', array($this->planboardRoutes, 'register'));
        }
        add_action('rest_api_init', array($this, 'register_compatibility_routes'));
        add_action('init', array($this, 'register_cityguide_profiles'));
        add_action('wp_enqueue_scripts', array($this, 'register_assets'));
        add_action('init', array($this, 'ensure_cron_schedule'));
        add_action(self::GUIDE_SYNC_HOOK, array($this, 'sync_guide_calendars'));

        if (function_exists('add_shortcode')) {
            // Legacy planner aliases removed; canonical planner entry point is [sbdp_dayplanner].
        }

        if (is_admin()) {
            $this->register_admin();
        }
    }

    public function registerProfiles(): void
    {
        $this->register_cityguide_profiles();
    }

    public function init(): void
    {
        add_action(
            'sbdp/engine/bootstrapped',
            function (BookingEngine $engine): void {
                $this->register($engine);
            }
        );
    }

    public function register_cityguide_profiles(): void
    {
        if (! class_exists('\BSP\Planner\Vendor\CityGuideProfileStore')) {
            return;
        }

        $store = new \BSP\Planner\Vendor\CityGuideProfileStore();
        $store->register();
    }

    /**
     * @param array<int, array<string, mixed>> $bookings
     * @return array<int, array<string, string>>
     */
    public function generateSchedule(array $bookings): array
    {
        return $this->service->generateSchedule($bookings);
    }

    /**
     * @param array<int, array<string, mixed>> $bookings
     * @return array<string, mixed>
     */
    public function buildSchedule(array $bookings): array
    {
        return $this->service->buildSchedule($bookings);
    }

    /**
     * @param array<int, array<string, mixed>> $bookings
     * @return array<int, array<string, mixed>>
     */
    public function detectConflicts(array $bookings): array
    {
        return $this->service->detectConflicts($bookings);
    }

    /**
     * @param array<int, array<string, mixed>> $bookings
     */
    public function hasOverlap(array $bookings): bool
    {
        if ($bookings === array()) {
            return false;
        }

        $simpleEligible = true;
        $seen = array();

        foreach ($bookings as $booking) {
            if (! is_array($booking)) {
                continue;
            }

            $time = (string) ($booking['time'] ?? $booking['start'] ?? '');
            $resource = (string) ($booking['resource'] ?? '');

            if ($time === '' || preg_match('/^\d{2}:\d{2}$/', $time) !== 1) {
                $simpleEligible = false;
                break;
            }

            $key = $resource . '|' . $time;
            if (isset($seen[$key])) {
                return true;
            }

            $seen[$key] = true;
        }

        if ($simpleEligible) {
            return false;
        }

        return $this->detectConflicts($bookings) !== array();
    }

    /**
     * @param array<int, string> $allSlots
     * @param array<int, string> $bookedSlots
     * @return array<int, string>
     */
    public function availableSlots(array $allSlots, array $bookedSlots): array
    {
        return $this->service->availableSlots($allSlots, $bookedSlots);
    }

    /**
     * @param array<string, mixed> $booking
     * @param array<int, array<string, mixed>> $resources
     *
     * @return array<string, mixed>
     */
    public function assignResource(array $booking, array $resources): array
    {
        if ($resources !== array()) {
            $first = $resources[0];
            $booking['resource'] = (string) ($first['id'] ?? ($first['name'] ?? ''));

            return $booking;
        }

        if (! isset($booking['resource']) || $booking['resource'] === '') {
            $booking['resource'] = 'unassigned';
        }

        return $booking;
    }

    public function moveBooking(int $bookingId, string $time): bool
    {
        if ($bookingId <= 0) {
            return false;
        }

        return trim($time) !== '';
    }

    /**
     * @param array<string, mixed> $booking
     * @return array<int, string>
     */
    public function validateBooking(array $booking): array
    {
        return $this->service->validateBooking($booking);
    }

    public function register_assets(): void
    {
        if (! function_exists('wp_register_script') || ! defined('SBDP_URL')) {
            return;
        }

        wp_register_script(
            'sbdp-planner-app',
            SBDP_URL . 'assets/planner.js',
            array('wp-api-fetch'),
            defined('SBDP_VER') ? SBDP_VER : null,
            true
        );

        wp_register_script(
            'sbdp-planner-dice',
            SBDP_URL . 'assets/js/planner-dice.js',
            array('sbdp-planner-app'),
            defined('SBDP_VER') ? SBDP_VER : null,
            true
        );

        // SUPERSEDED BY day-planner-refresh.css / shared DDB UI — PENDING ARCHIVE
    }

    public function render_shortcode(): string
    {
        $rest_base = function_exists('rest_url') ? rest_url('booking/v1/planner') : '/wp-json/booking/v1/planner';
        $pricing_preview = function_exists('rest_url') ? rest_url('sbdp/v1/pricing/preview') : '/wp-json/sbdp/v1/pricing/preview';
        $nonce = function_exists('wp_create_nonce') ? wp_create_nonce('wp_rest') : '';
        $public_nonce = function_exists('wp_create_nonce') ? wp_create_nonce(CoreRestService::PUBLIC_NONCE_ACTION) : '';

        if (function_exists('wp_enqueue_script')) {
            wp_enqueue_script('sbdp-planner-app');
            wp_enqueue_script('sbdp-planner-dice');
        }

        if (function_exists('wp_enqueue_style')) {
            // SUPERSEDED BY day-planner-refresh.css / shared DDB UI — PENDING ARCHIVE
        }

        $attributes = array(
            'data-rest'             => function_exists('esc_url') ? esc_url($rest_base) : $rest_base,
            'data-rest-base'        => function_exists('esc_url') ? esc_url($rest_base) : $rest_base,
            'data-pricing'          => function_exists('esc_url') ? esc_url($pricing_preview) : $pricing_preview,
            'data-pricing-preview'  => function_exists('esc_url') ? esc_url($pricing_preview) : $pricing_preview,
            'data-nonce'            => function_exists('esc_attr') ? esc_attr($nonce) : $nonce,
            'data-public'           => function_exists('esc_attr') ? esc_attr($public_nonce) : $public_nonce,
            'data-public-nonce'     => function_exists('esc_attr') ? esc_attr($public_nonce) : $public_nonce,
        );

        $attributeString = '';
        foreach ($attributes as $attribute => $value) {
            if ($value === '') {
                continue;
            }
            $attributeString .= sprintf(' %s="%s"', $attribute, $value);
        }

        return '<div id="bpm-planner"' . $attributeString . '></div>';
    }

    public function renderBookingForm(): string
    {
        return $this->render_shortcode();
    }

    public function ensure_cron_schedule(): void
    {
        if (! function_exists('wp_next_scheduled') || ! function_exists('wp_schedule_event')) {
            return;
        }

        if (\wp_next_scheduled(self::GUIDE_SYNC_HOOK)) {
            return;
        }

        $interval = apply_filters('sbdp_planner_sync_interval', 'hourly');
        if (! is_string($interval) || $interval === '') {
            $interval = 'hourly';
        }

        \wp_schedule_event(time() + 300, $interval, self::GUIDE_SYNC_HOOK);
    }

    public function sync_guide_calendars(): void
    {
        $synced = false;

        if (class_exists('\BSP\Planner\Vendor\CityGuideProfileStore') && class_exists('\BSP\Planner\Vendor\CityGuideProfile')) {
            $store    = new \BSP\Planner\Vendor\CityGuideProfileStore();
            $profiles = $store->all();

            foreach ($profiles as $profile) {
                if (! $profile instanceof \BSP\Planner\Vendor\CityGuideProfile) {
                    continue;
                }

                $icalUrl = trim($profile->icalUrl);
                if ($icalUrl === '') {
                    continue;
                }

                $ical = $this->fetchGuideCalendar($icalUrl);
                if ($ical === null || $ical === '') {
                    $this->recordGuideSyncError($profile->id, 'fetch_failed');
                    continue;
                }

                $windows = $this->importGuideCalendar($ical);

                if ($profile->id > 0 && function_exists('update_post_meta')) {
                    update_post_meta($profile->id, '_sbdp_cityguide_last_sync', gmdate(DATE_ATOM));
                }

                if ($windows !== array()) {
                    $synced = true;
                    $this->recordGuideSyncStatus($profile->id, 'synced');

                    do_action(
                        'sbdp/planner/guide_synced',
                        array(
                            'guide_id' => $profile->id,
                            'windows'  => $windows,
                            'source'   => 'cron',
                        )
                    );
                } else {
                    $this->recordGuideSyncError($profile->id, 'empty_availability');

                    do_action(
                        'sbdp/planner/guide_sync_warning',
                        array(
                            'guide_id' => $profile->id,
                            'reason'   => 'empty_availability',
                            'source'   => 'cron',
                        )
                    );
                }
            }
        }

        if (function_exists('do_action')) {
            do_action(
                'sbdp/planner/sync_guides',
                $this->service,
                array(
                    'synced' => $synced,
                )
            );
        }
    }

    private function fetchGuideCalendar(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        if (function_exists('wp_remote_get')) {
            $response = wp_remote_get($url, array('timeout' => 20));
            if (is_wp_error($response)) {
                return null;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code < 200 || $code >= 300) {
                return null;
            }

            return (string) wp_remote_retrieve_body($response);
        }

        $context  = stream_context_create(array('http' => array('timeout' => 20)));
        $contents = @file_get_contents($url, false, $context);

        return false === $contents ? null : $contents;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function importGuideCalendar(string $ical): array
    {
        if (! class_exists('\BSP\Planner\Vendor\CityGuideICalImporter')) {
            return array();
        }

        $importer = new \BSP\Planner\Vendor\CityGuideICalImporter();
        $windows  = $importer->import($ical);

        return is_array($windows) ? $windows : array();
    }

    private function recordGuideSyncStatus(int $guideId, string $status): void
    {
        if ($guideId <= 0 || ! function_exists('update_post_meta')) {
            return;
        }

        update_post_meta($guideId, '_sbdp_cityguide_status', $status);
        delete_post_meta($guideId, '_sbdp_cityguide_last_error');
    }

    private function recordGuideSyncError(int $guideId, string $reason): void
    {
        if ($guideId > 0 && function_exists('update_post_meta')) {
            update_post_meta($guideId, '_sbdp_cityguide_status', 'error');
            update_post_meta($guideId, '_sbdp_cityguide_last_error', $reason);
        }

        if (function_exists('do_action')) {
            do_action(
                'sbdp/planner/guide_sync_error',
                array(
                    'guide_id' => $guideId,
                    'reason'   => $reason,
                )
            );
        }
    }

    public function register_compatibility_routes(): void
    {
        if (! function_exists('register_rest_route')) {
            return;
        }

        register_rest_route(
            'bsp/v1',
            '/planner/schedule',
            array(
                'methods'             => 'POST',
                'callback'            => array($this->routes, 'generate_schedule'),
                'permission_callback' => array($this, 'can_manage'),
            )
        );

        register_rest_route(
            'bsp/v1',
            '/planner/availability',
            array(
                'methods'             => 'POST',
                'callback'            => array($this->routes, 'get_availability'),
                'permission_callback' => array($this, 'can_manage'),
            )
        );

        register_rest_route(
            'bsp/v1',
            '/planner/guide-availability',
            array(
                'methods'             => 'POST',
                'callback'            => array($this->routes, 'guide_availability'),
                'permission_callback' => array($this, 'can_manage'),
            )
        );
    }

    private function register_admin(): void
    {
        if (
            ! class_exists('\BSP\Planner\Vendor\Admin\ProfileAdmin')
            || ! class_exists('\BSP\Planner\Vendor\CityGuideProfileStore')
        ) {
            return;
        }

        $store = new \BSP\Planner\Vendor\CityGuideProfileStore();
        $admin = new \BSP\Planner\Vendor\Admin\ProfileAdmin($store);
        if (method_exists($admin, 'hooks')) {
            $admin->hooks();
        }
    }

    public function can_manage(): bool
    {
        if (! function_exists('current_user_can')) {
            return false;
        }

        return current_user_can('manage_sbdp_planner') || current_user_can('manage_woocommerce');
    }
}

if (! class_exists('\BSP\Planner\Module', false)) {
    \class_alias(Module::class, '\BSP\Planner\Module');
}
