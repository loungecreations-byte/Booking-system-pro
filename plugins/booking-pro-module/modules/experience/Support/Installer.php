<?php
declare(strict_types=1);

namespace BSP\Experience\Support;

use wpdb;

final class Installer
{
    private const OPTION = 'bsp_experience_schema_version';
    private const VERSION = '2026-07-19-2';

    public static function maybeInstall(): void
    {
        if ((string) get_option(self::OPTION, '') !== self::VERSION) {
            self::install();
        }
    }

    public static function install(): void
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return;
        }
        $upgrade = ABSPATH . 'wp-admin/includes/upgrade.php';
        if (is_readable($upgrade)) {
            require_once $upgrade;
        }
        foreach (self::schemas($wpdb->prefix, $wpdb->get_charset_collate()) as $sql) {
            dbDelta($sql);
        }
        update_option(self::OPTION, self::VERSION, false);
    }

    /** @return array<int,string> */
    public static function schemas(string $prefix, string $collation): array
    {
        return array(
            "CREATE TABLE {$prefix}bsp_experience_favorites (\nid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\nuser_id BIGINT UNSIGNED NOT NULL,\nobject_type VARCHAR(40) NOT NULL,\nobject_id BIGINT UNSIGNED NOT NULL,\ncreated_at DATETIME NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY user_object (user_id,object_type,object_id),\nKEY user_created (user_id,created_at),\nKEY object_lookup (object_type,object_id)\n) {$collation};",
            "CREATE TABLE {$prefix}bsp_experience_timeline (\nid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\nuser_id BIGINT UNSIGNED NOT NULL,\nevent_type VARCHAR(80) NOT NULL,\nsource_type VARCHAR(50) NOT NULL,\nsource_id VARCHAR(100) NOT NULL,\nidempotency_key VARCHAR(191) NOT NULL,\npayload_json LONGTEXT NULL,\noccurred_at DATETIME NOT NULL,\ncreated_at DATETIME NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY idempotency_key (idempotency_key),\nKEY user_occurred (user_id,occurred_at),\nKEY event_type (event_type)\n) {$collation};",
            "CREATE TABLE {$prefix}bsp_experience_access_claims (\nid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\nuser_id BIGINT UNSIGNED NOT NULL,\nticket_id BIGINT UNSIGNED NOT NULL,\nclaimed_at DATETIME NOT NULL,\nrevoked_at DATETIME NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY ticket_claim (ticket_id),\nKEY user_active (user_id,revoked_at)\n) {$collation};",
            "CREATE TABLE {$prefix}bsp_experience_progress (\nuser_id BIGINT UNSIGNED NOT NULL,\ntour_id BIGINT UNSIGNED NOT NULL,\ncompleted_steps_json LONGTEXT NOT NULL,\nlast_step_id BIGINT UNSIGNED NULL,\nstarted_at DATETIME NULL,\ncompleted_at DATETIME NULL,\nprogress_version INT UNSIGNED NOT NULL DEFAULT 1,\nupdated_at DATETIME NOT NULL,\nPRIMARY KEY  (user_id,tour_id),\nKEY tour_completed (tour_id,completed_at),\nKEY user_updated (user_id,updated_at)\n) {$collation};",
            "CREATE TABLE {$prefix}bsp_experience_certificates (\nid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\nuser_id BIGINT UNSIGNED NOT NULL,\ntour_id BIGINT UNSIGNED NOT NULL,\nverification_code CHAR(64) NOT NULL,\nissued_at DATETIME NOT NULL,\nrevoked_at DATETIME NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY user_tour (user_id,tour_id),\nUNIQUE KEY verification_code (verification_code),\nKEY issued_at (issued_at)\n) {$collation};",
            "CREATE TABLE {$prefix}bsp_experience_rewards (\nid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\nuser_id BIGINT UNSIGNED NOT NULL,\nreward_type VARCHAR(50) NOT NULL,\nsource_type VARCHAR(50) NOT NULL,\nsource_id VARCHAR(100) NOT NULL,\nidempotency_key VARCHAR(191) NOT NULL,\nstatus VARCHAR(20) NOT NULL DEFAULT 'earned',\npayload_json LONGTEXT NULL,\nearned_at DATETIME NOT NULL,\nrevoked_at DATETIME NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY idempotency_key (idempotency_key),\nKEY user_status (user_id,status),\nKEY earned_at (earned_at)\n) {$collation};",
        );
    }
}
