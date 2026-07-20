<?php
declare(strict_types=1);

namespace BSP\Experience\Service;

use wpdb;

final class ExperienceProgressService
{
    private wpdb $db;
    public function __construct(?wpdb $db=null) { global $wpdb; $this->db=$db??$wpdb; }

    /** @return array<string,mixed> */
    public function merge(int $userId,int $tourId,array $stepIds,int $lastStepId=0): array
    {
        $valid=array_map('intval',get_posts(array('post_type'=>'sbdp_tour_step','post_parent'=>$tourId,'post_status'=>'publish','fields'=>'ids','posts_per_page'=>-1)));
        $incoming=array_values(array_unique(array_intersect($valid,array_filter(array_map('intval',$stepIds)))));
        $current=$this->get($userId,$tourId); $merged=array_values(array_unique(array_merge($current['completed_steps'],$incoming))); sort($merged);
        $completed=count($valid)>0 && count(array_intersect($valid,$merged))===count($valid);
        $table=$this->db->prefix.'bsp_experience_progress'; $json=wp_json_encode($merged); $last=in_array($lastStepId,$valid,true)?$lastStepId:(int)($current['last_step_id']??0);
        $this->db->query($this->db->prepare("INSERT INTO {$table} (user_id,tour_id,completed_steps_json,last_step_id,started_at,completed_at,progress_version,updated_at) VALUES (%d,%d,%s,%d,UTC_TIMESTAMP(),%s,1,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE completed_steps_json=VALUES(completed_steps_json),last_step_id=VALUES(last_step_id),completed_at=COALESCE(completed_at,VALUES(completed_at)),progress_version=progress_version+1,updated_at=UTC_TIMESTAMP()",$userId,$tourId,$json,$last,$completed?gmdate('Y-m-d H:i:s'):null));
        return $this->get($userId,$tourId);
    }

    /** @return array{completed_steps:array<int,int>,last_step_id:int,completed_at:?string} */
    public function get(int $userId,int $tourId): array
    {
        $row=$this->db->get_row($this->db->prepare("SELECT completed_steps_json,last_step_id,completed_at FROM {$this->db->prefix}bsp_experience_progress WHERE user_id=%d AND tour_id=%d",$userId,$tourId),ARRAY_A);
        $steps=is_array($row)?json_decode((string)$row['completed_steps_json'],true):array();
        return array('completed_steps'=>is_array($steps)?array_values(array_unique(array_map('intval',$steps))):array(),'last_step_id'=>(int)($row['last_step_id']??0),'completed_at'=>!empty($row['completed_at'])?(string)$row['completed_at']:null);
    }
}
