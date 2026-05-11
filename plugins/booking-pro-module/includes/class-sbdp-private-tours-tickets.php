<?php

/**
 * Ticketing utilities for private tours.
 *
 * @package Booking_Pro_Module
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Manages ticket issuance, storage, and session handling.
 */
class SBDP_Private_Tours_Tickets
{
    private const TABLE = 'sbdp_private_tour_tickets';
    private const SESSION_PREFIX = 'sbdp_private_session_';
    public const SESSION_TTL = 12 * HOUR_IN_SECONDS;
/**
     * Cache for step counts during a request lifecycle.
     *
     * @var array<int, int>
     */
    private static $step_count_cache = array();
/**
     * Ensure the ticket table exists.
     */
    public static function create_table(): void
    {
        global $wpdb;
        $table   = self::table();
        $collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            tour_id bigint(20) unsigned NOT NULL,
            order_id bigint(20) unsigned DEFAULT 0,
            order_item_id bigint(20) unsigned DEFAULT 0,
            email varchar(191) NOT NULL DEFAULT '',
            token varchar(64) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            issued_to varchar(191) NOT NULL DEFAULT '',
            progress longtext NULL,
            session_token varchar(64) NOT NULL DEFAULT '',
            session_expires_at datetime NULL,
            created_at datetime NOT NULL,
            redeemed_at datetime NULL,
            expires_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token (token),
            KEY tour_id (tour_id),
            KEY order_id (order_id),
            KEY order_item_id (order_item_id)
        ) {$collate};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Issue tickets when an order completes.
     *
     * @param mixed $order Completed order instance.
     */
    public static function issue_from_order($order): void
    {
        if (! is_a($order, 'WC_Order')) {
            return;
        }

        $email = (string) $order->get_billing_email();
        if ('' === $email) {
            return;
        }

        foreach ($order->get_items() as $item) {
            if (! $item instanceof WC_Order_Item_Product) {
                continue;
            }

            $product_id = (int) $item->get_product_id();
            $variation_id = (int) $item->get_variation_id();
            if ($product_id <= 0 && $variation_id <= 0) {
                continue;
            }

            $tour_id = 0;
            if ($variation_id > 0) {
                $tour_id = self::find_tour_by_product($variation_id);
            }
            if ($tour_id <= 0 && $product_id > 0) {
                $tour_id = self::find_tour_by_product($product_id);
            }

            if ($tour_id <= 0) {
                continue;
            }

            $order_item_id = (int) $item->get_id();
            if (self::order_item_has_tickets($order_item_id)) {
                continue;
            }

            $quantity = max(1, (int) $item->get_quantity());
            $tickets  = self::create_tickets($tour_id, (int) $order->get_id(), $order_item_id, $email, $quantity);
            if (! empty($tickets)) {
                self::send_ticket_email($email, $tour_id, $tickets, $order);
            }
        }
    }

    /**
     * Create ticket rows for a purchase.
     *
     * @param int    $tour_id        Tour identifier.
     * @param int    $order_id       WooCommerce order ID.
     * @param int    $order_item_id  WooCommerce order item ID.
     * @param string $email          Recipient email.
     * @param int    $quantity       Number of tickets.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function create_tickets(int $tour_id, int $order_id, int $order_item_id, string $email, int $quantity): array
    {
        global $wpdb;
        $table   = self::table();
        $tickets = array();
        $expires_at = self::resolve_ticket_expiry($tour_id, $order_id);

        for ($i = 0; $i < $quantity; $i++) {
            $token    = self::generate_token();
            $inserted = $wpdb->insert($table, array(
                    'tour_id'       => $tour_id,
                    'order_id'      => $order_id,
                    'order_item_id' => $order_item_id,
                    'email'         => $email,
                    'token'         => $token,
                    'status'        => 'active',
                    'created_at'    => current_time('mysql', true),
                    'expires_at'    => $expires_at,
                ), array('%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s'));
            if (false === $inserted) {
                continue;
            }

            $tickets[] = array(
                'token'      => $token,
                'tour_id'    => $tour_id,
                'expires_at' => $expires_at,
            );
        }
        return $tickets;
    }

    /**
     * Send ticket instructions to the buyer.
     *
     * @param string        $email   Recipient address.
     * @param int           $tour_id Tour identifier.
     * @param array<int, array<string, mixed>> $tickets Issued tickets.
     * @param mixed         $order   Associated order.
     */
    public static function send_ticket_email(string $email, int $tour_id, array $tickets, $order): void
    {
        if (! is_a($order, 'WC_Order')) {
            return;
        }

        $tour_title = get_the_title($tour_id);
        $site_name  = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
        $portal     = self::portal_url();
        $lines   = array();
        $lines[] = sprintf(__('Bedankt voor je aankoop van de "%s" privetour.', 'sbdp'), $tour_title);
        $lines[] = '';
        $lines[] = __('Gebruik onderstaande ticketcodes om iedere deelnemer toegang te geven:', 'sbdp');
        foreach ($tickets as $index => $ticket) {
            $lines[] = sprintf('%d. %s', $index + 1, $ticket['token']);
        }

        $lines[] = '';
        $lines[] = sprintf(__('Open het portaal: %s', 'sbdp'), $portal);
        $lines[] = __('Voer per deelnemer een ticketcode in om de tour te starten.', 'sbdp');
        $lines[] = __('Gebruik het e-mailadres van je bestelling voor toegang.', 'sbdp');
        $lines[] = __('Deel je ticketcodes niet; elke code is persoonlijk.', 'sbdp');
        if (! empty($tickets[0]['expires_at'])) {
            $lines[] = sprintf(__('Tickets zijn geldig tot %s (UTC).', 'sbdp'), $tickets[0]['expires_at']);
        }
        $support = (string) get_post_meta($tour_id, '_sbdp_tour_support_email', true);
        if ('' !== $support) {
            $lines[] = '';
            $lines[] = sprintf(__('Vragen? Mail naar %s.', 'sbdp'), $support);
        }

        $lines[] = '';
        $lines[] = sprintf(__('Bestelnummer: %s', 'sbdp'), $order->get_order_number());
        $subject = sprintf(__('[%s] Privetour tickets voor %s', 'sbdp'), $site_name, $tour_title);
        $message = implode("\n", $lines);
        wp_mail($email, $subject, $message);
    }

    /**
     * Fetch a ticket record via token.
     *
     * @param string $token Ticket token.
     *
     * @return array<string, mixed>|null
     */
    public static function get_ticket_by_token(string $token): ?array
    {
        global $wpdb;
        $table = self::table();
/** @var array<string, mixed>|null $ticket */
        $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE token = %s LIMIT 1", $token), ARRAY_A);
        return $ticket ?: null;
    }

    /**
     * Fetch ticket record linked to a session token.
     *
     * @param string $session Session token.
     *
     * @return array<string, mixed>|null
     */
    public static function get_ticket_by_session(string $session): ?array
    {
        $ticket_id = get_transient(self::SESSION_PREFIX . $session);
        if (! $ticket_id) {
            return null;
        }

        return self::get_ticket_by_id((int) $ticket_id);
    }

    /**
     * Retrieve a ticket by identifier.
     *
     * @param int $ticket_id Ticket ID.
     *
     * @return array<string, mixed>|null
     */
    public static function get_ticket_by_id(int $ticket_id): ?array
    {
        global $wpdb;
        if ($ticket_id <= 0) {
            return null;
        }

        $table = self::table();
/** @var array<string, mixed>|null $ticket */
        $ticket = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $ticket_id), ARRAY_A);
        return $ticket ?: null;
    }

    /**
     * Count published steps linked to a tour.
     *
     * @param int $tour_id Tour identifier.
     *
     * @return int
     */
    public static function get_step_count(int $tour_id): int
    {
        if ($tour_id <= 0) {
            return 0;
        }

        if (isset(self::$step_count_cache[$tour_id])) {
            return self::$step_count_cache[$tour_id];
        }

        $posts = get_posts(array(
                'post_type'      => SBDP_Private_Tours::POST_TYPE_TOUR_STEP,
                'post_status'    => 'publish',
                'numberposts'    => -1,
                'post_parent'    => $tour_id,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ));
        $count = is_array($posts) ? count($posts) : 0;
        self::$step_count_cache[$tour_id] = $count;
        return $count;
    }

    /**
     * Store progress JSON for a ticket.
     *
     * @param int   $ticket_id Ticket identifier.
     * @param array $progress  Progress payload.
     */
    public static function store_progress(int $ticket_id, array $progress): void
    {
        global $wpdb;
        $wpdb->update(self::table(), array(
                'progress' => wp_json_encode($progress),
            ), array(
                'id' => $ticket_id,
            ), array('%s'), array('%d'));
    }

    /**
     * Decode stored progress.
     *
     * @param string|null $value Raw progress.
     *
     * @return array<string, mixed>
     */
    public static function decode_progress(?string $value): array
    {
        if (! $value) {
            return array();
        }

        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            return array();
        }

        return $decoded;
    }

    /**
     * Decode gamification payloads stored as JSON.
     *
     * @param string $value Raw payload.
     *
     * @return array<string, mixed>
     */
    public static function decode_gamification(string $value): array
    {
        if ('' === $value) {
            return array();
        }

        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            return array();
        }

        return $decoded;
    }

    /**
     * Mark ticket as redeemed.
     *
     * @param int $ticket_id Ticket identifier.
     */
    public static function touch_redeemed(int $ticket_id): void
    {
        global $wpdb;
        $wpdb->update(self::table(), array(
                'redeemed_at' => current_time('mysql', true),
            ), array(
                'id' => $ticket_id,
            ), array('%s'), array('%d'));
    }

    /**
     * Persist a new session token for a ticket.
     *
     * @param int $ticket_id Ticket ID.
     *
     * @return string
     */
    public static function create_session(int $ticket_id): string
    {
        global $wpdb;
            $session = self::generate_token(32);
            $expires_at = gmdate('Y-m-d H:i:s', time() + self::SESSION_TTL);
            set_transient(self::SESSION_PREFIX . $session, $ticket_id, self::SESSION_TTL);
            $wpdb->update(self::table(), array(
                'session_token'      => $session,
                'session_expires_at' => $expires_at,
            ), array(
                'id' => $ticket_id,
            ), array('%s', '%s'), array('%d'));
            return $session;
    }
    /**
     * Generate a preview ticket for editors without an order.
     *
     * @param int $tour_id Tour identifier.
     * @param int $user_id User requesting the preview.
     *
     * @return string|null
     */
    public static function create_preview_ticket(int $tour_id, int $user_id): ?string
    {
        global $wpdb;
        $tour = get_post($tour_id);
        if (! $tour || SBDP_Private_Tours::POST_TYPE_TOUR !== $tour->post_type) {
            return null;
        }

        $token      = self::generate_token();
        $issued_to  = $user_id > 0 ? sprintf('user:%d', $user_id) : 'preview';
        $created_at = current_time('mysql', true);
        $expires_at = gmdate('Y-m-d H:i:s', time() + HOUR_IN_SECONDS);
        $inserted = $wpdb->insert(self::table(), array(
                'tour_id'       => $tour_id,
                'order_id'      => 0,
                'order_item_id' => 0,
                'email'         => '',
                'token'         => $token,
                'status'        => 'preview',
                'issued_to'     => $issued_to,
                'created_at'    => $created_at,
                'expires_at'    => $expires_at,
            ), array('%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s'));
        if (false === $inserted) {
            return null;
        }

        return $token;
    }

    /**
     * Determine whether the order item already produced tickets.
     *
     * @param int $order_item_id Order item ID.
     *
     * @return bool
     */
    public static function order_item_has_tickets(int $order_item_id): bool
    {
        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table() . " WHERE order_item_id = %d", $order_item_id));
        return $count > 0;
    }

    /**
     * Locate the tour linked to a product ID.
     *
     * @param int $product_id Product ID.
     *
     * @return int
     */
    public static function find_tour_by_product(int $product_id): int
    {
        /** @var array<string, mixed> $query_args */
        $query_args = array(
            'post_type'   => SBDP_Private_Tours::POST_TYPE_TOUR,
            'post_status' => 'any',
            'numberposts' => 1,
            'fields'      => 'ids',
            'meta_key'    => '_sbdp_tour_product_id',
            'meta_value'  => $product_id,
        );
        $ids = get_posts($query_args);
        if (empty($ids)) {
            return 0;
        }

        return (int) $ids[0];
    }

    /**
     * Retrieve ordered steps for a tour.
     *
     * @param int $tour_id Tour identifier.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_steps_for_tour(int $tour_id): array
    {
        $posts = get_posts(array(
                'post_type'      => SBDP_Private_Tours::POST_TYPE_TOUR_STEP,
                'post_status'    => 'publish',
                'numberposts'    => -1,
                'post_parent'    => $tour_id,
                'orderby'        => array(
                    'menu_order' => 'ASC',
                    'title'      => 'ASC',
                ),
            ));
        $steps = array();
        foreach ($posts as $index => $post) {
            $template_id = (int) get_post_meta($post->ID, '_sbdp_step_template_id', true);
            $content = (string) $post->post_content;
            $rendered = '';
            if ($template_id && class_exists('\Elementor\Plugin') && did_action('elementor/loaded')) {
                $rendered = \Elementor\Plugin::instance()->frontend->get_builder_content($template_id, true);
            }

            if ('' !== (string) $rendered) {
                $content = $rendered;
            } else {
                if (class_exists('\Elementor\Plugin') && did_action('elementor/loaded')) {
                    $document = \Elementor\Plugin::instance()->documents->get($post->ID);
                    if ($document && $document->is_built_with_elementor()) {
                        $rendered = \Elementor\Plugin::instance()->frontend->get_builder_content($post->ID, true);
                        if ('' !== (string) $rendered) {
                            $content = $rendered;
                        }
                    }
                }

                if ('' === (string) $rendered) {
                    $content = apply_filters('the_content', $post->post_content);
                }
            }

            $video_url = (string) get_post_meta($post->ID, '_sbdp_step_video_url', true);
            $audio_url = (string) get_post_meta($post->ID, '_sbdp_step_audio_url', true);
            $image_url = (string) get_post_meta($post->ID, '_sbdp_step_image_url', true);
            $heygen_video_url = (string) get_post_meta($post->ID, SBDP_Private_Tours::STEP_META_HEYGEN_VIDEO, true);
            $heygen_embed_url = SBDP_Private_Tours::normalize_heygen_video_url($heygen_video_url);
            $media_url = (string) get_post_meta($post->ID, '_sbdp_step_media_url', true);
            if ('' === $media_url) {
                $media_url = $video_url ?: $audio_url;
                if ('' === $media_url) {
                    $media_url = $image_url;
                }
            }

            $lat = get_post_meta($post->ID, '_sbdp_step_lat', true);
            $lng = get_post_meta($post->ID, '_sbdp_step_lng', true);
            $lat = is_numeric($lat) ? (float) $lat : null;
            $lng = is_numeric($lng) ? (float) $lng : null;
            $altitude_m = get_post_meta($post->ID, '_sbdp_step_altitude_m', true);
            $altitude_m = is_numeric($altitude_m) ? (float) $altitude_m : null;
            $area = (string) get_post_meta($post->ID, '_sbdp_step_area', true);
            $location_type = (string) get_post_meta($post->ID, '_sbdp_step_location_type', true);
            $location_label = (string) get_post_meta($post->ID, '_sbdp_step_location_label', true);
            $spot_name = trim((string) get_the_title($post));
            if ('' === $spot_name) {
                $spot_name = $location_label;
            }
            $content_type = (string) get_post_meta($post->ID, '_sbdp_step_type', true);
            $steps[] = array(
                'id'          => (int) $post->ID,
                'index'       => $index,
                'title'       => get_the_title($post),
                'content'     => $content,
                'type'        => $content_type,
                'contentType' => $content_type,
                'mediaUrl'    => $media_url,
                'videoUrl'    => $video_url,
                'audioUrl'    => $audio_url,
                'imageUrl'    => $image_url,
                'heygenVideoUrl' => $heygen_embed_url,
                'heygenEmbedUrl' => $heygen_embed_url,
                'lat'         => $lat,
                'lng'         => $lng,
                'altitudeM'   => $altitude_m,
                'altitude_m'  => $altitude_m,
                'area'        => $area,
                'locationType' => $location_type,
                'locationLabel' => $location_label,
                'spot'        => array(
                    'name'       => $spot_name,
                    'lat'        => $lat,
                    'lng'        => $lng,
                    'altitude_m' => $altitude_m,
                    'area'       => $area,
                    'type'       => $location_type,
                ),
                'templateId'  => $template_id,
                'vrAsset'     => (string) get_post_meta($post->ID, '_sbdp_step_vr_asset', true),
                'gamification' => self::decode_gamification((string) get_post_meta($post->ID, '_sbdp_step_gamification', true)),
                'points'      => (int) get_post_meta($post->ID, '_sbdp_step_points', true),
                'menuOrder'   => (int) $post->menu_order,
            );
        }

        return $steps;
    }

    /**
     * Produce a secure random token.
     *
     * @param int $bytes Number of random bytes.
     *
     * @return string
     */
    public static function generate_token(int $bytes = 16): string
    {

        return substr(bin2hex(random_bytes($bytes)), 0, $bytes * 2);
    }

    /**
     * Resolve ticket expiry datetime in UTC or return null.
     *
     * @param int $tour_id  Tour identifier.
     * @param int $order_id WooCommerce order ID.
     *
     * @return string|null
     */
    private static function resolve_ticket_expiry(int $tour_id, int $order_id): ?string
    {
        $days = (int) apply_filters('sbdp/private_tours/ticket_expiry_days', 30, $tour_id, $order_id);
        if ($days <= 0) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', time() + (DAY_IN_SECONDS * $days));
    }

    /**
     * Build the ticket table name.
     *
     * @return string
     */
    private static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Portal URL for participant access.
     *
     * @return string
     */
    public static function portal_url(): string
    {
        $page = get_page_by_path('private-tour-portal', OBJECT, 'page');
        if ($page instanceof WP_Post) {
            return get_permalink($page);
        }

        return home_url('/');
    }

    /**
     * Remove expired preview tickets from the database.
     */
    public static function cleanup_preview_tokens(): void
    {
        global $wpdb;
        $table = self::table();
        $now   = gmdate('Y-m-d H:i:s');
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE status = %s AND expires_at IS NOT NULL AND expires_at <= %s", 'preview', $now));
    }
}
