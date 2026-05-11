<?php

declare(strict_types=1);

namespace BSP\CompetitorMonitor\Admin;

use BSP\CompetitorMonitor\Service\EliioApiClient;
use BSP\CompetitorMonitor\Service\PriceMonitorService;

final class DashboardPage
{
    private const TENANTS = [
        'Eropuitje.nl' => '019c9db6-92f5-716e-ab35-7cc7a0310272',
    ];

    public static function register(): void
    {
        \add_menu_page(
            'Concurrent Monitor',
            'Concurrent',
            'manage_options',
            'bsp-competitor-monitor',
            [self::class, 'render'],
            'dashicons-visibility',
            58
        );
    }

    public static function render(): void
    {
        if (! \current_user_can('manage_options')) {
            return;
        }

        $service  = new PriceMonitorService(new EliioApiClient(self::TENANTS));
        $snapshot = $service->getSnapshot();
        $history  = $service->getHistory();
        $email    = (string) \get_option('bsp_competitor_notify_email', \get_option('admin_email', ''));

        $ran   = isset($_GET['ran']);
        $saved = isset($_GET['saved']);

        $nextCron = \wp_next_scheduled('bsp_competitor_monitor_run');
        ?>
        <div class="wrap">
            <h1>🕵️ Concurrent Monitor — Eropuitje.nl</h1>

            <?php if ($ran): ?>
                <div class="notice notice-success is-dismissible"><p>Monitor is handmatig uitgevoerd.</p></div>
            <?php endif; ?>
            <?php if ($saved): ?>
                <div class="notice notice-success is-dismissible"><p>Instellingen opgeslagen.</p></div>
            <?php endif; ?>

            <div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:16px;">

                <!-- ACTIEVE PRIJZEN -->
                <div style="flex:1;min-width:360px;">
                    <h2>Huidige catalogus</h2>
                    <?php if (empty($snapshot)): ?>
                        <p><em>Nog geen snapshot. Klik "Nu uitvoeren" om te starten.</em></p>
                    <?php else: ?>
                        <?php foreach ($snapshot as $label => $products): ?>
                            <h3><?php echo \esc_html($label); ?> (<?php echo \count($products); ?> producten)</h3>
                            <table class="widefat striped" style="margin-bottom:20px;">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Prijs</th>
                                        <th>lv2</th>
                                        <th>Duur</th>
                                        <th>Max pers.</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($products as $product): ?>
                                    <tr>
                                        <td><strong><?php echo \esc_html((string) ($product['name'] ?? '')); ?></strong></td>
                                        <td>€ <?php echo \number_format((float) ($product['price'] ?? 0), 2, ',', '.'); ?></td>
                                        <td><?php echo $product['lv2Price'] !== null ? '€ ' . \number_format((float) $product['lv2Price'], 2, ',', '.') : '—'; ?></td>
                                        <td>
                                            <?php
                                            $opts = $product['options'] ?? [];
                                            if (\is_array($opts)) {
                                                echo \esc_html(\implode(' / ', \array_map(
                                                    static fn($m) => ($m >= 60 ? \floor($m / 60) . 'u' . ($m % 60 > 0 ? ($m % 60) . 'm' : '') : $m . 'm'),
                                                    $opts
                                                )));
                                            }
                                            ?>
                                        </td>
                                        <td><?php echo \esc_html((string) ($product['maxParticipant'] ?? '—')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- RECHTER KOLOM: acties + history -->
                <div style="flex:0 0 320px;">

                    <!-- Handmatig uitvoeren -->
                    <div class="postbox" style="padding:16px;margin-bottom:16px;">
                        <h2 class="hndle">⚡ Uitvoeren</h2>
                        <p>Volgende automatische check: <strong><?php echo $nextCron ? \date('d-m-Y H:i', $nextCron) : 'niet gepland'; ?></strong></p>
                        <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="bsp_competitor_run_now">
                            <?php \wp_nonce_field('bsp_competitor_run_now'); ?>
                            <button type="submit" class="button button-primary">Nu uitvoeren</button>
                        </form>
                    </div>

                    <!-- Notificatie e-mail -->
                    <div class="postbox" style="padding:16px;margin-bottom:16px;">
                        <h2 class="hndle">📧 Notificaties</h2>
                        <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="bsp_competitor_save_settings">
                            <?php \wp_nonce_field('bsp_competitor_save_settings'); ?>
                            <label>Stuur wijzigingen naar:
                                <input type="email"
                                       name="bsp_competitor_notify_email"
                                       value="<?php echo \esc_attr($email); ?>"
                                       class="widefat"
                                       style="margin-top:6px;">
                            </label>
                            <button type="submit" class="button" style="margin-top:10px;">Opslaan</button>
                        </form>
                    </div>

                    <!-- Wijzigingshistorie -->
                    <div class="postbox" style="padding:16px;">
                        <h2 class="hndle">📋 Wijzigingen (laatste 30 runs)</h2>
                        <?php if (empty($history)): ?>
                            <p><em>Nog geen geschiedenis.</em></p>
                        <?php else: ?>
                            <?php foreach ($history as $entry): ?>
                                <div style="border-bottom:1px solid #eee;padding:8px 0;">
                                    <small style="color:#888;"><?php echo \esc_html((string) ($entry['date'] ?? '')); ?></small><br>
                                    <?php
                                    $changes = $entry['changes'] ?? [];
                                    if (empty($changes)):
                                    ?>
                                        <span style="color:#46b450;">✓ Geen wijzigingen</span>
                                    <?php else: ?>
                                        <?php foreach ($changes as $change): ?>
                                            <?php
                                            $type = (string) ($change['type'] ?? '');
                                            $name = \esc_html((string) ($change['name'] ?? ''));
                                            if ($type === 'price_change'):
                                                $field = (string) ($change['field'] ?? 'price');
                                                $old   = $change['old_value'] !== null ? '€' . \number_format((float) $change['old_value'], 2, ',', '.') : '—';
                                                $new   = $change['new_value'] !== null ? '€' . \number_format((float) $change['new_value'], 2, ',', '.') : '—';
                                            ?>
                                                <div style="color:#d63638;">💰 <?php echo $name; ?> <small>(<?php echo \esc_html($field); ?>)</small>: <?php echo \esc_html($old); ?> → <?php echo \esc_html($new); ?></div>
                                            <?php elseif ($type === 'new_product'): ?>
                                                <div style="color:#007cba;">➕ Nieuw: <?php echo $name; ?></div>
                                            <?php elseif ($type === 'removed_product'): ?>
                                                <div style="color:#d63638;">➖ Verwijderd: <?php echo $name; ?></div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                </div>
            </div>
        </div>
        <?php
    }
}
