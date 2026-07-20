<?php
declare(strict_types=1);
namespace BSP\Gamification\Service;

final class CollectibleSetService
{
    public function evaluate(int $userId): array
    {
        global $wpdb;$completed=array();$sets=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}bsp_collectible_sets WHERE status='active'",ARRAY_A);foreach((array)$sets as $set){$ids=array_values(array_filter(array_map('intval',(array)json_decode((string)$set['item_ids_json'],true))));if(!$ids)continue;$placeholders=implode(',',array_fill(0,count($ids),'%d'));$args=array_merge(array($userId),$ids);$count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT collectible_id) FROM {$wpdb->prefix}bsp_user_collectibles WHERE user_id=%d AND collectible_id IN ({$placeholders})",...$args));if($count!==count($ids))continue;$result=(new XpLedgerService())->award($userId,'collectible_set.completed','collectible_set',(string)$set['id'],array('slug'=>$set['slug']),(int)$set['xp_reward']);if(!empty($result['created']))$completed[]=array('id'=>(int)$set['id'],'title'=>$set['title'],'xp'=>(int)$set['xp_reward']);}return $completed;
    }
}
