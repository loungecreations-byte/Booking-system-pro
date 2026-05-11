<?php
if (! defined('ABSPATH')) {
	exit;
}

if (! function_exists('ddb_spot_health_details')) {
	function ddb_spot_health_details(int $spot_id): array {
		$post = get_post($spot_id);
		if (! $post instanceof WP_Post || 'ddb_spot' !== $post->post_type) {
			return array(
				'score' => 0,
				'missing' => array(__('Spot niet gevonden.', 'ddb-spots')),
				'checks' => array(),
			);
		}

		$policy = new DDB_Spots_Service_Quality_Policy();
		$config = DDB_Spots_Admin_Settings_Page::get_config();
		$checks = $policy->build_checks_for_post($post, $config);
		$total = count($checks);
		$passed = 0;
		$missing = array();
		foreach ($checks as $check) {
			if (! empty($check['ok'])) {
				$passed++;
				continue;
			}
			$missing[] = (string) ($check['label'] ?? '');
		}

		$score = $total > 0 ? (int) round(($passed / $total) * 100) : 0;

		return array(
			'score' => max(0, min(100, $score)),
			'missing' => array_values(array_filter(array_map('strval', $missing))),
			'checks' => $checks,
		);
	}
}

if (! function_exists('ddb_spot_health_score')) {
	function ddb_spot_health_score(int $spot_id): int {
		$details = ddb_spot_health_details($spot_id);
		return max(0, min(100, (int) ($details['score'] ?? 0)));
	}
}

if (! function_exists('ddb_spot_cta_targets')) {
	function ddb_spot_cta_targets(int $spot_id): array {
		$provider = (string) get_post_meta($spot_id, '_ddb_booking_provider', true);
		$cta = (string) get_post_meta($spot_id, '_ddb_cta_url', true);
		$generic = (string) get_post_meta($spot_id, '_ddb_spot_cta_url', true);
		$event = (string) get_post_meta($spot_id, '_ddb_event_ticket_url', true);
		$hotel = (string) get_post_meta($spot_id, '_ddb_hotel_booking_url', true);
		$restaurant = (string) get_post_meta($spot_id, '_ddb_restaurant_booking_url', true);
		$formitable_venue = (string) get_post_meta($spot_id, '_ddb_formitable_venue_id', true);
		$formitable_widget = (string) get_post_meta($spot_id, '_ddb_formitable_widget_id', true);
		$formitable_embed = (string) get_post_meta($spot_id, '_ddb_formitable_embed', true);
		$formitable_embed_raw = (string) get_post_meta($spot_id, '_ddb_formitable_embed_raw', true);
		$type = ddb_spot_primary_type($spot_id);

		$primary = '';
		if (in_array($type, array('event', 'events'), true)) {
			$primary = in_array($provider, array('external', 'ticket'), true) && '' !== trim($cta) ? $cta : $event;
		} elseif (in_array($type, array('hotel', 'hotels'), true)) {
			$primary = in_array($provider, array('external', 'ticket'), true) && '' !== trim($cta) ? $cta : $hotel;
		} elseif (in_array($type, array('restaurant', 'restaurants'), true)) {
			if ('formitable' === $provider) {
				$has_formitable = '' !== trim($formitable_venue) || '' !== trim($formitable_widget) || '' !== trim($formitable_embed) || '' !== trim($formitable_embed_raw);
				$primary = $has_formitable ? '#restaurant-widget' : '';
			} else {
				if (in_array($provider, array('external', 'ticket'), true) && '' !== trim($cta)) {
					$primary = $cta;
				} elseif ('' !== trim($restaurant)) {
					$primary = $restaurant;
				} else {
					$primary = $generic;
				}
			}
		} else {
			$primary = '' !== trim($cta) ? $cta : $generic;
		}

		return array(
			'primary' => (string) $primary,
			'generic' => $generic,
			'event' => $event,
			'hotel' => $hotel,
			'restaurant' => $restaurant,
			'provider' => $provider,
		);
	}
}

if (! function_exists('ddb_spot_primary_type')) {
	function ddb_spot_primary_type(int $spot_id): string {
		$override = sanitize_title((string) get_post_meta($spot_id, '_ddb_spot_type_primary', true));
		if ('' !== $override) {
			return $override;
		}
		$terms = wp_get_post_terms($spot_id, 'ddb_spot_type', array('fields' => 'slugs'));
		if (is_wp_error($terms) || empty($terms)) {
			return '';
		}
		return (string) $terms[0];
	}
}

if (! function_exists('ddb_spot_base_score')) {
	function ddb_spot_base_score(int $spot_id, array $context = array()): float {
		$origin = ddb_spot_origin_context($context);
		$distance_score = ddb_spot_distance_score($spot_id, $origin['lat'], $origin['lng']);
		$health = (float) ddb_spot_health_score($spot_id);
		$targets = ddb_spot_cta_targets($spot_id);
		$availability = '' !== trim((string) ($targets['primary'] ?? '')) ? 100.0 : 0.0;
		$manual_priority = max(0.0, min(100.0, (float) get_post_meta($spot_id, '_ddb_priority', true)));

		$base = (
			($distance_score * 0.25) +
			($availability * 0.35) +
			($health * 0.30) +
			($manual_priority * 0.10)
		);

		return max(0.0, min(100.0, $base));
	}
}

if (! function_exists('ddb_spot_final_score')) {
	function ddb_spot_final_score(int $spot_id, array $context = array()): float {
		$base = ddb_spot_base_score($spot_id, $context);
		$threshold = (int) ddb_spots_premium_setting('relevance_threshold', 60);
		if ($base < max(0, min(100, $threshold))) {
			return $base;
		}

		$plan = ddb_spots_get_spot_plan_info($spot_id);
		if (empty($plan['is_paid_active'])) {
			return $base;
		}

		$entitlements = isset($plan['entitlements']) && is_array($plan['entitlements']) ? $plan['entitlements'] : array();
		$boost = max(0.0, min(1.0, (float) ($entitlements['ranking_boost'] ?? 0.0)));
		if ($boost <= 0.0) {
			return $base;
		}

		$cap = (float) ddb_spots_premium_setting('boost_cap', 1.2);
		$cap = max(1.0, min(2.0, $cap));
		$max_boost = max(0.0, $cap - 1.0);
		$applied = min($boost, $max_boost);

		$final = $base * (1.0 + $applied);
		$max_final = $base * $cap;
		return min($final, $max_final);
	}
}

if (! function_exists('ddb_spot_ranking_debug')) {
	function ddb_spot_ranking_debug(int $spot_id, array $context = array()): array {
		$base = ddb_spot_base_score($spot_id, $context);
		$final = ddb_spot_final_score($spot_id, $context);
		$cap = (float) ddb_spots_premium_setting('boost_cap', 1.2);
		$threshold = (int) ddb_spots_premium_setting('relevance_threshold', 60);
		$health = ddb_spot_health_score($spot_id);
		$plan = ddb_spots_get_spot_plan_info($spot_id);

		return array(
			'base_score' => round($base, 4),
			'final_score' => round($final, 4),
			'multiplier' => $base > 0.0 ? round($final / $base, 4) : 1.0,
			'boost_cap' => round(max(1.0, min(2.0, $cap)), 4),
			'threshold' => max(0, min(100, $threshold)),
			'threshold_met' => $base >= max(0, min(100, $threshold)),
			'health_score' => $health,
			'plan_key' => (string) ($plan['plan_key'] ?? 'free'),
			'paid_active' => ! empty($plan['is_paid_active']),
		);
	}
}

if (! function_exists('ddb_spot_origin_context')) {
	function ddb_spot_origin_context(array $context): array {
		$lat = null;
		$lng = null;
		if (isset($context['lat']) && is_scalar($context['lat']) && is_numeric((string) $context['lat'])) {
			$lat = (float) $context['lat'];
		}
		if (isset($context['lng']) && is_scalar($context['lng']) && is_numeric((string) $context['lng'])) {
			$lng = (float) $context['lng'];
		}
		if (isset($context['origin_lat']) && is_scalar($context['origin_lat']) && is_numeric((string) $context['origin_lat'])) {
			$lat = (float) $context['origin_lat'];
		}
		if (isset($context['origin_lng']) && is_scalar($context['origin_lng']) && is_numeric((string) $context['origin_lng'])) {
			$lng = (float) $context['origin_lng'];
		}

		return array('lat' => $lat, 'lng' => $lng);
	}
}

if (! function_exists('ddb_spot_distance_score')) {
	function ddb_spot_distance_score(int $spot_id, ?float $origin_lat, ?float $origin_lng): float {
		if (null === $origin_lat || null === $origin_lng) {
			return 50.0;
		}
		$lat = ddb_spot_meta_float($spot_id, '_ddb_lat');
		$lng = ddb_spot_meta_float($spot_id, '_ddb_lng');
		if (null === $lat || null === $lng) {
			return 0.0;
		}

		$distance = ddb_spot_haversine_km($origin_lat, $origin_lng, $lat, $lng);
		$score = 100.0 - (2.0 * $distance);
		return max(0.0, min(100.0, $score));
	}
}

if (! function_exists('ddb_spot_meta_float')) {
	function ddb_spot_meta_float(int $spot_id, string $meta_key): ?float {
		$value = trim(str_replace(',', '.', (string) get_post_meta($spot_id, $meta_key, true)));
		if ('' === $value || ! is_numeric($value)) {
			return null;
		}
		return (float) $value;
	}
}

if (! function_exists('ddb_spot_haversine_km')) {
	function ddb_spot_haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
		$earth_radius = 6371.0;
		$d_lat = deg2rad($lat2 - $lat1);
		$d_lng = deg2rad($lng2 - $lng1);
		$a = sin($d_lat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($d_lng / 2) ** 2;
		$c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
		return $earth_radius * $c;
	}
}
