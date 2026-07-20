<?php
declare(strict_types=1);
namespace BSP\Experience\Service;
use wpdb;
final class RewardService
{
    private wpdb $db; public function __construct(?wpdb $db=null){global $wpdb;$this->db=$db??$wpdb;}
    public function earn(int $userId,string $type,string $sourceType,string $sourceId,array $payload=array()): bool
    {
        $key=hash('sha256',implode('|',array($userId,$type,$sourceType,$sourceId,'v1')));
        return 1===$this->db->query($this->db->prepare("INSERT IGNORE INTO {$this->db->prefix}bsp_experience_rewards (user_id,reward_type,source_type,source_id,idempotency_key,status,payload_json,earned_at) VALUES (%d,%s,%s,%s,%s,'earned',%s,UTC_TIMESTAMP())",$userId,$type,$sourceType,$sourceId,$key,wp_json_encode($payload)));
    }
    public function all(int $userId): array { $rows=$this->db->get_results($this->db->prepare("SELECT id,reward_type,source_type,source_id,status,payload_json,earned_at,revoked_at FROM {$this->db->prefix}bsp_experience_rewards WHERE user_id=%d ORDER BY earned_at DESC",$userId),ARRAY_A); return is_array($rows)?$rows:array(); }
}
