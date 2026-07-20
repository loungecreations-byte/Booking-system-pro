<?php
declare(strict_types=1);

namespace BSP\Experience\Repository;

use wpdb;

final class FavoriteRepository
{
    private const TYPES = array('tour', 'product', 'spot', 'activity');
    private wpdb $db;
    public function __construct(?wpdb $db = null) { global $wpdb; $this->db = $db ?? $wpdb; }

    public function add(int $userId, string $type, int $objectId): bool
    {
        if ($userId <= 0 || $objectId <= 0 || ! in_array($type, self::TYPES, true) || ! get_post($objectId)) {
            return false;
        }
        $sql = "INSERT IGNORE INTO {$this->db->prefix}bsp_experience_favorites (user_id,object_type,object_id,created_at) VALUES (%d,%s,%d,UTC_TIMESTAMP())";
        return 1 === $this->db->query($this->db->prepare($sql, $userId, $type, $objectId));
    }

    public function remove(int $userId, string $type, int $objectId): bool
    {
        return false !== $this->db->delete($this->db->prefix . 'bsp_experience_favorites', array('user_id'=>$userId,'object_type'=>$type,'object_id'=>$objectId), array('%d','%s','%d'));
    }

    /** @return array<int,array<string,mixed>> */
    public function all(int $userId): array
    {
        $rows = $this->db->get_results($this->db->prepare("SELECT object_type,object_id,created_at FROM {$this->db->prefix}bsp_experience_favorites WHERE user_id=%d ORDER BY created_at DESC", $userId), ARRAY_A);
        return array_map(static function (array $row): array {
            $id = (int) $row['object_id'];
            return array('type'=>(string)$row['object_type'],'id'=>$id,'title'=>(string)get_the_title($id),'url'=>(string)get_permalink($id),'created_at'=>(string)$row['created_at']);
        }, is_array($rows) ? $rows : array());
    }
}
