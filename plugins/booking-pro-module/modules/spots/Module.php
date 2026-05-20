<?php

declare(strict_types=1);

namespace BSP\Spots;

use BSP\Core\Interfaces\ModuleInterface;

/**
 * Spots module — Patch A
 *
 * Adds partner/supplier meta and admin list columns to the ddb_spot post type.
 * ddb_spot is registered by the ddb-spots-0.1.0 plugin; this module extends it
 * from within booking-pro-module so plugin updates cannot overwrite these additions.
 *
 * SCOPE: admin-only, purely additive.
 * NO effect on BookingModeService, Quote OS, Vendor Portal, or any booking flow.
 */
if (! class_exists(__NAMESPACE__ . '\\Module', false)) {
    final class Module implements ModuleInterface
    {
        private static bool $bootstrapped = false;

        public function init(): void
        {
            if (self::$bootstrapped) {
                return;
            }
            self::$bootstrapped = true;

            // Guard: ddb_spot CPT must be registered (by ddb-spots-0.1.0).
            // We defer to 'init' so post_type_exists() is reliable.
            if (function_exists('add_action')) {
                add_action('init', [__CLASS__, 'maybeBootstrap'], 20);
            }
        }

        public static function maybeBootstrap(): void
        {
            if (! post_type_exists('ddb_spot')) {
                return;
            }

            // Only load admin classes in WP admin context.
            if (! is_admin()) {
                return;
            }

            (new SpotPartnerMetaModule())->init();
            (new SpotAdminColumns())->init();
        }
    }
}
