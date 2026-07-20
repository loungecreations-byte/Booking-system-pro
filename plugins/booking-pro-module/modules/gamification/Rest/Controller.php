<?php
declare(strict_types=1);
namespace BSP\Gamification\Rest;
use BSP\Gamification\Domain\LevelResolver;
use BSP\Gamification\Repository\ProgressRepository;
use WP_Error;
use WP_REST_Request;

final class Controller
{
    public static function register(): void
    {
        register_rest_route('bsp/v1','/me/progress',array('methods'=>'GET','callback'=>array(__CLASS__,'progress'),'permission_callback'=>array(__CLASS__,'canRead')));
        register_rest_route('bsp/v1','/me/progress/events',array('methods'=>'GET','callback'=>array(__CLASS__,'events'),'permission_callback'=>array(__CLASS__,'canRead')));
        register_rest_route('bsp/v1','/me/badges',array('methods'=>'GET','callback'=>array(__CLASS__,'badges'),'permission_callback'=>array(__CLASS__,'canRead')));
        register_rest_route('bsp/v1','/me/progress/notifications/(?P<id>\d+)/read',array('methods'=>'POST','callback'=>array(__CLASS__,'readNotification'),'permission_callback'=>array(__CLASS__,'canRead')));
        register_rest_route('bsp/v1','/me/progress/privacy',array('methods'=>'PATCH','callback'=>array(__CLASS__,'privacy'),'permission_callback'=>array(__CLASS__,'canRead')));
    }
    public static function canRead() { return is_user_logged_in() ? true : new WP_Error('rest_forbidden','Log in om voortgang te bekijken.',array('status'=>401)); }
    public static function progress()
    {
        $repo = new ProgressRepository(); $userId = get_current_user_id(); $row = $repo->progress($userId);
        $optIn = get_user_meta($userId,'_bsp_gamification_opt_in',true);
        return rest_ensure_response(array('progress'=>(new LevelResolver())->resolve((int)$row['lifetime_xp']),'available_xp'=>(int)$row['available_xp'],'badges'=>$repo->badges($userId),'events'=>$repo->events($userId,10),'notifications'=>$repo->notifications($userId),'privacy'=>array('opt_in'=>$optIn === '' || (bool)$optIn,'public'=>(bool)get_user_meta($userId,'_bsp_public_progress_enabled',true))));
    }
    public static function events(WP_REST_Request $request) { return rest_ensure_response(array('events'=>(new ProgressRepository())->events(get_current_user_id(),(int)$request->get_param('limit')))); }
    public static function badges() { return rest_ensure_response(array('badges'=>(new ProgressRepository())->badges(get_current_user_id()))); }
    public static function readNotification(WP_REST_Request $request) { return rest_ensure_response(array('success'=>(new ProgressRepository())->markNotificationRead(get_current_user_id(),(int)$request['id']))); }
    public static function privacy(WP_REST_Request $request)
    {
        $payload = $request->get_json_params(); $userId = get_current_user_id();
        update_user_meta($userId,'_bsp_gamification_opt_in',! empty($payload['opt_in']) ? 1 : 0);
        update_user_meta($userId,'_bsp_public_progress_enabled',! empty($payload['public']) ? 1 : 0);
        return rest_ensure_response(array('success'=>true));
    }
}
