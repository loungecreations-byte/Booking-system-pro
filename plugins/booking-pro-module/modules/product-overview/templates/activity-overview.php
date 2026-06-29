<?php

declare(strict_types=1);

$configJson = wp_json_encode($component, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
if ($configJson === false) {
    $configJson = '{}';
}

$componentId = isset($component['componentId']) && is_string($component['componentId'])
    ? $component['componentId']
    : uniqid('sbdp-ao-', false);

$products = isset($component['products']) && is_array($component['products'])
    ? $component['products']
    : array();

$renderedProducts = array_slice($products, 0, 12);
?>
<section
    class="sbdp-activity-overview"
    data-component="sbdp-activity-overview"
    data-component-id="<?php echo esc_attr($componentId); ?>"
    data-config="<?php echo esc_attr($configJson); ?>"
>
    <div
        class="sbdp-activity-overview__app"
        id="<?php echo esc_attr($componentId); ?>-root"
        data-role="activity-overview-root"
    >
        <?php if (! empty($renderedProducts)) : ?>
            <div class="ao-shell" data-server-rendered="true">
                <div class="ao-layout">
                    <main class="ao-main">
                        <div class="ao-grid">
                            <?php foreach ($renderedProducts as $product) : ?>
                                <?php
                                if (! is_array($product)) {
                                    continue;
                                }

                                $productId = isset($product['id']) ? (int) $product['id'] : 0;
                                if ($productId <= 0) {
                                    continue;
                                }

                                $title = isset($product['title']) && is_string($product['title']) && $product['title'] !== ''
                                    ? $product['title']
                                    : __('Activiteit', 'sbdp');
                                $image = isset($product['image']) && is_string($product['image']) ? trim($product['image']) : '';
                                $slug = isset($product['slug']) && is_string($product['slug']) && $product['slug'] !== ''
                                    ? $product['slug']
                                    : 'product-' . $productId;
                                $permalink = isset($product['permalink']) && is_string($product['permalink'])
                                    ? $product['permalink']
                                    : '';
                                $href = $permalink !== ''
                                    ? $permalink
                                    : add_query_arg('start', $slug, home_url('/plan-je-dag'));
                                $duration = '';
                                if (isset($product['duration']) && is_array($product['duration'])) {
                                    $duration = isset($product['duration']['formatted']) && is_string($product['duration']['formatted'])
                                        ? $product['duration']['formatted']
                                        : '';
                                }
                                $priceRaw = isset($product['price']['raw']) && is_numeric($product['price']['raw'])
                                    ? (float) $product['price']['raw']
                                    : 0.0;
                                $priceFormatted = isset($product['price']['formatted']) && is_string($product['price']['formatted'])
                                    ? trim($product['price']['formatted'])
                                    : '';
                                $bookingCapability = isset($product['booking_capability']) && is_string($product['booking_capability'])
                                    ? strtolower($product['booking_capability'])
                                    : '';
                                $routeIntent = isset($product['route_intent']) && is_string($product['route_intent'])
                                    ? strtolower($product['route_intent'])
                                    : '';
                                $isRequestOnly = $bookingCapability === 'request' || $routeIntent === 'quote';
                                ?>
                                <article class="ui-listing-card" data-card-variant="default">
                                    <div class="ui-listing-card__media">
                                        <?php if ($image !== '') : ?>
                                            <img
                                                class="ui-listing-card__image"
                                                src="<?php echo esc_url($image); ?>"
                                                alt="<?php echo esc_attr($title); ?>"
                                                loading="lazy"
                                                referrerpolicy="no-referrer"
                                            >
                                        <?php else : ?>
                                            <span class="ui-listing-card__placeholder" aria-hidden="true"></span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="ui-listing-card__overlay">
                                        <div class="ui-listing-card__top-left"></div>
                                        <div class="ui-listing-card__top-right">
                                            <?php if ($duration !== '') : ?>
                                                <span class="ui-listing-card__duration-chip"><?php echo esc_html($duration); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <a class="ui-listing-card__content" href="<?php echo esc_url($href); ?>">
                                        <h3 class="ui-listing-card__title"><?php echo esc_html($title); ?></h3>
                                    </a>

                                    <div class="ui-listing-card__action-row">
                                        <div class="ui-listing-card__price">
                                            <?php if ($priceRaw > 0.0) : ?>
                                                <span class="ui-listing-card__price-prefix"><?php esc_html_e('Vanaf', 'sbdp'); ?></span>
                                            <?php endif; ?>
                                            <span>
                                                <?php
                                                if ($priceFormatted !== '') {
                                                    echo wp_kses_post($priceFormatted);
                                                    echo esc_html_x(' p.p.', 'price per person suffix', 'sbdp');
                                                } else {
                                                    esc_html_e('Prijs op aanvraag', 'sbdp');
                                                }
                                                ?>
                                            </span>
                                        </div>
                                        <a class="ui-listing-card__cta ui-listing-card__cta--primary" href="<?php echo esc_url($href); ?>">
                                            <?php echo esc_html($isRequestOnly ? __('Bekijk offerte', 'sbdp') : __('Bekijk activiteit', 'sbdp')); ?>
                                        </a>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </main>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <noscript class="sbdp-activity-overview__noscript">
        <?php esc_html_e('Activeer JavaScript om activiteiten te bekijken.', 'sbdp'); ?>
    </noscript>
</section>
