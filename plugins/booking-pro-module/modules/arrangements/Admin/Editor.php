<?php

declare(strict_types=1);

namespace SBDP\Modules\Arrangements\Admin;

use SBDP\Modules\Arrangements\Domain\BookableProductLookupService;
use SBDP\Modules\Arrangements\Domain\ArrangementRepository;
use SBDP\Modules\Arrangements\Domain\ArrangementSchema;
use SBDP\Modules\Arrangements\Domain\ArrangementWorkspaceService;

use function add_action;
use function add_meta_box;
use function checked;
use function check_ajax_referer;
use function current_user_can;
use function defined;
use function delete_post_meta;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_url;
use function esc_textarea;
use function function_exists;
use function get_current_screen;
use function get_post_meta;
use function get_post_status;
use function in_array;
use function is_array;
use function is_readable;
use function plugins_url;
use function sanitize_text_field;
use function selected;
use function update_post_meta;
use function wp_create_nonce;
use function wp_enqueue_script;
use function wp_enqueue_style;
use function wp_localize_script;
use function wp_json_encode;
use function wp_nonce_field;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;
use function wp_verify_nonce;
use function wp_update_post;
use function admin_url;

final class Editor
{
    private const META_VALIDATION = '_sbdp_arrangement_workspace_validation';
    private const META_PUBLISH_BLOCKED = '_sbdp_arrangement_publish_blocked';

    public function register(): void
    {
        add_action('add_meta_boxes_' . ArrangementSchema::POST_TYPE, array($this, 'registerMetaBox'));
        add_action('save_post_' . ArrangementSchema::POST_TYPE, array($this, 'save'), 10, 3);
        add_action('admin_enqueue_scripts', array($this, 'enqueueAssets'));
        add_action('wp_ajax_sbdp_arrangement_lookup_products', array($this, 'lookupProducts'));
        add_action('wp_ajax_sbdp_arrangement_suggest_products', array($this, 'suggestProducts'));
    }

    public function registerMetaBox(): void
    {
        add_meta_box(
            'sbdp_arrangement_workspace',
            __('Arrangement workspace', 'sbdp'),
            array($this, 'render'),
            ArrangementSchema::POST_TYPE,
            'normal',
            'high'
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if (! in_array($hook, array('post.php', 'post-new.php'), true)) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (! $screen || $screen->post_type !== ArrangementSchema::POST_TYPE) {
            return;
        }

        wp_enqueue_script('jquery-ui-sortable');
        $script = plugin_dir_path(__FILE__) . '../../../assets/js/arrangements-admin.js';
        $style = plugin_dir_path(__FILE__) . '../../../assets/css/arrangements-admin.css';
        if (is_readable($script)) {
            wp_enqueue_script(
                'sbdp-arrangements-admin',
                plugins_url('../../../assets/js/arrangements-admin.js', __FILE__),
                array('jquery', 'jquery-ui-sortable'),
                (string) filemtime($script),
                true
            );
            wp_localize_script(
                'sbdp-arrangements-admin',
                'SBDP_ARRANGEMENTS_ADMIN',
                array(
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'lookupAction' => 'sbdp_arrangement_lookup_products',
                    'suggestAction' => 'sbdp_arrangement_suggest_products',
                    'lookupNonce' => wp_create_nonce('sbdp_arrangement_lookup_products'),
                    'strings' => array(
                        'searchPlaceholder' => __('Zoek bookable product', 'sbdp'),
                        'searching' => __('Zoeken...', 'sbdp'),
                        'empty' => __('Geen boekbare producten gevonden.', 'sbdp'),
                        'invalid' => __('Geen geldig Woo product gekoppeld.', 'sbdp'),
                        'clear' => __('Wis', 'sbdp'),
                        'duplicate' => __('Dupliceer', 'sbdp'),
                        'newSegment' => __('Nieuw onderdeel', 'sbdp'),
                        'noProduct' => __('Nog geen boekbaar product gekoppeld.', 'sbdp'),
                        'endTimePrefix' => __('Eindtijd', 'sbdp'),
                        'suggestionsTitle' => __('Slimme suggesties', 'sbdp'),
                        'suggestionsEmpty' => __('Kies eerst een hoofdactiviteit voor gerichte suggesties.', 'sbdp'),
                    ),
                )
            );
        }
        if (is_readable($style)) {
            wp_enqueue_style(
                'sbdp-arrangements-admin',
                plugins_url('../../../assets/css/arrangements-admin.css', __FILE__),
                array(),
                (string) filemtime($style)
            );
        }
    }

    public function render(\WP_Post $post): void
    {
        $repo = new ArrangementRepository();
        $productLookup = new BookableProductLookupService();
        $workspaceService = new ArrangementWorkspaceService();
        $arrangement = $repo->normalize($post);
        $workspace = $workspaceService->build($arrangement);
        $validation = is_array($workspace['validation'] ?? null) ? $workspace['validation'] : array('errors' => array(), 'warnings' => array());
        $summary = is_array($workspace['summary'] ?? null) ? $workspace['summary'] : array();
        $productSnapshots = $productLookup->getSnapshots(
            array_map(
                static fn ($segment): int => is_array($segment) ? (int) ($segment['linked_product_id'] ?? 0) : 0,
                is_array($arrangement['segments'] ?? null) ? $arrangement['segments'] : array()
            )
        );
        $publishBlocked = (string) get_post_meta($post->ID, self::META_PUBLISH_BLOCKED, true);
        if ($publishBlocked !== '') {
            delete_post_meta($post->ID, self::META_PUBLISH_BLOCKED);
        }

        wp_nonce_field('sbdp_arrangement_save', 'sbdp_arrangement_nonce');
        ?>
        <div class="sbdp-arrangement-admin" data-sbdp-arrangement-editor>
            <?php if ($publishBlocked !== '') : ?>
                <div class="notice notice-error inline"><p><?php echo esc_html($publishBlocked); ?></p></div>
            <?php endif; ?>
            <?php if (! empty($validation['errors'])) : ?>
                <div class="notice notice-error inline"><p><strong><?php esc_html_e('Publicatie geblokkeerd:', 'sbdp'); ?></strong> <?php echo esc_html(implode(' ', $validation['errors'])); ?></p></div>
            <?php endif; ?>
            <?php if (! empty($validation['warnings'])) : ?>
                <div class="notice notice-warning inline"><p><strong><?php esc_html_e('Let op:', 'sbdp'); ?></strong> <?php echo esc_html(implode(' ', $validation['warnings'])); ?></p></div>
            <?php endif; ?>

            <div class="sbdp-arrangement-admin__summary-strip">
                <div class="sbdp-arrangement-admin__summary-card">
                    <span class="sbdp-arrangement-admin__summary-label"><?php esc_html_e('Status', 'sbdp'); ?></span>
                    <strong><?php echo esc_html((string) ($summary['status'] ?? 'draft')); ?></strong>
                </div>
                <div class="sbdp-arrangement-admin__summary-card">
                    <span class="sbdp-arrangement-admin__summary-label"><?php esc_html_e('Hoofdactiviteit', 'sbdp'); ?></span>
                    <strong><?php echo esc_html((string) ($summary['anchor_title'] ?? '')); ?></strong>
                </div>
                <div class="sbdp-arrangement-admin__summary-card">
                    <span class="sbdp-arrangement-admin__summary-label"><?php esc_html_e('Planner', 'sbdp'); ?></span>
                    <strong><?php echo esc_html((string) ($summary['planner_window'] ?? '')); ?></strong>
                </div>
                <div class="sbdp-arrangement-admin__summary-card">
                    <span class="sbdp-arrangement-admin__summary-label"><?php esc_html_e('Woo sales product', 'sbdp'); ?></span>
                    <strong>#<?php echo esc_html((string) ((int) ($arrangement['sales_product_id'] ?? 0))); ?></strong>
                </div>
            </div>

            <div class="sbdp-arrangement-admin__tabs" role="tablist">
                <button type="button" class="button button-secondary is-active" data-tab="basis"><?php esc_html_e('Basis', 'sbdp'); ?></button>
                <button type="button" class="button button-secondary" data-tab="onderdelen"><?php esc_html_e('Onderdelen', 'sbdp'); ?></button>
                <button type="button" class="button button-secondary" data-tab="planning"><?php esc_html_e('Planning', 'sbdp'); ?></button>
                <button type="button" class="button button-secondary" data-tab="prijs"><?php esc_html_e('Prijs', 'sbdp'); ?></button>
                <button type="button" class="button button-secondary" data-tab="beschikbaarheid"><?php esc_html_e('Beschikbaarheid', 'sbdp'); ?></button>
                <button type="button" class="button button-secondary" data-tab="frontend"><?php esc_html_e('Frontend', 'sbdp'); ?></button>
                <button type="button" class="button button-secondary" data-tab="samenvatting"><?php esc_html_e('Samenvatting', 'sbdp'); ?></button>
            </div>

            <section class="sbdp-arrangement-admin__panel is-active" data-panel="basis">
                <div class="sbdp-arrangement-admin__grid sbdp-arrangement-admin__grid--two">
                    <label class="sbdp-arrangement-admin__field">
                        <span><?php esc_html_e('Arrangement type', 'sbdp'); ?></span>
                        <select name="sbdp_arrangement[arrangement_type]">
                            <option value="fixed" <?php selected((string) ($arrangement['arrangement_type'] ?? 'fixed'), 'fixed'); ?>>fixed</option>
                            <option value="dynamic" <?php selected((string) ($arrangement['arrangement_type'] ?? ''), 'dynamic'); ?>>dynamic</option>
                            <option value="customized" <?php selected((string) ($arrangement['arrangement_type'] ?? ''), 'customized'); ?>>customized</option>
                        </select>
                    </label>
                    <label class="sbdp-arrangement-admin__field">
                        <span><?php esc_html_e('Creation mode', 'sbdp'); ?></span>
                        <select name="sbdp_arrangement[creation_mode]">
                            <option value="template" <?php selected((string) ($arrangement['creation_mode'] ?? 'template'), 'template'); ?>>template</option>
                            <option value="fixed" <?php selected((string) ($arrangement['creation_mode'] ?? ''), 'fixed'); ?>>fixed</option>
                            <option value="dynamic" <?php selected((string) ($arrangement['creation_mode'] ?? ''), 'dynamic'); ?>>dynamic</option>
                            <option value="customized" <?php selected((string) ($arrangement['creation_mode'] ?? ''), 'customized'); ?>>customized</option>
                        </select>
                    </label>
                    <label class="sbdp-arrangement-admin__field">
                        <span><?php esc_html_e('Visibility', 'sbdp'); ?></span>
                        <select name="sbdp_arrangement[visibility]">
                            <option value="public" <?php selected((string) ($arrangement['visibility'] ?? 'public'), 'public'); ?>>public</option>
                            <option value="internal" <?php selected((string) ($arrangement['visibility'] ?? ''), 'internal'); ?>>internal</option>
                            <option value="hidden" <?php selected((string) ($arrangement['visibility'] ?? ''), 'hidden'); ?>>hidden</option>
                            <option value="archived" <?php selected((string) ($arrangement['visibility'] ?? ''), 'archived'); ?>>archived</option>
                        </select>
                    </label>
                    <label class="sbdp-arrangement-admin__field">
                        <span><?php esc_html_e('Woo sales product ID', 'sbdp'); ?></span>
                        <input type="number" min="0" name="sbdp_arrangement[sales_product_id]" value="<?php echo esc_attr((string) ((int) ($arrangement['sales_product_id'] ?? 0))); ?>" />
                    </label>
                    <label class="sbdp-arrangement-admin__field">
                        <span><?php esc_html_e('Template ID', 'sbdp'); ?></span>
                        <input type="number" min="0" name="sbdp_arrangement[template_id]" value="<?php echo esc_attr((string) ((int) ($arrangement['template_id'] ?? 0))); ?>" />
                    </label>
                    <label class="sbdp-arrangement-admin__field">
                        <span><?php esc_html_e('Valuta', 'sbdp'); ?></span>
                        <input type="text" name="sbdp_arrangement[currency]" value="<?php echo esc_attr((string) ($arrangement['currency'] ?? 'EUR')); ?>" />
                    </label>
                </div>
            </section>

            <section class="sbdp-arrangement-admin__panel" data-panel="onderdelen" hidden>
                <div class="sbdp-arrangement-builder" data-arrangement-builder>
                    <div class="sbdp-arrangement-builder__main">
                        <div class="sbdp-arrangement-builder__intro">
                            <div>
                                <h3><?php esc_html_e('Programma builder', 'sbdp'); ?></h3>
                                <p class="description"><?php esc_html_e('Bouw het arrangement als een compact programma. Sleep kaarten in volgorde, kies de hoofdactiviteit en houd de preview rechts in de gaten.', 'sbdp'); ?></p>
                            </div>
                            <div class="sbdp-arrangement-builder__actions">
                                <button type="button" class="button button-secondary" data-add-segment data-segment-preset="pre"><?php esc_html_e('Voeg vooraf toe', 'sbdp'); ?></button>
                                <button type="button" class="button button-primary" data-add-segment data-segment-preset="anchor"><?php esc_html_e('Voeg hoofdactiviteit toe', 'sbdp'); ?></button>
                                <button type="button" class="button button-secondary" data-add-segment data-segment-preset="post"><?php esc_html_e('Voeg achteraf toe', 'sbdp'); ?></button>
                            </div>
                        </div>

                        <div id="sbdp-arrangement-segments" class="sbdp-arrangement-builder__segments" data-segment-list>
                            <?php foreach (($arrangement['segments'] ?? array()) as $index => $segment) : ?>
                                <?php
                                if (! is_array($segment)) {
                                    continue;
                                }
                                $linkedProductId = (int) ($segment['linked_product_id'] ?? 0);
                                $snapshot = $productSnapshots[$linkedProductId] ?? null;
                                $this->renderSegmentCard($index, $segment, is_array($snapshot) ? $snapshot : null);
                                ?>
                            <?php endforeach; ?>
                        </div>

                        <template id="sbdp-arrangement-segment-template">
                            <?php $this->renderSegmentCard('__INDEX__', array('role' => 'post', 'segment_type' => 'activity', 'required' => true), null); ?>
                        </template>
                    </div>

                    <aside class="sbdp-arrangement-builder__sidebar">
                        <div class="sbdp-arrangement-admin__summary-block sbdp-arrangement-suggestions" data-suggestions-panel>
                            <h4><?php esc_html_e('Slimme suggesties', 'sbdp'); ?></h4>
                            <div class="sbdp-arrangement-suggestions__list" data-suggestions-list>
                                <p class="sbdp-arrangement-product-picker__empty"><?php esc_html_e('Kies eerst een hoofdactiviteit voor gerichte suggesties.', 'sbdp'); ?></p>
                            </div>
                        </div>
                        <div class="sbdp-arrangement-admin__summary-block sbdp-arrangement-preview" data-arrangement-preview>
                            <h4><?php esc_html_e('Live preview', 'sbdp'); ?></h4>
                            <div class="sbdp-arrangement-preview__status" data-preview-status>
                                <span class="sbdp-arrangement-preview__status-pill"><?php echo esc_html((string) ($summary['status'] ?? 'draft')); ?></span>
                                <span data-preview-window><?php echo esc_html((string) ($summary['planner_window'] ?? '')); ?></span>
                            </div>
                            <ol class="sbdp-arrangement-preview__list" data-preview-list>
                                <?php foreach (($summary['program_rows'] ?? array()) as $row) : ?>
                                    <?php if (! is_array($row)) { continue; } ?>
                                    <li class="sbdp-arrangement-preview__item">
                                        <strong><?php echo esc_html((string) ($row['label'] ?? '')); ?></strong>
                                        <span><?php echo esc_html(implode(' · ', array_filter(array((string) ($row['role'] ?? ''), (string) ($row['timing'] ?? ''), sprintf(__('%d min', 'sbdp'), (int) ($row['duration'] ?? 0)))))); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                            <div class="sbdp-arrangement-preview__health" data-preview-health>
                                <?php foreach (($validation['errors'] ?? array()) as $message) : ?>
                                    <div class="sbdp-arrangement-preview__notice is-error"><?php echo esc_html((string) $message); ?></div>
                                <?php endforeach; ?>
                                <?php foreach (($validation['warnings'] ?? array()) as $message) : ?>
                                    <div class="sbdp-arrangement-preview__notice is-warning"><?php echo esc_html((string) $message); ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </aside>
                </div>
            </section>

            <section class="sbdp-arrangement-admin__panel" data-panel="planning" hidden>
                <div class="sbdp-arrangement-admin__grid sbdp-arrangement-admin__grid--three">
                    <label class="sbdp-arrangement-admin__field">
                        <span><?php esc_html_e('Totale duur (min)', 'sbdp'); ?></span>
                        <input type="number" min="0" name="sbdp_arrangement[duration_total]" value="<?php echo esc_attr((string) ((int) ($arrangement['duration_total'] ?? 0))); ?>" />
                    </label>
                    <label class="sbdp-arrangement-admin__field">
                        <span><?php esc_html_e('Daypart', 'sbdp'); ?></span>
                        <input type="text" name="sbdp_arrangement[daypart]" value="<?php echo esc_attr((string) ($arrangement['daypart'] ?? '')); ?>" />
                    </label>
                    <label class="sbdp-arrangement-admin__field">
                        <span><?php esc_html_e('Sort order', 'sbdp'); ?></span>
                        <input type="number" name="sbdp_arrangement[sort_order]" value="<?php echo esc_attr((string) ((int) ($arrangement['sort_order'] ?? 0))); ?>" />
                    </label>
                </div>
            </section>

            <section class="sbdp-arrangement-admin__panel" data-panel="prijs" hidden>
                <div class="sbdp-arrangement-admin__grid sbdp-arrangement-admin__grid--three">
                    <label class="sbdp-arrangement-admin__field">
                        <span><?php esc_html_e('Price strategy', 'sbdp'); ?></span>
                        <select name="sbdp_arrangement[price_strategy]">
                            <?php foreach (ArrangementSchema::PRICE_STRATEGIES as $strategy) : ?>
                                <option value="<?php echo esc_attr($strategy); ?>" <?php selected((string) ($arrangement['price_strategy'] ?? 'sum_children'), $strategy); ?>><?php echo esc_html($strategy); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="sbdp-arrangement-admin__field">
                        <span><?php esc_html_e('Base price', 'sbdp'); ?></span>
                        <input type="number" min="0" step="0.01" name="sbdp_arrangement[base_price]" value="<?php echo esc_attr((string) ($arrangement['base_price'] ?? 0)); ?>" />
                    </label>
                </div>
                <p class="description"><?php esc_html_e('Prijsdefinitie leeft hier; WooCommerce blijft de execution truth voor btw, totals en checkout.', 'sbdp'); ?></p>
            </section>

            <section class="sbdp-arrangement-admin__panel" data-panel="beschikbaarheid" hidden>
                <div class="sbdp-arrangement-admin__grid sbdp-arrangement-admin__grid--two">
                    <label class="sbdp-arrangement-admin__field">
                        <span><?php esc_html_e('Featured', 'sbdp'); ?></span>
                        <input type="checkbox" name="sbdp_arrangement[featured]" value="1" <?php checked(! empty($arrangement['featured'])); ?> />
                    </label>
                    <label class="sbdp-arrangement-admin__field sbdp-arrangement-admin__field--full">
                        <span><?php esc_html_e('Regelnotitie', 'sbdp'); ?></span>
                        <textarea name="sbdp_arrangement[rules][0][note]" rows="4"><?php echo esc_textarea(is_array($arrangement['rules'] ?? null) && isset($arrangement['rules'][0]['note']) ? (string) $arrangement['rules'][0]['note'] : ''); ?></textarea>
                    </label>
                </div>
            </section>

            <section class="sbdp-arrangement-admin__panel" data-panel="frontend" hidden>
                <div class="sbdp-arrangement-admin__grid sbdp-arrangement-admin__grid--two">
                    <label class="sbdp-arrangement-admin__field sbdp-arrangement-admin__field--full">
                        <span><?php esc_html_e('Excerpt', 'sbdp'); ?></span>
                        <textarea name="sbdp_arrangement[excerpt]" rows="5"><?php echo esc_textarea((string) ($arrangement['excerpt'] ?? '')); ?></textarea>
                    </label>
                </div>
            </section>

            <section class="sbdp-arrangement-admin__panel" data-panel="samenvatting" hidden>
                <div class="sbdp-arrangement-admin__summary-layout">
                    <div class="sbdp-arrangement-admin__summary-block">
                        <h4><?php esc_html_e('Contractcheck', 'sbdp'); ?></h4>
                        <ul>
                            <li><?php echo esc_html(sprintf(__('Segmenten: %d zichtbaar', 'sbdp'), (int) ($summary['visible_segment_count'] ?? 0))); ?></li>
                            <li><?php echo esc_html(sprintf(__('Hoofdactiviteit: %s', 'sbdp'), (string) ($summary['anchor_title'] ?? 'onbekend'))); ?></li>
                            <li><?php echo esc_html(sprintf(__('Plannerwindow: %s', 'sbdp'), (string) ($summary['planner_window'] ?? 'n.v.t.'))); ?></li>
                            <li><?php echo esc_html(sprintf(__('Price strategy: %s', 'sbdp'), (string) (($summary['commerce']['price_strategy'] ?? 'sum_children')))); ?></li>
                        </ul>
                    </div>
                    <div class="sbdp-arrangement-admin__summary-block">
                        <h4><?php esc_html_e('Programma-preview', 'sbdp'); ?></h4>
                        <ol class="sbdp-arrangement-admin__program-list">
                            <?php foreach (($summary['program_rows'] ?? array()) as $row) : ?>
                                <?php if (! is_array($row)) { continue; } ?>
                                <li>
                                    <strong><?php echo esc_html((string) ($row['label'] ?? '')); ?></strong>
                                    <span><?php echo esc_html((string) ($row['role'] ?? '')); ?></span>
                                    <span><?php echo esc_html((string) ($row['timing'] ?? '')); ?></span>
                                    <span><?php echo esc_html(sprintf(__('%d min', 'sbdp'), (int) ($row['duration'] ?? 0))); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    </div>
                    <div class="sbdp-arrangement-admin__summary-block">
                        <h4><?php esc_html_e('Aggregate JSON', 'sbdp'); ?></h4>
                        <pre><?php echo esc_html(wp_json_encode($workspace, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                    </div>
                </div>
            </section>
        </div>
        <?php
    }

    public function lookupProducts(): void
    {
        if (! current_user_can('manage_woocommerce') && ! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Geen toegang.', 'sbdp')), 403);
        }

        check_ajax_referer('sbdp_arrangement_lookup_products', 'nonce');

        $query = isset($_POST['query']) ? sanitize_text_field((string) wp_unslash($_POST['query'])) : '';
        $lookup = new BookableProductLookupService();

        wp_send_json_success(
            array(
                'results' => $lookup->search($query),
            )
        );
    }

    public function suggestProducts(): void
    {
        if (! current_user_can('manage_woocommerce') && ! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Geen toegang.', 'sbdp')), 403);
        }

        check_ajax_referer('sbdp_arrangement_lookup_products', 'nonce');

        $productId = isset($_POST['product_id']) ? (int) wp_unslash($_POST['product_id']) : 0;
        $lookup = new BookableProductLookupService();

        wp_send_json_success(
            array(
                'results' => $lookup->suggestForProduct($productId),
            )
        );
    }

    /**
     * @param array<string, mixed>|null $snapshot
     */
    private function renderProductPicker(string $fieldName, int $productId, ?array $snapshot): void
    {
        $title = is_array($snapshot) ? (string) ($snapshot['title'] ?? '') : '';
        ?>
        <div class="sbdp-arrangement-product-picker" data-product-picker>
            <input type="hidden" name="<?php echo esc_attr($fieldName); ?>" value="<?php echo esc_attr((string) $productId); ?>" data-product-id-input />
            <div class="sbdp-arrangement-product-picker__controls">
                <input
                    type="search"
                    class="regular-text"
                    value="<?php echo esc_attr($title); ?>"
                    placeholder="<?php esc_attr_e('Zoek bookable product', 'sbdp'); ?>"
                    autocomplete="off"
                    data-product-search
                />
                <button type="button" class="button button-secondary" data-clear-product><?php esc_html_e('Wis', 'sbdp'); ?></button>
            </div>
            <div class="sbdp-arrangement-product-picker__results" data-product-results hidden></div>
            <div class="sbdp-arrangement-product-picker__snapshot" data-product-snapshot>
                <?php $this->renderProductSnapshot($snapshot); ?>
            </div>
        </div>
        <?php
    }

    /**
     * @param array<string, mixed>|null $snapshot
     */
    private function renderProductSnapshot(?array $snapshot): void
    {
        if (! is_array($snapshot) || $snapshot === array()) {
            echo '<p class="sbdp-arrangement-product-picker__empty">' . esc_html__('Nog geen boekbaar product gekoppeld.', 'sbdp') . '</p>';
            return;
        }

        $meta = array_filter(
            array(
                (string) ($snapshot['price_label'] ?? ''),
                (string) ($snapshot['tax_label'] ?? ''),
                (string) ($snapshot['duration_label'] ?? ''),
                (string) ($snapshot['people_label'] ?? ''),
                (string) ($snapshot['availability_label'] ?? ''),
            )
        );
        ?>
        <div class="sbdp-arrangement-product-card">
            <strong class="sbdp-arrangement-product-card__title"><?php echo esc_html((string) ($snapshot['title'] ?? '')); ?></strong>
            <span class="sbdp-arrangement-product-card__meta">#<?php echo esc_html((string) ((int) ($snapshot['id'] ?? 0))); ?> · <?php echo esc_html((string) ($snapshot['type'] ?? '')); ?></span>
            <div class="sbdp-arrangement-product-card__chips">
                <?php foreach ($meta as $item) : ?>
                    <span class="sbdp-arrangement-product-card__chip"><?php echo esc_html($item); ?></span>
                <?php endforeach; ?>
            </div>
            <?php if (! empty($snapshot['edit_url'])) : ?>
                <a class="sbdp-arrangement-product-card__link" href="<?php echo esc_url((string) $snapshot['edit_url']); ?>">
                    <?php esc_html_e('Open Woo product', 'sbdp'); ?>
                </a>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @param int|string $index
     * @param array<string, mixed> $segment
     * @param array<string, mixed>|null $snapshot
     */
    private function renderSegmentCard($index, array $segment, ?array $snapshot): void
    {
        $indexString = (string) $index;
        $role = (string) ($segment['role'] ?? 'post');
        $duration = (int) ($segment['max_duration'] ?? $segment['min_duration'] ?? 0);
        ?>
        <article class="sbdp-arrangement-segment-card" data-segment-row data-segment-index="<?php echo esc_attr($indexString); ?>" data-role="<?php echo esc_attr($role); ?>">
            <input type="hidden" name="sbdp_arrangement[segments][<?php echo esc_attr($indexString); ?>][sequence]" value="<?php echo esc_attr((string) ($segment['sequence'] ?? $indexString)); ?>" data-segment-sequence />
            <input type="hidden" name="sbdp_arrangement[segments][<?php echo esc_attr($indexString); ?>][min_duration]" value="<?php echo esc_attr((string) ((int) ($segment['min_duration'] ?? 0))); ?>" />

            <div class="sbdp-arrangement-segment-card__handle" aria-hidden="true">
                <span></span><span></span><span></span>
            </div>

            <div class="sbdp-arrangement-segment-card__header">
                <div class="sbdp-arrangement-segment-card__heading">
                    <span class="sbdp-arrangement-segment-card__badge" data-role-badge><?php echo esc_html($this->segmentRoleLabel($role)); ?></span>
                    <input
                        type="text"
                        class="sbdp-arrangement-segment-card__title"
                        name="sbdp_arrangement[segments][<?php echo esc_attr($indexString); ?>][title_override]"
                        value="<?php echo esc_attr((string) ($segment['title_override'] ?? '')); ?>"
                        placeholder="<?php esc_attr_e('Titel voor dit onderdeel', 'sbdp'); ?>"
                        data-segment-title
                    />
                </div>
                <div class="sbdp-arrangement-segment-card__actions">
                    <button type="button" class="button button-small button-secondary sbdp-arrangement-duplicate-row"><?php esc_html_e('Dupliceer', 'sbdp'); ?></button>
                    <button type="button" class="button-link-delete sbdp-arrangement-remove-row"><?php esc_html_e('Verwijder', 'sbdp'); ?></button>
                </div>
            </div>

            <div class="sbdp-arrangement-segment-card__grid">
                <label class="sbdp-arrangement-admin__field">
                    <span><?php esc_html_e('Rol in programma', 'sbdp'); ?></span>
                    <select name="sbdp_arrangement[segments][<?php echo esc_attr($indexString); ?>][role]" data-segment-role>
                        <option value="anchor" <?php selected($role, 'anchor'); ?>><?php esc_html_e('Hoofdactiviteit', 'sbdp'); ?></option>
                        <option value="pre" <?php selected($role, 'pre'); ?>><?php esc_html_e('Vooraf', 'sbdp'); ?></option>
                        <option value="post" <?php selected($role, 'post'); ?>><?php esc_html_e('Achteraf', 'sbdp'); ?></option>
                    </select>
                </label>
                <label class="sbdp-arrangement-admin__field">
                    <span><?php esc_html_e('Vaste starttijd', 'sbdp'); ?></span>
                    <select name="sbdp_arrangement[segments][<?php echo esc_attr($indexString); ?>][fixed_start_time]" data-segment-start>
                        <?php $this->renderTimeOptions((string) ($segment['fixed_start_time'] ?? '')); ?>
                    </select>
                </label>
                <label class="sbdp-arrangement-admin__field">
                    <span><?php esc_html_e('Timing', 'sbdp'); ?></span>
                    <select name="sbdp_arrangement[segments][<?php echo esc_attr($indexString); ?>][timing_mode]" data-segment-timing-mode>
                        <option value="fixed_start" <?php selected((string) ($segment['timing_mode'] ?? 'after_previous'), 'fixed_start'); ?>><?php esc_html_e('Vaste starttijd', 'sbdp'); ?></option>
                        <option value="after_previous" <?php selected((string) ($segment['timing_mode'] ?? 'after_previous'), 'after_previous'); ?>><?php esc_html_e('Na vorige', 'sbdp'); ?></option>
                        <option value="before_next" <?php selected((string) ($segment['timing_mode'] ?? ''), 'before_next'); ?>><?php esc_html_e('Voor volgende', 'sbdp'); ?></option>
                    </select>
                </label>
                <label class="sbdp-arrangement-admin__field">
                    <span><?php esc_html_e('Offset (min)', 'sbdp'); ?></span>
                    <input type="number" min="0" name="sbdp_arrangement[segments][<?php echo esc_attr($indexString); ?>][buffer_before]" value="<?php echo esc_attr((string) ((int) ($segment['buffer_before'] ?? 0))); ?>" data-segment-offset />
                </label>
                <label class="sbdp-arrangement-admin__field">
                    <span><?php esc_html_e('Duur (min)', 'sbdp'); ?></span>
                    <input type="number" min="0" name="sbdp_arrangement[segments][<?php echo esc_attr($indexString); ?>][max_duration]" value="<?php echo esc_attr((string) $duration); ?>" data-segment-duration />
                </label>
                <label class="sbdp-arrangement-admin__field">
                    <span><?php esc_html_e('Type', 'sbdp'); ?></span>
                    <select name="sbdp_arrangement[segments][<?php echo esc_attr($indexString); ?>][segment_type]">
                        <?php foreach (ArrangementSchema::SEGMENT_TYPES as $segmentType) : ?>
                            <option value="<?php echo esc_attr($segmentType); ?>" <?php selected((string) ($segment['segment_type'] ?? 'activity'), $segmentType); ?>><?php echo esc_html($segmentType); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <div class="sbdp-arrangement-segment-card__timing-meta">
                <span class="sbdp-arrangement-segment-card__timing-pill" data-segment-endtime>
                    <?php echo esc_html($this->endTimeLabel((string) ($segment['fixed_start_time'] ?? ''), $duration)); ?>
                </span>
            </div>

            <div class="sbdp-arrangement-segment-card__product">
                <?php
                $this->renderProductPicker(
                    'sbdp_arrangement[segments][' . $indexString . '][linked_product_id]',
                    (int) ($segment['linked_product_id'] ?? 0),
                    $snapshot
                );
                ?>
            </div>

            <details class="sbdp-arrangement-segment-card__advanced">
                <summary><?php esc_html_e('Advanced', 'sbdp'); ?></summary>
                <div class="sbdp-arrangement-segment-card__advanced-grid">
                    <label class="sbdp-arrangement-admin__field">
                        <span><?php esc_html_e('Required', 'sbdp'); ?></span>
                        <input type="checkbox" name="sbdp_arrangement[segments][<?php echo esc_attr($indexString); ?>][required]" value="1" <?php checked(! empty($segment['required'])); ?> />
                    </label>
                    <label class="sbdp-arrangement-admin__field">
                        <span><?php esc_html_e('Optioneel', 'sbdp'); ?></span>
                        <input type="checkbox" name="sbdp_arrangement[segments][<?php echo esc_attr($indexString); ?>][is_optional]" value="1" <?php checked(! empty($segment['is_optional'])); ?> />
                    </label>
                    <label class="sbdp-arrangement-admin__field">
                        <span><?php esc_html_e('Verbergen', 'sbdp'); ?></span>
                        <input type="checkbox" name="sbdp_arrangement[segments][<?php echo esc_attr($indexString); ?>][is_hidden]" value="1" <?php checked(! empty($segment['is_hidden'])); ?> />
                    </label>
                </div>
            </details>
        </article>
        <?php
    }

    private function segmentRoleLabel(string $role): string
    {
        return match ($role) {
            'anchor' => (string) __('Hoofdactiviteit', 'sbdp'),
            'pre' => (string) __('Vooraf', 'sbdp'),
            default => (string) __('Achteraf', 'sbdp'),
        };
    }

    private function renderTimeOptions(string $selected): void
    {
        echo '<option value="">' . esc_html__('Geen vaste tijd', 'sbdp') . '</option>';
        for ($minutes = 8 * 60; $minutes <= 23 * 60; $minutes += 15) {
            $time = sprintf('%02d:%02d', (int) floor($minutes / 60), $minutes % 60);
            printf(
                '<option value="%1$s" %2$s>%1$s</option>',
                esc_attr($time),
                selected($selected, $time, false)
            );
        }
    }

    private function endTimeLabel(string $startTime, int $duration): string
    {
        if ($startTime === '' || $duration <= 0) {
            return (string) __('Eindtijd volgt automatisch zodra starttijd en duur bekend zijn.', 'sbdp');
        }

        [$hours, $minutes] = array_pad(array_map('intval', explode(':', $startTime)), 2, 0);
        $end = ($hours * 60) + $minutes + $duration;

        return sprintf(
            __('Eindtijd %s', 'sbdp'),
            sprintf('%02d:%02d', (int) floor(($end % 1440) / 60), $end % 60)
        );
    }

    public function save(int $postId, \WP_Post $post, bool $update): void
    {
        unset($update);

        // Prevent infinite recursion: $repo->save() calls wp_insert_post() which re-fires this hook.
        static $saving = [];
        if (isset($saving[$postId])) {
            return;
        }
        $saving[$postId] = true;

        if (! current_user_can('manage_woocommerce') && ! current_user_can('manage_options')) {
            unset($saving[$postId]);
            return;
        }
        if (! isset($_POST['sbdp_arrangement_nonce']) || ! wp_verify_nonce((string) wp_unslash($_POST['sbdp_arrangement_nonce']), 'sbdp_arrangement_save')) {
            unset($saving[$postId]);
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            unset($saving[$postId]);
            return;
        }
        if (! isset($_POST['sbdp_arrangement']) || ! is_array($_POST['sbdp_arrangement'])) {
            unset($saving[$postId]);
            return;
        }

        $raw = wp_unslash($_POST['sbdp_arrangement']);
        $repo = new ArrangementRepository();
        $workspaceService = new ArrangementWorkspaceService();
        $payload = $repo->normalizePayload(is_array($raw) ? $raw : array());
        $payload['id'] = $postId;
        $payload['title'] = isset($_POST['post_title']) ? sanitize_text_field((string) wp_unslash($_POST['post_title'])) : (string) $payload['title'];
        $payload['description'] = isset($_POST['content']) ? (string) wp_unslash($_POST['content']) : (string) $payload['description'];
        $payload['excerpt'] = isset($_POST['excerpt']) ? sanitize_text_field((string) wp_unslash($_POST['excerpt'])) : (string) $payload['excerpt'];
        $payload['status'] = get_post_status($post) ?: 'draft';

        $savedId = $repo->save($payload);
        if ($savedId <= 0) {
            unset($saving[$postId]);
            return;
        }

        $savedArrangement = $repo->find($savedId);
        if (! is_array($savedArrangement) || $savedArrangement === array()) {
            $savedArrangement = $payload;
        }
        $workspace = $workspaceService->build($savedArrangement);
        $validation = is_array($workspace['validation'] ?? null) ? $workspace['validation'] : array('errors' => array(), 'warnings' => array());
        update_post_meta($savedId, self::META_VALIDATION, wp_json_encode($validation));

        if (! empty($validation['errors']) && ((string) ($payload['status'] ?? '') === 'publish' || get_post_status($savedId) === 'publish')) {
            remove_action('save_post_' . ArrangementSchema::POST_TYPE, array($this, 'save'), 10);
            wp_update_post(array(
                'ID' => $savedId,
                'post_status' => 'draft',
            ));
            add_action('save_post_' . ArrangementSchema::POST_TYPE, array($this, 'save'), 10, 3);
            update_post_meta(
                $savedId,
                self::META_PUBLISH_BLOCKED,
                __('Arrangement bleef concept omdat de workspace-validatie fouten vond. Werk eerst de contractcheck weg.', 'sbdp')
            );
        }

        unset($saving[$postId]);
    }
}
