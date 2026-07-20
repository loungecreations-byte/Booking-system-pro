<?php
declare(strict_types=1);
namespace BSP\Gamification\Rest;
use BSP\Gamification\Repository\CollectibleRepository;
use WP_REST_Request;

final class CollectiblesController
{
    public static function register(): void
    {
        register_rest_route('bsp/v1','/me/collectibles',array('methods'=>'GET','callback'=>array(__CLASS__,'collection'),'permission_callback'=>array(Controller::class,'canRead')));
        register_rest_route('bsp/v1','/me/collectibles/(?P<id>\d+)/seen',array('methods'=>'POST','callback'=>array(__CLASS__,'seen'),'permission_callback'=>array(Controller::class,'canRead')));
        register_rest_route('bsp/v1','/routes/(?P<route_id>\d+)/collection-state',array('methods'=>'GET','callback'=>array(__CLASS__,'route'),'permission_callback'=>array(Controller::class,'canRead')));
        register_rest_route('bsp/v1','/me/collection-sets',array('methods'=>'GET','callback'=>array(__CLASS__,'sets'),'permission_callback'=>array(Controller::class,'canRead')));
    }
    public static function collection(WP_REST_Request $request){$items=(new CollectibleRepository())->collection(get_current_user_id(),(int)$request->get_param('route_id'));return rest_ensure_response(self::payload($items));}
    public static function route(WP_REST_Request $request){$items=(new CollectibleRepository())->collection(get_current_user_id(),(int)$request['route_id']);return rest_ensure_response(self::payload($items));}
    public static function seen(WP_REST_Request $request){return rest_ensure_response(array('success'=>(new CollectibleRepository())->markSeen(get_current_user_id(),(int)$request['id'])));}
    public static function sets(){global $wpdb;$userId=get_current_user_id();$sets=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}bsp_collectible_sets WHERE status='active' ORDER BY id",ARRAY_A);foreach($sets as &$set){$ids=array_values(array_filter(array_map('intval',(array)json_decode((string)$set['item_ids_json'],true))));$set['total']=count($ids);$set['unlocked']=0;if($ids){$marks=implode(',',array_fill(0,count($ids),'%d'));$set['unlocked']=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT collectible_id) FROM {$wpdb->prefix}bsp_user_collectibles WHERE user_id=%d AND collectible_id IN ({$marks})",...array_merge(array($userId),$ids)));}unset($set['item_ids_json']);}return rest_ensure_response(array('sets'=>$sets));}
    private static function payload(array $items): array{$unlocked=count(array_filter($items,fn($i)=>$i['unlocked']));return array('items'=>$items,'summary'=>array('unlocked'=>$unlocked,'total'=>count($items),'percent'=>count($items)?(int)floor($unlocked/count($items)*100):0));}
}
