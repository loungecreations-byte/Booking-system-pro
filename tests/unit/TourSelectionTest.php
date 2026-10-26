<?php
declare(strict_types=1);

use BSPModule\Core\Product\TourSelection;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/booking-core/src/Product/TourSelection.php';

final class TourSelectionTest extends TestCase
{
    private function catalog(): array
    {
        return [
            'enabled' => true,
            'version' => 'supplier-verified-v1',
            'variants' => [
                'two_hours' => ['enabled' => true, 'label' => '2 uur', 'duration_minutes' => 120, 'external_variant_id' => 'test-2'],
                'two_hours_city_quest' => ['enabled' => true, 'label' => '2 uur + City Quest', 'duration_minutes' => 120, 'city_quest' => true, 'external_variant_id' => 'test-quest'],
                'three_hours' => ['enabled' => true, 'label' => '3 uur', 'duration_minutes' => 180, 'external_variant_id' => 'test-3'],
            ],
            'upgrades' => [
                'flex' => ['enabled' => true, 'label' => 'Flex', 'terms_version' => 'v1', 'allowed_variants' => ['two_hours', 'two_hours_city_quest', 'three_hours'], 'external_option_id' => 'test-flex'],
                'damage' => ['enabled' => true, 'label' => 'Afkoop eigen risico', 'terms_version' => 'v1', 'allowed_variants' => ['two_hours', 'three_hours']],
            ],
        ];
    }

    public function testVariantsAndUpgradesRemainSeparate(): void
    {
        $snapshot = TourSelection::normalize($this->catalog(), [
            'variant' => 'two_hours_city_quest', 'upgrades' => ['flex'],
        ], 10);
        self::assertSame(120, $snapshot['duration_minutes']);
        self::assertTrue($snapshot['city_quest']);
        self::assertSame(10, $snapshot['participants']);
        self::assertSame('flex', $snapshot['upgrades'][0]['code']);
    }

    public function testIncompatibleUpgradeFailsClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TourSelection::normalize($this->catalog(), ['variant' => 'two_hours_city_quest', 'upgrades' => ['damage']], 10);
    }

    public function testDuplicateUpgradeFailsClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TourSelection::normalize($this->catalog(), ['variant' => 'two_hours', 'upgrades' => ['flex', 'flex']], 10);
    }

    public function testUnknownVariantFailsClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TourSelection::normalize($this->catalog(), ['variant' => 'unmapped', 'upgrades' => []], 10);
    }

    public function testBookingDefaultsToDisabled(): void
    {
        $catalog = $this->catalog();
        $selection = TourSelection::normalize($catalog, ['variant' => 'two_hours', 'upgrades' => []], 10);
        self::assertFalse(TourSelection::canAutoBook($catalog, $selection));
    }

    public function testUnmappedUpgradeBlocksAutomaticBooking(): void
    {
        $catalog = $this->catalog() + [];
        $catalog['supplier_catalog_verified'] = true;
        $catalog['booking_flow_verified'] = true;
        $catalog['transaction_safety_verified'] = true;
        $catalog['auto_booking_enabled'] = true;
        $selection = TourSelection::normalize($catalog, ['variant' => 'two_hours', 'upgrades' => ['damage']], 10);
        self::assertFalse(TourSelection::canAutoBook($catalog, $selection));
    }
}
