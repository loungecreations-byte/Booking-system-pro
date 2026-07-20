<?php
declare(strict_types=1);
namespace BSP\Gamification\Rest;
use BSP\Gamification\Service\CollectibleUnlockService;
use WP_Error;
use WP_REST_Request;

final class TourCompletionController
{
    public static function register(): void { register_rest_route('bsp/v1','/tours/(?P<tour_id>\d+)/steps/(?P<step_id>\d+)/complete',array('methods'=>'POST','callback'=>array(__CLASS__,'complete'),'permission_callback'=>array(__CLASS__,'permission'))); }
    public static function permission(WP_REST_Request $request)
    {
        if(!is_user_logged_in())return new WP_Error('rest_forbidden','Log in om tourvoortgang op te slaan.',array('status'=>401));$tourId=(int)$request['tour_id'];$stepId=(int)$request['step_id'];if(get_post_type($tourId)!=='sbdp_private_tour'||get_post_type($stepId)!=='sbdp_tour_step'||(int)wp_get_post_parent_id($stepId)!==$tourId)return new WP_Error('invalid_tour_step','Deze stop hoort niet bij deze tour.',array('status'=>404));if(current_user_can('manage_woocommerce'))return true;$productId=(int)get_post_meta($tourId,'_sbdp_tour_product_id',true);$user=wp_get_current_user();return $productId>0&&function_exists('wc_customer_bought_product')&&wc_customer_bought_product($user->user_email,$user->ID,$productId)?true:new WP_Error('tour_access_required','Voor deze tour is een geldig product vereist.',array('status'=>403));
    }
    public static function complete(WP_REST_Request $request)
    {
        global $wpdb;$userId=get_current_user_id();$tourId=(int)$request['tour_id'];$stepId=(int)$request['step_id'];$table=$wpdb->prefix.'bsp_tour_step_completions';$context=array('source'=>'tour_player','client_time'=>sanitize_text_field((string)$request->get_param('client_time')));$wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$table} (user_id,tour_id,step_id,context_json,completed_at) VALUES (%d,%d,%d,%s,UTC_TIMESTAMP())",$userId,$tourId,$stepId,wp_json_encode($context)));$completionId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE user_id=%d AND tour_id=%d AND step_id=%d",$userId,$tourId,$stepId));$unlocked=(new CollectibleUnlockService())->consume('tour.step_completed',$userId,$tourId,(string)$stepId,$completionId,$context);$total=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='sbdp_tour_step' AND post_status='publish' AND post_parent=%d",$tourId));$completed=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id=%d AND tour_id=%d",$userId,$tourId));if($total>0&&$completed>=$total)do_action('sbdp/route/completed',$userId,$tourId);return rest_ensure_response(array('success'=>true,'completion_id'=>$completionId,'collectibles'=>$unlocked,'progress'=>array('completed'=>$completed,'total'=>$total)));
    }
}
