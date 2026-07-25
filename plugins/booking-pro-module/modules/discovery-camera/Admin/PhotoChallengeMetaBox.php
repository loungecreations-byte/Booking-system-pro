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
        add_action('save_post_sbdp_tour_step', array(__CLASS__, 'save'), 20, 2);
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

    public static function enqueue(string $hook): void
    {
        if (! in_array($hook, array('post.php', 'post-new.php'), true)) {
            return;
        }
        $screen = get_current_screen();
        if (! $screen || $screen->post_type !== 'sbdp_tour_step') {
            return;
        }

        $file = dirname(__DIR__) . '/assets/admin.css';
        wp_enqueue_style(
            'ddb-discovery-camera-admin',
            SBDP_URL . 'modules/discovery-camera/assets/admin.css',
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
