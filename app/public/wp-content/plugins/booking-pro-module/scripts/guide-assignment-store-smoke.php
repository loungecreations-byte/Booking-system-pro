<?php

declare(strict_types=1);

if (! function_exists('add_action')) {
    $wpLoad = dirname(__DIR__, 4) . '/wp-load.php';
    if (is_readable($wpLoad)) {
        require_once $wpLoad;
    }
}

use BSP\BookingBoard\Service\BoardService;
use BSP\Bookings\Service\BookingManager;
use BSP\Bookings\Service\GuideAssignmentService;
use BSP\Planner\Vendor\CityGuideProfileStore;

function sbdp_guide_assignment_smoke_fail(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function sbdp_guide_assignment_smoke_ok(bool $condition, string $message): void
{
    if (! $condition) {
        sbdp_guide_assignment_smoke_fail($message);
    }
}

sbdp_guide_assignment_smoke_ok(
    class_exists(CityGuideProfileStore::class),
    'CityGuideProfileStore class does not load.'
);

$store = new CityGuideProfileStore();
sbdp_guide_assignment_smoke_ok(
    method_exists($store, 'all') && method_exists($store, 'register'),
    'CityGuideProfileStore contract methods are missing.'
);

$profiles = $store->all();
sbdp_guide_assignment_smoke_ok(is_array($profiles), 'CityGuideProfileStore::all() must return an array.');

$guideAssignments = new GuideAssignmentService($store);
sbdp_guide_assignment_smoke_ok(
    $guideAssignments instanceof GuideAssignmentService,
    'GuideAssignmentService cannot initialize with CityGuideProfileStore.'
);

$manager = BookingManager::createDefault();
sbdp_guide_assignment_smoke_ok(
    $manager instanceof BookingManager,
    'BookingManager::createDefault() cannot initialize.'
);

$board = new BoardService($manager);
sbdp_guide_assignment_smoke_ok(
    $board instanceof BoardService,
    'BoardService cannot initialize with BookingManager.'
);

$gracefulMissingData = true;
if ($profiles === array() && isset($GLOBALS['wpdb']) && $GLOBALS['wpdb'] instanceof wpdb) {
    $reflection = new ReflectionClass($guideAssignments);
    $method = $reflection->getMethod('resolveGuides');
    $method->setAccessible(true);

    $result = $method->invoke(
        $guideAssignments,
        $GLOBALS['wpdb'],
        array(
            'requested_language' => 'nl',
            'preferred_resource_ref' => '',
            'scheduled_date' => '2026-06-17',
            'scheduled_start_time' => '10:00',
            'scheduled_end_time' => '11:00',
        )
    );

    $gracefulMissingData = $result === array(0, 0, 0);
}

sbdp_guide_assignment_smoke_ok(
    $gracefulMissingData,
    'Missing guide data must degrade to no assignment candidate, not silent success.'
);

echo json_encode(
    array(
        'ok' => true,
        'city_guide_profile_store_loaded' => true,
        'profile_count' => count($profiles),
        'guide_assignment_service_initializes' => true,
        'booking_manager_initializes' => true,
        'board_service_initializes' => true,
        'missing_guide_data_degrades_gracefully' => $gracefulMissingData,
    ),
    JSON_PRETTY_PRINT
) . PHP_EOL;

