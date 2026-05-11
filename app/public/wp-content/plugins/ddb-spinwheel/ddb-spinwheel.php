<?php
/**
 * Plugin Name: DDB Spinwheel (Daily Spin)
 * Description: Daily spinwheel rewards for DagjeDenBosch-style funnels. Shortcode: [ddb_spinwheel]
 * Version: 0.2.0
 * Author: OWN Creations
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

final class DDB_Spinwheel_Plugin {
    const VERSION = '0.2.0';
    const OPTION_VERSION  = 'ddb_spinwheel_version';
    const OPTION_PRIZES   = 'ddb_spinwheel_prizes';
    const OPTION_SETTINGS = 'ddb_spinwheel_settings';
    const TABLE_STATE = 'ddb_spin_state';
    const TABLE_LOG   = 'ddb_spin_log';
    const TABLE_EARN  = 'ddb_spin_earn';
    const REST_NS = 'ddb-spin/v1';

    public static function init() : void {
        register_activation_hook(__FILE__, [__CLASS__, 'on_activate']);
        add_action('plugins_loaded', [__CLASS__, 'maybe_upgrade']);
        add_action('init', [__CLASS__, 'register_shortcode']);
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
        add_action('admin_menu', [__CLASS__, 'register_admin_menu']);
        add_action('admin_init', [__CLASS__, 'handle_admin_post']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'register_admin_assets']);
        add_action('woocommerce_cart_loaded_from_session', [__CLASS__, 'maybe_auto_apply_coupon'], 1);
        add_action('woocommerce_before_cart', [__CLASS__, 'maybe_auto_apply_coupon'], 1);
        add_action('woocommerce_before_checkout_form', [__CLASS__, 'maybe_auto_apply_coupon'], 1);
    }

    public static function on_activate() : void {
        self::install_schema();
        update_option(self::OPTION_VERSION, self::VERSION, false);
    }

    public static function maybe_upgrade() : void {
        $stored = get_option(self::OPTION_VERSION, '');
        if ($stored === self::VERSION) return;
        self::install_schema();
        if (get_option(self::OPTION_PRIZES, null) === null) {
            update_option(self::OPTION_PRIZES, self::default_prizes(), false);
        }
        if (get_option(self::OPTION_SETTINGS, null) === null) {
            update_option(self::OPTION_SETTINGS, self::default_settings(), false);
        }
        update_option(self::OPTION_VERSION, self::VERSION, false);
    }

    private static function install_schema() : void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $state = $wpdb->prefix . self::TABLE_STATE;
        $log   = $wpdb->prefix . self::TABLE_LOG;
        $earn  = $wpdb->prefix . self::TABLE_EARN;

        $sql_state = "CREATE TABLE $state (
            user_id bigint(20) unsigned NOT NULL,
            spinners_balance int(11) NOT NULL DEFAULT 0,
            next_free_spin_at datetime NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (user_id)
        ) $charset;";

        $sql_log = "CREATE TABLE $log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            used_free_spin tinyint(1) NOT NULL DEFAULT 0,
            prize_key varchar(80) NOT NULL,
            prize_label varchar(190) NOT NULL,
            prize_type varchar(30) NOT NULL,
            prize_value varchar(190) NOT NULL,
            created_at datetime NOT NULL,
            meta longtext NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) $charset;";

        $sql_earn = "CREATE TABLE $earn (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            action varchar(40) NOT NULL,
            source varchar(190) NULL,
            spins int(11) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            meta longtext NULL,
            PRIMARY KEY (id),
            KEY user_action (user_id, action),
            KEY created_at (created_at)
        ) $charset;";

        dbDelta($sql_state);
        dbDelta($sql_log);
        dbDelta($sql_earn);

        if (get_option(self::OPTION_PRIZES, null) === null) {
            update_option(self::OPTION_PRIZES, self::default_prizes(), false);
        }
        if (get_option(self::OPTION_SETTINGS, null) === null) {
            update_option(self::OPTION_SETTINGS, self::default_settings(), false);
        }
    }

    public static function default_settings() : array {
        return [
            'cooldown_mode' => '24h', // '24h' or 'midnight'
            'timezone'      => wp_timezone_string(),
            'require_login' => true,
            'wheel_title'   => 'Bossche Rad',
            'wheel_subtitle'=> 'Draai en pak een dagbonus',
            'auto_apply_coupon' => true,
            'partner_redeem_token' => '',
            'earn_actions' => [
                'review' => [
                    'spins' => 1,
                    'cooldown_hours' => 24,
                    'requires_token' => false,
                    'token' => '',
                ],
                'referral' => [
                    'spins' => 1,
                    'cooldown_hours' => 24,
                    'requires_token' => true,
                    'token' => '',
                ],
                'checkin' => [
                    'spins' => 1,
                    'cooldown_hours' => 12,
                    'requires_token' => true,
                    'token' => '',
                ],
                'booking' => [
                    'spins' => 2,
                    'cooldown_hours' => 0,
                    'requires_token' => true,
                    'token' => '',
                ],
            ],
        ];
    }

        public static function default_prizes() : array {
        return [
            ['key'=>'koffie','label'=>'Gratis koffie/thee','type'=>'message','value'=>'Koffie/thee bij deelnemende partner','weight'=>45,'active'=>1,'active_time'=>'08:00-12:00'],
            ['key'=>'snack','label'=>'Snack-upgrade','type'=>'message','value'=>'Bittergarnituur upgrade (daluren)','weight'=>30,'active'=>1,'active_time'=>'12:00-17:00'],
            ['key'=>'tegoed5','label'=>'EUR 5 DDB-tegoed','type'=>'credit','value'=>'5','weight'=>18,'active'=>1,'active_time'=>''],
            ['key'=>'coupon10','label'=>'EUR 10 korting','type'=>'coupon','value'=>'10','weight'=>6,'active'=>1,'active_time'=>''],
            ['key'=>'vip','label'=>'VIP upgrade','type'=>'message','value'=>'Priority / Flex-slot (op aanvraag)','weight'=>1,'active'=>1,'active_time'=>'18:00-23:00'],
        ];
    }

    public static function register_assets() : void {
        $url  = plugin_dir_url(__FILE__);
        $ver  = self::VERSION;

        wp_register_style('ddb-spinwheel', $url . 'assets/spinwheel.css', [], $ver);
        wp_register_script('ddb-spinwheel', $url . 'assets/spinwheel.js', [], $ver, true);

        $settings = self::get_settings();
        wp_localize_script('ddb-spinwheel', 'DDBSpinwheel', [
            'restUrl' => esc_url_raw(rest_url(self::REST_NS)),
            'nonce'   => wp_create_nonce('wp_rest'),
            'requireLogin' => (bool) ($settings['require_login'] ?? true),
        ]);
    }

    public static function register_admin_assets($hook) : void {
        if ($hook !== 'toplevel_page_ddb-spinwheel') return;
        wp_enqueue_style('ddb-spinwheel-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', [], self::VERSION);
    }

    public static function register_shortcode() : void {
        add_shortcode('ddb_spinwheel', [__CLASS__, 'shortcode_spinwheel']);
    }

    public static function shortcode_spinwheel($atts = []) : string {
        wp_enqueue_style('ddb-spinwheel');
        wp_enqueue_script('ddb-spinwheel');

        $settings = self::get_settings();
        $title = esc_html($settings['wheel_title'] ?? 'Bossche Rad');
        $subtitle = esc_html($settings['wheel_subtitle'] ?? 'Draai en pak een dagbonus');

        ob_start(); ?>
        <div class="ddb-spinwheel" data-ddb-spinwheel>
            <div class="ddb-spinwheel__header">
                <div>
                    <div class="ddb-spinwheel__title"><?php echo $title; ?></div>
                    <div class="ddb-spinwheel__subtitle"><?php echo $subtitle; ?></div>
                </div>
                <div class="ddb-spinwheel__status-chip" data-status-chip>Jeroen Bosch laadt...</div>
            </div>
            <div class="ddb-spinwheel__intro">Elke dag 1 gratis spin zodra de timer op nul staat. Extra spins via review, referral of check-in. Bonus gaat direct naar je winkelwagen.</div>

            <div class="ddb-spinwheel__coupon" data-coupon-banner hidden></div>

            <div class="ddb-spinwheel__frame">
                <div class="ddb-spinwheel__pointer" aria-hidden="true"></div>
                <div class="ddb-spinwheel__hand" aria-hidden="true"></div>
                <div class="ddb-spinwheel__wheel" data-wheel role="img" aria-label="Spinwheel"></div>
                <button class="ddb-spinwheel__center-btn" data-spin-btn data-spin-label="SPIN" data-free-label="GRATIS SPIN" data-busy-label="..." type="button" aria-label="Spin het rad">SPIN</button>
            </div>

            <div class="ddb-spinwheel__meta" data-meta>
                <div class="ddb-spinwheel__balance">
                    <span class="ddb-spinwheel__label">Spins:</span>
                    <span class="ddb-spinwheel__value" data-balance>-</span>
                </div>
                <div class="ddb-spinwheel__countdown">
                    <span class="ddb-spinwheel__label">Volgende gratis spin:</span>
                    <span class="ddb-spinwheel__value" data-countdown>-</span>
                    <span class="ddb-spinwheel__badge" data-free-badge hidden>GRATIS</span>
                </div>
            </div>

            <div class="ddb-spinwheel__actions">
                <button class="ddb-spinwheel__btn ddb-spinwheel__btn--primary" data-spin-btn data-spin-label="SPIN" data-free-label="GRATIS SPIN" data-busy-label="..." type="button">SPIN</button>
                <button class="ddb-spinwheel__btn ddb-spinwheel__btn--ghost" data-refresh-btn type="button">Ververs</button>
                <?php if (current_user_can('manage_options')): ?>
                    <button class="ddb-spinwheel__btn ddb-spinwheel__btn--link" data-test-spin-btn type="button" title="Admin testspin">Test spin</button>
                <?php endif; ?>
            </div>

            <div class="ddb-spinwheel__extras" data-extras>
                <div class="ddb-spinwheel__extra-row">
                    <span class="ddb-spinwheel__label">Referral code</span>
                    <span class="ddb-spinwheel__value" data-referral>-</span>
                </div>
                <div class="ddb-spinwheel__extra-row">
                    <span class="ddb-spinwheel__label">Laatste coupon</span>
                    <span class="ddb-spinwheel__value" data-last-coupon>-</span>
                </div>
            </div>

            <div class="ddb-spinwheel__earn" data-earn>
                <div class="ddb-spinwheel__earn-title">Verdien extra spins</div>
                <div class="ddb-spinwheel__earn-actions">
                    <button class="ddb-spinwheel__earn-btn" data-earn-action="review" type="button">Review</button>
                    <button class="ddb-spinwheel__earn-btn" data-earn-action="referral" type="button">Referral</button>
                    <button class="ddb-spinwheel__earn-btn" data-earn-action="checkin" type="button">Check-in</button>
                </div>
                <div class="ddb-spinwheel__earn-note" data-earn-note>Check-in kan via QR of token.</div>
            </div>

            <div class="ddb-spinwheel__notice" data-notice role="status" aria-live="polite"></div>

            <div class="ddb-spinwheel__modal" data-modal hidden style="display:none">
                <div class="ddb-spinwheel__modal-inner" role="dialog" aria-modal="true" aria-label="Prijs">
                    <div class="ddb-spinwheel__modal-title">Jeroen Bosch Bonus</div>
                    <div class="ddb-spinwheel__modal-lead">Jeroen Bosch draaide het rad en legt deze bonus klaar.</div>
                    <div class="ddb-spinwheel__modal-prize" data-prize>-</div>
                    <div class="ddb-spinwheel__modal-detail" data-prize-detail></div>
                    <div class="ddb-spinwheel__modal-actions">
                        <button class="ddb-spinwheel__btn" data-close-modal type="button">Top</button>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function register_rest_routes() : void {
        register_rest_route(self::REST_NS, '/status', [
            'methods'  => 'GET',
            'callback' => [__CLASS__, 'rest_status'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::REST_NS, '/execute', [
            'methods'  => 'POST',
            'callback' => [__CLASS__, 'rest_execute'],
            'permission_callback' => [__CLASS__, 'rest_permission_user_nonce'],
        ]);

        register_rest_route(self::REST_NS, '/earn', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'rest_earn'],
            'permission_callback' => [__CLASS__, 'rest_permission_user_nonce'],
        ]);

        register_rest_route(self::REST_NS, '/partner/redeem', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'rest_partner_redeem'],
            'permission_callback' => [__CLASS__, 'rest_permission_partner'],
        ]);
    }

    public static function rest_permission_user_nonce(\WP_REST_Request $req) : bool {
        $nonce = $req->get_header('X-WP-Nonce');
        if (!$nonce || !\wp_verify_nonce($nonce, 'wp_rest')) {
            return false;
        }

        return \is_user_logged_in();
    }

    public static function rest_permission_partner(\WP_REST_Request $req) : bool {
        $settings = self::get_settings();
        $expected_token = trim((string)($settings['partner_redeem_token'] ?? ''));
        $token = trim((string)$req->get_param('token'));

        if (!$expected_token || !$token) {
            return false;
        }

        return hash_equals($expected_token, $token);
    }

    private static function get_settings() : array {
        $defaults = self::default_settings();
        $s = get_option(self::OPTION_SETTINGS, $defaults);
        if (!is_array($s)) return $defaults;
        return array_replace_recursive($defaults, $s);
    }

    private static function get_prizes() : array {
        $p = get_option(self::OPTION_PRIZES, self::default_prizes());
        if (!is_array($p)) return self::default_prizes();
        $out = [];
        foreach ($p as $pr) {
            if (!is_array($pr)) continue;
            if (empty($pr['key']) || empty($pr['label'])) continue;
            $pr['weight'] = max(0, (int)($pr['weight'] ?? 0));
            $pr['active'] = (int)($pr['active'] ?? 0);
            $pr['type'] = sanitize_key($pr['type'] ?? 'message');
            $pr['value'] = (string)($pr['value'] ?? '');
            $pr['active_time'] = isset($pr['active_time']) ? sanitize_text_field((string)$pr['active_time']) : '';
            $out[] = $pr;
        }
        return $out ?: self::default_prizes();
    }

    private static function ensure_user_state(int $user_id) : array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_STATE;

        $row = $wpdb->get_row($wpdb->prepare("SELECT user_id, spinners_balance, next_free_spin_at FROM $table WHERE user_id=%d", $user_id), ARRAY_A);
        if (!$row) {
            $now = gmdate('Y-m-d H:i:s');
            $wpdb->insert($table, [
                'user_id' => $user_id,
                'spinners_balance' => 0,
                'next_free_spin_at' => null,
                'updated_at' => $now,
            ], ['%d','%d','%s','%s']);
            $row = ['user_id'=>$user_id,'spinners_balance'=>0,'next_free_spin_at'=>null];
        }
        return $row;
    }

    private static function get_earn_actions_config(?array $settings = null) : array {
        $settings = $settings ?? self::get_settings();
        $defaults = self::default_settings()['earn_actions'];
        $earn = $settings['earn_actions'] ?? [];
        if (!is_array($earn)) $earn = [];

        $clean = [];
        foreach ($defaults as $key => $def) {
            $row = is_array($earn[$key] ?? null) ? $earn[$key] : [];
            $clean[$key] = [
                'spins' => max(0, (int)($row['spins'] ?? $def['spins'] ?? 0)),
                'cooldown_hours' => max(0, (int)($row['cooldown_hours'] ?? $def['cooldown_hours'] ?? 0)),
                'requires_token' => !empty($row['requires_token']),
                'token' => sanitize_text_field($row['token'] ?? ($def['token'] ?? '')),
            ];
        }
        return $clean;
    }

    private static function get_earn_rule(string $action, ?array $settings = null) : ?array {
        $actions = self::get_earn_actions_config($settings);
        return $actions[$action] ?? null;
    }

    private static function add_spinners(int $user_id, int $delta, \DateTimeImmutable $now) : int {
        $delta = max(0, $delta);
        $state = self::ensure_user_state($user_id);
        $new_balance = max(0, (int)($state['spinners_balance'] ?? 0) + $delta);

        global $wpdb;
        $table_state = $wpdb->prefix . self::TABLE_STATE;
        $wpdb->update($table_state, [
            'spinners_balance' => $new_balance,
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ], ['user_id' => $user_id], ['%d','%s'], ['%d']);

        return $new_balance;
    }

    private static function log_spin_earn(int $user_id, string $action, int $spins, \DateTimeImmutable $now, array $meta = [], ?string $source = null) : void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_EARN;
        $wpdb->insert($table, [
            'user_id' => $user_id,
            'action' => $action,
            'source' => $source ? substr($source, 0, 180) : null,
            'spins' => max(0, $spins),
            'created_at' => $now->format('Y-m-d H:i:s'),
            'meta' => $meta ? wp_json_encode($meta) : null,
        ], ['%d','%s','%s','%d','%s','%s']);
    }

    private static function recent_earn_exists(int $user_id, string $action, int $cooldown_hours, \DateTimeImmutable $now) : ?string {
        if ($cooldown_hours <= 0) return null;
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_EARN;
        $since = $now->modify("-{$cooldown_hours} hours")->format('Y-m-d H:i:s');
        $last = $wpdb->get_var($wpdb->prepare("SELECT created_at FROM $table WHERE user_id=%d AND action=%s AND created_at >= %s ORDER BY created_at DESC LIMIT 1", $user_id, $action, $since));
        return $last ?: null;
    }

    private static function prize_is_active_now(array $prize, \DateTimeImmutable $now_utc, array $settings) : bool {
        $slot = trim((string)($prize['active_time'] ?? ''));
        if ($slot === '') return true;
        if (!preg_match('/^(\\d{1,2}):(\\d{2})-(\\d{1,2}):(\\d{2})$/', $slot, $m)) return true;

        $tz = new \DateTimeZone($settings['timezone'] ?? wp_timezone_string());
        $local_now = $now_utc->setTimezone($tz);
        $start = $local_now->setTime((int)$m[1], (int)$m[2], 0);
        $end   = $local_now->setTime((int)$m[3], (int)$m[4], 0);

        // overnight windows (e.g. 22:00-02:00)
        if ($end <= $start) {
            $end = $end->modify('+1 day');
        }

        return $local_now >= $start && $local_now <= $end;
    }

    private static function get_referral_code(int $user_id) : string {
        $code = (string) get_user_meta($user_id, 'ddb_spinwheel_referral_code', true);
        if ($code) return $code;
        $new = 'DDB-' . strtoupper(wp_generate_password(8, false, false));
        update_user_meta($user_id, 'ddb_spinwheel_referral_code', $new);
        return $new;
    }

    private static function maybe_apply_streak_bonus(int $user_id, \DateTimeImmutable $now) : ?int {
        $today = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
        $last_bonus = (string) get_user_meta($user_id, 'ddb_spinwheel_last_streak_bonus', true);
        if ($last_bonus === $today) return null;

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_LOG;
        $since = $now->modify('-2 days')->format('Y-m-d 00:00:00');
        $rows = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT DATE(created_at) FROM $table WHERE user_id=%d AND created_at >= %s ORDER BY created_at DESC", $user_id, $since));
        if (count($rows) < 3) return null;

        // Need today + previous 2 days present
        $dates = array_map('strval', $rows);
        $yesterday = $now->modify('-1 day')->format('Y-m-d');
        $two_days = $now->modify('-2 day')->format('Y-m-d');
        $needed = [$today, $yesterday, $two_days];
        foreach ($needed as $d) {
            if (!in_array($d, $dates, true)) return null;
        }

        $new_balance = self::add_spinners($user_id, 1, $now);
        update_user_meta($user_id, 'ddb_spinwheel_last_streak_bonus', $today);
        return $new_balance;
    }

    private static function compute_next_free_spin_at(\DateTimeImmutable $now, array $settings) : \DateTimeImmutable {
        $mode = $settings['cooldown_mode'] ?? '24h';
        if ($mode === 'midnight') {
            $tz = new \DateTimeZone($settings['timezone'] ?? wp_timezone_string());
            $local = $now->setTimezone($tz);
            $next = $local->modify('tomorrow')->setTime(0, 0, 0);
            return $next->setTimezone(new \DateTimeZone('UTC'));
        }
        return $now->modify('+24 hours');
    }

    private static function free_spin_available(?string $next_free_spin_at_utc, \DateTimeImmutable $now_utc) : bool {
        if (empty($next_free_spin_at_utc)) return true;
        $ts = strtotime($next_free_spin_at_utc . ' UTC');
        if (!$ts) return true;
        return $now_utc->getTimestamp() >= $ts;
    }

    public static function rest_status(\WP_REST_Request $req) : \WP_REST_Response {
        $settings = self::get_settings();

        if (!is_user_logged_in()) {
            return new \WP_REST_Response([
                'requires_login' => (bool)($settings['require_login'] ?? true),
                'logged_in' => false,
                'server_time' => time(),
            ], 200);
        }

        $user_id = get_current_user_id();
        $state = self::ensure_user_state($user_id);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $spinners = (int)($state['spinners_balance'] ?? 0);
        $next = $state['next_free_spin_at'];
        $free = self::free_spin_available($next, $now);

        $prizes = array_values(array_filter(self::get_prizes(), function($p) use ($now, $settings) {
            if (empty($p['active']) || (int)$p['weight'] <= 0) return false;
            return self::prize_is_active_now($p, $now, $settings);
        }));
        if (!$prizes) $prizes = self::default_prizes();

        $segments = array_map(function($p){
            return [
                'key' => (string)$p['key'],
                'label' => (string)$p['label'],
                'weight' => (int)$p['weight'],
            ];
        }, $prizes);

        $earn_meta = [];
        foreach (self::get_earn_actions_config($settings) as $key => $cfg) {
            $earn_meta[$key] = [
                'spins' => (int)$cfg['spins'],
                'cooldown_hours' => (int)$cfg['cooldown_hours'],
                'requires_token' => (bool)$cfg['requires_token'],
            ];
        }

        $referral = $this_ref = null;
        if (is_user_logged_in()) {
            $referral = self::get_referral_code(get_current_user_id());
        }

        $last_coupon_meta = get_user_meta(get_current_user_id(), 'ddb_spinwheel_last_coupon', true);
        $last_coupon = null;
        if (is_array($last_coupon_meta) && !empty($last_coupon_meta['code'])) {
            $exp = isset($last_coupon_meta['expires_at']) ? (int)$last_coupon_meta['expires_at'] : null;
            if (!$exp || $exp > $now->getTimestamp()) {
                $last_coupon = [
                    'code' => $last_coupon_meta['code'],
                    'amount' => $last_coupon_meta['amount'] ?? null,
                    'expires_at' => $exp,
                ];
            }
        }

        return new \WP_REST_Response([
            'logged_in' => true,
            'spinners_balance' => $spinners,
            'free_spin_available' => $free,
            'next_free_spin_at' => $next ? strtotime($next . ' UTC') : null,
            'server_time' => $now->getTimestamp(),
            'segments' => $segments,
            'earn_actions' => $earn_meta,
            'referral_code' => $referral,
            'last_coupon' => $last_coupon,
        ], 200);
    }

    private static function weighted_pick(array $prizes) : array {
        $sum = 0;
        foreach ($prizes as $p) $sum += max(0, (int)($p['weight'] ?? 0));
        if ($sum <= 0) return $prizes[array_rand($prizes)];

        $r = random_int(1, $sum);
        $c = 0;
        foreach ($prizes as $p) {
            $c += max(0, (int)($p['weight'] ?? 0));
            if ($r <= $c) return $p;
        }
        return end($prizes);
    }

    private static function maybe_award_coupon(int $user_id, array $prize) : array {
        $code = 'DDB-' . strtoupper(wp_generate_password(10, false, false));
        $amount = (float)($prize['value'] ?? 0);
        $expires_ts = (new \DateTimeImmutable('+7 days'))->getTimestamp();

        if (class_exists('WC_Coupon') && function_exists('wc_get_coupon_id_by_code') && $amount > 0.0) {
            $coupon = new \WC_Coupon();
            $coupon->set_code($code);
            $coupon->set_amount($amount);
            $coupon->set_discount_type('fixed_cart');
            $coupon->set_usage_limit(1);
            $coupon->set_usage_limit_per_user(1);
            $coupon->set_individual_use(false);
            $coupon->set_description('DDB Spinwheel reward');
            $coupon->set_date_expires($expires_ts);
            $coupon->save();

            return [
                'coupon_code' => $code,
                'coupon_amount' => $amount,
                'coupon_type' => 'fixed_cart',
                'coupon_expires_in_days' => 7,
                'woocommerce' => true,
                'coupon_expires_at' => $expires_ts,
            ];
        }

        return [
            'coupon_code' => $code,
            'coupon_amount' => $amount,
            'coupon_type' => 'fixed_cart',
            'coupon_expires_in_days' => 7,
            'woocommerce' => false,
            'coupon_expires_at' => $expires_ts,
        ];
    }

    private static function award_credit(int $user_id, array $prize) : array {
        $delta = (int)($prize['value'] ?? 0);
        if ($delta < 0) $delta = 0;
        $current = (int) get_user_meta($user_id, 'ddb_wallet_credits', true);
        update_user_meta($user_id, 'ddb_wallet_credits', $current + $delta);
        return [
            'credit_added' => $delta,
            'credit_total' => $current + $delta,
        ];
    }

    private static function build_test_award_meta(int $user_id, array $prize, array $settings) : array {
        $type = sanitize_key($prize['type'] ?? 'message');

        if ($type === 'coupon') {
            return [
                'coupon_code' => 'TEST-' . strtoupper(wp_generate_password(6, false, false)),
                'coupon_amount' => (float)($prize['value'] ?? 0),
                'coupon_type' => 'fixed_cart',
                'coupon_expires_in_days' => 7,
                'auto_apply_coupon' => !empty($settings['auto_apply_coupon']),
                'test_mode' => true,
            ];
        }

        if ($type === 'credit') {
            return [
                'credit_added' => max(0, (int)($prize['value'] ?? 0)),
                'credit_total' => (int) get_user_meta($user_id, 'ddb_wallet_credits', true),
                'test_mode' => true,
            ];
        }

        return ['test_mode' => true];
    }

    public static function rest_execute(\WP_REST_Request $req) : \WP_REST_Response {
        $nonce = $req->get_header('X-WP-Nonce');
        if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
            return new \WP_REST_Response(['error' => 'invalid_nonce'], 403);
        }

        if (!is_user_logged_in()) {
            return new \WP_REST_Response(['error' => 'not_logged_in'], 401);
        }

        $settings = self::get_settings();
        $user_id = get_current_user_id();
        $state = self::ensure_user_state($user_id);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $is_test = current_user_can('manage_options') && (bool)$req->get_param('test_spin');

        $prizes = array_values(array_filter(self::get_prizes(), function($p) use ($now, $settings) {
            if (empty($p['active']) || (int)$p['weight'] <= 0) return false;
            return self::prize_is_active_now($p, $now, $settings);
        }));
        if (!$prizes) $prizes = self::default_prizes();
        $prize = self::weighted_pick($prizes);

        $segments = array_map(fn($p) => (string)$p['key'], $prizes);
        $target_index = array_search((string)$prize['key'], $segments, true);
        if ($target_index === false) $target_index = 0;

        $type = sanitize_key($prize['type'] ?? 'message');

        if ($is_test) {
            $award_meta = self::build_test_award_meta($user_id, $prize, $settings);

            return new \WP_REST_Response([
                'ok' => true,
                'test_mode' => true,
                'used_free_spin' => false,
                'spinners_balance' => (int)($state['spinners_balance'] ?? 0),
                'next_free_spin_at' => $state['next_free_spin_at'] ? strtotime($state['next_free_spin_at'] . ' UTC') : null,
                'server_time' => $now->getTimestamp(),
                'prize' => [
                    'key' => (string)$prize['key'],
                    'label' => (string)$prize['label'],
                    'type' => $type,
                    'value' => (string)$prize['value'],
                    'award_meta' => $award_meta,
                ],
                'target_index' => (int)$target_index,
                'segment_count' => (int)count($prizes),
                'streak_bonus' => false,
            ], 200);
        }

        $spinners = (int)($state['spinners_balance'] ?? 0);
        $next = $state['next_free_spin_at'];
        $free = self::free_spin_available($next, $now);

        if (!$free && $spinners <= 0) {
            return new \WP_REST_Response([
                'error' => 'no_spins_available',
                'spinners_balance' => $spinners,
                'next_free_spin_at' => $next ? strtotime($next . ' UTC') : null,
            ], 409);
        }

        $used_free = $free ? 1 : 0;

        // Consume spin
        $new_spinners = $spinners;
        $new_next = $next;
        if ($free) {
            $new_next_dt = self::compute_next_free_spin_at($now, $settings);
            $new_next = $new_next_dt->format('Y-m-d H:i:s');
        } else {
            $new_spinners = max(0, $spinners - 1);
        }

        $award_meta = [];

        if ($type === 'coupon') {
            $award_meta = self::maybe_award_coupon($user_id, $prize);
            if (!empty($settings['auto_apply_coupon'])) {
                $award_meta['auto_apply_coupon'] = true;
            }
        } elseif ($type === 'credit') {
            $award_meta = self::award_credit($user_id, $prize);
        }

        if ($type === 'coupon' && !empty($award_meta['coupon_code'])) {
            update_user_meta($user_id, 'ddb_spinwheel_last_coupon', [
                'code' => $award_meta['coupon_code'],
                'amount' => $award_meta['coupon_amount'] ?? null,
                'expires_at' => $award_meta['coupon_expires_at'] ?? null,
                'generated_at' => $now->getTimestamp(),
            ]);
        }

        // Persist
        global $wpdb;
        $table_state = $wpdb->prefix . self::TABLE_STATE;
        $table_log   = $wpdb->prefix . self::TABLE_LOG;

        $wpdb->update($table_state, [
            'spinners_balance' => $new_spinners,
            'next_free_spin_at' => $new_next,
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ], ['user_id' => $user_id], ['%d','%s','%s'], ['%d']);

        $wpdb->insert($table_log, [
            'user_id' => $user_id,
            'used_free_spin' => $used_free,
            'prize_key' => (string)$prize['key'],
            'prize_label' => (string)$prize['label'],
            'prize_type' => $type,
            'prize_value' => (string)$prize['value'],
            'created_at' => $now->format('Y-m-d H:i:s'),
            'meta' => $award_meta ? wp_json_encode($award_meta) : null,
        ], ['%d','%d','%s','%s','%s','%s','%s','%s']);

        $streak_bonus_balance = self::maybe_apply_streak_bonus($user_id, $now);

        return new \WP_REST_Response([
            'ok' => true,
            'used_free_spin' => (bool)$used_free,
            'spinners_balance' => $streak_bonus_balance !== null ? $streak_bonus_balance : $new_spinners,
            'next_free_spin_at' => $new_next ? strtotime($new_next . ' UTC') : null,
            'server_time' => $now->getTimestamp(),
            'prize' => [
                'key' => (string)$prize['key'],
                'label' => (string)$prize['label'],
                'type' => $type,
                'value' => (string)$prize['value'],
                'award_meta' => $award_meta,
            ],
            'target_index' => (int)$target_index,
            'segment_count' => (int)count($prizes),
            'streak_bonus' => $streak_bonus_balance !== null,
        ], 200);
    }

    public static function rest_earn(\WP_REST_Request $req) : \WP_REST_Response {
        $nonce = $req->get_header('X-WP-Nonce');
        if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
            return new \WP_REST_Response(['error' => 'invalid_nonce'], 403);
        }

        if (!is_user_logged_in()) {
            return new \WP_REST_Response(['error' => 'not_logged_in'], 401);
        }

        $action = sanitize_key($req->get_param('action'));
        if (!$action) {
            return new \WP_REST_Response(['error' => 'invalid_action'], 400);
        }

        $settings = self::get_settings();
        $rule = self::get_earn_rule($action, $settings);
        if (!$rule || (int)$rule['spins'] <= 0) {
            return new \WP_REST_Response(['error' => 'unsupported_action'], 400);
        }

        $token = (string)($req->get_param('token') ?? '');
        $expected = (string)($rule['token'] ?? '');
        if (!empty($rule['requires_token'])) {
            if (!$expected || !hash_equals($expected, $token)) {
                return new \WP_REST_Response(['error' => 'invalid_token'], 403);
            }
        }

        $user_id = get_current_user_id();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $last = self::recent_earn_exists($user_id, $action, (int)$rule['cooldown_hours'], $now);
        if ($last) {
            $retry_after = strtotime($last . ' UTC') + ((int)$rule['cooldown_hours'] * 3600);
            return new \WP_REST_Response([
                'error' => 'cooldown_active',
                'retry_after' => $retry_after,
                'cooldown_hours' => (int)$rule['cooldown_hours'],
            ], 409);
        }

        $source = sanitize_text_field((string)$req->get_param('source'));
        $meta_raw = $req->get_param('meta');
        $meta = is_array($meta_raw) ? array_map('sanitize_text_field', $meta_raw) : [];

        $new_balance = self::add_spinners($user_id, (int)$rule['spins'], $now);
        self::log_spin_earn($user_id, $action, (int)$rule['spins'], $now, $meta, $source);

        return new \WP_REST_Response([
            'ok' => true,
            'action' => $action,
            'spins_added' => (int)$rule['spins'],
            'spinners_balance' => $new_balance,
            'cooldown_hours' => (int)$rule['cooldown_hours'],
        ], 200);
    }

    public static function rest_partner_redeem(\WP_REST_Request $req) : \WP_REST_Response {
        $settings = self::get_settings();
        $expected_token = trim((string)($settings['partner_redeem_token'] ?? ''));
        $token = trim((string)$req->get_param('token'));

        if (!$expected_token || !$token || !hash_equals($expected_token, $token)) {
            return new \WP_REST_Response(['error' => 'forbidden'], 403);
        }

        if (!class_exists('WC_Coupon') || !function_exists('wc_get_coupon_id_by_code')) {
            return new \WP_REST_Response(['error' => 'woocommerce_inactive'], 400);
        }

        $code = wc_format_coupon_code((string)$req->get_param('coupon_code'));
        if (!$code) {
            return new \WP_REST_Response(['error' => 'invalid_coupon_code'], 400);
        }

        $coupon_id = wc_get_coupon_id_by_code($code);
        if (!$coupon_id) {
            return new \WP_REST_Response(['error' => 'coupon_not_found'], 404);
        }

        $coupon = new \WC_Coupon($code);
        $limit = (int)$coupon->get_usage_limit();
        $used  = (int)$coupon->get_usage_count();
        $expires = $coupon->get_date_expires();

        if ($limit > 0 && $used >= $limit) {
            return new \WP_REST_Response(['error' => 'coupon_already_used'], 409);
        }

        if ($expires && $expires->getTimestamp() < time()) {
            return new \WP_REST_Response(['error' => 'coupon_expired'], 410);
        }

        $coupon->set_usage_count($used + 1);
        $coupon->update_meta_data('ddb_partner_redeemed', current_time('mysql'));
        $coupon->save();

        return new \WP_REST_Response([
            'ok' => true,
            'coupon_code' => $code,
            'usage_count' => $used + 1,
            'usage_limit' => $limit,
            'expires_at' => $expires ? $expires->getTimestamp() : null,
        ], 200);
    }

    public static function maybe_auto_apply_coupon() : void {
        $settings = self::get_settings();
        if (empty($settings['auto_apply_coupon'])) return;
        if (!is_user_logged_in()) return;
        if (!function_exists('WC') || !function_exists('wc_get_coupon_id_by_code') || !function_exists('wc_format_coupon_code')) return;

        $cart = WC()->cart;
        if (!$cart) return;

        $meta = get_user_meta(get_current_user_id(), 'ddb_spinwheel_last_coupon', true);
        if (!is_array($meta) || empty($meta['code'])) return;

        $code = wc_format_coupon_code((string)$meta['code']);
        if (!$code || $cart->has_discount($code)) return;

        $coupon_id = wc_get_coupon_id_by_code($code);
        if (!$coupon_id) return;

        $coupon = new \WC_Coupon($code);
        $expires = $coupon->get_date_expires();
        if ($expires && $expires->getTimestamp() < time()) return;

        $cart->apply_coupon($code);

        // Reminder if not applied (fallback) or expiring soon
        $exp_ts = $expires ? $expires->getTimestamp() : (isset($meta['expires_at']) ? (int)$meta['expires_at'] : null);
        $hours_left = $exp_ts ? (($exp_ts - time()) / 3600) : null;
        if ($hours_left !== null && $hours_left <= 48 && $hours_left > 0) {
            wc_print_notice(sprintf(__('Je spinwheel coupon %s verloopt over %.0f uur.', 'ddb-spinwheel'), $code, $hours_left), 'notice');
        }
    }

    public static function register_admin_menu() : void {
        add_menu_page(
            'DDB Spinwheel',
            'DDB Spinwheel',
            'manage_options',
            'ddb-spinwheel',
            [__CLASS__, 'render_admin_page'],
            'dashicons-controls-repeat',
            58
        );
    }

    public static function handle_admin_post() : void {
        if (!is_admin()) return;
        if (!current_user_can('manage_options')) return;
        if (!isset($_POST['ddb_spinwheel_action'])) return;

        check_admin_referer('ddb_spinwheel_save', 'ddb_spinwheel_nonce');
        $action = sanitize_key($_POST['ddb_spinwheel_action']);

        if ($action === 'save_settings') {
            $settings = self::get_settings();
            $settings['cooldown_mode'] = in_array($_POST['cooldown_mode'] ?? '24h', ['24h','midnight'], true) ? $_POST['cooldown_mode'] : '24h';
            $settings['timezone'] = sanitize_text_field($_POST['timezone'] ?? wp_timezone_string());
            $settings['require_login'] = !empty($_POST['require_login']);
            $settings['wheel_title'] = sanitize_text_field($_POST['wheel_title'] ?? ($settings['wheel_title'] ?? 'Bossche Rad'));
            $settings['wheel_subtitle'] = sanitize_text_field($_POST['wheel_subtitle'] ?? ($settings['wheel_subtitle'] ?? 'Draai en pak een dagbonus'));
            $settings['auto_apply_coupon'] = !empty($_POST['auto_apply_coupon']);
            $settings['partner_redeem_token'] = sanitize_text_field($_POST['partner_redeem_token'] ?? ($settings['partner_redeem_token'] ?? ''));

            $earn_defaults = self::default_settings()['earn_actions'];
            $earn_settings = [];
            foreach ($earn_defaults as $key => $def) {
                $earn_settings[$key] = [
                    'spins' => max(0, (int)($_POST["earn_{$key}_spins"] ?? ($settings['earn_actions'][$key]['spins'] ?? $def['spins'] ?? 0))),
                    'cooldown_hours' => max(0, (int)($_POST["earn_{$key}_cooldown"] ?? ($settings['earn_actions'][$key]['cooldown_hours'] ?? $def['cooldown_hours'] ?? 0))),
                    'requires_token' => !empty($_POST["earn_{$key}_requires_token"]),
                    'token' => sanitize_text_field($_POST["earn_{$key}_token"] ?? ($settings['earn_actions'][$key]['token'] ?? '')),
                ];
            }
            $settings['earn_actions'] = $earn_settings;
            update_option(self::OPTION_SETTINGS, $settings, false);
        }

        if ($action === 'save_prizes') {
            $raw = wp_unslash($_POST['prizes_json'] ?? '');
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $clean = [];
                foreach ($decoded as $p) {
                    if (!is_array($p)) continue;
                    $key = sanitize_key($p['key'] ?? '');
                    $label = sanitize_text_field($p['label'] ?? '');
                    if (!$key || !$label) continue;

                    $type = sanitize_key($p['type'] ?? 'message');
                    if (!in_array($type, ['message','credit','coupon'], true)) $type = 'message';

                    $clean[] = [
                        'key' => $key,
                        'label' => $label,
                        'type' => $type,
                        'value' => sanitize_text_field((string)($p['value'] ?? '')),
                        'weight' => max(0, (int)($p['weight'] ?? 0)),
                        'active' => !empty($p['active']) ? 1 : 0,
                    ];
                }
                update_option(self::OPTION_PRIZES, $clean ?: self::default_prizes(), false);
            }
        }

        wp_safe_redirect(admin_url('admin.php?page=ddb-spinwheel&updated=1'));
        exit;
    }

    public static function render_admin_page() : void {
        if (!current_user_can('manage_options')) return;

        $settings = self::get_settings();
        $prizes = self::get_prizes();
        $earn = self::get_earn_actions_config($settings);
        $updated = !empty($_GET['updated']);
        ?>
        <div class="wrap ddb-spinwheel-admin">
            <h1>DDB Spinwheel</h1>
            <?php if ($updated): ?>
                <div class="notice notice-success"><p>Opgeslagen.</p></div>
            <?php endif; ?>

            <div class="ddb-admin-grid">
                <div class="ddb-card">
                    <h2>Instellingen</h2>
                    <form method="post">
                        <?php wp_nonce_field('ddb_spinwheel_save', 'ddb_spinwheel_nonce'); ?>
                        <input type="hidden" name="ddb_spinwheel_action" value="save_settings"/>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="wheel_title">Titel</label></th>
                                <td><input class="regular-text" id="wheel_title" name="wheel_title" value="<?php echo esc_attr($settings['wheel_title'] ?? 'Bossche Rad'); ?>"/></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="wheel_subtitle">Subtitel</label></th>
                                <td><input class="regular-text" id="wheel_subtitle" name="wheel_subtitle" value="<?php echo esc_attr($settings['wheel_subtitle'] ?? 'Draai en pak een dagbonus'); ?>"/></td>
                            </tr>
                            <tr>
                                <th scope="row">Cooldown</th>
                                <td>
                                    <label><input type="radio" name="cooldown_mode" value="24h" <?php checked(($settings['cooldown_mode'] ?? '24h') === '24h'); ?> /> 24 uur</label><br/>
                                    <label><input type="radio" name="cooldown_mode" value="midnight" <?php checked(($settings['cooldown_mode'] ?? '24h') === 'midnight'); ?> /> reset om middernacht</label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="timezone">Timezone</label></th>
                                <td><input class="regular-text" id="timezone" name="timezone" value="<?php echo esc_attr($settings['timezone'] ?? wp_timezone_string()); ?>"/></td>
                            </tr>
                            <tr>
                                <th scope="row">Login vereist</th>
                                <td><label><input type="checkbox" name="require_login" <?php checked(!empty($settings['require_login'])); ?>/> Ja</label></td>
                            </tr>
                            <tr>
                                <th scope="row">Auto-apply coupon</th>
                                <td><label><input type="checkbox" name="auto_apply_coupon" <?php checked(!empty($settings['auto_apply_coupon'])); ?>/> Plaats prijs-coupon automatisch in WooCommerce cart/checkout</label></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="partner_redeem_token">Partner redeem token</label></th>
                                <td>
                                    <input class="regular-text" id="partner_redeem_token" name="partner_redeem_token" value="<?php echo esc_attr($settings['partner_redeem_token'] ?? ''); ?>"/>
                                    <p class="description">Secret key voor partners om coupons te markeren als ingewisseld via de REST API.</p>
                                </td>
                            </tr>
                        </table>

                        <h3>Spins verdienen</h3>
                        <p class="description">Geef het aantal spinners per actie, cooldown (uren) en optioneel een token/code voor referral, check-in QR of boeking.</p>
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th>Actie</th>
                                    <th>Spins</th>
                                    <th>Cooldown (uur)</th>
                                    <th>Token vereist</th>
                                    <th>Token / code</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $labels = [
                                    'review' => 'Review',
                                    'referral' => 'Referral',
                                    'checkin' => 'Check-in (QR)',
                                    'booking' => 'Boeking / afspraak',
                                ];
                                foreach ($labels as $key => $label):
                                    $row = $earn[$key] ?? ['spins'=>0,'cooldown_hours'=>0,'requires_token'=>false,'token'=>''];
                                ?>
                                <tr>
                                    <td><?php echo esc_html($label); ?></td>
                                    <td><input type="number" min="0" class="small-text" name="earn_<?php echo esc_attr($key); ?>_spins" value="<?php echo esc_attr($row['spins']); ?>"/></td>
                                    <td><input type="number" min="0" class="small-text" name="earn_<?php echo esc_attr($key); ?>_cooldown" value="<?php echo esc_attr($row['cooldown_hours']); ?>"/></td>
                                    <td><input type="checkbox" name="earn_<?php echo esc_attr($key); ?>_requires_token" <?php checked(!empty($row['requires_token'])); ?>/></td>
                                    <td><input type="text" class="regular-text" name="earn_<?php echo esc_attr($key); ?>_token" value="<?php echo esc_attr($row['token']); ?>"/></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <p><button class="button button-primary">Instellingen opslaan</button></p>
                    </form>
                </div>

                <div class="ddb-card">
                    <h2>Prijzen (JSON)</h2>
                    <p>Pas de prijzen aan. <strong>weight</strong> bepaalt de kans (hoger = vaker). types: <code>message</code>, <code>credit</code>, <code>coupon</code>. Optioneel: <code>active_time</code> met formaat <code>HH:MM-HH:MM</code> voor tijdvensters (bijv. koffie 08:00-12:00; over-midnight kan 22:00-02:00).</p>
                    <form method="post">
                        <?php wp_nonce_field('ddb_spinwheel_save', 'ddb_spinwheel_nonce'); ?>
                        <input type="hidden" name="ddb_spinwheel_action" value="save_prizes"/>

                        <textarea name="prizes_json" class="ddb-json"><?php echo esc_textarea(wp_json_encode($prizes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></textarea>

                        <p><button class="button button-primary">Prijzen opslaan</button></p>
                    </form>

                    <hr/>
                    <h3>Shortcode</h3>
                    <code>[ddb_spinwheel]</code>
                    <p>Tip: zet ‘m op je home, in de planner of op de bedanktpagina.</p>
                </div>
            </div>
        </div>
        <?php
    }
}

DDB_Spinwheel_Plugin::init();
