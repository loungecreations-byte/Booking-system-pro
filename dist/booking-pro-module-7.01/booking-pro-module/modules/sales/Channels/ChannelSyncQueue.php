<?php

declare(strict_types=1);

namespace BSP\Sales\Channels;

use wpdb;
use BSPModule\Core\Audit\AuditLogger;
use BSPModule\Core\Notifications\NotificationCenter;


use function absint;
use function current_time;
use function is_array;
use function json_decode;
use function max;
use function time;
use function __;
use function do_action;
use function gmdate;
use function sprintf;
use function ucfirst;
use function wp_json_encode;
use const ARRAY_A;

final class ChannelSyncQueue
{
    private const TABLE = 'bsp_channel_queue';
    private const STATUS_PENDING    = 'pending';
    private const STATUS_PROCESSING = 'processing';
    private const STATUS_RETRY      = 'retry';
    private const STATUS_FAILED     = 'failed';
    private const STATUS_COMPLETED  = 'completed';

    public static function enqueue(string $entityType, int $entityId, array $payload = array(), ?int $channelId = null, ?string $scheduledAt = null): void
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return;
        }

        $table        = $wpdb->prefix . self::TABLE;
        $scheduled    = $scheduledAt ?: current_time('mysql', true);
        $normalizedId = $channelId ? absint($channelId) : 0;

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE channel_id = %d AND entity_type = %s AND entity_id = %d AND status IN ('pending','retry','processing') LIMIT 1",
                $normalizedId,
                $entityType,
                $entityId
            )
        );

        $data = array(
            'channel_id'   => $normalizedId,
            'entity_type'  => $entityType,
            'entity_id'    => $entityId,
            'payload'      => wp_json_encode($payload),
            'status'       => self::STATUS_PENDING,
            'scheduled_at' => $scheduled,
            'updated_at'   => current_time('mysql', true),
        );

        if ($existing) {
            $wpdb->update(
                $table,
                $data,
                array('id' => (int) $existing),
                array('%d', '%s', '%d', '%s', '%s', '%s'),
                array('%d')
            );
            return;
        }

        $data['created_at'] = current_time('mysql', true);

        $wpdb->insert(
            $table,
            $data,
            array('%d', '%s', '%d', '%s', '%s', '%s', '%s')
        );
    }

    public static function releaseStaleLocks(int $timeoutSeconds = 900): void
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return;
        }

        $table     = $wpdb->prefix . self::TABLE;
        $threshold = gmdate('Y-m-d H:i:s', time() - max(60, $timeoutSeconds));

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET status = %s, locked_at = NULL, updated_at = %s WHERE status = %s AND locked_at IS NOT NULL AND locked_at < %s",
                self::STATUS_RETRY,
                current_time('mysql', true),
                self::STATUS_PROCESSING,
                $threshold
            )
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function claim(int $limit = 10): array
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return array();
        }

        $table = $wpdb->prefix . self::TABLE;
        $now   = current_time('mysql', true);

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, channel_id, entity_type, entity_id, payload, attempts FROM {$table} WHERE status IN (%s,%s) AND scheduled_at <= %s ORDER BY scheduled_at ASC LIMIT %d",
                self::STATUS_PENDING,
                self::STATUS_RETRY,
                $now,
                max(1, $limit)
            ),
            ARRAY_A
        ) ?: array();

        if ($rows === array()) {
            return array();
        }

        foreach ($rows as $row) {
            $wpdb->update(
                $table,
                array(
                    'status'     => self::STATUS_PROCESSING,
                    'locked_at'  => $now,
                    'updated_at' => $now,
                ),
                array('id' => (int) $row['id']),
                array('%s', '%s', '%s'),
                array('%d')
            );
        }

        return array_map(
            static function (array $row): array {
                $payload = array();
                if (! empty($row['payload'])) {
                    $decoded = json_decode((string) $row['payload'], true);
                    if (is_array($decoded)) {
                        $payload = $decoded;
                    }
                }

                return array(
                    'id'         => (int) $row['id'],
                    'channel_id' => (int) $row['channel_id'],
                    'entity_type'=> (string) $row['entity_type'],
                    'entity_id'  => (int) $row['entity_id'],
                    'payload'    => $payload,
                    'attempts'   => (int) $row['attempts'],
                );
            },
            $rows
        );
    }

    public static function markCompleted(int $id): void
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return;
        }

        $table = $wpdb->prefix . self::TABLE;
        $wpdb->update(
            $table,
            array(
                'status'     => self::STATUS_COMPLETED,
                'locked_at'  => null,
                'updated_at' => current_time('mysql', true),
            ),
            array('id' => $id),
            array('%s', '%s', '%s'),
            array('%d')
        );
    }

        public static function markFailedAttempt(int $id, string $errorMessage, int $retryDelaySeconds = 300, int $maxAttempts = 5): void
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return;
        }

        $table = $wpdb->prefix . self::TABLE;
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT attempts, channel_id, entity_type, entity_id FROM {$table} WHERE id = %d",
                $id
            ),
            ARRAY_A
        );

        $attempts = isset($row['attempts']) ? (int) $row['attempts'] + 1 : 1;

        $status = $attempts >= $maxAttempts ? self::STATUS_FAILED : self::STATUS_RETRY;
        $scheduled_at = $status === self::STATUS_FAILED
            ? current_time('mysql', true)
            : gmdate('Y-m-d H:i:s', time() + max(60, $retryDelaySeconds));

        $wpdb->update(
            $table,
            array(
                'status'       => $status,
                'attempts'     => $attempts,
                'last_error'   => $errorMessage,
                'locked_at'    => null,
                'scheduled_at' => $scheduled_at,
                'updated_at'   => current_time('mysql', true),
            ),
            array('id' => $id),
            array('%s', '%d', '%s', '%s', '%s', '%s'),
            array('%d')
        );

        if ($status !== self::STATUS_FAILED) {
            return;
        }

        $channelId  = isset($row['channel_id']) ? (int) $row['channel_id'] : 0;
        $entityType = isset($row['entity_type']) ? (string) $row['entity_type'] : '';
        $entityId   = isset($row['entity_id']) ? (int) $row['entity_id'] : 0;

        $context = array(
            'scope'       => 'channel_sync',
            'channel_id'  => $channelId,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
        );

        $payload = array(
            'queue_id' => $id,
            'attempts' => $attempts,
            'message'  => $errorMessage,
        );

        AuditLogger::log('channel_sync_failed', $context, $payload, 'error');

        $entityLabel = $entityType !== '' ? $entityType : __( 'item', 'sbdp' );
        $message = sprintf(
            __( 'Kanaalsynchronisatie mislukt voor %1$s #%2$d (kanaal %3$d): %4$s', 'sbdp' ),
            ucfirst($entityLabel),
            $entityId,
            $channelId,
            $errorMessage
        );

        NotificationCenter::notify(
            $message,
            'error',
            array('capability' => 'manage_woocommerce')
        );

        do_action(
            'sbdp/channel_sync/failed',
            $channelId,
            $entityType,
            $entityId,
            $errorMessage,
            $attempts
        );
    }
}
