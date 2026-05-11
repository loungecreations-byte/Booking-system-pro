<?php

/**
 * Ensure core roles can access BSP sales REST endpoints.
 */

add_action(
    'init',
    static function () {
        foreach (array('administrator', 'shop_manager') as $roleName) {
            $role = get_role($roleName);
            if (! $role instanceof WP_Role) {
                continue;
            }

            if (! $role->has_cap('manage_bsp_sales')) {
                $role->add_cap('manage_bsp_sales');
            }
        }
    }
);
