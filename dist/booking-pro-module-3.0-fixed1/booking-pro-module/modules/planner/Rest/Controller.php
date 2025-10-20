<?php
declare(strict_types=1);

namespace BSP\Planner\Rest;

use BSP\Planner\Module;
use BSP\Planner\Vendor\CityGuideICalImporter;
use BSP\Planner\Vendor\CityGuideProfileStore;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class Controller
{
    public static function register(): void
    {
        if (!\function_exists('register_rest_route')) {
            return;
        }

        \register_rest_route('bsp/v1', '/planner/schedule', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'schedule'],
            'permission_callback' => '__return_true',
        ]);

        \register_rest_route('bsp/v1', '/planner/availability', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'availability'],
            'permission_callback' => '__return_true',
        ]);

        \register_rest_route('bsp/v1', '/planner/guide-availability', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'guideAvailability'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function schedule(WP_REST_Request $request)
    {
        $bookings = $request->get_param('bookings');
        $bookings = \is_array($bookings) ? $bookings : [];

        $module   = new Module();
        $schedule = $module->generateSchedule($bookings);

        return rest_ensure_response($schedule);
    }

    public static function availability(WP_REST_Request $request)
    {
        $allSlots    = $request->get_param('all');
        $bookedSlots = $request->get_param('booked');
        $allSlots    = \is_array($allSlots) ? $allSlots : [];
        $bookedSlots = \is_array($bookedSlots) ? $bookedSlots : [];

        $module    = new Module();
        $available = $module->availableSlots($allSlots, $bookedSlots);

        return rest_ensure_response(['available' => $available]);
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public static function guideAvailability(WP_REST_Request $request)
    {
        $store   = new CityGuideProfileStore();
        $guideId = (int) ($request->get_param('guide_id') ?? 0);

        $ical = $request->get_param('ical');
        $ical = \is_string($ical) ? trim($ical) : '';

        if (0 !== $guideId && '' === $ical) {
            $profile = $store->find($guideId);
            if ($profile) {
                $ical = $profile->icalUrl;
            }
        }

        if ('' === $ical) {
            $url = $request->get_param('ical_url');
            if (\is_string($url) && '' !== trim($url)) {
                $ical = self::fetchIcal(trim($url)) ?? '';
            }
        } elseif (filter_var($ical, FILTER_VALIDATE_URL)) {
            $ical = self::fetchIcal($ical) ?? '';
        }

        if ('' === $ical) {
            return rest_ensure_response(new WP_Error('bsp_planner_missing_ical', 'No iCal content provided.', ['status' => 400]));
        }

        $importer = new CityGuideICalImporter();
        $windows  = $importer->import($ical);

        if (!empty($windows) && 0 !== $guideId) {
            update_post_meta($guideId, '_bsp_cityguide_last_sync', gmdate(DATE_ATOM));
            update_post_meta($guideId, '_bsp_cityguide_status', 'synced');
        }

        return rest_ensure_response(['windows' => $windows]);
    }

    private static function fetchIcal(string $url): ?string
    {
        if ('' === $url) {
            return null;
        }

        if (\function_exists('wp_remote_get')) {
            $response = wp_remote_get($url, ['timeout' => 15]);
            if (is_wp_error($response)) {
                return null;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code < 200 || $code >= 300) {
                return null;
            }

            return (string) wp_remote_retrieve_body($response);
        }

        $context  = stream_context_create(['http' => ['timeout' => 15]]);
        $contents = @file_get_contents($url, false, $context);

        return false === $contents ? null : $contents;
    }
}

