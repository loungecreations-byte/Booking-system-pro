<?php

declare(strict_types=1);

use BSP\Gamification\Domain\LevelResolver;
use BSP\Gamification\Support\Installer;
use PHPUnit\Framework\TestCase;

final class GamificationModuleTest extends TestCase
{
    public function testLevelBoundariesAreDeterministic(): void
    {
        $resolver = new LevelResolver();

        self::assertSame(1, $resolver->resolve(249)['number']);
        self::assertSame(2, $resolver->resolve(250)['number']);
        self::assertSame(8, $resolver->resolve(12500)['number']);
        self::assertSame(100, $resolver->resolve(12500)['progress']);
    }

    public function testLedgerSchemaHasIdempotencyAndUserIndexes(): void
    {
        $sql = implode("\n", Installer::schemas('wp_', ''));

        self::assertStringContainsString('UNIQUE KEY idempotency_key', $sql);
        self::assertStringContainsString('PRIMARY KEY  (user_id)', $sql);
        self::assertStringContainsString('UNIQUE KEY user_badge', $sql);
    }

    public function testFrontendCannotAwardXpDirectly(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root . '/modules/gamification/Rest/Controller.php');
        $frontend = file_get_contents($root . '/modules/gamification/assets/progress/index.jsx');

        self::assertStringNotContainsString("register_rest_route('bsp/v1','/xp", $controller);
        self::assertStringNotContainsString('xp_delta:', $frontend);
        self::assertStringNotContainsString('style={{', $frontend);
    }

    public function testWooAndDomainEventsUseTheSharedLedger(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/modules/gamification/Events/EventSubscriber.php');

        self::assertStringContainsString('woocommerce_payment_complete', $source);
        self::assertStringContainsString('bsp/gamification/event', $source);
        self::assertStringContainsString('reverseSource', $source);
    }

    public function testCollectiblesUseIndexedIdempotentTables(): void
    {
        $sql=implode("\n",Installer::schemas('wp_',''));
        self::assertStringContainsString('CREATE TABLE wp_bsp_collectibles', $sql);
        self::assertStringContainsString('UNIQUE KEY user_collectible', $sql);
        self::assertStringContainsString('UNIQUE KEY idempotency_key', $sql);
    }

    public function testCollectibleApiDoesNotExposeUnlockEndpoint(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/modules/gamification/Rest/CollectiblesController.php');
        self::assertStringContainsString('/me/collectibles', $source);
        self::assertStringNotContainsString("'/unlock'", $source);
        self::assertStringNotContainsString('xp_reward', $source);
    }

    public function testTourCompletionIsServerValidated(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/modules/gamification/Rest/TourCompletionController.php');
        self::assertStringContainsString('wc_customer_bought_product', $source);
        self::assertStringContainsString('wp_get_post_parent_id', $source);
        self::assertStringContainsString('bsp_tour_step_completions', $source);
        self::assertStringContainsString("'tour.step_completed'", $source);
    }

    public function testBoschSeederReusesCanonicalTourSteps(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/modules/gamification/Support/BoschCollectibleSeeder.php');
        self::assertStringContainsString("'post_parent'=>\$tourId", $source);
        self::assertStringContainsString("'checkpoint_id'=>(string)\$step->ID", $source);
        self::assertStringContainsString("'unlock_event'=>'tour.step_completed'", $source);
    }
}
