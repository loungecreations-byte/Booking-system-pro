<?php
if (! defined('ABSPATH')) {
	exit;
}

$selected_route_url = ! empty($selected_snapshot['maps_url']) ? (string) $selected_snapshot['maps_url'] : '';
$listing_variant = isset($variant) && is_string($variant) && '' !== trim($variant) ? sanitize_html_class($variant) : 'map-first';
$term_label_for = static function (array $terms, string $slug): string {
	foreach ($terms as $term) {
		if ($term instanceof WP_Term && (string) $term->slug === $slug) {
			return (string) $term->name;
		}
	}
	return $slug;
};
?>
<div class="ddb-spots-shell ddb-spots-listing ddb-spots-listing--<?php echo esc_attr($listing_variant); ?> ddb-listing-shell<?php echo 'map-first' === $listing_variant ? ' is-map-open' : ''; ?>" data-ddb-component="listing-shell">
	<form method="get" class="ddb-listing-toolbar ddb-spots-controls ui-summary ui-summary--compact ddb-summary ddb-card" aria-label="<?php esc_attr_e('Spot filters', 'ddb-spots'); ?>">
		<div class="ui-card__body ddb-card__body">
			<input type="hidden" name="ddb_page" value="1" />
			<div class="ddb-spots-controls__grid">
				<label class="ui-field ddb-listing-field ddb-field">
					<span class="ddb-field__label"><?php esc_html_e('Zoek', 'ddb-spots'); ?></span>
					<input class="ui-input ddb-input" type="search" name="ddb_q" value="<?php echo esc_attr($search_query ?? ''); ?>" placeholder="<?php esc_attr_e('Naam of omschrijving', 'ddb-spots'); ?>" />
				</label>
				<label class="ui-field ddb-listing-field ddb-field">
					<span class="ddb-field__label"><?php esc_html_e('Type', 'ddb-spots'); ?></span>
					<select class="ui-select ddb-select" name="ddb_type">
						<option value=""><?php esc_html_e('Alle types', 'ddb-spots'); ?></option>
						<?php foreach (($type_options ?? array()) as $term) : ?>
							<option value="<?php echo esc_attr((string) $term->slug); ?>" <?php selected((string) ($selected_type ?? ''), (string) $term->slug); ?>><?php echo esc_html((string) $term->name); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="ui-field ddb-listing-field ddb-field">
					<span class="ddb-field__label"><?php esc_html_e('Buurt', 'ddb-spots'); ?></span>
					<select class="ui-select ddb-select" name="ddb_area">
						<option value=""><?php esc_html_e('Alle buurten', 'ddb-spots'); ?></option>
						<?php foreach (($area_options ?? array()) as $term) : ?>
							<option value="<?php echo esc_attr((string) $term->slug); ?>" <?php selected((string) ($selected_area ?? ''), (string) $term->slug); ?>><?php echo esc_html((string) $term->name); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="ui-field ddb-listing-field ddb-field">
					<span class="ddb-field__label"><?php esc_html_e('Categorie', 'ddb-spots'); ?></span>
					<select class="ui-select ddb-select" name="ddb_category">
						<option value=""><?php esc_html_e('Alle categorieën', 'ddb-spots'); ?></option>
						<?php foreach (($category_options ?? array()) as $term) : ?>
							<option value="<?php echo esc_attr((string) $term->slug); ?>" <?php selected((string) ($selected_category ?? ''), (string) $term->slug); ?>><?php echo esc_html((string) $term->name); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</div>

			<div class="ddb-spots-controls__filters">
				<?php if (! empty($selected_type)) : ?><span class="ui-badge ui-badge--secondary ddb-chip ddb-chip--selected"><?php echo esc_html($term_label_for((array) $type_options, (string) $selected_type)); ?></span><?php endif; ?>
				<?php if (! empty($selected_area)) : ?><span class="ui-badge ui-badge--secondary ddb-chip ddb-chip--selected"><?php echo esc_html($term_label_for((array) $area_options, (string) $selected_area)); ?></span><?php endif; ?>
				<?php if (! empty($selected_category)) : ?><span class="ui-badge ui-badge--secondary ddb-chip ddb-chip--selected"><?php echo esc_html($term_label_for((array) $category_options, (string) $selected_category)); ?></span><?php endif; ?>
			</div>

			<div class="ddb-spots-controls__actions">
				<button type="submit" class="ui-btn ui-btn--primary ddb-button ddb-button--primary"><?php esc_html_e('Toon resultaten', 'ddb-spots'); ?></button>
				<a class="ui-btn ui-btn--ghost ddb-button ddb-button--ghost" href="<?php echo esc_url((string) ($reset_url ?? '#')); ?>"><?php esc_html_e('Reset', 'ddb-spots'); ?></a>
				<?php if ((bool) apply_filters('ddb_spots_show_legacy_theme_button', false, 0, 'listing')) : ?>
					<button type="button" class="ui-btn ui-btn--ghost ddb-button ddb-button--ghost" data-ddb-theme-toggle data-light-label="<?php echo esc_attr__('Lichte modus', 'ddb-spots'); ?>" data-dark-label="<?php echo esc_attr__('Donkere modus', 'ddb-spots'); ?>"><?php esc_html_e('Thema', 'ddb-spots'); ?></button>
				<?php endif; ?>
				<button type="button" class="ui-btn ui-btn--secondary ddb-button ddb-button--secondary ddb-listing-btn--map" data-ddb-map-toggle><?php esc_html_e('Resultaten tonen', 'ddb-spots'); ?></button>
			</div>
		</div>
	</form>

	<div class="ddb-listing-main ddb-spots-layout">
		<section class="ddb-listing-results ddb-spots-results" aria-live="polite">
			<header class="ddb-listing-results__head ddb-spots-results__head">
				<div>
					<h2><?php echo esc_html(sprintf(_n('%d spot gevonden', '%d spots gevonden', (int) ($total_visible ?? 0), 'ddb-spots'), (int) ($total_visible ?? 0))); ?></h2>
				</div>
			</header>

			<?php if (! empty($regular_posts ?? array())) : ?>
				<div class="ddb-spots-grid">
					<?php foreach ($regular_posts as $post) : ?>
						<?php echo $this->render_listing_spot_card((int) $post->ID, false, $origin_lat ?? null, $origin_lng ?? null); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<p class="ddb-listing-empty"><?php esc_html_e('Geen spots gevonden voor deze filters.', 'ddb-spots'); ?></p>
			<?php endif; ?>

			<?php if (! empty($pagination ?? '')) : ?>
				<nav class="ddb-spots-pagination" aria-label="<?php esc_attr_e('Spots pagina navigatie', 'ddb-spots'); ?>"><?php echo wp_kses_post((string) $pagination); ?></nav>
			<?php endif; ?>
		</section>

				<aside class="ddb-listing-map ddb-spots-rail<?php echo ! empty($map_points ?? array()) ? ' is-ready' : ''; ?>" data-ddb-map-pane>
					<div class="ddb-listing-map__sticky ddb-spots-rail__sticky">
						<section class="ddb-map-shell ui-summary ui-summary--compact ddb-summary ddb-card ddb-card--raised">
							<div class="ui-card__body ddb-card__body">
						<div class="ddb-map-shell__head">
								<div>
									<h3><?php esc_html_e('Kaart & selectie', 'ddb-spots'); ?></h3>
								</div>
						</div>
						<?php if (! empty($map_points ?? array()) && ! empty($active_map ?? null)) : ?>
							<div class="ddb-map-shell__fallback" data-ddb-map-fallback>
								<div class="ddb-map-shell__fallback-grid" aria-hidden="true">
									<span class="ddb-map-shell__blob ddb-map-shell__blob--one"></span>
									<span class="ddb-map-shell__blob ddb-map-shell__blob--two"></span>
									<span class="ddb-map-shell__pin ddb-map-shell__pin--one"></span>
									<span class="ddb-map-shell__pin ddb-map-shell__pin--two"></span>
									<span class="ddb-map-shell__pin ddb-map-shell__pin--three"></span>
								</div>
								<div class="ddb-map-shell__focus" data-ddb-map-focus>
									<p class="ddb-map-shell__eyebrow"><?php esc_html_e('Kaartpreview', 'ddb-spots'); ?></p>
									<strong data-ddb-map-title><?php echo esc_html((string) $active_map['title']); ?></strong>
									<span data-ddb-map-address><?php echo esc_html((string) $active_map['address']); ?></span>
									<a data-ddb-map-link href="<?php echo esc_url((string) $active_map['maps_url']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open route', 'ddb-spots'); ?></a>
								</div>
							</div>
						<?php else : ?>
							<p class="ddb-map-shell__empty"><?php esc_html_e('Voor deze selectie zijn nog geen kaartcoördinaten beschikbaar.', 'ddb-spots'); ?></p>
						<?php endif; ?>
					</div>
				</section>

				<?php if (! empty($selected_snapshot ?? array())) : ?>
					<section class="ddb-selected-spot ui-summary ui-summary--compact ddb-summary ddb-card ddb-card--selected" data-ddb-selected-panel data-ddb-spot-id="<?php echo esc_attr((string) $selected_snapshot['id']); ?>">
						<div class="ui-card__body ddb-card__body">
							<p class="ddb-selected-spot__eyebrow"><?php esc_html_e('Geselecteerde plek', 'ddb-spots'); ?></p>
							<h3 class="ddb-selected-spot__title ddb-card__title" data-ddb-selected-title><?php echo esc_html((string) $selected_snapshot['title']); ?></h3>
							<p class="ddb-selected-spot__meta" data-ddb-selected-meta><?php echo esc_html(trim(implode(' · ', array_filter(array((string) $selected_snapshot['type_label'], (string) $selected_snapshot['area_label'], (string) $selected_snapshot['price_label_text'], ! empty($selected_snapshot['rating']) ? number_format((float) $selected_snapshot['rating'], 1) . ' ★' : ''))))); ?></p>
							<p class="ddb-selected-spot__summary" data-ddb-selected-summary><?php echo esc_html((string) $selected_snapshot['summary']); ?></p>
							<div class="ddb-selected-spot__tags" data-ddb-selected-tags>
								<?php foreach (array_slice((array) ($selected_snapshot['tags'] ?? array()), 0, 3) as $tag) : ?>
									<span class="ui-badge ui-badge--secondary ddb-chip"><?php echo esc_html((string) $tag); ?></span>
								<?php endforeach; ?>
							</div>
							<div class="ddb-selected-spot__actions">
								<a class="ui-btn ui-btn--primary ddb-button ddb-button--primary" data-ddb-selected-view href="<?php echo esc_url((string) $selected_snapshot['link']); ?>"><?php esc_html_e('Bekijk plek', 'ddb-spots'); ?></a>
								<?php if ('' !== trim($selected_route_url)) : ?>
									<a class="ui-btn ui-btn--secondary ddb-button ddb-button--secondary" data-ddb-selected-route href="<?php echo esc_url($selected_route_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Route', 'ddb-spots'); ?></a>
								<?php endif; ?>
								<button type="button" class="ui-btn ui-btn--ghost ddb-button ddb-button--ghost" data-ddb-add-to-day data-ddb-spot-id="<?php echo esc_attr((string) $selected_snapshot['id']); ?>" data-ddb-spot-type="<?php echo esc_attr((string) $selected_snapshot['type_slug']); ?>"><?php esc_html_e('Voeg toe aan dag', 'ddb-spots'); ?></button>
							</div>
						</div>
					</section>
				<?php endif; ?>

				<?php if (! empty($selected_context_lines ?? array())) : ?>
					<section class="ddb-selected-context ui-summary ui-summary--compact ddb-summary ddb-card" data-ddb-selected-context>
						<div class="ui-card__body ddb-card__body">
							<p class="ddb-selected-context__eyebrow"><?php esc_html_e('Handige context', 'ddb-spots'); ?></p>
							<ul class="ddb-selected-context__list">
								<?php foreach (array_slice((array) $selected_context_lines, 0, 3) as $line) : ?>
									<li><?php echo esc_html((string) $line); ?></li>
								<?php endforeach; ?>
							</ul>
						</div>
					</section>
				<?php endif; ?>
			</div>
		</aside>
	</div>
</div>
