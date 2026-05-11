<?php

declare(strict_types=1);

namespace BSP\PartnerProgram\Admin;

use BSP\PartnerProgram\Service\GovernanceService;
use function admin_url;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_url;
use function number_format_i18n;
use function ob_get_clean;
use function ob_start;

/**
 * GovernanceDashboard — renders the "Partner Programma" tab inside
 * the platform-wide sbdp_governance cockpit.
 *
 * This class registers itself via WordPress filters so AdminMenu.php
 * does not need a hard dependency on this module.
 *
 * Hooks registered by Module.php:
 *   filter bsp_governance_extra_tabs     → registerTab()
 *   action bsp_governance_render_tab_partner → render()
 *   filter bsp_governance_hero_cards     → heroCard()
 */
final class GovernanceDashboard
{
    public static function registerTab(array $tabs): array
    {
        $tabs['partner'] = __('Partner Programma', 'sbdp');
        return $tabs;
    }

    /**
     * Adds a partner readiness card to the cockpit hero bar.
     *
     * @param array<int, array<string, string>> $cards
     * @return array<int, array<string, string>>
     */
    public static function heroCard(array $cards): array
    {
        $status    = GovernanceService::getOverallStatus();
        $labelMap  = [
            'green'  => __('Gereed', 'sbdp'),
            'orange' => __('Gedeeltelijk', 'sbdp'),
            'red'    => __('Geblokkeerd', 'sbdp'),
            'blue'   => __('Verbonden', 'sbdp'),
        ];
        $metaMap   = [
            'green'  => __('Alle go-live blockers zijn groen.', 'sbdp'),
            'orange' => __('Één of meer go-live gates zijn nog niet groen.', 'sbdp'),
            'red'    => __('Kritieke go-live blockers actief.', 'sbdp'),
            'blue'   => __('Verbonden maar nog niet geverifieerd.', 'sbdp'),
        ];

        $cards[] = [
            'title'  => __('Partner Programma', 'sbdp'),
            'value'  => $labelMap[$status] ?? strtoupper($status),
            'meta'   => $metaMap[$status] ?? '',
            'status' => $status,
            'href'   => admin_url('admin.php?page=sbdp_governance&tab=partner'),
        ];

        return $cards;
    }

    public static function render(): void
    {
        $data = GovernanceService::getData();

        echo '<div class="bsp-partner-gov">';
        self::renderHeroSummary($data['hero']);
        self::renderGoLiveGates($data['golive_gates']);
        self::renderReadinessMatrix($data['readiness_matrix']);
        self::renderFlowHealth($data['flow_health']);
        self::renderMoneyHealth($data['money_health']);
        self::renderDomainConflicts($data['domain_conflicts']);
        echo '</div>';
    }

    // -------------------------------------------------------------------------
    // Hero summary row
    // -------------------------------------------------------------------------

    private static function renderHeroSummary(array $hero): void
    {
        echo '<div class="sbdp-governance-grid sbdp-governance-grid--cards" style="margin-bottom:24px">';

        self::card(__('Totaal partners', 'sbdp'), (string) number_format_i18n($hero['total_partners']), __('Geregistreerd in bsp_partner_accounts', 'sbdp'), 'info');
        self::card(__('Actieve partners', 'sbdp'), (string) number_format_i18n($hero['active_partners']), __('account_status = active', 'sbdp'), $hero['active_partners'] > 0 ? 'pass' : 'unknown');
        self::card(__('Aanvragen open', 'sbdp'), (string) number_format_i18n($hero['open_claims']), __('Claims in review wachtrij', 'sbdp'), $hero['open_claims'] > 0 ? 'warn' : 'pass', admin_url('admin.php?page=sbdp_partner_claims'));
        self::card(__('Domeinconflicten', 'sbdp'), (string) number_format_i18n($hero['open_conflicts']), __('Identiteits-, entitlement- en revenue-risico\'s', 'sbdp'), $hero['open_conflicts'] > 0 ? 'fail' : 'pass');
        self::card(__('Locatie seeds', 'sbdp'), (string) number_format_i18n($hero['seeds_total']), __('Discovery seeds (niet commercieel)', 'sbdp'), 'info', admin_url('admin.php?page=sbdp_partner_seeds'));

        echo '</div>';
    }

    // -------------------------------------------------------------------------
    // Go-live gates
    // -------------------------------------------------------------------------

    private static function renderGoLiveGates(array $gates): void
    {
        echo '<section class="sbdp-governance-panel" style="margin-bottom:24px">';
        echo '<div class="sbdp-governance-panel__header"><div>';
        echo '<h2>' . esc_html__('Go-live Gates', 'sbdp') . '</h2>';
        echo '<p>' . esc_html__('Alle verplichte gates moeten groen zijn vóór productie-release van het Partner Programma.', 'sbdp') . '</p>';
        echo '</div></div>';

        $blockers   = array_filter($gates, fn(array $g) => $g['severity'] === 'blocker');
        $required   = array_filter($gates, fn(array $g) => $g['severity'] === 'required');
        $recommended= array_filter($gates, fn(array $g) => $g['severity'] === 'recommended');

        $allBlockersGreen = empty(array_filter($blockers, fn(array $g) => $g['status'] !== 'green'));
        $summary = $allBlockersGreen
            ? '<span class="bsp-gov-pill bsp-gov-pill--pass">' . esc_html__('Safe to release', 'sbdp') . '</span>'
            : '<span class="bsp-gov-pill bsp-gov-pill--fail">' . esc_html__('GEBLOKKEERD', 'sbdp') . '</span>';

        echo '<p style="font-size:15px;font-weight:600;margin-bottom:16px">' . esc_html__('Release status: ', 'sbdp') . $summary . '</p>';

        foreach ([
            [$blockers,    __('Verplicht (blockers)', 'sbdp')],
            [$required,    __('Verplicht', 'sbdp')],
            [$recommended, __('Aanbevolen', 'sbdp')],
        ] as [$group, $groupLabel]) {
            echo '<h4 style="margin:16px 0 6px;font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:#526173">' . esc_html($groupLabel) . '</h4>';
            echo '<table class="widefat striped sbdp-governance-table"><thead><tr>';
            echo '<th>' . esc_html__('Gate', 'sbdp') . '</th>';
            echo '<th style="width:100px">' . esc_html__('Status', 'sbdp') . '</th>';
            echo '</tr></thead><tbody>';
            foreach ($group as $gate) {
                echo '<tr><td>' . esc_html($gate['label']) . '</td><td>' . self::statusPill($gate['status']) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '</section>';
    }

    // -------------------------------------------------------------------------
    // Readiness matrix
    // -------------------------------------------------------------------------

    private static function renderReadinessMatrix(array $matrix): void
    {
        echo '<section class="sbdp-governance-panel" style="margin-bottom:24px">';
        echo '<div class="sbdp-governance-panel__header"><div>';
        echo '<h2>' . esc_html__('Readiness Matrix', 'sbdp') . '</h2>';
        echo '<p>' . esc_html__('Designed / Built / Connected / Verified per platform-module. Groen = geverifieerd. Oranje = gebouwd maar niet verbonden. Blauw = verbonden maar niet geverifieerd. Rood = ontbreekt of onveilig.', 'sbdp') . '</p>';
        echo '</div></div>';

        echo '<div style="overflow-x:auto">';
        echo '<table class="widefat striped sbdp-governance-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Module', 'sbdp') . '</th>';
        echo '<th style="width:90px;text-align:center">' . esc_html__('Designed', 'sbdp') . '</th>';
        echo '<th style="width:90px;text-align:center">' . esc_html__('Built', 'sbdp') . '</th>';
        echo '<th style="width:90px;text-align:center">' . esc_html__('Connected', 'sbdp') . '</th>';
        echo '<th style="width:90px;text-align:center">' . esc_html__('Verified', 'sbdp') . '</th>';
        echo '<th>' . esc_html__('Owner', 'sbdp') . '</th>';
        echo '<th>' . esc_html__('Blocker / note', 'sbdp') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($matrix as $row) {
            echo '<tr>';
            echo '<td><strong>' . esc_html($row['label']) . '</strong>';
            if (! empty($row['note'])) {
                echo '<br><small style="color:#526173">' . esc_html($row['note']) . '</small>';
            }
            if (! empty($row['live'])) {
                echo '<br><small style="color:#1d4ed8">';
                foreach ($row['live'] as $lk => $lv) {
                    echo esc_html($lk . ': ' . $lv) . ' &nbsp;';
                }
                echo '</small>';
            }
            echo '</td>';
            echo '<td style="text-align:center">' . self::trafficLight($row['designed']) . '</td>';
            echo '<td style="text-align:center">' . self::trafficLight($row['built']) . '</td>';
            echo '<td style="text-align:center">' . self::trafficLight($row['connected']) . '</td>';
            echo '<td style="text-align:center">' . self::trafficLight($row['verified']) . '</td>';
            echo '<td style="white-space:nowrap">' . esc_html($row['owner']) . '</td>';
            echo '<td><small>' . esc_html($row['blocker']) . '</small></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
        echo '</section>';
    }

    // -------------------------------------------------------------------------
    // Flow health
    // -------------------------------------------------------------------------

    private static function renderFlowHealth(array $flows): void
    {
        echo '<section class="sbdp-governance-panel" style="margin-bottom:24px">';
        echo '<div class="sbdp-governance-panel__header"><div>';
        echo '<h2>' . esc_html__('Operationele Flow Health', 'sbdp') . '</h2>';
        echo '<p>' . esc_html__('Actieve teltoestand per kritieke flow. Rood = actief revenue- of datarisico.', 'sbdp') . '</p>';
        echo '</div></div>';

        echo '<div class="sbdp-governance-grid sbdp-governance-grid--cards" style="margin-bottom:16px">';
        foreach ($flows as $flow) {
            self::card($flow['label'], self::flowStatusLabel($flow['status']), $flow['action'] ?: __('Geen actie vereist', 'sbdp'), $flow['status'], $flow['action_url'] ?? '');
        }
        echo '</div>';

        echo '<table class="widefat striped sbdp-governance-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Flow', 'sbdp') . '</th>';
        echo '<th>' . esc_html__('Status', 'sbdp') . '</th>';
        echo '<th>' . esc_html__('Tellingen', 'sbdp') . '</th>';
        echo '<th>' . esc_html__('Vereiste actie', 'sbdp') . '</th>';
        echo '</tr></thead><tbody>';

        $fieldLabels = [
            'claim_flow'         => ['total' => 'Totaal', 'pending' => 'Wachtend', 'in_review' => 'In review', 'approved' => 'Goedgekeurd', 'rejected' => 'Afgewezen', 'expired' => 'Verlopen'],
            'billing_sync'       => ['total' => 'Contracten', 'active' => 'Actief', 'grace' => 'Grace period', 'cancelled' => 'Geannuleerd', 'no_contract' => 'Geen contract'],
            'commission_capture' => ['missing' => 'Boekingen zonder commissie', 'unbatched' => 'Ongebatchte items', 'items_pending' => 'Items pending', 'items_held' => 'Items held'],
            'settlement_payout'  => ['open_batches' => 'Open batches', 'unbatched' => 'Ongebatched', 'no_payout' => 'Geen uitbetalingsprofiel'],
        ];

        foreach ($flows as $key => $flow) {
            $counts = '';
            foreach (($fieldLabels[$key] ?? []) as $fk => $fl) {
                if (isset($flow[$fk]) && $flow[$fk] !== 0) {
                    $counts .= esc_html($fl . ': ' . number_format_i18n((int) $flow[$fk])) . ' &nbsp; ';
                }
            }

            $actionHtml = '';
            if (! empty($flow['action']) && ! empty($flow['action_url'])) {
                $actionHtml = '<a href="' . esc_url($flow['action_url']) . '">' . esc_html($flow['action']) . '</a>';
            } elseif (! empty($flow['action'])) {
                $actionHtml = esc_html($flow['action']);
            } else {
                $actionHtml = '<span style="color:#526173">—</span>';
            }

            echo '<tr>';
            echo '<td><strong>' . esc_html($flow['label']) . '</strong></td>';
            echo '<td>' . self::statusPill($flow['status']) . '</td>';
            echo '<td><small>' . ($counts ?: '—') . '</small></td>';
            echo '<td>' . $actionHtml . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</section>';
    }

    // -------------------------------------------------------------------------
    // Money health
    // -------------------------------------------------------------------------

    private static function renderMoneyHealth(array $money): void
    {
        echo '<section class="sbdp-governance-panel" style="margin-bottom:24px">';
        echo '<div class="sbdp-governance-panel__header"><div>';
        echo '<h2>' . esc_html__('Commerciële Gezondheid', 'sbdp') . '</h2>';
        echo '<p>' . esc_html__('Tier-verdeling, uitbetalingsstatus en openstaande settlement-risico\'s.', 'sbdp') . '</p>';
        echo '</div></div>';

        echo '<div class="sbdp-governance-grid sbdp-governance-grid--cards" style="margin-bottom:16px">';
        self::card(__('Basis partners', 'sbdp'), (string) number_format_i18n($money['basis_count']), __('Tier: Basis (18% commissie)', 'sbdp'), 'info');
        self::card(__('Premium partners', 'sbdp'), (string) number_format_i18n($money['premium_count']), __('Tier: Premium (14%, lead routing)', 'sbdp'), 'info');
        self::card(__('Gold partners', 'sbdp'), (string) number_format_i18n($money['gold_count']), __('Tier: Gold (10%, volledig bookable)', 'sbdp'), 'info');
        self::card(__('Grace period', 'sbdp'), (string) number_format_i18n($money['grace_period']), __('Contracten past_due', 'sbdp'), $money['grace_period'] > 0 ? 'warn' : 'pass');
        self::card(__('Zonder IBAN', 'sbdp'), (string) number_format_i18n($money['no_payout']), __('Vendors zonder uitbetalingsprofiel', 'sbdp'), $money['no_payout'] > 0 ? 'fail' : 'pass', admin_url('admin.php?page=sbdp_partner_settlements'));
        self::card(__('Ontbrekende commissie', 'sbdp'), (string) number_format_i18n($money['missing_commission']), __('Betalingen zonder commissie-record', 'sbdp'), $money['missing_commission'] > 0 ? 'fail' : 'pass', admin_url('admin.php?page=sbdp_partner_commissions'));
        echo '</div>';

        echo '<h4 style="margin:0 0 8px;font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:#526173">' . esc_html__('Commercial mode verdeling', 'sbdp') . '</h4>';
        echo '<div class="sbdp-governance-grid sbdp-governance-grid--cards">';
        foreach ([
            'listing'  => [__('Listing only', 'sbdp'), __('Zichtbaar zonder booking', 'sbdp')],
            'lead'     => [__('Lead mode', 'sbdp'), __('Lead routing actief', 'sbdp')],
            'bookable' => [__('Bookable', 'sbdp'), __('Volledig online boekbaar', 'sbdp')],
        ] as $mode => [$label, $meta]) {
            $cnt = (int) ($money['by_mode'][$mode] ?? 0);
            self::card($label, (string) number_format_i18n($cnt), $meta, $cnt > 0 ? 'pass' : 'unknown');
        }
        echo '</div>';

        if ($money['items_held'] > 0 || $money['items_disputed'] > 0 || $money['open_batches'] > 0) {
            echo '<div style="margin-top:16px" class="notice notice-warning inline"><p>';
            $parts = [];
            if ($money['items_held'] > 0) {
                $parts[] = sprintf(esc_html__('%d uitbetaling-items on hold', 'sbdp'), $money['items_held']);
            }
            if ($money['items_disputed'] > 0) {
                $parts[] = sprintf(esc_html__('%d betwiste items', 'sbdp'), $money['items_disputed']);
            }
            if ($money['open_batches'] > 0) {
                $parts[] = sprintf(esc_html__('%d open settlement-batches', 'sbdp'), $money['open_batches']);
            }
            echo esc_html(implode(' — ', $parts));
            echo ' <a href="' . esc_url(admin_url('admin.php?page=sbdp_partner_settlements')) . '">' . esc_html__('Bekijk uitbetalingen →', 'sbdp') . '</a>';
            echo '</p></div>';
        }

        echo '</section>';
    }

    // -------------------------------------------------------------------------
    // Domain conflicts
    // -------------------------------------------------------------------------

    private static function renderDomainConflicts(array $conflicts): void
    {
        if (empty($conflicts)) {
            return;
        }

        $criticals = array_filter($conflicts, fn(array $c) => $c['severity'] === 'critical');

        echo '<section class="sbdp-governance-panel" style="margin-bottom:24px">';
        echo '<div class="sbdp-governance-panel__header"><div>';
        echo '<h2>' . esc_html__('Domeinconflicten & Data-risico\'s', 'sbdp') . '</h2>';
        echo '<p>' . esc_html__('Conflicten die platformwaarheid, revenue of uitbetaling in gevaar brengen. Criticals moeten opgelost zijn vóór go-live.', 'sbdp') . '</p>';
        echo '</div></div>';

        if (! empty($criticals)) {
            echo '<div class="notice notice-error inline" style="margin-bottom:16px"><p>';
            echo '<strong>' . esc_html__('Kritieke conflicten actief — go-live niet veilig!', 'sbdp') . '</strong>';
            echo '</p></div>';
        }

        echo '<table class="widefat striped sbdp-governance-table">';
        echo '<thead><tr>';
        echo '<th style="width:80px">' . esc_html__('Ernst', 'sbdp') . '</th>';
        echo '<th style="width:120px">' . esc_html__('Domein', 'sbdp') . '</th>';
        echo '<th>' . esc_html__('Conflict', 'sbdp') . '</th>';
        echo '<th style="width:80px">' . esc_html__('Aantal', 'sbdp') . '</th>';
        echo '<th style="width:120px">' . esc_html__('Actie', 'sbdp') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($conflicts as $c) {
            $severityPill = match ($c['severity']) {
                'critical'=> '<span class="bsp-gov-pill bsp-gov-pill--fail">Kritiek</span>',
                'high'    => '<span class="bsp-gov-pill bsp-gov-pill--warn">Hoog</span>',
                'medium'  => '<span class="bsp-gov-pill bsp-gov-pill--info">Medium</span>',
                default   => '<span class="bsp-gov-pill bsp-gov-pill--pass">Laag</span>',
            };
            $actionHtml = ! empty($c['action_url'])
                ? '<a href="' . esc_url($c['action_url']) . '">' . esc_html__('Bekijk →', 'sbdp') . '</a>'
                : '—';

            echo '<tr>';
            echo '<td>' . $severityPill . '</td>';
            echo '<td>' . esc_html($c['area']) . '</td>';
            echo '<td>' . esc_html($c['label']) . '</td>';
            echo '<td>' . (is_int($c['count']) ? esc_html((string) number_format_i18n($c['count'])) : '—') . '</td>';
            echo '<td>' . $actionHtml . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</section>';
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function card(string $title, string $value, string $meta, string $status, string $href = ''): void
    {
        $cls       = 'sbdp-governance-card sbdp-governance-card--' . esc_attr($status);
        $indicator = match ($status) {
            'pass'    => '●',
            'fail'    => '●',
            'warn'    => '●',
            'info'    => '●',
            'unknown' => '○',
            default   => '○',
        };

        $innerHtml  = '<div class="sbdp-governance-card__indicator" aria-hidden="true">' . $indicator . '</div>';
        $innerHtml .= '<div class="sbdp-governance-card__body">';
        $innerHtml .= '<p class="sbdp-governance-card__title">' . esc_html($title) . '</p>';
        $innerHtml .= '<p class="sbdp-governance-card__value">' . esc_html($value) . '</p>';
        $innerHtml .= '<p class="sbdp-governance-card__meta">' . esc_html($meta) . '</p>';
        $innerHtml .= '</div>';

        if ($href !== '') {
            echo '<a href="' . esc_url($href) . '" class="' . esc_attr($cls) . '" style="text-decoration:none;color:inherit">' . $innerHtml . '</a>';
        } else {
            echo '<div class="' . esc_attr($cls) . '">' . $innerHtml . '</div>';
        }
    }

    private static function trafficLight(string $status): string
    {
        return match ($status) {
            'green'  => '<span style="color:#0f7b45;font-size:18px" title="Verified">●</span>',
            'orange' => '<span style="color:#946200;font-size:18px" title="Built / Partial">●</span>',
            'blue'   => '<span style="color:#1d4ed8;font-size:18px" title="Connected">●</span>',
            'red'    => '<span style="color:#b42318;font-size:18px" title="Missing / Unsafe">●</span>',
            default  => '<span style="color:#ccc;font-size:18px" title="Unknown">○</span>',
        };
    }

    private static function statusPill(string $status): string
    {
        return match ($status) {
            'pass', 'green' => '<span class="bsp-gov-pill bsp-gov-pill--pass">' . esc_html__('Groen', 'sbdp') . '</span>',
            'warn', 'orange'=> '<span class="bsp-gov-pill bsp-gov-pill--warn">' . esc_html__('Oranje', 'sbdp') . '</span>',
            'fail', 'red'   => '<span class="bsp-gov-pill bsp-gov-pill--fail">' . esc_html__('Rood', 'sbdp') . '</span>',
            'info', 'blue'  => '<span class="bsp-gov-pill bsp-gov-pill--info">' . esc_html__('Blauw', 'sbdp') . '</span>',
            default          => '<span class="bsp-gov-pill">' . esc_html(strtoupper($status)) . '</span>',
        };
    }

    private static function flowStatusLabel(string $status): string
    {
        return match ($status) {
            'pass'  => __('OK', 'sbdp'),
            'warn'  => __('Waarschuwing', 'sbdp'),
            'red'   => __('Risico', 'sbdp'),
            default => strtoupper($status),
        };
    }
}
