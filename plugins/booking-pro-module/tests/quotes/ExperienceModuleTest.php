<?php
declare(strict_types=1);

use BSP\Experience\Support\Installer;
use BSP\Experience\Account\AccountPage;
use PHPUnit\Framework\TestCase;

final class ExperienceModuleTest extends TestCase
{
    public function testSchemasProtectFavoriteAndTimelineIdempotency(): void
    {
        $sql=implode("\n",Installer::schemas('wp_',''));
        self::assertStringContainsString('UNIQUE KEY user_object', $sql);
        self::assertStringContainsString('UNIQUE KEY idempotency_key', $sql);
        self::assertStringContainsString('KEY user_occurred', $sql);
        self::assertStringContainsString('UNIQUE KEY ticket_claim', $sql);
        self::assertStringContainsString('PRIMARY KEY  (user_id,tour_id)', $sql);
        self::assertStringContainsString('UNIQUE KEY verification_code', $sql);
    }

    public function testProgressAndClaimsAreServerValidated(): void
    {
        $controller=file_get_contents(dirname(__DIR__,2).'/modules/experience/Rest/Controller.php');
        $claim=file_get_contents(dirname(__DIR__,2).'/modules/experience/Service/TicketClaimService.php');
        $progress=file_get_contents(dirname(__DIR__,2).'/modules/experience/Service/ExperienceProgressService.php');
        self::assertStringContainsString('canAccessTour',$controller);
        self::assertStringContainsString('hash_equals',$claim);
        self::assertStringContainsString("'post_parent'=>\$tourId",$progress);
        self::assertStringContainsString('array_intersect',$progress);
    }

    public function testExperienceApiIsCurrentUserOnlyAndPrivateCache(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/modules/experience/Rest/Controller.php');
        self::assertStringContainsString("'/me/experience'",$source);
        self::assertStringContainsString('is_user_logged_in()', $source);
        self::assertStringContainsString("'Cache-Control','private, no-store, max-age=0'",$source);
        self::assertStringNotContainsString('get_param(\'user_id\')',$source);
    }

    public function testAccessPolicyRejectsRefundedCancelledAndExpiredAccess(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/modules/experience/Service/ExperienceAccessPolicy.php');
        self::assertStringContainsString("array('cancelled', 'refunded', 'failed')",$source);
        self::assertStringContainsString("\$status === 'active'",$source);
        self::assertStringContainsString('! $expired',$source);
    }

    public function testExperienceDoesNotOwnCommerceOrBookingTruth(): void
    {
        $root=dirname(__DIR__,2).'/modules/experience';
        $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        $source=''; foreach ($files as $file) if ($file->isFile() && $file->getExtension()==='php') $source.=file_get_contents($file->getPathname());
        self::assertStringNotContainsString('woocommerce_before_calculate_totals',$source);
        self::assertStringNotContainsString('directBookable',$source);
        self::assertStringNotContainsString('booking-widget',$source);
        self::assertStringNotContainsString('xp_delta:', $source);
    }

    public function testGamificationPrivacyIncludesTourCompletions(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/modules/gamification/Privacy/PrivacyService.php');
        self::assertStringContainsString("'bsp_tour_step_completions'",$source);
        self::assertStringContainsString('Voltooide tourstappen',$source);
    }

    public function testAccountPageUsesEndpointScopedExternalScript(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/modules/experience/Account/AccountPage.php');

        self::assertStringNotContainsString('<script>', $source);
        self::assertStringNotContainsString('data-endpoint=', $source);
        self::assertStringNotContainsString('data-nonce=', $source);
        self::assertStringContainsString("add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueueAssets'))", $source);
        self::assertStringContainsString("is_wc_endpoint_url(self::ENDPOINT)", $source);
        self::assertStringContainsString("wp_enqueue_script(", $source);
        self::assertStringContainsString("wp_localize_script(", $source);
        self::assertStringContainsString("rest_url('bsp/v1/me/experience')", $source);
        self::assertStringContainsString("wp_create_nonce('wp_rest')", $source);
    }

    public function testAccountFrontendRegressionSuiteExists(): void
    {
        $test=file_get_contents(dirname(__DIR__,2).'/tests/js/account-experience.test.mjs');

        foreach (array(
            'successful response',
            'HTTP error',
            'invalid JSON',
            'fetch timeout',
            'missing root container',
            'duplicate initialization',
            'late DOM ready',
        ) as $scenario) {
            self::assertStringContainsString($scenario, $test);
        }
    }

    public function testAccountScriptEnqueuesOnlyOnExperienceEndpoint(): void
    {
        if (! defined('SBDP_DIR')) {
            define('SBDP_DIR', dirname(__DIR__, 2) . '/');
        }
        if (! defined('SBDP_URL')) {
            define('SBDP_URL', 'https://example.test/wp-content/plugins/booking-pro-module/');
        }
        if (! defined('SBDP_VERSION')) {
            define('SBDP_VERSION', 'test');
        }

        $GLOBALS['__test_enqueued_scripts'] = array();
        $GLOBALS['__test_localized_scripts'] = array();
        $GLOBALS['__test_is_account_page'] = false;
        $GLOBALS['__test_wc_endpoint'] = '';
        AccountPage::enqueueAssets();
        self::assertSame(array(), $GLOBALS['__test_enqueued_scripts']);

        $GLOBALS['__test_is_account_page'] = true;
        $GLOBALS['__test_wc_endpoint'] = 'orders';
        AccountPage::enqueueAssets();
        self::assertSame(array(), $GLOBALS['__test_enqueued_scripts']);

        $GLOBALS['__test_wc_endpoint'] = 'mijn-dagjedenbosch';
        AccountPage::enqueueAssets();

        self::assertCount(1, $GLOBALS['__test_enqueued_scripts']);
        self::assertCount(1, $GLOBALS['__test_localized_scripts']);
        self::assertSame('bsp-experience-account', $GLOBALS['__test_enqueued_scripts'][0]['handle']);
        self::assertSame('bspExperienceAccount', $GLOBALS['__test_localized_scripts'][0]['objectName']);
        self::assertSame('https://example.test/wp-json/bsp/v1/me/experience', $GLOBALS['__test_localized_scripts'][0]['data']['endpoint']);
        self::assertSame('valid-nonce-wp_rest', $GLOBALS['__test_localized_scripts'][0]['data']['nonce']);
    }
}
