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
            $product_id = (int) $item->get_product_id();
            if ($product_id <= 0) {
                continue;
            }

            $tour_id = self::find_tour_by_product($product_id);
            if ($tour_id <= 0) {
                continue;
            }

            $order_item_id = (int) $item->get_id();
            if (self::order_item_has_tickets($order_item_id)) {
                continue;
            }

            $quantity = max(1, (int) $item->get_quantity());
            $tickets  = self::create_tickets(
                $tour_id,
                (int) $order->get_id(),
                $order_item_id,
                $email,
                $quantity
            );

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

        for ($i = 0; $i < $quantity; $i++) {
            $token    = self::generate_token();
            $inserted = $wpdb->insert(
                $table,
                array(
                    'tour_id'       => $tour_id,
                    'order_id'      => $order_id,
                    'order_item_id' => $order_item_id,
                    'email'         => $email,
                    'token'         => $token,
                    'status'        => 'active',
                    'created_at'    => current_time('mysql', true),
                ),
                array('%d', '%d', '%d', '%s', '%s', '%s', '%s')
            );

            if (false === $inserted) {
                continue;
            }

            $tickets[] = array(
                'token'   => $token,
                'tour_id' => $tour_id,
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
        $lines[] = sprintf(__('Bedankt voor je aankoop van de "%s" privétour.', 'sbdp'), $tour_title);
        $lines[] = '';
        $lines[] = __('Gebruik onderstaande ticketcodes om iedere deelnemer toegang te geven:', 'sbdp');

        foreach ($tickets as $index => $ticket) {
            $lines[] = sprintf('%d. %s', $index + 1, $ticket['token']);
        }

        $lines[] = '';
        $lines[] = sprintf(__('Open het portaal: %s', 'sbdp'), $portal);
        $lines[] = __('Voer per deelnemer een ticketcode in om de tour te starten.', 'sbdp');

        $support = (string) get_post_meta($tour_id, '_sbdp_tour_support_email', true);
        if ('' !== $support) {
            $lines[] = '';
            $lines[] = sprintf(__('Vragen? Mail naar %s.', 'sbdp'), $support);
        }

        $lines[] = '';
        $lines[] = sprintf(__('Bestelnummer: %s', 'sbdp'), $order->get_order_number());

        $subject = sprintf(__('[%s] Privétour tickets voor %s', 'sbdp'), $site_name, $tour_title);
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
        $ticket = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE token = %s LIMIT 1", $token),
            ARRAY_A
        );

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
        $ticket = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $ticket_id),
            ARRAY_A
        );

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

        $posts = get_posts(
            array(
                'post_type'      => 'sbdp_private_tour_step',
                'post_status'    => 'publish',
                'numberposts'    => -1,
                'post_parent'    => $tour_id,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            )
        );

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

        $wpdb->update(
            self::table(),
            array(
                'progress' => wp_json_encode($progress),
            ),
            array(
                'id' => $ticket_id,
            ),
            array('%s'),
            array('%d')
        );
    }

    /**
     * Decode stored progress.
     *
     * @param string|null $value Raw progress.
     *
     * @return array
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

        $wpdb->update(
            self::table(),
            array(
                'redeemed_at' => current_time('mysql', true),
            ),
            array(
                'id' => $ticket_id,
            ),
            array('%s'),
            array('%d')
        );
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
        $session = self::generate_token(32);

        set_transient(self::SESSION_PREFIX . $session, $ticket_id, self::SESSION_TTL);

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
        if (! $tour || 'sbdp_private_tour' !== $tour->post_type) {
            return null;
        }

        $token      = self::generate_token();
        $issued_to  = $user_id > 0 ? sprintf('user:%d', $user_id) : 'preview';
        $created_at = current_time('mysql', true);
        $expires_at = gmdate('Y-m-d H:i:s', time() + HOUR_IN_SECONDS);

        $inserted = $wpdb->insert(
            self::table(),
            array(
                'tour_id'       => $tour_id,
                'order_id'      => 0,
                'order_item_id' => 0,
                'email'         => '',
                'token'         => $token,
                'status'        => 'preview',
                'issued_to'     => $issued_to,
                'created_at'    => $created_at,
                'expires_at'    => $expires_at,
            ),
            array('%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );

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

        $count = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM " . self::table() . " WHERE order_item_id = %d", $order_item_id)
        );

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
        $ids = get_posts(
            array(
                'post_type'      => 'sbdp_private_tour',
                'post_status'    => 'any',
                'numberposts'    => 1,
                'fields'         => 'ids',
                'meta_key'       => '_sbdp_tour_product_id',
                'meta_value'     => $product_id,
                'no_found_rows'  => true,
            )
        );

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
        $posts = get_posts(
            array(
                'post_type'      => 'sbdp_private_tour_step',
                'post_status'    => 'publish',
                'numberposts'    => -1,
                'post_parent'    => $tour_id,
                'orderby'        => array(
                    'menu_order' => 'ASC',
                    'title'      => 'ASC',
                ),
            )
        );

        $steps = array();

        foreach ($posts as $index => $post) {
            $content = apply_filters('the_content', $post->post_content);

            if (class_exists('\Elementor\Plugin') && did_action('elementor/loaded')) {
                $document = \Elementor\Plugin::instance()->documents->get($post->ID);
                if ($document && $document->is_built_with_elementor()) {
                    $rendered = \Elementor\Plugin::instance()->frontend->get_builder_content($post->ID, true);
                    if ('' !== (string) $rendered) {
                        $content = $rendered;
                    }
                }
            }

            $steps[] = array(
                'id'          => (int) $post->ID,
                'index'       => $index,
                'title'       => get_the_title($post),
                'content'     => $content,
                'type'        => (string) get_post_meta($post->ID, '_sbdp_step_type', true),
                'mediaUrl'    => (string) get_post_meta($post->ID, '_sbdp_step_media_url', true),
                'vrAsset'     => (string) get_post_meta($post->ID, '_sbdp_step_vr_asset', true),
                'gamification'=> self::decode_gamification((string) get_post_meta($post->ID, '_sbdp_step_gamification', true)),
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

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE status = %s AND expires_at IS NOT NULL AND expires_at <= %s",
                'preview',
                $now
            )
        );
    }
}


