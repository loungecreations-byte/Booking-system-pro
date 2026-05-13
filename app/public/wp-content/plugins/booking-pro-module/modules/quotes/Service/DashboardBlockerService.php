<?php

declare(strict_types=1);

namespace BSP\Quotes\Service;

final class DashboardBlockerService
{
    /**
     * @param array<string, mixed> $sendReadiness
     * @param array<string, mixed> $businessValidation
     * @param array<int, array<string, mixed>> $assumptions
     * @return array{
     *     state: string,
     *     primary_blocker: array<string, mixed>|null,
     *     assumptions: array<int, array<string, mixed>>,
     *     hidden_count: int,
     *     ready: bool,
     *     quote_editable: bool
     * }
     */
    public function buildState(
        array $sendReadiness,
        array $businessValidation,
        array $assumptions,
        bool $quoteCommerciallyEditable
    ): array {
        $sendBlockers = $this->normalizeSendBlockers($sendReadiness);
        $businessBlockers = $this->normalizeBusinessViolations($businessValidation);
        $openSendAssumptions = $this->normalizeOpenSendAssumptions($assumptions);
        $resolvableAssumptions = array_values(array_filter(
            $openSendAssumptions,
            static fn (array $assumption): bool => in_array((string) ($assumption['code'] ?? ''), array('uncertain_pricing', 'uncertain_availability'), true)
        ));

        $hardBlockers = array_values(array_filter(
            array_merge($sendBlockers, $businessBlockers),
            static fn (array $blocker): bool => (string) ($blocker['code'] ?? '') !== 'send_assumption_open'
        ));
        usort($hardBlockers, fn (array $left, array $right): int => $this->priority($left) <=> $this->priority($right));
        $workflowBlockers = array_values(array_filter(
            $hardBlockers,
            static fn (array $blocker): bool => in_array((string) ($blocker['code'] ?? ''), array('review_not_approved', 'send_status_not_ready'), true)
        ));
        $blockingBeforeAssumptions = array_values(array_filter(
            $hardBlockers,
            static fn (array $blocker): bool => ! in_array((string) ($blocker['code'] ?? ''), array('review_not_approved', 'send_status_not_ready'), true)
        ));

        $ready = ! empty($sendReadiness['ready']) && $openSendAssumptions === array();
        $primaryBlocker = $blockingBeforeAssumptions[0] ?? null;
        $state = 'ready';

        if ($primaryBlocker !== null) {
            $state = 'blocked';
        } elseif ($resolvableAssumptions !== array()) {
            $state = 'assumptions';
        } elseif ($workflowBlockers !== array()) {
            $primaryBlocker = $workflowBlockers[0];
            $state = 'blocked';
        } elseif ($openSendAssumptions !== array()) {
            $primaryBlocker = $openSendAssumptions[0];
            $state = 'blocked';
        } elseif (! $ready) {
            $primaryBlocker = array(
                'code' => 'not_ready',
                'label' => __('Offerte is nog niet verzendklaar', 'sbdp'),
                'message' => __('Controleer de communicatie-tab voor de exacte status.', 'sbdp'),
                'steps' => array(
                    __('Open de communicatie-tab.', 'sbdp'),
                    __('Controleer of review en send-status klaar staan.', 'sbdp'),
                    __('Rond de ontbrekende stap af.', 'sbdp'),
                ),
                'button_label' => __('Naar communicatie', 'sbdp'),
                'button_tab' => 'communication',
                'severity' => 'send_guard',
            );
            $state = 'blocked';
        }

        if ($ready && ! $quoteCommerciallyEditable) {
            $state = 'locked';
        }

        return array(
            'state' => $state,
            'primary_blocker' => $primaryBlocker,
            'assumptions' => $resolvableAssumptions,
            'hidden_count' => max(0, count($hardBlockers) - ($primaryBlocker !== null ? 1 : 0)),
            'ready' => $ready,
            'quote_editable' => $quoteCommerciallyEditable,
        );
    }

    /**
     * @param array<string, mixed> $sendReadiness
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSendBlockers(array $sendReadiness): array
    {
        $blockers = array();
        foreach ((array) ($sendReadiness['blockers'] ?? array()) as $blocker) {
            if (! is_array($blocker)) {
                continue;
            }

            $code = (string) ($blocker['code'] ?? 'unknown');
            $blockers[] = array_merge(
                $this->definition($code),
                array(
                    'code' => $code,
                    'message' => $this->operatorMessage($code, (string) ($blocker['message'] ?? '')),
                )
            );
        }

        return $blockers;
    }

    /**
     * @param array<string, mixed> $businessValidation
     * @return array<int, array<string, mixed>>
     */
    private function normalizeBusinessViolations(array $businessValidation): array
    {
        $blockers = array();
        foreach ((array) ($businessValidation['violations'] ?? array()) as $violation) {
            if (! is_array($violation)) {
                continue;
            }
            if ((string) ($violation['severity'] ?? 'warning') !== 'error') {
                continue;
            }

            $code = (string) ($violation['code'] ?? 'business_rule');
            $definition = $this->definition($code);
            $blockers[] = array_merge(
                $definition,
                array(
                    'code' => $code,
                    'message' => $this->operatorMessage($code, (string) ($violation['message'] ?? '')),
                    'button_tab' => (string) ($violation['fix_url'] ?? ($definition['button_tab'] ?? 'dashboard')),
                )
            );
        }

        return $blockers;
    }

    /**
     * @param array<int, array<string, mixed>> $assumptions
     * @return array<int, array<string, mixed>>
     */
    private function normalizeOpenSendAssumptions(array $assumptions): array
    {
        $normalized = array();
        foreach ($assumptions as $assumption) {
            if (! is_array($assumption) || (string) ($assumption['status'] ?? 'open') !== 'open' || empty($assumption['blocks_send'])) {
                continue;
            }

            $code = (string) ($assumption['assumption_type'] ?? 'send_assumption_open');
            $normalized[] = array_merge(
                $this->definition($code),
                array(
                    'code' => $code,
                    'assumption_id' => (int) ($assumption['id'] ?? 0),
                    'message' => (string) ($assumption['message'] ?? ''),
                )
            );
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(string $code): array
    {
        return match ($code) {
            'customer_email_invalid', 'no_customer' => array(
                'label' => __('Klant e-mail ontbreekt', 'sbdp'),
                'severity' => 'critical',
                'steps' => array(
                    __('Open de klantinformatie.', 'sbdp'),
                    __('Voeg een geldig e-mailadres toe.', 'sbdp'),
                    __('Sla de intake op.', 'sbdp'),
                ),
                'button_label' => __('Naar klantinfo', 'sbdp'),
                'button_tab' => 'dashboard',
            ),
            'quote_lines_missing', 'no_program' => array(
                'label' => __('Programmaregels ontbreken', 'sbdp'),
                'severity' => 'critical',
                'steps' => array(
                    __('Open Bewerken / Build.', 'sbdp'),
                    __('Voeg programmaregels, producten, datum/tijd en prijs toe.', 'sbdp'),
                    __('Sla de offerte op.', 'sbdp'),
                ),
                'button_label' => __('Naar build', 'sbdp'),
                'button_tab' => 'build',
            ),
            'woo_product_missing', 'woo_product_unavailable', 'woo_product_not_purchasable', 'woo_product_status_invalid', 'woo_product_tax_invalid' => array(
                'label' => __('Woo product moet gecontroleerd worden', 'sbdp'),
                'severity' => 'critical',
                'steps' => array(
                    __('Open Bewerken / Build.', 'sbdp'),
                    __('Controleer het gekoppelde Woo product.', 'sbdp'),
                    __('Gebruik alleen producten die direct afgerekend mogen worden.', 'sbdp'),
                ),
                'button_label' => __('Naar build', 'sbdp'),
                'button_tab' => 'build',
            ),
            'pricing_confidence_missing' => array(
                'label' => __('Prijs moet bevestigd worden', 'sbdp'),
                'severity' => 'send_guard',
                'steps' => array(
                    __('Ga naar Programma.', 'sbdp'),
                    __('Controleer de prijs per onderdeel.', 'sbdp'),
                    __('Bevestig de prijs.', 'sbdp'),
                    __('Controleer daarna beschikbaarheid.', 'sbdp'),
                ),
                'button_label' => __('Naar Programma', 'sbdp'),
                'button_tab' => 'build',
            ),
            'availability_confidence_missing' => array(
                'label' => __('Beschikbaarheid moet bevestigd worden', 'sbdp'),
                'severity' => 'send_guard',
                'steps' => array(
                    __('Ga naar Programma.', 'sbdp'),
                    __('Controleer datum, tijd en capaciteit per onderdeel.', 'sbdp'),
                    __('Bevestig de beschikbaarheid.', 'sbdp'),
                    __('Controleer daarna de voorsteltekst.', 'sbdp'),
                ),
                'button_label' => __('Naar Programma', 'sbdp'),
                'button_tab' => 'build',
            ),
            'review_not_approved' => array(
                'label' => __('Review moet nog afgerond worden', 'sbdp'),
                'severity' => 'send_guard',
                'steps' => array(
                    __('Controleer de offerte-inhoud.', 'sbdp'),
                    __('Klik daarna op verzendklaar maken.', 'sbdp'),
                    __('De review wordt dan afgerond als alle checks slagen.', 'sbdp'),
                ),
                'button_label' => __('Naar communicatie', 'sbdp'),
                'button_tab' => 'communication',
            ),
            'send_status_not_ready' => array(
                'label' => __('Offerte staat nog niet op verzendklaar', 'sbdp'),
                'severity' => 'send_guard',
                'steps' => array(
                    __('Controleer of alle blockers opgelost zijn.', 'sbdp'),
                    __('Gebruik daarna de verzendklaar actie.', 'sbdp'),
                    __('Verstuur pas nadat de groene status verschijnt.', 'sbdp'),
                ),
                'button_label' => __('Naar communicatie', 'sbdp'),
                'button_tab' => 'communication',
            ),
            'uncertain_pricing' => array(
                'label' => __('Leverancier moet prijs bevestigen', 'sbdp'),
                'severity' => 'assumption',
                'steps' => array(
                    __('Bel of mail de leverancier.', 'sbdp'),
                    __('Bevestig dat de prijs klopt.', 'sbdp'),
                    __('Klik daarna op Prijs bevestigd.', 'sbdp'),
                ),
                'button_label' => __('Prijs bevestigd', 'sbdp'),
            ),
            'uncertain_availability' => array(
                'label' => __('Capaciteit moet nog gecheckt worden', 'sbdp'),
                'severity' => 'assumption',
                'steps' => array(
                    __('Check planning en capaciteit.', 'sbdp'),
                    __('Bevestig dat de uitvoering kan.', 'sbdp'),
                    __('Klik daarna op Beschikbaarheid OK.', 'sbdp'),
                ),
                'button_label' => __('Beschikbaarheid OK', 'sbdp'),
            ),
            'date_invalid' => array(
                'label' => __('Eventdatum ontbreekt of klopt niet', 'sbdp'),
                'severity' => 'business',
                'steps' => array(
                    __('Open de intake context.', 'sbdp'),
                    __('Vul een geldige toekomstige datum in.', 'sbdp'),
                    __('Sla de intake op.', 'sbdp'),
                ),
                'button_label' => __('Naar overzicht', 'sbdp'),
                'button_tab' => 'dashboard',
            ),
            default => array(
                'label' => __('Offerte heeft nog een blokkade', 'sbdp'),
                'severity' => 'business',
                'steps' => array(
                    __('Open de communicatie-tab.', 'sbdp'),
                    __('Lees de blocker-melding.', 'sbdp'),
                    __('Los de genoemde stap op.', 'sbdp'),
                ),
                'button_label' => __('Naar communicatie', 'sbdp'),
                'button_tab' => 'communication',
            ),
        };
    }

    private function operatorMessage(string $code, string $fallback): string
    {
        return match ($code) {
            'pricing_confidence_missing' => __('Deze offerte kan nog niet worden verstuurd omdat de prijs nog niet definitief is bevestigd.', 'sbdp'),
            'availability_confidence_missing' => __('Deze offerte kan nog niet worden verstuurd omdat beschikbaarheid nog moet worden bevestigd.', 'sbdp'),
            'send_status_not_ready' => __('Deze offerte kan nog niet worden verstuurd. Rond eerst de open punten af.', 'sbdp'),
            'review_not_approved' => __('Deze offerte kan nog niet worden verstuurd omdat de review nog niet is afgerond.', 'sbdp'),
            default => $fallback,
        };
    }

    /**
     * @param array<string, mixed> $blocker
     */
    private function priority(array $blocker): int
    {
        return match ((string) ($blocker['code'] ?? '')) {
            'customer_email_invalid', 'no_customer' => 10,
            'quote_lines_missing', 'no_program' => 20,
            'woo_product_missing', 'woo_product_unavailable', 'woo_product_not_purchasable', 'woo_product_status_invalid', 'woo_product_tax_invalid' => 30,
            'line_amount_invalid', 'line_amount_negative', 'line_currency_invalid', 'mixed_currency' => 40,
            'pricing_confidence_missing' => 50,
            'availability_confidence_missing' => 60,
            'review_not_approved' => 70,
            'send_status_not_ready' => 80,
            default => 90,
        };
    }
}
