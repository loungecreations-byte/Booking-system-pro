<?php

declare(strict_types=1);

namespace BSP\Quotes\Admin;

use BSP\Core\Services\BookingModeService;
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
                'id' => (int) ($line['id'] ?? 0),
                'quote_version_id' => (int) ($line['quote_version_id'] ?? 0),
                'source_line_number' => (int) ($line['line_number'] ?? ($index + 1)),
                'sort_order' => (int) ($line['sort_order'] ?? ($line['line_number'] ?? ($index + 1))),
                'line_status' => (string) ($line['line_status'] ?? ''),
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
                'id' => 0,
                'quote_version_id' => $currentVersion !== null ? (int) ($currentVersion['id'] ?? 0) : 0,
                'source_line_number' => 0,
                'sort_order' => 1,
                'line_status' => '',
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
            if (self::quoteLinePricingControlStatus($builderRow) === 'needs_check') {
                ++$openBlockers;
            }
            if (in_array(self::quoteLineAvailabilityControlStatus($builderRow), array('needs_check', 'unavailable'), true)) {
                ++$openBlockers;
            }
        }
        if ((string) ($quote['review_status'] ?? 'not_started') !== 'approved' || (string) ($quote['send_status'] ?? 'not_ready') !== 'ready_to_send') {
            ++$openBlockers;
        }
        $primaryActionUrl = add_query_arg(array(
            'page' => 'sbdp_quotes',
            'quote_id' => $quoteId,
            'workspace_tab' => $readyToSend ? 'communication' : 'build',
        ), admin_url('admin.php'));

        echo '<section class="postbox bsp-quote-admin__panel"><div class="bsp-quote-admin__panel-header"><div><h3>' . esc_html__('Offerte controleren', 'sbdp') . '</h3><p class="bsp-quote-admin__muted">' . esc_html__('Controleer klantvraag, programma, prijs, beschikbaarheid en open punten op één plek.', 'sbdp') . '</p></div></div><div class="bsp-quote-admin__panel-body">';
        echo '<div class="bsp-quote-admin__readiness-summary bsp-quote-admin__readiness-summary--operator"><strong>' . esc_html(sprintf(__('Werkversie %s', 'sbdp'), $versionLabel)) . '</strong><p>' . esc_html($frozenHint) . '</p></div>';
        echo '<div class="bsp-quote-admin__builder-intake"><div><span class="bsp-quote-admin__field-label">' . esc_html__('Datum', 'sbdp') . '</span><strong>' . esc_html($defaultDate !== '' ? $defaultDate : __('Nog open', 'sbdp')) . '</strong></div><div><span class="bsp-quote-admin__field-label">' . esc_html__('Groep', 'sbdp') . '</span><strong>' . esc_html($defaultParticipants > 0 ? sprintf(__('%d personen', 'sbdp'), $defaultParticipants) : __('Nog open', 'sbdp')) . '</strong></div><div><span class="bsp-quote-admin__field-label">' . esc_html__('Nog nodig voor verzenden', 'sbdp') . '</span><strong>' . esc_html((string) $openBlockers) . '</strong></div></div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__builder-form" data-builder-slots-url="' . esc_url($availabilitySlotsUrl) . '" data-builder-rest-nonce="' . esc_attr($restNonce) . '">';
        echo wp_nonce_field('sbdp_quote_save_operations_draft', '_wpnonce', true, false);
        echo '<input type="hidden" name="action" value="sbdp_quote_save_operations_draft"><input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '">';
        echo '<div class="bsp-quote-admin__actions bsp-quote-admin__actions--stacked"><button class="button button-primary" type="submit">' . esc_html__('Bewaar dagprogramma', 'sbdp') . '</button><button class="button button-secondary" type="submit" name="create_new_version" value="1">' . esc_html__('Maak nieuwe versie', 'sbdp') . '</button><button class="button button-secondary bsp-quote-admin__builder-add" type="button">' . esc_html__('Voeg programmaregel toe', 'sbdp') . '</button></div>';
        echo '<p class="bsp-quote-admin__muted bsp-quote-admin__proposal-copy">' . esc_html__('Bewerk regels inline. Beschikbaarheid bepaalt of verzenden kan.', 'sbdp') . '</p>';
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
        echo '  <div><span class="bsp-quote-admin__field-label">' . esc_html__('Beschikbaarheidsstatus', 'sbdp') . '</span><strong>' . esc_html(self::quoteBuilderAvailabilityLabel($availabilityStatus)) . '</strong></div>';
        echo '  <div><span class="bsp-quote-admin__field-label">' . esc_html__('Status', 'sbdp') . '</span><strong>' . esc_html($readyToSend ? __('Klaar voor verzending', 'sbdp') : __('Niet verzendklaar', 'sbdp')) . '</strong></div>';
        echo '  <div><span class="bsp-quote-admin__field-label">' . esc_html__('Nog nodig', 'sbdp') . '</span><strong>' . esc_html($readyToSend ? __('Geen', 'sbdp') : ($openBlockers > 0 ? sprintf(_n('%d punt', '%d punten', $openBlockers, 'sbdp'), $openBlockers) : __('Vrijgave nodig', 'sbdp'))) . '</strong></div>';
        echo '  <div class="bsp-quote-admin__quote-total-card-action"><a class="button button-primary" href="' . esc_url($primaryActionUrl) . '">' . esc_html($readyToSend ? __('Open voorstelcontrole', 'sbdp') : __('Controleer open punten', 'sbdp')) . '</a></div>';
        echo '</div>';
        echo '<div class="bsp-quote-admin__builder-list" data-builder-list>';
        foreach (array_values($builderRows) as $index => $builderRow) {
            echo self::renderQuoteBuildRow($index, $builderRow, $catalog, $quoteId);
        }
        echo '</div>';
        echo '<template id="bsp-quote-builder-row-template">' . self::renderQuoteBuildRow('__INDEX__', array(
            'id' => 0,
            'quote_version_id' => $currentVersion !== null ? (int) ($currentVersion['id'] ?? 0) : 0,
            'source_line_number' => 0,
            'sort_order' => 0,
            'line_status' => '',
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
        echo '</form>';
        self::renderLineControlForms($quoteId, $builderRows);
        echo '</div></section>';
        self::renderQuoteBuildScript();
    }

    /**
     * @param int|string $index
     * @param array<string, mixed> $line
     * @param array<int, array<string, mixed>> $catalog
     */
    public static function renderQuoteBuildRow($index, array $line, array $catalog, int $quoteId = 0): string
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
        $pricingControlStatus = self::quoteLinePricingControlStatus($line);
        $availabilityControlStatus = self::quoteLineAvailabilityControlStatus($line);
        $availabilityLabel = self::quoteBuilderAvailabilityLabel($availabilityConfidence);
        $lineHasBlocker = $availabilityControlStatus === 'unavailable' || (string) ($line['line_status'] ?? '') === 'unavailable';
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
        $currency = (string) (($line['currency'] ?? '') ?: 'EUR');
        $unitAmount = is_numeric($line['unit_amount_snapshot'] ?? null) ? (float) $line['unit_amount_snapshot'] : null;
        $lineTotal = is_numeric($line['line_total_snapshot'] ?? null) ? (float) $line['line_total_snapshot'] : null;
        $unitLabel = $unitAmount !== null && $unitAmount > 0.0 ? self::formatMoney($unitAmount, $currency) . ' p.p.' : __('Prijs open', 'sbdp');
        $totalLabel = $lineTotal !== null && $lineTotal > 0.0 ? self::formatMoney($lineTotal, $currency) : $priceSnapshot;
        $availabilitySymbol = match ($availabilityControlStatus) {
            'confirmed' => '✓',
            'unavailable' => '✕',
            'under_reservation' => '–',
            default => '!',
        };
        $availabilityText = self::quoteLineControlLabel($availabilityControlStatus, 'availability');

        $hiddenFields = '<input type="hidden" name="lines[' . $indexAttr . '][tax_class]" value="' . esc_attr((string) ($line['tax_class'] ?? '')) . '">';
        $hiddenFields .= '<input type="hidden" name="lines[' . $indexAttr . '][source_line_number]" value="' . esc_attr((string) ($line['source_line_number'] ?? 0)) . '">';
        $hiddenFields .= '<input type="hidden" name="lines[' . $indexAttr . '][sort_order]" value="' . esc_attr((string) $sortOrder) . '" data-builder-sort-order>';
        $hiddenFields .= '<input type="hidden" name="lines[' . $indexAttr . '][vendor_id]" value="' . esc_attr((string) ($line['vendor_id'] ?? '')) . '">';
        $hiddenFields .= '<input type="hidden" name="lines[' . $indexAttr . '][resource_id]" value="' . esc_attr((string) ($line['resource_id'] ?? '')) . '">';
        $hiddenFields .= '<input type="hidden" name="lines[' . $indexAttr . '][pricing_mode]" value="' . esc_attr((string) ($line['pricing_mode'] ?? 'directional')) . '" data-builder-pricing-mode>';
        $hiddenFields .= '<input type="hidden" name="lines[' . $indexAttr . '][pricing_confidence]" value="' . esc_attr((string) ($line['pricing_confidence'] ?? 'unknown')) . '" data-builder-pricing-confidence>';
        $hiddenFields .= '<input type="hidden" name="lines[' . $indexAttr . '][availability_confidence]" value="' . esc_attr((string) ($line['availability_confidence'] ?? 'unknown')) . '" data-builder-availability-confidence>';
        $hiddenFields .= '<input type="hidden" name="lines[' . $indexAttr . '][currency]" value="' . esc_attr((string) (($line['currency'] ?? '') ?: 'EUR')) . '" data-builder-currency>';
        $hiddenFields .= '<input type="hidden" name="lines[' . $indexAttr . '][pricing_snapshot_json]" value="' . esc_attr((string) \wp_json_encode($line['pricing_snapshot_json'] ?? array())) . '">';
        $hiddenFields .= '<input type="hidden" name="lines[' . $indexAttr . '][availability_snapshot_json]" value="' . esc_attr((string) \wp_json_encode($line['availability_snapshot_json'] ?? array())) . '">';
        $hiddenFields .= '<input type="hidden" name="lines[' . $indexAttr . '][mapping_notes]" value="' . esc_attr((string) ($line['mapping_notes'] ?? '')) . '">';
        $hiddenFields .= '<input type="hidden" name="lines[' . $indexAttr . '][external_label]" value="' . esc_attr((string) ($line['external_label'] ?? '')) . '">';
        $hiddenFields .= '<input type="hidden" name="lines[' . $indexAttr . '][validated_slot_label]" value="' . esc_attr($slotLabel) . '" data-builder-slot-label>';
        $hiddenFields .= '<input type="hidden" name="lines[' . $indexAttr . '][position_group]" value="' . esc_attr((string) ($line['position_group'] ?? '')) . '">';
        $hiddenFields .= '<input type="hidden" name="lines[' . $indexAttr . '][is_optional]" value="' . esc_attr(! empty($line['is_optional']) ? '1' : '0') . '">';
        $hiddenFields .= '<input type="hidden" name="lines[' . $indexAttr . '][selected_option_labels]" value="' . esc_attr((string) ($line['selected_option_labels'] ?? '')) . '">';
        $hiddenFields .= '<input type="hidden" name="lines[' . $indexAttr . '][duration_minutes]" value="' . esc_attr((string) ((int) ($line['duration_minutes'] ?? 0))) . '" data-builder-duration>';

        $html = '<article class="bsp-quote-admin__builder-row bsp-quote-admin__builder-row--compact' . ($lineHasBlocker ? ' has-blocker' : '') . '" data-builder-row draggable="true">';
        $html .= $hiddenFields;
        $html .= '<div class="bsp-quote-admin__builder-compact-summary">';
        $html .= '<div class="bsp-quote-admin__builder-row-drag"><button type="button" class="button-link bsp-quote-admin__builder-handle" aria-label="' . esc_attr__('Versleep', 'sbdp') . '">≡</button></div>';
        $html .= '<div class="bsp-quote-admin__builder-row-headline"><span class="bsp-quote-admin__tiny-label">' . esc_html(sprintf(__('Regel %d', 'sbdp'), $sortOrder)) . '</span><strong data-builder-title-label>' . esc_html($rowTitle) . '</strong><small><span data-builder-time-label>' . esc_html($slotLabel !== '' ? $slotLabel : __('Tijd nog open', 'sbdp')) . '</span> · <span data-builder-participants-label>' . esc_html((string) ((int) ($line['participants'] ?? 0))) . '</span> ' . esc_html__('pers.', 'sbdp') . ' · <span data-builder-unit-summary>' . esc_html($unitLabel) . '</span> · <span data-builder-line-summary-total>' . esc_html($totalLabel) . '</span></small></div>';
        $html .= '<div class="bsp-quote-admin__builder-availability-summary"><span title="' . esc_attr($availabilityText) . '">' . esc_html($availabilitySymbol) . '</span><small>' . esc_html($availabilityText) . '</small></div>';
        $html .= '<div class="bsp-quote-admin__builder-row-actions">';
        $html .= '<button type="button" class="button button-small bsp-quote-admin__builder-edit-toggle" data-builder-edit-toggle>' . esc_html__('Wijzig', 'sbdp') . '</button>';
        $html .= '<button type="button" class="button-link bsp-quote-admin__builder-duplicate" title="' . esc_attr__('Dupliceer', 'sbdp') . '"><span class="dashicons dashicons-admin-page"></span></button>';
        $html .= '<button type="button" class="button-link bsp-quote-admin__builder-remove" title="' . esc_attr__('Verwijder', 'sbdp') . '"><span class="dashicons dashicons-trash"></span></button>';
        $html .= '</div></div>';
        $html .= '<div class="bsp-quote-admin__builder-edit-panel">';
        $html .= '<div class="bsp-quote-admin__builder-edit-fields">';
        $html .= '<div class="bsp-quote-admin__builder-row-main-inputs">';
        $html .= '<label class="bsp-quote-admin__compact-field bsp-quote-admin__compact-field--product"><span>' . esc_html__('Activiteit', 'sbdp') . '</span><select name="lines[' . $indexAttr . '][product_id]" data-builder-product-select class="bsp-quote-admin__compact-select">' . $productOptions . '</select></label>';
        $html .= '<label class="bsp-quote-admin__compact-field bsp-quote-admin__compact-field--title"><span>' . esc_html__('Titel in voorstel', 'sbdp') . '</span><input type="text" name="lines[' . $indexAttr . '][title]" value="' . esc_attr((string) ($line['title'] ?? '')) . '" placeholder="' . esc_attr__('Titel voor klant', 'sbdp') . '" data-builder-title class="bsp-quote-admin__compact-input"></label>';
        $html .= '<label class="bsp-quote-admin__compact-field"><span>' . esc_html__('Datum', 'sbdp') . '</span><input type="date" name="lines[' . $indexAttr . '][service_date]" value="' . esc_attr($dateValue) . '" data-builder-date class="bsp-quote-admin__compact-input-date"></label>';
        $html .= '<label class="bsp-quote-admin__compact-field"><span>' . esc_html__('Start', 'sbdp') . '</span><input type="time" name="lines[' . $indexAttr . '][proposed_start_time]" value="' . esc_attr($startValue) . '" data-builder-start-time></label>';
        $html .= '<label class="bsp-quote-admin__compact-field"><span>' . esc_html__('Einde', 'sbdp') . '</span><input type="time" name="lines[' . $indexAttr . '][proposed_end_time]" value="' . esc_attr($endValue) . '" data-builder-end-time></label>';
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
        $html .= '</div>';
        $html .= self::renderLineControlButtons($quoteId, $line, $pricingControlStatus, $availabilityControlStatus);
        if ($productId <= 0) {
            $html .= '<p class="bsp-quote-admin__muted">' . esc_html__('Maatwerkregels of handmatig aangepaste tijden blijven onder voorbehoud', 'sbdp') . '</p>';
        }
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</article>';

        return $html;
    }

    /**
     * @param array<string, mixed> $line
     */
    private static function quoteLineBookingMode(array $line): string
    {
        $productId = (int) ($line['product_id'] ?? 0);
        if ($productId <= 0) {
            return '';
        }

        $service = new BookingModeService();
        $resolved = $service->resolve($productId);

        return (string) ($resolved['bookingMode'] ?? '');
    }

    /**
     * @param array<string, mixed> $line
     */
    private static function quoteLineSupplierStatus(array $line): string
    {
        $snapshot = is_array($line['availability_snapshot_json'] ?? null) ? $line['availability_snapshot_json'] : array();
        $status = trim((string) ($snapshot['supplierStatus'] ?? ''));
        if ($status !== '') {
            return $status;
        }

        return 'supplier_confirmation_required';
    }

    private static function quoteLineSupplierStatusLabel(string $status): string
    {
        return match ($status) {
            'supplier_option_requested' => __('Optie aangevraagd', 'sbdp'),
            'supplier_option_held' => __('Optie bevestigd', 'sbdp'),
            'supplier_declined' => __('Partner geweigerd', 'sbdp'),
            'supplier_booking_confirmed' => __('Definitief bevestigd', 'sbdp'),
            'supplier_unavailable' => __('Niet beschikbaar', 'sbdp'),
            default => __('Partnerbevestiging nodig', 'sbdp'),
        };
    }

    private static function supplierConfirmationOptions(): array
    {
        return array(
            'supplier_confirmation_required' => __('Partnerbevestiging nodig', 'sbdp'),
            'supplier_option_requested' => __('Optie aangevraagd', 'sbdp'),
            'supplier_option_held' => __('Optie bevestigd', 'sbdp'),
            'supplier_declined' => __('Partner geweigerd', 'sbdp'),
            'supplier_booking_confirmed' => __('Definitief bevestigd', 'sbdp'),
            'supplier_unavailable' => __('Niet beschikbaar', 'sbdp'),
        );
    }

    /**
     * @param array<string, mixed> $line
     */
    private static function renderSupplierConfirmationPanel(int $quoteId, array $line): string
    {
        $lineId = (int) ($line['id'] ?? 0);
        if ($quoteId <= 0 || $lineId <= 0) {
            return '';
        }

        $snapshot = is_array($line['availability_snapshot_json'] ?? null) ? $line['availability_snapshot_json'] : array();
        $status = self::quoteLineSupplierStatus($line);
        $optionExpiresAt = trim((string) ($snapshot['optionExpiresAt'] ?? ''));
        $supplierBookingReference = trim((string) ($snapshot['supplierBookingReference'] ?? ''));
        $internalNote = trim((string) ($snapshot['supplierInternalNote'] ?? ''));
        $supplierName = trim((string) ($snapshot['supplierName'] ?? 'Eropuitje'));
        $bookingMode = trim((string) ($snapshot['bookingMode'] ?? BookingModeService::MODE_SUPPLIER_CONFIRMATION));

        $statusLabel = self::quoteLineSupplierStatusLabel($status);
        $availabilityStatus = trim((string) ($snapshot['availabilityStatus'] ?? ''));
        $availabilityCheckedAt = trim((string) ($snapshot['availabilityCheckedAt'] ?? ''));

        $badgeClass = in_array($status, array('supplier_booking_confirmed'), true)
            ? 'bsp-badge--success'
            : (in_array($status, array('supplier_declined', 'supplier_unavailable'), true)
                ? 'bsp-badge--error'
                : (in_array($status, array('supplier_option_requested', 'supplier_option_held'), true)
                    ? 'bsp-badge--info'
                    : 'bsp-badge--warning'));

        $html = '<div class="bsp-quote-admin__supplier-panel">';
        $html .= '<div class="bsp-quote-admin__supplier-panel-header">';
        $html .= '<strong>' . esc_html__('Partner', 'sbdp') . '</strong>';
        $html .= '<span class="bsp-badge ' . esc_attr($badgeClass) . '">' . esc_html($statusLabel) . '</span>';
        if ($supplierName !== '') {
            $html .= '<span class="bsp-quote-admin__muted">' . esc_html($supplierName) . '</span>';
        }
        $html .= '</div>';

        $infoItems = array();
        if ($availabilityStatus !== '') {
            $infoItems[] = esc_html__('Beschikbaarheid', 'sbdp') . ': <strong>' . esc_html($availabilityStatus) . '</strong>';
        }
        if ($availabilityCheckedAt !== '') {
            $infoItems[] = esc_html__('Gecheckt op', 'sbdp') . ': <strong>' . esc_html($availabilityCheckedAt) . '</strong>';
        }
        if ($optionExpiresAt !== '') {
            $infoItems[] = esc_html__('Optie geldig tot', 'sbdp') . ': <strong>' . esc_html($optionExpiresAt) . '</strong>';
        }
        if ($supplierBookingReference !== '') {
            $infoItems[] = esc_html__('Partnerreferentie', 'sbdp') . ': <strong>' . esc_html($supplierBookingReference) . '</strong>';
        }
        if ($infoItems !== array()) {
            $html .= '<p class="bsp-quote-admin__muted bsp-quote-admin__supplier-info-row">' . implode(' &middot; ', $infoItems) . '</p>';
        }

        $html .= '<div class="bsp-quote-admin__supplier-mini-row">';
        if (in_array($status, array('supplier_confirmation_required', 'supplier_option_requested'), true)) {
            $html .= '<div class="bsp-quote-admin__supplier-panel-form bsp-quote-admin__supplier-panel-form--action">';
            $html .= '<button type="submit" form="' . esc_attr(self::supplierRequestFormId($lineId)) . '" class="button button-primary">' . esc_html__('Vraag partner', 'sbdp') . '</button>';
            $html .= '</div>';
        }

        $statusFormId = self::supplierStatusFormId($lineId);
        $html .= '<div class="bsp-quote-admin__supplier-panel-form">';
        $html .= '<label><span class="bsp-quote-admin__tiny-label">' . esc_html__('Status', 'sbdp') . '</span><select name="supplier_status" form="' . esc_attr($statusFormId) . '" class="bsp-quote-admin__compact-select">';
        foreach (self::supplierConfirmationOptions() as $value => $label) {
            $html .= '<option value="' . esc_attr($value) . '"' . selected($status, $value, false) . '>' . esc_html($label) . '</option>';
        }
        $html .= '</select></label>';
        $html .= '<label><span class="bsp-quote-admin__tiny-label">' . esc_html__('Optie tot', 'sbdp') . '</span><input type="text" name="option_expires_at" form="' . esc_attr($statusFormId) . '" value="' . esc_attr($optionExpiresAt) . '" placeholder="2026-05-23T10:00:00+00:00" class="bsp-quote-admin__compact-input"></label>';
        $html .= '<label><span class="bsp-quote-admin__tiny-label">' . esc_html__('Ref.', 'sbdp') . '</span><input type="text" name="supplier_booking_reference" form="' . esc_attr($statusFormId) . '" value="' . esc_attr($supplierBookingReference) . '" class="bsp-quote-admin__compact-input"></label>';
        $html .= '<label><span class="bsp-quote-admin__tiny-label">' . esc_html__('Notitie', 'sbdp') . '</span><input type="text" name="internal_note" form="' . esc_attr($statusFormId) . '" value="' . esc_attr($internalNote) . '" class="bsp-quote-admin__compact-input"></label>';
        $html .= '<button type="submit" form="' . esc_attr($statusFormId) . '" class="button button-secondary">' . esc_html__('Opslaan', 'sbdp') . '</button>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';

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

    /**
     * @param array<int, array<string, mixed>> $lines
     */
    private static function renderLineControlForms(int $quoteId, array $lines): void
    {
        foreach ($lines as $line) {
            $lineId = (int) ($line['id'] ?? 0);
            if ($quoteId <= 0 || $lineId <= 0) {
                continue;
            }

            foreach (array('pricing', 'availability') as $dimension) {
                $statuses = $dimension === 'pricing'
                    ? array('needs_check', 'confirmed', 'under_reservation')
                    : array('needs_check', 'confirmed', 'under_reservation', 'unavailable');
                foreach ($statuses as $status) {
                    $formId = self::lineControlFormId($lineId, $dimension, $status);
                    echo '<form id="' . esc_attr($formId) . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__hidden-control-form">';
                    echo wp_nonce_field('sbdp_quote_line_control_status', '_wpnonce', true, false);
                    echo '<input type="hidden" name="action" value="sbdp_quote_line_control_status">';
                    echo '<input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '">';
                    echo '<input type="hidden" name="line_id" value="' . esc_attr((string) $lineId) . '">';
                    echo '<input type="hidden" name="dimension" value="' . esc_attr($dimension) . '">';
                    echo '<input type="hidden" name="status" value="' . esc_attr($status) . '">';
                    echo '</form>';
                }
            }

            if (self::quoteLineBookingMode($line) === BookingModeService::MODE_SUPPLIER_CONFIRMATION) {
                self::renderSupplierConfirmationHiddenForms($quoteId, $line);
            }
        }
    }

    /**
     * @param array<string, mixed> $line
     */
    private static function renderLineControlButtons(int $quoteId, array $line, string $pricingStatus, string $availabilityStatus): string
    {
        $lineId = (int) ($line['id'] ?? 0);
        if ($quoteId <= 0 || $lineId <= 0) {
            return '<p class="bsp-quote-admin__muted">' . esc_html__('Sla deze nieuwe regel eerst op om beschikbaarheid te markeren.', 'sbdp') . '</p>';
        }

        $html = '<div id="quote-line-control-' . esc_attr((string) $lineId) . '" class="bsp-quote-admin__line-control-panel">';
        $html .= self::renderLineControlGroup(
            __('Beschikbaarheid', 'sbdp'),
            self::quoteLineControlLabel($availabilityStatus, 'availability'),
            $lineId,
            'availability',
            array(
                'confirmed' => __('✓ Beschikbaar', 'sbdp'),
                'needs_check' => __('! Controleren', 'sbdp'),
                'unavailable' => __('✕ Niet beschikbaar', 'sbdp'),
                'under_reservation' => __('– N.v.t.', 'sbdp'),
            ),
            $availabilityStatus
        );
        if ($availabilityStatus === 'unavailable') {
            $html .= '<p class="bsp-quote-admin__line-control-blocker">' . esc_html__('Blocker: deze programmaregel is niet beschikbaar en kan zo niet worden verzonden.', 'sbdp') . '</p>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * @param array<string, string> $actions
     */
    private static function renderLineControlGroup(string $title, string $currentLabel, int $lineId, string $dimension, array $actions, string $currentStatus): string
    {
        $html = '<div class="bsp-quote-admin__line-control-group">';
        $html .= '<div><span class="bsp-quote-admin__tiny-label">' . esc_html($title) . '</span><strong>' . esc_html($currentLabel) . '</strong></div>';
        $html .= '<div class="bsp-quote-admin__line-control-actions">';
        foreach ($actions as $status => $label) {
            $disabled = $status === $currentStatus ? ' disabled' : '';
            $buttonClass = 'button bsp-quote-admin__availability-segment is-' . sanitize_html_class((string) $status);
            if ($status === $currentStatus) {
                $buttonClass .= ' is-active';
            }
            $symbol = match ((string) $status) {
                'confirmed' => '✓',
                'needs_check' => '!',
                'unavailable' => '✕',
                'under_reservation' => '–',
                default => '•',
            };
            $html .= '<button type="submit" class="' . esc_attr($buttonClass) . '" form="' . esc_attr(self::lineControlFormId($lineId, $dimension, (string) $status)) . '" title="' . esc_attr($label) . '"' . $disabled . '><span aria-hidden="true">' . esc_html($symbol) . '</span><span class="screen-reader-text">' . esc_html($label) . '</span></button>';
        }
        $html .= '</div></div>';

        return $html;
    }

    private static function lineControlFormId(int $lineId, string $dimension, string $status): string
    {
        return 'quote-line-control-' . $lineId . '-' . $dimension . '-' . $status;
    }

    /**
     * @param array<string, mixed> $line
     */
    private static function renderSupplierConfirmationHiddenForms(int $quoteId, array $line): void
    {
        $lineId = (int) ($line['id'] ?? 0);
        if ($quoteId <= 0 || $lineId <= 0) {
            return;
        }

        $snapshot = is_array($line['availability_snapshot_json'] ?? null) ? $line['availability_snapshot_json'] : array();
        $bookingMode = trim((string) ($snapshot['bookingMode'] ?? BookingModeService::MODE_SUPPLIER_CONFIRMATION));

        echo '<form id="' . esc_attr(self::supplierRequestFormId($lineId)) . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__hidden-control-form">';
        echo wp_nonce_field('sbdp_quote_line_supplier_request_draft', '_wpnonce', true, false);
        echo '<input type="hidden" name="action" value="sbdp_quote_line_supplier_request_draft">';
        echo '<input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '">';
        echo '<input type="hidden" name="line_id" value="' . esc_attr((string) $lineId) . '">';
        echo '</form>';

        echo '<form id="' . esc_attr(self::supplierStatusFormId($lineId)) . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="bsp-quote-admin__hidden-control-form">';
        echo wp_nonce_field('sbdp_quote_line_supplier_status', '_wpnonce', true, false);
        echo '<input type="hidden" name="action" value="sbdp_quote_line_supplier_status">';
        echo '<input type="hidden" name="quote_id" value="' . esc_attr((string) $quoteId) . '">';
        echo '<input type="hidden" name="line_id" value="' . esc_attr((string) $lineId) . '">';
        echo '<input type="hidden" name="booking_mode" value="' . esc_attr($bookingMode) . '">';
        echo '</form>';
    }

    private static function supplierRequestFormId(int $lineId): string
    {
        return 'quote-line-supplier-request-' . $lineId;
    }

    private static function supplierStatusFormId(int $lineId): string
    {
        return 'quote-line-supplier-status-' . $lineId;
    }

    /**
     * @param array<string, mixed> $line
     */
    private static function quoteLinePricingControlStatus(array $line): string
    {
        $snapshot = is_array($line['pricing_snapshot_json'] ?? null) ? $line['pricing_snapshot_json'] : array();
        $status = (string) ($snapshot['control_status'] ?? '');
        if (in_array($status, array('needs_check', 'confirmed', 'under_reservation'), true)) {
            return $status;
        }

        return (string) ($line['pricing_confidence'] ?? 'unknown') === 'execution_verified'
            ? 'confirmed'
            : (((string) ($line['pricing_confidence'] ?? 'unknown') === 'snapshot') ? 'under_reservation' : 'needs_check');
    }

    /**
     * @param array<string, mixed> $line
     */
    private static function quoteLineAvailabilityControlStatus(array $line): string
    {
        $snapshot = is_array($line['availability_snapshot_json'] ?? null) ? $line['availability_snapshot_json'] : array();
        $status = (string) ($snapshot['control_status'] ?? '');
        if (in_array($status, array('needs_check', 'confirmed', 'under_reservation', 'unavailable'), true)) {
            return $status;
        }

        if ((string) ($line['line_status'] ?? '') === 'unavailable') {
            return 'unavailable';
        }

        return (string) ($line['availability_confidence'] ?? 'unknown') === 'confirmed'
            ? 'confirmed'
            : (in_array((string) ($line['availability_confidence'] ?? 'unknown'), array('snapshot', 'projected'), true) ? 'under_reservation' : 'needs_check');
    }

    private static function quoteLineControlLabel(string $status, string $dimension): string
    {
        if ($dimension === 'pricing') {
            return match ($status) {
                'confirmed' => __('Totaal geldig', 'sbdp'),
                'under_reservation' => __('Prijs onder voorbehoud', 'sbdp'),
                default => __('Prijs nog controleren', 'sbdp'),
            };
        }

        return match ($status) {
            'confirmed' => __('Beschikbaar', 'sbdp'),
            'under_reservation' => __('N.v.t.', 'sbdp'),
            'unavailable' => __('Niet beschikbaar', 'sbdp'),
            default => __('Controleren', 'sbdp'),
        };
    }

    private static function quoteBuilderPricingLabel(string $confidence): string
    {
        return match ($confidence) {
            'execution_verified' => __('Totaal geldig', 'sbdp'),
            'snapshot' => __('Onder voorbehoud', 'sbdp'),
            'projected', 'directional' => __('Prijs nog controleren', 'sbdp'),
            default => __('Prijs nog niet bevestigd', 'sbdp'),
        };
    }

    private static function quoteBuilderAvailabilityLabel(string $confidence): string
    {
        return match ($confidence) {
            'confirmed' => __('Beschikbaar', 'sbdp'),
            'snapshot', 'projected' => __('Controleren', 'sbdp'),
            default => __('Controleren', 'sbdp'),
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
                execution_verified: "Totaal geldig",
                snapshot: "Onder voorbehoud",
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
                const lineSummaryTotal = row.querySelector("[data-builder-line-summary-total]");
                const unitSummary = row.querySelector("[data-builder-unit-summary]");
                if (priceSummary) { priceSummary.textContent = label; }
                if (lineTotalLabel) { lineTotalLabel.textContent = label; }
                if (lineSummaryTotal) { lineSummaryTotal.textContent = total !== null ? formatMoney(currency, total) : "Totaal open"; }
                if (unitSummary) { unitSummary.textContent = unit !== null ? (formatMoney(currency, unit) + " p.p.") : "Prijs open"; }
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
                const titleLabel = row.querySelector("[data-builder-title-label]");
                const timeLabel = row.querySelector("[data-builder-time-label]");
                const participantsLabel = row.querySelector("[data-builder-participants-label]");
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
                if (timeLabel) {
                    timeLabel.textContent = (slotInput && slotInput.value.trim()) || slotText(startInput ? startInput.value : "", endInput ? endInput.value : "") || "Tijd nog open";
                }
                if (participantsLabel) {
                    const participantsInput = row.querySelector("[data-builder-participants]");
                    participantsLabel.textContent = participantsInput && participantsInput.value ? participantsInput.value : "0";
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
                const editToggle = row.querySelector("[data-builder-edit-toggle]");
                if (editToggle) {
                    editToggle.addEventListener("click", () => {
                        row.classList.toggle("is-editing");
                        editToggle.textContent = row.classList.contains("is-editing") ? "Sluit" : "Wijzig";
                    });
                }
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
            .bsp-quote-admin__badge.is-error{background:#f8d7da;color:#8a2424}
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
            .bsp-quote-admin__workspace{display:flex;flex-direction:column;gap:10px;margin-top:10px}
            .bsp-quote-admin__accordion-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:6px;margin:8px 0}
            .bsp-quote-admin__modal-open{margin-top:8px}
            .bsp-quote-admin__modal[hidden]{display:none}
            .bsp-quote-admin__modal{position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;padding:24px;background:rgba(0,0,0,.55)}
            .bsp-quote-admin__modal-panel{width:min(720px,calc(100vw - 32px));max-height:calc(100vh - 48px);overflow:auto;border:1px solid #d0d7de;border-radius:10px;background:#fff;color:#1d2327;box-shadow:0 18px 60px rgba(0,0,0,.28)}
            .bsp-quote-admin__modal-header{display:flex;align-items:center;justify-content:space-between;padding:12px 14px;border-bottom:1px solid #dcdcde}
            .bsp-quote-admin__modal-header h3{margin:0;font-size:16px}
            .bsp-quote-admin__modal-close{font-size:24px;line-height:1;text-decoration:none}
            .bsp-quote-admin__modal-panel > p{margin:12px 14px 0}
            .bsp-quote-admin__modal-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;padding:14px}
            .bsp-quote-admin__modal-grid label{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:700;color:#50575e}
            .bsp-quote-admin__modal-grid input,.bsp-quote-admin__modal-grid textarea{width:100%;font-weight:400;color:#1d2327}
            .bsp-quote-admin__modal-grid input[readonly],.bsp-quote-admin__modal-grid textarea[readonly]{background:#f6f7f7;color:#646970}
            .bsp-quote-admin__modal-span,.bsp-quote-admin__modal-actions{grid-column:1/-1}
            .bsp-quote-admin__modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:4px}
            .bsp-quote-admin__decision-strip{position:sticky;top:32px;z-index:40;display:grid;grid-template-columns:minmax(0,1fr) minmax(170px,auto);gap:10px;align-items:center;margin:0;padding:8px 10px;border:1px solid #2f3a42;border-radius:7px;background:#171f26;color:#f6f7f7;box-shadow:0 2px 8px rgba(0,0,0,.12)}
            .bsp-quote-admin__decision-strip-main{display:grid;grid-template-columns:1.4fr repeat(6,minmax(88px,.75fr));gap:6px;align-items:stretch}
            .bsp-quote-admin__decision-strip-main > div,.bsp-quote-admin__compact-metrics > div{padding:7px 8px;border:1px solid #e2e4e7;border-radius:6px;background:#fbfcfe;min-width:0}
            .bsp-quote-admin__decision-strip span,.bsp-quote-admin__compact-metrics span{display:block;margin-bottom:3px;color:#646970;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
            .bsp-quote-admin__decision-strip strong,.bsp-quote-admin__compact-metrics strong{display:block;color:#1d2327;font-size:13px;line-height:1.25;overflow-wrap:anywhere}
            .bsp-quote-admin__decision-strip .bsp-quote-admin__decision-strip-main > div{background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.1)}
            .bsp-quote-admin__decision-strip .bsp-quote-admin__decision-strip-main span,.bsp-quote-admin__decision-strip-action .bsp-quote-admin__field-label{color:#b8c4ce}
            .bsp-quote-admin__decision-strip .bsp-quote-admin__decision-strip-main strong{color:#fff}
            .bsp-quote-admin__decision-strip-action{display:flex;flex-direction:column;justify-content:center;gap:6px;min-width:170px}
            .bsp-quote-admin__decision-strip-action .button{width:100%;text-align:center}
            .bsp-quote-admin__summary-bar{display:grid;grid-template-columns:minmax(0,1.05fr) minmax(0,1.2fr) minmax(260px,.75fr);gap:10px;align-items:start;margin:0;padding:10px;border:1px solid #d0d7de;border-radius:8px;background:#fff}
            .bsp-quote-admin__summary-bar-section{min-width:0;padding:0 10px;border-right:1px solid #eef0f2}
            .bsp-quote-admin__summary-bar-section:first-child{padding-left:0}
            .bsp-quote-admin__summary-bar-section:last-child{padding-right:0;border-right:0}
            .bsp-quote-admin__summary-bar-section h3{margin:0 0 8px;font-size:12px;line-height:1.2;text-transform:uppercase;letter-spacing:.05em;color:#50575e}
            .bsp-quote-admin__summary-bar-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(118px,1fr));gap:6px}
            .bsp-quote-admin__summary-bar-item{min-width:0;padding:6px 7px;border:1px solid #edf0f2;border-radius:6px;background:#fbfcfe}
            .bsp-quote-admin__summary-bar-item span{display:block;margin-bottom:2px;font-size:10px;font-weight:700;line-height:1.2;text-transform:uppercase;letter-spacing:.04em;color:#646970}
            .bsp-quote-admin__summary-bar-item strong{display:block;font-size:12px;line-height:1.2;color:#1d2327;overflow-wrap:anywhere}
            .bsp-quote-admin__summary-bar-item.is-primary{border-color:#c8b58a;background:#fffaf0}
            .bsp-quote-admin__summary-bar-item.is-primary strong{font-size:15px;color:#2b2116}
            .bsp-quote-admin__summary-bar-note{margin:7px 0 0;color:#646970;font-size:12px;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
            .bsp-quote-admin__summary-status-row,.bsp-quote-admin__summary-action-counts{display:flex;flex-wrap:wrap;gap:5px;align-items:center;margin-top:8px}
            .bsp-quote-admin__summary-next-action{display:block;margin-top:8px;font-size:14px;line-height:1.25;color:#1d2327}
            .bsp-quote-admin__action-center{margin-top:8px}
            .bsp-quote-admin__summary-cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;align-items:start}
            .bsp-quote-admin__summary-card{margin:0}
            .bsp-quote-admin__summary-card .bsp-quote-admin__panel-body{padding:12px}
            .bsp-quote-admin__compact-metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px}
            .bsp-quote-admin__alert-list{margin-top:7px;padding:8px;border:1px solid #d0d7de;border-radius:7px;background:#fbfcfe}
            .bsp-quote-admin__alert-list:first-child{margin-top:0}
            .bsp-quote-admin__alert-list ul{margin:6px 0 0;padding:0;list-style:none;display:flex;flex-direction:column;gap:6px}
            .bsp-quote-admin__alert-list li{display:flex;flex-direction:column;gap:3px;margin:0;padding:0 0 6px;border-bottom:1px solid rgba(0,0,0,.05)}
            .bsp-quote-admin__alert-list li:last-child{padding-bottom:0;border-bottom:0}
            .bsp-quote-admin__alert-list span{font-weight:600;font-size:12px}
            .bsp-quote-admin__alert-list small{color:#646970;line-height:1.3;font-size:11px}
            .bsp-quote-admin__alert-list .button{align-self:flex-start;min-height:24px;padding:1px 7px;font-size:11px;line-height:20px}
            .bsp-quote-admin__alert-more small{font-style:italic}
            .bsp-quote-admin__alert-list.is-blocker{border-left:3px solid #b32d2e;background:#fff8f8}
            .bsp-quote-admin__alert-list.is-warning{border-left:4px solid #dba617;background:#fffaf0}
            .bsp-quote-admin__alert-list.is-info{border-left:4px solid #72aee6}
            .bsp-quote-admin__alert-list.is-partner{border-left:3px solid #2271b1;background:#f6fbff}
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
            .bsp-quote-admin__panel{margin:0; background:#fff; border:1px solid #dcdcde; border-radius:6px;box-shadow:0 1px 2px rgba(0,0,0,.03)}
            .bsp-quote-admin__panel-header{padding:12px 14px 9px;border-bottom:1px solid #f0f0f1}
            .bsp-quote-admin__panel-header h3{margin:0 0 3px;font-size:15px;line-height:1.25}
            .bsp-quote-admin__panel-header p{margin:0}
            .bsp-quote-admin__panel-body{padding:12px 14px}
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
                display: grid;
                grid-template-columns: minmax(130px,.8fr) minmax(160px,1fr) 116px 62px 62px 58px;
                gap: 6px;
                align-items: end;
            }

            .bsp-quote-admin__compact-select {
                max-width: 100%;
                font-size: 13px !important;
                min-height: 28px !important;
            }

            .bsp-quote-admin__compact-input {
                width: 100%;
                font-size: 13px !important;
                min-height: 28px !important;
            }

            .bsp-quote-admin__compact-input-date {
                width: 100%;
                font-size: 12px !important;
                min-height: 28px !important;
            }

            .bsp-quote-admin__compact-input-num {
                width: 58px;
                font-size: 13px !important;
                min-height: 28px !important;
            }

            .bsp-quote-admin__input-with-label {
                display: flex;
                align-items: center;
                gap: 4px;
                background: #11161d;
                padding: 2px 6px;
                border: 1px solid #30363d;
                border-radius: 5px;
                min-height: 28px;
            }

            .bsp-quote-admin__builder-row-main-inputs .bsp-quote-admin__input-with-label {
                align-items: stretch;
                flex-direction: column;
                gap: 2px;
                min-width: 0;
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
                gap: 8px;
                background: transparent;
                margin: 6px 0 0;
                padding: 0;
                border: 0;
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
                gap: 8px;
                flex-shrink: 0;
            }

            .bsp-quote-admin__compact-input-price {
                width: 80px !important;
                font-size: 13px !important;
                min-height: 28px !important;
                border: 1px solid #30363d !important;
                border-radius: 5px !important;
                background: #11161d !important;
                color: #e6edf3 !important;
                font-weight: 600;
            }

            .bsp-quote-admin__line-total-display {
                text-align: right;
                min-width: 120px;
            }

            .bsp-quote-admin__line-total-display strong {
                display: block;
                font-size: 13px;
                color: #e6edf3;
            }

            .bsp-quote-admin__builder-row-footer {
                display: grid;
                grid-template-columns: 110px minmax(250px,.65fr) minmax(360px,1fr);
                justify-content: stretch;
                align-items: start;
                gap: 6px;
                margin-top: 6px;
            }

            .bsp-quote-admin__status-badges {
                display: flex;
                gap: 4px;
                flex-wrap: wrap;
            }

            .bsp-quote-admin__line-control-panel {
                display: flex;
                align-items: center;
                flex-wrap: wrap;
                gap: 8px;
                width: 100%;
                grid-column: 2;
                margin-top: 0;
                padding: 0;
                border: 0;
                border-radius: 0;
                background: transparent;
            }

            .bsp-quote-admin__line-control-group {
                display: flex;
                flex-direction: row;
                align-items: center;
                gap: 7px;
                min-width: 0;
            }

            .bsp-quote-admin__line-control-actions {
                display: flex;
                flex-wrap: nowrap;
                gap: 2px;
                padding: 2px;
                border: 1px solid #30363d;
                border-radius: 999px;
                background: #0f141b;
            }

            .bsp-quote-admin__availability-segment.button {
                min-width: 26px;
                min-height: 24px;
                padding: 0 7px;
                border: 0;
                border-radius: 999px;
                background: transparent;
                color: #8b949e;
                line-height: 22px;
                font-size: 12px;
                font-weight: 700;
                box-shadow: none;
                text-align: center;
            }

            .bsp-quote-admin__availability-segment.button:hover,
            .bsp-quote-admin__availability-segment.button:focus {
                background: #1f2933;
                color: #e6edf3;
            }

            .bsp-quote-admin__availability-segment.is-active,
            .bsp-quote-admin__availability-segment.button:disabled {
                opacity: 1;
                cursor: default;
            }

            .bsp-quote-admin__availability-segment.is-confirmed.is-active,
            .bsp-quote-admin__availability-segment.is-confirmed:disabled {
                background: #123320;
                color: #3fb950;
            }

            .bsp-quote-admin__availability-segment.is-needs_check.is-active,
            .bsp-quote-admin__availability-segment.is-needs_check:disabled {
                background: #3b2f0f;
                color: #e3b341;
            }

            .bsp-quote-admin__availability-segment.is-unavailable.is-active,
            .bsp-quote-admin__availability-segment.is-unavailable:disabled {
                background: #3a1518;
                color: #f85149;
            }

            .bsp-quote-admin__availability-segment.is-under_reservation.is-active,
            .bsp-quote-admin__availability-segment.is-under_reservation:disabled {
                background: #22272e;
                color: #adbac7;
            }

            .bsp-quote-admin__line-control-blocker {
                flex: 1 0 100%;
                order: 3;
                margin: 2px 0 0;
                color: #f85149;
                font-weight: 600;
                font-size: 11px;
                pointer-events: none;
            }

            .bsp-quote-admin__hidden-control-form {
                display: none;
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
                gap: 6px;
                background: transparent;
                padding: 0;
                margin-top: 4px;
                border-radius: 0;
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
            .bsp-quote-admin__builder-intake{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:8px;margin:0 0 10px;padding:10px;border:1px solid #d0d7de;border-radius:8px;background:#f6f8fa}
            .bsp-quote-admin__builder-intake strong{display:block;margin-top:4px}
            .bsp-quote-admin__quote-total-card{position:sticky;top:118px;bottom:auto;right:auto;width:auto;z-index:30;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;padding:10px;border:1px solid #3b342d;border-radius:8px;background:#18120f;color:#fff;box-shadow:0 2px 8px rgba(0,0,0,.12)}
            .bsp-quote-admin__quote-total-card .bsp-quote-admin__field-label,.bsp-quote-admin__quote-total-card .bsp-quote-admin__muted{color:#d7ccc2}
            .bsp-quote-admin__quote-total-card .bsp-quote-admin__field-label{white-space:normal;line-height:1.1}
            .bsp-quote-admin__quote-total-card > div{min-width:0}
            .bsp-quote-admin__quote-total-card strong{display:block;margin-top:3px;font-size:13px;line-height:1.2;overflow-wrap:anywhere}
            .bsp-quote-admin__quote-total-card label{display:flex;flex-direction:column;gap:6px}
            .bsp-quote-admin__quote-total-card input{max-width:100%;background:#fff;color:#1d2327}
            .bsp-quote-admin__quote-discount-row{display:grid;grid-template-columns:64px minmax(70px,1fr) minmax(100px,1fr);gap:6px;align-items:end;grid-column:span 2}
            .bsp-quote-admin__quote-discount-row .bsp-quote-admin__field-label{align-self:center}
            .bsp-quote-admin__quote-discount-summary{font-size:12px;color:#d7ccc2;align-self:end}
            .bsp-quote-admin__quote-total-card-total{border-top:0;padding-top:0}
            .bsp-quote-admin__quote-total-card-action{display:flex;align-items:end}
            .bsp-quote-admin__quote-total-card-action .button{width:100%;text-align:center}
            .bsp-quote-admin__builder-form{display:flex;flex-direction:column;gap:10px}
            .bsp-quote-admin__builder-list{display:flex;flex-direction:column;gap:8px}
            .bsp-quote-admin__builder-row{position:relative;display:flex;flex-direction:column;gap:5px;padding:8px;border:1px solid #24292f;border-radius:8px;background:#0f1115;color:#d8dee9;box-shadow:none}
            .bsp-quote-admin__builder-row.has-blocker{border-left:3px solid #f85149;background:#171014}
            .bsp-quote-admin__builder-row--compact{padding:7px 9px;border-radius:7px}
            .bsp-quote-admin__builder-compact-summary{display:grid;grid-template-columns:22px minmax(220px,1fr) 74px;gap:8px;align-items:center;min-height:36px}
            .bsp-quote-admin__builder-compact-summary strong{display:block;font-size:12px;line-height:1.15;color:#e6edf3}
            .bsp-quote-admin__builder-compact-summary .bsp-quote-admin__tiny-label{font-size:9px;line-height:1.05;margin-bottom:1px}
            .bsp-quote-admin__builder-compact-summary .bsp-quote-admin__badge{padding:2px 6px;font-size:10px;line-height:1.15;white-space:nowrap;margin:0 3px 2px 0}
            .bsp-quote-admin__builder-row-headline{min-width:0}
            .bsp-quote-admin__builder-row-headline small{display:block;margin-top:1px;color:#8b949e;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
            .bsp-quote-admin__builder-row-drag{display:flex;align-items:center;justify-content:center}
            .bsp-quote-admin__builder-row-actions{display:flex;align-items:center;justify-content:flex-end;gap:6px}
            .bsp-quote-admin__builder-edit-panel{position:static;display:block;width:100%;min-width:0}
            .bsp-quote-admin__builder-edit-panel > summary{display:none}
            .bsp-quote-admin__builder-edit-panel > summary::-webkit-details-marker{display:none}
            .bsp-quote-admin__builder-edit-fields{grid-column:1/-1;margin-top:0;padding:7px;border:1px solid #24292f;border-radius:7px;background:#0b0d10}
            .bsp-quote-admin__supplier-panel{position:static;grid-column:3;width:100%;box-sizing:border-box;margin-top:0;padding:6px 7px;border:1px solid #24292f;border-radius:7px;background:#101318}
            .bsp-quote-admin__builder-advanced-compact{grid-column:1/-1;width:100%}
            .bsp-quote-admin__builder-row.is-dragging{opacity:.55}
            .bsp-quote-admin__builder-row-top{display:flex;justify-content:space-between;gap:12px;align-items:center}
            .bsp-quote-admin__builder-row-order{display:flex;align-items:center;gap:10px}
            .bsp-quote-admin__builder-row-order > div{display:flex;flex-direction:column;gap:2px}
            .bsp-quote-admin__builder-row-order span{color:#50575e}
            .bsp-quote-admin__builder-handle{cursor:grab;text-decoration:none;font-size:18px;line-height:1}
            .bsp-quote-admin__builder-card-status{display:flex;flex-wrap:wrap;gap:3px}
            .bsp-quote-admin__compact-field{display:flex;flex-direction:column;gap:2px;min-width:0}
            .bsp-quote-admin__compact-field span{font-size:9px;color:#8b949e;text-transform:uppercase;letter-spacing:.04em;font-weight:700}
            .bsp-quote-admin__compact-field--product{min-width:150px}
            .bsp-quote-admin__compact-field--title{min-width:200px}
            .bsp-quote-admin__builder-row input,
            .bsp-quote-admin__builder-row select,
            .bsp-quote-admin__builder-row textarea {
                border-color:#30363d;
                background:#11161d;
                color:#e6edf3;
                box-shadow:none;
            }
            .bsp-quote-admin__builder-row input:focus,
            .bsp-quote-admin__builder-row select:focus,
            .bsp-quote-admin__builder-row textarea:focus {
                border-color:#58a6ff;
                box-shadow:0 0 0 1px #58a6ff;
            }
            .bsp-quote-admin__builder-row input::placeholder,
            .bsp-quote-admin__builder-row textarea::placeholder { color:#6e7681; }
            .bsp-quote-admin__supplier-panel-header{display:flex;align-items:center;gap:6px;font-size:11px;color:#e6edf3}
            .bsp-quote-admin__supplier-info-row{margin:3px 0;font-size:10px;line-height:1.25}
            .bsp-quote-admin__supplier-mini-row{display:grid;grid-template-columns:86px minmax(104px,.8fr) minmax(112px,1fr) 56px;align-items:end;gap:5px;margin-top:4px}
            .bsp-quote-admin__supplier-panel-form{display:contents;margin-top:0}
            .bsp-quote-admin__supplier-panel-form--action{display:contents}
            .bsp-quote-admin__supplier-panel-form label{display:flex;flex-direction:column;gap:2px;font-size:10px;color:#8b949e;text-transform:uppercase;letter-spacing:.04em;font-weight:700}
            .bsp-quote-admin__supplier-panel-form input,
            .bsp-quote-admin__supplier-panel-form select{width:100%;min-height:26px;font-size:11px;max-width:100%}
            .bsp-quote-admin__supplier-panel-form label:nth-of-type(4){display:none}
            .bsp-quote-admin__supplier-panel-form button{min-height:26px;font-size:10px;line-height:1.1;padding:0 6px;white-space:normal}
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
            .bsp-quote-admin__slot-pill{border:1px solid #8c8f94;border-radius:999px;background:#fff;color:#1d2327;padding:5px 10px;cursor:pointer;font-size:12px}
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
                .bsp-quote-admin__builder-compact-summary{grid-template-columns:28px minmax(160px,1fr) 74px}
                .bsp-quote-admin__builder-row-main-inputs{grid-template-columns:minmax(180px,1fr) minmax(220px,1fr) repeat(4,minmax(72px,.55fr))}
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
                .bsp-quote-admin__builder-row-main-inputs{grid-template-columns:1fr 1fr}
                .bsp-quote-admin__builder-row-interaction{flex-direction:column;align-items:stretch}
                .bsp-quote-admin__builder-row-footer{grid-template-columns:1fr}
                .bsp-quote-admin__line-control-panel,
                .bsp-quote-admin__supplier-panel{grid-column:1}
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

            /* ── Dark-theme override for complete Quote OS ── */
            .bsp-quote-admin__command-shell {
                background: #0e1117;
                border-color: #21262d;
            }
            .bsp-quote-admin__command-main {
                background: #161b22;
                color: #e6edf3;
            }
            /* Override WordPress .wrap margin/bg bleed */
            .bsp-quote-admin__command-wrap {
                background: transparent;
            }
            /* Workspace heading & meta bar */
            .bsp-quote-admin__command-main .bsp-quote-admin__workspace-heading h2,
            .bsp-quote-admin__command-main .bsp-quote-admin__workspace-meta strong,
            .bsp-quote-admin__command-main h1, .bsp-quote-admin__command-main h2,
            .bsp-quote-admin__command-main h3, .bsp-quote-admin__command-main h4 {
                color: #e6edf3;
            }
            .bsp-quote-admin__command-main p,
            .bsp-quote-admin__command-main label,
            .bsp-quote-admin__command-main small,
            .bsp-quote-admin__command-main td,
            .bsp-quote-admin__command-main th,
            .bsp-quote-admin__command-main li,
            .bsp-quote-admin__command-main span:not(.bsp-quote-admin__sidebar-item-name):not(.bsp-quote-admin__sidebar-item-meta):not(.dashicons) {
                color: #adbac7;
            }
            /* Nav tabs on dark */
            .bsp-quote-admin__command-main .nav-tab-wrapper,
            .bsp-quote-admin__command-main .bsp-quote-admin__workspace-tabs {
                border-bottom-color: #30363d;
                background: transparent;
            }
            .bsp-quote-admin__command-main .nav-tab {
                background: #21262d;
                border-color: #30363d;
                color: #adbac7;
            }
            .bsp-quote-admin__command-main .nav-tab:hover {
                background: #2d333b;
                color: #e6edf3;
            }
            .bsp-quote-admin__command-main .nav-tab-active,
            .bsp-quote-admin__command-main .nav-tab-active:focus,
            .bsp-quote-admin__command-main .nav-tab-active:hover {
                background: #161b22;
                border-bottom-color: #161b22;
                color: #e6edf3;
                border-color: #30363d;
            }
            /* Panels / cards */
            .bsp-quote-admin__command-main .bsp-quote-admin__panel,
            .bsp-quote-admin__command-main .postbox {
                background: #21262d;
                border-color: #30363d;
                box-shadow: none;
            }
            .bsp-quote-admin__command-main .bsp-quote-admin__panel-header {
                border-bottom-color: #30363d;
            }
            .bsp-quote-admin__command-main .bsp-quote-admin__panel-header h3 { color: #e6edf3; }
            .bsp-quote-admin__command-main .bsp-quote-admin__panel-header p { color: #7d8590; }
            .bsp-quote-admin__command-main .bsp-quote-admin__panel-body { background: #1c2128; }
            /* Summary bar & items */
            .bsp-quote-admin__command-main .bsp-quote-admin__summary-bar {
                background: #21262d;
                border-color: #30363d;
            }
            .bsp-quote-admin__command-main .bsp-quote-admin__summary-bar-section {
                border-right-color: #30363d;
            }
            .bsp-quote-admin__command-main .bsp-quote-admin__summary-bar-section h3 { color: #7d8590; }
            .bsp-quote-admin__command-main .bsp-quote-admin__summary-bar-item {
                background: #2d333b;
                border-color: #30363d;
            }
            .bsp-quote-admin__command-main .bsp-quote-admin__summary-bar-item span { color: #7d8590; }
            .bsp-quote-admin__command-main .bsp-quote-admin__summary-bar-item strong { color: #e6edf3; }
            .bsp-quote-admin__command-main .bsp-quote-admin__summary-bar-item.is-primary {
                background: #2a1f0e;
                border-color: #5a3e20;
            }
            .bsp-quote-admin__command-main .bsp-quote-admin__summary-bar-item.is-primary strong { color: #e3b341; }
            /* Workspace hero / compact header */
            .bsp-quote-admin__command-main .bsp-quote-admin__workspace-hero--compact {
                background: #21262d;
                border-color: #30363d;
            }
            .bsp-quote-admin__command-main .bsp-quote-admin__compact-quote-header > div {
                background: #2d333b;
                border-color: #30363d;
            }
            .bsp-quote-admin__command-main .bsp-quote-admin__compact-quote-header span { color: #7d8590; }
            .bsp-quote-admin__command-main .bsp-quote-admin__compact-quote-header strong { color: #e6edf3; }
            .bsp-quote-admin__command-main .bsp-quote-admin__compact-next-action {
                background: #1a2538;
                border-color: #2d5a9e;
            }
            /* Alert lists */
            .bsp-quote-admin__command-main .bsp-quote-admin__alert-list {
                background: #21262d;
                border-color: #30363d;
            }
            .bsp-quote-admin__command-main .bsp-quote-admin__alert-list.is-blocker { background: #2d1117; border-left-color: #f85149; }
            .bsp-quote-admin__command-main .bsp-quote-admin__alert-list.is-warning { background: #2b1d00; border-left-color: #e3b341; }
            .bsp-quote-admin__command-main .bsp-quote-admin__alert-list.is-info { border-left-color: #58a6ff; background: #1a2538; }
            .bsp-quote-admin__command-main .bsp-quote-admin__alert-list.is-partner { background: #1a2538; border-left-color: #58a6ff; }
            .bsp-quote-admin__command-main .bsp-quote-admin__alert-list li { border-bottom-color: rgba(255,255,255,0.06); }
            .bsp-quote-admin__command-main .bsp-quote-admin__alert-list span { color: #e6edf3; }
            .bsp-quote-admin__command-main .bsp-quote-admin__alert-list small { color: #7d8590; }
            /* Decision strip metrics */
            .bsp-quote-admin__command-main .bsp-quote-admin__compact-metrics > div {
                background: #2d333b;
                border-color: #30363d;
            }
            /* Stats */
            .bsp-quote-admin__command-main .bsp-quote-admin__stat {
                background: #21262d;
                border-color: #30363d;
            }
            .bsp-quote-admin__command-main .bsp-quote-admin__stat-value { color: #e6edf3; }
            .bsp-quote-admin__command-main .bsp-quote-admin__stat-label { color: #7d8590; }
            /* Send check items */
            .bsp-quote-admin__command-main .bsp-quote-admin__send-check-item {
                background: #21262d;
                border-color: #30363d;
            }
            .bsp-quote-admin__command-main .bsp-quote-admin__send-check-item strong { color: #e6edf3; }
            .bsp-quote-admin__command-main .bsp-quote-admin__send-check-item small { color: #7d8590; }
            /* Readiness summary */
            .bsp-quote-admin__command-main .bsp-quote-admin__readiness-summary {
                background: #21262d;
                border-color: #30363d;
            }
            .bsp-quote-admin__command-main .bsp-quote-admin__readiness-summary--operator { background: #21262d; }
            .bsp-quote-admin__command-main .bsp-quote-admin__readiness-summary--action { background: #2b1d00; border-color: #5a3e00; }
            .bsp-quote-admin__command-main .bsp-quote-admin__readiness-summary strong { color: #e6edf3; }
            .bsp-quote-admin__command-main .bsp-quote-admin__readiness-summary p { color: #7d8590; }
            /* Focus / blocker cards */
            .bsp-quote-admin__command-main .bsp-quote-admin__focus-card { background: #21262d; border-left-color: #30363d; }
            .bsp-quote-admin__command-main .bsp-quote-admin__focus-card--blocked { background: #2d1117; border-left-color: #f85149; }
            .bsp-quote-admin__command-main .bsp-quote-admin__focus-card--assumptions { background: #2b1d00; border-left-color: #e3b341; }
            .bsp-quote-admin__command-main .bsp-quote-admin__focus-card--ready { background: #122320; border-left-color: #3fb950; }
            .bsp-quote-admin__command-main .bsp-quote-admin__focus-card h2 { color: #e6edf3; }
            .bsp-quote-admin__command-main .bsp-quote-admin__focus-kicker { color: #7d8590; }
            .bsp-quote-admin__command-main .bsp-quote-admin__focus-message { color: #adbac7; }
            .bsp-quote-admin__command-main .bsp-quote-admin__focus-steps {
                background: #2d333b;
                border-color: #30363d;
            }
            .bsp-quote-admin__command-main .bsp-quote-admin__focus-details { color: #7d8590; }
            /* Assumption cards */
            .bsp-quote-admin__command-main .bsp-quote-admin__assumption-card {
                background: #2b1d00;
                border-color: #5a3e00;
            }
            .bsp-quote-admin__command-main .bsp-quote-admin__assumption-card strong { color: #e6edf3; }
            .bsp-quote-admin__command-main .bsp-quote-admin__assumption-card p { color: #adbac7; }
            /* Decision action */
            .bsp-quote-admin__command-main .bsp-quote-admin__decision-action {
                background: #21262d;
                border-color: #30363d;
            }
            .bsp-quote-admin__command-main .bsp-quote-admin__decision-action strong { color: #e6edf3; }
            .bsp-quote-admin__command-main .bsp-quote-admin__decision-action p { color: #7d8590; }
            /* Overview stats */
            .bsp-quote-admin__command-main .bsp-quote-admin__overview-stat {
                background: #21262d;
                border-color: #30363d;
                color: #adbac7;
            }
            .bsp-quote-admin__command-main .bsp-quote-admin__overview-stat span { color: #7d8590; }
            .bsp-quote-admin__command-main .bsp-quote-admin__overview-stat strong { color: #e6edf3; }
            .bsp-quote-admin__command-main .bsp-quote-admin__overview-stat:hover,
            .bsp-quote-admin__command-main .bsp-quote-admin__overview-stat.is-active { border-color: #58a6ff; }
            .bsp-quote-admin__command-main .bsp-quote-admin__overview-hero { background: #21262d; }
            .bsp-quote-admin__command-main .bsp-quote-admin__overview-hero h2 { color: #e6edf3; }
            .bsp-quote-admin__command-main .bsp-quote-admin__overview-next p { color: #adbac7; }
            .bsp-quote-admin__command-main .bsp-quote-admin__overview-next strong { color: #e6edf3; }
            /* Communication: reply panel, composer, timeline */
            .bsp-quote-admin__command-main .bsp-quote-admin__customer-reply-panel {
                background: #1a2538;
                border-left-color: #58a6ff;
            }
            .bsp-quote-admin__command-main .bsp-quote-admin__customer-reply-panel h3 { color: #e6edf3; }
            .bsp-quote-admin__command-main .bsp-quote-admin__composer-card,
            .bsp-quote-admin__command-main .bsp-quote-admin__proposal-status-card,
            .bsp-quote-admin__command-main .bsp-quote-admin__timeline-card,
            .bsp-quote-admin__command-main .bsp-quote-admin__advanced-panel {
                background: #21262d;
                border-color: #30363d;
            }
            .bsp-quote-admin__command-main .bsp-quote-admin__customer-reply-excerpt {
                background: #2d333b;
                border-color: #30363d;
                color: #adbac7;
            }
            .bsp-quote-admin__command-main .bsp-quote-admin__compact-timeline { gap: 6px; }
            .bsp-quote-admin__command-main .bsp-quote-admin__timeline-row {
                background: #21262d;
                border-color: #30363d;
            }
            .bsp-quote-admin__command-main .bsp-quote-admin__timeline-row summary strong { color: #e6edf3; }
            .bsp-quote-admin__command-main .bsp-quote-admin__timeline-row summary small { color: #7d8590; }
            .bsp-quote-admin__command-main .bsp-quote-admin__thread-item {
                background: #21262d;
                border-color: #30363d;
            }
            .bsp-quote-admin__command-main .bsp-quote-admin__thread-item.is-inbound { background: #2b1d00; }
            /* Builder rows */
            .bsp-quote-admin__command-main .bsp-quote-admin__builder-row {
                background: #21262d;
                border-color: #30363d;
            }
            .bsp-quote-admin__command-main .bsp-quote-admin__builder-row:hover { box-shadow: 0 2px 8px rgba(0,0,0,.4); }
            .bsp-quote-admin__command-main .bsp-quote-admin__builder-row.has-blocker { background: #2d1117; border-left-color: #f85149; }
            .bsp-quote-admin__command-main .bsp-quote-admin__builder-row input:not([type="hidden"]),
            .bsp-quote-admin__command-main .bsp-quote-admin__builder-row select,
            .bsp-quote-admin__command-main .bsp-quote-admin__builder-row textarea {
                background: #0f141b !important;
                border: 1px solid #30363d !important;
                box-shadow: none !important;
                color: #e6edf3 !important;
            }
            .bsp-quote-admin__command-main .bsp-quote-admin__builder-row input[type="date"]::-webkit-calendar-picker-indicator,
            .bsp-quote-admin__command-main .bsp-quote-admin__builder-row input[type="time"]::-webkit-calendar-picker-indicator {
                filter: invert(1);
                opacity: .65;
            }
            .bsp-quote-admin__command-main .bsp-quote-admin__builder-row-interaction {
                background: #1c2128;
                border-top-color: #30363d;
                border-bottom-color: #30363d;
            }
            .bsp-quote-admin__command-main .bsp-quote-admin__line-control-panel {
                background: #21262d;
                border-color: #30363d;
            }
            .bsp-quote-admin__command-main .bsp-quote-admin__builder-intake {
                background: #1c2128;
                border-color: #30363d;
            }
            .bsp-quote-admin__command-main .bsp-quote-admin__advanced-grid { background: #2d333b; }
            /* Checklist & timeline items */
            .bsp-quote-admin__command-main .bsp-quote-admin__checklist li,
            .bsp-quote-admin__command-main .bsp-quote-admin__timeline li {
                background: #21262d;
                border-color: #30363d;
            }
            .bsp-quote-admin__command-main .bsp-quote-admin__checklist--compact li { background: #1c2128; }
            /* Program items */
            .bsp-quote-admin__command-main .bsp-quote-admin__program-item {
                background: #21262d;
                border-color: #30363d;
            }
            .bsp-quote-admin__command-main .bsp-quote-admin__program-time { border-right-color: #30363d; }
            .bsp-quote-admin__command-main .bsp-quote-admin__program-time strong { color: #e6edf3; }
            .bsp-quote-admin__command-main .bsp-quote-admin__program-body h4 { color: #e6edf3; }
            /* Slot pills */
            .bsp-quote-admin__command-main .bsp-quote-admin__slot-pill {
                background: #2d333b;
                border-color: #30363d;
                color: #adbac7;
            }
            .bsp-quote-admin__command-main .bsp-quote-admin__slot-pill:hover { border-color: #58a6ff; color: #58a6ff; }
            .bsp-quote-admin__command-main .bsp-quote-admin__slot-pill.is-selected { background: #1f6feb; border-color: #1f6feb; color: #fff; }
            /* Debug JSON */
            .bsp-quote-admin__command-main .bsp-quote-admin__debug-json {
                background: #0d1117;
                border-color: #21262d;
                color: #adbac7;
            }
            /* Badges on dark background */
            .bsp-quote-admin__command-main .bsp-quote-admin__badge { background: #2d333b; color: #adbac7; }
            .bsp-quote-admin__command-main .bsp-quote-admin__badge.is-good { background: #122320; color: #3fb950; }
            .bsp-quote-admin__command-main .bsp-quote-admin__badge.is-warn { background: #2b1d00; color: #e3b341; }
            .bsp-quote-admin__command-main .bsp-quote-admin__badge.is-neutral { background: #2d333b; color: #7d8590; }
            .bsp-quote-admin__command-main .bsp-quote-admin__badge.is-error { background: #2d1117; color: #f85149; }
            .bsp-quote-admin__command-main .bsp-badge { background: #2d333b; color: #adbac7; }
            .bsp-quote-admin__command-main .bsp-badge.is-neutral { background: #2d333b; color: #7d8590; }
            .bsp-quote-admin__command-main .bsp-badge.is-warn { background: #2b1d00; color: #e3b341; }
            .bsp-quote-admin__command-main .bsp-badge.is-success { background: #122320; color: #3fb950; }
            .bsp-quote-admin__command-main .bsp-badge.is-error { background: #2d1117; color: #f85149; }
            /* Form inputs */
            .bsp-quote-admin__command-main input[type="text"],
            .bsp-quote-admin__command-main input[type="number"],
            .bsp-quote-admin__command-main input[type="date"],
            .bsp-quote-admin__command-main input[type="email"],
            .bsp-quote-admin__command-main input[type="password"],
            .bsp-quote-admin__command-main textarea,
            .bsp-quote-admin__command-main select {
                background: #0d1117;
                border-color: #30363d;
                color: #e6edf3;
            }
            .bsp-quote-admin__command-main input[type="text"]:focus,
            .bsp-quote-admin__command-main input[type="number"]:focus,
            .bsp-quote-admin__command-main textarea:focus,
            .bsp-quote-admin__command-main select:focus {
                border-color: #58a6ff;
                box-shadow: 0 0 0 1px #58a6ff;
                outline: none;
            }
            .bsp-quote-admin__command-main .bsp-quote-admin__input-with-label {
                background: #0d1117;
                border-color: #30363d;
            }
            /* Compact price input */
            .bsp-quote-admin__command-main .bsp-quote-admin__compact-input-price {
                background: transparent !important;
                color: #e6edf3 !important;
            }
            /* Stack form muted divider */
            .bsp-quote-admin__command-main .bsp-quote-admin__stack-form--muted { border-top-color: #30363d; }
            /* Section heading */
            .bsp-quote-admin__command-main .bsp-quote-admin__section-heading h4 { color: #e6edf3; }
            .bsp-quote-admin__command-main .bsp-quote-admin__section-heading span { color: #7d8590; }
            /* Muted text */
            .bsp-quote-admin__command-main .bsp-quote-admin__muted { color: #7d8590 !important; }
            .bsp-quote-admin__command-main .bsp-quote-admin__field-label { color: #7d8590; }
            /* Links */
            .bsp-quote-admin__command-main a { color: #58a6ff; }
            .bsp-quote-admin__command-main a:hover { color: #79b8ff; }
            /* WP notice inside workspace */
            .bsp-quote-admin__command-main .notice,
            .bsp-quote-admin__command-main .bsp-quote-admin__workspace-notice {
                background: #21262d;
                border-left-color: #58a6ff;
                color: #adbac7;
            }
            .bsp-quote-admin__command-main .notice-warning { background: #2b1d00; border-left-color: #e3b341; }
            .bsp-quote-admin__command-main .notice-error { background: #2d1117; border-left-color: #f85149; }
            .bsp-quote-admin__command-main .notice-success { background: #122320; border-left-color: #3fb950; }
            /* Workspace meta/heading section below tabs */
            .bsp-quote-admin__command-main .bsp-quote-admin__workspace-meta {
                background: #21262d;
                border-color: #30363d;
            }
            /* WP table styling inside workspace */
            .bsp-quote-admin__command-main table { border-color: #30363d; }
            .bsp-quote-admin__command-main td, .bsp-quote-admin__command-main th {
                border-color: #30363d;
                color: #adbac7;
            }
            .bsp-quote-admin__command-main .widefat thead th,
            .bsp-quote-admin__command-main .widefat tfoot th { background: #21262d; color: #7d8590; }
            .bsp-quote-admin__command-main .widefat tbody tr { background: #1c2128; }
            .bsp-quote-admin__command-main .widefat tbody tr:hover { background: #21262d; }
            .bsp-quote-admin__command-main .widefat tbody .alternate { background: #21262d; }
            /* Splash screen */
            .bsp-quote-admin__command-splash { color: #7d8590; }
            .bsp-quote-admin__command-splash h2 { color: #e6edf3; }

            /* ══════════════════════════════════════════════
               Quote Control Dashboard (QCD) — scopes 1-8
               ══════════════════════════════════════════════ */
            .bsp-qcd { display: flex; flex-direction: column; gap: 0; }

            /* ── Decision Bar — 7 labeled columns ── */
            .bsp-qcd__decision-bar {
                display: grid;
                grid-template-columns: 2fr 1fr 1fr 1.2fr 1.5fr 1.3fr 2fr;
                background: #111;
                border: 1px solid #2a2a2a;
                border-radius: 6px;
                margin-bottom: 8px;
                overflow: hidden;
            }
            .bsp-qcd__db-col {
                display: flex;
                flex-direction: column;
                gap: 3px;
                padding: 9px 11px;
                border-right: 1px solid #2a2a2a;
                min-width: 0;
            }
            .bsp-qcd__db-col:last-child { border-right: none; }
            .bsp-qcd__db-col > span  { font-size: 10px; color: #7d8590; text-transform: uppercase; letter-spacing: .04em; }
            .bsp-qcd__db-col > strong { font-size: 12px; color: #e6edf3; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .bsp-qcd__db-col > small  { font-size: 10px; color: #7d8590; }
            .bsp-qcd__db-col--action { background: #0d1117; }
            .bsp-qcd__db-col--action > strong { color: #adbac7; white-space: normal; }
            .bsp-qcd__final-status {
                font-weight: 700;
                font-size: 11px;
                padding: 2px 8px;
                border-radius: 99px;
                display: inline-block;
                align-self: flex-start;
            }
            .bsp-qcd__status--ok    { background: #122320; color: #3fb950; border: 1px solid #2a4a3a; }
            .bsp-qcd__status--warn  { background: #2b1d00; color: #e3b341; border: 1px solid #4a3810; }
            .bsp-qcd__status--error { background: #2d1117; color: #f85149; border: 1px solid #4a1a1a; }
            .bsp-qcd__primary-btn   { font-weight: 600; margin-top: 4px; align-self: flex-start; }

            /* ── Context Grid: KLANT | PRIJS & PROGRAMMA | NOG NODIG ── */
            .bsp-qcd__context-grid {
                display: grid;
                grid-template-columns: 1fr 1fr 1fr;
                gap: 8px;
                margin-bottom: 8px;
            }
            .bsp-qcd__context-col {
                background: #0a0a0a;
                border: 1px solid #2a2a2a;
                border-radius: 7px;
                padding: 10px 12px;
                display: flex;
                flex-direction: column;
                gap: 6px;
            }
            .bsp-qcd__context-heading {
                margin: 0 0 2px;
                font-size: 10px;
                font-weight: 700;
                color: #7d8590;
                text-transform: uppercase;
                letter-spacing: .05em;
            }
            .bsp-qcd__cf-list {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 0;
            }
            .bsp-qcd__cf--primary strong { color: #e3b341 !important; font-size: 13px !important; }
            .bsp-qcd__nodig-counts { display: flex; flex-wrap: wrap; gap: 4px; }
            .bsp-qcd__nodig-badge { font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 99px; }
            .bsp-qcd__nodig-badge--error { background: #2d1117; color: #f85149; }
            .bsp-qcd__nodig-badge--warn  { background: #2b1d00; color: #e3b341; }
            .bsp-qcd__nodig-badge--ok    { background: #122320; color: #3fb950; }
            .bsp-qcd__nodig-must   { margin: 4px 0 2px; font-size: 11px; color: #adbac7; font-weight: 600; }
            .bsp-qcd__nodig-list   { margin: 0 0 4px; padding: 0; list-style: none; }
            .bsp-qcd__nodig-item   { display: flex; align-items: flex-start; gap: 5px; padding: 3px 0; font-size: 11px; color: #adbac7; border-bottom: 1px solid #111; }
            .bsp-qcd__nodig-item a { color: #d4a574; text-decoration: none; }
            .bsp-qcd__nodig-item a:hover { text-decoration: underline; }
            .bsp-qcd__nodig-icon   { font-size: 11px; color: #f85149; font-weight: 700; flex-shrink: 0; padding-top: 1px; }
            .bsp-qcd__nodig-partner { margin: 3px 0; font-size: 11px; color: #adbac7; }
            .bsp-qcd__nodig-ok     { margin: 4px 0 0; font-size: 11px; color: #3fb950; }
            .bsp-qcd__nodig-cta    { display: inline-block; margin-top: 6px; }

            /* ── Accordion Bottom Rows ── */
            .bsp-qcd__bottom-section {
                display: flex;
                flex-direction: column;
                gap: 4px;
                margin-top: 8px;
            }
            .bsp-qcd__bottom-row {
                border: 1px solid #2a2a2a;
                background: #080808;
                border-radius: 7px;
                overflow: hidden;
            }
            .bsp-qcd__bottom-row-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 10px 14px;
                cursor: pointer;
                list-style: none;
                background: #0d1117;
                user-select: none;
            }
            .bsp-qcd__bottom-row-header::-webkit-details-marker { display: none; }
            .bsp-qcd__bottom-row-header::marker { display: none; }
            .bsp-qcd__bottom-row-title { font-size: 13px; font-weight: 600; color: #e6edf3; }
            .bsp-qcd__bottom-row-meta  { display: flex; align-items: center; gap: 8px; font-size: 11px; color: #7d8590; }
            .bsp-qcd__bottom-row-body  { border-top: 1px solid #151515; }

            /* ── Approval Matrix ── */
            .bsp-qcd__matrix-section { margin-bottom: 8px; box-shadow: none; border: 1px solid #2a2a2a; background: #0a0a0a; }
            .bsp-qcd__matrix-header { padding: 10px 16px 6px; border-bottom: 1px solid #1a1a1a; }
            .bsp-qcd__matrix-header h3 { margin: 0; font-size: 13px; color: #adbac7; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
            .bsp-qcd__matrix-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
                gap: 0;
                padding: 0;
            }
            .bsp-qcd__matrix-item {
                display: flex;
                align-items: flex-start;
                gap: 12px;
                padding: 12px 16px;
                border-right: 1px solid #1a1a1a;
                border-bottom: 1px solid #1a1a1a;
            }
            .bsp-qcd__matrix-item:last-child { border-right: none; }
            .bsp-qcd__matrix-icon {
                font-size: 16px;
                font-weight: 700;
                width: 24px;
                height: 24px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
                margin-top: 1px;
            }
            .bsp-qcd__matrix-icon.is-good    { background: #122320; color: #3fb950; }
            .bsp-qcd__matrix-icon.is-warn    { background: #2b1d00; color: #e3b341; }
            .bsp-qcd__matrix-icon.is-error   { background: #2d1117; color: #f85149; }
            .bsp-qcd__matrix-icon.is-neutral { background: #1a1a1a; color: #7d8590; }
            .bsp-qcd__matrix-body { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
            .bsp-qcd__matrix-label  { font-weight: 600; font-size: 12px; color: #e6edf3; }
            .bsp-qcd__matrix-status { font-size: 11px; color: #adbac7; }
            .bsp-qcd__matrix-action { font-size: 11px; color: #d4a574; text-decoration: none; display: inline-block; margin-top: 2px; }
            .bsp-qcd__matrix-action:hover { color: #e6c49a; text-decoration: underline; }

            /* ── Program Timeline ── */
            .bsp-qcd__program-section { margin-bottom: 8px; border: 1px solid #2a2a2a; background: #0a0a0a; box-shadow: none; }
            .bsp-qcd__program-header  { display: flex; align-items: center; justify-content: space-between; padding: 10px 16px; border-bottom: 1px solid #1a1a1a; }
            .bsp-qcd__program-header h3 { margin: 0; font-size: 13px; color: #e6edf3; }
            .bsp-qcd__timeline { display: flex; flex-direction: column; gap: 0; }
            .bsp-qcd__timeline-item {
                display: grid;
                grid-template-columns: 28px 100px 1fr auto;
                align-items: start;
                gap: 12px;
                padding: 10px 16px;
                border-bottom: 1px solid #111;
            }
            .bsp-qcd__timeline-item:last-child { border-bottom: none; }
            .bsp-qcd__timeline-item:hover { background: #0f0f0f; }
            .bsp-qcd__tl-num   { font-size: 11px; color: #7d8590; font-weight: 600; padding-top: 2px; }
            .bsp-qcd__tl-time  { font-size: 12px; color: #adbac7; padding-top: 2px; font-variant-numeric: tabular-nums; }
            .bsp-qcd__tl-body  { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
            .bsp-qcd__tl-title { font-size: 13px; color: #e6edf3; font-weight: 600; }
            .bsp-qcd__tl-detail { font-size: 11px; color: #7d8590; }
            .bsp-qcd__tl-status { display: flex; flex-direction: column; gap: 4px; align-items: flex-end; flex-shrink: 0; }
            .bsp-qcd__tl-badge  { font-size: 10px; padding: 2px 7px; border-radius: 99px; font-weight: 600; white-space: nowrap; }
            .bsp-qcd__tl-badge.is-good    { background: #122320; color: #3fb950; }
            .bsp-qcd__tl-badge.is-warn    { background: #2b1d00; color: #e3b341; }
            .bsp-qcd__tl-badge.is-neutral { background: #1a1a1a; color: #7d8590; }

            /* ── Customer Card ── */
            .bsp-qcd__customer-section { margin-bottom: 8px; border: 1px solid #2a2a2a; background: #0a0a0a; box-shadow: none; }
            .bsp-qcd__customer-header { display: flex; align-items: center; justify-content: space-between; padding: 10px 16px; border-bottom: 1px solid #1a1a1a; }
            .bsp-qcd__customer-header h3 { margin: 0; font-size: 13px; color: #e6edf3; }
            .bsp-qcd__customer-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
                gap: 0;
                padding: 0;
            }
            .bsp-qcd__cf {
                display: flex;
                flex-direction: column;
                gap: 2px;
                padding: 10px 16px;
                border-right: 1px solid #111;
                border-bottom: 1px solid #111;
            }
            .bsp-qcd__cf span     { font-size: 10px; color: #7d8590; text-transform: uppercase; letter-spacing: .04em; }
            .bsp-qcd__cf strong   { font-size: 12px; color: #e6edf3; }
            .bsp-qcd__cf strong a { color: #58a6ff; text-decoration: none; }
            .bsp-qcd__cf strong a:hover { text-decoration: underline; }
            .bsp-qcd__cf--wide    { grid-column: 1 / -1; }

            /* ── Inline lower control cards ── */
            .bsp-qcd__bottom-section { display:grid; grid-template-columns:minmax(0,1.15fr) minmax(280px,.85fr); gap:8px; margin-top:8px; align-items:start; }
            .bsp-qcd__info-card { margin:0; border:1px solid #2a2a2a; background:#080808; box-shadow:none; min-width:0; }
            .bsp-qcd__proposal-card { grid-row:span 2; }
            .bsp-qcd__card-header { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; padding:10px 16px; border-bottom:1px solid #151515; }
            .bsp-qcd__card-header h3 { margin:0; font-size:13px; color:#e6edf3; }
            .bsp-qcd__card-header p { margin:2px 0 0; font-size:11px; color:#7d8590; }
            .bsp-qcd__card-status { flex-shrink:0; border-radius:99px; padding:3px 8px; font-size:10px; font-weight:700; }
            .bsp-qcd__card-status.is-good { background:#122320; color:#3fb950; }
            .bsp-qcd__card-status.is-warn { background:#2b1d00; color:#e3b341; }
            .bsp-qcd__card-status.is-neutral { background:#1a1a1a; color:#7d8590; }
            .bsp-qcd__card-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:0; border-bottom:1px solid #111; }
            .bsp-qcd__card-grid .bsp-quote-admin__summary-bar-item { display:flex; flex-direction:column; gap:2px; padding:9px 12px; border-right:1px solid #111; }
            .bsp-qcd__card-grid .bsp-quote-admin__summary-bar-item span { font-size:10px; color:#4a4a4a; text-transform:uppercase; letter-spacing:.04em; }
            .bsp-qcd__card-grid .bsp-quote-admin__summary-bar-item strong { font-size:12px; color:#e6edf3; }
            .bsp-qcd__card-grid .bsp-quote-admin__summary-bar-item.is-primary strong { color:#e3b341; }
            .bsp-qcd__proposal-copy, .bsp-qcd__message-snippet { padding:10px 16px; border-bottom:1px solid #111; }
            .bsp-qcd__proposal-copy p, .bsp-qcd__message-snippet p { margin:0 0 8px; color:#adbac7; font-size:12px; line-height:1.45; }
            .bsp-qcd__proposal-copy p:last-child, .bsp-qcd__message-snippet p:last-child { margin-bottom:0; }
            .bsp-qcd__readiness-list { margin:0; padding:8px 16px; list-style:none; border-bottom:1px solid #111; }
            .bsp-qcd__readiness-list li { display:grid; grid-template-columns:22px minmax(120px,.45fr) 1fr; gap:8px; align-items:start; padding:5px 0; font-size:12px; color:#adbac7; }
            .bsp-qcd__readiness-icon { width:18px; height:18px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-weight:700; font-size:11px; }
            .bsp-qcd__readiness-icon.is-good { background:#122320; color:#3fb950; }
            .bsp-qcd__readiness-icon.is-warn { background:#2b1d00; color:#e3b341; }
            .bsp-qcd__card-actions { display:flex; gap:8px; flex-wrap:wrap; padding:10px 16px; }
            .bsp-qcd__proposal-editor-inline { padding:12px 16px 14px; border-top:1px solid #151515; background:#0d1117; }
            .bsp-qcd__proposal-editor-head { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:10px; }
            .bsp-qcd__proposal-editor-head h4 { margin:0; color:#e6edf3; font-size:13px; }
            .bsp-qcd__proposal-ai-actions { display:flex; flex-wrap:wrap; gap:6px; justify-content:flex-end; }
            .bsp-qcd__proposal-form { display:flex; flex-direction:column; gap:10px; margin:0; }
            .bsp-qcd__proposal-editor-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
            .bsp-qcd__proposal-editor-grid label { display:flex; flex-direction:column; gap:4px; color:#adbac7; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
            .bsp-qcd__proposal-editor-wide { grid-column:1 / -1; }
            .bsp-qcd__proposal-editor-grid input,
            .bsp-qcd__proposal-editor-grid textarea { width:100%; max-width:100%; background:#0f141b !important; border:1px solid #30363d !important; color:#e6edf3 !important; box-shadow:none !important; font-size:12px; }
            .bsp-qcd__proposal-form-message { margin:0; padding:7px 9px; border-radius:6px; background:#161b22; color:#adbac7; font-size:12px; }
            .bsp-qcd__proposal-form-message.is-success { background:#122320; color:#3fb950; }
            .bsp-qcd__proposal-form-message.is-error { background:#3a1518; color:#f85149; }

            /* ── Audit list ── */
            .bsp-qcd__audit-list { margin: 0 0 12px; padding: 0; list-style: none; }
            .bsp-qcd__audit-item { display: grid; grid-template-columns: 140px auto 1fr; gap: 8px; align-items: baseline; padding: 5px 0; border-bottom: 1px solid #0d0d0d; font-size: 11px; }
            .bsp-qcd__audit-time { color: #4a4a4a; font-variant-numeric: tabular-nums; }
            .bsp-qcd__audit-type { color: #7d8590; font-weight: 600; }
            .bsp-qcd__audit-msg  { color: #4a4a4a; }
            .bsp-qcd__audit-card .bsp-qcd__audit-list { padding:8px 16px 0; margin-bottom:0; }

            /* ── Customer Modal (QCD version) ── */
            .bsp-qcd__modal-panel { max-width: 700px; width: 95vw; }
            .bsp-qcd__modal-form  { padding: 16px 20px; }
            .bsp-qcd__modal-grid  { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 20px; }
            .bsp-qcd__modal-grid label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; color: #adbac7; }
            .bsp-qcd__modal-grid label input, .bsp-qcd__modal-grid label textarea { margin-top: 2px; }
            .bsp-qcd__modal-wide { grid-column: 1 / -1; }

            /* ── Shared empty state ── */
            .bsp-qcd__empty { color: #4a4a4a; font-style: italic; padding: 12px 0; margin: 0; font-size: 12px; }

            /* ── Compact QCD overrides ── */
            .bsp-qcd{display:flex;flex-direction:column;gap:8px}
            .bsp-qcd__layout{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:8px;align-items:start}
            .bsp-qcd__main{display:flex;flex-direction:column;gap:8px;min-width:0}
            .bsp-qcd__side{display:flex;flex-direction:column;gap:8px;position:sticky;top:54px;min-width:0}
            .bsp-qcd__matrix-section,.bsp-qcd__context-col,.bsp-qcd__bottom-row,.bsp-qcd__program-body .bsp-quote-admin__panel{border-radius:7px}
            .bsp-qcd__matrix-header{display:none}
            .bsp-qcd__matrix-grid{grid-template-columns:repeat(6,minmax(120px,1fr))}
            .bsp-qcd__matrix-item{gap:7px;padding:7px 9px}
            .bsp-qcd__matrix-icon{width:19px;height:19px;font-size:12px}
            .bsp-qcd__matrix-label{font-size:11px}
            .bsp-qcd__matrix-status,.bsp-qcd__matrix-action{font-size:10px}
            .bsp-qcd__card-header{padding:8px 10px}
            section.bsp-qcd__bottom-row .bsp-qcd__bottom-row-header{cursor:default;user-select:auto}
            .bsp-quote-admin__quote-total-card{display:grid;grid-template-columns:repeat(5,minmax(120px,1fr));gap:0;margin:0 0 6px;padding:0;border:1px solid #24292f;border-radius:7px;background:#0b0d10}
            .bsp-quote-admin__quote-total-card>div{padding:7px 9px;border-right:1px solid #151515}
            .bsp-quote-admin__quote-total-card input{min-height:24px;font-size:11px}
            .bsp-quote-admin__quote-total-card-action{display:none}
            .bsp-quote-admin__builder-list{gap:5px}
            .bsp-quote-admin__builder-row{padding:0;border-radius:7px;overflow:hidden}
            .bsp-quote-admin__builder-row--compact{padding:0}
            .bsp-quote-admin__builder-compact-summary{grid-template-columns:22px minmax(260px,1fr) 112px 118px;gap:8px;min-height:48px;padding:7px 8px}
            .bsp-quote-admin__builder-row-headline strong{font-size:12px}
            .bsp-quote-admin__builder-row-headline small{font-size:10px}
            .bsp-quote-admin__builder-availability-summary{display:flex;align-items:center;gap:5px;justify-content:flex-end;color:#adbac7;font-size:10px}
            .bsp-quote-admin__builder-availability-summary span{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:#161b22;border:1px solid #30363d;font-weight:700;color:#e3b341}
            .bsp-quote-admin__builder-row.has-blocker .bsp-quote-admin__builder-availability-summary span{background:#2d1117;color:#f85149}
            .bsp-quote-admin__builder-row-actions{gap:5px}
            .bsp-quote-admin__builder-row-actions .button-link{min-width:22px;height:22px}
            .bsp-quote-admin__builder-edit-panel{display:none;border-top:1px solid #151515;background:#0b0d10}
            .bsp-quote-admin__builder-row.is-editing .bsp-quote-admin__builder-edit-panel,
            .bsp-quote-admin__builder-row.has-blocker .bsp-quote-admin__builder-edit-panel{display:block}
            .bsp-quote-admin__builder-edit-fields{padding:7px;border:0;border-radius:0;background:transparent}
            .bsp-quote-admin__builder-row-main-inputs{grid-template-columns:minmax(150px,1fr) minmax(180px,1.2fr) 120px 80px 80px 88px;gap:6px}
            .bsp-quote-admin__builder-row input,.bsp-quote-admin__builder-row select,.bsp-quote-admin__builder-row textarea{min-height:26px;font-size:11px;padding:2px 6px}
            .bsp-quote-admin__builder-row-interaction{gap:6px}
            .bsp-quote-admin__slot-picker-compact{display:none}
            .bsp-quote-admin__commercial-inputs-compact{display:grid;grid-template-columns:110px 1fr;gap:6px;align-items:end}
            .bsp-quote-admin__builder-row-footer{padding-top:6px;border-top:1px solid #151515;display:grid;grid-template-columns:minmax(120px,.6fr) minmax(220px,1fr);gap:6px;align-items:center}
            .bsp-quote-admin__line-control-panel{margin:0}
            .bsp-quote-admin__line-control-group{display:flex;align-items:center;justify-content:flex-end;gap:8px}
            .bsp-quote-admin__line-control-group>div:first-child{display:none}
            .bsp-quote-admin__line-control-actions{display:inline-flex;gap:2px;padding:2px;border:1px solid #30363d;border-radius:999px;background:#0d1117}
            .bsp-quote-admin__availability-segment{min-width:24px;width:24px;height:24px;min-height:24px;padding:0!important;border-radius:50%!important;border:0!important;background:transparent!important;color:#adbac7!important;line-height:22px!important}
            .bsp-quote-admin__availability-segment.is-active{background:#1f6feb!important;color:#fff!important}
            .bsp-quote-admin__availability-segment.is-unavailable.is-active{background:#da3633!important}
            .bsp-quote-admin__line-control-blocker{font-size:11px;margin:4px 0 0;color:#f85149}
            .bsp-quote-admin__supplier-panel{grid-column:1/-1;padding:6px;margin-top:4px}
            .bsp-qcd__proposal-card{grid-row:auto}
            .bsp-qcd__card-grid{grid-template-columns:repeat(4,minmax(110px,1fr))}
            .bsp-qcd__proposal-copy,.bsp-qcd__message-snippet{padding:8px 10px}
            .bsp-qcd__mail-status-rail{display:flex;flex-wrap:wrap;gap:4px;padding:8px 10px 0}
            .bsp-qcd__mail-step{display:inline-flex;align-items:center;gap:4px;border:1px solid #30363d;border-radius:999px;color:#7d8590;background:#0d1117;font-size:10px;font-weight:700;padding:3px 7px}
            .bsp-qcd__mail-step.is-done{border-color:#214d35;background:#122320;color:#3fb950}
            .bsp-qcd__mail-step.is-current{border-color:#6b4d12;background:#2b1d00;color:#e3b341}
            .bsp-qcd__mail-step.is-unknown{border-color:#30363d;background:#161b22;color:#adbac7}
            .bsp-qcd__mail-truth{margin:5px 10px 0;color:#7d8590;font-size:11px;line-height:1.35}
            .bsp-qcd__send-disabled-reason{display:inline-flex;align-items:center;color:#e3b341;font-size:11px;line-height:1.35;max-width:520px}
            .bsp-qcd__readiness-list{padding:6px 10px}
            .bsp-qcd__readiness-list li{grid-template-columns:20px minmax(90px,.45fr) 1fr;padding:4px 0;font-size:11px}
            .bsp-qcd__proposal-editor-inline{padding:8px 10px}
            .bsp-qcd__proposal-editor-head{margin-bottom:6px}
            .bsp-qcd__proposal-ai-actions .button{min-height:24px;padding:0 7px;font-size:11px}
            .bsp-qcd__proposal-form{gap:7px}
            .bsp-qcd__proposal-editor-grid{gap:7px}
            .bsp-qcd__proposal-editor-grid label{font-size:10px}
            .bsp-qcd__proposal-editor-grid textarea{min-height:50px}
            .bsp-qcd__proposal-editor-grid textarea[name=program_text]{min-height:92px}
            .bsp-qcd__audit-item{grid-template-columns:86px 1fr;gap:4px}
            .bsp-qcd__audit-msg{grid-column:1/-1}
            /* Decision bar compact — fit 7 cols on narrower screens */
            .bsp-qcd__decision-bar{grid-template-columns:2fr 1fr 1fr 1.2fr 1.5fr 1.3fr 2fr}
            /* Context grid compact */
            .bsp-qcd__context-grid{gap:6px}
            .bsp-qcd__context-col{padding:8px 10px}
            .bsp-qcd__cf{padding:5px 8px}
            .bsp-qcd__cf span{font-size:9px}
            .bsp-qcd__cf strong{font-size:11px}
            /* Program body (replaces old .bsp-qcd__program-editor) */
            .bsp-qcd__program-body .bsp-quote-admin__panel-header{display:none}
            .bsp-qcd__program-body .bsp-quote-admin__panel-body{padding:8px}
            .bsp-qcd__program-body .bsp-quote-admin__readiness-summary,
            .bsp-qcd__program-body .bsp-quote-admin__builder-intake,
            .bsp-qcd__program-body .bsp-quote-admin__proposal-copy{display:none}
            .bsp-qcd__program-body .bsp-quote-admin__actions--stacked{display:flex;flex-direction:row;gap:6px;margin:0 0 6px;align-items:center}
            .bsp-qcd__program-body .bsp-quote-admin__actions--stacked .button{min-height:28px;padding:1px 8px;font-size:11px}
            @media (max-width: 1180px){.bsp-qcd__layout{grid-template-columns:1fr}.bsp-qcd__side{position:static}.bsp-qcd__context-grid{grid-template-columns:1fr 1fr}.bsp-qcd__context-nodig{grid-column:1/-1}.bsp-qcd__matrix-grid{grid-template-columns:repeat(3,1fr)}}
            @media (max-width: 900px){.bsp-qcd__decision-bar{grid-template-columns:1fr 1fr 1fr;row-gap:0}.bsp-qcd__db-col{border-bottom:1px solid #1a1a1a}}
            @media (max-width: 782px){.bsp-qcd__context-grid{grid-template-columns:1fr}.bsp-qcd__matrix-grid{grid-template-columns:1fr 1fr}.bsp-quote-admin__builder-compact-summary{grid-template-columns:22px minmax(0,1fr)}.bsp-quote-admin__builder-availability-summary,.bsp-quote-admin__builder-row-actions{grid-column:2}.bsp-quote-admin__builder-row-main-inputs,.bsp-qcd__proposal-editor-grid,.bsp-quote-admin__quote-total-card{grid-template-columns:1fr}}
        </style>';
    }
}
