<?php

declare(strict_types=1);

namespace {
    if (! function_exists('get_post_meta')) {
        function get_post_meta(int $postId, string $key, bool $single = true)
        {
            unset($single);
            return $GLOBALS['__ddb_booking_mode_meta'][$postId][$key] ?? '';
        }
    }
}

namespace BSP\Tests\Quotes {
    use BSPModule\Core\Services\BookingModeService;
    use PHPUnit\Framework\TestCase;

    require_once dirname(__DIR__, 2) . '/modules/core/Services/BookingModeService.php';

    final class BookingModeServiceTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['__ddb_booking_mode_meta'] = array();
        }

        public function testDirectProductCanBeDirectBookableOnlyWhenEnabled(): void
        {
            $this->setMeta(201, array(
                '_ddb_booking_mode' => 'direct',
                '_ddb_direct_booking_enabled' => 'yes',
            ));

            $resolved = (new BookingModeService())->resolve(201);

            $this->assertSame('direct', $resolved['bookingMode']);
            $this->assertTrue($resolved['directBookable']);
            $this->assertSame('checkout', $resolved['routeIntent']);

            $this->setMeta(202, array(
                '_ddb_booking_mode' => 'direct',
                '_ddb_direct_booking_enabled' => 'no',
            ));

            $disabled = (new BookingModeService())->resolve(202);

            $this->assertSame('direct', $disabled['bookingMode']);
            $this->assertFalse($disabled['directBookable']);
            $this->assertSame('blocked', $disabled['routeIntent']);
        }

        public function testJeroenBoschTourProductCanBeExplicitDirectBookable(): void
        {
            $this->setMeta(116, array(
                '_ddb_booking_mode' => 'direct',
                '_ddb_direct_booking_enabled' => 'yes',
                '_ddb_quote_os_enabled' => 'no',
                '_ddb_supplier_confirmation_required' => 'no',
            ));

            $resolved = (new BookingModeService())->resolve(116);

            $this->assertSame('direct', $resolved['bookingMode']);
            $this->assertTrue($resolved['directBookable']);
            $this->assertFalse($resolved['quoteOsEnabled']);
            $this->assertFalse($resolved['supplierConfirmationRequired']);
            $this->assertSame('checkout', $resolved['routeIntent']);
        }

        public function testQuoteProductIsRequestOnlyWithQuoteOsEnabled(): void
        {
            $this->setMeta(203, array(
                '_ddb_booking_mode' => 'quote',
                '_ddb_direct_booking_enabled' => 'yes',
            ));

            $resolved = (new BookingModeService())->resolve(203);

            $this->assertSame('quote', $resolved['bookingMode']);
            $this->assertFalse($resolved['directBookable']);
            $this->assertTrue($resolved['quoteOsEnabled']);
            $this->assertSame('quote', $resolved['routeIntent']);
        }

        public function testSupplierConfirmationRequiresSupplierAndDisablesDirectBooking(): void
        {
            $this->setMeta(204, array(
                '_ddb_booking_mode' => 'supplier_confirmation',
                '_ddb_direct_booking_enabled' => 'yes',
                '_ddb_supplier_confirmation_required' => 'no',
            ));

            $resolved = (new BookingModeService())->resolve(204);

            $this->assertSame('supplier_confirmation', $resolved['bookingMode']);
            $this->assertFalse($resolved['directBookable']);
            $this->assertTrue($resolved['quoteOsEnabled']);
            $this->assertTrue($resolved['supplierConfirmationRequired']);
        }

        public function testBlockedProductDisablesDirectAndQuoteRoute(): void
        {
            $this->setMeta(205, array(
                '_ddb_booking_mode' => 'blocked',
                '_ddb_direct_booking_enabled' => 'yes',
                '_ddb_quote_os_enabled' => 'yes',
                '_ddb_supplier_confirmation_required' => 'yes',
            ));

            $resolved = (new BookingModeService())->resolve(205);

            $this->assertSame('blocked', $resolved['bookingMode']);
            $this->assertFalse($resolved['directBookable']);
            $this->assertFalse($resolved['quoteOsEnabled']);
            $this->assertFalse($resolved['supplierConfirmationRequired']);
            $this->assertSame('blocked', $resolved['routeIntent']);
        }

        public function testProduct115DefaultsToSupplierConfirmation(): void
        {
            $this->setMeta(115, array(
                '_ddb_booking_mode' => 'direct',
                '_ddb_direct_booking_enabled' => 'yes',
            ));

            $resolved = (new BookingModeService())->resolve(115);

            $this->assertSame('supplier_confirmation', $resolved['bookingMode']);
            $this->assertFalse($resolved['directBookable']);
            $this->assertTrue($resolved['quoteOsEnabled']);
            $this->assertTrue($resolved['supplierConfirmationRequired']);
            $this->assertSame('Eropuitje', $resolved['supplierName']);
            $this->assertSame(3, $resolved['supplierOptionDays']);
            $this->assertSame('manual', $resolved['supplierCancelMode']);
        }

        public function testEliioProviderProductDefaultsToSupplierConfirmation(): void
        {
            $this->setMeta(206, array(
                '_ddb_supplier_provider' => 'eliio',
                '_ddb_booking_mode' => 'direct',
                '_ddb_direct_booking_enabled' => 'yes',
            ));

            $resolved = (new BookingModeService())->resolve(206);

            $this->assertSame('supplier_confirmation', $resolved['bookingMode']);
            $this->assertFalse($resolved['directBookable']);
            $this->assertTrue($resolved['supplierConfirmationRequired']);
        }

        public function testBlockedMayStrictlyOverrideProduct115(): void
        {
            $this->setMeta(115, array(
                '_ddb_booking_mode' => 'blocked',
                '_ddb_direct_booking_enabled' => 'yes',
                '_ddb_quote_os_enabled' => 'yes',
            ));

            $resolved = (new BookingModeService())->resolve(115);

            $this->assertSame('blocked', $resolved['bookingMode']);
            $this->assertFalse($resolved['directBookable']);
            $this->assertFalse($resolved['quoteOsEnabled']);
            $this->assertSame('blocked', $resolved['routeIntent']);
        }

        public function testBookingWidgetIsNotReferencedByBookingModeCode(): void
        {
            $root = dirname(__DIR__, 2);
            $service = (string) file_get_contents($root . '/modules/core/Services/BookingModeService.php');
            $metaBox = (string) file_get_contents($root . '/modules/core/Admin/BookingModeProductMetaBox.php');

            $this->assertStringNotContainsString('booking-widget', $service);
            $this->assertStringNotContainsString('booking-widget', $metaBox);
        }

        /**
         * @param array<string, string> $meta
         */
        private function setMeta(int $productId, array $meta): void
        {
            $GLOBALS['__ddb_booking_mode_meta'][$productId] = $meta;
        }
    }
}
