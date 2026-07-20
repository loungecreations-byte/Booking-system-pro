<?php
declare(strict_types=1);
namespace BSP\Gamification\Admin;
use BSP\Gamification\Service\XpLedgerService;
use BSP\Gamification\Repository\ProgressRepository;

final class AdminPage
{
    public static function register(): void
    {
        add_action('admin_menu',array(__CLASS__,'menu'));
        add_action('admin_post_bsp_gamification_adjust',array(__CLASS__,'adjust'));
        add_action('admin_post_bsp_gamification_save_badge',array(__CLASS__,'saveBadge'));
        add_action('admin_post_bsp_gamification_rebuild',array(__CLASS__,'rebuild'));
    }
    public static function menu(): void { add_submenu_page('woocommerce',__('Gamification','sbdp'),__('Gamification','sbdp'),'manage_woocommerce','bsp-gamification',array(__CLASS__,'render')); }
    public static function render(): void
    {
        if (! current_user_can('manage_woocommerce')) { return; } global $wpdb;
        $badges = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}bsp_badges ORDER BY category,title",ARRAY_A);
        $events = $wpdb->get_results("SELECT e.*,u.user_login FROM {$wpdb->prefix}bsp_xp_events e LEFT JOIN {$wpdb->users} u ON u.ID=e.user_id ORDER BY e.id DESC LIMIT 50",ARRAY_A);
        echo '<div class="wrap"><h1>'.esc_html__('Gamification','sbdp').'</h1>';
        echo '<h2>'.esc_html__('XP-correctie','sbdp').'</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('bsp_gamification_adjust');
        echo '<input type="hidden" name="action" value="bsp_gamification_adjust"><table class="form-table"><tr><th><label for="user_id">User ID</label></th><td><input id="user_id" name="user_id" type="number" min="1" required></td></tr><tr><th><label for="delta">XP-correctie</label></th><td><input id="delta" name="delta" type="number" required></td></tr><tr><th><label for="reason">Reden</label></th><td><input id="reason" name="reason" class="regular-text" required></td></tr></table>'; submit_button(__('Correctie opslaan','sbdp')); echo '</form>';
        echo '<h2>'.esc_html__('Badges','sbdp').'</h2><table class="widefat striped"><thead><tr><th>Badge</th><th>Categorie</th><th>XP</th><th>Status</th></tr></thead><tbody>';
        foreach ((array)$badges as $badge) { echo '<tr><td>'.esc_html($badge['title']).'<br><code>'.esc_html($badge['slug']).'</code></td><td>'.esc_html($badge['category']).'</td><td>'.(int)$badge['xp_reward'].'</td><td>'.esc_html($badge['status']).'</td></tr>'; }
        echo '</tbody></table>';
        echo '<h2>'.esc_html__('Badge toevoegen of bijwerken','sbdp').'</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('bsp_gamification_save_badge');
        echo '<input type="hidden" name="action" value="bsp_gamification_save_badge"><table class="form-table"><tr><th><label for="badge_slug">Slug</label></th><td><input id="badge_slug" name="slug" class="regular-text" required></td></tr><tr><th><label for="badge_title">Titel</label></th><td><input id="badge_title" name="title" class="regular-text" required></td></tr><tr><th><label for="badge_description">Beschrijving</label></th><td><textarea id="badge_description" name="description" class="large-text" required></textarea></td></tr><tr><th><label for="badge_category">Categorie</label></th><td><input id="badge_category" name="category" value="algemeen" required></td></tr><tr><th><label for="badge_event">Eventtype</label></th><td><input id="badge_event" name="event_type" class="regular-text" required></td></tr><tr><th><label for="badge_threshold">Drempel</label></th><td><input id="badge_threshold" name="threshold" type="number" min="1" value="1" required></td></tr><tr><th><label for="badge_xp">XP-beloning</label></th><td><input id="badge_xp" name="xp_reward" type="number" min="0" value="0"></td></tr><tr><th>Status</th><td><select name="status"><option value="draft">Concept</option><option value="active">Actief</option><option value="archived">Gearchiveerd</option></select></td></tr></table>'; submit_button(__('Badge opslaan','sbdp')); echo '</form>';
        echo '<h2>'.esc_html__('Projectie herstellen','sbdp').'</h2><p>'.esc_html__('Bouw de XP-projectie van één gebruiker opnieuw op vanuit de onveranderlijke ledger.','sbdp').'</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('bsp_gamification_rebuild'); echo '<input type="hidden" name="action" value="bsp_gamification_rebuild"><input name="user_id" type="number" min="1" required> '; submit_button(__('Projectie herbouwen','sbdp'),'secondary','submit',false); echo '</form>';
        echo '<h2>'.esc_html__('XP-audit','sbdp').'</h2><table class="widefat striped"><thead><tr><th>ID</th><th>Gebruiker</th><th>Event</th><th>Bron</th><th>XP</th><th>Tijd</th></tr></thead><tbody>'; foreach ((array)$events as $event) { echo '<tr><td>'.(int)$event['id'].'</td><td>'.esc_html($event['user_login'] ?: '#'.$event['user_id']).'</td><td><code>'.esc_html($event['event_type']).'</code></td><td>'.esc_html($event['source_type'].':'.$event['source_id']).'</td><td>'.(int)$event['xp_delta'].'</td><td>'.esc_html($event['occurred_at']).'</td></tr>'; } echo '</tbody></table></div>';
    }
    public static function adjust(): void
    {
        if (! current_user_can('manage_woocommerce')) { wp_die('Forbidden',403); } check_admin_referer('bsp_gamification_adjust');
        (new XpLedgerService())->adjust((int)($_POST['user_id']??0),(int)($_POST['delta']??0),sanitize_text_field((string)($_POST['reason']??'')),'admin-'.get_current_user_id().'-'.wp_generate_uuid4());
        wp_safe_redirect(admin_url('admin.php?page=bsp-gamification&updated=1')); exit;
    }
    public static function saveBadge(): void
    {
        if (! current_user_can('manage_woocommerce')) { wp_die('Forbidden',403); } check_admin_referer('bsp_gamification_save_badge'); global $wpdb;
        $slug = sanitize_title((string)($_POST['slug']??'')); $title = sanitize_text_field((string)($_POST['title']??'')); $eventType = sanitize_key((string)($_POST['event_type']??''));
        if ($slug === '' || $title === '' || $eventType === '') { wp_die(esc_html__('Slug, titel en eventtype zijn verplicht.','sbdp'),400); }
        $data = array('slug'=>$slug,'title'=>$title,'description'=>sanitize_textarea_field((string)($_POST['description']??'')),'category'=>sanitize_key((string)($_POST['category']??'algemeen')),'criteria_json'=>wp_json_encode(array('event_type'=>$eventType,'count'=>max(1,(int)($_POST['threshold']??1)),'unique_source'=>true)),'criteria_version'=>1,'xp_reward'=>max(0,(int)($_POST['xp_reward']??0)),'visibility'=>'visible','status'=>in_array(($_POST['status']??''),array('draft','active','archived'),true)?$_POST['status']:'draft','updated_at'=>gmdate('Y-m-d H:i:s'));
        $existing = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}bsp_badges WHERE slug=%s",$slug));
        if ($existing) { $wpdb->update($wpdb->prefix.'bsp_badges',$data,array('id'=>$existing)); } else { $data['created_at']=gmdate('Y-m-d H:i:s'); $wpdb->insert($wpdb->prefix.'bsp_badges',$data); }
        wp_safe_redirect(admin_url('admin.php?page=bsp-gamification&badge_saved=1')); exit;
    }
    public static function rebuild(): void
    {
        if (! current_user_can('manage_woocommerce')) { wp_die('Forbidden',403); } check_admin_referer('bsp_gamification_rebuild'); $userId=(int)($_POST['user_id']??0); if ($userId<=0) { wp_die('Invalid user',400); }
        (new ProgressRepository())->rebuildProjection($userId); wp_safe_redirect(admin_url('admin.php?page=bsp-gamification&rebuilt=1')); exit;
    }
}
