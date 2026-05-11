<?php

/**
 * Ensure core roles can access Planboard v2 capabilities.
 */

if (! defined('SBDP_PLANBOARD_V2')) {
    define('SBDP_PLANBOARD_V2', true);
}

add_filter('bsp/planboard/v2_enabled', '__return_true');

add_action(
    'init',
    static function (): void {
        foreach (array('administrator', 'shop_manager') as $roleName) {
            $role = get_role($roleName);
            if (! $role instanceof WP_Role) {
                continue;
            }

            foreach (
                array(
                    'board.view',
                    'booking.move',
                    'booking.create',
                    'booking.checkin',
                    'payment.add',
                    'rules.manage',
                ) as $capability
            ) {
                if (! $role->has_cap($capability)) {
                    $role->add_cap($capability);
                }
            }
        }
    }
);

add_filter(
    'map_meta_cap',
    static function (array $caps, $cap, int $user_id, array $args): array {
        $cap = is_string($cap) ? $cap : '';
        $planboardCaps = array(
            'board.view',
            'booking.move',
            'booking.create',
            'booking.checkin',
            'payment.add',
            'rules.manage',
        );

        if (in_array($cap, $planboardCaps, true)) {
            $caps = array_merge($caps, array('manage_woocommerce', 'manage_options'));
        }

        return $caps;
    },
    1,
    4
);

add_filter(
    'bsp/planboard/capability_map',
    static function (array $caps, $capability): array {
        return array_unique(array_merge($caps, array('manage_woocommerce', 'manage_options')));
    },
    10,
    2
);
