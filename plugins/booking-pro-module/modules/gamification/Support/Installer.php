<?php
declare(strict_types=1);
namespace BSP\Gamification\Support;
use wpdb;

final class Installer
{
    private const OPTION = 'bsp_gamification_schema_version';
    private const VERSION = '2026-07-16-5';

    public static function maybeInstall(): void
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb || (string) get_option(self::OPTION, '') === self::VERSION) { return; }
        self::install();
    }

    public static function install(): void
    {
        global $wpdb;
        if (! $wpdb instanceof wpdb) { return; }
        $upgrade = ABSPATH . 'wp-admin/includes/upgrade.php';
        if (is_readable($upgrade)) { require_once $upgrade; }
        foreach (self::schemas($wpdb->prefix, $wpdb->get_charset_collate()) as $sql) { dbDelta($sql); }
        update_option(self::OPTION, self::VERSION, false); self::seedBadges($wpdb);
    }

    /** @return array<int,string> */
    public static function schemas(string $p, string $c): array
    {
        return array(
            "CREATE TABLE {$p}bsp_xp_events (\nid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\nuser_id BIGINT UNSIGNED NOT NULL,\nevent_type VARCHAR(80) NOT NULL,\nsource_type VARCHAR(50) NOT NULL,\nsource_id VARCHAR(100) NOT NULL,\nidempotency_key VARCHAR(191) NOT NULL,\nxp_delta INT NOT NULL,\nstatus VARCHAR(20) NOT NULL DEFAULT 'confirmed',\nreason_code VARCHAR(80) NOT NULL DEFAULT '',\ncontext_json LONGTEXT NULL,\noccurred_at DATETIME NOT NULL,\ncreated_at DATETIME NOT NULL,\nreversed_event_id BIGINT UNSIGNED NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY idempotency_key (idempotency_key),\nKEY user_id (user_id),\nKEY event_type (event_type),\nKEY occurred_at (occurred_at),\nKEY status (status)\n) {$c};",
            "CREATE TABLE {$p}bsp_user_progress (\nuser_id BIGINT UNSIGNED NOT NULL,\nlifetime_xp BIGINT NOT NULL DEFAULT 0,\navailable_xp BIGINT NOT NULL DEFAULT 0,\ncurrent_level SMALLINT UNSIGNED NOT NULL DEFAULT 1,\nprogress_version INT UNSIGNED NOT NULL DEFAULT 1,\nlast_event_id BIGINT UNSIGNED NULL,\nupdated_at DATETIME NOT NULL,\nPRIMARY KEY  (user_id),\nKEY current_level (current_level),\nKEY updated_at (updated_at)\n) {$c};",
            "CREATE TABLE {$p}bsp_badges (\nid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\nslug VARCHAR(100) NOT NULL,\ncategory VARCHAR(50) NOT NULL,\ntitle VARCHAR(191) NOT NULL,\ndescription TEXT NOT NULL,\ncriteria_json LONGTEXT NOT NULL,\ncriteria_version INT UNSIGNED NOT NULL DEFAULT 1,\nimage_attachment_id BIGINT UNSIGNED NULL,\nxp_reward INT NOT NULL DEFAULT 0,\nvisibility VARCHAR(20) NOT NULL DEFAULT 'visible',\nstarts_at DATETIME NULL,\nends_at DATETIME NULL,\nstatus VARCHAR(20) NOT NULL DEFAULT 'active',\ncreated_at DATETIME NOT NULL,\nupdated_at DATETIME NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY slug (slug),\nKEY category (category),\nKEY status (status)\n) {$c};",
            "CREATE TABLE {$p}bsp_user_badges (\nid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\nuser_id BIGINT UNSIGNED NOT NULL,\nbadge_id BIGINT UNSIGNED NOT NULL,\nawarded_event_id BIGINT UNSIGNED NULL,\nawarded_at DATETIME NOT NULL,\nrevoked_at DATETIME NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY user_badge (user_id,badge_id),\nKEY badge_id (badge_id),\nKEY awarded_at (awarded_at)\n) {$c};",
            "CREATE TABLE {$p}bsp_progress_notifications (\nid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\nuser_id BIGINT UNSIGNED NOT NULL,\nnotification_type VARCHAR(50) NOT NULL,\nreference_type VARCHAR(50) NOT NULL,\nreference_id BIGINT UNSIGNED NOT NULL,\npayload_json LONGTEXT NULL,\nread_at DATETIME NULL,\ncreated_at DATETIME NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY user_reference (user_id,notification_type,reference_type,reference_id),\nKEY user_read (user_id,read_at),\nKEY created_at (created_at)\n) {$c};",
            "CREATE TABLE {$p}bsp_collectibles (\nid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\nslug VARCHAR(100) NOT NULL,\ntype VARCHAR(30) NOT NULL,\ntitle VARCHAR(191) NOT NULL,\nshort_description TEXT NOT NULL,\nstory_content LONGTEXT NULL,\nrarity VARCHAR(20) NOT NULL DEFAULT 'common',\nimage_attachment_id BIGINT UNSIGNED NULL,\nsilhouette_attachment_id BIGINT UNSIGNED NULL,\naudio_attachment_id BIGINT UNSIGNED NULL,\nxp_reward INT NOT NULL DEFAULT 15,\nhint_mode VARCHAR(20) NOT NULL DEFAULT 'hidden',\nhint_text TEXT NULL,\nstatus VARCHAR(20) NOT NULL DEFAULT 'draft',\nstarts_at DATETIME NULL,\nends_at DATETIME NULL,\ncreated_at DATETIME NOT NULL,\nupdated_at DATETIME NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY slug (slug),\nKEY type (type),\nKEY rarity (rarity),\nKEY status_window (status,starts_at,ends_at)\n) {$c};",
            "CREATE TABLE {$p}bsp_collectible_routes (\nid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\ncollectible_id BIGINT UNSIGNED NOT NULL,\nroute_id BIGINT UNSIGNED NOT NULL,\ncheckpoint_id VARCHAR(100) NOT NULL DEFAULT '',\nunlock_event VARCHAR(80) NOT NULL DEFAULT 'qr.checkpoint_verified',\ndisplay_order INT NOT NULL DEFAULT 0,\nis_required TINYINT(1) NOT NULL DEFAULT 0,\nPRIMARY KEY  (id),\nUNIQUE KEY collectible_route_checkpoint (collectible_id,route_id,checkpoint_id),\nKEY route_checkpoint (route_id,checkpoint_id),\nKEY unlock_event (unlock_event)\n) {$c};",
            "CREATE TABLE {$p}bsp_user_collectibles (\nid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\nuser_id BIGINT UNSIGNED NOT NULL,\ncollectible_id BIGINT UNSIGNED NOT NULL,\nroute_id BIGINT UNSIGNED NOT NULL DEFAULT 0,\ncheckpoint_id VARCHAR(100) NOT NULL DEFAULT '',\nunlock_event_id BIGINT UNSIGNED NULL,\nidempotency_key VARCHAR(191) NOT NULL,\nunlocked_at DATETIME NOT NULL,\nseen_at DATETIME NULL,\ncontext_json LONGTEXT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY user_collectible (user_id,collectible_id),\nUNIQUE KEY idempotency_key (idempotency_key),\nKEY user_unlocked (user_id,unlocked_at),\nKEY route_id (route_id)\n) {$c};",
            "CREATE TABLE {$p}bsp_collectible_sets (\nid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\nslug VARCHAR(100) NOT NULL,\ntitle VARCHAR(191) NOT NULL,\ndescription TEXT NOT NULL,\nimage_attachment_id BIGINT UNSIGNED NULL,\nxp_reward INT NOT NULL DEFAULT 0,\nstatus VARCHAR(20) NOT NULL DEFAULT 'draft',\nitem_ids_json LONGTEXT NOT NULL,\ncreated_at DATETIME NOT NULL,\nupdated_at DATETIME NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY slug (slug),\nKEY status (status)\n) {$c};",
            "CREATE TABLE {$p}bsp_tour_step_completions (\nid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\nuser_id BIGINT UNSIGNED NOT NULL,\ntour_id BIGINT UNSIGNED NOT NULL,\nstep_id BIGINT UNSIGNED NOT NULL,\ncontext_json LONGTEXT NULL,\ncompleted_at DATETIME NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY user_tour_step (user_id,tour_id,step_id),\nKEY tour_step (tour_id,step_id),\nKEY completed_at (completed_at)\n) {$c};",
        );
    }

    private static function seedBadges(wpdb $wpdb): void
    {
        $badges = array(
            array('eerste-stappen','Routes','Eerste stappen','Voltooi je eerste route.','route.completed',1,25),
            array('routekenner','Routes','Routekenner','Voltooi vijf unieke routes.','route.completed',5,50),
            array('bossche-luisteraar','Audiotours','Bossche luisteraar','Voltooi drie audiotours.','audio_tour.completed',3,50),
            array('eerste-boeking','Boeken','Eerste boeking','Rond je eerste betaalde boeking af.','booking.payment_completed',1,50),
            array('eerste-vondst','Collectibles','Eerste vondst','Ontgrendel je eerste collectible.','collectible.unlocked',1,25),
            array('bosch-speurder','Collectibles','Bosch-speurder','Ontgrendel vijf unieke collectibles.','collectible.unlocked',5,50),
            array('symbolenjager','Collectibles','Symbolenjager','Ontgrendel tien unieke collectibles.','collectible.unlocked',10,75),
            array('wereld-van-bosch','Collectibles','De wereld van Bosch','Ontgrendel twintig unieke collectibles.','collectible.unlocked',20,150),
        );
        $table = $wpdb->prefix . 'bsp_badges';
        foreach ($badges as $b) {
            $criteria = wp_json_encode(array('event_type' => $b[4], 'count' => $b[5], 'unique_source' => true));
            $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$table} (slug,category,title,description,criteria_json,criteria_version,xp_reward,visibility,status,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,1,%d,'visible','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())", $b[0],$b[1],$b[2],$b[3],$criteria,$b[6]));
        }
    }
}
