<?php

declare(strict_types=1);

namespace BSP\Tests\Quotes;

use BSP\VendorPortal\Service\VendorDashboardService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class VendorPortalRegressionTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../..';

    public function testFinancialSummaryUsesExplicitCommerceStatuses(): void
    {
        $now = time();
        $bookings = array(
            array('status' => 'paid', 'total' => 100.0, 'currency' => 'EUR', 'timestamp' => $now),
            array('status' => 'completed', 'total' => 50.0, 'currency' => 'EUR', 'timestamp' => $now),
            array('status' => 'requested', 'total' => 80.0, 'currency' => 'EUR', 'timestamp' => $now),
            array('status' => 'cancelled', 'total' => 200.0, 'currency' => 'EUR', 'timestamp' => $now),
            array('status' => 'refunded', 'total' => 25.0, 'currency' => 'EUR', 'timestamp' => $now),
        );

        $method = new ReflectionMethod(VendorDashboardService::class, 'buildFinancialSummary');
        $method->setAccessible(true);
        $summary = $method->invoke(new VendorDashboardService(), $bookings);

        self::assertSame(150.0, $summary['total_revenue']);
        self::assertSame(150.0, $summary['paid_revenue']);
        self::assertSame(80.0, $summary['pending_revenue']);
        self::assertSame(150.0, $summary['ytd_revenue']);
        self::assertSame(5, $summary['ytd_bookings']);
        self::assertSame(2, $summary['monthly_breakdown'][0]['count']);
    }

    public function testPortalTemplateContainsOneDashboardAndAccessibleTabs(): void
    {
        $template = $this->readFile('plugins/booking-pro-module/modules/vendor-portal/Templates/dashboard.php');

        self::assertSame(1, substr_count($template, 'class="sbdp-vendor-portal__dashboard"'));
        self::assertStringNotContainsString('<?php return; ?>', $template);
        self::assertSame(4, substr_count($template, 'aria-labelledby="sbdp-tab-control-'));
        self::assertSame(4, substr_count($template, 'role="tab"'));
    }

    public function testPortalFrontendDoesNotPersistSessionToken(): void
    {
        $script = $this->readFile('plugins/booking-pro-module/modules/vendor-portal/assets/vendor-portal.js');
        $controller = $this->readFile('plugins/booking-pro-module/modules/vendor-portal/Rest/PortalController.php');

        self::assertStringNotContainsString('token: String(response.token', $script);
        self::assertStringNotContainsString('!state.session.token', $script);
        self::assertStringNotContainsString('window.prompt(', $script);
        self::assertStringContainsString('credentials: \'same-origin\'', $script);
        self::assertStringContainsString('tab.setAttribute(\'tabindex\'', $script);
        self::assertStringContainsString("api('/confirmations/respond'", $script);
        self::assertStringContainsString("'httponly' => true", $controller);
        self::assertStringContainsString("'samesite' => 'Strict'", $controller);
        self::assertStringContainsString("'session'   => self::tokenFingerprint", $controller);
    }

    public function testActionCenterHasFiltersEmptyStateAndAccessibleDialog(): void
    {
        $template = $this->readFile('plugins/booking-pro-module/modules/vendor-portal/Templates/dashboard.php');

        self::assertStringContainsString('data-sbdp-action-filter="all"', $template);
        self::assertStringContainsString('data-sbdp-action-filter="confirmations"', $template);
        self::assertStringContainsString('data-sbdp-action-filter="dietary"', $template);
        self::assertStringContainsString('data-sbdp-action-empty', $template);
        self::assertStringContainsString('<dialog class="sbdp-vp-action-dialog"', $template);
        self::assertStringContainsString('data-sbdp-action-dialog-note required', $template);
    }

    public function testPortalDoesNotInventParticipantsForMissingBookingData(): void
    {
        $script = $this->readFile('plugins/booking-pro-module/modules/vendor-portal/assets/vendor-portal.js');

        self::assertStringContainsString('function canonicalParticipants(value)', $script);
        self::assertStringNotContainsString('booking.participants ||', $script);
        self::assertStringNotContainsString('parseInt(booking.participants, 10) || 0', $script);
    }

    public function testRepeatedPartnerResponseHasAnIdempotentServicePath(): void
    {
        $service = $this->readFile('plugins/booking-pro-module/modules/bookings/Service/PartnerConfirmationService.php');

        self::assertStringContainsString("'idempotent_replay' => true", $service);
        self::assertStringContainsString("(string) (\$previousResponse['action'] ?? '') === \$action", $service);
        self::assertStringContainsString("(string) (\$previousResponse['note'] ?? '') === \$note", $service);
    }

    private function readFile(string $relativePath): string
    {
        $contents = file_get_contents(self::ROOT . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
        self::assertIsString($contents);

        return $contents;
    }
}
