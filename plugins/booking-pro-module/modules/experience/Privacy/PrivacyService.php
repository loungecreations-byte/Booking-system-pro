<?php
declare(strict_types=1);

namespace BSP\Experience\Privacy;

final class PrivacyService
{
    public static function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', array(__CLASS__, 'exporters'));
        add_filter('wp_privacy_personal_data_erasers', array(__CLASS__, 'erasers'));
    }
    public static function exporters(array $items): array { $items['bsp-experience']=array('exporter_friendly_name'=>'DagjeDenBosch experiences','callback'=>array(__CLASS__,'export')); return $items; }
    public static function erasers(array $items): array { $items['bsp-experience']=array('eraser_friendly_name'=>'DagjeDenBosch experiences','callback'=>array(__CLASS__,'erase')); return $items; }
    public static function export(string $email, int $page=1): array
    {
        $user=get_user_by('email',$email); if (! $user) return array('data'=>array(),'done'=>true); global $wpdb;
        $favorites=$wpdb->get_results($wpdb->prepare("SELECT object_type,object_id,created_at FROM {$wpdb->prefix}bsp_experience_favorites WHERE user_id=%d",$user->ID),ARRAY_A);
        $timeline=$wpdb->get_results($wpdb->prepare("SELECT event_type,source_type,source_id,payload_json,occurred_at FROM {$wpdb->prefix}bsp_experience_timeline WHERE user_id=%d",$user->ID),ARRAY_A);
        $progress=$wpdb->get_results($wpdb->prepare("SELECT tour_id,completed_steps_json,last_step_id,started_at,completed_at,updated_at FROM {$wpdb->prefix}bsp_experience_progress WHERE user_id=%d",$user->ID),ARRAY_A);
        $certificates=$wpdb->get_results($wpdb->prepare("SELECT tour_id,verification_code,issued_at,revoked_at FROM {$wpdb->prefix}bsp_experience_certificates WHERE user_id=%d",$user->ID),ARRAY_A);
        $rewards=$wpdb->get_results($wpdb->prepare("SELECT reward_type,source_type,source_id,status,payload_json,earned_at,revoked_at FROM {$wpdb->prefix}bsp_experience_rewards WHERE user_id=%d",$user->ID),ARRAY_A);
        return array('data'=>array(array('group_id'=>'bsp-experience','group_label'=>'DagjeDenBosch experiences','item_id'=>'experience-'.$user->ID,'data'=>array(array('name'=>'Favorieten','value'=>wp_json_encode($favorites)),array('name'=>'Tijdlijn','value'=>wp_json_encode($timeline)),array('name'=>'Voortgang','value'=>wp_json_encode($progress)),array('name'=>'Certificaten','value'=>wp_json_encode($certificates)),array('name'=>'Rewards','value'=>wp_json_encode($rewards))))),'done'=>true);
    }
    public static function erase(string $email, int $page=1): array
    {
        $user=get_user_by('email',$email); if (! $user) return array('items_removed'=>false,'items_retained'=>false,'messages'=>array(),'done'=>true); global $wpdb;
        foreach (array('bsp_experience_favorites','bsp_experience_timeline','bsp_experience_progress','bsp_experience_certificates','bsp_experience_rewards','bsp_experience_access_claims') as $suffix) $wpdb->delete($wpdb->prefix.$suffix,array('user_id'=>$user->ID),array('%d'));
        return array('items_removed'=>true,'items_retained'=>false,'messages'=>array(),'done'=>true);
    }
}
