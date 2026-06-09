<?php

declare(strict_types=1);

namespace BSP\Tests\Quotes;

use PHPUnit\Framework\TestCase;

final class LegacyProductPlannerAuthorityTest extends TestCase
{
    public function testLegacyProductPlannerDoesNotDefaultToDirectCheckoutWhenRuntimeIsMissing(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/includes/bootstrap/sbdp-single-product-planner.php');

        self::assertIsString($source);
        self::assertStringContainsString("'route_intent' => 'quote'", $source);
        self::assertStringContainsString("'reason_code' => 'booking_runtime_unavailable'", $source);
        self::assertStringContainsString("'directBookable' => false", $source);
        self::assertStringNotContainsString(": array('bookingMode' => 'direct', 'routeIntent' => 'checkout', 'directBookable' => true)", $source);
        self::assertStringNotContainsString("route_intent']) ? (string) \$booking_profile['route_intent'] : 'checkout'", $source);
    }

    public function testProductPageRefreshDoesNotDefaultToDirectCheckoutWhenBookingModeRuntimeIsMissing(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/modules/product-page-refresh/Module.php');

        self::assertIsString($source);
        self::assertStringContainsString("'bookingMode' => 'supplier_confirmation'", $source);
        self::assertStringContainsString("'routeIntent' => 'quote'", $source);
        self::assertStringContainsString("'directBookable' => false", $source);
        self::assertStringNotContainsString("'bookingMode' => 'direct',", $source);
        self::assertStringNotContainsString("'routeIntent' => 'checkout',", $source);
        self::assertStringNotContainsString("'directBookable' => true", $source);
    }

    public function testCartRestoreDoesNotInventAvailabilityTruth(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/includes/bootstrap/sbdp-planner-domain.php');

        self::assertIsString($source);
        self::assertStringContainsString("'reason'                => 'cart_restore_unverified'", $source);
        self::assertStringNotContainsString("'selectedSlotAvailable' => true", $source);
    }

    public function testArrangementAvailabilityDoesNotTreatMissingProjectionAsAvailable(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/modules/arrangements/Domain/ArrangementAvailabilityService.php');

        self::assertIsString($source);
        self::assertStringContainsString("\$request->set_param('participants', \$participants);", $source);
        self::assertStringContainsString("'reason' => 'availability_unverified'", $source);
        self::assertStringNotContainsString("'reason' => 'derived'", $source);
        self::assertStringNotContainsString("return array('available' => true, 'reason' => 'no_slot_constraints');", $source);
    }

    public function testHomeOnboardingRuntimeDoesNotNamePlannerRouteCheckout(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/modules/core/Shortcodes/Shortcodes.php');

        self::assertIsString($source);
        self::assertStringContainsString("'route_intent' => 'planner'", $source);
        self::assertStringContainsString("'planner_url'  => \$plannerUrl", $source);
        self::assertStringContainsString("routeIntent === 'planner'", $source);
        self::assertStringNotContainsString("'route_intent' => 'checkout'", $source);
        self::assertStringNotContainsString("'checkout_url' => \$plannerUrl", $source);
    }

    public function testFloatingActionBarDoesNotInventDefaultParticipantsForTotals(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/assets/js/day-planner/app/components/FloatingActionBar.jsx');

        self::assertIsString($source);
        self::assertStringNotContainsString('const FALLBACK_PARTICIPANTS = 10;', $source);
        self::assertStringContainsString('function firstPositiveParticipant(...values)', $source);
        self::assertStringContainsString("config?.participants\n  );", $source);
    }

    public function testPlannerProviderDoesNotInitializeCanonicalParticipantsToTen(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/assets/js/day-planner/store/PlannerProvider.jsx');

        self::assertIsString($source);
        self::assertStringContainsString('const DEFAULT_PARTICIPANTS = null;', $source);
        self::assertStringContainsString('participants: "",', $source);
        self::assertStringContainsString('Aantal deelnemers ontbreekt voor de beschikbaarheidscontrole.', $source);
        self::assertStringNotContainsString('const DEFAULT_PARTICIPANTS = 10;', $source);
        self::assertStringNotContainsString('participants: String(DEFAULT_PARTICIPANTS)', $source);
    }

    public function testInfoStepDoesNotSeedParticipantsWithTen(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/assets/js/day-planner/app/components/InfoStep.jsx');

        self::assertIsString($source);
        self::assertStringContainsString('const [participantInput, setParticipantInput] = useState("");', $source);
        self::assertStringContainsString('function firstPositiveParticipant(...values)', $source);
        self::assertStringContainsString("config?.default_participants\n  );", $source);
        self::assertStringNotContainsString('const FALLBACK_PARTICIPANTS = 10;', $source);
        self::assertStringNotContainsString('useState(String(FALLBACK_PARTICIPANTS))', $source);
    }
}
