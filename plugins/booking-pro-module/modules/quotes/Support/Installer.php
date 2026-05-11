<?php

declare(strict_types=1);

namespace BSP\Quotes\Support;

use wpdb;

use function dbDelta;
use function get_option;
use function update_option;

final class Installer
{
    private const OPTION_SCHEMA_VERSION = 'bsp_quotes_schema_version';
    private const SCHEMA_VERSION = '2026-04-21-2';

    public static function install(): void
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return;
        }

        $upgradeFile = ABSPATH . 'wp-admin/includes/upgrade.php';
        if (is_readable($upgradeFile)) {
            require_once $upgradeFile;
        }

        foreach (self::schemas($wpdb->prefix, $wpdb->get_charset_collate()) as $sql) {
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

        $installedVersion = (string) get_option(self::OPTION_SCHEMA_VERSION, '');
        if ($installedVersion === self::SCHEMA_VERSION && self::tablesExist($wpdb)) {
            return;
        }

        self::install();
    }

    /**
     * @return array<int, string>
     */
    public static function schemas(string $prefix, string $charsetCollate): array
    {
        return array(
            "CREATE TABLE {$prefix}bsp_quote_requests (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                request_reference VARCHAR(100) NOT NULL,
                source_type VARCHAR(40) NOT NULL DEFAULT 'admin_manual',
                status VARCHAR(40) NOT NULL DEFAULT 'new',
                request_summary TEXT NOT NULL,
                requester_name VARCHAR(190) NOT NULL DEFAULT '',
                requester_email VARCHAR(190) NOT NULL DEFAULT '',
                requester_phone VARCHAR(50) NULL,
                requester_company VARCHAR(190) NULL,
                event_type VARCHAR(100) NULL,
                product_type VARCHAR(100) NULL,
                group_size INT(10) UNSIGNED NOT NULL DEFAULT 0,
                preferred_date DATE NULL,
                preferred_start_time VARCHAR(20) NULL,
                preferred_end_time VARCHAR(20) NULL,
                customer_id BIGINT(20) UNSIGNED NULL,
                planner_plan_id BIGINT(20) UNSIGNED NULL,
                assigned_user_id BIGINT(20) UNSIGNED NULL,
                classification VARCHAR(50) NULL,
                complexity_score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                pricing_confidence VARCHAR(20) NOT NULL DEFAULT 'unknown',
                availability_confidence VARCHAR(20) NOT NULL DEFAULT 'unknown',
                review_required TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
                source_payload LONGTEXT NULL,
                normalized_payload LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY request_reference (request_reference),
                KEY status (status),
                KEY source_type (source_type),
                KEY preferred_date (preferred_date),
                KEY assigned_user_id (assigned_user_id),
                KEY requester_email (requester_email),
                KEY created_at (created_at)
            ) {$charsetCollate};",
            "CREATE TABLE {$prefix}bsp_quotes (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                quote_reference VARCHAR(100) NOT NULL,
                quote_request_id BIGINT(20) UNSIGNED NOT NULL,
                status VARCHAR(40) NOT NULL DEFAULT 'draft',
                review_status VARCHAR(40) NOT NULL DEFAULT 'not_started',
                send_status VARCHAR(40) NOT NULL DEFAULT 'not_ready',
                handoff_status VARCHAR(40) NOT NULL DEFAULT 'not_ready',
                current_version_id BIGINT(20) UNSIGNED NULL,
                approved_version_id BIGINT(20) UNSIGNED NULL,
                owner_user_id BIGINT(20) UNSIGNED NULL,
                customer_id BIGINT(20) UNSIGNED NULL,
                planner_plan_id BIGINT(20) UNSIGNED NULL,
                booking_master_id BIGINT(20) UNSIGNED NULL,
                woo_order_id BIGINT(20) UNSIGNED NULL,
                approved_at DATETIME NULL,
                approved_by BIGINT(20) UNSIGNED NULL,
                sent_at DATETIME NULL,
                sent_by BIGINT(20) UNSIGNED NULL,
                handoff_ready_at DATETIME NULL,
                handoff_completed_at DATETIME NULL,
                closed_reason VARCHAR(100) NULL,
                internal_notes LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY quote_reference (quote_reference),
                KEY quote_request_id (quote_request_id),
                KEY status (status),
                KEY review_status (review_status),
                KEY send_status (send_status),
                KEY handoff_status (handoff_status),
                KEY owner_user_id (owner_user_id),
                KEY created_at (created_at)
            ) {$charsetCollate};",
            "CREATE TABLE {$prefix}bsp_quote_versions (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                quote_id BIGINT(20) UNSIGNED NOT NULL,
                version_number SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                status VARCHAR(40) NOT NULL DEFAULT 'draft',
                proposal_title VARCHAR(190) NOT NULL DEFAULT '',
                proposal_summary LONGTEXT NULL,
                snapshot_type VARCHAR(40) NOT NULL DEFAULT 'initial',
                pricing_confidence VARCHAR(20) NOT NULL DEFAULT 'unknown',
                availability_confidence VARCHAR(20) NOT NULL DEFAULT 'unknown',
                proposal_direction_a_json LONGTEXT NULL,
                proposal_direction_b_json LONGTEXT NULL,
                premium_upsell_json LONGTEXT NULL,
                pricing_snapshot_json LONGTEXT NULL,
                availability_snapshot_json LONGTEXT NULL,
                handoff_payload_json LONGTEXT NULL,
                missing_info_json LONGTEXT NULL,
                render_payload_json LONGTEXT NULL,
                review_notes LONGTEXT NULL,
                supersedes_version_id BIGINT(20) UNSIGNED NULL,
                created_by BIGINT(20) UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY quote_version (quote_id, version_number),
                KEY quote_id (quote_id),
                KEY status (status),
                KEY created_at (created_at)
            ) {$charsetCollate};",
            "CREATE TABLE {$prefix}bsp_quote_lines (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                quote_version_id BIGINT(20) UNSIGNED NOT NULL,
                line_number SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                line_type VARCHAR(40) NOT NULL DEFAULT 'product',
                line_status VARCHAR(40) NOT NULL DEFAULT 'mapped',
                title VARCHAR(190) NOT NULL DEFAULT '',
                product_id BIGINT(20) UNSIGNED NULL,
                vendor_id BIGINT(20) UNSIGNED NULL,
                resource_id BIGINT(20) UNSIGNED NULL,
                quantity INT(10) UNSIGNED NOT NULL DEFAULT 1,
                participants INT(10) UNSIGNED NOT NULL DEFAULT 0,
                service_date DATE NULL,
                proposed_start_time VARCHAR(20) NULL,
                proposed_end_time VARCHAR(20) NULL,
                start_time VARCHAR(20) NULL,
                end_time VARCHAR(20) NULL,
                duration_minutes INT(10) UNSIGNED NULL,
                pricing_mode VARCHAR(20) NOT NULL DEFAULT 'directional',
                pricing_confidence VARCHAR(20) NOT NULL DEFAULT 'unknown',
                availability_confidence VARCHAR(20) NOT NULL DEFAULT 'unknown',
                unit_amount_snapshot DECIMAL(12,2) NULL,
                line_total_snapshot DECIMAL(12,2) NULL,
                currency VARCHAR(10) NULL,
                tax_class VARCHAR(50) NULL,
                pricing_snapshot_json LONGTEXT NULL,
                availability_snapshot_json LONGTEXT NULL,
                selected_option_labels_json LONGTEXT NULL,
                validated_slot_label VARCHAR(190) NULL,
                mapping_notes LONGTEXT NULL,
                external_label VARCHAR(190) NULL,
                is_optional TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                position_group VARCHAR(40) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY quote_version_id (quote_version_id),
                KEY product_id (product_id),
                KEY vendor_id (vendor_id),
                KEY resource_id (resource_id),
                KEY service_date (service_date)
            ) {$charsetCollate};",
            "CREATE TABLE {$prefix}bsp_quote_assumptions (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                quote_id BIGINT(20) UNSIGNED NOT NULL,
                quote_version_id BIGINT(20) UNSIGNED NULL,
                quote_line_id BIGINT(20) UNSIGNED NULL,
                assumption_type VARCHAR(50) NOT NULL DEFAULT 'manual_review_required',
                severity VARCHAR(20) NOT NULL DEFAULT 'warning',
                visibility VARCHAR(20) NOT NULL DEFAULT 'internal',
                status VARCHAR(20) NOT NULL DEFAULT 'open',
                message LONGTEXT NOT NULL,
                resolution_note LONGTEXT NULL,
                blocks_review TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                blocks_send TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                blocks_handoff TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                resolved_at DATETIME NULL,
                resolved_by BIGINT(20) UNSIGNED NULL,
                created_by BIGINT(20) UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY quote_id (quote_id),
                KEY quote_version_id (quote_version_id),
                KEY quote_line_id (quote_line_id),
                KEY status (status),
                KEY assumption_type (assumption_type),
                KEY severity (severity)
            ) {$charsetCollate};",
            "CREATE TABLE {$prefix}bsp_quote_followups (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                quote_request_id BIGINT(20) UNSIGNED NULL,
                quote_id BIGINT(20) UNSIGNED NOT NULL,
                followup_type VARCHAR(50) NOT NULL DEFAULT 'manual_review',
                status VARCHAR(20) NOT NULL DEFAULT 'open',
                priority VARCHAR(20) NOT NULL DEFAULT 'normal',
                title VARCHAR(190) NOT NULL DEFAULT '',
                note LONGTEXT NULL,
                due_at DATETIME NULL,
                assigned_user_id BIGINT(20) UNSIGNED NULL,
                completed_at DATETIME NULL,
                completed_by BIGINT(20) UNSIGNED NULL,
                created_by BIGINT(20) UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY quote_request_id (quote_request_id),
                KEY quote_id (quote_id),
                KEY status (status),
                KEY priority (priority),
                KEY assigned_user_id (assigned_user_id),
                KEY due_at (due_at)
            ) {$charsetCollate};",
            "CREATE TABLE {$prefix}bsp_quote_events (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                quote_request_id BIGINT(20) UNSIGNED NULL,
                quote_id BIGINT(20) UNSIGNED NULL,
                quote_version_id BIGINT(20) UNSIGNED NULL,
                event_type VARCHAR(100) NOT NULL,
                actor_type VARCHAR(40) NOT NULL DEFAULT 'system',
                actor_id BIGINT(20) UNSIGNED NULL,
                context_key VARCHAR(100) NULL,
                message LONGTEXT NULL,
                payload_json LONGTEXT NULL,
                occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY quote_request_id (quote_request_id),
                KEY quote_id (quote_id),
                KEY quote_version_id (quote_version_id),
                KEY event_type (event_type),
                KEY occurred_at (occurred_at)
            ) {$charsetCollate};",
            "CREATE TABLE {$prefix}bsp_quote_messages (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                quote_id BIGINT(20) UNSIGNED NOT NULL,
                quote_version_id BIGINT(20) UNSIGNED NULL,
                direction VARCHAR(20) NOT NULL DEFAULT 'outbound',
                message_type VARCHAR(40) NOT NULL DEFAULT 'proposal',
                channel VARCHAR(20) NOT NULL DEFAULT 'email',
                status VARCHAR(20) NOT NULL DEFAULT 'draft',
                subject VARCHAR(255) NOT NULL DEFAULT '',
                body LONGTEXT NULL,
                body_summary LONGTEXT NULL,
                from_name VARCHAR(190) NULL,
                from_email VARCHAR(190) NULL,
                to_name VARCHAR(190) NULL,
                to_email VARCHAR(190) NULL,
                provider_message_id VARCHAR(255) NULL,
                in_reply_to_message_id VARCHAR(255) NULL,
                references_json LONGTEXT NULL,
                thread_token VARCHAR(100) NULL,
                sent_at DATETIME NULL,
                received_at DATETIME NULL,
                created_by BIGINT(20) UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY quote_id (quote_id),
                KEY quote_version_id (quote_version_id),
                KEY direction (direction),
                KEY message_type (message_type),
                KEY status (status),
                KEY provider_message_id (provider_message_id(191)),
                KEY thread_token (thread_token)
            ) {$charsetCollate};",
            "CREATE TABLE {$prefix}bsp_quote_message_failures (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                direction VARCHAR(20) NOT NULL DEFAULT 'inbound',
                channel VARCHAR(20) NOT NULL DEFAULT 'email',
                failure_reason VARCHAR(100) NOT NULL DEFAULT 'unmatched_quote',
                subject VARCHAR(255) NOT NULL DEFAULT '',
                from_name VARCHAR(190) NULL,
                from_email VARCHAR(190) NULL,
                to_email VARCHAR(190) NULL,
                provider_message_id VARCHAR(255) NULL,
                in_reply_to_message_id VARCHAR(255) NULL,
                references_json LONGTEXT NULL,
                body LONGTEXT NULL,
                payload_json LONGTEXT NULL,
                guessed_quote_reference VARCHAR(100) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'open',
                linked_quote_id BIGINT(20) UNSIGNED NULL,
                resolved_at DATETIME NULL,
                resolved_by BIGINT(20) UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY status (status),
                KEY failure_reason (failure_reason),
                KEY provider_message_id (provider_message_id(191)),
                KEY guessed_quote_reference (guessed_quote_reference),
                KEY linked_quote_id (linked_quote_id)
            ) {$charsetCollate};",
        );
    }

    private static function tablesExist(wpdb $wpdb): bool
    {
        $tables = array(
            $wpdb->prefix . 'bsp_quote_requests',
            $wpdb->prefix . 'bsp_quotes',
            $wpdb->prefix . 'bsp_quote_versions',
            $wpdb->prefix . 'bsp_quote_lines',
            $wpdb->prefix . 'bsp_quote_assumptions',
            $wpdb->prefix . 'bsp_quote_followups',
            $wpdb->prefix . 'bsp_quote_events',
            $wpdb->prefix . 'bsp_quote_messages',
            $wpdb->prefix . 'bsp_quote_message_failures',
        );

        foreach ($tables as $table) {
            $existing = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($existing !== $table) {
                return false;
            }
        }

        return true;
    }
}
