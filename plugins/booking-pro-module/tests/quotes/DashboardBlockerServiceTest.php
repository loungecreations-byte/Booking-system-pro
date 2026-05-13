<?php

declare(strict_types=1);

namespace {
    if (! function_exists('__')) {
        function __(string $text, ?string $domain = null): string
        {
            return $text;
        }
    }
}

namespace BSP\Tests\Quotes {

use BSP\Quotes\Service\DashboardBlockerService;
use PHPUnit\Framework\TestCase;

final class DashboardBlockerServiceTest extends TestCase
{
    public function testCriticalBlockerWinsOverAssumptions(): void
    {
        $state = (new DashboardBlockerService())->buildState(
            array(
                'ready' => false,
                'blockers' => array(
                    array('code' => 'send_assumption_open', 'message' => 'Prijs onzeker.'),
                    array('code' => 'customer_email_invalid', 'message' => 'Email ontbreekt.'),
                ),
            ),
            array('violations' => array()),
            array(
                array(
                    'id' => 7,
                    'status' => 'open',
                    'blocks_send' => 1,
                    'assumption_type' => 'uncertain_pricing',
                    'message' => 'Prijs onzeker.',
                ),
            ),
            true
        );

        $this->assertSame('blocked', $state['state']);
        $this->assertSame('customer_email_invalid', $state['primary_blocker']['code']);
        $this->assertSame('Klant e-mail ontbreekt', $state['primary_blocker']['label']);
    }

    public function testResolvableAssumptionsBecomeOrangeStateWhenNoHardBlockerExists(): void
    {
        $state = (new DashboardBlockerService())->buildState(
            array(
                'ready' => false,
                'blockers' => array(
                    array('code' => 'send_assumption_open', 'message' => 'Beschikbaarheid onzeker.'),
                    array('code' => 'review_not_approved', 'message' => 'Review ontbreekt.'),
                ),
            ),
            array('violations' => array()),
            array(
                array(
                    'id' => 12,
                    'status' => 'open',
                    'blocks_send' => 1,
                    'assumption_type' => 'uncertain_availability',
                    'message' => 'Beschikbaarheid onzeker.',
                ),
            ),
            true
        );

        $this->assertSame('assumptions', $state['state']);
        $this->assertCount(1, $state['assumptions']);
        $this->assertSame('Capaciteit moet nog gecheckt worden', $state['assumptions'][0]['label']);
    }

    public function testReadyFrozenQuoteShowsLockedState(): void
    {
        $state = (new DashboardBlockerService())->buildState(
            array('ready' => true, 'blockers' => array()),
            array('violations' => array()),
            array(),
            false
        );

        $this->assertSame('locked', $state['state']);
        $this->assertTrue($state['ready']);
    }

    public function testBusinessWarningDoesNotOverrideReadyState(): void
    {
        $state = (new DashboardBlockerService())->buildState(
            array('ready' => true, 'blockers' => array()),
            array(
                'violations' => array(
                    array(
                        'code' => 'group_size_unusual',
                        'severity' => 'warning',
                        'message' => 'Groepsgrootte is ongebruikelijk.',
                    ),
                ),
            ),
            array(),
            true
        );

        $this->assertSame('ready', $state['state']);
    }

    public function testPricingBlockerUsesHumanOperatorCopy(): void
    {
        $state = (new DashboardBlockerService())->buildState(
            array(
                'ready' => false,
                'blockers' => array(
                    array(
                        'code' => 'pricing_confidence_missing',
                        'message' => 'Quote kan niet verzendklaar worden gezet zonder bevestigde prijsstatus.',
                    ),
                ),
            ),
            array('violations' => array()),
            array(),
            true
        );

        $this->assertSame('blocked', $state['state']);
        $this->assertSame('Prijs moet bevestigd worden', $state['primary_blocker']['label']);
        $this->assertSame('Deze offerte kan nog niet worden verstuurd omdat de prijs nog niet definitief is bevestigd.', $state['primary_blocker']['message']);
        $this->assertNotContains('Zet de prijsconfidence pas goed na echte controle.', $state['primary_blocker']['steps']);
        $this->assertContains('Bevestig de prijs.', $state['primary_blocker']['steps']);
        $this->assertStringNotContainsString('pricing_confidence', $state['primary_blocker']['message']);
        $this->assertStringNotContainsString('ready_to_send', $state['primary_blocker']['message']);
    }
}
}
