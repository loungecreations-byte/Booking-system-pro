<?php
if (! defined('ABSPATH')) {
	exit;
}

class DDB_Spots_Core_Roles {
	public const CAP_VIEW_INSIGHTS = 'ddb_spots_view_insights';
	public const CAP_MANAGE_ENGINE = 'ddb_spots_manage_engine';
	public const ROLE_ANALYST = 'ddb_spots_analyst';

	public static function setup(): void {
		self::ensure_caps_for_role('administrator', array(self::CAP_VIEW_INSIGHTS, self::CAP_MANAGE_ENGINE));
		self::ensure_caps_for_role('editor', array(self::CAP_VIEW_INSIGHTS));

		$analyst_caps = array(
			'read' => true,
			self::CAP_VIEW_INSIGHTS => true,
		);
		$role = get_role(self::ROLE_ANALYST);
		if (! $role) {
			add_role(self::ROLE_ANALYST, 'DDB Spots Analyst', $analyst_caps);
		} else {
			foreach ($analyst_caps as $cap => $grant) {
				if ($grant) {
					$role->add_cap($cap);
				}
			}
		}
	}

	private static function ensure_caps_for_role(string $role_name, array $caps): void {
		$role = get_role($role_name);
		if (! $role instanceof WP_Role) {
			return;
		}
		foreach ($caps as $cap) {
			$role->add_cap($cap);
		}
	}
}

