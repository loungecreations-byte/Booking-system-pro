<?php

declare(strict_types=1);

namespace BSP\PartnerProgram\Admin;

use BSP\PartnerProgram\Service\ClaimService;
use function add_action;
use function add_menu_page;
use function add_submenu_page;
use function current_user_can;
use function esc_html;
use function esc_html_e;
use function esc_url;
use function wp_create_nonce;
use function rest_url;
use function wp_enqueue_style;
use function wp_enqueue_script;
use function wp_localize_script;
use function plugins_url;
use function plugin_dir_path;
use function sanitize_text_field;
use function absint;
use function current_time;
use function admin_url;

/**
 * PartnerAdmin — registers WP admin pages for the Partner Program.
 *
 * All pages hang off the canonical `sbdp_bookings` parent menu.
 * Renders HTML-first views (no JS framework dependency).
 */
final class PartnerAdmin
{
    private const CAPABILITY  = 'manage_woocommerce';
    private const PARENT_SLUG = 'sbdp_bookings';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'registerMenus']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
        add_action('admin_post_bsp_approve_claim', [self::class, 'handleApproveClaim']);
        add_action('admin_post_bsp_reject_claim', [self::class, 'handleRejectClaim']);
        add_action('admin_post_bsp_create_settlement', [self::class, 'handleCreateSettlement']);
        add_action('admin_post_bsp_approve_settlement', [self::class, 'handleApproveSettlement']);
        add_action('admin_post_bsp_create_seed', [self::class, 'handleCreateSeed']);
    }

    public static function registerMenus(): void
    {
        $cap = current_user_can('manage_options') ? 'manage_options' : self::CAPABILITY;

        add_submenu_page(
            self::PARENT_SLUG,
            __('Partner Programma', 'sbdp'),
            __('Partners', 'sbdp'),
            $cap,
            'sbdp_partners',
            [self::class, 'renderPage']
        );
    }

    /**
     * Tab dispatcher — all partner sections in one page.
     */
    public static function renderPage(): void
    {
        $tab      = sanitize_key($_GET['tab'] ?? 'partners');
        $base_url = admin_url('admin.php?page=sbdp_partners');

        $tabs = [
            'partners'    => __('Partners', 'sbdp'),
            'claims'      => __('Aanvragen', 'sbdp'),
            'settlements' => __('Uitbetalingen', 'sbdp'),
            'commissions' => __('Commissies', 'sbdp'),
            'seeds'       => __('Locaties', 'sbdp'),
            'settings'    => __('Instellingen', 'sbdp'),
        ];

        echo '<div class="wrap bsp-partner-admin">';
        echo '<h1>' . esc_html__('Partner Programma', 'sbdp') . '</h1>';
        echo '<nav class="nav-tab-wrapper" style="margin-bottom:0">';
        foreach ($tabs as $slug => $label) {
            $class = $slug === $tab ? ' nav-tab-active' : '';
            $url   = add_query_arg('tab', $slug, $base_url);
            echo '<a href="' . esc_url($url) . '" class="nav-tab' . esc_attr($class) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
        echo '<div style="margin-top:20px;">';

        switch ($tab) {
            case 'claims':
                self::renderClaimsContent();
                break;
            case 'settlements':
                self::renderSettlementsContent();
                break;
            case 'commissions':
                self::renderCommissionsContent();
                break;
            case 'seeds':
                self::renderSeedsContent();
                break;
            case 'settings':
                self::renderSettingsContent();
                break;
            default:
                self::renderPartnersContent();
                break;
        }

        echo '</div></div>';
    }

    public static function enqueueAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'sbdp_bookings_page_sbdp_partners') {
            return;
        }

        wp_enqueue_style(
            'bsp-partner-admin',
            plugins_url('modules/partner-program/assets/admin.css', dirname(__DIR__, 2) . '/booking-pro-module.php'),
            [],
            '1.0.0'
        );
    }

    // -------------------------------------------------------------------------
    // Partners overview
    // -------------------------------------------------------------------------

    private static function renderPartnersContent(): void
    {
        global $wpdb;
        $accounts = $wpdb->get_results(
            "SELECT pa.*, be.legal_name, be.trade_name, v.vendor_name, v.contact_email
             FROM {$wpdb->prefix}bsp_partner_accounts pa
             LEFT JOIN {$wpdb->prefix}bsp_business_entities be ON be.id = pa.business_entity_id
             LEFT JOIN {$wpdb->prefix}bsp_vendors v ON v.id = pa.vendor_id
             ORDER BY pa.id DESC
             LIMIT 200",
            ARRAY_A
        ) ?: [];

        ?>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Bedrijf', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Vendor', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Tier', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Status', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Mode', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Aangemeld', 'sbdp'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($accounts)) : ?>
                        <tr><td colspan="7"><?php esc_html_e('Geen partners gevonden.', 'sbdp'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($accounts as $a) : ?>
                            <tr>
                                <td><?php echo (int) $a['id']; ?></td>
                                <td><?php echo esc_html($a['trade_name'] ?: $a['legal_name'] ?: '—'); ?></td>
                                <td><?php echo esc_html($a['vendor_name'] ?: '—'); ?></td>
                                <td><span class="bsp-tier bsp-tier--<?php echo esc_attr($a['partner_tier']); ?>"><?php echo esc_html(ucfirst($a['partner_tier'])); ?></span></td>
                                <td><?php echo esc_html($a['account_status']); ?></td>
                                <td><?php echo esc_html($a['commercial_mode']); ?></td>
                                <td><?php echo esc_html(substr((string) $a['created_at'], 0, 10)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php
    }

    // -------------------------------------------------------------------------
    // Claims
    // -------------------------------------------------------------------------

    private static function renderClaimsContent(): void
    {
        global $wpdb;

        // Handle success/error messages.
        $msg = sanitize_text_field($_GET['bsp_msg'] ?? '');

        $claims = $wpdb->get_results(
            "SELECT cr.*, ps.name AS seed_name, u.display_name AS user_name, u.user_email
             FROM {$wpdb->prefix}bsp_claim_requests cr
             LEFT JOIN {$wpdb->prefix}bsp_place_seeds ps ON ps.id = cr.place_seed_id
             LEFT JOIN {$wpdb->prefix}users u ON u.ID = cr.claimant_wp_user_id
             ORDER BY cr.submitted_at DESC
             LIMIT 200",
            ARRAY_A
        ) ?: [];

        ?>

            <?php if ($msg === 'approved') : ?>
                <div class="notice notice-success"><p><?php esc_html_e('Aanvraag goedgekeurd.', 'sbdp'); ?></p></div>
            <?php elseif ($msg === 'rejected') : ?>
                <div class="notice notice-warning"><p><?php esc_html_e('Aanvraag afgewezen.', 'sbdp'); ?></p></div>
            <?php endif; ?>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Locatie', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Aanvrager', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Status', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Ingediend', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Acties', 'sbdp'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($claims)) : ?>
                        <tr><td colspan="6"><?php esc_html_e('Geen aanvragen gevonden.', 'sbdp'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($claims as $c) : ?>
                            <tr>
                                <td><?php echo (int) $c['id']; ?></td>
                                <td><?php echo esc_html($c['seed_name'] ?? '—'); ?></td>
                                <td><?php echo esc_html($c['user_name'] ?? '—'); ?> <br><small><?php echo esc_html($c['user_email'] ?? ''); ?></small></td>
                                <td><code><?php echo esc_html($c['claim_status']); ?></code></td>
                                <td><?php echo esc_html(substr((string) $c['submitted_at'], 0, 10)); ?></td>
                                <td>
                                    <?php if ($c['claim_status'] === 'under_review') : ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                                            <input type="hidden" name="action" value="bsp_approve_claim">
                                            <input type="hidden" name="claim_id" value="<?php echo (int) $c['id']; ?>">
                                            <?php wp_nonce_field('bsp_approve_claim_' . $c['id'], '_wpnonce'); ?>
                                            <button class="button button-primary"><?php esc_html_e('Goedkeuren', 'sbdp'); ?></button>
                                        </form>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                                            <input type="hidden" name="action" value="bsp_reject_claim">
                                            <input type="hidden" name="claim_id" value="<?php echo (int) $c['id']; ?>">
                                            <?php wp_nonce_field('bsp_reject_claim_' . $c['id'], '_wpnonce'); ?>
                                            <button class="button"><?php esc_html_e('Afwijzen', 'sbdp'); ?></button>
                                        </form>
                                    <?php else : ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php
    }

    // -------------------------------------------------------------------------
    // Commissions overview (unbatched + batched items)
    // -------------------------------------------------------------------------

    private static function renderCommissionsContent(): void
    {
        global $wpdb;

        $vendorFilter  = absint($_GET['vendor_id'] ?? 0);
        $statusFilter  = sanitize_key($_GET['item_status'] ?? '');
        $perPage       = 50;
        $page          = max(1, absint($_GET['paged'] ?? 1));
        $offset        = ($page - 1) * $perPage;

        $itemsTable   = $wpdb->prefix . 'bsp_settlement_items';
        $mastersTable = $wpdb->prefix . 'bsp_booking_masters';
        $batchesTable = $wpdb->prefix . 'bsp_settlement_batches';
        $vendorsTable = $wpdb->prefix . 'bsp_vendors';

        $where  = ['1=1'];
        $params = [];

        if ($vendorFilter) {
            $where[]  = 'si.vendor_id = %d';
            $params[] = $vendorFilter;
        }

        if ($statusFilter) {
            $where[]  = 'si.item_status = %s';
            $params[] = $statusFilter;
        }

        $whereClause = implode(' AND ', $where);

        $queryArgs = array_merge(["SELECT COUNT(*) FROM {$itemsTable} si WHERE {$whereClause}"], $params);
        $total = (int) $wpdb->get_var($wpdb->prepare(...$queryArgs));

        $queryParams = array_merge($params, [$perPage, $offset]);
        $items       = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT si.*, v.vendor_name, DATE(bm.created_at) AS booking_date,
                        sb.batch_reference, sb.period_label
                 FROM {$itemsTable} si
                 LEFT JOIN {$vendorsTable} v ON v.id = si.vendor_id
                 LEFT JOIN {$mastersTable} bm ON bm.id = si.booking_master_id
                 LEFT JOIN {$batchesTable} sb ON sb.id = si.batch_id AND si.batch_id > 0
                 WHERE {$whereClause}
                 ORDER BY si.id DESC
                 LIMIT %d OFFSET %d",
                ...$queryParams
            ),
            ARRAY_A
        ) ?: [];

        $pageUrl = admin_url('admin.php?page=sbdp_partners&tab=commissions');
        ?>
        <h2><?php esc_html_e('Partner Commissies', 'sbdp'); ?></h2>

            <form method="get" style="margin-bottom: 12px;">
                <input type="hidden" name="page" value="sbdp_partners">
                <input type="hidden" name="tab" value="commissions">
                <label><?php esc_html_e('Vendor ID:', 'sbdp'); ?>
                    <input type="number" name="vendor_id" value="<?php echo esc_attr($vendorFilter ?: ''); ?>" min="0" style="width:80px">
                </label>
                &nbsp;
                <label><?php esc_html_e('Status:', 'sbdp'); ?>
                    <select name="item_status">
                        <option value=""><?php esc_html_e('Alle', 'sbdp'); ?></option>
                        <?php foreach (['pending', 'in_review', 'approved', 'paid', 'cancelled'] as $s) : ?>
                            <option value="<?php echo esc_attr($s); ?>" <?php selected($statusFilter, $s); ?>><?php echo esc_html($s); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                &nbsp;
                <button class="button"><?php esc_html_e('Filter', 'sbdp'); ?></button>
            </form>

            <p><?php printf(esc_html__('%d items gevonden.', 'sbdp'), $total); ?></p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Vendor', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Datum', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Bruto', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Commissie %', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Commissie €', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Uitbetaling', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Status', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Batch', 'sbdp'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)) : ?>
                        <tr><td colspan="9"><?php esc_html_e('Geen commissie-items gevonden.', 'sbdp'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($items as $i) : ?>
                            <tr>
                                <td><?php echo (int) $i['id']; ?></td>
                                <td><?php echo esc_html($i['vendor_name'] ?? '—'); ?> <small>(#<?php echo (int) $i['vendor_id']; ?>)</small></td>
                                <td><?php echo esc_html($i['booking_date'] ?? '—'); ?></td>
                                <td>€<?php echo number_format((float) $i['gross_eur'], 2, ',', '.'); ?></td>
                                <td><?php echo number_format((float) $i['commission_rate'] * 100, 1); ?>%</td>
                                <td>€<?php echo number_format((float) $i['commission_eur'], 2, ',', '.'); ?></td>
                                <td>€<?php echo number_format((float) $i['payout_eur'], 2, ',', '.'); ?></td>
                                <td><code><?php echo esc_html($i['item_status']); ?></code></td>
                                <td>
                                    <?php if ($i['batch_id'] && $i['batch_id'] > 0) : ?>
                                        <code><?php echo esc_html($i['batch_reference'] ?? '#' . $i['batch_id']); ?></code>
                                        <?php if ($i['period_label']) : ?>
                                            <br><small><?php echo esc_html($i['period_label']); ?></small>
                                        <?php endif; ?>
                                    <?php else : ?>
                                        <em><?php esc_html_e('Nog niet in batch', 'sbdp'); ?></em>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php
            $totalPages = (int) ceil($total / $perPage);
            if ($totalPages > 1) :
                echo '<div class="tablenav"><div class="tablenav-pages">';
                for ($p = 1; $p <= $totalPages; $p++) {
                    $url = add_query_arg(['paged' => $p, 'vendor_id' => $vendorFilter ?: null, 'item_status' => $statusFilter ?: null], $pageUrl);
                    $cls = $p === $page ? ' current' : '';
                    printf('<a class="button%s" href="%s">%d</a> ', esc_attr($cls), esc_url($url), $p);
                }
                echo '</div></div>';
            endif;
            ?>
        <?php
    }

    // -------------------------------------------------------------------------
    // Settlements
    // -------------------------------------------------------------------------

    private static function renderSettlementsContent(): void
    {
        global $wpdb;

        $msg = sanitize_text_field($_GET['bsp_msg'] ?? '');

        $batches = $wpdb->get_results(
            "SELECT sb.*, COUNT(si.id) AS item_count
             FROM {$wpdb->prefix}bsp_settlement_batches sb
             LEFT JOIN {$wpdb->prefix}bsp_settlement_items si ON si.batch_id = sb.id
             GROUP BY sb.id
             ORDER BY sb.id DESC
             LIMIT 100",
            ARRAY_A
        ) ?: [];

        // Count pending items not yet in a batch.
        $pendingCount = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}bsp_settlement_items WHERE batch_id = 0 AND item_status = 'pending'"
        );

        ?>
            <?php if ($msg === 'created') : ?>
                <div class="notice notice-success"><p><?php esc_html_e('Batch aangemaakt.', 'sbdp'); ?></p></div>
            <?php elseif ($msg === 'approved') : ?>
                <div class="notice notice-success"><p><?php esc_html_e('Batch goedgekeurd.', 'sbdp'); ?></p></div>
            <?php endif; ?>

            <div class="bsp-settlement-create" style="margin:16px 0; padding:16px; background:#fff; border:1px solid #ccd0d4; border-radius:4px;">
                <h2><?php esc_html_e('Nieuwe batch aanmaken', 'sbdp'); ?></h2>
                <p><?php printf(esc_html__('%d openstaande items beschikbaar.', 'sbdp'), $pendingCount); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="bsp_create_settlement">
                    <?php wp_nonce_field('bsp_create_settlement', '_wpnonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e('Label', 'sbdp'); ?></th>
                            <td><input type="text" name="period_label" class="regular-text" placeholder="<?php echo esc_attr(date('Y-m')); ?>" required></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Start datum', 'sbdp'); ?></th>
                            <td><input type="date" name="period_start" value="<?php echo esc_attr(date('Y-m-01')); ?>" required></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Eind datum', 'sbdp'); ?></th>
                            <td><input type="date" name="period_end" value="<?php echo esc_attr(date('Y-m-t')); ?>" required></td>
                        </tr>
                    </table>
                    <button class="button button-primary" <?php disabled($pendingCount, 0); ?>><?php esc_html_e('Batch aanmaken', 'sbdp'); ?></button>
                </form>
            </div>

            <h2><?php esc_html_e('Batches', 'sbdp'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Referentie', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Periode', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Status', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Items', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Uitbetaling', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Acties', 'sbdp'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($batches)) : ?>
                        <tr><td colspan="7"><?php esc_html_e('Geen batches gevonden.', 'sbdp'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($batches as $b) : ?>
                            <tr>
                                <td><?php echo (int) $b['id']; ?></td>
                                <td><code><?php echo esc_html($b['batch_reference']); ?></code></td>
                                <td><?php echo esc_html($b['period_start'] . ' / ' . $b['period_end']); ?></td>
                                <td><code><?php echo esc_html($b['batch_status']); ?></code></td>
                                <td><?php echo (int) $b['item_count']; ?></td>
                                <td>€<?php echo number_format((float) $b['total_payout_eur'], 2, ',', '.'); ?></td>
                                <td>
                                    <?php if ($b['batch_status'] === 'draft') : ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                                            <input type="hidden" name="action" value="bsp_approve_settlement">
                                            <input type="hidden" name="batch_id" value="<?php echo (int) $b['id']; ?>">
                                            <?php wp_nonce_field('bsp_approve_settlement_' . $b['id'], '_wpnonce'); ?>
                                            <button class="button button-primary"><?php esc_html_e('Goedkeuren', 'sbdp'); ?></button>
                                        </form>
                                    <?php else : ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php
    }

    // -------------------------------------------------------------------------
    // Place seeds
    // -------------------------------------------------------------------------

    private static function renderSeedsContent(): void
    {
        global $wpdb;

        $seeds = $wpdb->get_results(
            "SELECT s.*, (SELECT COUNT(*) FROM {$wpdb->prefix}bsp_claim_requests cr WHERE cr.place_seed_id = s.id) AS claim_count
             FROM {$wpdb->prefix}bsp_place_seeds s
             ORDER BY s.id DESC
             LIMIT 500",
            ARRAY_A
        ) ?: [];

        $msg = sanitize_text_field($_GET['bsp_msg'] ?? '');

        ?>
            <p><?php esc_html_e('Seeds zijn externe bronrecords (Google, handmatig). Ze zijn discovery only — geen commerciële waarheid.', 'sbdp'); ?></p>

            <?php if ($msg === 'seed_created') : ?>
                <div class="notice notice-success"><p><?php esc_html_e('Seed aangemaakt.', 'sbdp'); ?></p></div>
            <?php elseif ($msg === 'seed_error') : ?>
                <div class="notice notice-error"><p><?php esc_html_e('Fout bij aanmaken seed. Controleer alle velden.', 'sbdp'); ?></p></div>
            <?php endif; ?>

            <div style="margin: 16px 0; padding: 16px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
                <h2><?php esc_html_e('Handmatig seed toevoegen', 'sbdp'); ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="bsp_create_seed">
                    <?php wp_nonce_field('bsp_create_seed', '_wpnonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="bsp_seed_name"><?php esc_html_e('Naam *', 'sbdp'); ?></label></th>
                            <td><input type="text" id="bsp_seed_name" name="name" class="regular-text" required maxlength="255"></td>
                        </tr>
                        <tr>
                            <th><label for="bsp_seed_address"><?php esc_html_e('Adres', 'sbdp'); ?></label></th>
                            <td><input type="text" id="bsp_seed_address" name="address" class="regular-text" maxlength="500"></td>
                        </tr>
                        <tr>
                            <th><label for="bsp_seed_city"><?php esc_html_e('Stad', 'sbdp'); ?></label></th>
                            <td><input type="text" id="bsp_seed_city" name="city" class="regular-text" maxlength="200"></td>
                        </tr>
                        <tr>
                            <th><label for="bsp_seed_postal_code"><?php esc_html_e('Postcode', 'sbdp'); ?></label></th>
                            <td><input type="text" id="bsp_seed_postal_code" name="postal_code" class="small-text" maxlength="20"></td>
                        </tr>
                        <tr>
                            <th><label for="bsp_seed_phone"><?php esc_html_e('Telefoon', 'sbdp'); ?></label></th>
                            <td><input type="text" id="bsp_seed_phone" name="phone" class="regular-text" maxlength="50"></td>
                        </tr>
                        <tr>
                            <th><label for="bsp_seed_website"><?php esc_html_e('Website', 'sbdp'); ?></label></th>
                            <td><input type="url" id="bsp_seed_website" name="website" class="regular-text" maxlength="500"></td>
                        </tr>
                        <tr>
                            <th><label for="bsp_seed_lat"><?php esc_html_e('Breedtegraad', 'sbdp'); ?></label></th>
                            <td><input type="number" id="bsp_seed_lat" name="lat" step="0.0000001" min="-90" max="90" class="small-text"></td>
                        </tr>
                        <tr>
                            <th><label for="bsp_seed_lng"><?php esc_html_e('Lengtegraad', 'sbdp'); ?></label></th>
                            <td><input type="number" id="bsp_seed_lng" name="lng" step="0.0000001" min="-180" max="180" class="small-text"></td>
                        </tr>
                    </table>
                    <button class="button button-primary"><?php esc_html_e('Seed aanmaken', 'sbdp'); ?></button>
                </form>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Naam', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Stad', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Bron', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Status', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Aanvragen', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Gesynchroniseerd', 'sbdp'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($seeds)) : ?>
                        <tr><td colspan="7"><?php esc_html_e('Geen seeds gevonden. Voer wp bsp-partner seeds sync uit om Google-data te importeren.', 'sbdp'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($seeds as $s) : ?>
                            <tr>
                                <td><?php echo (int) $s['id']; ?></td>
                                <td><?php echo esc_html($s['name']); ?></td>
                                <td><?php echo esc_html($s['city'] ?? '—'); ?></td>
                                <td><?php echo esc_html($s['external_source']); ?></td>
                                <td><code><?php echo esc_html($s['sync_status']); ?></code></td>
                                <td><?php echo (int) $s['claim_count']; ?></td>
                                <td><?php echo esc_html(substr((string) $s['last_synced_at'], 0, 10) ?: '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php
    }

    // -------------------------------------------------------------------------
    // Settings (Partner Instellingen)
    // -------------------------------------------------------------------------

    private static function renderSettingsContent(): void
    {
        ?>
        <form method="post" action="options.php">
            <?php
            settings_fields('sbdp_partner_settings');
            do_settings_sections('sbdp_partner_settings');
            submit_button(__('Opslaan', 'sbdp'));
            ?>
        </form>
        <?php
    }

    // -------------------------------------------------------------------------
    // Form handlers (admin-post)
    // -------------------------------------------------------------------------

    public static function handleApproveClaim(): void
    {
        if (! current_user_can('manage_options') && ! current_user_can('manage_woocommerce')) {
            wp_die('Toegang geweigerd.');
        }

        $claimId = absint($_POST['claim_id'] ?? 0);
        check_admin_referer('bsp_approve_claim_' . $claimId);

        ClaimService::adminApproveClaim($claimId, get_current_user_id());
        wp_safe_redirect(add_query_arg(['page' => 'sbdp_partner_claims', 'bsp_msg' => 'approved'], admin_url('admin.php')));
        exit;
    }

    public static function handleRejectClaim(): void
    {
        if (! current_user_can('manage_options') && ! current_user_can('manage_woocommerce')) {
            wp_die('Toegang geweigerd.');
        }

        $claimId = absint($_POST['claim_id'] ?? 0);
        check_admin_referer('bsp_reject_claim_' . $claimId);

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'bsp_claim_requests',
            ['claim_status' => 'rejected', 'reviewed_by' => get_current_user_id(), 'reviewed_at' => current_time('mysql')],
            ['id' => $claimId]
        );

        wp_safe_redirect(add_query_arg(['page' => 'sbdp_partner_claims', 'bsp_msg' => 'rejected'], admin_url('admin.php')));
        exit;
    }

    public static function handleCreateSettlement(): void
    {
        if (! current_user_can('manage_options') && ! current_user_can('manage_woocommerce')) {
            wp_die('Toegang geweigerd.');
        }

        check_admin_referer('bsp_create_settlement');

        $label = sanitize_text_field($_POST['period_label'] ?? date('Y-m'));
        $start = sanitize_text_field($_POST['period_start'] ?? date('Y-m-01'));
        $end   = sanitize_text_field($_POST['period_end'] ?? date('Y-m-t'));

        \BSP\PartnerProgram\Service\SettlementService::createBatch($label, $start, $end, get_current_user_id());

        wp_safe_redirect(add_query_arg(['page' => 'sbdp_partners', 'tab' => 'settlements', 'bsp_msg' => 'created'], admin_url('admin.php')));
        exit;
    }

    public static function handleApproveSettlement(): void
    {
        if (! current_user_can('manage_options') && ! current_user_can('manage_woocommerce')) {
            wp_die('Toegang geweigerd.');
        }

        $batchId = absint($_POST['batch_id'] ?? 0);
        check_admin_referer('bsp_approve_settlement_' . $batchId);

        \BSP\PartnerProgram\Service\SettlementService::approveBatch($batchId, get_current_user_id());

        wp_safe_redirect(add_query_arg(['page' => 'sbdp_partners', 'tab' => 'settlements', 'bsp_msg' => 'approved'], admin_url('admin.php')));
        exit;
    }

    public static function handleCreateSeed(): void
    {
        if (! current_user_can('manage_options') && ! current_user_can('manage_woocommerce')) {
            wp_die('Toegang geweigerd.');
        }

        check_admin_referer('bsp_create_seed');

        $name       = sanitize_text_field($_POST['name'] ?? '');
        $address    = sanitize_text_field($_POST['address'] ?? '');
        $city       = sanitize_text_field($_POST['city'] ?? '');
        $postalCode = sanitize_text_field($_POST['postal_code'] ?? '');
        $phone      = sanitize_text_field($_POST['phone'] ?? '');
        $website    = esc_url_raw($_POST['website'] ?? '');
        $latRaw = isset($_POST['lat']) && $_POST['lat'] !== '' ? (float) $_POST['lat'] : null;
        $lngRaw = isset($_POST['lng']) && $_POST['lng'] !== '' ? (float) $_POST['lng'] : null;
        $lat = ($latRaw !== null && $latRaw >= -90.0 && $latRaw <= 90.0) ? $latRaw : null;
        $lng = ($lngRaw !== null && $lngRaw >= -180.0 && $lngRaw <= 180.0) ? $lngRaw : null;

        if (! $name) {
            wp_safe_redirect(add_query_arg(['page' => 'sbdp_partners', 'tab' => 'seeds', 'bsp_msg' => 'seed_error'], admin_url('admin.php')));
            exit;
        }

        global $wpdb;
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'bsp_place_seeds',
            [
                'name'            => $name,
                'address'         => $address ?: null,
                'city'            => $city ?: null,
                'postal_code'     => $postalCode ?: null,
                'phone'           => $phone ?: null,
                'website'         => $website ?: null,
                'lat'             => $lat,
                'lng'             => $lng,
                'external_source' => 'manual',
                'sync_status'     => 'synced',
                'last_synced_at'  => current_time('mysql', true),
                'created_at'      => current_time('mysql', true),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s']
        );

        $msg = $inserted ? 'seed_created' : 'seed_error';
        wp_safe_redirect(add_query_arg(['page' => 'sbdp_partners', 'tab' => 'seeds', 'bsp_msg' => $msg], admin_url('admin.php')));
        exit;
    }
}
