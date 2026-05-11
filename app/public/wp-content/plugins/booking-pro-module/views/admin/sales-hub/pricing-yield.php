<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $rules
 * @var array<int, array<string, mixed>> $audit
 */

$rulesJson = esc_attr(wp_json_encode($rules));
$auditLog = is_array($audit) ? $audit : array();
?>

<div class="wrap sbdp-saleshub-admin">
    <h1><?php esc_html_e('Sales Hub – prijzen en rendement', 'sbdp'); ?></h1>
    <p class="sbdp-saleshub-intro">
        <?php esc_html_e('Beheer prijsstrategie, kanaalaanpassingen, kortingsregels en rendementssturing voor planner en boekingsflows.', 'sbdp'); ?>
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
                    <p><?php esc_html_e('Schakel JavaScript in om prijsregels te bewerken. De huidige configuratie staat hieronder ter referentie.', 'sbdp'); ?></p>
                </div>
                <pre class="sbdp-saleshub-json"><?php echo esc_html(wp_json_encode($rules, JSON_PRETTY_PRINT)); ?></pre>
            </noscript>
        </div>

        <p class="submit">
            <button type="submit" class="button button-primary">
                <?php esc_html_e('Prijsregels opslaan', 'sbdp'); ?>
            </button>
        </p>
    </form>

    <section class="sbdp-saleshub-audit">
        <header>
            <h2><?php esc_html_e('Recente wijzigingen', 'sbdp'); ?></h2>
            <p><?php esc_html_e('De laatste 15 wijzigingen in prijsregels, rendement en kortingsdefinities.', 'sbdp'); ?></p>
        </header>
        <?php if ($auditLog === array()) : ?>
            <p><?php esc_html_e('Er zijn nog geen auditregels vastgelegd.', 'sbdp'); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Tijdstip', 'sbdp'); ?></th>
                        <th><?php esc_html_e('Actie', 'sbdp'); ?></th>
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
