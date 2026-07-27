<?php

declare(strict_types=1);

namespace BSP\DiscoveryCamera\Admin;

use BSP\DiscoveryCamera\Content\PhotoChallengeMeta;
use BSP\DiscoveryCamera\Domain\PhotoChallenge;
use BSP\DiscoveryCamera\Provider\OpenAiVisionProvider;
use BSP\ExperienceBuilder\Module as ExperienceBuilderModule;
use BSP\ExperienceBuilder\Service\ModuleDocumentService;
use BSP\ExperienceBuilder\Service\ModuleValidationService;
use WP_Post;

final class PhotoChallengeMetaBox
{
    private const NONCE_ACTION = 'ddb_save_photo_challenge';
    private const NONCE_NAME = 'ddb_photo_challenge_nonce';

    public static function register(): void
    {
        add_action('add_meta_boxes_sbdp_tour_step', array(__CLASS__, 'add'));
        add_action('save_post_sbdp_tour_step', array(__CLASS__, 'save'), 20, 2);
        add_action('admin_post_ddb_create_photo_challenge', array(__CLASS__, 'createForTour'));
        add_action('admin_post_ddb_test_photo_challenge', array(__CLASS__, 'testChallenge'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
    }

    public static function add(): void
    {
        add_meta_box(
            'ddb-photo-challenge',
            __('AI Discovery Camera', 'sbdp'),
            array(__CLASS__, 'render'),
            'sbdp_tour_step',
            'normal',
            'high'
        );
    }

    public static function createForTour(): void
    {
        $tourId = isset($_GET['tour_id']) ? absint($_GET['tour_id']) : 0;
        $preset = sanitize_key((string) ($_GET['preset'] ?? 'blank'));
        $allowedPresets = array('blank', 'story', 'audio', 'video', 'sketchfab', 'quiz', 'discovery');
        if (! in_array($preset, $allowedPresets, true)) {
            $preset = 'blank';
        }
        if (
            $tourId <= 0
            || get_post_type($tourId) !== 'sbdp_private_tour'
            || ! current_user_can('edit_post', $tourId)
        ) {
            wp_die(
                esc_html__('Je mag aan deze tour geen foto-opdracht toevoegen.', 'sbdp'),
                '',
                array('response' => 403)
            );
        }
        check_admin_referer('ddb_create_photo_challenge_' . $tourId);

        $orders = get_posts(array(
            'post_type' => 'sbdp_tour_step',
            'post_parent' => $tourId,
            'post_status' => array('publish', 'draft', 'private'),
            'numberposts' => -1,
            'fields' => 'ids',
            'orderby' => 'menu_order',
            'order' => 'DESC',
        ));
        $highestOrder = 0;
        foreach ($orders as $stepId) {
            $step = get_post((int) $stepId);
            $highestOrder = max($highestOrder, $step ? (int) $step->menu_order : 0);
        }

        $titles = array(
            'blank' => __('Nieuwe interactieve stap', 'sbdp'),
            'story' => __('Nieuw verhaal', 'sbdp'),
            'audio' => __('Nieuw audiohoofdstuk', 'sbdp'),
            'video' => __('Nieuw videohoofdstuk', 'sbdp'),
            'sketchfab' => __('Nieuwe 3D-beleving', 'sbdp'),
            'quiz' => __('Nieuwe quizopdracht', 'sbdp'),
            'discovery' => __('Nieuwe AI Discovery-opdracht', 'sbdp'),
        );
        $stepTitle = $titles[$preset];
        $stepId = wp_insert_post(array(
            'post_type' => 'sbdp_tour_step',
            'post_status' => 'draft',
            'post_parent' => $tourId,
            'menu_order' => $highestOrder + 1,
            'post_title' => $stepTitle,
            'post_content' => '',
        ), true);
        if (is_wp_error($stepId)) {
            wp_die(esc_html($stepId->get_error_message()), '', array('response' => 500));
        }

        update_post_meta((int) $stepId, '_sbdp_step_type', $preset === 'discovery' ? 'photo_challenge' : 'text');
        $document = self::presetDocument((int) $stepId, $preset);
        $service = new ModuleDocumentService(new ModuleValidationService(ExperienceBuilderModule::registry()));
        $saved = $service->save((int) $stepId, $document, 0);
        if (is_wp_error($saved)) {
            wp_delete_post((int) $stepId, true);
            wp_die(esc_html($saved->get_error_message()), '', array('response' => 500));
        }

        if ($preset === 'discovery') {
            update_post_meta((int) $stepId, PhotoChallengeMeta::KEY, PhotoChallenge::sanitize(array(
                'revision' => 1,
                'title' => $stepTitle,
                'mission' => __('Beschrijf hier wat de bezoeker moet fotograferen.', 'sbdp'),
                'required_object' => array('type' => 'object', 'label' => __('object', 'sbdp')),
                'validation_type' => array('composition'),
                'difficulty' => 'medium',
                'pass_score' => 70,
                'xp_reward' => 0,
            )));
        }

        wp_safe_redirect(add_query_arg(
            array('post' => (int) $stepId, 'action' => 'edit', 'ddb_photo_created' => 1),
            admin_url('post.php')
        ));
        exit;
    }

    /** @return array<string,mixed> */
    private static function presetDocument(int $stepId, string $preset): array
    {
        $types = array(
            'blank' => array(),
            'story' => array('text'),
            'audio' => array('text', 'audio'),
            'video' => array('text', 'video'),
            'sketchfab' => array('text', 'sketchfab'),
            'quiz' => array('text', 'quiz', 'reward'),
            'discovery' => array('text', 'ai_photo_challenge', 'reward'),
        );
        $modules = array();
        foreach ($types[$preset] ?? array() as $index => $type) {
            $moduleId = wp_generate_uuid4();
            $previousId = $index > 0 ? (string) ($modules[$index - 1]['id'] ?? '') : '';
            $module = array(
                'id' => $moduleId,
                'type' => $type,
                'version' => 1,
                'index' => $index,
                'enabled' => ! in_array($type, array('image', 'audio', 'video', 'sketchfab'), true),
                'title' => ucfirst(str_replace('_', ' ', $type)),
                'settings' => array(),
                'content' => array(),
                'conditions' => array(),
                'completion' => array('mode' => 'automatic', 'requirements' => array()),
                'visibility' => array('mode' => 'when_conditions_match'),
                'metadata' => array('source' => 'chapter_preset', 'preset' => $preset),
            );
            if ($type === 'ai_photo_challenge') {
                $module['settings'] = array('source' => 'chapter_meta');
                $module['completion']['mode'] = 'photo_approved';
            } elseif ($type === 'sketchfab') {
                $module['completion']['mode'] = 'manual';
            } elseif ($type === 'quiz') {
                $module['settings'] = array('source' => 'chapter_meta');
                $module['completion']['mode'] = 'quiz_passed';
            } elseif ($type === 'reward') {
                $module['settings'] = array('event_type' => 'experience.module_reward');
                $module['content'] = array(
                    'title' => __('Beloning ontgrendeld', 'sbdp'),
                    'message' => __('Je hebt alle onderdelen voltooid.', 'sbdp'),
                    'xp_amount' => 0,
                );
                $module['completion']['mode'] = 'server_claim';
            } elseif ($type === 'text') {
                $module['content'] = array('html' => '');
            } else {
                $module['content'] = array('attachment_id' => 0, 'url' => '');
            }
            if ($previousId !== '') {
                $module['conditions'][] = array(
                    'type' => 'module_completed',
                    'module_id' => $previousId,
                    'operator' => 'is',
                    'value' => '1',
                );
            }
            $modules[] = $module;
        }

        return array(
            'schema_version' => 1,
            'document_id' => 'chapter-' . $stepId . '-modules',
            'revision' => 1,
            'modules' => $modules,
        );
    }

    public static function testChallenge(): void
    {
        $stepId = absint($_GET['step_id'] ?? 0);
        if ($stepId <= 0 || get_post_type($stepId) !== 'sbdp_tour_step' || ! current_user_can('edit_post', $stepId)) {
            wp_die(esc_html__('Geen toegang tot deze foto-opdracht.', 'sbdp'), '', array('response' => 403));
        }
        check_admin_referer('ddb_test_photo_challenge_' . $stepId);
        $challenge = PhotoChallengeMeta::forStep($stepId);
        $referenceId = absint($challenge['reference_image_id'] ?? 0);
        $path = $referenceId > 0 ? get_attached_file($referenceId) : '';
        try {
            if (! is_string($path) || ! is_readable($path)) {
                throw new \RuntimeException('Selecteer eerst een voorbeeldfoto en sla de opdracht op.');
            }
            $result = (new OpenAiVisionProvider())->analyze($challenge, $path);
            set_transient('ddb_photo_test_' . get_current_user_id() . '_' . $stepId, array('ok' => true, 'result' => $result), 10 * MINUTE_IN_SECONDS);
        } catch (\Throwable $error) {
            set_transient('ddb_photo_test_' . get_current_user_id() . '_' . $stepId, array('ok' => false, 'message' => sanitize_text_field($error->getMessage())), 10 * MINUTE_IN_SECONDS);
        }
        wp_safe_redirect(add_query_arg(array('post' => $stepId, 'action' => 'edit', 'ddb_photo_tested' => 1), admin_url('post.php')));
        exit;
    }

    public static function enqueue(string $hook): void
    {
        if (! in_array($hook, array('post.php', 'post-new.php'), true)) {
            return;
        }
        $screen = get_current_screen();
        if (! $screen || ! in_array($screen->post_type, array('sbdp_tour_step', 'sbdp_private_tour'), true)) {
            return;
        }

        $file = dirname(__DIR__) . '/Assets/admin.css';
        wp_enqueue_style(
            'ddb-discovery-camera-admin',
            SBDP_URL . 'modules/discovery-camera/Assets/admin.css',
            array(),
            is_readable($file) ? (string) filemtime($file) : SBDP_VERSION
        );

        if ($screen->post_type !== 'sbdp_private_tour') {
            if ($screen->post_type === 'sbdp_tour_step') {
                wp_enqueue_media();
                $builderFile = dirname(__DIR__) . '/Assets/photo-builder.js';
                wp_enqueue_script(
                    'ddb-photo-builder',
                    SBDP_URL . 'modules/discovery-camera/Assets/photo-builder.js',
                    array('jquery'),
                    is_readable($builderFile) ? (string) filemtime($builderFile) : SBDP_VERSION,
                    true
                );
            }
            return;
        }

        $tourId = isset($_GET['post']) ? absint($_GET['post']) : 0;
        if ($tourId <= 0 || get_post_type($tourId) !== 'sbdp_private_tour') {
            return;
        }

        $scriptFile = dirname(__DIR__) . '/Assets/chapter-type-chooser.js';
        wp_enqueue_script(
            'ddb-photo-chapter-chooser',
            SBDP_URL . 'modules/discovery-camera/Assets/chapter-type-chooser.js',
            array('sbdp-tour-builder'),
            is_readable($scriptFile) ? (string) filemtime($scriptFile) : SBDP_VERSION,
            true
        );
        wp_localize_script('ddb-photo-chapter-chooser', 'ddbPhotoChapterChooser', array(
            'createUrl' => wp_nonce_url(
                add_query_arg(
                    array('action' => 'ddb_create_photo_challenge', 'tour_id' => $tourId),
                    admin_url('admin-post.php')
                ),
                'ddb_create_photo_challenge_' . $tourId
            ),
            'editBaseUrl' => admin_url('post.php?action=edit&post='),
        ));
    }

    public static function render(WP_Post $post): void
    {
        $challenge = PhotoChallengeMeta::forStep((int) $post->ID);
        $types = PhotoChallenge::validationTypes();
        $selectedTypes = (array) ($challenge['validation_type'] ?? array());
        $hints = (array) ($challenge['hints'] ?? array('', '', ''));

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        $testResult = get_transient('ddb_photo_test_' . get_current_user_id() . '_' . (int) $post->ID);
        if (is_array($testResult)) {
            delete_transient('ddb_photo_test_' . get_current_user_id() . '_' . (int) $post->ID);
            ?>
            <div class="notice inline <?php echo ! empty($testResult['ok']) ? 'notice-success' : 'notice-error'; ?>">
                <p>
                    <?php
                    echo ! empty($testResult['ok'])
                        ? esc_html(sprintf('AI-test gereed: %d/100 — %s', (int) ($testResult['result']['total_score'] ?? 0), (string) ($testResult['result']['feedback']['message'] ?? '')))
                        : esc_html((string) ($testResult['message'] ?? 'AI-test mislukt.'));
                    ?>
                </p>
            </div>
            <?php
        }
        ?>
        <div class="ddb-photo-builder" data-photo-challenge-builder>
            <p class="description">
                <?php esc_html_e('Actief als legacy Photo Challenge-hoofdstuk of als AI Photo Challenge-module. De fake provider geeft op staging altijd menselijke review terug.', 'sbdp'); ?>
            </p>
            <div class="ddb-photo-builder__grid">
                <?php self::textField('title', __('Titel', 'sbdp'), $challenge['title'] ?? '', true); ?>
                <?php self::textField('subtitle', __('Subtitel', 'sbdp'), $challenge['subtitle'] ?? ''); ?>
                <?php self::selectField('difficulty', __('Moeilijkheid', 'sbdp'), $challenge['difficulty'] ?? 'medium', array(
                    'easy' => __('Makkelijk', 'sbdp'),
                    'medium' => __('Gemiddeld', 'sbdp'),
                    'hard' => __('Moeilijk', 'sbdp'),
                    'legendary' => __('Legendary', 'sbdp'),
                )); ?>
                <?php self::textField('required_object_type', __('Objectcode', 'sbdp'), $challenge['required_object']['type'] ?? '', true, 'gargoyle'); ?>
                <?php self::textField('required_object_label', __('Objectnaam voor bezoeker', 'sbdp'), $challenge['required_object']['label'] ?? '', false, __('waterspuwer', 'sbdp')); ?>
                <?php self::numberField('pass_score', __('Minimale score', 'sbdp'), (int) ($challenge['pass_score'] ?? 70), 1, 100); ?>
                <?php self::numberField('xp_reward', __('XP na validatie', 'sbdp'), (int) ($challenge['xp_reward'] ?? 0), 0, 500); ?>
                <?php self::textField('badge_reward', __('Badge-slug', 'sbdp'), $challenge['badge_reward'] ?? '', false, 'architect-hunter'); ?>
                <?php self::numberField('hidden_collectible_id', __('Verborgen collectible-ID', 'sbdp'), (int) ($challenge['hidden_collectible_id'] ?? 0), 0, PHP_INT_MAX); ?>
                <?php self::numberField('reference_image_id', __('Voorbeeldfoto attachment-ID', 'sbdp'), (int) ($challenge['reference_image_id'] ?? 0), 0, PHP_INT_MAX); ?>
                <?php self::numberField('voice_attachment_id', __('Voice intro attachment-ID', 'sbdp'), (int) ($challenge['voice_intro']['attachment_id'] ?? 0), 0, PHP_INT_MAX); ?>
                <?php self::selectField('interaction_type', __('Interactietype', 'sbdp'), $challenge['interaction_type'] ?? 'photo', array(
                    'photo' => __('Photo Challenge', 'sbdp'),
                    'then_now' => __('Toen & Nu', 'sbdp'),
                    'hidden_discovery' => __('Hidden Discovery', 'sbdp'),
                    'boss' => __('Boss Battle', 'sbdp'),
                )); ?>
                <?php self::selectField('persona', __('AI-gids', 'sbdp'), $challenge['persona'] ?? 'guide', array(
                    'guide' => __('DagjeDenBosch gids', 'sbdp'),
                    'bosch' => __('Jeroen Bosch', 'sbdp'),
                    'frederik_hendrik' => __('Frederik Hendrik', 'sbdp'),
                    'chef' => __('Chef', 'sbdp'),
                )); ?>
                <?php self::textField('historical_year', __('Historisch jaar', 'sbdp'), $challenge['historical_year'] ?? '', false, '1629'); ?>
            </div>
            <div class="ddb-photo-builder__media-actions">
                <button type="button" class="button" data-ddb-select-media="image" data-ddb-target="reference_image_id">
                    <?php esc_html_e('Selecteer voorbeeldfoto', 'sbdp'); ?>
                </button>
                <button type="button" class="button" data-ddb-select-media="audio" data-ddb-target="voice_attachment_id">
                    <?php esc_html_e('Selecteer voice intro', 'sbdp'); ?>
                </button>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg(array('action' => 'ddb_test_photo_challenge', 'step_id' => (int) $post->ID), admin_url('admin-post.php')), 'ddb_test_photo_challenge_' . (int) $post->ID)); ?>">
                    <?php esc_html_e('Test AI met voorbeeldfoto', 'sbdp'); ?>
                </a>
            </div>

            <?php self::textareaField('mission', __('Missie', 'sbdp'), $challenge['mission'] ?? '', true); ?>
            <?php self::textareaField(
                'camera_alternative',
                __('Alternatieve opdracht zonder camera', 'sbdp'),
                $challenge['camera_alternative'] ?? ''
            ); ?>
            <?php self::textareaField('historical_context', __('Historische context', 'sbdp'), $challenge['historical_context'] ?? ''); ?>
            <?php self::textareaField('voice_transcript', __('Voice intro transcript', 'sbdp'), $challenge['voice_intro']['transcript'] ?? ''); ?>
            <?php self::textareaField('ai_prompt', __('Aanvullende AI-instructie', 'sbdp'), $challenge['ai_prompt'] ?? ''); ?>
            <?php
            $bossTargets = implode("\n", array_map(
                static fn (array $target): string => (int) ($target['count'] ?? 1) . ' | ' . (string) ($target['label'] ?? ''),
                (array) ($challenge['boss_targets'] ?? array())
            ));
            self::textareaField('boss_targets_text', __('Boss Battle doelen (aantal | object)', 'sbdp'), $bossTargets);
            ?>

            <fieldset class="ddb-photo-builder__types">
                <legend><?php esc_html_e('Validatietypes', 'sbdp'); ?> *</legend>
                <?php foreach ($types as $type) : ?>
                    <label>
                        <input type="checkbox" name="ddb_photo_challenge[validation_type][]" value="<?php echo esc_attr($type); ?>" <?php checked(in_array($type, $selectedTypes, true)); ?>>
                        <span><?php echo esc_html(str_replace('_', ' ', ucfirst($type))); ?></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <div class="ddb-photo-builder__hints">
                <?php for ($index = 0; $index < 3; $index++) : ?>
                    <?php self::textField('hints][' . $index, sprintf(__('Hint %d', 'sbdp'), $index + 1), $hints[$index] ?? ''); ?>
                <?php endfor; ?>
            </div>

            <label class="ddb-photo-builder__toggle">
                <input type="checkbox" name="ddb_photo_challenge[community_allowed]" value="1" <?php checked(! empty($challenge['community_allowed'])); ?>>
                <span><?php esc_html_e('Community-inzending toestaan na een geslaagde foto (publicatie pas na moderatie)', 'sbdp'); ?></span>
            </label>
            <input type="hidden" name="ddb_photo_challenge[revision]" value="<?php echo esc_attr((string) ($challenge['revision'] ?? 1)); ?>">
        </div>
        <?php
    }

    public static function save(int $postId, WP_Post $post): void
    {
        if (
            $post->post_type !== 'sbdp_tour_step'
            || wp_is_post_autosave($postId)
            || wp_is_post_revision($postId)
            || ! current_user_can('edit_post', $postId)
        ) {
            return;
        }
        $nonce = isset($_POST[self::NONCE_NAME]) ? sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])) : '';
        if ($nonce === '' || ! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return;
        }

        $raw = isset($_POST['ddb_photo_challenge']) && is_array($_POST['ddb_photo_challenge'])
            ? wp_unslash($_POST['ddb_photo_challenge'])
            : array();
        $previous = PhotoChallengeMeta::forStep($postId);
        $raw['revision'] = max(1, (int) ($previous['revision'] ?? 1)) + 1;
        $raw['required_object'] = array(
            'type' => $raw['required_object_type'] ?? '',
            'label' => $raw['required_object_label'] ?? '',
        );
        $raw['voice_intro'] = array(
            'attachment_id' => $raw['voice_attachment_id'] ?? 0,
            'transcript' => $raw['voice_transcript'] ?? '',
        );
        $raw['boss_targets'] = array();
        foreach (preg_split('/\r\n|\r|\n/', (string) ($raw['boss_targets_text'] ?? '')) as $line) {
            $parts = array_map('trim', explode('|', $line, 2));
            if (($parts[1] ?? '') !== '') {
                $raw['boss_targets'][] = array('count' => absint($parts[0] ?? 1), 'label' => $parts[1]);
            }
        }

        update_post_meta($postId, PhotoChallengeMeta::KEY, PhotoChallenge::sanitize($raw));
    }

    private static function textField(string $key, string $label, $value, bool $required = false, string $placeholder = ''): void
    {
        ?>
        <label class="ddb-photo-builder__field">
            <span><?php echo esc_html($label); ?><?php echo $required ? ' *' : ''; ?></span>
            <input type="text" name="ddb_photo_challenge[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr((string) $value); ?>" placeholder="<?php echo esc_attr($placeholder); ?>">
        </label>
        <?php
    }

    private static function numberField(string $key, string $label, int $value, int $min, int $max): void
    {
        ?>
        <label class="ddb-photo-builder__field">
            <span><?php echo esc_html($label); ?></span>
            <input type="number" name="ddb_photo_challenge[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr((string) $value); ?>" min="<?php echo esc_attr((string) $min); ?>" max="<?php echo esc_attr((string) $max); ?>">
        </label>
        <?php
    }

    private static function selectField(string $key, string $label, string $value, array $options): void
    {
        ?>
        <label class="ddb-photo-builder__field">
            <span><?php echo esc_html($label); ?></span>
            <select name="ddb_photo_challenge[<?php echo esc_attr($key); ?>]">
                <?php foreach ($options as $optionValue => $optionLabel) : ?>
                    <option value="<?php echo esc_attr($optionValue); ?>" <?php selected($value, $optionValue); ?>><?php echo esc_html($optionLabel); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php
    }

    private static function textareaField(string $key, string $label, $value, bool $required = false): void
    {
        ?>
        <label class="ddb-photo-builder__field ddb-photo-builder__field--wide">
            <span><?php echo esc_html($label); ?><?php echo $required ? ' *' : ''; ?></span>
            <textarea name="ddb_photo_challenge[<?php echo esc_attr($key); ?>]" rows="3"><?php echo esc_textarea((string) $value); ?></textarea>
        </label>
        <?php
    }
}
