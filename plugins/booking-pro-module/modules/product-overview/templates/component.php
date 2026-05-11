<?php

declare(strict_types=1);

use WP_Term;

/**
 * @var array<string, mixed> $component
 * @var array<int, WP_Term>  $types
 * @var bool                 $showFilters
 * @var bool                 $displayMapToggle
 */

$configJson = wp_json_encode($component, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
if ($configJson === false) {
    $configJson = '{}';
}

$currentFilters = isset($component['filters']) && is_array($component['filters'])
    ? $component['filters']
    : array();

$typeValue = isset($currentFilters['type']) ? (string) $currentFilters['type'] : '';
$dateValue = isset($currentFilters['date']) ? (string) $currentFilters['date'] : '';
$minValue  = isset($currentFilters['min_price']) ? (string) $currentFilters['min_price'] : '';
$maxValue  = isset($currentFilters['max_price']) ? (string) $currentFilters['max_price'] : '';
$mapConfig = isset($component['map']) && is_array($component['map']) ? $component['map'] : array();
$mapHasData = ! empty($mapConfig['hasCoordinates']);
$mapRequested = ! empty($mapConfig['enabled']);
?>
<section
    class="sbdp-po"
    data-component="sbdp-product-overview"
    data-component-id="<?php echo esc_attr($component['componentId']); ?>"
    data-default-view="<?php echo esc_attr($component['defaultView']); ?>"
    data-config="<?php echo esc_attr($configJson); ?>"
>
    <?php if ($showFilters) : ?>
        <form class="sbdp-po__filters" data-role="filters">
            <div class="sbdp-po__field">
                <label class="sbdp-po__label">
                    <span><?php echo esc_html($component['strings']['typeLabel']); ?></span>
                    <select name="type" data-field="type">
                        <option value=""><?php esc_html_e('Alle types', 'sbdp'); ?></option>
                        <?php foreach ($types as $type) : ?>
                            <option value="<?php echo esc_attr($type->slug); ?>" <?php selected($typeValue, $type->slug); ?>>
                                <?php echo esc_html($type->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <div class="sbdp-po__field">
                <label class="sbdp-po__label">
                    <span><?php echo esc_html($component['strings']['dateLabel']); ?></span>
                    <input
                        type="date"
                        name="date"
                        value="<?php echo esc_attr($dateValue); ?>"
                        data-field="date"
                    />
                </label>
            </div>

            <div class="sbdp-po__field sbdp-po__field--range">
                <span class="sbdp-po__label"><?php echo esc_html($component['strings']['priceRange']); ?></span>
                <div class="sbdp-po__range-inputs">
                    <input
                        type="number"
                        step="1"
                        min="0"
                        name="min_price"
                        placeholder="<?php esc_attr_e('Min', 'sbdp'); ?>"
                        value="<?php echo esc_attr($minValue); ?>"
                        data-field="min_price"
                    />
                    <span aria-hidden="true">-</span>
                    <input
                        type="number"
                        step="1"
                        min="0"
                        name="max_price"
                        placeholder="<?php esc_attr_e('Max', 'sbdp'); ?>"
                        value="<?php echo esc_attr($maxValue); ?>"
                        data-field="max_price"
                    />
                </div>
            </div>

            <div class="sbdp-po__actions">
                <button type="submit" class="sbdp-po__button sbdp-po__button--primary">
                    <?php echo esc_html($component['strings']['applyFilters']); ?>
                </button>
                <button type="button" class="sbdp-po__button sbdp-po__button--ghost" data-action="reset-filters">
                    <?php echo esc_html($component['strings']['clearFilters']); ?>
                </button>
            </div>
        </form>
    <?php endif; ?>

    <?php if ($mapRequested) : ?>
        <div class="sbdp-po__view-toggle <?php echo $displayMapToggle ? '' : 'is-disabled'; ?>" data-role="view-toggle" role="group" aria-label="<?php esc_attr_e('Weergave wisselen', 'sbdp'); ?>">
            <button
                type="button"
                class="sbdp-po__view-button <?php echo $component['defaultView'] === 'grid' ? 'is-active' : ''; ?>"
                data-action="set-view"
                data-view="grid"
            >
                <?php echo esc_html($component['strings']['gridLabel']); ?>
            </button>
            <button
                type="button"
                class="sbdp-po__view-button <?php echo $component['defaultView'] === 'map' ? 'is-active' : ''; ?> <?php echo $displayMapToggle ? '' : 'is-disabled'; ?>"
                data-action="set-view"
                data-view="map"
                <?php disabled(! $displayMapToggle); ?>
            >
                <?php echo esc_html($component['strings']['mapLabel']); ?>
            </button>
        </div>
    <?php endif; ?>

    <div class="sbdp-po__body" data-role="body">
        <div class="sbdp-po__status" data-role="status" aria-live="polite"></div>
        <div
            class="sbdp-po__grid <?php echo $component['defaultView'] === 'map' ? 'is-hidden' : ''; ?>"
            data-role="grid"
            aria-live="polite"
            data-empty-text="<?php echo esc_attr($component['strings']['empty']); ?>"
        >
            <?php if (empty($component['products'])) : ?>
                <div class="sbdp-po__empty">
                    <?php echo esc_html($component['strings']['empty']); ?>
                </div>
            <?php else : ?>
                <?php foreach ($component['products'] as $product) : ?>
                    <?php if (! is_array($product)) { continue; } ?>
                    <?php
                    $productId = isset($product['id']) ? (int) $product['id'] : 0;
                    $typeSlug  = isset($product['type']['slug']) ? (string) $product['type']['slug'] : '';
                    $lat       = isset($product['coordinates']['lat']) ? (float) $product['coordinates']['lat'] : 0.0;
                    $lng       = isset($product['coordinates']['lng']) ? (float) $product['coordinates']['lng'] : 0.0;
                    ?>
                    <article
                        class="sbdp-po__card"
                        data-product-id="<?php echo esc_attr((string) $productId); ?>"
                        data-type="<?php echo esc_attr($typeSlug); ?>"
                        data-lat="<?php echo esc_attr((string) $lat); ?>"
                        data-lng="<?php echo esc_attr((string) $lng); ?>"
                    >
                        <?php if (! empty($product['image'])) : ?>
                            <div class="sbdp-po__media" style="background-image: url('<?php echo esc_url((string) $product['image']); ?>');"></div>
                        <?php endif; ?>

                        <div class="sbdp-po__card-content">
                            <p class="sbdp-po__type">
                                <?php echo esc_html($product['type']['label'] ?? ''); ?>
                            </p>

                            <h3 class="sbdp-po__title">
                                <?php echo esc_html($product['title'] ?? ''); ?>
                            </h3>

                            <?php if (! empty($product['excerpt'])) : ?>
                                <p class="sbdp-po__excerpt">
                                    <?php echo esc_html($product['excerpt']); ?>
                                </p>
                            <?php endif; ?>

                            <?php if (! empty($product['location'])) : ?>
                                <p class="sbdp-po__location">
                                    <span class="sbdp-po__location-label">
                                        <?php echo esc_html($component['strings']['locationLabel']); ?>:
                                    </span>
                                    <span><?php echo esc_html($product['location']); ?></span>
                                </p>
                            <?php endif; ?>

                            <div class="sbdp-po__meta">
                                <?php if (! empty($product['duration']['formatted'])) : ?>
                                    <span class="sbdp-po__badge">
                                        <?php echo esc_html($component['strings']['durationLabel']); ?>:
                                        <?php echo esc_html($product['duration']['formatted']); ?>
                                    </span>
                                <?php endif; ?>

                                <?php if (! empty($product['price']['formatted'])) : ?>
                                    <span class="sbdp-po__badge">
                                        <?php echo esc_html($component['strings']['priceLabel']); ?>:
                                        <?php echo wp_kses_post($product['price']['formatted']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <a
                                class="sbdp-po__button-link"
                                href="<?php echo esc_url($product['permalink'] ?? '#'); ?>"
                                target="_self"
                                rel="bookmark"
                            >
                                <?php echo esc_html($component['strings']['viewDetails']); ?>
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <p
            class="sbdp-po__map-note"
            data-role="map-note"
            <?php echo ($mapRequested && ! $mapHasData) ? '' : 'hidden'; ?>
        >
            <?php echo esc_html($component['strings']['noMapData']); ?>
        </p>

        <?php if (! empty($component['map']['enabled'])) : ?>
            <div
                class="sbdp-po__map <?php echo (! empty($component['mapEnabled']) && $component['defaultView'] === 'map') ? 'is-active' : ''; ?>"
                data-role="map"
                data-component-id="<?php echo esc_attr($component['componentId']); ?>"
                aria-hidden="<?php echo (! empty($component['mapEnabled']) && $component['defaultView'] === 'map') ? 'false' : 'true'; ?>"
            ></div>
        <?php endif; ?>
    </div>
</section>
