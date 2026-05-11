<?php

declare(strict_types=1);

namespace BSP\Bookings\Support;

use wpdb;

use function get_option;
use function update_option;
use function dbDelta;

final class Installer
{
    private const OPTION_SCHEMA_VERSION = 'bsp_bookings_operations_schema_version';
    private const SCHEMA_VERSION = '2026-04-05-4';

    public static function install(): void
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charsetCollate = $wpdb->get_charset_collate();

        $schemas = [
            "CREATE TABLE {$wpdb->prefix}bsp_booking_masters (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                booking_reference VARCHAR(100) NOT NULL,
                woo_order_id BIGINT(20) UNSIGNED NULL,
                legacy_booking_id BIGINT(20) UNSIGNED NULL,
                status VARCHAR(50) NOT NULL DEFAULT 'draft',
                legacy_status VARCHAR(50) NOT NULL DEFAULT '',
                booking_type VARCHAR(50) NOT NULL DEFAULT 'standard',
                commercial_status VARCHAR(50) NOT NULL DEFAULT '',
                commercial_currency VARCHAR(10) NOT NULL DEFAULT 'EUR',
                commercial_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                participants INT(10) UNSIGNED NOT NULL DEFAULT 0,
                customer_name VARCHAR(190) NOT NULL DEFAULT '',
                customer_email VARCHAR(190) NOT NULL DEFAULT '',
                booking_date DATE NULL,
                booking_time VARCHAR(20) NOT NULL DEFAULT '',
                booking_end_date DATE NULL,
                booking_end_time VARCHAR(20) NOT NULL DEFAULT '',
                channel VARCHAR(100) NOT NULL DEFAULT 'web',
                vendor_id BIGINT(20) UNSIGNED NULL,
                resource_ref VARCHAR(190) NOT NULL DEFAULT '',
                payload LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY booking_reference (booking_reference),
                KEY woo_order_id (woo_order_id),
                KEY status (status),
                KEY booking_date (booking_date),
                KEY channel (channel)
            ) {$charsetCollate};",
            "CREATE TABLE {$wpdb->prefix}bsp_booking_legs (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                master_id BIGINT(20) UNSIGNED NOT NULL,
                booking_reference VARCHAR(100) NOT NULL,
                woo_order_id BIGINT(20) UNSIGNED NULL,
                legacy_booking_id BIGINT(20) UNSIGNED NULL,
                leg_key VARCHAR(100) NOT NULL,
                leg_index SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
                status VARCHAR(50) NOT NULL DEFAULT 'draft',
                legacy_status VARCHAR(50) NOT NULL DEFAULT '',
                leg_type VARCHAR(50) NOT NULL DEFAULT 'activity',
                title VARCHAR(190) NOT NULL DEFAULT '',
                product_id BIGINT(20) UNSIGNED NULL,
                supplier_id BIGINT(20) UNSIGNED NULL,
                scheduled_date DATE NULL,
                scheduled_time VARCHAR(20) NOT NULL DEFAULT '',
                scheduled_end_date DATE NULL,
                scheduled_end_time VARCHAR(20) NOT NULL DEFAULT '',
                participants INT(10) UNSIGNED NOT NULL DEFAULT 0,
                payload LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY master_leg (master_id, leg_key),
                KEY booking_reference (booking_reference),
                KEY woo_order_id (woo_order_id),
                KEY status (status),
                KEY scheduled_date (scheduled_date)
            ) {$charsetCollate};",
            "CREATE TABLE {$wpdb->prefix}bsp_booking_events (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                master_id BIGINT(20) UNSIGNED NOT NULL,
                leg_id BIGINT(20) UNSIGNED NULL,
                booking_reference VARCHAR(100) NOT NULL,
                woo_order_id BIGINT(20) UNSIGNED NULL,
                event_type VARCHAR(100) NOT NULL,
                payload LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY master_id (master_id),
                KEY booking_reference (booking_reference),
                KEY woo_order_id (woo_order_id),
                KEY event_type (event_type),
                KEY created_at (created_at)
            ) {$charsetCollate};",
            "CREATE TABLE {$wpdb->prefix}bsp_guide_profiles (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                city_guide_post_id BIGINT(20) UNSIGNED NOT NULL,
                display_name VARCHAR(190) NOT NULL DEFAULT '',
                status VARCHAR(50) NOT NULL DEFAULT 'idle',
                timezone VARCHAR(100) NOT NULL DEFAULT 'UTC',
                allow_nl_tours TINYINT(1) NOT NULL DEFAULT 0,
                payload LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY city_guide_post_id (city_guide_post_id),
                KEY status (status)
            ) {$charsetCollate};",
            "CREATE TABLE {$wpdb->prefix}bsp_guide_skills (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                profile_id BIGINT(20) UNSIGNED NOT NULL,
                skill_type VARCHAR(50) NOT NULL DEFAULT 'language',
                skill_code VARCHAR(50) NOT NULL DEFAULT '',
                proficiency TINYINT(3) UNSIGNED NOT NULL DEFAULT 5,
                protected_pool TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY profile_skill (profile_id, skill_type, skill_code),
                KEY skill_lookup (skill_type, skill_code),
                KEY protected_pool (protected_pool)
            ) {$charsetCollate};",
            "CREATE TABLE {$wpdb->prefix}bsp_guide_assignments (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                master_id BIGINT(20) UNSIGNED NOT NULL,
                leg_id BIGINT(20) UNSIGNED NULL,
                leg_key VARCHAR(100) NOT NULL DEFAULT '',
                booking_reference VARCHAR(100) NOT NULL,
                requested_language VARCHAR(20) NOT NULL DEFAULT 'nl',
                status VARCHAR(50) NOT NULL DEFAULT 'needed',
                primary_guide_id BIGINT(20) UNSIGNED NULL,
                backup_guide_id BIGINT(20) UNSIGNED NULL,
                scheduled_date DATE NULL,
                scheduled_start_time VARCHAR(20) NOT NULL DEFAULT '',
                scheduled_end_time VARCHAR(20) NOT NULL DEFAULT '',
                scarcity_score INT(10) UNSIGNED NOT NULL DEFAULT 0,
                payload LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY master_leg (master_id, leg_key),
                KEY booking_reference (booking_reference),
                KEY leg_id (leg_id),
                KEY requested_language (requested_language),
                KEY primary_guide_id (primary_guide_id),
                KEY status (status),
                KEY scheduled_date (scheduled_date)
            ) {$charsetCollate};",
            "CREATE TABLE {$wpdb->prefix}bsp_partner_confirmations (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                master_id BIGINT(20) UNSIGNED NOT NULL,
                leg_id BIGINT(20) UNSIGNED NULL,
                leg_key VARCHAR(100) NOT NULL DEFAULT '',
                booking_reference VARCHAR(100) NOT NULL,
                supplier_id BIGINT(20) UNSIGNED NULL,
                status VARCHAR(50) NOT NULL DEFAULT 'awaiting_partner',
                scheduled_date DATE NULL,
                scheduled_time VARCHAR(20) NOT NULL DEFAULT '',
                scheduled_end_time VARCHAR(20) NOT NULL DEFAULT '',
                participants INT(10) UNSIGNED NOT NULL DEFAULT 0,
                responded_at DATETIME NULL,
                confirmed_at DATETIME NULL,
                payload LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY master_leg (master_id, leg_key),
                KEY booking_reference (booking_reference),
                KEY supplier_id (supplier_id),
                KEY status (status),
                KEY scheduled_date (scheduled_date)
            ) {$charsetCollate};",
            "CREATE TABLE {$wpdb->prefix}bsp_guest_dietary_profiles (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                master_id BIGINT(20) UNSIGNED NOT NULL,
                booking_reference VARCHAR(100) NOT NULL,
                guest_index SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
                guest_name VARCHAR(190) NOT NULL DEFAULT '',
                intake_mode VARCHAR(30) NOT NULL DEFAULT 'per_guest',
                menu_choice VARCHAR(190) NOT NULL DEFAULT '',
                allergen_flags LONGTEXT NULL,
                severity VARCHAR(30) NOT NULL DEFAULT 'none',
                notes TEXT NULL,
                partner_status VARCHAR(50) NOT NULL DEFAULT 'pending_review',
                payload LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY master_guest (master_id, guest_index),
                KEY booking_reference (booking_reference),
                KEY severity (severity),
                KEY partner_status (partner_status)
            ) {$charsetCollate};",
        ];

        foreach ($schemas as $sql) {
            dbDelta($sql);
        }

        update_option(self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION, false);
    }

    public static function maybeInstall(): void
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return;
        }

        if (self::isInstalled($wpdb)) {
            return;
        }

        self::install();
    }

    private static function isInstalled(wpdb $wpdb): bool
    {
        $installedVersion = (string) get_option(self::OPTION_SCHEMA_VERSION, '');
        if ($installedVersion !== self::SCHEMA_VERSION) {
            return false;
        }

        foreach ([
            $wpdb->prefix . 'bsp_booking_masters',
            $wpdb->prefix . 'bsp_booking_legs',
            $wpdb->prefix . 'bsp_booking_events',
            $wpdb->prefix . 'bsp_guide_profiles',
            $wpdb->prefix . 'bsp_guide_skills',
            $wpdb->prefix . 'bsp_guide_assignments',
            $wpdb->prefix . 'bsp_partner_confirmations',
            $wpdb->prefix . 'bsp_guest_dietary_profiles',
        ] as $table) {
            $existing = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($existing !== $table) {
                return false;
            }
        }

        return true;
    }
}

if (! class_exists('BSPModule\\Bookings\\Support\\Installer', false)) {
    class_alias(Installer::class, 'BSPModule\\Bookings\\Support\\Installer');
}
