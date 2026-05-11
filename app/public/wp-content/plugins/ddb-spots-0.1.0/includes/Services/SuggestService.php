<?php
if (! defined('ABSPATH')) {
	exit;
}

class DDB_Spots_Service_Suggest_Service {
	private DDB_Spots_Domain_Spot_Repository $spots;
	private DDB_Spots_Domain_Event_Repository $events;

	public function __construct(DDB_Spots_Domain_Spot_Repository $spots, DDB_Spots_Domain_Event_Repository $events) {
		$this->spots = $spots;
		$this->events = $events;
	}

	public function suggest(array $input): array {
		$intent = sanitize_key((string) ($input['intent'] ?? ''));
		$area = sanitize_key((string) ($input['area'] ?? ''));
		$duration = absint((int) ($input['duration'] ?? 0));
		$lat = $this->to_float($input['lat'] ?? null);
		$lng = $this->to_float($input['lng'] ?? null);

		$candidates = $this->spots->get_candidates('' !== $area ? $area : null);
		if (empty($candidates)) {
			return array('primary' => null, 'alternatives' => array());
		}

		$spot_ids = array_map(static fn(array $row): int => (int) $row['id'], $candidates);
		$popularity = $this->events->popularity_scores($spot_ids, 30);
		$weights = $this->get_weights();

		$scored = array();
		foreach ($candidates as $row) {
			$type_score = $this->type_match_score($intent, (string) ($row['type'] ?? ''));
			$distance_score = $this->distance_score($lat, $lng, $row);
			$duration_score = $this->duration_fit_score($duration, (int) ($row['duration_hint'] ?? 0));
			$popularity_score = (int) ($popularity[ (int) $row['id'] ] ?? 0);
			$margin_score = $this->margin_score((string) ($row['price_band'] ?? ''));
			$priority_score = max(0, min(100, (int) ($row['priority_score'] ?? 0)));
			$conversion_bonus = $type_score * 0.3 + $duration_score * 0.2;

			$final_score = $this->weighted_sum(
				array(
					'distance' => $distance_score,
					'popularity' => $popularity_score,
					'margin' => $margin_score,
					'priority' => $priority_score + $conversion_bonus,
				),
				$weights
			);
			$row['score'] = round($final_score, 3);
			$scored[] = $row;
		}

		usort(
			$scored,
			static function (array $a, array $b): int {
				$sa = (float) ($a['score'] ?? 0.0);
				$sb = (float) ($b['score'] ?? 0.0);
				if ($sa === $sb) {
					return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
				}
				return ($sb <=> $sa);
			}
		);

		return array(
			'primary' => $scored[0] ?? null,
			'alternatives' => array_values(array_slice($scored, 1, 3)),
		);
	}

	private function get_weights(): array {
		$config = DDB_Spots_Admin_Settings_Page::get_config();
		$saved = isset($config['ranking_visibility']['weights']) && is_array($config['ranking_visibility']['weights']) ? $config['ranking_visibility']['weights'] : array();
		$defaults = array(
			'distance' => 30.0,
			'popularity' => 25.0,
			'margin' => 20.0,
			'priority' => 25.0,
		);
		foreach ($defaults as $key => $default) {
			if (isset($saved[ $key ])) {
				$defaults[ $key ] = max(0.0, min(100.0, (float) $saved[ $key ]));
			}
		}
		return $defaults;
	}

	private function weighted_sum(array $scores, array $weights): float {
		$total = 0.0;
		$total_weight = 0.0;
		foreach ($scores as $key => $score) {
			$weight = (float) ($weights[ $key ] ?? 0.0);
			if ($weight <= 0.0) {
				continue;
			}
			$total += max(0.0, min(100.0, (float) $score)) * $weight;
			$total_weight += $weight;
		}
		if ($total_weight <= 0.0) {
			return 0.0;
		}
		return $total / $total_weight;
	}

	private function type_match_score(string $intent, string $type): float {
		if ('' === $intent || '' === $type) {
			return 50.0;
		}
		if ($intent === $type) {
			return 100.0;
		}
		return str_contains($intent, $type) || str_contains($type, $intent) ? 80.0 : 20.0;
	}

	private function duration_fit_score(int $requested, int $hint): float {
		if ($requested <= 0 || $hint <= 0) {
			return 50.0;
		}
		$delta = abs($requested - $hint);
		return max(0.0, 100.0 - min(100.0, ($delta * 2.5)));
	}

	private function margin_score(string $price_band): float {
		$price_band = trim($price_band);
		if ('' === $price_band) {
			return 40.0;
		}
		$level = (int) preg_replace('/[^0-9]/', '', $price_band);
		return max(10.0, min(100.0, 25.0 * max(1, $level)));
	}

	private function distance_score(?float $origin_lat, ?float $origin_lng, array $row): float {
		$lat = isset($row['lat']) ? (float) $row['lat'] : null;
		$lng = isset($row['lng']) ? (float) $row['lng'] : null;
		if (null === $origin_lat || null === $origin_lng || null === $lat || null === $lng) {
			return 50.0;
		}
		$km = $this->haversine_km($origin_lat, $origin_lng, $lat, $lng);
		return max(0.0, min(100.0, 100.0 - ($km * 2.0)));
	}

	private function to_float($value): ?float {
		if (null === $value || '' === $value) {
			return null;
		}
		$value = str_replace(',', '.', (string) $value);
		return is_numeric($value) ? (float) $value : null;
	}

	private function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
		$earth_radius = 6371.0;
		$d_lat = deg2rad($lat2 - $lat1);
		$d_lng = deg2rad($lng2 - $lng1);
		$a = sin($d_lat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($d_lng / 2) ** 2;
		$c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
		return $earth_radius * $c;
	}
}

