<?php

declare(strict_types=1);

namespace BSP\DiscoveryCamera\Admin;

use BSP\DiscoveryCamera\Content\PhotoChallengeMeta;
use BSP\DiscoveryCamera\Domain\PhotoChallenge;
use WP_Post;

final class PhotoChallengeMetaBox
{
    private const NONCE_ACTION = 'ddb_save_photo_challenge';
    private const NONCE_NAME = 'ddb_photo_challenge_nonce';

    public static function register(): void
    {
        add_action('add_meta_boxes_sbdp_tour_step', array(__CLASS__, 'add'));
        add_action('add_meta_boxes_sbdp_private_tour', array(__CLASS__, 'addTourLauncher'));
        add_action('save_post_sbdp_tour_step', array(__CLASS__, 'save'), 20, 2);
        add_action('admin_post_ddb_create_photo_challenge', array(__CLASS__, 'createForTour'));
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

    public static function addTourLauncher(): void
    {
        add_meta_box(
            'ddb-photo-challenge-launcher',
            __('AI Photo Challenges', 'sbdp'),
            array(__CLASS__, 'renderTourLauncher'),
            'sbdp_private_tour',
            'side',
            'high'
        );
    }

    public static function renderTourLauncher(WP_Post $tour): void
    {
        $steps = get_posts(array(
            'post_type' => 'sbdp_tour_step',
            'post_parent' => (int) $tour->ID,
            'post_status' => array('publish', 'draft', 'private'),
            'numberposts' => -1,
            'orderby' => array('menu_order' => 'ASC', 'ID' => 'ASC'),
            'meta_key' => '_sbdp_step_type',
            'meta_value' => 'photo_challenge',
        ));
        $createUrl = wp_nonce_url(
            add_query_arg(
                array('action' => 'ddb_create_photo_challenge', 'tour_id' => (int) $tour->ID),
                admin_url('admin-post.php')
            ),
            'ddb_create_photo_challenge_' . (int) $tour->ID
        );
        ?>
        <div class="ddb-photo-launcher">
            <p><?php esc_html_e('Voeg een Photo Challenge toe als normaal hoofdstuk binnen deze bestaande tour.', 'sbdp'); ?></p>
            <a class="button button-primary button-large ddb-photo-launcher__create" href="<?php echo esc_url($createUrl); ?>">
                <?php esc_html_e('Nieuwe foto-opdracht', 'sbdp'); ?>
            </a>
            <?php if ($steps) : ?>
                <hr>
                <strong><?php esc_html_e('Foto-opdrachten in deze tour', 'sbdp'); ?></strong>
                <ul class="ddb-photo-launcher__list">
                    <?php foreach ($steps as $step) : ?>
                        <li>
                            <a href="<?php echo esc_url(get_edit_post_link((int) $step->ID, '')); ?>">
                                <?php echo esc_html(get_the_title($step) ?: __('Naamloze foto-opdracht', 'sbdp')); ?>
                            </a>
                            <small><?php echo esc_html((string) get_post_status($step)); ?></small>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function createForTour(): void
    {
        $tourId = isset($_GET['tour_id']) ? absint($_GET['tour_id']) : 0;
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

        $stepId = wp_insert_post(array(
            'post_type' => 'sbdp_tour_step',
            'post_status' => 'draft',
            'post_parent' => $tourId,
            'menu_order' => $highestOrder + 1,
            'post_title' => __('Nieuwe foto-opdracht', 'sbdp'),
            'post_content' => '',
        ), true);
        if (is_wp_error($stepId)) {
            wp_die(esc_html($stepId->get_error_message()), '', array('response' => 500));
        }

        update_post_meta((int) $stepId, '_sbdp_step_type', 'photo_challenge');
        update_post_meta((int) $stepId, PhotoChallengeMeta::KEY, PhotoChallenge::sanitize(array(
            'revision' => 1,
            'title' => __('Nieuwe foto-opdracht', 'sbdp'),
            'mission' => __('Beschrijf hier wat de bezoeker moet fotograferen.', 'sbdp'),
            'required_object' => array('type' => 'object', 'label' => __('object', 'sbdp')),
            'validation_type' => array('composition'),
            'difficulty' => 'medium',
            'pass_score' => 70,
            'xp_reward' => 0,
        )));

        wp_safe_redirect(add_query_arg(
            array('post' => (int) $stepId, 'action' => 'edit', 'ddb_photo_created' => 1),
            admin_url('post.php')
        ));
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
    }

    public static function render(WP_Post $post): void
    {
        $challenge = PhotoChallengeMeta::forStep((int) $post->ID);
        $types = PhotoChallenge::validationTypes();
        $selectedTypes = (array) ($challenge['validation_type'] ?? array());
        $hints = (array) ($challenge['hints'] ?? array('', '', ''));

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        ?>
        <div class="ddb-photo-builder" data-photo-challenge-builder>
            <p class="description">
                <?php esc_html_e('Wordt alleen actief wanneer Step type op “Photo Challenge” staat. De fake provider geeft op staging altijd menselijke review terug.', 'sbdp'); ?>
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
                <?php self::numberField('voice_attachment_id', __('Voice intro attachment-ID', 'sbdp'), (int) ($challenge['voice_intro']['attachment_id'] ?? 0), 0, PHP_INT_MAX); ?>
            </div>

            <?php self::textareaField('mission', __('Missie', 'sbdp'), $challenge['mission'] ?? '', true); ?>
            <?php self::textareaField('historical_context', __('Historische context', 'sbdp'), $challenge['historical_context'] ?? ''); ?>
            <?php self::textareaField('voice_transcript', __('Voice intro transcript', 'sbdp'), $challenge['voice_intro']['transcript'] ?? ''); ?>

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
                <span><?php esc_html_e('Communitypublicatie later toestaan (nog niet actief)', 'sbdp'); ?></span>
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
