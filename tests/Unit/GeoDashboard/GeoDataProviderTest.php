<?php

declare(strict_types=1);

namespace BSP\Tests\Unit\GeoDashboard;

use BSP\GeoDashboard\Service\GeoDataProvider;
use BSP\Sales\Vendors\VendorService;
use PHPUnit\Framework\TestCase;

final class GeoDataProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (class_exists(VendorService::class)) {
            VendorService::init();
        }
    }

    public function testGetGeoDataStructure(): void
    {
        $provider = new GeoDataProvider();
        $data     = $provider->getGeoData();

        $this->assertArrayHasKey('vendors', $data);
        $this->assertArrayHasKey('bookings', $data);
    }
}
