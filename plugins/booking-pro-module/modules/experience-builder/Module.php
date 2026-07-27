<?php

declare(strict_types=1);

namespace BSP\ExperienceBuilder;

use BSP\Core\Interfaces\ModuleInterface;
use BSP\ExperienceBuilder\Admin\ChapterBuilderMetaBox;
use BSP\ExperienceBuilder\ModuleTypes\AiPhotoChallengeModule;
use BSP\ExperienceBuilder\ModuleTypes\QuizModule;
use BSP\ExperienceBuilder\ModuleTypes\RewardModule;
use BSP\ExperienceBuilder\ModuleTypes\AudioModule;
use BSP\ExperienceBuilder\ModuleTypes\ImageModule;
use BSP\ExperienceBuilder\ModuleTypes\SketchfabModule;
use BSP\ExperienceBuilder\ModuleTypes\TextModule;
use BSP\ExperienceBuilder\ModuleTypes\VideoModule;
use BSP\ExperienceBuilder\Registry\ModuleRegistry;
use BSP\ExperienceBuilder\Rest\ChapterModulesController;
use BSP\ExperienceBuilder\Service\ModuleDocumentService;
use BSP\ExperienceBuilder\Service\ModuleValidationService;

final class Module implements ModuleInterface
{
    private static bool $booted = false;
    private static ?ModuleRegistry $registry = null;

    public function init(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        $registry = self::registry();
        $registry->register(new TextModule());
        $registry->register(new ImageModule());
        $registry->register(new AudioModule());
        $registry->register(new VideoModule());
        $registry->register(new SketchfabModule());
        $registry->register(new AiPhotoChallengeModule());
        $registry->register(new QuizModule());
        $registry->register(new RewardModule());

        do_action('sbdp/experience_builder/register_modules', $registry);
        add_action('init', array(__CLASS__, 'registerPostMeta'));
        add_action('rest_api_init', array(ChapterModulesController::class, 'register'));
        ChapterBuilderMetaBox::register();
    }

    public static function registry(): ModuleRegistry
    {
        if (self::$registry === null) {
            self::$registry = new ModuleRegistry();
        }

        return self::$registry;
    }

    public static function registerPostMeta(): void
    {
        $service = new ModuleDocumentService(new ModuleValidationService(self::registry()));
        register_post_meta(
            'sbdp_tour_step',
            ModuleDocumentService::META_KEY,
            array(
                'type' => 'object',
                'single' => true,
                'default' => array(),
                // A dedicated endpoint must call ModuleDocumentService::save() so
                // core REST cannot bypass validation and revision conflict checks.
                'show_in_rest' => false,
                'sanitize_callback' => array($service, 'sanitizeForMeta'),
                'auth_callback' => array(__CLASS__, 'canEditMeta'),
            )
        );
    }

    /** @param mixed $allowed @param mixed $metaKey @param mixed $postId */
    public static function canEditMeta($allowed, $metaKey, $postId): bool
    {
        unset($allowed, $metaKey);

        return absint($postId) > 0 && current_user_can('edit_post', absint($postId));
    }
}
