<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $rules
 * @var array<int, array<string, mixed>> $audit
 */

use function date_i18n;
use function esc_attr;
use function esc_html;
use function esc_html_e;
use function esc_url;
use function get_option;
use function wp_json_encode;
use function wp_nonce_field;

$rulesJson = esc_attr(wp_json_encode($rules));
$auditLog = is_array($audit) ? $audit : array();
?>

<div class="wrap sbdp-saleshub-admin">
    <h1><?php esc_html_e('Sales Hub – Pricing & Yield', 'sbdp'); ?></h1>
    <p class="sbdp-saleshub-intro">
        <?php esc_html_e('Configure global price strategy, channel adjustments, coupons, and yield automation for the planner and booking flows.', 'sbdp'); ?>
    </p>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="sbdp-saleshub-form">
        <?php wp_nonce_field('sbdp_saleshub_save'); ?>
        <input type="hidden" name="action" value="sbdp_saleshub_save">
        <input type="hidden" id="sbdp_saleshub_rules" name="sbdp_saleshub_rules" value="<?php echo $rulesJson; ?>">

        <div
            id="sbdp-saleshub-app"
            class="sbdp-saleshub-app"
            data-rules="<?php echo $rulesJson; ?>"
        >
            <noscript>
                <div class="notice notice-warning">
                    <p><?php esc_html_e('Enable JavaScript to edit pricing rules. The current configuration is displayed below for reference.', 'sbdp'); ?></p>
                </div>
                <pre class="sbdp-saleshub-json"><?php echo esc_html(wp_json_encode($rules, JSON_PRETTY_PRINT)); ?></pre>
            </noscript>
        </div>

        <p class="submit">
            <button type="submit" class="button button-primary">
                <?php esc_html_e('Save Pricing Rules', 'sbdp'); ?>
            </button>
        </p>
    </form>

    <section class="sbdp-saleshub-audit">
        <header>
            <h2><?php esc_html_e('Recent Updates', 'sbdp'); ?></h2>
            <p><?php esc_html_e('Last 15 changes across pricing, yield, and coupon definitions.', 'sbdp'); ?></p>
        </header>
        <?php if ($auditLog === array()) : ?>
            <p><?php esc_html_e('No audit entries recorded yet.', 'sbdp'); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Timestamp', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Action', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Details', 'sbdp'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($auditLog as $entry) : ?>
                        <tr>
                            <td>
                                <?php
                                $ts = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
                                echo esc_html(date_i18n(get_option('date_format', 'Y-m-d H:i'), $ts));
                                ?>
                            </td>
                            <td><?php echo esc_html((string) ($entry['action'] ?? '')); ?></td>
                            <td>
                                <code><?php echo esc_html(wp_json_encode($entry['diff'] ?? array())); ?></code>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>
