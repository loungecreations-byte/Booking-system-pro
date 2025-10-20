<?php

declare(strict_types=1);

namespace BSP\ActivityCtaBlock;

use BSP\Core\Interfaces\ModuleInterface;

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

final class Module implements ModuleInterface
{
    /**
     * Track contexts already logged within the current request.
     *
     * @var array<string, array<int, bool>>
     */
    private array $logged = array();

    public function init(): void
    {
        if (\function_exists('add_action')) {
            \add_action('init', array($this, 'registerShortcode'));
            \add_action('wp_enqueue_scripts', array($this, 'enqueueAssets'));
            \add_action('ddb_cta_block', array($this, 'outputCtaViaAction'), 10, 1);
            \add_action('woocommerce_single_product_summary', array($this, 'renderForWooCommerce'), 35);
        }

        if (\function_exists('add_filter')) {
            \add_filter('the_content', array($this, 'appendToContent'), 50);
            \add_filter('elementor/frontend/the_content', array($this, 'appendToContent'), 50);
        }
    }

    public function registerShortcode(): void
    {
        if (! \function_exists('add_shortcode')) {
            return;
        }

        \add_shortcode('ddb_cta_block', array($this, 'handleShortcode'));
    }

    public function enqueueAssets(): void
    {
        if (! \function_exists('wp_enqueue_style') || ! \function_exists('wp_enqueue_script')) {
            return;
        }

        $postId = $this->resolveCurrentPostId();
        $hasShortcode = $this->currentContentHasShortcode();

        if ($postId === 0 && ! $hasShortcode) {
            return;
        }

        if ($postId > 0 && ! $this->shouldHandlePost($postId) && ! $hasShortcode) {
            return;
        }

        $this->ensureAssetsEnqueued();
    }

    private function ensureAssetsEnqueued(): void
    {
        if (! \function_exists('wp_enqueue_style') || ! \function_exists('wp_enqueue_script')) {
            return;
        }

        if (\function_exists('wp_register_style')) {
            \wp_register_style(
                'ddb-cta-block',
                SBDP_URL . 'assets/css/activity-cta-block.css',
                array(),
                SBDP_VER
            );
        }

        \wp_enqueue_style('ddb-cta-block');

        if (\function_exists('wp_register_script')) {
            \wp_register_script(
                'ddb-cta-block',
                SBDP_URL . 'assets/js/activity-cta-block.js',
                array(),
                SBDP_VER,
                true
            );
        }

        \wp_enqueue_script('ddb-cta-block');

        \wp_localize_script(
            'ddb-cta-block',
            'DDB_CTA',
            array(
                'addedLabel'               => __('Toegevoegd aan Plan je dag', 'sbdp'),
                'selectTimeLabel'          => __('Selecteer een starttijd', 'sbdp'),
                'selectParticipantsLabel'  => __('Selecteer aantal personen', 'sbdp'),
                'noSlotsLabel'             => __('Geen tijdsloten beschikbaar', 'sbdp'),
                'noCapacityLabel'          => __('Geen capaciteit beschikbaar', 'sbdp'),
            )
        );
    }

    public function appendToContent(string $content): string
    {
        if ((\function_exists('is_admin') && \is_admin()) || (\function_exists('is_feed') && \is_feed())) {
            return $content;
        }

        if (\function_exists('is_singular') && ! \is_singular()) {
            return $content;
        }

        if (\function_exists('in_the_loop') && ! \in_the_loop()) {
            return $content;
        }

        if (\function_exists('is_main_query') && ! \is_main_query()) {
            return $content;
        }

        $postId = $this->resolveCurrentPostId();

        if ($postId === 0 || ! $this->shouldHandlePost($postId)) {
            return $content;
        }

        if (\function_exists('is_product') && \is_product()) {
            return $content;
        }

        if ($this->contentAlreadyHasCta($content)) {
            return $content;
        }

        if (! $this->shouldAutoAppend($postId, 'the_content')) {
            return $content;
        }

        $markup = $this->buildMarkup($postId, 'the_content');

        if ($markup === '') {
            return $content;
        }

        return $content . $markup;
    }

    /**
     * Shortcode callback.
     *
     * @param array<string, mixed> $atts Shortcode attributes.
     */
    public function handleShortcode(array $atts = array()): string
    {
        $normalised   = $this->normaliseShortcodeAttributes($atts);
        $postId       = $normalised['post_id'];
        $forced       = $normalised['force'] || $normalised['show_inputs'];
        $showInputs   = $normalised['show_inputs'];

        if ($postId <= 0) {
            $postId = $this->resolveCurrentPostId();
        }

        if ($postId === 0) {
            return '';
        }

        if (! $forced && ! $this->shouldHandlePost($postId) && ! $this->currentContentHasShortcode()) {
            return '';
        }

        if ($forced) {
            $this->ensureAssetsEnqueued();
        }

        $context = $showInputs ? 'woocommerce' : 'shortcode';

        return $this->buildMarkup($postId, $context);
    }

    /**
     * Action hook entry point.
     */
    public function outputCtaViaAction(?int $postId = null): void
    {
        $postId = $postId !== null ? (int) $postId : $this->resolveCurrentPostId();
        if ($postId === 0 || ! $this->shouldHandlePost($postId)) {
            return;
        }

        echo $this->buildMarkup($postId, 'action'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function renderForWooCommerce(): void
    {
        if (! \function_exists('is_product') || ! \is_product()) {
            return;
        }

        $postId = $this->resolveCurrentPostId();
        if ($postId === 0 || ! $this->shouldHandlePost($postId)) {
            return;
        }

        echo $this->buildMarkup($postId, 'woocommerce'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    private function resolveCurrentPostId(): int
    {
        $postId = 0;

        if (\function_exists('get_the_ID')) {
            $postId = (int) \get_the_ID();
            if ($postId > 0) {
                return $postId;
            }
        }

        if (\function_exists('get_queried_object_id')) {
            $queried = (int) \get_queried_object_id();
            if ($queried > 0) {
                return $queried;
            }
        }

        return 0;
    }

    private function shouldHandlePost(int $postId): bool
    {
        if (! \function_exists('get_post_type')) {
            return false;
        }

        $postType = \get_post_type($postId);
        if (! is_string($postType) || $postType === '') {
            return false;
        }

        $targets = $this->getTargetPostTypes();

        return in_array($postType, $targets, true);
    }

    /**
     * @return array<int, string>
     */
    private function getTargetPostTypes(): array
    {
        $defaults = array('activity');

        if (\function_exists('post_type_exists') && \post_type_exists('product')) {
            $defaults[] = 'product';
        }

        /**
         * Filter the list of post types that should receive the CTA block.
         *
         * @param array<int, string> $defaults Default list of post type slugs.
         */
        $filtered = \apply_filters('ddb_cta_target_post_types', $defaults);

        if (! is_array($filtered)) {
            return array_values(array_unique($defaults));
        }

        $normalised = array();

        foreach ($filtered as $postType) {
            if (! is_string($postType)) {
                continue;
            }

            $postType = trim($postType);
            if ($postType === '') {
                continue;
            }

            $normalised[] = $postType;
        }

        if ($normalised === array()) {
            $normalised = $defaults;
        }

        return array_values(array_unique($normalised));
    }

    private function contentAlreadyHasCta(string $content): bool
    {
        if (strpos($content, 'ddb-cta-block') !== false) {
            return true;
        }

        if (\function_exists('has_shortcode') && \has_shortcode($content, 'ddb_cta_block')) {
            return true;
        }

        return false;
    }

    private function shouldAutoAppend(int $postId, string $context): bool
    {
        unset($context);

        /**
         * Filter whether the CTA should auto-append to standard content output.
         *
         * @param bool $autoAppend Default false, auto-injection disabled unless explicitly enabled.
         * @param int  $postId     Current post identifier.
         */
        $autoAppend = \apply_filters('ddb_cta_auto_append', false, $postId);

        return (bool) $autoAppend;
    }

    private function buildMarkup(int $postId, string $context): string
    {
        $permalink = \function_exists('get_permalink') ? \get_permalink($postId) : '';
        if (! is_string($permalink) || $permalink === '') {
            return '';
        }

        $title = \function_exists('get_the_title') ? \get_the_title($postId) : '';
        if (! is_string($title) || $title === '') {
            $title = __('Onbekende activiteit', 'sbdp');
        }

        $buttonLabel = __('+ Voeg toe aan Plan je dag', 'sbdp');
        $addedLabel  = __('Toegevoegd aan Plan je dag', 'sbdp');

        $bookingUrl = $this->buildBookingUrl($permalink, $postId);
        $contactUrl = $this->buildContactUrl($title, $postId);

        $sanitisedTitle = $this->sanitizeTitle($title);
        $requiresDetails = $context === 'woocommerce' ? '1' : '0';

        $dateLabel         = __('Datum', 'sbdp');
        $timeLabel         = __('Tijd', 'sbdp');
        $participantsLabel = __('Deelnemers', 'sbdp');
        $participantSingle = __('persoon', 'sbdp');
        $participantPlural = __('personen', 'sbdp');

        $attrContext            = $this->escAttr($context);
        $attrRequiresDetails    = $this->escAttr($requiresDetails);
        $attrParticipantSingle  = $this->escAttr($participantSingle);
        $attrParticipantPlural  = $this->escAttr($participantPlural);
        $attrTitle              = $this->escAttr($sanitisedTitle);
        $attrPermalink          = $this->escUrl($permalink);
        $attrBooking            = $this->escUrl($bookingUrl);
        $attrContact            = $this->escUrl($contactUrl);
        $plannerUrl             = $this->getPlannerUrl();
        $attrPlanner            = $this->escAttr($plannerUrl !== '' ? $this->escUrl($plannerUrl) : '');
        $attrAddedLabel         = $this->escAttr($addedLabel);
        $attrDefaultLabel       = $this->escAttr($buttonLabel);
        $buttonText             = $this->escHtml($buttonLabel);
        $directText             = $this->escHtml(__('Direct boeken', 'sbdp'));
        $infoText               = $this->escHtml(__('Doe een aanvraag', 'sbdp'));

        $attrDateLabel         = $this->escAttr($dateLabel);
        $attrTimeLabel         = $this->escAttr($timeLabel);
        $attrParticipantsLabel = $this->escAttr($participantsLabel);
        $dateLabelText         = $this->escHtml($dateLabel);
        $timeLabelText         = $this->escHtml($timeLabel);
        $participantsLabelText = $this->escHtml($participantsLabel);

        if ($requiresDetails === '1') {
            $detailsMarkup = sprintf(
                '<div class="ddb-cta-details" data-ddb-cta-details data-requires-inputs="1">
                    <label class="ddb-cta-details__field">
                        <span class="ddb-cta-details__label">%1$s</span>
                        <input type="date" class="ddb-cta-details__input" data-ddb-cta-input="date" />
                    </label>
                    <label class="ddb-cta-details__field">
                        <span class="ddb-cta-details__label">%2$s</span>
                        <select class="ddb-cta-details__input" data-ddb-cta-select="time">
                            <option value="">%4$s</option>
                        </select>
                    </label>
                    <label class="ddb-cta-details__field">
                        <span class="ddb-cta-details__label">%3$s</span>
                        <select class="ddb-cta-details__input" data-ddb-cta-select="participants">
                            <option value="">%5$s</option>
                        </select>
                    </label>
                </div>',
                $dateLabelText,
                $timeLabelText,
                $participantsLabelText,
                $this->escHtml(__('Selecteer een starttijd', 'sbdp')),
                $this->escHtml(__('Selecteer aantal personen', 'sbdp'))
            );
        } else {
            $detailsMarkup = sprintf(
                '<div class="ddb-cta-details" data-ddb-cta-details data-label-date="%1$s" data-label-time="%2$s" data-label-participants="%3$s" hidden>
                    <span class="ddb-cta-details__item">
                        <span class="ddb-cta-details__label">%4$s</span>
                        <span class="ddb-cta-details__value" data-ddb-cta-date>&mdash;</span>
                    </span>
                    <span class="ddb-cta-details__item">
                        <span class="ddb-cta-details__label">%5$s</span>
                        <span class="ddb-cta-details__value" data-ddb-cta-time>&mdash;</span>
                    </span>
                    <span class="ddb-cta-details__item">
                        <span class="ddb-cta-details__label">%6$s</span>
                        <span class="ddb-cta-details__value" data-ddb-cta-participants>&mdash;</span>
                    </span>
                </div>',
                $attrDateLabel,
                $attrTimeLabel,
                $attrParticipantsLabel,
                $dateLabelText,
                $timeLabelText,
                $participantsLabelText
            );
        }

        $actionsMarkup = sprintf(
            '<div class="ddb-cta-actions">
                <button class="ddb-add-to-plan" data-activity-id="%1$d" data-activity-title="%2$s" data-activity-url="%3$s" data-added-label="%4$s" data-default-label="%5$s" data-planner-url="%11$s" aria-pressed="false">%6$s</button>
                <a class="ddb-direct-book" href="%7$s">%8$s</a>
                <a class="ddb-info" href="%9$s">%10$s</a>
            </div>',
            $postId,
            $attrTitle,
            $attrPermalink,
            $attrAddedLabel,
            $attrDefaultLabel,
            $buttonText,
            $attrBooking,
            $directText,
            $attrContact,
            $infoText,
            $attrPlanner
        );

        $markup = sprintf(
            '<div class="ddb-cta-block" data-cta-context="%1$s" data-requires-details="%2$s" data-label-participant-single="%3$s" data-label-participant-plural="%4$s">%5$s%6$s</div>',
            $attrContext,
            $attrRequiresDetails,
            $attrParticipantSingle,
            $attrParticipantPlural,
            $detailsMarkup,
            $actionsMarkup
        );

        $this->logInjection($postId, $context);

        return $markup;
    }

    private function buildBookingUrl(string $permalink, int $postId): string
    {
        $url = \function_exists('add_query_arg')
            ? (string) \add_query_arg('action', 'book', $permalink)
            : $permalink;

        /**
         * Filter the booking URL for the CTA block.
         *
         * @param string $url      Booking URL.
         * @param int    $postId   Post identifier.
         * @param string $original Original permalink.
         */
        return (string) \apply_filters('ddb_cta_booking_url', $url, $postId, $permalink);
    }

    private function buildContactUrl(string $title, int $postId): string
    {
        $base = '/contact';
        $url  = \function_exists('add_query_arg')
            ? (string) \add_query_arg('subject', rawurlencode($title), $base)
            : $base . '?subject=' . rawurlencode($title);

        /**
         * Filter the contact URL for the CTA block.
         *
         * @param string $url   Contact URL.
         * @param int    $postId Post identifier.
         * @param string $title Activity title.
         */
        return (string) \apply_filters('ddb_cta_contact_url', $url, $postId, $title);
    }

    private function getPlannerUrl(): string
    {
        $pageId = (int) \get_option('sbdp_planner_page_id', 0);
        if ($pageId > 0) {
            $link = \get_permalink($pageId);
            if (is_string($link) && $link !== '') {
                return $link;
            }
        }

        $page = \get_page_by_path('plan-je-dag');
        if ($page instanceof \WP_Post) {
            $link = \get_permalink($page);
            if (is_string($link) && $link !== '') {
                return $link;
            }
        }

        return '';
    }

    private function logInjection(int $postId, string $context): void
    {
        if (isset($this->logged[$context][$postId])) {
            return;
        }

        $this->logged[$context][$postId] = true;

        $logDir = SBDP_DIR . 'logs';
        $logFile = $logDir . DIRECTORY_SEPARATOR . 'cta_injection.log';

        if (! is_dir($logDir) && \function_exists('wp_mkdir_p')) {
            \wp_mkdir_p($logDir);
        }

        $permalink = \function_exists('get_permalink') ? \get_permalink($postId) : '';
        $entry = sprintf(
            "[%s] context=%s post_id=%d permalink=%s",
            gmdate('c'),
            $context,
            $postId,
            is_string($permalink) ? $permalink : ''
        );

        \file_put_contents($logFile, $entry . PHP_EOL, FILE_APPEND);
    }

    /**
     * @param array<string, mixed> $atts
     *
     * @return array{post_id:int,force:bool,show_inputs:bool}
     */
    private function normaliseShortcodeAttributes(array $atts): array
    {
        $postId = 0;
        if (isset($atts['post_id'])) {
            $postId = (int) $atts['post_id'];
        }

        $force = false;
        if (isset($atts['force'])) {
            $raw = $atts['force'];
            if (is_string($raw)) {
                $force = $raw === '1' || $raw === 'true' || $raw === 'yes' || $raw === 'force';
            } else {
                $force = (bool) $raw;
            }
        }

        $showInputs = false;
        if (isset($atts['mode'])) {
            $mode = strtolower(trim((string) $atts['mode']));
            if (in_array($mode, array('product', 'woocommerce', 'inputs', 'detailed'), true)) {
                $showInputs = true;
            }
        }

        if (! $showInputs && isset($atts['details'])) {
            $rawDetails = $atts['details'];
            if (is_string($rawDetails)) {
                $rawDetails = strtolower(trim($rawDetails));
                $showInputs = in_array($rawDetails, array('1', 'true', 'yes', 'all', 'inputs', 'detailed'), true);
            } else {
                $showInputs = (bool) $rawDetails;
            }
        }

        if (! $showInputs && isset($atts['show_inputs'])) {
            $rawInput = $atts['show_inputs'];
            if (is_string($rawInput)) {
                $value = filter_var($rawInput, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($value === null) {
                    $showInputs = in_array(strtolower(trim($rawInput)), array('1', 'true', 'yes', 'on', 'product', 'inputs', 'detailed'), true);
                } else {
                    $showInputs = (bool) $value;
                }
            } else {
                $showInputs = (bool) $rawInput;
            }
        }

        return array(
            'post_id'     => $postId,
            'force'       => $force,
            'show_inputs' => $showInputs,
        );
    }

    private function currentContentHasShortcode(): bool
    {
        if (! \function_exists('is_singular') || ! \is_singular()) {
            return false;
        }

        if (! \function_exists('get_post') || ! \function_exists('has_shortcode')) {
            return false;
        }

        $post = \get_post();
        if (! $post || ! isset($post->post_content)) {
            return false;
        }

        return \has_shortcode((string) $post->post_content, 'ddb_cta_block');
    }

    private function sanitizeTitle(string $title): string
    {
        if (\function_exists('wp_strip_all_tags')) {
            return (string) \wp_strip_all_tags($title);
        }

        return trim(strip_tags($title));
    }

    private function escAttr(string $value): string
    {
        if (\function_exists('esc_attr')) {
            return (string) \esc_attr($value);
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function escUrl(string $value): string
    {
        if (\function_exists('esc_url')) {
            return (string) \esc_url($value);
        }

        return filter_var($value, FILTER_SANITIZE_URL) ?: '';
    }

    private function escHtml(string $value): string
    {
        if (\function_exists('esc_html')) {
            return (string) \esc_html($value);
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if (! \class_exists('BSPModule\\ActivityCtaBlock\\Module', false)) {
    \class_alias(Module::class, 'BSPModule\\ActivityCtaBlock\\Module');
}

// phpcs:enable PSR1.Files.SideEffects.FoundWithSymbols
