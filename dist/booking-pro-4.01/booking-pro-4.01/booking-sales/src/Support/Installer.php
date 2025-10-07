<?php

declare(strict_types=1);

namespace BSP\Sales\Support;

use BSP\Sales\Pricing\YieldEngine;
use BSP\Sales\Promotions\PromotionsService;
use wpdb;
use function current_time;
use function dbDelta;
use function get_role;
use function wp_json_encode;

final class Installer
{
    public static function install(): void
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charsetCollate = $wpdb->get_charset_collate();

        $schemas = [
            "CREATE TABLE {$wpdb->prefix}bsp_products (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                product_id BIGINT(20) UNSIGNED NOT NULL,
                external_sku VARCHAR(190) NOT NULL DEFAULT '',
                vendor_id BIGINT(20) UNSIGNED NULL,
                data LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY product_id (product_id),
                KEY vendor_id (vendor_id)
            ) {$charsetCollate};",
            "CREATE TABLE {$wpdb->prefix}bsp_yield_rules (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(190) NOT NULL,
                condition_json LONGTEXT NOT NULL,
                adjustment_json LONGTEXT NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                priority SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY active (active),
                KEY priority (priority)
            ) {$charsetCollate};",
            "CREATE TABLE {$wpdb->prefix}bsp_price_log (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                product_id BIGINT(20) UNSIGNED NOT NULL,
                rule_id BIGINT(20) UNSIGNED NULL,
                context LONGTEXT NULL,
                base_price DECIMAL(12,4) NOT NULL,
                adjusted_price DECIMAL(12,4) NOT NULL,
                channel VARCHAR(190) NULL,
                logged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY product_id (product_id),
                KEY rule_id (rule_id)
            ) {$charsetCollate};",
            "CREATE TABLE {$wpdb->prefix}bsp_channels (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(190) NOT NULL,
                api_key TEXT NOT NULL,
                settings LONGTEXT NULL,
                commission_rate DECIMAL(6,2) NOT NULL DEFAULT 0.00,
                sync_status VARCHAR(50) NOT NULL DEFAULT 'idle',
                last_sync DATETIME NULL,
                last_error TEXT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY active (active)
            ) {$charsetCollate};",
            "CREATE TABLE {$wpdb->prefix}bsp_vendors (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(190) NOT NULL,
                slug VARCHAR(190) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                channels LONGTEXT NULL,
                capabilities LONGTEXT NULL,
                payout_terms VARCHAR(100) NULL,
                commission_rate DECIMAL(6,2) NULL,
                contact_name VARCHAR(190) NULL,
                contact_email VARCHAR(190) NULL,
                contact_phone VARCHAR(50) NULL,
                webhook_url TEXT NULL,
                pricing_currency VARCHAR(10) NULL,
                pricing_base_rate DECIMAL(12,2) NULL,
                pricing_markup_type VARCHAR(20) NULL,
                pricing_markup_value DECIMAL(12,2) NULL,
                metadata LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY slug (slug),
                KEY status (status)
            ) {$charsetCollate};",
            "CREATE TABLE {$wpdb->prefix}bsp_promotions (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                code VARCHAR(64) NOT NULL,
                name VARCHAR(190) NOT NULL,
                description TEXT NULL,
                type VARCHAR(50) NOT NULL,
                channel_scope LONGTEXT NULL,
                booking_scope LONGTEXT NULL,
                reward_payload LONGTEXT NULL,
                stacking_policy VARCHAR(50) NOT NULL DEFAULT 'single',
                status VARCHAR(20) NOT NULL DEFAULT 'draft',
                starts_at DATETIME NULL,
                ends_at DATETIME NULL,
                created_by BIGINT(20) UNSIGNED NULL,
                updated_by BIGINT(20) UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY code (code),
                KEY status (status),
                KEY ends_at (ends_at)
            ) {$charsetCollate};",
            "CREATE TABLE {$wpdb->prefix}bsp_promotion_audit (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                promotion_id BIGINT(20) UNSIGNED NOT NULL,
                changed_by BIGINT(20) UNSIGNED NULL,
                change_type VARCHAR(50) NOT NULL,
                payload_before LONGTEXT NULL,
                payload_after LONGTEXT NULL,
                note TEXT NULL,
                changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY promotion_id (promotion_id),
                KEY changed_at (changed_at)
            ) {$charsetCollate};",
            "CREATE TABLE {$wpdb->prefix}bsp_funnel_events (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                session_id VARCHAR(100) NOT NULL,
                customer_id BIGINT(20) UNSIGNED NULL,
                channel VARCHAR(100) NULL,
                outlet_id BIGINT(20) UNSIGNED NULL,
                step VARCHAR(100) NOT NULL,
                step_payload LONGTEXT NULL,
                utm_source VARCHAR(190) NULL,
                utm_medium VARCHAR(190) NULL,
                utm_campaign VARCHAR(190) NULL,
                promotion_id BIGINT(20) UNSIGNED NULL,
                occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY session_step (session_id, step),
                KEY occurred_at (occurred_at)
            ) {$charsetCollate};",
            "CREATE TABLE {$wpdb->prefix}bsp_funnel_sequences (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                session_id VARCHAR(100) NOT NULL,
                funnel_version VARCHAR(100) NOT NULL DEFAULT '',
                first_step_at DATETIME NULL,
                last_step_at DATETIME NULL,
                completed TINYINT(1) NOT NULL DEFAULT 0,
                completion_reason VARCHAR(190) NULL,
                promotion_id BIGINT(20) UNSIGNED NULL,
                loyalty_points_earned BIGINT(20) NULL,
                revenue_value DECIMAL(12,2) NULL,
                currency VARCHAR(10) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY session_id (session_id)
            ) {$charsetCollate};",
            "CREATE TABLE {$wpdb->prefix}bsp_loyalty_accounts (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                customer_id BIGINT(20) UNSIGNED NOT NULL,
                program_status VARCHAR(50) NOT NULL DEFAULT 'standard',
                points_balance BIGINT(20) NOT NULL DEFAULT 0,
                lifetime_points BIGINT(20) NOT NULL DEFAULT 0,
                outlet_caps LONGTEXT NULL,
                terms_version VARCHAR(50) NULL,
                last_reward_redeem_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY customer_id (customer_id)
            ) {$charsetCollate};",
            "CREATE TABLE {$wpdb->prefix}bsp_loyalty_ledger (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                account_id BIGINT(20) UNSIGNED NOT NULL,
                event_type VARCHAR(50) NOT NULL,
                reference_id VARCHAR(100) NULL,
                promotion_id BIGINT(20) UNSIGNED NULL,
                points_delta BIGINT(20) NOT NULL DEFAULT 0,
                note TEXT NULL,
                created_by BIGINT(20) UNSIGNED NULL,
                effective_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY account_event (account_id, event_type),
                KEY reference_id (reference_id)
            ) {$charsetCollate};",
        ];

        foreach ($schemas as $sql) {
            dbDelta($sql);
        }

        self::seedChannels($wpdb->prefix . 'bsp_channels');
        self::seedVendors($wpdb->prefix . 'bsp_vendors');
        self::grantCapabilities();
        YieldEngine::flushRuleCache();
    }

    public static function uninstall(): void
    {
        global $wpdb;
        if ($wpdb instanceof wpdb) {
            foreach ([
                'bsp_products',
                'bsp_yield_rules',
                'bsp_price_log',
                'bsp_channels',
                'bsp_vendors',
                'bsp_promotions',
                'bsp_promotion_audit',
                'bsp_funnel_events',
                'bsp_funnel_sequences',
                'bsp_loyalty_accounts',
                'bsp_loyalty_ledger',
            ] as $table) {
                $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
            }
        }

        $role = get_role('administrator');
        if ($role instanceof \WP_Role) {
            $role->remove_cap('manage_bsp_sales');
            $role->remove_cap(PromotionsService::CAPABILITY);
        }
    }

    private static function seedChannels(string $table): void
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return;
        }

        $existing = (int) $wpdb->get_var("SELECT COUNT(id) FROM {$table}");
        if ($existing > 0) {
            return;
        }

        foreach ([
            ['GetYourGuide', 18.50],
            ['Viator', 15.00],
            ['Tripadvisor Experiences', 12.00],
        ] as [$name, $commission]) {
            $wpdb->insert(
                $table,
                [
                    'name'            => $name,
                    'api_key'         => '',
                    'settings'        => wp_json_encode(['mode' => 'manual']),
                    'commission_rate' => $commission,
                    'sync_status'     => 'idle',
                    'last_sync'       => null,
                    'last_error'      => null,
                    'active'          => 1,
                    'created_at'      => current_time('mysql', true),
                ]
            );
        }
    }

    private static function seedVendors(string $table): void
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return;
        }

        $existing = (int) $wpdb->get_var("SELECT COUNT(id) FROM {$table}");
        if ($existing > 0) {
            return;
        }

        foreach ([
            [
                'slug'             => 'direct-outlet',
                'name'             => 'Direct Outlet',
                'status'           => 'active',
                'channels'         => ['direct'],
                'capabilities'     => ['inventory_push' => true, 'pricing_type' => 'gross'],
                'payout_terms'     => 'net-14',
                'commission_rate'  => 0.00,
                'contact_name'     => 'In-house Sales',
                'contact_email'    => 'sales@owncreations.com',
                'contact_phone'    => '+00 000 000 0000',
                'webhook_url'      => '',
                'pricing_currency' => 'USD',
                'pricing_base_rate' => 0.00,
                'pricing_markup_type' => null,
                'pricing_markup_value' => null,
                'metadata'         => ['tier' => 'primary'],
            ],
            [
                'slug'             => 'marketplace-syndicate',
                'name'             => 'Marketplace Syndicate',
                'status'           => 'pending',
                'channels'         => ['getyourguide', 'viator'],
                'capabilities'     => ['inventory_push' => false, 'pricing_type' => 'net'],
                'payout_terms'     => 'net-30',
                'commission_rate'  => 15.00,
                'contact_name'     => 'Partner Success',
                'contact_email'    => 'partners@owncreations.com',
                'contact_phone'    => '',
                'webhook_url'      => '',
                'pricing_currency' => 'USD',
                'pricing_base_rate' => 49.00,
                'pricing_markup_type' => 'percent',
                'pricing_markup_value' => 18.00,
                'metadata'         => ['tier' => 'aggregation'],
            ],
        ] as $vendor) {
            $wpdb->insert(
                $table,
                [
                    'name'            => $vendor['name'],
                    'slug'            => $vendor['slug'],
                    'status'          => $vendor['status'],
                    'channels'        => wp_json_encode($vendor['channels']),
                    'capabilities'    => wp_json_encode($vendor['capabilities']),
                    'payout_terms'    => $vendor['payout_terms'],
                    'commission_rate' => $vendor['commission_rate'],
                    'contact_name'    => $vendor['contact_name'],
                    'contact_email'   => $vendor['contact_email'],
                    'contact_phone'   => $vendor['contact_phone'],
                    'webhook_url'     => $vendor['webhook_url'],
                    'pricing_currency' => $vendor['pricing_currency'],
                    'pricing_base_rate' => $vendor['pricing_base_rate'],
                    'pricing_markup_type' => $vendor['pricing_markup_type'],
                    'pricing_markup_value' => $vendor['pricing_markup_value'],
                    'metadata'        => wp_json_encode($vendor['metadata']),
                    'created_at'      => current_time('mysql', true),
                ]
            );
        }
    }
    private static function grantCapabilities(): void
    {
        $role = get_role('administrator');
        if ($role instanceof \WP_Role) {
            $role->add_cap('manage_bsp_sales');
            $role->add_cap(PromotionsService::CAPABILITY);
        }
    }
}






