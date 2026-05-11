<?php

declare(strict_types=1);

namespace {
    if (! class_exists('WP_Post')) {
        class WP_Post
        {
            public int $ID;

            public function __construct(int $id)
            {
                $this->ID = $id;
            }
        }
    }
}

namespace BSPModule\Core\Shortcodes {
    function get_option(string $key, $default = null)
    {
        return $GLOBALS['__home_onboarding_options'][$key] ?? $default;
    }

    function get_page_by_path(string $path)
    {
        return $GLOBALS['__home_onboarding_pages'][$path] ?? null;
    }

    function get_permalink($post): string
    {
        $id = $post instanceof \WP_Post ? $post->ID : (int) $post;

        return $GLOBALS['__home_onboarding_permalinks'][$id] ?? '';
    }

    function apply_filters(string $hook, $value)
    {
        $callback = $GLOBALS['__home_onboarding_filters'][$hook] ?? null;
        return is_callable($callback) ? $callback($value) : $value;
    }
}

namespace BSP\Tests\BookingTruth {

use BSPModule\Core\Shortcodes\Shortcodes;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once dirname(__DIR__, 2) . '/modules/core/Shortcodes/Shortcodes.php';

final class HomeOnboardingRuntimePublicationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['__home_onboarding_options'] = array();
        $GLOBALS['__home_onboarding_pages'] = array();
        $GLOBALS['__home_onboarding_permalinks'] = array();
        $GLOBALS['__home_onboarding_filters'] = array();
    }

    public function testCheckoutRuntimePublishesPlannerUrl(): void
    {
        $GLOBALS['__home_onboarding_options']['sbdp_booking_flow'] = 'pay';
        $GLOBALS['__home_onboarding_options']['sbdp_planner_page_id'] = 42;
        $GLOBALS['__home_onboarding_permalinks'][42] = 'https://dagjedenbosch.test/plan-je-dag/';

        $runtime = $this->invokeRuntimeBuilder();

        $this->assertSame('checkout', $runtime['route_intent']);
        $this->assertSame('https://dagjedenbosch.test/plan-je-dag/', $runtime['checkout_url']);
    }

    public function testRequestRuntimePublishesQuoteUrl(): void
    {
        $GLOBALS['__home_onboarding_options']['sbdp_booking_flow'] = 'request';
        $GLOBALS['__home_onboarding_pages']['offerte'] = new \WP_Post(7);
        $GLOBALS['__home_onboarding_permalinks'][7] = 'https://dagjedenbosch.test/offerte/';

        $runtime = $this->invokeRuntimeBuilder();

        $this->assertSame('quote', $runtime['route_intent']);
        $this->assertSame('https://dagjedenbosch.test/offerte/', $runtime['quote_url']);
    }

    public function testRuntimeFailsClosedWhenNoTargetUrlIsAvailable(): void
    {
        $GLOBALS['__home_onboarding_options']['sbdp_booking_flow'] = 'pay';

        $runtime = $this->invokeRuntimeBuilder();

        $this->assertSame(array(), $runtime);
    }

    /**
     * @return array<string, string>
     */
    private function invokeRuntimeBuilder(): array
    {
        $method = new ReflectionMethod(Shortcodes::class, 'buildHomeOnboardingRuntime');
        $method->setAccessible(true);

        /** @var array<string, string> $runtime */
        $runtime = $method->invoke(null);

        return $runtime;
    }
}
}
