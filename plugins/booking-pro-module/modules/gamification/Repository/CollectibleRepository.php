<?php
declare(strict_types=1);
namespace BSP\Gamification\Repository;
use wpdb;

final class CollectibleRepository
{
    private wpdb $db;
    public function __construct(?wpdb $db=null){ global $wpdb; $this->db=$db??$wpdb; }
    public function candidates(string $eventType,int $routeId,string $checkpointId): array
    {
        $now=gmdate('Y-m-d H:i:s');
        $sql="SELECT c.*,r.route_id,r.checkpoint_id FROM {$this->db->prefix}bsp_collectibles c JOIN {$this->db->prefix}bsp_collectible_routes r ON r.collectible_id=c.id WHERE c.status='active' AND r.unlock_event=%s AND r.route_id=%d AND (r.checkpoint_id='' OR r.checkpoint_id=%s) AND (c.starts_at IS NULL OR c.starts_at<=%s) AND (c.ends_at IS NULL OR c.ends_at>=%s) ORDER BY r.display_order,c.id";
        $rows=$this->db->get_results($this->db->prepare($sql,$eventType,$routeId,$checkpointId,$now,$now),ARRAY_A); return is_array($rows)?$rows:array();
    }
    public function unlock(int $userId,array $item,int $eventId,array $context): bool
    {
        $key=hash('sha256',$userId.'|'.$item['id'].'|v1');
        return 1===$this->db->query($this->db->prepare("INSERT IGNORE INTO {$this->db->prefix}bsp_user_collectibles (user_id,collectible_id,route_id,checkpoint_id,unlock_event_id,idempotency_key,unlocked_at,context_json) VALUES (%d,%d,%d,%s,%d,%s,UTC_TIMESTAMP(),%s)",$userId,$item['id'],$item['route_id'],$item['checkpoint_id'],$eventId,$key,wp_json_encode($context)));
    }
    public function collection(int $userId,int $routeId=0): array
    {
        $where=$routeId>0?$this->db->prepare(' AND r.route_id=%d',$routeId):'';
        $rows=$this->db->get_results($this->db->prepare("SELECT c.*,r.route_id,r.checkpoint_id,u.id AS unlock_id,u.unlocked_at,u.seen_at FROM {$this->db->prefix}bsp_collectibles c JOIN {$this->db->prefix}bsp_collectible_routes r ON r.collectible_id=c.id LEFT JOIN {$this->db->prefix}bsp_user_collectibles u ON u.collectible_id=c.id AND u.user_id=%d WHERE c.status='active' {$where} GROUP BY c.id ORDER BY r.route_id,r.display_order,c.id",$userId),ARRAY_A);
        return array_map(array($this,'present'),is_array($rows)?$rows:array());
    }
    public function markSeen(int $userId,int $collectibleId): bool { return false!==$this->db->update($this->db->prefix.'bsp_user_collectibles',array('seen_at'=>gmdate('Y-m-d H:i:s')),array('user_id'=>$userId,'collectible_id'=>$collectibleId),array('%s'),array('%d','%d')); }
    public function find(int $id,int $userId=0): ?array { $row=$this->db->get_row($this->db->prepare("SELECT c.*,r.route_id,r.checkpoint_id,u.unlocked_at,u.seen_at FROM {$this->db->prefix}bsp_collectibles c LEFT JOIN {$this->db->prefix}bsp_collectible_routes r ON r.collectible_id=c.id LEFT JOIN {$this->db->prefix}bsp_user_collectibles u ON u.collectible_id=c.id AND u.user_id=%d WHERE c.id=%d LIMIT 1",$userId,$id),ARRAY_A); return is_array($row)?$this->present($row):null; }
    private function present(array $row): array
    {
        $unlocked=!empty($row['unlocked_at']); $imageId=$unlocked?(int)$row['image_attachment_id']:(int)$row['silhouette_attachment_id'];
        return array('id'=>(int)$row['id'],'slug'=>$row['slug'],'type'=>$row['type'],'title'=>$unlocked?$row['title']:__('Onbekend object','sbdp'),'description'=>$unlocked?$row['short_description']:($row['hint_mode']==='hidden'?'':$row['hint_text']),'story'=>$unlocked?$row['story_content']:'','rarity'=>$row['rarity'],'route_id'=>(int)($row['route_id']??0),'unlocked'=>$unlocked,'unlocked_at'=>$row['unlocked_at']??null,'seen_at'=>$row['seen_at']??null,'image'=>$imageId?wp_get_attachment_image_url($imageId,'large'):null,'audio'=>$unlocked&&$row['audio_attachment_id']?wp_get_attachment_url((int)$row['audio_attachment_id']):null);
    }
}
