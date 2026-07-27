<?php

declare(strict_types=1);

if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
}
if (! function_exists('sanitize_key')) {
    function sanitize_key(string $value): string
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)) ?? '';
    }
}
if (! function_exists('wp_kses_post')) {
    function wp_kses_post(string $value): string { return $value; }
}
if (! function_exists('esc_url_raw')) {
    function esc_url_raw(string $value): string { return filter_var($value, FILTER_SANITIZE_URL) ?: ''; }
}
if (! function_exists('wp_parse_url')) {
    function wp_parse_url(string $value, int $component = -1) { return parse_url($value, $component); }
}
if (! function_exists('absint')) {
    function absint($value): int { return abs((int) $value); }
}
if (! function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field(string $value): string { return trim(strip_tags($value)); }
}

$moduleRoot = dirname(__DIR__, 2) . '/modules/experience-builder/';
foreach (array(
    'Contract/ModuleTypeInterface.php',
    'Contract/ChapterModuleRepositoryInterface.php',
    'Domain/ModuleDefinition.php',
    'Registry/ModuleRegistry.php',
    'ModuleTypes/AbstractContentModule.php',
    'ModuleTypes/TextModule.php',
    'ModuleTypes/ImageModule.php',
    'ModuleTypes/AudioModule.php',
    'ModuleTypes/VideoModule.php',
    'ModuleTypes/SketchfabModule.php',
    'ModuleTypes/AiPhotoChallengeModule.php',
    'ModuleTypes/QuizModule.php',
    'ModuleTypes/RewardModule.php',
    'Service/ModuleValidationService.php',
    'Repository/WordPressChapterModuleRepository.php',
    'Service/ModuleDocumentService.php',
) as $moduleFile) {
    require_once $moduleRoot . $moduleFile;
}

use BSP\ExperienceBuilder\ModuleTypes\AudioModule;
use BSP\ExperienceBuilder\ModuleTypes\ImageModule;
use BSP\ExperienceBuilder\ModuleTypes\TextModule;
use BSP\ExperienceBuilder\ModuleTypes\VideoModule;
use BSP\ExperienceBuilder\ModuleTypes\SketchfabModule;
use BSP\ExperienceBuilder\ModuleTypes\AiPhotoChallengeModule;
use BSP\ExperienceBuilder\ModuleTypes\QuizModule;
use BSP\ExperienceBuilder\ModuleTypes\RewardModule;
use BSP\ExperienceBuilder\Contract\ChapterModuleRepositoryInterface;
use BSP\ExperienceBuilder\Registry\ModuleRegistry;
use BSP\ExperienceBuilder\Service\ModuleDocumentService;
use BSP\ExperienceBuilder\Service\ModuleValidationService;
use PHPUnit\Framework\TestCase;

final class ExperienceBuilderModuleTest extends TestCase
{
    private function validator(): ModuleValidationService
    {
        $registry = new ModuleRegistry();
        $registry->register(new TextModule());
        $registry->register(new ImageModule());
        $registry->register(new AudioModule());
        $registry->register(new VideoModule());
        $registry->register(new SketchfabModule());
        $registry->register(new AiPhotoChallengeModule());
        $registry->register(new QuizModule());
        $registry->register(new RewardModule());

        return new ModuleValidationService($registry);
    }

    public function testRegistryPublishesCoreDefinitions(): void
    {
        $registry = new ModuleRegistry();
        $registry->register(new TextModule());
        $registry->register(new ImageModule());

        self::assertSame(array('text', 'image'), array_keys($registry->definitions()));
        self::assertSame(1, $registry->definitions()['text']['schema_version']);
    }

    public function testDocumentNormalizesOrderingAndContent(): void
    {
        $result = $this->validator()->normalize(array(
            'schema_version' => 1,
            'revision' => 4,
            'modules' => array(
                array(
                    'id' => 'b6f14dd4-b6c4-43cf-90da-8f92fb89a77c',
                    'type' => 'text',
                    'index' => 99,
                    'content' => array('html' => '<p>Verhaal</p>'),
                ),
                array(
                    'id' => 'a09208ce-b2ee-4dad-bb50-a41bdb42057d',
                    'type' => 'image',
                    'index' => 1,
                    'content' => array('url' => 'https://example.test/foto.jpg', 'alt' => 'Poort'),
                ),
            ),
        ));

        self::assertSame(array(), $result['errors']);
        self::assertSame(0, $result['document']['modules'][0]['index']);
        self::assertSame(1, $result['document']['modules'][1]['index']);
        self::assertSame('<p>Verhaal</p>', $result['document']['modules'][0]['content']['html']);
    }

    public function testMalformedAndDuplicateModulesAreRejected(): void
    {
        $id = 'b6f14dd4-b6c4-43cf-90da-8f92fb89a77c';
        $result = $this->validator()->normalize(array(
            'schema_version' => 1,
            'modules' => array(
                array('id' => $id, 'type' => 'text', 'content' => array()),
                array('id' => $id, 'type' => 'text', 'content' => array()),
                'invalid',
            ),
        ));

        self::assertSame(
            array('duplicate_module_id', 'invalid_module'),
            array_column($result['errors'], 'code')
        );
    }

    public function testDisabledMediaModuleMayBeSavedAsIncompleteDraft(): void
    {
        $result = $this->validator()->normalize(array(
            'schema_version' => 1,
            'modules' => array(array(
                'id' => 'b6f14dd4-b6c4-43cf-90da-8f92fb89a77c',
                'type' => 'video',
                'enabled' => false,
                'content' => array(),
                'completion' => array('mode' => 'automatic'),
            )),
        ));

        self::assertSame(array(), $result['errors']);
        self::assertFalse($result['document']['modules'][0]['enabled']);
    }

    public function testUnknownModuleIsPreservedForForwardCompatibility(): void
    {
        $result = $this->validator()->normalize(array(
            'schema_version' => 1,
            'modules' => array(array(
                'id' => 'b6f14dd4-b6c4-43cf-90da-8f92fb89a77c',
                'type' => 'future_module',
                'settings' => array('future' => true),
                'content' => array('raw' => 'keep-me'),
            )),
        ));

        self::assertSame(array(), $result['errors']);
        self::assertSame('unknown_module_type', $result['warnings'][0]['code']);
        self::assertSame('keep-me', $result['document']['modules'][0]['content']['raw']);
    }

    public function testLegacyAdapterIsReadOnlyAndDoesNotWrite(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/modules/experience-builder/Service/LegacyChapterAdapter.php');
        self::assertStringContainsString("'virtual' => true", $source);
        self::assertStringContainsString("'read_only' => true", $source);
        self::assertStringNotContainsString('update_post_meta', $source);
        self::assertStringNotContainsString('wp_insert_post', $source);
    }

    public function testModuleLayerCannotOwnCommerceProgressOrRewards(): void
    {
        $root = dirname(__DIR__, 2) . '/modules/experience-builder';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        $source = '';
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $source .= file_get_contents($file->getPathname());
            }
        }

        foreach (array(
            'woocommerce_before_calculate_totals',
            'directBookable',
            'booking-widget',
            'XpLedgerService',
            'CollectibleUnlockService',
        ) as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
        self::assertSame(
            1,
            substr_count($source, 'use BSP\\Experience\\Service\\ExperienceProgressService;'),
            'Only the server-side completion adapter may consume canonical Experience progress.'
        );
    }

    public function testGenericCoreRestCannotBypassConflictCheckedSave(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/modules/experience-builder/Module.php');
        $service = file_get_contents(dirname(__DIR__, 2) . '/modules/experience-builder/Service/ModuleDocumentService.php');

        self::assertStringContainsString("'show_in_rest' => false", $source);
        self::assertStringContainsString("current_user_can('edit_post'", $source);
        self::assertStringContainsString("'chapter_modules_conflict'", $service);
        self::assertStringContainsString("'invalid_chapter_modules'", $service);
    }

    public function testSaveLoadRoundtripAndRevisionConflict(): void
    {
        $repository = new class implements ChapterModuleRepositoryInterface {
            /** @var array<string,mixed> */
            public array $stored = array();
            // Mirrors register_post_meta(default => array()) before the first write.
            public function get(int $chapterId) { return $this->stored[$chapterId] ?? array(); }
            public function update(int $chapterId, array $document): bool
            {
                $this->stored[$chapterId] = $document;
                return true;
            }
            public function postType(int $chapterId): string { return 'sbdp_tour_step'; }
        };
        $GLOBALS['__test_current_user_can'] = true;
        $service = new ModuleDocumentService($this->validator(), $repository);
        $document = array(
            'schema_version' => 1,
            'revision' => 1,
            'modules' => array(array(
                'id' => 'b6f14dd4-b6c4-43cf-90da-8f92fb89a77c',
                'type' => 'text',
                'content' => array('html' => '<p>Rondreis</p>'),
            )),
        );

        $saved = $service->save(42, $document, 0);
        self::assertIsArray($saved);
        self::assertSame(1, $saved['document']['revision']);
        self::assertSame($saved['document'], $service->get(42));

        $conflict = $service->save(42, $document, 0);
        self::assertInstanceOf(WP_Error::class, $conflict);
        self::assertSame('chapter_modules_conflict', $conflict->code);
    }

    public function testBuilderRestBoundaryIsCapabilityCheckedAndSizeLimited(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/modules/experience-builder/Rest/ChapterModulesController.php');

        self::assertStringContainsString("current_user_can('edit_post'", $source);
        self::assertStringContainsString("'methods' => 'PUT'", $source);
        self::assertStringContainsString('expected_revision', $source);
        self::assertStringContainsString('512 * KB_IN_BYTES', $source);
        self::assertStringNotContainsString("'permission_callback' => '__return_true'", $source);
    }

    public function testRuntimeCompletionReusesExistingProgressTruth(): void
    {
        $completion = file_get_contents(dirname(__DIR__, 2) . '/modules/experience-builder/Service/ModuleCompletionService.php');
        $runtime = file_get_contents(dirname(__DIR__, 2) . '/assets/js/tour-navigation.js');
        $tickets = file_get_contents(dirname(__DIR__, 2) . '/includes/class-sbdp-private-tours-tickets.php');

        self::assertStringContainsString('bsp_experience_timeline', $completion);
        self::assertStringContainsString('ExperienceProgressService', $completion);
        self::assertStringContainsString('SBDP_Private_Tours_Tickets::store_progress', $completion);
        self::assertStringNotContainsString('XpLedgerService', $completion);
        self::assertStringNotContainsString('CollectibleUnlockService', $completion);
        self::assertStringContainsString("'modules'      => \$modules", $tickets);
        self::assertStringContainsString('renderModules(step)', $runtime);
        self::assertStringContainsString('X-DDB-Tour-Session', $runtime);
    }

    public function testSketchfabAcceptsOnlyOfficialModelUrlsAndValidUids(): void
    {
        self::assertTrue(SketchfabModule::allowedUrl('https://sketchfab.com/3d-models/sint-jan-12345678901234567890'));
        self::assertFalse(SketchfabModule::allowedUrl('https://example.test/3d-models/sint-jan-12345678901234567890'));
        self::assertFalse(SketchfabModule::allowedUrl('javascript:alert(1)'));
        self::assertTrue(SketchfabModule::validUid('12345678901234567890'));
        self::assertFalse(SketchfabModule::validUid('../unsafe'));
    }

    public function testSketchfabRuntimeIsLazyAndUsesServerValidatedEvidence(): void
    {
        $runtime = file_get_contents(dirname(__DIR__, 2) . '/assets/js/tour-navigation.js');
        $completion = file_get_contents(dirname(__DIR__, 2) . '/modules/experience-builder/Service/ModuleCompletionService.php');

        self::assertStringContainsString('static.sketchfab.com/api/sketchfab-viewer-1.12.1.js', $runtime);
        self::assertStringContainsString('IntersectionObserver', $runtime);
        self::assertStringContainsString('viewerready', $runtime);
        self::assertStringContainsString('annotationSelect', $runtime);
        self::assertStringContainsString('dnt: 1', $runtime);
        self::assertStringNotContainsString('api_token', $runtime);
        self::assertStringContainsString('invalid_module_completion_evidence', $completion);
        self::assertStringContainsString('minimum_view_time_elapsed', $completion);
        self::assertStringContainsString('array_diff($requiredAnnotations, $opened)', $completion);
    }

    public function testPhotoChallengeModuleIsAnAdapterToExistingCameraTruth(): void
    {
        $module = new AiPhotoChallengeModule();
        $definition = $module->definition();
        $normalized = $module->normalize(array(
            'content' => array('duplicated_challenge' => true),
            'settings' => array('source' => 'client'),
            'completion' => array('mode' => 'manual'),
        ));

        self::assertSame('ai_photo_challenge', $module->type());
        self::assertSame(array('photo_approved'), $definition['completion_modes']);
        self::assertSame(array(), $normalized['content']);
        self::assertSame(array('source' => 'chapter_meta'), $normalized['settings']);
        self::assertSame('photo_approved', $normalized['completion']['mode']);
    }

    public function testOnlyOnePhotoChallengeAdapterIsAllowedPerChapter(): void
    {
        $module = static fn (string $id): array => array(
            'id' => $id,
            'type' => 'ai_photo_challenge',
            'enabled' => true,
            'completion' => array('mode' => 'photo_approved'),
        );
        $result = $this->validator()->normalize(array(
            'schema_version' => 1,
            'modules' => array(
                $module('b6f14dd4-b6c4-43cf-90da-8f92fb89a77c'),
                $module('a09208ce-b2ee-4dad-bb50-a41bdb42057d'),
            ),
        ));

        self::assertContains('duplicate_singleton_module', array_column($result['errors'], 'code'));
    }

    public function testPhotoChallengeCompletionRemainsServerOwnedAndIdempotent(): void
    {
        $camera = file_get_contents(dirname(__DIR__, 2) . '/modules/discovery-camera/Service/PhotoChallengeCompletionService.php');
        $attempts = file_get_contents(dirname(__DIR__, 2) . '/modules/discovery-camera/Service/PhotoAttemptService.php');
        $controller = file_get_contents(dirname(__DIR__, 2) . '/modules/discovery-camera/Rest/Controller.php');
        $completion = file_get_contents(dirname(__DIR__, 2) . '/modules/experience-builder/Service/ModuleCompletionService.php');

        self::assertStringContainsString("firstModuleIdForType(\$stepId, 'ai_photo_challenge')", $camera);
        self::assertStringContainsString("'event' => 'photo_approved'", $camera);
        self::assertStringContainsString("'module_completion'", $camera);
        self::assertStringContainsString("'module_completion'", $attempts);
        self::assertStringContainsString('$moduleChallenge', $controller);
        self::assertStringContainsString("'invalid_module_completion_evidence'", $completion);
        self::assertStringContainsString("'photo_approved'", $completion);
        self::assertStringContainsString('bsp_photo_attempts', $completion);
        self::assertStringContainsString("status=%s", $completion);
        self::assertStringContainsString('ticket_id=%d', $completion);
        self::assertStringContainsString('user_id=%d', $completion);
        self::assertStringContainsString('INSERT IGNORE', $completion);
    }

    public function testQuizModuleSanitizesInlineAnswerTruthForServerEvaluation(): void
    {
        $module = new QuizModule();
        $normalized = $module->normalize(array(
            'content' => array(
                'pass_percentage' => 80,
                'questions' => array(array(
                    'id' => 'q1',
                    'question' => 'Waar staat de draak?',
                    'answers' => array(
                        array('id' => 'a1', 'label' => 'Links'),
                        array('id' => 'a2', 'label' => 'Rechts'),
                    ),
                    'correct_answer_ids' => array('a2', 'client_invented'),
                )),
            ),
            'settings' => array('client_scoring' => true),
            'completion' => array('mode' => 'automatic'),
        ));

        self::assertSame(array('a2'), $normalized['content']['questions'][0]['correct_answer_ids']);
        self::assertSame(80, $normalized['content']['pass_percentage']);
        self::assertSame(array('source' => 'module'), $normalized['settings']);
        self::assertSame('quiz_passed', $normalized['completion']['mode']);
    }

    public function testQuizAndRewardAreValidatedByServerServices(): void
    {
        $completion = file_get_contents(dirname(__DIR__, 2) . '/modules/experience-builder/Service/ModuleCompletionService.php');
        $reward = file_get_contents(dirname(__DIR__, 2) . '/modules/gamification/Service/ExperienceModuleRewardService.php');
        $tickets = file_get_contents(dirname(__DIR__, 2) . '/includes/class-sbdp-private-tours-tickets.php');

        self::assertStringContainsString('evaluateQuiz', $completion);
        self::assertStringContainsString("'quiz_not_passed'", $completion);
        self::assertStringContainsString('priorModulesCompleted', $completion);
        self::assertStringContainsString("'reward_prerequisites_incomplete'", $completion);
        self::assertStringContainsString('ExperienceModuleRewardService', $completion);
        self::assertStringContainsString('XpLedgerService', $reward);
        self::assertStringContainsString("'experience.module_reward'", $reward);
        self::assertStringContainsString("unset(\$quiz['questions'][\$question_index]['correct_answer_ids'])", $tickets);
        self::assertStringContainsString("unset(\$modules[\$module_index]['content']['questions'][\$question_index]['correct_answer_ids'])", $tickets);
    }

    public function testRewardIntentIsBoundedAndServerClaimed(): void
    {
        $module = new RewardModule();
        $normalized = $module->normalize(array(
            'content' => array('title' => 'Prijs', 'message' => 'Klaar', 'xp_amount' => 99999),
        ));

        self::assertSame(500, $normalized['content']['xp_amount']);
        self::assertSame('experience.module_reward', $normalized['settings']['event_type']);
        self::assertSame('server_claim', $normalized['completion']['mode']);
    }

    public function testConditionsAreAndOnlyAndMayReferenceEarlierModules(): void
    {
        $result = $this->validator()->normalize(array(
            'schema_version' => 1,
            'modules' => array(
                array(
                    'id' => 'b6f14dd4-b6c4-43cf-90da-8f92fb89a77c',
                    'type' => 'text',
                    'content' => array('html' => 'Eerst'),
                ),
                array(
                    'id' => 'a09208ce-b2ee-4dad-bb50-a41bdb42057d',
                    'type' => 'reward',
                    'conditions' => array(array(
                        'type' => 'module_completed',
                        'module_id' => 'b6f14dd4-b6c4-43cf-90da-8f92fb89a77c',
                        'value' => '1',
                    )),
                ),
            ),
        ));
        self::assertSame(array(), $result['errors']);

        $result['document']['modules'][0]['conditions'] = array(array(
            'type' => 'module_completed',
            'module_id' => 'a09208ce-b2ee-4dad-bb50-a41bdb42057d',
            'value' => '1',
        ));
        $invalid = $this->validator()->normalize($result['document']);
        self::assertContains('invalid_condition_dependency', array_column($invalid['errors'], 'code'));
    }

    public function testUnsupportedAndMalformedConditionsAreRejected(): void
    {
        $result = $this->validator()->normalize(array(
            'schema_version' => 1,
            'modules' => array(
                array('id' => 'b6f14dd4-b6c4-43cf-90da-8f92fb89a77c', 'type' => 'quiz'),
                array(
                    'id' => 'a09208ce-b2ee-4dad-bb50-a41bdb42057d',
                    'type' => 'reward',
                    'conditions' => array(
                        array('type' => 'arbitrary_code', 'module_id' => '', 'value' => ''),
                        array('type' => 'quiz_score_at_least', 'module_id' => 'b6f14dd4-b6c4-43cf-90da-8f92fb89a77c', 'value' => '101'),
                    ),
                ),
            ),
        ));

        self::assertContains('unsupported_condition', array_column($result['errors'], 'code'));
        self::assertContains('invalid_quiz_score_condition', array_column($result['errors'], 'code'));
    }

    public function testRuntimeCompletionEnforcesConditionsOnTheServer(): void
    {
        $completion = file_get_contents(dirname(__DIR__, 2) . '/modules/experience-builder/Service/ModuleCompletionService.php');

        self::assertStringContainsString('conditionsSatisfied', $completion);
        self::assertStringContainsString("'module_conditions_not_met'", $completion);
        self::assertStringContainsString("'quiz_score_at_least'", $completion);
        self::assertStringContainsString("'photo_approved'", $completion);
        self::assertStringContainsString("'access_valid'", $completion);
    }

    public function testLegacyMigrationIsExplicitBackedUpAndRollbackable(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 2) . '/modules/experience-builder/Service/LegacyMigrationService.php');
        $controller = file_get_contents(dirname(__DIR__, 2) . '/modules/experience-builder/Rest/ChapterModulesController.php');

        self::assertStringContainsString('LegacyChapterAdapter', $migration);
        self::assertStringContainsString("'legacy_preserved' => true", $migration);
        self::assertStringContainsString('migrated_document_checksum', $migration);
        self::assertStringContainsString('hash_equals', $migration);
        self::assertStringContainsString('wp_salt', $migration);
        self::assertStringContainsString('delete_post_meta($chapterId, ModuleDocumentService::META_KEY)', $migration);
        self::assertStringNotContainsString("delete_post_meta(\$chapterId, '_sbdp_step_", $migration);
        self::assertStringContainsString("'/experience-builder/chapters/(?P<chapter_id>\\d+)/migration'", $controller);
        self::assertStringContainsString("'permission_callback' => array(__CLASS__, 'authorize')", $controller);
    }

    public function testMigrationCannotOverwriteAnExistingModularDocument(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 2) . '/modules/experience-builder/Service/LegacyMigrationService.php');

        self::assertStringContainsString("'already_modular'", $migration);
        self::assertStringContainsString('! $hasStored', $migration);
        self::assertStringContainsString("'chapter_migration_conflict'", $migration);
        self::assertStringContainsString("'chapter_rollback_conflict'", $migration);
        self::assertStringContainsString('current_user_can', $migration);
    }

    public function testEnabledPhotoAdapterRequiresPublishableChallengeConfiguration(): void
    {
        $service = file_get_contents(dirname(__DIR__, 2) . '/modules/experience-builder/Service/ModuleDocumentService.php');

        self::assertStringContainsString('validateChapterAdapters', $service);
        self::assertStringContainsString('PhotoChallenge::validationErrors', $service);
        self::assertStringContainsString("'ai_photo_challenge'", $service);
        self::assertStringContainsString("empty(\$module['enabled'])", $service);
    }
}
