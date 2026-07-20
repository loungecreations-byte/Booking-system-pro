<?php
declare(strict_types=1);
namespace BSP\Gamification\Privacy;

final class PrivacyService
{
    public static function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters',array(__CLASS__,'exporters'));
        add_filter('wp_privacy_personal_data_erasers',array(__CLASS__,'erasers'));
    }
    public static function exporters(array $exporters): array { $exporters['bsp-gamification']=array('exporter_friendly_name'=>'DagjeDenBosch voortgang','callback'=>array(__CLASS__,'export')); return $exporters; }
    public static function erasers(array $erasers): array { $erasers['bsp-gamification']=array('eraser_friendly_name'=>'DagjeDenBosch voortgang','callback'=>array(__CLASS__,'erase')); return $erasers; }
    public static function export(string $email,int $page=1): array
    {
        $user=get_user_by('email',$email); if (! $user) { return array('data'=>array(),'done'=>true); } global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT event_type,source_type,xp_delta,occurred_at FROM {$wpdb->prefix}bsp_xp_events WHERE user_id=%d",$user->ID),ARRAY_A);$collectibles=$wpdb->get_results($wpdb->prepare("SELECT collectible_id,route_id,checkpoint_id,unlocked_at,seen_at FROM {$wpdb->prefix}bsp_user_collectibles WHERE user_id=%d",$user->ID),ARRAY_A);$completions=$wpdb->get_results($wpdb->prepare("SELECT tour_id,step_id,context_json,completed_at FROM {$wpdb->prefix}bsp_tour_step_completions WHERE user_id=%d",$user->ID),ARRAY_A);
        return array('data'=>array(array('group_id'=>'bsp-gamification','group_label'=>'Voortgang','item_id'=>'progress-'.$user->ID,'data'=>array(array('name'=>'XP-events','value'=>wp_json_encode($rows)),array('name'=>'Collectibles','value'=>wp_json_encode($collectibles)),array('name'=>'Voltooide tourstappen','value'=>wp_json_encode($completions))))),'done'=>true);
    }
    public static function erase(string $email,int $page=1): array
    {
        $user=get_user_by('email',$email); if (! $user) { return array('items_removed'=>false,'items_retained'=>false,'messages'=>array(),'done'=>true); } global $wpdb;
        foreach (array('bsp_progress_notifications','bsp_user_badges','bsp_user_collectibles','bsp_tour_step_completions','bsp_user_progress','bsp_xp_events') as $suffix) { $wpdb->delete($wpdb->prefix.$suffix,array('user_id'=>$user->ID),array('%d')); }
        foreach (array('_bsp_xp_total','_bsp_current_level','_bsp_progress_updated_at','_bsp_gamification_opt_in','_bsp_public_progress_enabled') as $key) { delete_user_meta($user->ID,$key); }
        return array('items_removed'=>true,'items_retained'=>false,'messages'=>array(),'done'=>true);
    }
}
