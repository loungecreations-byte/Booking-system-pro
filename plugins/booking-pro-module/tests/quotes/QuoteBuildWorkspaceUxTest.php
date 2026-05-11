<?php

declare(strict_types=1);

namespace {
    if (! function_exists('esc_html')) {
        function esc_html($text): string
        {
            return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        }
    }

    if (! function_exists('esc_attr')) {
        function esc_attr($text): string
        {
            return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        }
    }

    if (! function_exists('esc_attr__')) {
        function esc_attr__(string $text, string $domain = 'default'): string
        {
            unset($domain);
            return $text;
        }
    }
}

namespace BSP\Tests\Quotes {

use BSP\Quotes\Admin\Controller;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once dirname(__DIR__, 2) . '/modules/quotes/Admin/Controller.php';

final class QuoteBuildWorkspaceUxTest extends TestCase
{
    public function testBuildRowUsesOperatorLabelsInsteadOfTechnicalFields(): void
    {
        $html = $this->renderBuildRow(array(
            'title' => 'Workshop worstenbroodjes',
            'product_id' => 123,
            'pricing_confidence' => 'unknown',
            'availability_confidence' => 'unknown',
            'currency' => 'EUR',
            'participants' => 12,
        ));

        $this->assertStringContainsString('Activiteit', $html);
        $this->assertStringContainsString('Aantal personen', $html);
        $this->assertStringContainsString('Beschikbare tijden', $html);
        $this->assertStringContainsString('Prijs p.p.', $html);
        $this->assertStringContainsString('Totaal regel', $html);
        $this->assertStringContainsString('Prijs nog niet bevestigd', $html);
        $this->assertStringContainsString('Beschikbaarheid nog niet bevestigd', $html);
        $this->assertStringContainsString('Geavanceerd / maatwerk', $html);
        $this->assertStringNotContainsString('Slotlabel', $html);
        $this->assertStringNotContainsString('Deelnemers', $html);
        $this->assertStringNotContainsString('snapshot unknown', $html);
        $this->assertStringNotContainsString('availability unknown', $html);
    }

    public function testBuildRowMarksManualLinesAsMaatwerkregel(): void
    {
        $html = $this->renderBuildRow(array(
            'title' => '',
            'product_id' => 0,
            'pricing_confidence' => 'unknown',
            'availability_confidence' => 'unknown',
        ));

        $this->assertStringContainsString('Maatwerkregel', $html);
        $this->assertStringContainsString('Maatwerkregels of handmatig aangepaste tijden blijven onder voorbehoud', $html);
    }

    public function testLineSummaryUsesExplicitPriceStatusWhenNoLinesArePriced(): void
    {
        $summary = $this->summarizeLines(array(
            array(
                'currency' => 'EUR',
                'line_total_snapshot' => '',
            ),
            array(
                'currency' => 'EUR',
                'line_total_snapshot' => null,
            ),
        ));

        $this->assertSame(2, $summary['total_lines']);
        $this->assertSame(0, $summary['priced_lines']);
        $this->assertSame('Prijs op aanvraag', $summary['total_label']);
    }

    public function testLineSummaryDoesNotPretendPartialPricingIsFinalTotal(): void
    {
        $summary = $this->summarizeLines(array(
            array(
                'currency' => 'EUR',
                'line_total_snapshot' => 100.0,
            ),
            array(
                'currency' => 'EUR',
                'line_total_snapshot' => '',
            ),
        ));

        $this->assertSame(2, $summary['total_lines']);
        $this->assertSame(1, $summary['priced_lines']);
        $this->assertSame('Deels geprijsd: EUR 100,00', $summary['total_label']);
    }

    public function testLineSummaryAppliesVersionDiscountOnlyWhenFullyPriced(): void
    {
        $summary = $this->summarizeLines(array(
            array(
                'currency' => 'EUR',
                'line_total_snapshot' => 100.0,
            ),
            array(
                'currency' => 'EUR',
                'line_total_snapshot' => 50.0,
            ),
        ), array(
            'pricing_snapshot_json' => array(
                'commercial_adjustments' => array(
                    'discount_amount' => 25.0,
                    'discount_label' => 'Actiekorting',
                    'currency' => 'EUR',
                ),
            ),
        ));

        $this->assertSame('EUR 150,00', $summary['subtotal_label']);
        $this->assertSame(25.0, $summary['discount_amount']);
        $this->assertSame('Actiekorting', $summary['discount_label']);
        $this->assertSame('EUR 125,00', $summary['total_label']);
    }

    /**
     * @param array<string, mixed> $line
     */
    private function renderBuildRow(array $line): string
    {
        $defaults = array(
            'source_line_number' => 1,
            'sort_order' => 1,
            'quantity' => 1,
            'participants' => 0,
            'service_date' => '',
            'proposed_start_time' => '',
            'proposed_end_time' => '',
            'duration_minutes' => 0,
            'selected_option_labels' => '',
            'validated_slot_label' => '',
            'vendor_id' => '',
            'resource_id' => '',
            'pricing_mode' => 'directional',
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

        $reflection = new ReflectionClass(Controller::class);
        $method = $reflection->getMethod('renderQuoteBuildRow');
        $method->setAccessible(true);

        return (string) $method->invoke(null, 0, array_merge($defaults, $line), array(
            array(
                'id' => 123,
                'title' => 'Workshop worstenbroodjes',
                'duration_minutes' => 90,
                'unit_amount_snapshot' => '25',
                'currency' => 'EUR',
            ),
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    private function summarizeLines(array $lines, ?array $version = null): array
    {
        $reflection = new ReflectionClass(Controller::class);
        $method = $reflection->getMethod('summarizeQuoteLines');
        $method->setAccessible(true);

        return $method->invoke(null, $lines, $version);
    }
}

}
