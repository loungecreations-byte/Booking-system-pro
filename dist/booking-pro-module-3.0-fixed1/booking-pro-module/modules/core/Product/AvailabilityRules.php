<?php

declare(strict_types=1);

namespace BSPModule\Core\Product;

final class AvailabilityRules {

	public static function defaultRules(): array {
		return array(
			'default'          => 'open',
			'exclude_weekdays' => array(),
			'exclude_months'   => array(),
			'exclude_times'    => array(),
			'overrides'        => array(),
		);
	}
}
