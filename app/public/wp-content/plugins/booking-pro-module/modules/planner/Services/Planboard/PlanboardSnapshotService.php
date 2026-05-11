<?php

declare(strict_types=1);

namespace BSP\Planner\Services\Planboard;

use BSP\Bookings\Service\BookingManager;
use DateTimeImmutable;
use WP_Post;

final class PlanboardSnapshotService
{
    public function __construct(
        private BookingManager $manager,
        private PlanboardRulesService $rulesService
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>|\WP_Error
     */
    public function snapshot(array $filters, bool $useCache = true): array
    {
        $range = PlanboardValidator::validateSnapshot($filters);
        if ($range instanceof \WP_Error) {
            return $range;
        }

        $cacheKey = PlanboardCache::buildKey('snapshot', $range);
        if ($useCache) {
            $cached = PlanboardCache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $bookings = $this->manager->getBookings(array(
            'date_from' => substr($range['start'], 0, 10),
            'date_to'   => substr($range['end'], 0, 10),
        ));

        $payload = array(
            'range'      => $range,
            'generated_at' => gmdate(DateTimeImmutable::ATOM),
            'resources'  => $this->loadResources(),
            'bookings'   => $this->normalizeBookings($bookings),
            'closures'   => $this->rulesService->summarize($range['start'], $range['end']),
        );

        PlanboardCache::set($cacheKey, $payload);

        return $payload;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadResources(): array
    {
        if (! function_exists('get_posts')) {
            return array();
        }

        $posts = get_posts(array(
            'post_type'      => 'bookable_resource',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
        ));

        $resources = array();

        foreach ($posts as $post) {
            if (! $post instanceof WP_Post) {
                continue;
            }

            $resources[] = array(
                'id'       => $post->ID,
                'name'     => $post->post_title,
                'capacity' => (int) get_post_meta($post->ID, '_sbdp_resource_capacity', true),
                'color'    => (string) get_post_meta($post->ID, '_sbdp_resource_color', true),
                'order'    => (int) get_post_meta($post->ID, '_sbdp_resource_order', true),
            );
        }

        $resources[] = array(
            'id'       => 0,
            'name'     => function_exists('__') ? __('Unassigned', 'sbdp') : 'Unassigned',
            'capacity' => 0,
            'color'    => '#94a3b8',
            'order'    => 9999,
        );

        usort(
            $resources,
            static fn (array $left, array $right): int => ($left['order'] ?? 0) <=> ($right['order'] ?? 0)
        );

        return $resources;
    }

    /**
     * @param array<int, array<string, mixed>> $bookings
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeBookings(array $bookings): array
    {
        $normalized = array();

        foreach ($bookings as $booking) {
            if (! is_array($booking)) {
                continue;
            }

            $start = $this->composeDateTime($booking['date'] ?? '', $booking['time'] ?? '');
            $end   = $this->composeDateTime(
                $booking['date_end'] ?? ($booking['date'] ?? ''),
                $booking['time_end'] ?? ($booking['time'] ?? '')
            );

            $resourceId = isset($booking['planner']['resource'])
                ? (int) $booking['planner']['resource']
                : (int) ($booking['resource'] ?? 0);

            $normalized[] = array(
                'id'           => (int) ($booking['id'] ?? 0),
                'status'       => (string) ($booking['status'] ?? ''),
                'start'        => $start,
                'end'          => $end,
                'resource_id'  => $resourceId,
                'participants' => (int) ($booking['participants'] ?? 0),
                'customer'     => array(
                    'name'  => (string) ($booking['customer']['name'] ?? ''),
                    'email' => (string) ($booking['customer']['email'] ?? ''),
                ),
                'items'        => isset($booking['items']) && is_array($booking['items']) ? $booking['items'] : array(),
                'channel'      => $booking['channel'] ?? null,
                'total'        => (float) ($booking['total'] ?? 0.0),
                'currency'     => (string) ($booking['currency'] ?? 'EUR'),
                'version'      => (string) ($booking['updated_at'] ?? ($booking['order']['updated_at'] ?? '')),
            );
        }

        return $normalized;
    }

    private function composeDateTime(string $date, string $time): string
    {
        $date = trim($date);
        $time = trim($time);
        if ($date === '') {
            return '';
        }

        $candidate = $date . ' ' . ($time !== '' ? $time : '00:00');

        try {
            return (new DateTimeImmutable($candidate))->format(DateTimeImmutable::ATOM);
        } catch (\Throwable $exception) {
            return $candidate;
        }
    }
}
