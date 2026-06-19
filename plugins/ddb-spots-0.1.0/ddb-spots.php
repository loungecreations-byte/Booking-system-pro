<?php
/**
 * Plugin Name: DDB Spots
 * Description: Unified spots model for DagjeDenBosch.nl with CPT, taxonomies, shortcodes, and REST API.
 * Version: 0.3.3
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: DagjeDenBosch
 * Text Domain: ddb-spots
 */

if (! defined('ABSPATH')) {
	exit;
}

define('DDB_SPOTS_VERSION', '0.3.3');
define('DDB_SPOTS_FILE', __FILE__);
define('DDB_SPOTS_PATH', plugin_dir_path(__FILE__));
define('DDB_SPOTS_URL', plugin_dir_url(__FILE__));

// Load global premium helpers (defines plan functions)
if (file_exists(DDB_SPOTS_PATH . 'includes/premium/plans.php')) {
	require_once DDB_SPOTS_PATH . 'includes/premium/plans.php';
}
if (file_exists(DDB_SPOTS_PATH . 'includes/premium/ranking.php')) {
        require_once DDB_SPOTS_PATH . 'includes/premium/ranking.php';
}
if (file_exists(DDB_SPOTS_PATH . 'includes/premium/slots.php')) {
	require_once DDB_SPOTS_PATH . 'includes/premium/slots.php';
}

if (! function_exists('ddb_spots_normalize_plan_key')) {
	function ddb_spots_normalize_plan_key(string $plan_key): string {
		$plan_key = sanitize_key($plan_key);
		return '' !== $plan_key ? $plan_key : 'free';
	}
}

if (! function_exists('ddb_spots_plan_entitlements')) {
	function ddb_spots_plan_entitlements(string $plan_key): array {
		unset($plan_key);
		return array(
			'media_limit' => 0,
			'analytics' => false,
			'top_picks' => false,
			'sponsored' => false,
			'ranking_boost' => 0.0,
			'modules' => array(),
		);
	}
}

if (! function_exists('ddb_spot_health_details')) {
	function ddb_spot_health_details(int $spot_id): array {
		unset($spot_id);
		return array(
			'score' => 0,
			'missing' => array(),
			'metrics' => array(),
		);
	}
}

if (! function_exists('ddb_spot_health_score')) {
	function ddb_spot_health_score(int $spot_id): int {
		$details = ddb_spot_health_details($spot_id);
		return max(0, min(100, (int) ($details['score'] ?? 0)));
	}
}
spl_autoload_register(static function ($class) {
    if (!str_starts_with($class, 'DDB_Spots')) {
        return;
    }

    $base_dir = DDB_SPOTS_PATH . 'includes/';
    
    // Mapping rules based on current folder structure
    $relative_class = str_replace('DDB_Spots_', '', $class);
    
    // Convert DDB_Spots_Something_Like_This to Something/Like/This
    $parts = explode('_', $relative_class);
    $last_index = count($parts) - 1;
    
    // Join all parts but the last with '/', then append the last part as filename
    if (count($parts) > 1) {
        $filename = $parts[$last_index];
        $path = implode('/', array_slice($parts, 0, $last_index));
        $file = $base_dir . $path . '/' . $filename . '.php';

        // Fallback for sub-subfolders (e.g. Domain_Spot_Repository -> Domain/SpotRepository.php)
        if (!file_exists($file)) {
             $file = $base_dir . $parts[0] . '/' . implode('', array_slice($parts, 1)) . '.php';
        }

        // Fallback for Service (singular) to Services (plural) folder
        if (!file_exists($file) && $parts[0] === 'Service') {
             $file = $base_dir . 'Services/' . implode('', array_slice($parts, 1)) . '.php';
        }
    } else {
        $file = $base_dir . $relative_class . '.php';
    }

    // Special case for root classes in includes/
    if (!file_exists($file)) {
        if ($class === 'DDB_Spots') {
            $file = $base_dir . 'class-ddb-spots.php';
        } elseif ($class === 'DDB_Spots_Booking') {
            $file = $base_dir . 'class-ddb-spots-booking.php';
        } elseif ($class === 'DDB_Spots_Cron_Sync_Service') {
             $file = $base_dir . 'Cron/Sync.php';
        } elseif ($class === 'DDB_Spots_Premium_Analytics') {
             $file = $base_dir . 'premium/analytics.php';
        } elseif ($class === 'DDB_Spots_Premium_Engine') {
             $file = $base_dir . 'premium/premium-engine.php';
        } elseif ($class === 'DDB_Spots_Rest_Api') {
             $file = $base_dir . 'Rest/Api.php';
        } elseif ($class === 'DDB_Spots_Integrations_Google_Places') {
             $file = $base_dir . 'Integrations/GooglePlaces.php';
        } elseif ($class === 'DDB_Spots_Business_Linker') {
             $file = $base_dir . 'Business/Linker.php';
        } elseif ($class === 'DDB_Spots_Business_Registry') {
             $file = $base_dir . 'Business/Registry.php';
        } elseif ($class === 'DDB_Spots_Admin_Editor_Tabs') {
             $file = $base_dir . 'Admin/EditorTabs.php';
        } elseif ($class === 'DDB_Spots_Admin_Spot_Health') {
             $file = $base_dir . 'Admin/SpotHealth.php';
        } elseif ($class === 'DDB_Spots_Admin_Insights_Page') {
             $file = $base_dir . 'Admin/InsightsPage.php';
        } elseif ($class === 'DDB_Spots_Admin_Settings_Page') {
             $file = $base_dir . 'Admin/SettingsPage.php';
        } elseif ($class === 'DDB_Spots_Admin_Sync_Dashboard') {
             $file = $base_dir . 'Admin/SyncDashboard.php';
        } elseif ($class === 'DDB_Spots_Admin_Global_Theme_Toggle') {
             $file = $base_dir . 'Admin/GlobalThemeToggle.php';
        } elseif ($class === 'DDB_Spots_Admin_Bulk_Csv_Sync_Page') {
             $file = $base_dir . 'Admin/BulkCsvSyncPage.php';
        } elseif ($class === 'DDB_Spots_Integrations_Spot_Vendor_Bridge') {
             $file = $base_dir . 'Integrations/SpotVendorBridge.php';
        } elseif ($class === 'DDB_Spots_Frontend_Render') {
             $file = $base_dir . 'Frontend/Render.php';
        } elseif ($class === 'DDB_Spots_Core_Installer') {
             $file = $base_dir . 'Core/Installer.php';
        } elseif ($class === 'DDB_Spots_Core_Roles') {
             $file = $base_dir . 'Core/Roles.php';
        } elseif ($class === 'DDB_Spots_Core_Container') {
             $file = $base_dir . 'Core/Container.php';
        } elseif ($class === 'DDB_Spots_Core_Schema') {
             $file = $base_dir . 'Core/Schema.php';
        } elseif ($class === 'DDB_Spots_Domain_Spot_Repository') {
             $file = $base_dir . 'Domain/SpotRepository.php';
        } elseif ($class === 'DDB_Spots_Domain_Event_Repository') {
             $file = $base_dir . 'Domain/EventRepository.php';
        } elseif ($class === 'DDB_Spots_Domain_Audit_Repository') {
             $file = $base_dir . 'Domain/AuditRepository.php';
        } elseif ($class === 'DDB_Spots_Service_Rate_Limiter') {
             $file = $base_dir . 'Services/RateLimiter.php';
        } elseif ($class === 'DDB_Spots_Service_Quality_Policy') {
             $file = $base_dir . 'Services/QualityPolicy.php';
        } elseif ($class === 'DDB_Spots_Service_Suggest_Service') {
             $file = $base_dir . 'Services/SuggestService.php';
        } elseif ($class === 'DDB_Spots_Service_Canonical_Sync') {
             $file = $base_dir . 'Services/CanonicalSync.php';
        }
    }

    if (file_exists($file)) {
        require_once $file;
    }
});

require_once DDB_SPOTS_PATH . 'includes/Core/Container.php';
require_once DDB_SPOTS_PATH . 'includes/class-ddb-spots.php';

function ddb_spots_activate(): void {
	DDB_Spots_Core_Roles::setup();
	DDB_Spots_Core_Installer::activate();
	$plugin = new DDB_Spots();
	$business_registry = new DDB_Spots_Business_Registry();
	$plugin->register_content_model();
	$business_registry->register_post_type();
	DDB_Spots_Cron_Sync_Service::activate();
	flush_rewrite_rules();
}

function ddb_spots_deactivate(): void {
	DDB_Spots_Cron_Sync_Service::deactivate();
	flush_rewrite_rules();
}

register_activation_hook(DDB_SPOTS_FILE, 'ddb_spots_activate');
register_deactivation_hook(DDB_SPOTS_FILE, 'ddb_spots_deactivate');

/**
 * Keep Spots engine screens available to administrators even if the custom
 * role caps were not persisted correctly during a broken plugin/runtime state.
 */
function ddb_spots_grant_admin_engine_caps(array $allcaps, array $caps, array $args, WP_User $user): array {
	unset($caps, $args, $user);

	if (! empty($allcaps['manage_options'])) {
		$allcaps[DDB_Spots_Core_Roles::CAP_MANAGE_ENGINE] = true;
		$allcaps[DDB_Spots_Core_Roles::CAP_VIEW_INSIGHTS] = true;
	}

	return $allcaps;
}
add_filter('user_has_cap', 'ddb_spots_grant_admin_engine_caps', 10, 4);

function ddb_spots_get_container(): DDB_Spots_Core_Container {
	static $container = null;
	if ($container === null) {
		$container = new DDB_Spots_Core_Container();
		
		// Core & Repositories
		$container->set('plugin', fn() => new DDB_Spots());
		$container->set('spot_repo', fn() => new DDB_Spots_Domain_Spot_Repository());
		$container->set('event_repo', fn() => new DDB_Spots_Domain_Event_Repository());
		$container->set('audit_repo', fn() => new DDB_Spots_Domain_Audit_Repository());

		// Services
		$container->set('rate_limiter', fn() => new DDB_Spots_Service_Rate_Limiter());
		$container->set('quality_policy', fn() => new DDB_Spots_Service_Quality_Policy());
		$container->set('suggest_service', fn($c) => new DDB_Spots_Service_Suggest_Service($c->get('spot_repo'), $c->get('event_repo')));
		$container->set('canonical_sync', fn($c) => new DDB_Spots_Service_Canonical_Sync($c->get('spot_repo'), $c->get('audit_repo')));
		$container->set('google_places', fn() => new DDB_Spots_Integrations_Google_Places());
                $container->set('cron_sync', fn($c) => new DDB_Spots_Cron_Sync_Service($c->get('google_places')));

		// Admin & Frontend
		$container->set('api', fn($c) => new DDB_Spots_Rest_Api($c->get('spot_repo'), $c->get('event_repo'), $c->get('audit_repo'), $c->get('suggest_service'), $c->get('rate_limiter'), $c->get('quality_policy')));
		$container->set('settings_page', fn() => new DDB_Spots_Admin_Settings_Page());
		$container->set('editor_tabs', fn($c) => new DDB_Spots_Admin_Editor_Tabs($c->get('plugin')));
		$container->set('spot_health', fn($c) => new DDB_Spots_Admin_Spot_Health($c->get('quality_policy')));
		$container->set('insights', fn($c) => new DDB_Spots_Admin_Insights_Page($c->get('event_repo'), $c->get('audit_repo')));
		$container->set('sync_dashboard', fn() => new DDB_Spots_Admin_Sync_Dashboard());
		$container->set('theme_toggle', fn() => new DDB_Spots_Admin_Global_Theme_Toggle());
		$container->set('bulk_csv_sync', fn() => new DDB_Spots_Admin_Bulk_Csv_Sync_Page());
		$container->set('business_registry', fn() => new DDB_Spots_Business_Registry());
		$container->set('business_linker', fn() => new DDB_Spots_Business_Linker());
		$container->set('premium_analytics', fn() => new DDB_Spots_Premium_Analytics());
		$container->set('premium_engine', fn() => new DDB_Spots_Premium_Engine());
		$container->set('frontend_render', fn($c) => new DDB_Spots_Frontend_Render($c->get('plugin')));
		$container->set('booking', fn() => new DDB_Spots_Booking());
		$container->set('installer', fn() => new DDB_Spots_Core_Installer());
		$container->set('vendor_bridge', fn() => new DDB_Spots_Integrations_Spot_Vendor_Bridge());
	}
	return $container;
}

add_action(
	'plugins_loaded',
	static function (): void {
		DDB_Spots_Core_Roles::setup();
		
		$container = ddb_spots_get_container();
		
		// Initialize all components
		$container->get('installer')->init();
		$container->get('settings_page')->init();
		$container->get('editor_tabs')->init();
		$container->get('spot_health')->init();
		$container->get('insights')->init();
		$container->get('sync_dashboard')->init();
		$container->get('theme_toggle')->init();
		$container->get('bulk_csv_sync')->init();
		$container->get('business_registry')->init();
		if (method_exists($container, 'has') && $container->has('business_linker')) {
			$container->get('business_linker')->init();
		}
		$container->get('premium_analytics')->init();
		$container->get('premium_engine')->init();
		$container->get('booking')->init();
		if (method_exists($container, 'has') && $container->has('vendor_bridge')) {
			$container->get('vendor_bridge')->init();
		}
		$container->get('google_places')->init();
		$container->get('cron_sync')->init();
		$container->get('canonical_sync')->init();
		$container->get('api')->init();
		$container->get('plugin')->init();
		$container->get('frontend_render')->init();
	}
);
// Added AJAX route
add_action('wp_ajax_ddb_spots_get_media_preview', function() {
    check_ajax_referer('ddb_spots_media_preview', 'nonce');

    if (! current_user_can('edit_posts')) {
        wp_send_json_error(array('message' => __('Geen toestemming.', 'ddb-spots')), 403);
    }

    $ids = isset($_POST['ids']) ? sanitize_text_field($_POST['ids']) : '';
    if (empty($ids)) {
        wp_send_json_error();
    }
    $id_array = array_values(array_filter(array_map('absint', array_map('trim', explode(',', $ids)))));
    $data = [];
    foreach ($id_array as $id) {
        $url = wp_get_attachment_image_url($id, 'thumbnail');
        if ($url) {
            $data[] = [
                'id' => $id,
                'url' => $url
            ];
        }
    }
    wp_send_json_success($data);
});
