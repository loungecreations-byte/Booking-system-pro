<?php

declare(strict_types=1);

namespace BSP\Planner;

use BSP\Core\CoreServiceProvider;
use BSP\Core\Interfaces\ModuleInterface;
use BSP\Planner\Rest\Controller as RestController;
use BSP\Planner\Vendor\Admin\ProfileAdmin;
use BSP\Planner\Vendor\CityGuideProfileStore;

/**
 * Planner module offering scheduling helpers and resource management.
 */
final class Module implements ModuleInterface
{
    private CityGuideProfileStore $profiles;

    private ProfileAdmin $admin;

    public function __construct(
        ?CityGuideProfileStore $profiles = null,
        ?ProfileAdmin $admin = null
    ) {
        $this->profiles = $profiles ?? new CityGuideProfileStore();
        $this->admin    = $admin ?? new ProfileAdmin($this->profiles);
    }

    public function init(): void
    {
        CoreServiceProvider::logger()->log('Planner module initialized');

        if (function_exists('add_action')) {
            add_action('init', [$this, 'registerProfiles']);
            add_action('rest_api_init', 'BSP\\Planner\\Rest\\Controller::register');
        }

        if (function_exists('add_shortcode')) {
            add_shortcode('bsp_booking_form', [$this, 'renderBookingForm']);
        }

        if (is_admin()) {
            $this->admin->hooks();
        }
    }

    public function registerProfiles(): void
    {
        $this->profiles->register();
    }

    /**
     * Build an ordered timeline from booking entries.
     *
     * @param array<int, array<string, mixed>> $bookings
     *
     * @return array<int, array<string, string>>
     */
    public function generateSchedule(array $bookings): array
    {
        $timeline = [];

        foreach ($bookings as $booking) {
            $timeline[] = [
                'slot'     => (string)($booking['time'] ?? ''),
                'label'    => (string)($booking['name'] ?? ''),
                'resource' => (string)($booking['resource'] ?? ''),
            ];
        }

        usort(
            $timeline,
            static fn(array $left, array $right): int => strcmp($left['slot'], $right['slot'])
        );

        return $timeline;
    }

    /**
     * Determine whether any bookings overlap for the same resource/time slot.
     *
     * @param array<int, array<string, mixed>> $bookings
     */
    public function hasOverlap(array $bookings): bool
    {
        $seen = [];

        foreach ($bookings as $booking) {
            $resource = (string)($booking['resource'] ?? '');
            $time     = (string)($booking['time'] ?? '');
            if ('' === $resource || '' === $time) {
                continue;
            }

            $key = $resource . '|' . $time;
            if (isset($seen[$key])) {
                return true;
            }

            $seen[$key] = true;
        }

        return false;
    }

    /**
     * Compute the list of available slots by removing booked entries.
     *
     * @param array<int, string> $allSlots
     * @param array<int, string> $bookedSlots
     *
     * @return array<int, string>
     */
    public function availableSlots(array $allSlots, array $bookedSlots): array
    {
        $remaining = array_values(array_diff($allSlots, $bookedSlots));
        sort($remaining);
        return $remaining;
    }

    /**
     * Assign a resource to a booking based on the provided pool.
     *
     * @param array<string, mixed> $booking
     * @param array<int, array<string, mixed>> $resources
     *
     * @return array<string, mixed>
     */
    public function assignResource(array $booking, array $resources): array
    {
        if (!empty($resources)) {
            $first = $resources[0];
            $booking['resource'] = (string)($first['id'] ?? ($first['name'] ?? ''));
            return $booking;
        }

        $booking['resource'] = $booking['resource'] ?? 'unassigned';
        return $booking;
    }

    public function moveBooking(int $bookingId, string $newTime): bool
    {
        return $bookingId > 0 && '' !== $newTime;
    }

    /**
     * Validate whether a booking payload contains required fields.
     *
     * @param array<string, mixed> $booking
     *
     * @return array<int, string>
     */
    public function validateBooking(array $booking): array
    {
        $errors = [];

        if (empty($booking['time'])) {
            $errors[] = 'time_required';
        }

        if (empty($booking['name'])) {
            $errors[] = 'name_required';
        }

        return $errors;
    }

    public function renderBookingForm(): string
    {
        if (! function_exists('plugins_url') || ! function_exists('esc_url')) {
            return '';
        }

        $pluginFile = defined('SBDP_FILE') ? SBDP_FILE : __FILE__;
        $baseUrl    = plugins_url('', $pluginFile);
        if (function_exists('trailingslashit')) {
            $baseUrl = trailingslashit($baseUrl);
        } elseif (substr($baseUrl, -1) !== '/') {
            $baseUrl .= '/';
        }

        $cssUrl     = $baseUrl . 'modules/planner/assets/planner.css';
        $jsUrl      = $baseUrl . 'modules/planner/assets/planner.js';
        $requestUrl = function_exists('rest_url') ? rest_url('bsp/v1/booking/request') : '/wp-json/bsp/v1/booking/request';

        $labels = [
            'name'   => function_exists('esc_html__') ? esc_html__('Naam', 'sbdp') : 'Naam',
            'date'   => function_exists('esc_html__') ? esc_html__('Datum', 'sbdp') : 'Datum',
            'time'   => function_exists('esc_html__') ? esc_html__('Tijd', 'sbdp') : 'Tijd',
            'vendor' => function_exists('esc_html__') ? esc_html__('Vendor ID', 'sbdp') : 'Vendor ID',
            'button' => function_exists('esc_html__') ? esc_html__('Boeking aanvragen', 'sbdp') : 'Boeking aanvragen',
        ];

        ob_start();
        ?>
        <link rel="stylesheet" href="<?php echo esc_url($cssUrl); ?>" />
        <form id="bsp-booking-form" class="bsp-form" data-endpoint="<?php echo esc_url($requestUrl); ?>">
            <label>
                <?php echo esc_html($labels['name']); ?>
                <input name="name" required />
            </label>
            <label>
                <?php echo esc_html($labels['date']); ?>
                <input name="date" type="date" required />
            </label>
            <label>
                <?php echo esc_html($labels['time']); ?>
                <input name="time" type="time" required />
            </label>
            <label>
                <?php echo esc_html($labels['vendor']); ?>
                <input name="vendor_id" type="number" min="0" />
            </label>
            <button type="submit" class="btn-primary"><?php echo esc_html($labels['button']); ?></button>
        </form>
        <div id="bsp-calendar"></div>
        <script src="<?php echo esc_url($jsUrl); ?>" defer></script>
        <?php
        return trim((string) ob_get_clean());
    }
}


