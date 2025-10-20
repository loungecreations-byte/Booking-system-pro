<?php

declare(strict_types=1);

namespace BSP\Tests\Unit\VendorPortal;

use BSP\VendorPortal\Service\VendorAuthService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class VendorAuthServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('delete_transient')) {
            global $bsp_test_transients;
            $bsp_test_transients = [];
        }
    }

    public function testLoginAndValidateToken(): void
    {
        $service = new VendorAuthService();
        $result  = $service->login(42, 'demo');

        $this->assertArrayHasKey('token', $result);
        $this->assertSame(42, $result['vendor_id']);

        $session = $service->validateToken($result['token']);
        $this->assertSame(42, $session['vendor_id']);
    }

    public function testLoginRejectsInvalidKey(): void
    {
        $service = new VendorAuthService();

        $this->expectException(InvalidArgumentException::class);
        $service->login(42, 'wrong');
    }
}
