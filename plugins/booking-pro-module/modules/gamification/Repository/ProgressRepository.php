<?php
declare(strict_types=1);
namespace BSP\Gamification\Repository;
use BSP\Gamification\Domain\LevelResolver;
use wpdb;

final class ProgressRepository
{
    private wpdb $db;
    public function __construct(?wpdb $db = null) { global $wpdb; $this->db = $db ?? $wpdb; }

    /** @param array<string,mixed> $event */
    public function insertEvent(array $event): int
    {
        $table = $this->db->prefix . 'bsp_xp_events';
        $result = $this->db->query($this->db->prepare(
            "INSERT IGNORE INTO {$table} (user_id,event_type,source_type,source_id,idempotency_key,xp_delta,status,reason_code,context_json,occurred_at,created_at,reversed_event_id) VALUES (%d,%s,%s,%s,%s,%d,%s,%s,%s,%s,UTC_TIMESTAMP(),%s)",
            $event['user_id'],$event['event_type'],$event['source_type'],$event['source_id'],$event['idempotency_key'],$event['xp_delta'],$event['status'],$event['reason_code'],$event['context_json'],$event['occurred_at'],$event['reversed_event_id'] ?? null
        ));
        return $result === 1 ? (int) $this->db->insert_id : 0;
    }

    public function project(int $userId, int $eventId, int $delta): array
    {
        $table = $this->db->prefix . 'bsp_user_progress';
        $this->db->query($this->db->prepare("INSERT INTO {$table} (user_id,lifetime_xp,available_xp,current_level,progress_version,last_event_id,updated_at) VALUES (%d,%d,%d,1,1,%d,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE lifetime_xp=GREATEST(0,lifetime_xp+%d),available_xp=GREATEST(0,available_xp+%d),last_event_id=VALUES(last_event_id),progress_version=progress_version+1,updated_at=UTC_TIMESTAMP()", $userId,max(0,$delta),max(0,$delta),$eventId,$delta,$delta));
        $row = $this->progress($userId); $level = (new LevelResolver())->resolve((int) ($row['lifetime_xp'] ?? 0));
        $this->db->update($table, array('current_level' => $level['number']), array('user_id' => $userId), array('%d'), array('%d'));
        update_user_meta($userId, '_bsp_xp_total', (int) $level['xp']);
        update_user_meta($userId, '_bsp_current_level', (int) $level['number']);
        update_user_meta($userId, '_bsp_progress_updated_at', gmdate('c'));
        return $level;
    }

    /** @return array<string,mixed> */
    public function progress(int $userId): array
    {
        $row = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->db->prefix}bsp_user_progress WHERE user_id=%d", $userId), ARRAY_A);
        return is_array($row) ? $row : array('user_id'=>$userId,'lifetime_xp'=>0,'available_xp'=>0,'current_level'=>1);
    }

    /** @return array<int,array<string,mixed>> */
    public function events(int $userId, int $limit = 30): array
    {
        $rows = $this->db->get_results($this->db->prepare("SELECT id,event_type,source_type,source_id,xp_delta,status,reason_code,occurred_at FROM {$this->db->prefix}bsp_xp_events WHERE user_id=%d ORDER BY id DESC LIMIT %d", $userId,max(1,min(100,$limit))), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    /** @return array<int,array<string,mixed>> */
    public function badges(int $userId): array
    {
        $rows = $this->db->get_results($this->db->prepare("SELECT b.*,ub.awarded_at,ub.revoked_at FROM {$this->db->prefix}bsp_badges b LEFT JOIN {$this->db->prefix}bsp_user_badges ub ON ub.badge_id=b.id AND ub.user_id=%d WHERE b.status='active' ORDER BY b.category,b.id",$userId),ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    public function eventCount(int $userId, string $eventType, bool $unique): int
    {
        $field = $unique ? 'COUNT(DISTINCT source_id)' : 'COUNT(*)';
        return (int) $this->db->get_var($this->db->prepare("SELECT {$field} FROM {$this->db->prefix}bsp_xp_events WHERE user_id=%d AND event_type=%s AND status='confirmed'",$userId,$eventType));
    }

    /** @return array<string,mixed>|null */
    public function confirmedSourceEvent(int $userId, string $eventType, string $sourceType, string $sourceId): ?array
    {
        $row = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->db->prefix}bsp_xp_events WHERE user_id=%d AND event_type=%s AND source_type=%s AND source_id=%s AND status='confirmed' ORDER BY id ASC LIMIT 1",$userId,$eventType,$sourceType,$sourceId),ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public function awardBadge(int $userId, int $badgeId, int $eventId, array $payload): bool
    {
        $result = $this->db->query($this->db->prepare("INSERT IGNORE INTO {$this->db->prefix}bsp_user_badges (user_id,badge_id,awarded_event_id,awarded_at) VALUES (%d,%d,%d,UTC_TIMESTAMP())",$userId,$badgeId,$eventId));
        if ($result !== 1) { return false; }
        $this->db->query($this->db->prepare("INSERT IGNORE INTO {$this->db->prefix}bsp_progress_notifications (user_id,notification_type,reference_type,reference_id,payload_json,created_at) VALUES (%d,'badge_awarded','badge',%d,%s,UTC_TIMESTAMP())",$userId,$badgeId,wp_json_encode($payload)));
        return true;
    }

    public function markNotificationRead(int $userId, int $id): bool
    {
        return false !== $this->db->update($this->db->prefix.'bsp_progress_notifications',array('read_at'=>gmdate('Y-m-d H:i:s')),array('id'=>$id,'user_id'=>$userId),array('%s'),array('%d','%d'));
    }

    /** @return array<int,array<string,mixed>> */
    public function notifications(int $userId, int $limit = 10): array
    {
        $rows = $this->db->get_results($this->db->prepare("SELECT id,notification_type,reference_type,reference_id,payload_json,read_at,created_at FROM {$this->db->prefix}bsp_progress_notifications WHERE user_id=%d ORDER BY id DESC LIMIT %d",$userId,max(1,min(50,$limit))),ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    public function rebuildProjection(int $userId): array
    {
        $xp = (int) $this->db->get_var($this->db->prepare("SELECT COALESCE(SUM(xp_delta),0) FROM {$this->db->prefix}bsp_xp_events WHERE user_id=%d AND status='confirmed'",$userId));
        $lastEvent = (int) $this->db->get_var($this->db->prepare("SELECT COALESCE(MAX(id),0) FROM {$this->db->prefix}bsp_xp_events WHERE user_id=%d",$userId));
        $this->db->delete($this->db->prefix.'bsp_user_progress',array('user_id'=>$userId),array('%d'));
        return $this->project($userId,$lastEvent,max(0,$xp));
    }
}
