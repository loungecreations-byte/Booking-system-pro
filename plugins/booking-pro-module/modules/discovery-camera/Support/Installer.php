<?php

declare(strict_types=1);

namespace BSP\DiscoveryCamera\Support;

use wpdb;

final class Installer
{
    private const OPTION = 'bsp_discovery_camera_schema_version';
    private const VERSION = '2026-07-25-2';

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

        add_option(FeatureFlags::OPTION_ENABLED, '0', '', false);
        add_option(FeatureFlags::OPTION_TOUR_ALLOWLIST, array(), '', false);
        add_option(FeatureFlags::OPTION_PROVIDER_MODE, 'fake', '', false);
        update_option(self::OPTION, self::VERSION, false);
    }

    /** @return array<int,string> */
    public static function schemas(string $prefix, string $collation): array
    {
        return array(
            "CREATE TABLE {$prefix}bsp_photo_attempts (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
attempt_uuid CHAR(36) NOT NULL,
idempotency_key VARCHAR(191) NOT NULL,
user_id BIGINT UNSIGNED NOT NULL,
ticket_id BIGINT UNSIGNED NULL,
tour_id BIGINT UNSIGNED NOT NULL,
step_id BIGINT UNSIGNED NOT NULL,
challenge_revision INT UNSIGNED NOT NULL DEFAULT 1,
status VARCHAR(24) NOT NULL DEFAULT 'created',
upload_hash CHAR(64) NULL,
media_attachment_id BIGINT UNSIGNED NULL,
private_object_key VARCHAR(255) NULL,
consent_version VARCHAR(32) NOT NULL DEFAULT '',
captured_at DATETIME NULL,
expires_at DATETIME NULL,
created_at DATETIME NOT NULL,
updated_at DATETIME NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY attempt_uuid (attempt_uuid),
UNIQUE KEY idempotency_key (idempotency_key),
UNIQUE KEY user_step_hash (user_id,step_id,upload_hash),
KEY user_created (user_id,created_at),
KEY step_status (step_id,status),
KEY status_updated (status,updated_at)
) {$collation};",
            "CREATE TABLE {$prefix}bsp_photo_analyses (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
attempt_id BIGINT UNSIGNED NOT NULL,
analysis_version INT UNSIGNED NOT NULL DEFAULT 1,
provider VARCHAR(40) NOT NULL,
model VARCHAR(80) NOT NULL DEFAULT '',
status VARCHAR(20) NOT NULL DEFAULT 'queued',
object_score DECIMAL(5,4) NULL,
historical_score DECIMAL(5,4) NULL,
composition_score DECIMAL(5,4) NULL,
creativity_score DECIMAL(5,4) NULL,
perspective_score DECIMAL(5,4) NULL,
lighting_score DECIMAL(5,4) NULL,
symmetry_score DECIMAL(5,4) NULL,
detail_score DECIMAL(5,4) NULL,
total_score DECIMAL(5,2) NULL,
result_json LONGTEXT NULL,
provider_request_id VARCHAR(191) NULL,
latency_ms INT UNSIGNED NULL,
estimated_cost_micros BIGINT UNSIGNED NULL,
created_at DATETIME NOT NULL,
completed_at DATETIME NULL,
PRIMARY KEY  (id),
UNIQUE KEY attempt_analysis (attempt_id,analysis_version),
KEY status_created (status,created_at)
) {$collation};",
        );
    }
}
