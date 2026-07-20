<?php
declare(strict_types=1);

namespace BSP\Experience\Service;

use WP_User;
use wpdb;

final class ExperienceAccessPolicy
{
    private wpdb $db;

    public function __construct(?wpdb $db = null)
    {
        global $wpdb;
        $this->db = $db ?? $wpdb;
    }

    /** @return array<int,array<string,mixed>> */
    public function forUser(WP_User $user): array
    {
        if (! $user->exists() || trim((string) $user->user_email) === '') {
            return array();
        }
        $table = $this->db->prefix . 'sbdp_private_tour_tickets';
        if ($this->db->get_var($this->db->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return array();
        }
        $claims = $this->db->prefix . 'bsp_experience_access_claims';
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT t.id,t.tour_id,t.order_id,t.token,t.status,t.access_expires_at,t.progress,t.created_at FROM {$table} t LEFT JOIN {$claims} c ON c.ticket_id=t.id AND c.revoked_at IS NULL WHERE LOWER(t.email)=LOWER(%s) OR c.user_id=%d ORDER BY t.created_at DESC,t.id DESC",
            (string) $user->user_email,
            (int) $user->ID
        ), ARRAY_A);
        $result = array();
        foreach ((array) $rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $tourId = (int) ($row['tour_id'] ?? 0);
            $status = (string) ($row['status'] ?? '');
            $expires = (string) ($row['access_expires_at'] ?? '');
            $expired = $expires !== '' && strtotime($expires . ' UTC') < time();
            $orderState = $this->orderState((int) ($row['order_id'] ?? 0));
            $allowed = $tourId > 0 && $status === 'active' && ! $expired && ! in_array($orderState, array('cancelled', 'refunded', 'failed'), true);
            $result[] = array(
                'ticket_id' => (int) ($row['id'] ?? 0),
                'tour_id' => $tourId,
                'order_id' => (int) ($row['order_id'] ?? 0),
                'allowed' => $allowed,
                'reason' => $allowed ? 'active_ticket' : ($expired ? 'expired' : ($orderState ?: ($status ?: 'inactive'))),
                'expires_at' => $expires !== '' ? $expires : null,
                'portal_url' => ! empty($row['token']) ? add_query_arg('ticket', (string) $row['token'], home_url('/private-tour-portal/')) : '',
                'progress' => $this->decodeProgress($row['progress'] ?? null),
            );
        }
        $unique=array(); foreach ($result as $item) $unique[(int)$item['ticket_id']]=$item;
        return array_values($unique);
    }

    private function orderState(int $orderId): string
    {
        if ($orderId <= 0 || ! function_exists('wc_get_order')) {
            return '';
        }
        $order = wc_get_order($orderId);
        return $order ? (string) $order->get_status() : '';
    }

    /** @return array<string,mixed> */
    private function decodeProgress($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : array();
    }
}
