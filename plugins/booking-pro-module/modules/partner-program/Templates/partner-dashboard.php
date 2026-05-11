<?php
/** @var array $account */
/** @var string $businessName */
/** @var string $tier */
/** @var string $status */
/** @var string $mode */
/** @var array $entitlements */
/** @var array $recentItems */
/** @var float $pendingTotal */
/** @var \WP_User $user */
/** @var string $payoutProfileUrl URL of the payout profile page, or empty string */

$tierLabel = [
    'basis'   => 'Basis',
    'premium' => 'Premium',
    'gold'    => 'Gold',
][$tier] ?? ucfirst($tier);

$isOnboarding = in_array($status, ['onboarding', 'pending_verification'], true);
?>
<div class="bsp-partner-portal">

    <header class="bsp-portal-header">
        <div class="bsp-portal-header__business">
            <h1><?php echo esc_html($businessName); ?></h1>
            <span class="bsp-tier bsp-tier--<?php echo esc_attr($tier); ?>"><?php echo esc_html($tierLabel); ?></span>
            <span class="bsp-portal-status bsp-portal-status--<?php echo esc_attr($status); ?>"><?php echo esc_html($status); ?></span>
        </div>
    </header>

    <?php if ($isOnboarding) : ?>
    <div class="bsp-portal-notice bsp-portal-notice--info">
        <strong><?php esc_html_e('Uw account wordt verwerkt.', 'sbdp'); ?></strong>
        <?php esc_html_e('Zodra uw account geactiveerd is, ziet u hier uw pakket en boekingsoverzicht. Stel alvast uw uitbetalingsgegevens in.', 'sbdp'); ?>
        <?php if ($payoutProfileUrl) : ?>
            <a class="bsp-btn bsp-btn--secondary bsp-portal-notice__cta"
               href="<?php echo esc_url($payoutProfileUrl); ?>">
                <?php esc_html_e('Stel uw uitbetalingsgegevens in', 'sbdp'); ?>
            </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="bsp-portal-grid">

        <!-- Entitlements card -->
        <section class="bsp-portal-card">
            <h2><?php esc_html_e('Uw pakket', 'sbdp'); ?></h2>
            <ul class="bsp-entitlement-list">
                <?php
                $labels = [
                    'max_offers'           => 'Max. aanbiedingen',
                    'max_users'            => 'Max. gebruikers',
                    'max_locations'        => 'Max. locaties',
                    'listing_visibility'   => 'Zichtbaarheid',
                    'featured_eligible'    => 'Uitgelicht mogelijk',
                    'lead_routing'         => 'Leadrouting',
                    'booking_access'       => 'Online boeken',
                    'reporting_depth'      => 'Rapportages',
                    'support_priority'     => 'Support',
                    'campaign_eligible'    => 'Campagnes',
                    'commission_rate_pct'  => 'Commissie',
                ];
                foreach ($labels as $key => $label) :
                    $value = $entitlements[$key] ?? '—';
                    if (is_bool($value)) {
                        $display = $value ? '✓' : '✗';
                    } elseif ($key === 'commission_rate_pct') {
                        $display = number_format((float) $value, 0) . '%';
                    } elseif ($value === -1) {
                        $display = 'Onbeperkt';
                    } else {
                        $display = esc_html((string) $value);
                    }
                ?>
                    <li class="bsp-entitlement-list__item">
                        <span class="bsp-entitlement-list__label"><?php echo esc_html($label); ?></span>
                        <span class="bsp-entitlement-list__value"><?php echo esc_html($display); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <!-- Financial summary card -->
        <section class="bsp-portal-card">
            <h2><?php esc_html_e('Financieel', 'sbdp'); ?></h2>

            <div class="bsp-portal-stat">
                <span class="bsp-portal-stat__label"><?php esc_html_e('Openstaand', 'sbdp'); ?></span>
                <span class="bsp-portal-stat__value">€<?php echo number_format($pendingTotal, 2, ',', '.'); ?></span>
            </div>

            <?php if ($payoutProfileUrl) : ?>
                <a class="bsp-btn bsp-btn--secondary bsp-portal-card__cta"
                   href="<?php echo esc_url($payoutProfileUrl); ?>">
                    <?php esc_html_e('Beheer uitbetalingsgegevens', 'sbdp'); ?>
                </a>
            <?php endif; ?>

            <?php if (! empty($recentItems)) : ?>
                <h3><?php esc_html_e('Recente boekingen', 'sbdp'); ?></h3>
                <table class="bsp-portal-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Datum', 'sbdp'); ?></th>
                            <th><?php esc_html_e('Bruto', 'sbdp'); ?></th>
                            <th><?php esc_html_e('Commissie', 'sbdp'); ?></th>
                            <th><?php esc_html_e('Uitbetaling', 'sbdp'); ?></th>
                            <th><?php esc_html_e('Status', 'sbdp'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentItems as $item) : ?>
                            <tr>
                                <td><?php echo esc_html($item['booking_date'] ?? '—'); ?></td>
                                <td>€<?php echo number_format((float) $item['gross_eur'], 2, ',', '.'); ?></td>
                                <td>€<?php echo number_format((float) $item['commission_eur'], 2, ',', '.'); ?></td>
                                <td>€<?php echo number_format((float) $item['payout_eur'], 2, ',', '.'); ?></td>
                                <td><code><?php echo esc_html($item['item_status']); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p><?php esc_html_e('Nog geen boekingen verwerkt.', 'sbdp'); ?></p>
            <?php endif; ?>
        </section>

    </div><!-- .bsp-portal-grid -->

</div><!-- .bsp-partner-portal -->
