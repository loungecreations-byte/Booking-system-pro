<?php

declare(strict_types=1);

namespace BSPModule\Core\Audit;

use WP_User;
use wpdb;

use function add_action;
use function function_exists;
use function current_time;
use function get_current_user_id;
use function get_userdata;
use function in_array;
use function is_array;
use function is_object;
use function json_decode;
use function strtolower;
use function trim;
use function wp_json_encode;

use const ARRAY_A;

final class AuditLogger
{
    private const TABLE = 'bsp_audit_log';

    public static function init(): void
    {
        if (function_exists('add_action')) {
            add_action('sbdp/audit/log', 'BSPModule\\Core\\Audit\\AuditLogger::handleAction', 10, 4);
        }
    }

    /**
     * Persist an audit trail entry for administrative activity.
     */
    public static function log(string $action, array $context = array(), array $payload = array(), string $severity = 'info'): void
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return;
        }

        $action = trim($action);
        if ($action === '') {
            $action = 'unknown';
        }

        $actorId = get_current_user_id();
        $actorName = '';
        if ($actorId) {
            $user = get_userdata($actorId);
            if ($user instanceof WP_User) {
                $actorName = $user->display_name ?: $user->user_login;
            }
        }

        $payloadWrapper = array(
            'context' => $context,
            'data'    => $payload,
        );

        $wpdb->insert(
            $wpdb->prefix . self::TABLE,
            array(
                'action'     => substr($action, 0, 180),
                'context'    => isset($context['scope']) ? (string) $context['scope'] : (string) ($context['context'] ?? ''),
                'payload'    => wp_json_encode($payloadWrapper),
                'actor_id'   => (int) $actorId,
                'actor_name' => $actorName !== '' ? $actorName : null,
                'severity'   => self::normaliseSeverity($severity),
                'created_at' => current_time('mysql', true),
            ),
            array('%s', '%s', '%s', '%d', '%s', '%s', '%s')
        );
    }

    /**
     * Retrieve the most recent audit entries.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function recent(int $limit = 50): array
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) {
            return array();
        }

        $limit = max(1, min(200, $limit));

        $table = $wpdb->prefix . self::TABLE;
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, action, context, payload, actor_id, actor_name, severity, created_at FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        if (! $rows) {
            return array();
        }

        $entries = array();
        foreach ($rows as $row) {
            $decoded = array();
            if (! empty($row['payload'])) {
                $data = json_decode((string) $row['payload'], true);
                if (is_array($data)) {
                    $decoded = $data;
                }
            }

            $entries[] = array(
                'id'         => (int) $row['id'],
                'action'     => (string) $row['action'],
                'context'    => (string) ($row['context'] ?? ''),
                'payload'    => $decoded,
                'actor_id'   => (int) $row['actor_id'],
                'actor_name' => (string) ($row['actor_name'] ?? ''),
                'severity'   => (string) $row['severity'],
                'created_at' => (string) $row['created_at'],
            );
        }

        return $entries;
    }

    public static function handleAction($action, $context = array(), $payload = array(), $severity = 'info'): void
    {
        $actionString    = is_string($action) ? $action : (is_object($action) ? get_class($action) : (string) $action);
        $contextArray    = is_array($context) ? $context : array('note' => (string) $context);
        $payloadArray    = is_array($payload) ? $payload : array('value' => $payload);
        $severityString  = is_string($severity) ? $severity : 'info';

        self::log($actionString, $contextArray, $payloadArray, $severityString);
    }

    private static function normaliseSeverity(string $severity): string
    {
        $value = strtolower(trim($severity));
        $allowed = array('info', 'warning', 'error', 'success');

        return in_array($value, $allowed, true) ? $value : 'info';
    }
}



