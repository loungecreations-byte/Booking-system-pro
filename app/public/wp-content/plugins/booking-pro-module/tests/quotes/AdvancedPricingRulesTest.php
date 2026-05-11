<?php

declare(strict_types=1);

namespace BSP\Tests\Quotes;

use PHPUnit\Framework\TestCase;
use SBDP\Pricing\AdvancedPricingRules;

require_once dirname(__DIR__, 2) . '/includes/Pricing/PricingService.php';

final class AdvancedPricingRulesTest extends TestCase
{
    public function testPeopleThresholdRuleOverridesPerPersonPriceWhenBasePriceIsPerPerson(): void
    {
        $result = AdvancedPricingRules::resolve(
            array(
                array(
                    'condition' => 'people',
                    'value' => '20',
                    'price' => 75.0,
                ),
            ),
            array(
                'participants' => 20,
                'start' => '2026-04-14 16:00:00',
            ),
            array(
                'base_price' => 0.0,
                'per_person' => 70.0,
                'supports_persons' => true,
                'base_price_per_person' => true,
                'fixed_fee' => 0.0,
            )
        );

        $this->assertSame(75.0, $result['per_person']);
        $this->assertSame(0.0, $result['base_price']);
        $this->assertCount(1, $result['applied_rules']);
        $this->assertSame('per_person', $result['applied_rules'][0]['target']);
    }

    public function testPeopleThresholdRuleDoesNotApplyBelowThreshold(): void
    {
        $result = AdvancedPricingRules::resolve(
            array(
                array(
                    'condition' => 'people',
                    'value' => '20',
                    'price' => 75.0,
                ),
            ),
            array(
                'participants' => 10,
                'start' => '2026-04-14 16:00:00',
            ),
            array(
                'base_price' => 0.0,
                'per_person' => 70.0,
                'supports_persons' => true,
                'base_price_per_person' => true,
                'fixed_fee' => 0.0,
            )
        );

        $this->assertSame(70.0, $result['per_person']);
        $this->assertSame(array(), $result['applied_rules']);
    }

    public function testDateRuleOverridesBookingBasePriceForNonPersonProduct(): void
    {
        $result = AdvancedPricingRules::resolve(
            array(
                array(
                    'condition' => 'date',
                    'value' => '2026-04-14>2026-04-16',
                    'price' => 210.0,
                ),
            ),
            array(
                'participants' => 4,
                'start' => '2026-04-14 12:00:00',
            ),
            array(
                'base_price' => 180.0,
                'per_person' => 0.0,
                'supports_persons' => false,
                'base_price_per_person' => false,
                'fixed_fee' => 0.0,
            )
        );

        $this->assertSame(210.0, $result['base_price']);
        $this->assertSame(0.0, $result['per_person']);
        $this->assertCount(1, $result['applied_rules']);
        $this->assertSame('base_price', $result['applied_rules'][0]['target']);
    }
}
