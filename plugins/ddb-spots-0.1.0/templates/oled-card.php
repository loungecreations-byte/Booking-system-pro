<?php
/**
 * Premium Spots Listing Card
 *
 * @var string $title
 * @var string $description
 * @var string $image_url
 * @var string $link
 * @var string $meta
 * @var string $badge
 * @var array  $tags
 * @var string $maps_url
 * @var string $address
 */
?>
<article
	class="ui-listing-card ddb-spot-card ddb-card ddb-card--interactive"
	data-card-clickable="true"
	tabindex="0"
	role="button"
	data-ddb-map-item
	data-ddb-spot-card
	data-spot-id="<?php echo esc_attr(isset($spot_id) ? (string) $spot_id : ''); ?>"
	data-spot-title="<?php echo esc_attr($title); ?>"
	data-spot-link="<?php echo esc_attr($link); ?>"
	data-spot-image="<?php echo esc_attr($image_url); ?>"
	data-spot-type="<?php echo esc_attr($type_label ?? ''); ?>"
	data-spot-type-slug="<?php echo esc_attr($type_slug ?? ''); ?>"
	data-spot-area="<?php echo esc_attr($area_label ?? ''); ?>"
	data-spot-area-slug="<?php echo esc_attr($area_slug ?? ''); ?>"
	data-spot-category="<?php echo esc_attr($category_label ?? ''); ?>"
	data-spot-category-slug="<?php echo esc_attr($category_slug ?? ''); ?>"
	data-spot-rating="<?php echo esc_attr(isset($rating) ? (string) $rating : '0'); ?>"
	data-spot-rating-total="<?php echo esc_attr(isset($rating_total) ? (string) $rating_total : '0'); ?>"
	data-spot-price="<?php echo esc_attr(isset($price_label) ? $price_label : ''); ?>"
	data-spot-best-time="<?php echo esc_attr(isset($best_time_slot) ? $best_time_slot : ''); ?>"
	data-spot-best-time-label="<?php echo esc_attr(isset($best_time_label) ? $best_time_label : ''); ?>"
	data-spot-walk="<?php echo esc_attr(isset($walk_distance_to_core) ? (string) $walk_distance_to_core : '0'); ?>"
	data-spot-opening="<?php echo esc_attr(isset($opening_label) ? $opening_label : ''); ?>"
	data-spot-summary="<?php echo esc_attr($description ?? ''); ?>"
	data-spot-meta="<?php echo esc_attr($meta ?? ''); ?>"
	data-spot-address="<?php echo esc_attr($address ?? ''); ?>"
	data-spot-maps-url="<?php echo esc_attr($maps_url ?? ''); ?>"
	data-spot-embed-url="<?php echo esc_attr(isset($embed_url) ? $embed_url : ''); ?>"
	data-spot-tags="<?php echo esc_attr(implode('|', isset($tags) && is_array($tags) ? $tags : array())); ?>">
	<div class="ui-listing-card__media ddb-card__media">
		<?php if ($image_url) : ?>
			<img class="ui-listing-card__image" src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($title); ?>" loading="lazy" />
		<?php else : ?>
			<div class="ui-listing-card__placeholder"></div>
		<?php endif; ?>
	</div>
	<div class="ui-listing-card__overlay ddb-card__body">
		<header class="ui-listing-card__header">
			<div class="ui-listing-card__header-main">
				<p class="ui-listing-card__eyebrow"><?php echo esc_html($meta ?? ''); ?></p>
				<h3 class="ui-listing-card__title ddb-card__title"><?php echo esc_html($title); ?></h3>
			</div>
			<?php if (! empty($badge)) : ?>
				<span class="ui-listing-card__price"><?php echo esc_html($badge); ?></span>
			<?php endif; ?>
		</header>
		<?php if (! empty($tags) && is_array($tags)) : ?>
			<ul class="ui-listing-card__meta ddb-card__meta">
				<?php foreach (array_slice($tags, 0, 2) as $tag) : ?>
					<li class="ui-listing-card__meta-item"><?php echo esc_html((string) $tag); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<div class="ui-listing-card__actions ddb-card__actions">
			<a class="ui-listing-card__cta ui-listing-card__cta--primary ddb-button ddb-button--primary" href="<?php echo esc_url($link); ?>"><?php esc_html_e('Bekijk plek', 'ddb-spots'); ?></a>
			<?php if (! empty($maps_url)) : ?>
				<a class="ui-listing-card__cta ui-listing-card__cta--secondary ddb-button ddb-button--secondary" href="<?php echo esc_url($maps_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Route starten', 'ddb-spots'); ?></a>
			<?php endif; ?>
		</div>
	</div>
</article>
