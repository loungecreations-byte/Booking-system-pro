<?php

declare(strict_types=1);

namespace SBDP\Modules\Arrangements\Admin;

use SBDP\Modules\Arrangements\Domain\ArrangementMigrationService;
use SBDP\Modules\Arrangements\Domain\ArrangementRepository;
use SBDP\Modules\Arrangements\Domain\ArrangementSchema;

use function admin_url;
use function add_action;
use function add_submenu_page;
use function current_user_can;
use function esc_html__;
use function esc_url;
use function get_posts;
use function get_post_status;
use function home_url;
use function wp_nonce_url;

final class Menu
{
    public function register(): void
    {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_post_sbdp_arrangements_migrate_legacy', array($this, 'handleLegacyMigration'));
    }

    public function menu(): void
    {
        $capability = current_user_can('manage_woocommerce') ? 'manage_woocommerce' : 'manage_options';
        add_submenu_page(
            'sbdp_bookings',
            __('Arrangements', 'sbdp'),
            __('Arrangements', 'sbdp'),
            $capability,
            'sbdp_arrangements_overview',
            array($this, 'renderOverview')
        );
        add_submenu_page(
            'sbdp_bookings',
            __('All arrangements', 'sbdp'),
            __('All arrangements', 'sbdp'),
            $capability,
            'edit.php?post_type=' . ArrangementSchema::POST_TYPE
        );
        add_submenu_page(
            'sbdp_bookings',
            __('New arrangement', 'sbdp'),
            __('New arrangement', 'sbdp'),
            $capability,
            'post-new.php?post_type=' . ArrangementSchema::POST_TYPE
        );
    }

    public function renderOverview(): void
    {
        $repository = new ArrangementRepository();
        $items = $repository->query();
        $counts = array(
            'publish' => 0,
            'draft' => 0,
            'archived' => 0,
            'template' => 0,
        );

        foreach ($items as $item) {
            $status = (string) ($item['status'] ?? '');
            if ($status === 'sbdp_archived') {
                $counts['archived']++;
            } elseif (isset($counts[$status])) {
                $counts[$status]++;
            }

            if ((string) ($item['creation_mode'] ?? '') === 'template') {
                $counts['template']++;
            }
        }

        $migrateUrl = wp_nonce_url(admin_url('admin-post.php?action=sbdp_arrangements_migrate_legacy'), 'sbdp_arrangements_migrate_legacy');
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Arrangements', 'sbdp') . '</h1>';
        echo '<p>' . esc_html__('Beheer vaste arrangementen, templates en arrangement-instances vanuit één domeinmodel.', 'sbdp') . '</p>';
        echo '<p>';
        echo '<a class="button button-primary" href="' . esc_url(admin_url('post-new.php?post_type=' . ArrangementSchema::POST_TYPE)) . '">' . esc_html__('Nieuw arrangement', 'sbdp') . '</a> ';
        echo '<a class="button" href="' . esc_url(admin_url('edit.php?post_type=' . ArrangementSchema::POST_TYPE)) . '">' . esc_html__('Overzicht', 'sbdp') . '</a> ';
        echo '<a class="button" href="' . esc_url($migrateUrl) . '">' . esc_html__('Migreer legacy bundles', 'sbdp') . '</a>';
        echo '</p>';
        echo '<div class="notice notice-info"><p>' . esc_html__('Vaste arrangementen zijn publishable objects; dynamic en customized varianten worden via dezelfde modelstructuur opgebouwd.', 'sbdp') . '</p></div>';
        echo '<ul style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;max-width:960px;">';
        foreach ($counts as $label => $count) {
            echo '<li style="background:#fff;border:1px solid #dcdcde;padding:16px;border-radius:10px;"><strong>' . esc_html(ucfirst($label)) . '</strong><br>' . esc_html((string) $count) . '</li>';
        }
        echo '</ul>';
        echo '</div>';
    }

    public function handleLegacyMigration(): void
    {
        if (! current_user_can('manage_woocommerce') && ! current_user_can('manage_options')) {
            wp_die(esc_html__('Geen toegang.', 'sbdp'));
        }

        check_admin_referer('sbdp_arrangements_migrate_legacy');
        $result = (new ArrangementMigrationService())->migrateLegacyBundles();

        wp_safe_redirect(add_query_arg(array('sbdp_arrangements_migrated' => rawurlencode(wp_json_encode($result))), admin_url('edit.php?post_type=' . ArrangementSchema::POST_TYPE)));
        exit;
    }
}
