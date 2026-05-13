<?php

declare(strict_types=1);

namespace BSP\Quotes\Admin;

use BSP\Quotes\Repository\QuoteRepositoryInterface;
use BSP\Quotes\Repository\QuoteRepository;
use BSP\Quotes\Service\QuoteAssumptionService;
use BSP\Quotes\Service\QuoteCommunicationService;
use BSP\Quotes\Service\QuoteConversionService;
use BSP\Quotes\Service\QuoteExecutionLookupService;
use BSP\Quotes\Service\QuoteExecutionLaunchService;
use BSP\Quotes\Service\QuoteExecutionRunnerService;
use BSP\Quotes\Service\DashboardBlockerService;
use BSP\Quotes\Service\QuoteBusinessRuleValidator;
use BSP\Quotes\Service\QuoteEventLogger;
use BSP\Quotes\Service\QuoteExecutionAdapterService;
use BSP\Quotes\Service\QuoteImmutabilityGuard;
use BSP\Quotes\Service\QuoteWooCartHydrationService;
use BSP\Quotes\Service\QuoteFollowupService;
use BSP\Quotes\Service\QuoteHandoffAdapterService;
use BSP\Quotes\Service\QuoteHandoffPreparationService;
use BSP\Quotes\Service\QuoteOperationsDraftService;
use BSP\Quotes\Service\QuoteRequestService;
use BSP\Quotes\Service\QuoteReviewService;
use BSP\Quotes\Service\QuoteSendService;
use BSP\Quotes\Service\QuoteSendReadinessValidator;
use BSP\Quotes\Service\WooCartLaunchGateway;

use function add_query_arg;
use function add_menu_page;
use function add_submenu_page;
use function admin_url;
use function check_admin_referer;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_url;

final class QuoteBuilderRenderer
{
    private static function resolveQuoteCommercialAdjustments(?array $currentVersion): array
    {
        $pricingSnapshot = is_array($currentVersion['pricing_snapshot_json'] ?? null)
            ? $currentVersion['pricing_snapshot_json']
            : array();
        $adjustments = is_array($pricingSnapshot['commercial_adjustments'] ?? null)
            ? $pricingSnapshot['commercial_adjustments']
            : array();
        $discountAmount = isset($adjustments['discount_amount']) && is_numeric($adjustments['discount_amount'])
            ? max(0.0, round((float) $adjustments['discount_amount'], 2))
            : 0.0;
        $discountLabel = trim((string) ($adjustments['discount_label'] ?? __('Korting', 'sbdp')));

        return array(
            'type' => 'fixed_amount',
            'discount_amount' => $discountAmount,
            'discount_label' => $discountLabel !== '' ? $discountLabel : __('Korting', 'sbdp'),
            'currency' => trim((string) (($adjustments['currency'] ?? '') ?: 'EUR')),
        );
    }

    /**
     * @param array<string, mixed> $quote
     * @param array<string, mixed>|null $currentVersion
     * @param array<int, array<string, mixed>> $lines
     */
    public static function renderQuoteBuildWorkspace(int $quoteId, array $quote, ?array $request, ?array $currentVersion, array $lines): void
    {
        $catalog = self::loadOperationsCatalogProducts();
        $defaultDate = trim((string) ($request['preferred_date'] ?? ''));
        $defaultParticipants = max(0, (int) ($request['group_size'] ?? 0));
        $builderRows = array();
        foreach ($lines as $index => $line) {
            if (! is_array($line)) {
                continue;
            }

            $builderRows[] = array(
                'source_line_number' => (int) ($line['line_number'] ?? ($index + 1)),
                'sort_order' => (int) ($line['sort_order'] ?? ($line['line_number'] ?? ($index + 1))),
                'title' => (string) ($line['title'] ?? ''),
                'product_id' => (int) ($line['product_id'] ?? 0),
                'quantity' => max(1, (int) ($line['quantity'] ?? 1)),
                'participants' => max(0, (int) ($line['participants'] ?? 0)),
                'service_date' => (string) ($line['service_date'] ?? ''),
                'proposed_start_time' => (string) ($line['proposed_start_time'] ?? ($line['start_time'] ?? '')),
                'proposed_end_time' => (string) ($line['proposed_end_time'] ?? ($line['end_time'] ?? '')),
                'duration_minutes' => (int) ($line['duration_minutes'] ?? 0),
                'selected_option_labels' => implode(', ', isset($line['selected_option_labels_json']) && is_array($line['selected_option_labels_json']) ? $line['selected_option_labels_json'] : array()),
                'validated_slot_label' => (string) ($line['validated_slot_label'] ?? ''),
                'vendor_id' => (string) ($line['vendor_id'] ?? ''),
                'resource_id' => (string) ($line['resource_id'] ?? ''),
                'pricing_mode' => (string) ($line['pricing_mode'] ?? 'directional'),
                'pricing_confidence' => (string) ($line['pricing_confidence'] ?? 'unknown'),
                'availability_confidence' => (string) ($line['availability_confidence'] ?? 'unknown'),
                'unit_amount_snapshot' => (string) ($line['unit_amount_snapshot'] ?? ''),
                'line_total_snapshot' => (string) ($line['line_total_snapshot'] ?? ''),
                'currency' => (string) (($line['currency'] ?? '') ?: 'EUR'),
                'tax_class' => (string) ($line['tax_class'] ?? ''),
                'pricing_snapshot_json' => isset($line['pricing_snapshot_json']) && is_array($line['pricing_snapshot_json']) ? $line['pricing_snapshot_json'] : array(),
                'availability_snapshot_json' => isset($line['availability_snapshot_json']) && is_array($line['availability_snapshot_json']) ? $line['availability_snapshot_json'] : array(),
                'mapping_notes' => (string) ($line['mapping_notes'] ?? ''),
                'external_label' => (string) ($line['external_label'] ?? ''),
                'is_optional' => ! empty($line['is_optional']) ? 1 : 0,
                'position_group' => (string) ($line['position_group'] ?? ''),
            );
        }

        if ($builderRows === array()) {
            $builderRows[] = array(
                'source_line_number' => 0,
                'sort_order' => 1,
                'title' => '',
                'product_id' => 0,
                'quantity' => 1,
                'participants' => $defaultParticipants,
                'service_date' => $defaultDate,
                'proposed_start_time' => '',
                'proposed_end_time' => '',
                'duration_minutes' => 0,
                'selected_option_labels' => '',
                'validated_slot_label' => '',
                'vendor_id' => '',
                'resource_id' => '',
                'pricing_mode' => 'directional',
                'pricing_confidence' => 'unknown',
                'availability_confidence' => 'unknown',
                'unit_amount_snapshot' => '',
                'line_total_snapshot' => '',
                'currency' => 'EUR',
                'tax_class' => '',
                'pricing_snapshot_json' => array(),
                'availability_snapshot_json' => array(),
                'mapping_notes' => '',
                'external_label' => '',
                'is_optional' => 0,
                'position_group' => '',
            );
        }

        $versionLabel = $currentVersion !== null ? sprintf('#%d', (int) ($currentVersion['version_number'] ?? 1)) : __('Nog geen versie', 'sbdp');
        $frozenHint = ((string) ($quote['review_status'] ?? 'not_started') === 'approved' || (string) ($quote['review_status'] ?? 'not_started') === 'pending_review' || (string) ($quote['send_status'] ?? 'not_ready') !== 'not_ready')
            ? __('Deze actieve versie is commercieel bevroren. Opslaan maakt een nieuwe draftversie aan.', 'sbdp')
            : __('Je werkt direct op de actieve draftversie totdat review of verzending de versie bevriest.', 'sbdp');
        $availabilitySlotsUrl = function_exists('rest_url') ? (string) rest_url('sbdp/v1/availability/slots') : '';
        $restNonce = function_exists('wp_create_nonce') ? (string) wp_create_nonce('wp_rest') : '';
        $commercialAdjustments = self::resolveQuoteCommercialAdjustments($currentVersion);
        $discountAmount = (float) ($commercialAdjustments['discount_amount'] ?? 0.0);
        $discountLabel = (string) ($commercialAdjustments['discount_label'] ?? __('Korting', 'sbdp'));
        $discountCurrency = (string) ($commercialAdjustments['currency'] ?? 'EUR');
        $pricingStatus = (string) ($currentVersion['pricing_confidence'] ?? 'unknown');
        $availabilityStatus = (string) ($currentVersion['availability_confidence'] ?? 'unknown');
        $readyToSend = (string) ($quote['send_status'] ?? 'not_ready') === 'ready_to_send';
        $commerciallyConfirmed = $pricingStatus === 'execution_verified' && $availabilityStatus === 'confirmed';
        $totalLabel = $readyToSend && $commerciallyConfirmed
            ? __('Offerteprijs', 'sbdp')
            : __('Voorstelbedrag onder voorbehoud', 'sbdp');
        $openBlockers = 0;
        foreach ($builderRows as $builderRow) {
            if ((string) ($builderRow['pricing_confidence'] ?? 'unknown') !== 'execution_verified') {
                ++$openBlockers;
            }
            if ((string) ($builderRow['availability_confidence'] ?? 'unknown') !== 'confirmed') {
                ++$openBlockers;
            }
        }
        $primaryActionUrl = add_query_arg(array(
            'page' => 'sbdp_quotes',
            'quote_id' => $quoteId,
            'workspace_tab' => $readyToSend ? 'communication' : 'dashboard',
        ), admin_url('admin.php'));

        echo '<section class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-header"><div><h3>' . esc_html__('Dagprogramma bouwen', 'sbdp') . '</h3><p class="bsp-quote-admin__muted">' . esc_html__('Kies activiteit, datum, aantal personen en daarna een beschikbaar tijdslot. Prijs en beschikbaarheid worden vóór verzenden gecontroleerd.', 'sbdp') . '</p></div></div><div class="bsp-quote-admin__panel-body">';
        echo '<div class="bsp-quote-admin__readiness-summary bsp-quote-admin__readiness-summary--operator"><strong>' . esc_html(sprintf(__('Werkversie %s', 'sbdp'), $versionLabel)) . '</strong><p>' . esc_html($frozenHint) . '</p></div>';
        echo '<div class="bsp-quote-admin__builder-intake"><div><span class="bsp-quote-admin__field-label">' . esc_html__('Eventdatum', 'sbdp') . '</span><strong>' . esc_html($defaultDate !== '' ? $defaultDate : __('Nog niet ingevuld', 'sbdp')) . '</strong></div><div><span class="bsp-quote-admin__field-label">' . esc_html__('Aantal personen', 'sbdp') . '</span><strong>' . esc_html($defaultParticipants > 0 ? sprintf(__('%d personen', 'sbdp'), $defaultParticipants) : __('Nog open', 'sbdp')) . '</strong></div><div><span class="bsp-quote-admin__field-label">' . esc_html__('Normale flow', 'sbdp') . '</span><strong>' . esc_html__('Activiteit -> datum -> tijdslot -> opslaan', 'sbdp') . '</strong></div></div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__builder-form" data-builder-slots-url="' . esc_url($availabilitySlotsUrl) . '" data-builder-rest-nonce="' . esc_attr($restNonce) . '">';
        echo wp_nonce_field('sbdp_quote_save_operations_draft', '_wpnonce', true, false);
        echo '<input type="hidden" name="action" value="sbdp_quote_save_operations_draft"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '">';
        echo '<div class="bsp-quote-admin__actions bsp-quote-admin__actions--stacked"><button class="button button-primary" type="submit">' . esc_html__('Bewaar dagprogramma', 'sbdp') . '</button><button class="button button-secondary" type="submit" name="create_new_version" value="1">' . esc_html__('Maak nieuwe versie', 'sbdp') . '</button><button class="button button-secondary bsp-quote-admin__builder-add" type="button">' . esc_html__('Voeg programmaregel toe', 'sbdp') . '</button></div>';
        echo '<p class="bsp-quote-admin__muted bsp-quote-admin__proposal-copy">' . esc_html__('Sleep regels in volgorde. Beschikbaarheid en prijs blijven onder voorbehoud totdat ze zijn bevestigd.', 'sbdp') . '</p>';
        echo '<div class="bsp-quote-admin__quote-total-card" data-builder-commercial-summary>';
        echo '  <div><span class="bsp-quote-admin__field-label">' . esc_html__('Subtotaal', 'sbdp') . '</span><strong data-builder-summary-subtotal>EUR 0,00</strong></div>';
        echo '  <div class="bsp-quote-admin__quote-discount-row">';
        echo '    <span class="bsp-quote-admin__field-label">' . esc_html__('Korting', 'sbdp') . '</span>';
        echo '    <input type="number" min="0" step="0.01" name="commercial_adjustments[discount_amount]" value="' . esc_attr($discountAmount > 0.0 ? number_format($discountAmount, 2, '.', '') : '') . '" placeholder="0.00" data-builder-discount-amount>';
        echo '    <input type="text" name="commercial_adjustments[discount_label]" value="' . esc_attr($discountLabel) . '" placeholder="' . esc_attr__('Korting label', 'sbdp') . '" data-builder-discount-label>';
        echo '    <input type="hidden" name="commercial_adjustments[currency]" value="' . esc_attr($discountCurrency) . '" data-builder-discount-currency>';
        echo '  </div>';
        echo '  <div class="bsp-quote-admin__quote-discount-summary"><em data-builder-summary-discount>' . esc_html__('Niet toegepast', 'sbdp') . '</em></div>';
        echo '  <div class="bsp-quote-admin__quote-total-card-total"><span class="bsp-quote-admin__field-label">' . esc_html($totalLabel) . '</span><strong data-builder-summary-total>EUR 0,00</strong></div>';
        echo '  <div><span class="bsp-quote-admin__field-label">' . esc_html__('Prijsstatus', 'sbdp') . '</span><strong>' . esc_html(self::quoteBuilderPricingLabel($pricingStatus)) . '</strong></div>';
        echo '  <div><span class="bsp-quote-admin__field-label">' . esc_html__('Beschikbaarheidsstatus', 'sbdp') . '</span><strong>' . esc_html(self::quoteBuilderAvailabilityLabel($availabilityStatus)) . '</strong></div>';
        echo '  <div><span class="bsp-quote-admin__field-label">' . esc_html__('Verzendstatus', 'sbdp') . '</span><strong>' . esc_html($readyToSend ? __('Verzendklaar', 'sbdp') : __('Niet verzendklaar', 'sbdp')) . '</strong></div>';
        echo '  <div><span class="bsp-quote-admin__field-label">' . esc_html__('Open blokkades', 'sbdp') . '</span><strong>' . esc_html((string) $openBlockers) . '</strong></div>';
        echo '  <div class="bsp-quote-admin__quote-total-card-action"><a class="button button-primary" href="' . esc_url($primaryActionUrl) . '">' . esc_html($readyToSend ? __('Voorstel versturen', 'sbdp') : __('Controleer open punten', 'sbdp')) . '</a></div>';
        echo '</div>';
        echo '<div class="bsp-quote-admin__builder-list" data-builder-list>';
        foreach (array_values($builderRows) as $index => $builderRow) {
            echo self::renderQuoteBuildRow($index, $builderRow, $catalog);
        }
        echo '</div>';
        echo '<template id="bsp-quote-builder-row-template">' . self::renderQuoteBuildRow('__INDEX__', array(
            'source_line_number' => 0,
            'sort_order' => 0,
            'title' => '',
            'product_id' => 0,
            'quantity' => 1,
            'participants' => $defaultParticipants,
            'service_date' => $defaultDate,
            'proposed_start_time' => '',
            'proposed_end_time' => '',
            'duration_minutes' => 0,
            'selected_option_labels' => '',
            'validated_slot_label' => '',
            'vendor_id' => '',
            'resource_id' => '',
            'pricing_mode' => 'directional',
            'pricing_confidence' => 'unknown',
            'availability_confidence' => 'unknown',
            'unit_amount_snapshot' => '',
            'line_total_snapshot' => '',
            'currency' => 'EUR',
            'tax_class' => '',
            'pricing_snapshot_json' => array(),
            'availability_snapshot_json' => array(),
            'mapping_notes' => '',
            'external_label' => '',
            'is_optional' => 0,
            'position_group' => '',
        ), $catalog) . '</template>';
        echo '<script type="application/json" id="bsp-quote-builder-catalog">' . esc_html((string) \wp_json_encode($catalog)) . '</script>';
        echo '</form></div></section>';
        self::renderQuoteBuildScript();
    }

    /**
     * @param int|string $index
     * @param array<string, mixed> $line
     * @param array<int, array<string, mixed>> $catalog
     */
    public static function renderQuoteBuildRow($index, array $line, array $catalog): string
    {
        $productId = (int) ($line['product_id'] ?? 0);
        $productOptions = '<option value="">' . esc_html__('Kies product', 'sbdp') . '</option>';
        foreach ($catalog as $product) {
            $optionValue = (int) ($product['id'] ?? 0);
            $selected = $productId === $optionValue ? ' selected' : '';
            $productOptions .= '<option value="' . esc_attr((string) $optionValue) . '"' . $selected
                . ' data-title="' . esc_attr((string) ($product['title'] ?? '')) . '"'
                . ' data-duration="' . esc_attr((string) ((int) ($product['duration_minutes'] ?? 0))) . '"'
                . ' data-unit-amount="' . esc_attr((string) ($product['unit_amount_snapshot'] ?? '')) . '"'
                . ' data-currency="' . esc_attr((string) ($product['currency'] ?? 'EUR')) . '">'
                . esc_html((string) ($product['title'] ?? ''))
                . '</option>';
        }

        $indexAttr = esc_attr((string) $index);
        $sortOrder = max(1, (int) ($line['sort_order'] ?? 1));
        $priceSnapshot = self::quoteBuilderPriceLabel($line);
        $pricingConfidence = (string) ($line['pricing_confidence'] ?? 'unknown');
        $availabilityConfidence = (string) ($line['availability_confidence'] ?? 'unknown');
        $pricingLabel = self::quoteBuilderPricingLabel($pricingConfidence);
        $availabilityLabel = self::quoteBuilderAvailabilityLabel($availabilityConfidence);
        $rowTitle = trim((string) ($line['title'] ?? ''));
        if ($rowTitle === '') {
            foreach ($catalog as $product) {
                if ((int) ($product['id'] ?? 0) === $productId) {
                    $rowTitle = (string) ($product['title'] ?? '');
                    break;
                }
            }
        }
        if ($rowTitle === '') {
            $rowTitle = $productId > 0 ? __('Activiteit zonder titel', 'sbdp') : __('Maatwerkregel', 'sbdp');
        }
        $lineTypeLabel = $productId > 0 ? __('Activiteit', 'sbdp') : __('Maatwerkregel', 'sbdp');
        $dateValue = (string) ($line['service_date'] ?? '');
        $startValue = (string) ($line['proposed_start_time'] ?? '');
        $endValue = (string) ($line['proposed_end_time'] ?? '');
        $slotLabel = self::quoteBuilderSlotLabel($startValue, $endValue, (string) ($line['validated_slot_label'] ?? ''));

        $html = '<input type="hidden" name="lines[' . $indexAttr . '][tax_class]" value="' . esc_attr((string) ($line['tax_class'] ?? '')) . '">';
        $html .= '<input type="hidden" name="lines[' . $indexAttr . '][source_line_number]" value="' . esc_attr((string) ($line['source_line_number'] ?? 0)) . '">';
        $html .= '<input type="hidden" name="lines[' . $indexAttr . '][sort_order]" value="' . esc_attr((string) $sortOrder) . '" data-builder-sort-order>';
        $html .= '<input type="hidden" name="lines[' . $indexAttr . '][vendor_id]" value="' . esc_attr((string) ($line['vendor_id'] ?? '')) . '">';
        $html .= '<input type="hidden" name="lines[' . $indexAttr . '][resource_id]" value="' . esc_attr((string) ($line['resource_id'] ?? '')) . '">';
        $html .= '<input type="hidden" name="lines[' . $indexAttr . '][pricing_mode]" value="' . esc_attr((string) ($line['pricing_mode'] ?? 'directional')) . '" data-builder-pricing-mode>';
        $html .= '<input type="hidden" name="lines[' . $indexAttr . '][pricing_confidence]" value="' . esc_attr((string) ($line['pricing_confidence'] ?? 'unknown')) . '" data-builder-pricing-confidence>';
        $html .= '<input type="hidden" name="lines[' . $indexAttr . '][availability_confidence]" value="' . esc_attr((string) ($line['availability_confidence'] ?? 'unknown')) . '" data-builder-availability-confidence>';
        $html .= '<input type="hidden" name="lines[' . $indexAttr . '][currency]" value="' . esc_attr((string) (($line['currency'] ?? '') ?: 'EUR')) . '" data-builder-currency>';
        $html .= '<input type="hidden" name="lines[' . $indexAttr . '][pricing_snapshot_json]" value="' . esc_attr((string) \wp_json_encode($line['pricing_snapshot_json'] ?? array())) . '">';
        $html .= '<input type="hidden" name="lines[' . $indexAttr . '][availability_snapshot_json]" value="' . esc_attr((string) \wp_json_encode($line['availability_snapshot_json'] ?? array())) . '">';
        $html .= '<input type="hidden" name="lines[' . $indexAttr . '][mapping_notes]" value="' . esc_attr((string) ($line['mapping_notes'] ?? '')) . '">';
        $html .= '<input type="hidden" name="lines[' . $indexAttr . '][external_label]" value="' . esc_attr((string) ($line['external_label'] ?? '')) . '">';
        $html .= '<input type="hidden" name="lines[' . $indexAttr . '][position_group]" value="' . esc_attr((string) ($line['position_group'] ?? '')) . '">';
        $html .= '<input type="hidden" name="lines[' . $indexAttr . '][is_optional]" value="' . esc_attr(! empty($line['is_optional']) ? '1' : '0') . '">';

        $html .= '<article class="bsp-quote-admin__builder-row bsp-quote-admin__builder-row--compact" data-builder-row draggable="true">';
        $html .= '<div class="bsp-quote-admin__builder-compact-summary">';
        $html .= '<div class="bsp-quote-admin__builder-row-drag"><button type="button" class="button-link bsp-quote-admin__builder-handle" aria-label="' . esc_attr__('Versleep', 'sbdp') . '">≡</button></div>';
        $html .= '<div><span class="bsp-quote-admin__tiny-label">' . esc_html__('Activiteit', 'sbdp') . '</span><strong data-builder-title-label>' . esc_html($rowTitle) . '</strong></div>';
        $html .= '<div><span class="bsp-quote-admin__tiny-label">' . esc_html__('Datum', 'sbdp') . '</span><strong data-builder-date-label>' . esc_html($dateValue !== '' ? $dateValue : __('Nog open', 'sbdp')) . '</strong></div>';
        $html .= '<div><span class="bsp-quote-admin__tiny-label">' . esc_html__('Tijd', 'sbdp') . '</span><strong data-builder-time-label>' . esc_html($slotLabel !== '' ? $slotLabel : __('Nog open', 'sbdp')) . '</strong></div>';
        $html .= '<div><span class="bsp-quote-admin__tiny-label">' . esc_html__('Personen', 'sbdp') . '</span><strong data-builder-participants-label>' . esc_html((string) ((int) ($line['participants'] ?? 0))) . '</strong></div>';
        $html .= '<div><span class="bsp-quote-admin__tiny-label">' . esc_html__('Prijs p.p.', 'sbdp') . '</span><strong data-builder-unit-label>' . esc_html((string) (($line['unit_amount_snapshot'] ?? '') !== '' ? self::formatMoney((float) $line['unit_amount_snapshot'], (string) (($line['currency'] ?? '') ?: 'EUR')) : __('Onder voorbehoud', 'sbdp'))) . '</strong></div>';
        $html .= '<div><span class="bsp-quote-admin__tiny-label">' . esc_html__('Regeltotaal', 'sbdp') . '</span><strong data-builder-line-total-label>' . esc_html($priceSnapshot) . '</strong></div>';
        $html .= '<div class="bsp-quote-admin__builder-card-status">' . self::renderInlineBadge($pricingLabel, self::confidenceBadgeClass($pricingConfidence)) . self::renderInlineBadge($availabilityLabel, self::confidenceBadgeClass($availabilityConfidence)) . '</div>';
        $html .= '<div class="bsp-quote-admin__builder-row-actions">';
        $html .= '<button type="button" class="button-link bsp-quote-admin__builder-duplicate" title="' . esc_attr__('Dupliceer', 'sbdp') . '"><span class="dashicons dashicons-admin-page"></span></button>';
        $html .= '<button type="button" class="button-link bsp-quote-admin__builder-remove" title="' . esc_attr__('Verwijder', 'sbdp') . '"><span class="dashicons dashicons-trash"></span></button>';
        $html .= '</div></div>';
        $html .= '<details class="bsp-quote-admin__builder-edit-panel"><summary class="button button-secondary">' . esc_html__('Bewerk', 'sbdp') . '</summary>';
        $html .= '<div class="bsp-quote-admin__builder-edit-fields">';
        $html .= '<div class="bsp-quote-admin__builder-row-main-inputs">';
        $html .= '<select name="lines[' . $indexAttr . '][product_id]" data-builder-product-select class="bsp-quote-admin__compact-select">' . $productOptions . '</select>';
        $html .= '<input type="text" name="lines[' . $indexAttr . '][title]" value="' . esc_attr((string) ($line['title'] ?? '')) . '" placeholder="' . esc_attr__('Titel voor klant', 'sbdp') . '" data-builder-title class="bsp-quote-admin__compact-input">';
        $html .= '<input type="date" name="lines[' . $indexAttr . '][service_date]" value="' . esc_attr($dateValue) . '" data-builder-date class="bsp-quote-admin__compact-input-date">';
        $html .= '<div class="bsp-quote-admin__input-with-label"><span class="bsp-quote-admin__tiny-label">Aantal personen</span><input type="number" min="0" step="1" name="lines[' . $indexAttr . '][participants]" value="' . esc_attr((string) ((int) ($line['participants'] ?? 0))) . '" data-builder-participants class="bsp-quote-admin__compact-input-num"></div>';
        $html .= '</div>';
        $html .= '<div class="bsp-quote-admin__builder-row-interaction">';
        $html .= '<div class="bsp-quote-admin__slot-picker-compact" data-builder-slot-picker>';
        $html .= '<div class="bsp-quote-admin__slot-list-chips" data-builder-slot-list>';
        if ($slotLabel !== '') {
            $html .= '<button type="button" class="bsp-quote-admin__slot-pill is-selected" data-builder-slot-start="' . esc_attr($startValue) . '" data-builder-slot-end="' . esc_attr($endValue) . '">' . esc_html($slotLabel) . '</button>';
        } else {
            $html .= '<span class="bsp-quote-admin__slot-empty-text">' . esc_html__('Beschikbare tijden: kies activiteit/datum voor tijden', 'sbdp') . '</span>';
        }
        $html .= '</div></div>';

        $html .= '<div class="bsp-quote-admin__commercial-inputs-compact">';
        $html .= '<div class="bsp-quote-admin__input-with-label"><span class="bsp-quote-admin__tiny-label">Prijs p.p.</span><input type="number" min="0" step="0.01" name="lines[' . $indexAttr . '][unit_amount_snapshot]" value="' . esc_attr((string) ($line['unit_amount_snapshot'] ?? '')) . '" data-builder-unit-amount class="bsp-quote-admin__compact-input-price"></div>';
        $html .= '<div class="bsp-quote-admin__line-total-display"><span class="bsp-quote-admin__tiny-label">Totaal regel</span><strong data-builder-line-total-label>' . esc_html($priceSnapshot) . '</strong></div>';
        $html .= '<input type="hidden" name="lines[' . $indexAttr . '][line_total_snapshot]" value="' . esc_attr((string) ($line['line_total_snapshot'] ?? '')) . '" data-builder-line-total>';
        $html .= '</div></div>';

        $html .= '<div class="bsp-quote-admin__builder-row-footer">';
        $html .= '<div class="bsp-quote-admin__status-badges">';
        $html .= self::renderInlineBadge($lineTypeLabel, $productId > 0 ? 'is-neutral' : 'is-warn');
        $html .= self::renderInlineBadge($pricingLabel, self::confidenceBadgeClass($pricingConfidence));
        $html .= self::renderInlineBadge($availabilityLabel, self::confidenceBadgeClass($availabilityConfidence));
        $html .= '</div>';
        if ($productId <= 0) {
            $html .= '<p class="bsp-quote-admin__muted">' . esc_html__('Maatwerkregels of handmatig aangepaste tijden blijven onder voorbehoud', 'sbdp') . '</p>';
        }
        $html .= '<details class="bsp-quote-admin__builder-advanced-compact"><summary>' . esc_html__('Geavanceerd / maatwerk', 'sbdp') . '</summary>';
        $html .= '<div class="bsp-quote-admin__advanced-grid">';
        $html .= '<label><span>' . esc_html__('Extra opties', 'sbdp') . '</span><input type="text" name="lines[' . $indexAttr . '][selected_option_labels]" value="' . esc_attr((string) ($line['selected_option_labels'] ?? '')) . '"></label>';
        $html .= '<label><span>' . esc_html__('Starttijd', 'sbdp') . '</span><input type="time" name="lines[' . $indexAttr . '][proposed_start_time]" value="' . esc_attr($startValue) . '" data-builder-start-time></label>';
        $html .= '<label><span>' . esc_html__('Eindtijd', 'sbdp') . '</span><input type="time" name="lines[' . $indexAttr . '][proposed_end_time]" value="' . esc_attr($endValue) . '" data-builder-end-time></label>';
        $html .= '<label><span>' . esc_html__('Duur (min)', 'sbdp') . '</span><input type="number" name="lines[' . $indexAttr . '][duration_minutes]" value="' . esc_attr((string) ((int) ($line['duration_minutes'] ?? 0))) . '" data-builder-duration></label>';
        $html .= '</div></details>';
        $html .= '</div>';
        $html .= '</div></details>';
        $html .= '</article>';

        return $html;
    }

    /**
     * @param array<string, mixed> $line
     */
    private static function quoteBuilderPriceLabel(array $line): string
    {
        $currency = (string) (($line['currency'] ?? '') ?: 'EUR');
        if ((string) ($line['line_total_snapshot'] ?? '') !== '') {
            $amount = (float) ($line['line_total_snapshot'] ?? 0);
            if ($amount <= 0.0) {
                return __('Inbegrepen / geen meerprijs', 'sbdp');
            }

            return sprintf(__('Totaal %s', 'sbdp'), self::formatMoney($amount, $currency));
        }

        if ((string) ($line['unit_amount_snapshot'] ?? '') !== '') {
            $amount = (float) ($line['unit_amount_snapshot'] ?? 0);
            if ($amount <= 0.0) {
                return __('Inbegrepen / geen meerprijs', 'sbdp');
            }

            return sprintf(__('Prijs per persoon %s', 'sbdp'), self::formatMoney($amount, $currency));
        }

        return __('Prijs nog niet bevestigd', 'sbdp');
    }

    private static function quoteBuilderPricingLabel(string $confidence): string
    {
        return match ($confidence) {
            'execution_verified' => __('Prijs bevestigd', 'sbdp'),
            'snapshot' => __('Prijs vastgelegd', 'sbdp'),
            'projected', 'directional' => __('Prijs nog controleren', 'sbdp'),
            default => __('Prijs nog niet bevestigd', 'sbdp'),
        };
    }

    private static function quoteBuilderAvailabilityLabel(string $confidence): string
    {
        return match ($confidence) {
            'confirmed' => __('Beschikbaarheid bevestigd', 'sbdp'),
            'snapshot', 'projected' => __('Beschikbaarheid nog controleren', 'sbdp'),
            default => __('Beschikbaarheid nog niet bevestigd', 'sbdp'),
        };
    }

    private static function quoteBuilderSlotLabel(string $start, string $end, string $validatedSlotLabel): string
    {
        $validatedSlotLabel = trim($validatedSlotLabel);
        if ($validatedSlotLabel !== '') {
            return $validatedSlotLabel;
        }

        $start = trim($start);
        $end = trim($end);
        if ($start !== '' && $end !== '') {
            return $start . ' - ' . $end;
        }

        if ($start !== '') {
            return $start;
        }

        return '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function loadOperationsCatalogProducts(): array
    {
        $catalog = array();

        if (class_exists('\BSP\DayPlanner\Service\ProductCatalogService')) {
            $service = new \BSP\DayPlanner\Service\ProductCatalogService();
            $items = $service->listProducts(array('include_arrangements' => true));
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $productId = (int) ($item['product_id'] ?? ($item['id'] ?? 0));
                    if ($productId <= 0) {
                        continue;
                    }

                    $title = trim((string) ($item['name'] ?? ($item['title'] ?? '')));
                    if ($title === '') {
                        continue;
                    }

                    $catalog[] = array(
                        'id' => $productId,
                        'title' => $title,
                        'duration_minutes' => max(0, (int) ($item['duration_minutes'] ?? ($item['duration']['minutes'] ?? 0))),
                        'unit_amount_snapshot' => isset($item['price_pp']) && is_numeric($item['price_pp']) ? (string) ((float) $item['price_pp']) : '',
                        'currency' => (string) (($item['pricing']['currency'] ?? $item['currency'] ?? 'EUR') ?: 'EUR'),
                    );
                }
            }
        }

        if ($catalog === array() && function_exists('wc_get_products')) {
            $products = wc_get_products(array(
                'status' => 'publish',
                'limit' => 50,
                'orderby' => 'title',
                'order' => 'ASC',
            ));
            if (is_array($products)) {
                foreach ($products as $product) {
                    if (! is_object($product) || ! method_exists($product, 'get_id')) {
                        continue;
                    }

                    $catalog[] = array(
                        'id' => (int) $product->get_id(),
                        'title' => (string) $product->get_name(),
                        'duration_minutes' => 0,
                        'unit_amount_snapshot' => method_exists($product, 'get_price') ? (string) $product->get_price() : '',
                        'currency' => 'EUR',
                    );
                }
            }
        }

        usort($catalog, static function (array $left, array $right): int {
            return strcmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
        });

        return $catalog;
    }

    private static function renderQuoteBuildScript(): void
    {
        static $rendered = false;
        if ($rendered) {
            return;
        }

        $rendered = true;

        echo '<script>
        (function(){
            const list = document.querySelector("[data-builder-list]");
            const template = document.getElementById("bsp-quote-builder-row-template");
            if (!list || !template) { return; }
            const form = list.closest("form");
            const slotsUrl = form ? (form.dataset.builderSlotsUrl || "") : "";
            const restNonce = form ? (form.dataset.builderRestNonce || "") : "";
            let dragSource = null;
            const pricingLabels = {
                execution_verified: "Prijs bevestigd",
                snapshot: "Prijs vastgelegd",
                projected: "Prijs nog controleren",
                directional: "Prijs nog controleren",
                unknown: "Prijs nog niet bevestigd"
            };
            const availabilityLabels = {
                confirmed: "Beschikbaarheid bevestigd",
                snapshot: "Beschikbaarheid nog controleren",
                projected: "Beschikbaarheid nog controleren",
                unknown: "Beschikbaarheid nog niet bevestigd"
            };

            const formatPriceLabel = (currency, amount) => {
                if (amount === "" || amount === null || typeof amount === "undefined") {
                    return "Prijs nog niet bevestigd";
                }
                const parsed = Number(amount);
                if (!Number.isFinite(parsed)) {
                    return "Prijs nog niet bevestigd";
                }
                if (parsed <= 0) {
                    return "Inbegrepen / geen meerprijs";
                }
                return "Prijs per persoon " + (currency || "EUR") + " " + parsed.toLocaleString("nl-NL", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            };

            const parseAmount = (value) => {
                const parsed = Number(String(value || "").replace(",", "."));
                return Number.isFinite(parsed) ? parsed : null;
            };

            const formatMoney = (currency, amount) => {
                return (currency || "EUR") + " " + amount.toLocaleString("nl-NL", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            };

            const refreshCommercialSummary = () => {
                const rows = Array.from(list.querySelectorAll("[data-builder-row]"));
                const currency = rows.find((row) => row.querySelector("[data-builder-currency]")?.value)?.querySelector("[data-builder-currency]")?.value || "EUR";
                let subtotal = 0;
                let priced = 0;
                rows.forEach((row) => {
                    const totalInput = row.querySelector("[data-builder-line-total]");
                    const amount = totalInput ? parseAmount(totalInput.value) : null;
                    if (amount !== null) {
                        subtotal += amount;
                        priced += 1;
                    }
                });
                const discountInput = form ? form.querySelector("[data-builder-discount-amount]") : null;
                const discountLabelInput = form ? form.querySelector("[data-builder-discount-label]") : null;
                const discount = Math.max(0, parseAmount(discountInput ? discountInput.value : "") || 0);
                const discountText = discountLabelInput && discountLabelInput.value.trim() ? discountLabelInput.value.trim() : "Korting";
                const total = Math.max(0, subtotal - discount);
                const subtotalLabel = priced > 0 ? formatMoney(currency, subtotal) : "Prijs op aanvraag";
                const finalTotalLabel = formatMoney(currency, total);
                const totalLabel = priced === rows.length && rows.length > 0 ? finalTotalLabel : (priced > 0 ? finalTotalLabel : "Prijs op aanvraag");
                const subtotalNode = document.querySelector("[data-builder-summary-subtotal]");
                const totalNode = document.querySelector("[data-builder-summary-total]");
                const discountNode = document.querySelector("[data-builder-summary-discount]");
                if (subtotalNode) { subtotalNode.textContent = subtotalLabel; }
                if (totalNode) { totalNode.textContent = totalLabel; }
                if (discountNode) { discountNode.textContent = discount > 0 ? (discountText + " -" + formatMoney(currency, discount)) : "Niet toegepast"; }
            };

            const updateLinePricePresentation = (row) => {
                const currency = row.querySelector("[data-builder-currency]")?.value || "EUR";
                const totalInput = row.querySelector("[data-builder-line-total]");
                const unitInput = row.querySelector("[data-builder-unit-amount]");
                const total = totalInput ? parseAmount(totalInput.value) : null;
                const unit = unitInput ? parseAmount(unitInput.value) : null;
                const label = total !== null ? ("Totaal " + formatMoney(currency, total)) : (unit !== null ? formatPriceLabel(currency, unit) : "Prijs nog niet bevestigd");
                const priceSummary = row.querySelector("[data-builder-price-summary]");
                const lineTotalLabel = row.querySelector("[data-builder-line-total-label]");
                if (priceSummary) { priceSummary.textContent = label; }
                if (lineTotalLabel) { lineTotalLabel.textContent = label; }
                refreshCommercialSummary();
            };

            const calculateLineTotalFromUnit = (row, force) => {
                const unitInput = row.querySelector("[data-builder-unit-amount]");
                const totalInput = row.querySelector("[data-builder-line-total]");
                const participantsInput = row.querySelector("[data-builder-participants]");
                if (!unitInput || !totalInput) { return; }
                if (!force && totalInput.value !== "") { return; }
                const unit = parseAmount(unitInput.value);
                const participants = participantsInput ? Number(participantsInput.value || 0) : 0;
                if (unit === null || !Number.isFinite(participants) || participants <= 0) { return; }
                totalInput.value = (unit * participants).toFixed(2);
                updateLinePricePresentation(row);
            };

            const timeToMinutes = (value) => {
                const match = String(value || "").match(/^(\\d{2}):(\\d{2})$/);
                if (!match) { return null; }
                return (Number(match[1]) * 60) + Number(match[2]);
            };

            const minutesToTime = (minutes) => {
                const bounded = Math.max(0, Math.min(24 * 60, minutes));
                const hours = Math.floor(bounded / 60);
                const mins = bounded % 60;
                return String(hours).padStart(2, "0") + ":" + String(mins).padStart(2, "0");
            };

            const getSelectedDuration = (row) => {
                const productSelect = row.querySelector("[data-builder-product-select]");
                const option = productSelect && productSelect.selectedOptions.length ? productSelect.selectedOptions[0] : null;
                const optionDuration = option ? Number(option.dataset.duration || 0) : 0;
                if (Number.isFinite(optionDuration) && optionDuration > 0) {
                    return Math.round(optionDuration);
                }
                const durationInput = row.querySelector("[data-builder-duration]");
                const inputDuration = durationInput ? Number(durationInput.value || 0) : 0;
                return Number.isFinite(inputDuration) && inputDuration > 0 ? Math.round(inputDuration) : 0;
            };

            const mergeIntervals = (intervals) => {
                const merged = [];
                for (const interval of intervals) {
                    const last = merged[merged.length - 1];
                    if (last && interval.start <= last.end) {
                        last.end = Math.max(last.end, interval.end);
                    } else {
                        merged.push({ start: interval.start, end: interval.end });
                    }
                }
                return merged;
            };

            const inferSlotStep = (intervals) => {
                const starts = intervals.map((slot) => slot.start).sort((left, right) => left - right);
                const steps = [];
                for (let index = 1; index < starts.length; index += 1) {
                    const delta = starts[index] - starts[index - 1];
                    if (delta > 0) { steps.push(delta); }
                }
                intervals.forEach((slot) => {
                    const duration = slot.end - slot.start;
                    if (duration > 0) { steps.push(duration); }
                });
                const usable = steps.filter((step) => step > 0 && step <= 120);
                return usable.length ? Math.max(5, Math.min(...usable)) : 30;
            };

            const normalizeSlotsForDuration = (row, slots) => {
                if (!Array.isArray(slots) || slots.length === 0) { return []; }
                const duration = getSelectedDuration(row);
                if (duration <= 0) { return slots; }
                const intervals = slots
                    .map((slot) => ({
                        start: timeToMinutes(slot.start || ""),
                        end: timeToMinutes(slot.end || "")
                    }))
                    .filter((slot) => slot.start !== null && slot.end !== null && slot.end > slot.start)
                    .sort((left, right) => left.start - right.start);
                const seen = new Set();
                const candidates = [];
                const step = inferSlotStep(intervals);
                mergeIntervals(intervals).forEach((interval) => {
                    for (let cursor = interval.start; cursor + duration <= interval.end; cursor += step) {
                        if (seen.has(cursor)) { continue; }
                        seen.add(cursor);
                        candidates.push({
                            start: minutesToTime(cursor),
                            end: minutesToTime(cursor + duration),
                            duration_minutes: duration
                        });
                    }
                });
                return candidates;
            };

            const slotText = (start, end) => {
                if (start && end) { return start + " - " + end; }
                return start || "Tijdslot";
            };

            const renderSlotMessage = (row, message) => {
                const listNode = row.querySelector("[data-builder-slot-list]");
                if (!listNode) { return; }
                listNode.innerHTML = "";
                const node = document.createElement("span");
                node.className = "bsp-quote-admin__slot-empty-text";
                node.textContent = message;
                listNode.appendChild(node);
            };

            const refreshRowState = (row) => {
                const titleInput = row.querySelector("[data-builder-title]");
                const productSelect = row.querySelector("[data-builder-product-select]");
                const titleLabel = row.querySelector("[data-builder-card-title]");
                const pricingInput = row.querySelector("[data-builder-pricing-confidence]");
                const availabilityInput = row.querySelector("[data-builder-availability-confidence]");
                const pricingLabel = row.querySelector("[data-builder-pricing-label]");
                const availabilityLabel = row.querySelector("[data-builder-availability-label]");
                const selectedSlot = row.querySelector("[data-builder-selected-slot]");
                const slotInput = row.querySelector("[data-builder-slot-label]");
                const startInput = row.querySelector("[data-builder-start-time]");
                const endInput = row.querySelector("[data-builder-end-time]");
                if (titleLabel) {
                    const selectedTitle = productSelect && productSelect.selectedOptions.length ? (productSelect.selectedOptions[0].dataset.title || "") : "";
                    titleLabel.textContent = (titleInput && titleInput.value.trim()) || selectedTitle || "Maatwerkregel";
                }
                if (selectedSlot) {
                    selectedSlot.textContent = (slotInput && slotInput.value.trim()) || slotText(startInput ? startInput.value : "", endInput ? endInput.value : "") || "Kies een tijdslot";
                }
                if (pricingLabel && pricingInput) {
                    pricingLabel.textContent = pricingLabels[pricingInput.value] || pricingLabels.unknown;
                }
                if (availabilityLabel && availabilityInput) {
                    availabilityLabel.textContent = availabilityLabels[availabilityInput.value] || availabilityLabels.unknown;
                }
                updateLinePricePresentation(row);
            };

            const setSelectedSlot = (row, start, end) => {
                const startInput = row.querySelector("[data-builder-start-time]");
                const endInput = row.querySelector("[data-builder-end-time]");
                const slotInput = row.querySelector("[data-builder-slot-label]");
                const durationInput = row.querySelector("[data-builder-duration]");
                const availabilityInput = row.querySelector("[data-builder-availability-confidence]");
                const selectedSlot = row.querySelector("[data-builder-selected-slot]");
                const label = slotText(start, end);
                if (startInput) { startInput.value = start || ""; }
                if (endInput) { endInput.value = end || ""; }
                if (slotInput) { slotInput.value = label; }
                if (selectedSlot) { selectedSlot.textContent = label; }
                if (durationInput && start && end) {
                    const startMinutes = timeToMinutes(start);
                    const endMinutes = timeToMinutes(end);
                    if (startMinutes !== null && endMinutes !== null && endMinutes > startMinutes) {
                        durationInput.value = String(endMinutes - startMinutes);
                    }
                }
                if (availabilityInput && availabilityInput.value === "unknown") {
                    availabilityInput.value = "projected";
                }
                refreshRowState(row);
            };

            const renderSlots = (row, slots) => {
                const listNode = row.querySelector("[data-builder-slot-list]");
                const showUnavailable = row.querySelector("[data-builder-show-unavailable]");
                const currentStart = row.querySelector("[data-builder-start-time]")?.value || "";
                const currentEnd = row.querySelector("[data-builder-end-time]")?.value || "";
                const durationMatchedSlots = normalizeSlotsForDuration(row, slots);
                if (!listNode) { return; }
                listNode.innerHTML = "";
                if (durationMatchedSlots.length === 0) {
                    const duration = getSelectedDuration(row);
                    renderSlotMessage(row, duration > 0 ? "Geen vrij tijdslot gevonden." : "Geen vrij tijdslot gevonden.");
                    return;
                }
                let correctedCurrentSlot = null;
                durationMatchedSlots.forEach((slot) => {
                    const start = String(slot.start || "");
                    const end = String(slot.end || "");
                    if (!start) { return; }
                    if (start === currentStart && end !== currentEnd) {
                        correctedCurrentSlot = { start, end };
                    }
                    const button = document.createElement("button");
                    button.type = "button";
                    button.className = "bsp-quote-admin__slot-pill" + (start === currentStart && end === currentEnd ? " is-selected" : "");
                    button.textContent = slotText(start, end);
                    button.dataset.builderSlotStart = start;
                    button.dataset.builderSlotEnd = end;
                    button.addEventListener("click", () => {
                        Array.from(listNode.querySelectorAll(".bsp-quote-admin__slot-pill")).forEach((node) => node.classList.remove("is-selected"));
                        button.classList.add("is-selected");
                        setSelectedSlot(row, start, end);
                    });
                    listNode.appendChild(button);
                });
                if (correctedCurrentSlot) {
                    setSelectedSlot(row, correctedCurrentSlot.start, correctedCurrentSlot.end);
                    const selectedButton = listNode.querySelector("[data-builder-slot-start=\"" + correctedCurrentSlot.start + "\"]");
                    if (selectedButton) {
                        selectedButton.classList.add("is-selected");
                    }
                }
                if (showUnavailable && showUnavailable.checked) {
                    const note = document.createElement("span");
                    note.className = "bsp-quote-admin__slot-empty-text";
                    note.textContent = "Niet-passende of bezette tijden worden bewust niet aangeboden.";
                    listNode.appendChild(note);
                }
            };

            const loadSlots = async (row) => {
                const productSelect = row.querySelector("[data-builder-product-select]");
                const dateInput = row.querySelector("[data-builder-date]");
                const participantsInput = row.querySelector("[data-builder-participants]");
                const slotContainer = row.querySelector(".bsp-quote-admin__builder-row-interaction-slots");
                
                if (!productSelect || !dateInput || !slotsUrl) {
                    renderSlotMessage(row, "Slotservice niet beschikbaar.");
                    return;
                }
                const productId = productSelect.value || "";
                const date = dateInput.value || "";
                const participants = participantsInput && participantsInput.value ? participantsInput.value : "1";
                if (!productId || !date) {
                    renderSlotMessage(row, "Kies eerst activiteit, datum en aantal.");
                    return;
                }
                renderSlotMessage(row, "Tijden laden...");
                const url = new URL(slotsUrl, window.location.origin);
                url.searchParams.set("product_id", productId);
                url.searchParams.set("date", date);
                url.searchParams.set("participants", participants);
                try {
                    const response = await fetch(url.toString(), {
                        credentials: "same-origin",
                        headers: restNonce ? { "X-WP-Nonce": restNonce } : {}
                    });
                    if (!response.ok) {
                        throw new Error("availability failed");
                    }
                    const payload = await response.json();
                    renderSlots(row, payload && Array.isArray(payload.slots) ? payload.slots : []);
                } catch (error) {
                    renderSlotMessage(row, "Tijden konden niet geladen worden. Controleer availability later of gebruik maatwerk.");
                }
            };

            const refreshOrders = () => {
                Array.from(list.querySelectorAll("[data-builder-row]")).forEach((row, index) => {
                    const sortInput = row.querySelector("[data-builder-sort-order]");
                    const orderLabel = row.querySelector("[data-builder-order-label]");
                    if (sortInput) { sortInput.value = String(index + 1); }
                    if (orderLabel) { orderLabel.textContent = "Stop " + (index + 1); }
                });
            };

            const bindRow = (row) => {
                if (!row) { return; }
                row.addEventListener("dragstart", () => {
                    dragSource = row;
                    row.classList.add("is-dragging");
                });
                row.addEventListener("dragend", () => {
                    row.classList.remove("is-dragging");
                    dragSource = null;
                    refreshOrders();
                });
                row.addEventListener("dragover", (event) => {
                    event.preventDefault();
                    if (!dragSource || dragSource === row) { return; }
                    const rect = row.getBoundingClientRect();
                    const before = (event.clientY - rect.top) < (rect.height / 2);
                    if (before) {
                        list.insertBefore(dragSource, row);
                    } else {
                        list.insertBefore(dragSource, row.nextSibling);
                    }
                });

                const removeButton = row.querySelector(".bsp-quote-admin__builder-remove");
                if (removeButton) {
                    removeButton.addEventListener("click", () => {
                        const rows = list.querySelectorAll("[data-builder-row]");
                        if (rows.length <= 1) { return; }
                        row.remove();
                        refreshCommercialSummary();
                        refreshOrders();
                    });
                }

                const duplicateButton = row.querySelector(".bsp-quote-admin__builder-duplicate");
                if (duplicateButton) {
                    duplicateButton.addEventListener("click", () => {
                        const clone = createRowFromTemplate();
                        if (!clone) { return; }
                        copyRowValues(row, clone);
                        list.insertBefore(clone, row.nextSibling);
                        refreshRowState(clone);
                        refreshCommercialSummary();
                        refreshOrders();
                    });
                }

                const productSelect = row.querySelector("[data-builder-product-select]");
                if (productSelect) {
                    productSelect.addEventListener("change", () => {
                        const option = productSelect.selectedOptions && productSelect.selectedOptions.length ? productSelect.selectedOptions[0] : null;
                        const titleInput = row.querySelector("[data-builder-title]");
                        const durationInput = row.querySelector("[data-builder-duration]");
                        const unitAmountInput = row.querySelector("[data-builder-unit-amount]");
                        const currencyInput = row.querySelector("[data-builder-currency]");
                        const pricingConfidenceInput = row.querySelector("[data-builder-pricing-confidence]");
                        const availabilityConfidenceInput = row.querySelector("[data-builder-availability-confidence]");
                        const priceSummary = row.querySelector("[data-builder-price-summary]");
                        if (!option) { return; }
                        if (titleInput && !titleInput.value.trim()) {
                            titleInput.value = option.dataset.title || "";
                        }
                        if (durationInput && !durationInput.value && option.dataset.duration) {
                            durationInput.value = option.dataset.duration;
                        }
                        if (unitAmountInput) {
                            unitAmountInput.value = option.dataset.unitAmount || "";
                        }
                        if (currencyInput) {
                            currencyInput.value = option.dataset.currency || "EUR";
                        }
                        if (pricingConfidenceInput && (option.dataset.unitAmount || "") !== "") {
                            pricingConfidenceInput.value = "snapshot";
                        }
                        if (availabilityConfidenceInput && productSelect.value) {
                            availabilityConfidenceInput.value = "projected";
                        }
                        if (priceSummary) {
                            priceSummary.textContent = formatPriceLabel(option.dataset.currency || "EUR", option.dataset.unitAmount || "");
                        }
                        calculateLineTotalFromUnit(row, true);
                        refreshRowState(row);
                        loadSlots(row);
                    });
                }
                const titleInput = row.querySelector("[data-builder-title]");
                if (titleInput) {
                    titleInput.addEventListener("input", () => refreshRowState(row));
                }
                const dateInput = row.querySelector("[data-builder-date]");
                if (dateInput) {
                    dateInput.addEventListener("change", () => loadSlots(row));
                }
                const participantsInput = row.querySelector("[data-builder-participants]");
                if (participantsInput) {
                    participantsInput.addEventListener("change", () => {
                        calculateLineTotalFromUnit(row, true);
                        loadSlots(row);
                    });
                    participantsInput.addEventListener("input", () => {
                        calculateLineTotalFromUnit(row, true);
                    });
                }
                const unitAmountInput = row.querySelector("[data-builder-unit-amount]");
                if (unitAmountInput) {
                    unitAmountInput.addEventListener("change", () => calculateLineTotalFromUnit(row, true));
                    unitAmountInput.addEventListener("input", () => updateLinePricePresentation(row));
                }
                const lineTotalInput = row.querySelector("[data-builder-line-total]");
                if (lineTotalInput) {
                    lineTotalInput.addEventListener("change", () => updateLinePricePresentation(row));
                    lineTotalInput.addEventListener("input", () => updateLinePricePresentation(row));
                }
                const refreshSlotsButton = row.querySelector("[data-builder-refresh-slots]");
                if (refreshSlotsButton) {
                    refreshSlotsButton.addEventListener("click", () => loadSlots(row));
                }
                const showUnavailable = row.querySelector("[data-builder-show-unavailable]");
                if (showUnavailable) {
                    showUnavailable.addEventListener("change", () => loadSlots(row));
                }
                Array.from(row.querySelectorAll("[data-builder-slot-start]")).forEach((button) => {
                    button.addEventListener("click", () => {
                        setSelectedSlot(row, button.dataset.builderSlotStart || "", button.dataset.builderSlotEnd || "");
                    });
                });
                Array.from(row.querySelectorAll("[data-builder-start-time], [data-builder-end-time], [data-builder-slot-label]")).forEach((field) => {
                    field.addEventListener("change", () => refreshRowState(row));
                    field.addEventListener("input", () => refreshRowState(row));
                });
                refreshRowState(row);
            };

            const createRowFromTemplate = () => {
                const index = list.querySelectorAll("[data-builder-row]").length;
                const html = template.innerHTML.replaceAll("__INDEX__", String(index));
                const wrapper = document.createElement("div");
                wrapper.innerHTML = html.trim();
                const row = wrapper.firstElementChild;
                if (row) {
                    list.appendChild(row);
                    bindRow(row);
                }
                return row;
            };

            const copyRowValues = (source, target) => {
                const sourceFields = source.querySelectorAll("input, select, textarea");
                sourceFields.forEach((field) => {
                    const name = field.getAttribute("name") || "";
                    const match = name.match(/\\]\\[(.+?)\\]$/);
                    if (!match) { return; }
                    const targetField = target.querySelector(`[name$="][${match[1]}]"]`);
                    if (!targetField) { return; }
                    targetField.value = field.value;
                });
            };

            Array.from(list.querySelectorAll("[data-builder-row]")).forEach(bindRow);
            if (form) {
                Array.from(form.querySelectorAll("[data-builder-discount-amount], [data-builder-discount-label]")).forEach((field) => {
                    field.addEventListener("input", refreshCommercialSummary);
                    field.addEventListener("change", refreshCommercialSummary);
                });
            }
            refreshOrders();
            refreshCommercialSummary();

            const addButton = document.querySelector(".bsp-quote-admin__builder-add");
            if (addButton) {
                addButton.addEventListener("click", () => {
                    const row = createRowFromTemplate();
                    if (row) { refreshRowState(row); }
                    refreshCommercialSummary();
                    refreshOrders();
                });
            }
        })();
        </script>';
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    private static function formatMoney(float $amount, string $currency): string
    {
        $formatted = \function_exists('number_format_i18n')
            ? (string) \number_format_i18n($amount, 2)
            : number_format($amount, 2, ',', '.');

        return trim($currency . ' ' . $formatted);
    }

    private static function renderInlineBadge(string $label, string $className): string
    {
        return '<span class="bsp-quote-admin__badge ' . esc_attr($className) . '">' . esc_html($label) . '</span>';
    }

    private static function confidenceBadgeClass(string $status): string
    {
        return match ($status) {
            'execution_verified', 'confirmed' => 'is-good',
            'snapshot', 'projected' => 'is-warn',
            default => 'is-neutral',
        };
    }

    public static function renderAdminStyles(): void
    {
        static $rendered = false;
        if ($rendered) {
            return;
        }

        $rendered = true;

        echo '<style>
            :root {
                --bsp-admin-primary: #0073aa;
                --bsp-admin-bg: #f0f0f1;
                --bsp-admin-border: #dcdcde;
                --bsp-admin-text: #2c3338;
                --bsp-admin-muted: #646970;
                --bsp-admin-success: #dff0d8;
                --bsp-admin-success-text: #3c763d;
                --bsp-admin-warn: #fcf8e3;
                --bsp-admin-warn-text: #8a6d3b;
                --bsp-admin-error: #f2dede;
                --bsp-admin-error-text: #a94442;
            }

            .bsp-quote-admin__stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:16px 0 20px}
            .bsp-quote-admin__stat{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;display:flex;flex-direction:column;gap:4px}
            .bsp-quote-admin__stat-value{font-size:24px;font-weight:600;line-height:1.2}
            .bsp-quote-admin__stat-label,.bsp-quote-admin__muted{color:#50575e}
            .bsp-quote-admin__badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:12px;font-weight:600;line-height:1.4;background:#f0f0f1;color:#1d2327;margin:0 4px 4px 0}
            .bsp-quote-admin__badge.is-good{background:#dff5e3;color:#0a5222}
            .bsp-quote-admin__badge.is-warn{background:#fff3cd;color:#7a4b00}
            .bsp-quote-admin__badge.is-neutral{background:#eef2f7;color:#324055}
            .bsp-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 10px;
                font-size: 10px;
                font-weight: 600;
                background: #eee;
            }
            .bsp-badge.is-neutral { background: #e7e7e7; color: #555; }
            .bsp-badge.is-warn { background: var(--bsp-admin-warn); color: var(--bsp-admin-warn-text); }
            .bsp-badge.is-success { background: var(--bsp-admin-success); color: var(--bsp-admin-success-text); }
            .bsp-badge.is-error { background: var(--bsp-admin-error); color: var(--bsp-admin-error-text); }

            .bsp-quote-admin__actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
            .bsp-quote-admin__actions--stacked{align-items:flex-start}
            .bsp-quote-admin__link{text-decoration:none}
            .bsp-quote-admin__workspace{display:flex;flex-direction:column;gap:16px;margin-top:16px}
            .bsp-quote-admin__workspace-tabs{margin:0;padding-top:0}
            .bsp-quote-admin__decision-strip{display:grid;grid-template-columns:minmax(0,1fr) minmax(180px,auto);gap:12px;align-items:stretch;margin:0;padding:12px;border:1px solid #d0d7de;border-radius:8px;background:#fff}
            .bsp-quote-admin__decision-strip-main{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px}
            .bsp-quote-admin__decision-strip-main > div,.bsp-quote-admin__compact-metrics > div{padding:9px 10px;border:1px solid #e2e4e7;border-radius:6px;background:#fbfcfe;min-width:0}
            .bsp-quote-admin__decision-strip span,.bsp-quote-admin__compact-metrics span{display:block;margin-bottom:3px;color:#646970;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
            .bsp-quote-admin__decision-strip strong,.bsp-quote-admin__compact-metrics strong{display:block;color:#1d2327;font-size:13px;line-height:1.25;overflow-wrap:anywhere}
            .bsp-quote-admin__decision-strip-action{display:flex;flex-direction:column;justify-content:center;gap:8px;min-width:180px}
            .bsp-quote-admin__decision-strip-action .button{width:100%;text-align:center}
            .bsp-quote-admin__summary-cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;align-items:start}
            .bsp-quote-admin__summary-card{margin:0}
            .bsp-quote-admin__summary-card .bsp-quote-admin__panel-body{padding:12px}
            .bsp-quote-admin__compact-metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px}
            .bsp-quote-admin__alert-list{margin-top:10px;padding:10px;border:1px solid #d0d7de;border-radius:8px;background:#fbfcfe}
            .bsp-quote-admin__alert-list:first-child{margin-top:0}
            .bsp-quote-admin__alert-list ul{margin:8px 0 0;padding:0;list-style:none;display:flex;flex-direction:column;gap:8px}
            .bsp-quote-admin__alert-list li{display:flex;flex-direction:column;gap:2px;margin:0}
            .bsp-quote-admin__alert-list span{font-weight:600}
            .bsp-quote-admin__alert-list small{color:#646970;line-height:1.35}
            .bsp-quote-admin__alert-list.is-blocker{border-left:4px solid #d63638;background:#fff7f7}
            .bsp-quote-admin__alert-list.is-warning{border-left:4px solid #dba617;background:#fffaf0}
            .bsp-quote-admin__alert-list.is-info{border-left:4px solid #72aee6}
            .bsp-quote-admin__debug-json{max-height:360px;overflow:auto;padding:12px;border:1px solid #d0d7de;border-radius:6px;background:#f6f7f7;white-space:pre-wrap;overflow-wrap:anywhere}
            .bsp-quote-admin__workspace-hero--compact{padding:12px;margin:0;border-radius:8px;border:1px solid #d0d7de;background:#fff}
            .bsp-quote-admin__compact-quote-header{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;align-items:stretch}
            .bsp-quote-admin__compact-quote-header > div{padding:10px 12px;border:1px solid #e2e4e7;border-radius:6px;background:#fbfcfe}
            .bsp-quote-admin__compact-quote-header span{display:block;margin-bottom:4px;color:#646970;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
            .bsp-quote-admin__compact-quote-header strong{display:block;font-size:14px;line-height:1.25;color:#1d2327}
            .bsp-quote-admin__compact-next-action{grid-column:span 2;border-color:#2271b1;background:#f6fbff}
            .bsp-quote-admin__compact-next-action .button{margin-top:8px}
            .bsp-quote-admin__send-check{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px;margin-top:10px}
            .bsp-quote-admin__send-check-item{display:grid;grid-template-columns:22px minmax(0,1fr);gap:2px 8px;align-items:center;padding:8px 10px;border:1px solid #d0d7de;border-radius:6px;background:#fff}
            .bsp-quote-admin__send-check-item span{grid-row:1/3;display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:999px;font-size:12px;font-weight:700}
            .bsp-quote-admin__send-check-item strong{font-size:12px;line-height:1.2}
            .bsp-quote-admin__send-check-item small{color:#646970}
            .bsp-quote-admin__send-check-item.is-ok span{background:#dff5e3;color:#0a5222}
            .bsp-quote-admin__send-check-item.is-open span{background:#fff3cd;color:#7a4b00}
            .bsp-quote-admin__workspace-grid{display:grid;grid-template-columns:minmax(0,1.8fr) minmax(320px,1fr);gap:16px;align-items:start}
            .bsp-quote-admin__workspace-main,.bsp-quote-admin__workspace-side{display:flex;flex-direction:column;gap:16px}
            .bsp-quote-admin__panel{margin:0; background:#fff; border:1px solid #dcdcde; border-radius:4px;}
            .bsp-quote-admin__panel-header{padding:16px 16px 0; border-bottom:1px solid #f0f0f1; padding-bottom:12px;}
            .bsp-quote-admin__panel-header h3{margin:0 0 4px;font-size:18px}
            .bsp-quote-admin__panel-header p{margin:0}
            .bsp-quote-admin__panel-body{padding:16px}
            .bsp-quote-admin__field-label{text-transform:uppercase;letter-spacing:.04em;font-size:11px;font-weight:700;color:#646970;display:block;margin-bottom:4px;}

            /* Compact Builder Styles */
            .bsp-quote-admin__builder-list {
                margin-top: 15px;
                padding-bottom: 120px; /* Space for sticky footer */
            }

            .bsp-quote-admin__builder-row {
                background: #fff;
                border: 1px solid var(--bsp-admin-border);
                border-radius: 4px;
                margin-bottom: 8px;
                padding: 10px;
                position: relative;
                transition: box-shadow 0.2s ease;
            }

            .bsp-quote-admin__builder-row:hover {
                box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            }

            .bsp-quote-admin__builder-row-header {
                display: flex;
                align-items: center;
                gap: 12px;
                margin-bottom: 8px;
            }

            .bsp-quote-admin__builder-row-drag {
                cursor: grab;
                color: var(--bsp-admin-muted);
                font-size: 20px;
            }

            .bsp-quote-admin__builder-row-main-inputs {
                display: flex;
                flex-grow: 1;
                gap: 8px;
                align-items: center;
            }

            .bsp-quote-admin__compact-select {
                max-width: 200px;
                font-size: 13px !important;
                height: 30px !important;
            }

            .bsp-quote-admin__compact-input {
                flex-grow: 1;
                font-size: 13px !important;
                height: 30px !important;
            }

            .bsp-quote-admin__compact-input-date {
                width: 130px;
                font-size: 12px !important;
                height: 30px !important;
            }

            .bsp-quote-admin__compact-input-num {
                width: 60px;
                font-size: 13px !important;
                height: 30px !important;
            }

            .bsp-quote-admin__input-with-label {
                display: flex;
                align-items: center;
                gap: 4px;
                background: #f6f7f7;
                padding: 0 6px;
                border: 1px solid #c3c4c7;
                border-radius: 3px;
                height: 30px;
            }

            .bsp-quote-admin__tiny-label {
                font-size: 10px;
                color: var(--bsp-admin-muted);
                text-transform: uppercase;
                font-weight: 600;
            }

            .bsp-quote-admin__builder-row-actions {
                display: flex;
                gap: 4px;
            }

            .bsp-quote-admin__builder-row-actions .button-link {
                color: var(--bsp-admin-muted);
                padding: 4px;
                text-decoration: none;
            }

            .bsp-quote-admin__builder-row-actions .bsp-quote-admin__builder-remove:hover {
                color: #d63638;
            }

            .bsp-quote-admin__builder-row-interaction {
                display: flex;
                justify-content: space-between;
                align-items: center;
                background: #fbfbfc;
                margin: 0 -10px;
                padding: 8px 10px;
                border-top: 1px solid #f0f0f1;
                border-bottom: 1px solid #f0f0f1;
            }

            .bsp-quote-admin__slot-list-chips {
                display: flex;
                flex-wrap: wrap;
                gap: 6px;
            }

            .bsp-quote-admin__slot-pill {
                background: #fff;
                border: 1px solid var(--bsp-admin-border);
                border-radius: 12px;
                padding: 2px 10px;
                font-size: 11px;
                cursor: pointer;
                transition: all 0.2s;
            }

            .bsp-quote-admin__slot-pill:hover {
                border-color: var(--bsp-admin-primary);
                color: var(--bsp-admin-primary);
            }

            .bsp-quote-admin__slot-pill.is-selected {
                background: var(--bsp-admin-primary);
                color: #fff;
                border-color: var(--bsp-admin-primary);
            }

            .bsp-quote-admin__slot-empty-text {
                font-size: 11px;
                color: var(--bsp-admin-muted);
                font-style: italic;
            }

            .bsp-quote-admin__commercial-inputs-compact {
                display: flex;
                align-items: center;
                gap: 12px;
            }

            .bsp-quote-admin__compact-input-price {
                width: 80px !important;
                font-size: 13px !important;
                height: 28px !important;
                border: none !important;
                background: transparent !important;
                font-weight: 600;
            }

            .bsp-quote-admin__line-total-display {
                text-align: right;
                min-width: 120px;
            }

            .bsp-quote-admin__line-total-display strong {
                display: block;
                font-size: 13px;
                color: var(--bsp-admin-text);
            }

            .bsp-quote-admin__builder-row-footer {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-top: 8px;
            }

            .bsp-quote-admin__status-badges {
                display: flex;
                gap: 4px;
            }

            .bsp-quote-admin__builder-advanced-compact summary {
                font-size: 11px;
                color: var(--bsp-admin-primary);
                cursor: pointer;
                outline: none;
            }

            .bsp-quote-admin__advanced-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
                gap: 10px;
                background: #f6f7f7;
                padding: 10px;
                margin-top: 10px;
                border-radius: 4px;
            }

            /* Sticky Commercial Summary Card */
            .bsp-quote-admin__quote-total-card {
                position: fixed;
                bottom: 20px;
                right: 40px;
                width: 320px;
                background: #fff;
                border: 2px solid var(--bsp-admin-primary);
                border-radius: 8px;
                box-shadow: 0 4px 15px rgba(0,0,0,0.15);
                z-index: 1001;
                padding: 15px;
            }

            .bsp-quote-admin__quote-total-card > div {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 8px;
            }

            .bsp-quote-admin__quote-total-card strong {
                font-size: 16px;
                color: var(--bsp-admin-primary);
            }

            .bsp-quote-admin__panel-toggle{list-style:none;cursor:pointer;padding:16px;display:flex;align-items:center;justify-content:space-between;gap:12px}
            .bsp-quote-admin__panel-toggle::-webkit-details-marker{display:none}
            .bsp-quote-admin__panel-toggle span{display:flex;flex-direction:column;gap:4px}
            .bsp-quote-admin__panel-toggle small{color:#50575e}
            .bsp-quote-admin__proposal-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:12px}
            .bsp-quote-admin__proposal-summary strong{display:block;margin-top:4px}
            .bsp-quote-admin__proposal-copy{margin:0 0 12px;max-width:72ch}
            .bsp-quote-admin__totals-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:12px 0 16px}
            .bsp-quote-admin__program{display:flex;flex-direction:column;gap:16px}
            .bsp-quote-admin__program-day{display:flex;flex-direction:column;gap:12px}
            .bsp-quote-admin__program-day-header{display:flex;justify-content:space-between;gap:12px;align-items:baseline;border-bottom:1px solid #dcdcde;padding-bottom:8px}
            .bsp-quote-admin__program-day-header h4{margin:0;font-size:16px}
            .bsp-quote-admin__program-list{display:flex;flex-direction:column;gap:12px}
            .bsp-quote-admin__program-item{display:grid;grid-template-columns:110px minmax(0,1fr);gap:16px;padding:16px;border:1px solid #dcdcde;border-radius:10px;background:linear-gradient(180deg,#ffffff 0%,#fcfcfd 100%)}
            .bsp-quote-admin__program-time{display:flex;flex-direction:column;gap:4px;padding-right:12px;border-right:1px solid #ececec}
            .bsp-quote-admin__program-time strong{font-size:20px;line-height:1.2}
            .bsp-quote-admin__program-time span{color:#50575e}
            .bsp-quote-admin__program-body{display:flex;flex-direction:column;gap:8px;min-width:0}
            .bsp-quote-admin__program-body h4{margin:0;font-size:18px;line-height:1.3}
            .bsp-quote-admin__program-subtitle{margin:0;color:#50575e}
            .bsp-quote-admin__program-price{display:flex;flex-direction:column;gap:2px}
            .bsp-quote-admin__program-note{margin:0}
            .bsp-quote-admin__table-wrap{overflow:auto}
            .bsp-quote-admin__lines-table td,.bsp-quote-admin__lines-table th{vertical-align:top}
            .bsp-quote-admin__cell-stack{display:flex;flex-direction:column;gap:4px;margin-top:4px}
            .bsp-quote-admin__checklist,.bsp-quote-admin__timeline{margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:10px}
            .bsp-quote-admin__checklist li,.bsp-quote-admin__timeline li{display:flex;flex-direction:column;gap:4px;padding:12px;border:1px solid #dcdcde;border-radius:8px;background:#fff}
            .bsp-quote-admin__checklist--compact{margin-top:8px;gap:8px}
            .bsp-quote-admin__checklist--compact li{padding:10px;background:#fcfcfd}
            .bsp-quote-admin__checklist--actions li{gap:6px}
            .bsp-quote-admin__checklist--actions .button-link{font-weight:600;text-decoration:none}
            .bsp-quote-admin__inline-form{display:inline-flex;align-items:center}
            .bsp-quote-admin__checklist--actions .bsp-quote-admin__inline-form + .button-link,
            .bsp-quote-admin__checklist--actions .button-link + .button-link{margin-top:2px}
            .bsp-quote-admin__readiness-summary{padding:12px;border-radius:8px;background:#f6f7f7;border:1px solid #dcdcde;margin-bottom:12px}
            .bsp-quote-admin__readiness-summary--operator{background:#fff;border-color:#d0d7de}
            .bsp-quote-admin__readiness-summary--compact{margin-top:12px;margin-bottom:0}
            .bsp-quote-admin__readiness-summary--action{background:#fffaf0;border-color:#e7c98b}
            .bsp-quote-admin__readiness-summary strong{display:block;margin-bottom:4px}
            .bsp-quote-admin__readiness-summary p{margin:0;color:#50575e}
            .bsp-quote-admin__focus-card{border-left:5px solid #dcdcde}
            .bsp-quote-admin__focus-card--blocked{background:#fff8f8;border-left-color:#d63638}
            .bsp-quote-admin__focus-card--assumptions{background:#fffbf0;border-left-color:#dba617}
            .bsp-quote-admin__focus-card--ready{background:#f8fff8;border-left-color:#46b450}
            .bsp-quote-admin__focus-card h2{margin:0 0 10px;font-size:24px;line-height:1.25}
            .bsp-quote-admin__focus-kicker{margin:0 0 8px;text-transform:uppercase;letter-spacing:.04em;font-size:12px;font-weight:700;color:#50575e}
            .bsp-quote-admin__focus-message{font-size:14px;margin:0 0 14px;color:#50575e;max-width:72ch}
            .bsp-quote-admin__focus-steps{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px;margin:14px 0}
            .bsp-quote-admin__focus-steps ol,.bsp-quote-admin__assumption-card ol{margin:8px 0 0 20px}
            .bsp-quote-admin__focus-button{min-width:220px;text-align:center}
            .bsp-quote-admin__focus-details{margin-top:12px;color:#50575e}
            .bsp-quote-admin__assumption-card{background:#fff;border:1px solid #e7c98b;border-radius:8px;padding:14px;margin:12px 0}
            .bsp-quote-admin__assumption-card strong{display:block;font-size:15px}
            .bsp-quote-admin__assumption-card p{margin:8px 0;color:#50575e}
            .bsp-quote-admin__assumption-card .button{margin-top:10px}
            .bsp-quote-admin__overview-hero{background:linear-gradient(180deg,#fff 0%,#f6f7f7 100%)}
            .bsp-quote-admin__overview-hero h2{margin:0 0 6px;font-size:26px;line-height:1.2}
            .bsp-quote-admin__overview-stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-top:16px}
            .bsp-quote-admin__overview-stat{display:flex;flex-direction:column;gap:4px;padding:12px 14px;border:1px solid #dcdcde;border-radius:8px;background:#fff;text-decoration:none;color:#1d2327}
            .bsp-quote-admin__overview-stat:hover,.bsp-quote-admin__overview-stat.is-active{border-color:#2271b1;box-shadow:inset 0 0 0 1px #2271b1}
            .bsp-quote-admin__overview-stat span{font-size:12px;color:#50575e;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
            .bsp-quote-admin__overview-stat strong{font-size:24px;line-height:1.1}
            .bsp-quote-admin__overview-next{border-left:5px solid #2271b1}
            .bsp-quote-admin__overview-next strong{display:block;font-size:20px;line-height:1.3;margin:4px 0}
            .bsp-quote-admin__overview-next p{margin:0 0 12px;color:#50575e}
            .bsp-quote-admin__overview-table td{vertical-align:top}
            .bsp-quote-admin__overview-row.is-action td:first-child{border-left:4px solid #d63638}
            .bsp-quote-admin__overview-row.is-assumptions td:first-child{border-left:4px solid #dba617}
            .bsp-quote-admin__overview-row.is-ready td:first-child{border-left:4px solid #46b450}
            .bsp-quote-admin__overview-row.is-done td:first-child{border-left:4px solid #8c8f94}
            .bsp-quote-admin__customer-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px}
            .bsp-quote-admin__customer-grid strong{display:block;margin-top:4px}
            .bsp-quote-admin__decision-grid{display:grid;grid-template-columns:minmax(0,1fr);gap:16px}
            .bsp-quote-admin__decision-action{padding:14px;border:1px solid #dcdcde;border-radius:10px;background:#f6f7f7;display:flex;flex-direction:column;gap:8px}
            .bsp-quote-admin__decision-action strong{font-size:18px;line-height:1.3}
            .bsp-quote-admin__decision-action p{margin:0;color:#50575e}
            .bsp-quote-admin__communication-workflow .bsp-quote-admin__panel-body{display:flex;flex-direction:column;gap:14px}
            .bsp-quote-admin__customer-reply-panel,.bsp-quote-admin__composer-card,.bsp-quote-admin__proposal-status-card,.bsp-quote-admin__timeline-card,.bsp-quote-admin__advanced-panel{padding:14px;border:1px solid #d0d7de;border-radius:8px;background:#fff}
            .bsp-quote-admin__customer-reply-panel{border-left:5px solid #2271b1;background:#f6fbff}
            .bsp-quote-admin__customer-reply-panel h3{margin:0;font-size:22px;line-height:1.2}
            .bsp-quote-admin__customer-reply-grid,.bsp-quote-admin__proposal-status-grid,.bsp-quote-admin__composer-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}
            .bsp-quote-admin__customer-reply-excerpt{margin:12px 0 8px;padding:12px;border:1px solid #d0d7de;border-radius:6px;background:#fff;font-size:14px;line-height:1.5}
            .bsp-quote-admin__section-heading{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:10px}
            .bsp-quote-admin__section-heading h4{margin:0;font-size:16px;line-height:1.25}
            .bsp-quote-admin__section-heading span{color:#646970;font-size:12px}
            .bsp-quote-admin__stack-form--composer textarea{min-height:180px}
            .bsp-quote-admin__stack-form--composer .button-primary{align-self:flex-start}
            .bsp-quote-admin__compact-timeline{display:flex;flex-direction:column;gap:8px}
            .bsp-quote-admin__timeline-row{border:1px solid #dcdcde;border-radius:6px;background:#fbfcfe}
            .bsp-quote-admin__timeline-row summary{display:grid;grid-template-columns:minmax(140px,.8fr) minmax(180px,1fr) minmax(180px,1fr);gap:8px;align-items:center;padding:10px 12px;cursor:pointer;list-style:none}
            .bsp-quote-admin__timeline-row summary::-webkit-details-marker{display:none}
            .bsp-quote-admin__timeline-row summary strong{font-size:13px;line-height:1.25}
            .bsp-quote-admin__timeline-row summary small{color:#646970;text-align:right}
            .bsp-quote-admin__timeline-row .bsp-quote-admin__message-summary,.bsp-quote-admin__timeline-row .bsp-quote-admin__message-body,.bsp-quote-admin__timeline-row .bsp-quote-admin__thread-actions{margin:0 12px 12px}
            .bsp-quote-admin__advanced-panel summary{cursor:pointer;font-weight:600;color:#50575e}
            .bsp-quote-admin__advanced-panel > *:not(summary){margin-top:10px}
            .bsp-quote-admin__stack-form{display:flex;flex-direction:column;gap:10px;margin-top:10px}
            .bsp-quote-admin__stack-form--muted{padding-top:12px;border-top:1px dashed #dcdcde}
            .bsp-quote-admin__stack-form label{display:flex;flex-direction:column;gap:4px;font-weight:600}
            .bsp-quote-admin__stack-form input,.bsp-quote-admin__stack-form textarea,.bsp-quote-admin__stack-form select{font-weight:400}
            .bsp-quote-admin__thread{display:flex;flex-direction:column;gap:12px;margin-top:16px}
            .bsp-quote-admin__thread-item{border:1px solid #dcdcde;border-radius:8px;padding:12px;background:#fff}
            .bsp-quote-admin__thread-item.is-inbound{background:#fffbf0}
            .bsp-quote-admin__thread-meta{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
            .bsp-quote-admin__message-summary{margin:10px 0 0}
            .bsp-quote-admin__message-body{margin-top:8px;white-space:pre-wrap;line-height:1.5}
            .bsp-quote-admin__thread-actions{margin-top:10px}
            .bsp-quote-admin__builder-intake{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin:0 0 16px;padding:14px;border:1px solid #d0d7de;border-radius:10px;background:#f6f8fa}
            .bsp-quote-admin__builder-intake strong{display:block;margin-top:4px}
            .bsp-quote-admin__quote-total-card{position:sticky;top:46px;bottom:auto;right:auto;width:auto;z-index:10;display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;padding:14px;border:1px solid #3b342d;border-radius:8px;background:#18120f;color:#fff;box-shadow:0 2px 8px rgba(0,0,0,.12)}
            .bsp-quote-admin__quote-total-card .bsp-quote-admin__field-label,.bsp-quote-admin__quote-total-card .bsp-quote-admin__muted{color:#d7ccc2}
            .bsp-quote-admin__quote-total-card strong{display:block;margin-top:5px;font-size:20px;line-height:1.25}
            .bsp-quote-admin__quote-total-card label{display:flex;flex-direction:column;gap:6px}
            .bsp-quote-admin__quote-total-card input{max-width:180px;background:#fff;color:#1d2327}
            .bsp-quote-admin__quote-discount-row{display:grid;grid-template-columns:minmax(80px,.8fr) 92px minmax(120px,1fr);gap:8px;align-items:end}
            .bsp-quote-admin__quote-discount-row .bsp-quote-admin__field-label{align-self:center}
            .bsp-quote-admin__quote-discount-summary{font-size:12px;color:#d7ccc2;align-self:end}
            .bsp-quote-admin__quote-total-card-total{border-top:1px solid rgba(255,255,255,.2);padding-top:8px}
            .bsp-quote-admin__quote-total-card-action{display:flex;align-items:end}
            .bsp-quote-admin__quote-total-card-action .button{width:100%;text-align:center}
            .bsp-quote-admin__builder-form{display:flex;flex-direction:column;gap:16px}
            .bsp-quote-admin__builder-list{display:flex;flex-direction:column;gap:12px}
            .bsp-quote-admin__builder-row{display:flex;flex-direction:column;gap:12px;padding:16px;border:1px solid #d0d7de;border-radius:14px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.04)}
            .bsp-quote-admin__builder-row--compact{padding:10px 12px;border-radius:8px}
            .bsp-quote-admin__builder-compact-summary{display:grid;grid-template-columns:28px minmax(180px,1.6fr) minmax(105px,.8fr) minmax(105px,.8fr) minmax(80px,.6fr) minmax(100px,.8fr) minmax(120px,.9fr) minmax(180px,1fr) minmax(100px,.6fr);gap:10px;align-items:center}
            .bsp-quote-admin__builder-compact-summary strong{display:block;font-size:13px;line-height:1.25}
            .bsp-quote-admin__builder-row-drag{display:flex;align-items:center;justify-content:center}
            .bsp-quote-admin__builder-row-actions{display:flex;align-items:center;justify-content:flex-end;gap:6px}
            .bsp-quote-admin__builder-edit-panel{position:relative}
            .bsp-quote-admin__builder-edit-panel > summary{cursor:pointer;list-style:none}
            .bsp-quote-admin__builder-edit-panel > summary::-webkit-details-marker{display:none}
            .bsp-quote-admin__builder-edit-fields{grid-column:1/-1;margin-top:12px;padding:12px;border:1px solid #d0d7de;border-radius:8px;background:#fbfcfe}
            .bsp-quote-admin__builder-row.is-dragging{opacity:.55}
            .bsp-quote-admin__builder-row-top{display:flex;justify-content:space-between;gap:12px;align-items:center}
            .bsp-quote-admin__builder-row-order{display:flex;align-items:center;gap:10px}
            .bsp-quote-admin__builder-row-order > div{display:flex;flex-direction:column;gap:2px}
            .bsp-quote-admin__builder-row-order span{color:#50575e}
            .bsp-quote-admin__builder-handle{cursor:grab;text-decoration:none;font-size:18px;line-height:1}
            .bsp-quote-admin__builder-card-status{display:flex;flex-wrap:wrap;gap:4px}
            .bsp-quote-admin__builder-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}
            .bsp-quote-admin__builder-grid--primary{grid-template-columns:minmax(240px,1.4fr) minmax(220px,1fr) minmax(170px,.7fr) minmax(150px,.6fr)}
            .bsp-quote-admin__builder-grid--commercial{grid-template-columns:180px 180px minmax(260px,1fr);align-items:end;padding:12px;border:1px solid #d0d7de;border-radius:12px;background:#fffaf0}
            .bsp-quote-admin__builder-grid label{display:flex;flex-direction:column;gap:4px;font-weight:600}
            .bsp-quote-admin__builder-grid input,.bsp-quote-admin__builder-grid select{font-weight:400}
            .bsp-quote-admin__builder-commercial-note{display:flex;flex-direction:column;gap:3px}
            .bsp-quote-admin__builder-commercial-note strong{font-size:16px}
            .bsp-quote-admin__builder-meta{display:flex;flex-direction:column;gap:4px;padding:10px 12px;border:1px solid #dcdcde;border-radius:8px;background:#f6f7f7}
            .bsp-quote-admin__slot-picker{border:1px solid #d0d7de;border-radius:12px;background:#fbfcfe;padding:12px;display:flex;flex-direction:column;gap:12px}
            .bsp-quote-admin__slot-picker-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
            .bsp-quote-admin__slot-picker-head strong{display:block;margin-top:3px;font-size:16px}
            .bsp-quote-admin__slot-picker-head p{margin:4px 0 0}
            .bsp-quote-admin__slot-actions{display:flex;flex-direction:column;gap:8px;align-items:flex-start}
            .bsp-quote-admin__slot-actions label{font-size:12px;color:#50575e}
            .bsp-quote-admin__slot-list{display:flex;flex-wrap:wrap;gap:8px}
            .bsp-quote-admin__slot-pill{border:1px solid #8c8f94;border-radius:999px;background:#fff;color:#1d2327;padding:7px 12px;cursor:pointer}
            .bsp-quote-admin__slot-pill:hover,.bsp-quote-admin__slot-pill.is-selected{border-color:#0a5222;background:#dff5e3;color:#0a5222}
            .bsp-quote-admin__slot-empty{display:inline-flex;align-items:center;min-height:30px;color:#646970}
            .bsp-quote-admin__builder-advanced{border-top:1px dashed #d0d7de;padding-top:10px}
            .bsp-quote-admin__builder-advanced summary{cursor:pointer;font-weight:600;color:#50575e}
            .bsp-quote-admin__builder-advanced .bsp-quote-admin__builder-grid{margin-top:12px}
            @media (max-width: 1080px){
                .bsp-quote-admin__summary-cards{grid-template-columns:1fr}
                .bsp-quote-admin__workspace-hero-inner,.bsp-quote-admin__workspace-grid{grid-template-columns:1fr}
                .bsp-quote-admin__program-item{grid-template-columns:1fr}
                .bsp-quote-admin__program-time{border-right:0;border-bottom:1px solid #ececec;padding-right:0;padding-bottom:8px}
                .bsp-quote-admin__panel--operator{position:static}
                .bsp-quote-admin__builder-compact-summary{grid-template-columns:28px minmax(160px,1.4fr) repeat(3,minmax(90px,.8fr)) minmax(140px,1fr)}
                .bsp-quote-admin__builder-card-status{grid-column:2/5}
                .bsp-quote-admin__builder-row-actions{grid-column:5/7}
                .bsp-quote-admin__builder-grid--primary{grid-template-columns:1fr 1fr}
                .bsp-quote-admin__builder-grid--commercial{grid-template-columns:1fr 1fr}
            }
            @media (max-width: 782px){
                .bsp-quote-admin__workspace{gap:12px}
                .bsp-quote-admin__decision-strip{grid-template-columns:1fr}
                .bsp-quote-admin__decision-strip-main{grid-template-columns:1fr}
                .bsp-quote-admin__decision-strip-action{min-width:0}
                .bsp-quote-admin__workspace-tabs{display:flex;flex-wrap:wrap;overflow-x:visible;white-space:normal;padding-bottom:2px;gap:4px}
                .bsp-quote-admin__workspace-tabs .nav-tab{margin-left:0}
                .bsp-quote-admin__workspace-hero-inner{padding:14px}
                .bsp-quote-admin__compact-quote-header{grid-template-columns:1fr}
                .bsp-quote-admin__compact-next-action{grid-column:auto}
                .bsp-quote-admin__send-check{grid-template-columns:1fr}
                .bsp-quote-admin__timeline-row summary{grid-template-columns:1fr}
                .bsp-quote-admin__timeline-row summary small{text-align:left}
                .bsp-quote-admin__quote-total-card{position:static;grid-template-columns:1fr}
                .bsp-quote-admin__quote-discount-row{grid-template-columns:1fr}
                .bsp-quote-admin__builder-compact-summary{grid-template-columns:28px minmax(0,1fr)}
                .bsp-quote-admin__builder-compact-summary > div:not(.bsp-quote-admin__builder-row-drag){grid-column:2}
                .bsp-quote-admin__builder-row-actions{grid-column:2;justify-content:flex-start}
                .bsp-quote-admin__workspace-heading h2{font-size:22px}
                .bsp-quote-admin__workspace-meta{grid-template-columns:1fr;padding:12px 14px 14px}
                .bsp-quote-admin__panel-body{padding:14px}
                .bsp-quote-admin__focus-card h2{font-size:20px}
                .bsp-quote-admin__focus-button{width:100%;min-width:0;text-align:center}
                .bsp-quote-admin__assumption-card .button{width:100%;text-align:center}
                .bsp-quote-admin__overview-stat-grid{grid-template-columns:1fr 1fr}
                .bsp-quote-admin__overview-next .button{width:100%;text-align:center}
                .bsp-quote-admin__customer-grid{grid-template-columns:1fr}
                .bsp-quote-admin__builder-grid--primary,.bsp-quote-admin__builder-grid--commercial,.bsp-quote-admin__slot-picker-head{grid-template-columns:1fr;display:grid}
            }

            /* ── Command Shell: 2-column workspace ── */
            .bsp-quote-admin__command-wrap { max-width: 100% !important; padding-right: 0 !important; }
            .bsp-quote-admin__command-shell {
                display: grid;
                grid-template-columns: 260px 1fr;
                gap: 0;
                min-height: calc(100vh - 46px);
                margin-top: 12px;
                border: 1px solid var(--bsp-admin-border);
                border-radius: 8px;
                overflow: hidden;
                background: var(--bsp-admin-bg);
            }
            .bsp-quote-admin__quote-sidebar {
                background: #1d2327;
                color: #f0f0f1;
                display: flex;
                flex-direction: column;
                overflow: hidden;
            }
            .bsp-quote-admin__sidebar-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 14px 16px 12px;
                border-bottom: 1px solid rgba(255,255,255,0.1);
                flex-shrink: 0;
            }
            .bsp-quote-admin__sidebar-header strong {
                font-size: 13px;
                font-weight: 600;
                color: #fff;
                letter-spacing: 0.03em;
                text-transform: uppercase;
            }
            .bsp-quote-admin__sidebar-list {
                overflow-y: auto;
                flex: 1;
            }
            .bsp-quote-admin__sidebar-item {
                display: block;
                padding: 11px 16px;
                border-bottom: 1px solid rgba(255,255,255,0.05);
                text-decoration: none;
                transition: background 0.1s;
                cursor: pointer;
            }
            .bsp-quote-admin__sidebar-item:hover { background: rgba(255,255,255,0.06); }
            .bsp-quote-admin__sidebar-item.is-active { background: var(--bsp-admin-primary); }
            .bsp-quote-admin__sidebar-item-name {
                font-size: 13px;
                font-weight: 600;
                color: #fff;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                display: block;
            }
            .bsp-quote-admin__sidebar-item-meta {
                font-size: 11px;
                color: rgba(255,255,255,0.55);
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                display: block;
                margin-top: 2px;
            }
            .bsp-quote-admin__sidebar-item-focus {
                font-size: 11px;
                margin-top: 4px;
                display: flex;
                align-items: center;
                gap: 6px;
            }
            .bsp-quote-admin__sidebar-urgency {
                display: inline-block;
                width: 7px;
                height: 7px;
                border-radius: 50%;
                flex-shrink: 0;
            }
            .bsp-quote-admin__sidebar-urgency--action { background: #f87171; }
            .bsp-quote-admin__sidebar-urgency--assumptions { background: #fbbf24; }
            .bsp-quote-admin__sidebar-urgency--ready { background: #34d399; }
            .bsp-quote-admin__sidebar-urgency--done { background: rgba(255,255,255,0.25); }
            .bsp-quote-admin__sidebar-focus-label {
                font-size: 11px;
                color: rgba(255,255,255,0.65);
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .bsp-quote-admin__command-main {
                background: #f0f0f1;
                overflow-y: auto;
                padding: 0;
            }
            .bsp-quote-admin__command-main .bsp-quote-admin__workspace {
                border-radius: 0;
                border: none;
            }
            .bsp-quote-admin__command-splash {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                height: 100%;
                min-height: 300px;
                gap: 12px;
                color: var(--bsp-admin-muted);
                padding: 40px;
                text-align: center;
            }
            .bsp-quote-admin__command-splash h2 { font-size: 20px; color: var(--bsp-admin-text); margin: 0; }
            .bsp-quote-admin__command-splash p { margin: 0; max-width: 36ch; }
            @media (max-width: 1100px) {
                .bsp-quote-admin__command-shell { grid-template-columns: 200px 1fr; }
            }
            @media (max-width: 782px) {
                .bsp-quote-admin__command-shell { grid-template-columns: 1fr; grid-template-rows: auto 1fr; }
                .bsp-quote-admin__quote-sidebar { min-height: 0; max-height: 200px; }
            }
        </style>';
    }
}
