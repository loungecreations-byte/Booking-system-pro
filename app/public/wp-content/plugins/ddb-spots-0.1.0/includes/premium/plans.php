<?php
if (! defined('ABSPATH')) {
	exit;
}

if (! function_exists('ddb_spots_premium_plan_definitions')) {
	function ddb_spots_premium_plan_definitions(): array {
		return array(
			'free' => array(
				'label' => __('Free', 'ddb-spots'),
				'description' => __('Basisvermelding zonder premium-boost.', 'ddb-spots'),
				'entitlements' => array(
					'media_limit' => 3,
					'analytics' => false,
					'top_picks' => false,
					'sponsored' => false,
					'ranking_boost' => 0.0,
					'modules' => array(),
				),
			),
			'presence' => array(
				'label' => __('Presence', 'ddb-spots'),
				'description' => __('Meer zichtbaarheid, zonder conversion-modules.', 'ddb-spots'),
				'entitlements' => array(
					'media_limit' => 8,
					'analytics' => true,
					'top_picks' => false,
					'sponsored' => false,
					'ranking_boost' => 0.05,
					'modules' => array(),
				),
			),
			'conversion' => array(
				'label' => __('Conversion', 'ddb-spots'),
				'description' => __('Conversie modules met beperkte ranking-boost.', 'ddb-spots'),
				'entitlements' => array(
					'media_limit' => 16,
					'analytics' => true,
					'top_picks' => false,
					'sponsored' => true,
					'ranking_boost' => 0.12,
					'modules' => array('cta_variant', 'highlight_badge'),
				),
			),
			'partner' => array(
				'label' => __('Partner', 'ddb-spots'),
				'description' => __('Volledig pakket met modules en Top Picks toegang.', 'ddb-spots'),
				'entitlements' => array(
					'media_limit' => 30,
					'analytics' => true,
					'top_picks' => true,
					'sponsored' => true,
					'ranking_boost' => 0.20,
					'modules' => array('cta_variant', 'highlight_badge', 'lead_form'),
				),
			),
		);
	}
}

if (! function_exists('ddb_spots_normalize_plan_key')) {
	function ddb_spots_normalize_plan_key(string $plan_key): string {
		$plan_key = sanitize_key($plan_key);
		$plans = ddb_spots_premium_plan_definitions();
		return isset($plans[ $plan_key ]) ? $plan_key : 'free';
	}
}

if (! function_exists('ddb_spots_plan_entitlements')) {
	function ddb_spots_plan_entitlements(string $plan_key): array {
		$plans = ddb_spots_premium_plan_definitions();
		$plan_key = ddb_spots_normalize_plan_key($plan_key);
		$row = isset($plans[ $plan_key ]) && is_array($plans[ $plan_key ]) ? $plans[ $plan_key ] : array();
		$entitlements = isset($row['entitlements']) && is_array($row['entitlements']) ? $row['entitlements'] : array();

		return array(
			'media_limit' => max(0, (int) ($entitlements['media_limit'] ?? 0)),
			'analytics' => ! empty($entitlements['analytics']),
			'top_picks' => ! empty($entitlements['top_picks']),
			'sponsored' => ! empty($entitlements['sponsored']),
			'ranking_boost' => max(0.0, min(1.0, (float) ($entitlements['ranking_boost'] ?? 0.0))),
			'modules' => array_values(array_filter(array_map('sanitize_key', (array) ($entitlements['modules'] ?? array())))),
		);
	}
}
