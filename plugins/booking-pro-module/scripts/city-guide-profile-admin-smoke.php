<?php

declare(strict_types=1);

if (! function_exists('add_action')) {
    $wpLoad = dirname(__DIR__, 4) . '/wp-load.php';
    if (is_readable($wpLoad)) {
        require_once $wpLoad;
    }
}

use BSP\Bookings\Service\GuideAssignmentService;
use BSP\Planner\Vendor\Admin\ProfileAdmin;
use BSP\Planner\Vendor\CityGuideProfileStore;

function sbdp_cityguide_admin_smoke_fail(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function sbdp_cityguide_admin_smoke_ok(bool $condition, string $message): void
{
    if (! $condition) {
        sbdp_cityguide_admin_smoke_fail($message);
    }
}

sbdp_cityguide_admin_smoke_ok(class_exists(CityGuideProfileStore::class), 'CityGuideProfileStore does not load.');
sbdp_cityguide_admin_smoke_ok(class_exists(ProfileAdmin::class), 'ProfileAdmin does not load.');

$store = new CityGuideProfileStore();
$store->register();
sbdp_cityguide_admin_smoke_ok(post_type_exists(ProfileAdmin::POST_TYPE), 'bsp_city_guide post type is not registered.');

$admin = new ProfileAdmin($store);
$admin->hooks();

sbdp_cityguide_admin_smoke_ok(
    has_action('admin_menu', array($admin, 'registerMenu')) !== false,
    'ProfileAdmin menu hook is not registered.'
);
sbdp_cityguide_admin_smoke_ok(
    has_action('add_meta_boxes_' . ProfileAdmin::POST_TYPE, array($admin, 'registerMetaBox')) !== false,
    'ProfileAdmin meta box hook is not registered.'
);
sbdp_cityguide_admin_smoke_ok(
    has_action('save_post_' . ProfileAdmin::POST_TYPE, array($admin, 'save')) !== false,
    'ProfileAdmin save hook is not registered.'
);

$requiredMetaKeys = array(
    ProfileAdmin::META_STATUS,
    ProfileAdmin::META_TIMEZONE,
    ProfileAdmin::META_ALLOW_NL_TOURS,
    ProfileAdmin::META_ICAL,
    ProfileAdmin::META_NOTE,
    ProfileAdmin::META_LAST_SYNC,
    ProfileAdmin::META_LANGUAGES,
    ProfileAdmin::META_PROTECTED_LANGUAGES,
);

$adminUser = get_users(array('role__in' => array('administrator'), 'number' => 1, 'fields' => 'ID'));
if (is_array($adminUser) && isset($adminUser[0])) {
    wp_set_current_user((int) $adminUser[0]);
}

$existing = get_page_by_title('Testgids MVP', OBJECT, ProfileAdmin::POST_TYPE);
$postId = $existing instanceof WP_Post
    ? (int) $existing->ID
    : (int) wp_insert_post(
        array(
            'post_title' => 'Testgids MVP',
            'post_type' => ProfileAdmin::POST_TYPE,
            'post_status' => 'publish',
        )
    );
sbdp_cityguide_admin_smoke_ok($postId > 0, 'Could not create or reuse Testgids MVP.');

$_POST = array(
    ProfileAdmin::NONCE_FIELD => wp_create_nonce(ProfileAdmin::NONCE_ACTION),
    'bsp_cityguide_status' => 'active<script>',
    'bsp_cityguide_languages' => array('nl', 'xx'),
    'bsp_cityguide_protected_languages' => array('nl', 'bad'),
    'bsp_cityguide_timezone' => 'Europe/Amsterdam<script>',
    'bsp_cityguide_ical' => 'https://example.test/gids.ics',
    'bsp_cityguide_note' => "test\n<script>alert(1)</script>",
    'bsp_cityguide_allow_nl_tours' => '1',
);
$admin->save($postId, get_post($postId));
$_POST = array();

$profiles = $store->all();
$matches = array_values(
    array_filter(
        $profiles,
        static fn ($profile): bool => isset($profile->id) && (int) $profile->id === $postId
    )
);
sbdp_cityguide_admin_smoke_ok($matches !== array(), 'CityGuideProfileStore::all() does not read the saved guide.');

$profile = $matches[0];
sbdp_cityguide_admin_smoke_ok((string) $profile->name === 'Testgids MVP', 'Saved guide name mismatch.');
sbdp_cityguide_admin_smoke_ok((string) $profile->status === 'active', 'Saved guide status was not sanitized to active.');
sbdp_cityguide_admin_smoke_ok($profile->languages === array('nl'), 'Saved guide languages were not sanitized.');
sbdp_cityguide_admin_smoke_ok($profile->protectedLanguages === array('nl'), 'Saved protected languages were not sanitized.');
sbdp_cityguide_admin_smoke_ok((string) $profile->timezone === 'Europe/Amsterdam', 'Saved guide timezone was not sanitized as expected.');
sbdp_cityguide_admin_smoke_ok((string) $profile->icalUrl === 'https://example.test/gids.ics', 'Saved guide iCal URL mismatch.');
sbdp_cityguide_admin_smoke_ok(strpos((string) $profile->note, '<script>') === false, 'Saved guide note was not sanitized.');

$blankPostId = (int) wp_insert_post(
    array(
        'post_title' => 'Testgids MVP zonder talen',
        'post_type' => ProfileAdmin::POST_TYPE,
        'post_status' => 'draft',
    )
);
sbdp_cityguide_admin_smoke_ok($blankPostId > 0, 'Could not create blank guide profile.');

$blankProfiles = $store->all();
$blankMatches = array_values(
    array_filter(
        $blankProfiles,
        static fn ($profile): bool => isset($profile->id) && (int) $profile->id === $blankPostId
    )
);
sbdp_cityguide_admin_smoke_ok($blankMatches !== array(), 'Blank guide profile is not readable.');
sbdp_cityguide_admin_smoke_ok($blankMatches[0]->languages === array('nl'), 'Blank guide profile did not default languages safely.');

$guideAssignments = new GuideAssignmentService($store);
sbdp_cityguide_admin_smoke_ok($guideAssignments instanceof GuideAssignmentService, 'GuideAssignmentService cannot initialize.');

$guardReflection = new ReflectionMethod(ProfileAdmin::class, 'canSave');
sbdp_cityguide_admin_smoke_ok($guardReflection->isPrivate(), 'ProfileAdmin save guard must remain private.');

wp_delete_post($blankPostId, true);

echo json_encode(
    array(
        'ok' => true,
        'cpt_registered' => true,
        'admin_hooks_registered' => true,
        'meta_keys' => $requiredMetaKeys,
        'test_guide_id' => $postId,
        'store_reads_saved_guide' => true,
        'save_sanitizes_fields' => true,
        'blank_guide_defaults_languages' => true,
        'guide_assignment_service_initializes' => true,
    ),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
