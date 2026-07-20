<?php
declare(strict_types=1);

namespace BSP\Experience\Service;

use wpdb;

final class CertificateService
{
    private wpdb $db;
    public function __construct(?wpdb $db=null){global $wpdb;$this->db=$db??$wpdb;}
    public function issueIfEligible(int $userId,int $tourId): ?array
    {
        $progress=(new ExperienceProgressService($this->db))->get($userId,$tourId); if (!$progress['completed_at']) return null;
        $code=hash('sha256',$userId.'|'.$tourId.'|'.wp_salt('auth'));
        $this->db->query($this->db->prepare("INSERT IGNORE INTO {$this->db->prefix}bsp_experience_certificates (user_id,tour_id,verification_code,issued_at) VALUES (%d,%d,%s,UTC_TIMESTAMP())",$userId,$tourId,$code));
        (new RewardService($this->db))->earn($userId,'tour_certificate','tour',(string)$tourId,array('non_monetary'=>true));
        $row=$this->db->get_row($this->db->prepare("SELECT id,tour_id,verification_code,issued_at,revoked_at FROM {$this->db->prefix}bsp_experience_certificates WHERE user_id=%d AND tour_id=%d",$userId,$tourId),ARRAY_A); return is_array($row)?$row:null;
    }
    public function all(int $userId): array { $rows=$this->db->get_results($this->db->prepare("SELECT id,tour_id,verification_code,issued_at,revoked_at FROM {$this->db->prefix}bsp_experience_certificates WHERE user_id=%d ORDER BY issued_at DESC",$userId),ARRAY_A); return is_array($rows)?$rows:array(); }
}
