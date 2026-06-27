<?php
if (! defined('ABSPATH')) {
	exit;
}

class DDB_Spots_Frontend_Render {
	private DDB_Spots $plugin;

	public function __construct(DDB_Spots $plugin) {
		$this->plugin = $plugin;
	}

	public function init(): void {
		remove_filter('the_content', array($this->plugin, 'append_single_spot_content'));
		add_filter('the_content', array($this, 'append_single_spot_content'), 20);
	}

	public function append_single_spot_content(string $content): string {
		if (! is_singular('ddb_spot') || ! in_the_loop() || ! is_main_query()) {
			return $content;
		}
		$spot_id = (int) get_the_ID();
		if ($spot_id <= 0) {
			return $content;
		}

		$reserve_cta = $this->plugin->render_cta_shortcode(array('id' => $spot_id));
		$layout = $this->build_spot_layout($spot_id, $content, $reserve_cta);
		return '' !== $layout ? $layout : $content;
	}

	private function build_spot_layout(int $spot_id, string $content, string $reserve_cta): string {
		$title = trim((string) get_the_title($spot_id));
		if ('' === $title) {
			return '';
		}

		$type_slug = $this->get_primary_type_slug($spot_id);
		$type_label = $this->get_primary_term_label($spot_id, 'ddb_spot_type');
		$area_label = $this->get_primary_term_label($spot_id, 'ddb_area');
		$summary = mb_substr($this->resolve_summary($spot_id, $content), 0, 96);

		$address = trim((string) get_post_meta($spot_id, '_ddb_address', true));
		$city = trim((string) get_post_meta($spot_id, '_ddb_city', true));
		$region = trim((string) get_post_meta($spot_id, '_ddb_region', true));
		$country = trim((string) get_post_meta($spot_id, '_ddb_country', true));
		$location = trim(implode(', ', array_filter(array($address, $city, $region, $country))));
		$phone = trim((string) get_post_meta($spot_id, '_ddb_google_phone', true));
		$website = trim((string) get_post_meta($spot_id, '_ddb_google_website', true));
		$maps_url = trim((string) get_post_meta($spot_id, '_ddb_google_maps_url', true));
		$lat = $this->to_float_or_null((string) get_post_meta($spot_id, '_ddb_lat', true));
		$lng = $this->to_float_or_null((string) get_post_meta($spot_id, '_ddb_lng', true));
		if ('' === $maps_url && null !== $lat && null !== $lng) {
			$maps_url = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($lat . ',' . $lng);
		}

		$duration_hint = absint((int) get_post_meta($spot_id, '_ddb_duration_hint', true));
		$group_max = absint((int) get_post_meta($spot_id, '_ddb_group_max', true));
		$rating = (float) get_post_meta($spot_id, '_ddb_google_rating', true);
		$rating_total = absint((int) get_post_meta($spot_id, '_ddb_google_user_ratings_total', true));

		$images = $this->collect_images($spot_id);
		$hero_image = '' !== $images['hero'] ? $images['hero'] : '<div class="ddb-node-hero__empty"></div>';
		$add_to_day = $this->build_add_to_day_button($spot_id, $type_slug);
		$secondary_cta = $this->build_secondary_cta($reserve_cta, $spot_id, $type_slug, $maps_url);
		$sticky_secondary = '' !== $secondary_cta ? $secondary_cta : ($maps_url ? '<a class="ddb-node-btn ddb-node-btn--subtle" href="' . esc_url($maps_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Route', 'ddb-spots') . '</a>' : '');

		$premium_labels = $this->render_premium_labels($spot_id);
		$context_snapshot = $this->build_context_snapshot($spot_id);
		$context_chips = $this->render_context_chips($context_snapshot);
		$day_context = $this->build_day_context_items($spot_id, $area_label, $duration_hint, $group_max);
		$highlights = $this->build_experience_highlights($spot_id);
		$bundles = ddb_get_smart_combinations($spot_id);
		$reviews = $this->parse_reviews((string) get_post_meta($spot_id, '_ddb_google_reviews_json', true));
		$hours_lines = $this->extract_hours_lines((string) get_post_meta($spot_id, '_ddb_google_opening_hours_json', true));

		$rating_markup = $rating > 0
			? '<span class="ddb-node-hero__rating">' . esc_html(number_format($rating, 1)) . '</span><span class="ddb-node-hero__reviews">' . esc_html(sprintf(_n('%d review', '%d reviews', max(1, $rating_total), 'ddb-spots'), max(1, $rating_total))) . '</span>'
			: '<span class="ddb-node-hero__reviews">' . esc_html__('Nieuwe spot', 'ddb-spots') . '</span>';
		$type_badge = '' !== $type_label ? '<span class="ui-badge ddb-node-badge">' . esc_html($type_label) . '</span>' : '';
		$area_badge = '' !== $area_label ? '<span class="ui-badge ddb-node-badge ddb-node-badge--ghost">' . esc_html($area_label) . '</span>' : '';
		$subtitle_markup = '' !== $summary ? '<p class="ddb-node-hero__subtitle">' . esc_html($summary) . '</p>' : '';
		$location_markup = '' !== $location ? '<p class="ddb-node-hero__location">' . esc_html($location) . '</p>' : '';

		$schema = $this->build_schema_json_ld($spot_id, array(
			'title' => $title,
			'summary' => $summary,
			'type_slug' => $type_slug,
			'address' => $address,
			'city' => $city,
			'region' => $region,
			'country' => $country,
			'lat' => $lat,
			'lng' => $lng,
			'rating' => $rating,
			'rating_total' => $rating_total,
			'phone' => $phone,
			'website' => $website,
		));

		$planner_hook_markup = $this->capture_add_to_day_hook_markup($spot_id);
		$show_theme_button = (bool) apply_filters('ddb_spots_show_legacy_theme_button', false, $spot_id, 'single');
		$theme_button = $show_theme_button ? '<button type="button" class="ddb-node-btn ddb-node-btn--ghost" data-ddb-theme-toggle data-light-label="' . esc_attr__('Lichte modus', 'ddb-spots') . '" data-dark-label="' . esc_attr__('Donkere modus', 'ddb-spots') . '">' . esc_html__('Thema', 'ddb-spots') . '</button>' : '';
		$section_nav = $this->render_section_nav();

		$description_markup = $this->build_description_markup($content, $spot_id);

		return '<article class="ddb-node" data-ddb-spot-id="' . esc_attr((string) $spot_id) . '" data-ddb-spot-type="' . esc_attr($type_slug) . '">' .
			'<header class="ddb-node-hero">' .
				'<div class="ddb-node-hero__media">' . $hero_image . '</div>' .
				'<div class="ddb-node-hero__overlay"></div>' .
				'<div class="ddb-node-hero__content">' .
					'<div class="ddb-node-hero__topline">' . $type_badge . $area_badge . $premium_labels . '</div>' .
					'<h1 class="ddb-node-hero__title">' . esc_html($title) . '</h1>' .
					$subtitle_markup .
					'<div class="ddb-node-hero__meta">' . $rating_markup . '</div>' .
					$context_chips .
					$location_markup .
					'<div class="ddb-node-hero__cta">' . $add_to_day . $secondary_cta . $theme_button . '</div>' .
				'</div>' .
			'</header>' .
			$planner_hook_markup .
			$section_nav .
			$this->render_main_sections($spot_id, $description_markup, $context_snapshot, $area_label, $day_context, $highlights, $bundles, $reviews, $location, $phone, $website, $hours_lines, $lat, $lng, $maps_url) .
			'<div class="ddb-node-sticky" data-ddb-sticky-cta>' .
				'<div class="ddb-node-sticky__title">' . esc_html($title) . '</div>' .
				'<div class="ddb-node-sticky__actions">' . $add_to_day . $sticky_secondary . '</div>' .
			'</div>' .
			$schema .
		'</article>';
	}

	private function render_main_sections(int $spot_id, string $description_markup, array $context_snapshot, string $area_label, array $day_context, array $highlights, array $bundles, array $reviews, string $location, string $phone, string $website, array $hours_lines, ?float $lat, ?float $lng, string $maps_url): string {
		$day_cards = '';
		foreach ($day_context as $reason) {
			$day_cards .= '<li>' . esc_html($reason) . '</li>';
		}

		$sections = array(
			'<section class="ui-surface ui-surface--elevated ddb-node-section" id="omschrijving"><h2>' . esc_html__('Over deze plek', 'ddb-spots') . '</h2><div class="ddb-node-description">' . $description_markup . '</div></section>',
			'<section class="ui-surface ui-surface--elevated ddb-node-section ddb-node-section--sidebar" id="praktisch"><h2>' . esc_html__('Praktisch', 'ddb-spots') . '</h2>' . $this->render_practical_markup($spot_id, $location, $phone, $website, $hours_lines, $lat, $lng, $maps_url) . '</section>',
			'<section class="ui-surface ui-surface--elevated ddb-node-section ddb-node-section--sidebar ddb-node-section--context" id="dag-context"><h2>' . esc_html__('Past in je dag', 'ddb-spots') . '</h2>' . $this->render_context_snapshot($context_snapshot, $area_label) . '</section>',
		);

		if ('' !== $day_cards) {
			// Append day context items to the sidebar 'Past in je dag' section
			$sections[2] = '<section class="ui-surface ui-surface--elevated ddb-node-section ddb-node-section--sidebar ddb-node-section--context" id="dag-context"><h2>' . esc_html__('Past in je dag', 'ddb-spots') . '</h2>' . $this->render_context_snapshot($context_snapshot, $area_label) . '<ul class="ddb-node-why-list">' . $day_cards . '</ul></section>';
		}

		$sections[] = '<section class="ui-surface ui-surface--elevated ddb-node-section" id="reviews"><h2>' . esc_html__('Reviews', 'ddb-spots') . '</h2>' . $this->render_reviews_markup($reviews) . '</section>';

		if (! empty($highlights)) {
			$highlight_list = '';
			foreach ($highlights as $item) {
				$highlight_list .= '<li>' . esc_html($item) . '</li>';
			}
			$sections[] = '<section class="ui-surface ui-surface--elevated ddb-node-section" id="experience-highlights"><h2>' . esc_html__('Hoogtepunten', 'ddb-spots') . '</h2><ul class="ddb-node-highlight-list">' . $highlight_list . '</ul></section>';
		}

		$sections[] = '<section class="ui-surface ui-surface--elevated ddb-node-section" id="maak-je-dag-compleet"><h2>' . esc_html__('Combineer', 'ddb-spots') . '</h2>' . $this->render_bundle_cards($bundles) . '</section>';

		return '<div class="ddb-node-main">' . implode('', $sections) . '</div>';
	}

	private function render_section_nav(): string {
		$links = array(
			array('id' => 'omschrijving', 'label' => __('Over deze plek', 'ddb-spots')),
			array('id' => 'praktisch', 'label' => __('Praktisch', 'ddb-spots')),
			array('id' => 'dag-context', 'label' => __('Past in je dag', 'ddb-spots')),
			array('id' => 'reviews', 'label' => __('Reviews', 'ddb-spots')),
			array('id' => 'maak-je-dag-compleet', 'label' => __('Combineer', 'ddb-spots')),
		);
		$out = '<nav class="ddb-node-nav" data-ddb-section-nav aria-label="' . esc_attr__('Spot secties', 'ddb-spots') . '">';
		foreach ($links as $index => $link) {
			$out .= '<a href="#' . esc_attr((string) $link['id']) . '"' . (0 === $index ? ' class="is-active"' : '') . '>' . esc_html((string) $link['label']) . '</a>';
		}
		$out .= '</nav>';
		return $out;
	}

	private function build_description_markup(string $content, int $spot_id): string {
		$content = trim($content);
		if ('' !== $content) {
			return $this->build_compact_description_markup($content);
		}

		$fallback = trim((string) get_post_field('post_excerpt', $spot_id));
		if ('' === $fallback) {
			$fallback = trim((string) get_post_meta($spot_id, '_ddb_google_editorial_summary', true));
		}
		if ('' === $fallback) {
			return '<p>' . esc_html__('Nog geen omschrijving toegevoegd.', 'ddb-spots') . '</p>';
		}

		return '<p>' . esc_html($fallback) . '</p>';
	}

	private function build_compact_description_markup(string $content): string {
		$content = trim(wp_kses_post($content));
		if ('' === $content) {
			return '';
		}

		$has_multiple_blocks = preg_match_all('/<(p|ul|ol|h2|h3|h4|blockquote)\b/i', $content) > 1;
		$word_count = str_word_count(wp_strip_all_tags($content));
		if (! $has_multiple_blocks && $word_count <= 70) {
			return $content;
		}

		$intro = '';
		$rest = '';
		if (preg_match('/<p\b[^>]*>.*?<\/p>/is', $content, $matches)) {
			$intro = (string) $matches[0];
			$rest = trim(preg_replace('/' . preg_quote($intro, '/') . '/is', '', $content, 1));
		}

		if ('' === $intro) {
			$plain = trim(wp_strip_all_tags($content));
			if ('' === $plain) {
				return $content;
			}
			return '<p>' . esc_html(wp_trim_words($plain, 42, '...')) . '</p>';
		}

		if ('' === $rest) {
			return $intro;
		}

		return $intro .
			'<details class="ddb-node-description__more">' .
				'<summary>' . esc_html__('Lees meer', 'ddb-spots') . '</summary>' .
				'<div class="ddb-node-description__body">' . $rest . '</div>' .
			'</details>';
	}
	private function render_bundle_cards(array $bundles): string {
		if (empty($bundles)) {
			return '<p class="ddb-node-empty">' . esc_html__('Nog geen slimme combinaties beschikbaar voor deze spot.', 'ddb-spots') . '</p>';
		}

		$cards = '<div class="ddb-node-bundle-grid">';
		foreach (array_slice($bundles, 0, 3) as $bundle) {
			$title = (string) ($bundle['title'] ?? '');
			$summary = (string) ($bundle['summary'] ?? '');
			$minutes = absint((int) ($bundle['estimated_minutes'] ?? 0));
			$range = (string) ($bundle['price_range'] ?? '');
			$url = (string) ($bundle['add_to_day_url'] ?? '');
			$spot_ids = isset($bundle['spot_ids']) && is_array($bundle['spot_ids']) ? array_map('absint', $bundle['spot_ids']) : array();
			$spot_ids = array_values(array_filter($spot_ids));
			$label = '' !== $title ? $title : __('Slimme combinatie', 'ddb-spots');

			$cards .= '<article class="ddb-node-bundle"><h3>' . esc_html($label) . '</h3>';
			if ('' !== $summary) {
				$cards .= '<p>' . esc_html(wp_trim_words($summary, 16, '...')) . '</p>';
			}
			$cards .= '<div class="ddb-node-bundle__meta">';
			if ($minutes > 0) {
				$cards .= '<span>' . esc_html(sprintf(__('%d min totaal', 'ddb-spots'), $minutes)) . '</span>';
			}
			if ('' !== $range) {
				$cards .= '<span>' . esc_html($range) . '</span>';
			}
			$cards .= '</div>';
			if ('' !== $url) {
				$cards .= '<a class="ui-btn ui-btn--secondary ddb-node-btn ddb-node-btn--subtle" href="' . esc_url($url) . '" data-ddb-track="module_event" data-ddb-context="bundle" data-ddb-cta-type="bundle_add" data-ddb-module="bundle_engine" data-ddb-spot-ids="' . esc_attr(implode(',', $spot_ids)) . '">' . esc_html__('Voeg beide toe', 'ddb-spots') . '</a>';
			}
			$cards .= '</article>';
		}
		$cards .= '</div>';
		return $cards;
	}

	private function render_reviews_markup(array $reviews): string {
		if (empty($reviews)) {
			return '<p class="ddb-node-empty">' . esc_html__('Nog geen reviews beschikbaar.', 'ddb-spots') . '</p>';
		}

		$out = '<div class="ddb-node-review-list">';
		foreach (array_slice($reviews, 0, 1) as $review) {
			$out .= $this->render_review_item($review);
		}
		$out .= '</div>';
		$rest = array_slice($reviews, 1);
		if (! empty($rest)) {
			$out .= '<details class="ddb-node-review-more"><summary>' . esc_html(sprintf(_n('%d meer review tonen', '%d meer reviews tonen', count($rest), 'ddb-spots'), count($rest))) . '</summary><div class="ddb-node-review-list">';
			foreach ($rest as $review) {
				$out .= $this->render_review_item($review);
			}
			$out .= '</div></details>';
		}
		return $out;
	}

	private function render_review_item(array $review): string {
		$author = (string) ($review['author_name'] ?? __('Bezoeker', 'ddb-spots'));
		$rating = isset($review['rating']) ? (float) $review['rating'] : 0.0;
		$text = trim((string) ($review['text'] ?? ''));
		if ('' === $text) {
			$text = __('Geen tekstreview beschikbaar.', 'ddb-spots');
		}

		$rating_markup = $rating > 0 ? sprintf('<span class="ddb-review-stars">%s/5</span>', number_format($rating, 1)) : '';

		return '<article class="ddb-node-review">' .
					'<header class="ddb-review-header">' .
						'<div class="ddb-review-author"><strong>' . esc_html($author) . '</strong></div>' .
						$rating_markup .
					'</header>' .
					'<div class="ddb-review-content"><p>' . esc_html(wp_trim_words($text, 35, '...')) . '</p></div>' .
				'</article>';
	}

	private function render_practical_markup(int $spot_id, string $location, string $phone, string $website, array $hours_lines, ?float $lat, ?float $lng, string $maps_url): string {
		$rows = array();
		$actions = array();
		if ('' !== $location) {
			$rows[] = '<div><dt>' . esc_html__('Adres', 'ddb-spots') . '</dt><dd>' . esc_html($location) . '</dd></div>';
		}
		if ('' !== $phone) {
			$tel = preg_replace('/[^0-9+]/', '', $phone);
			$val = '' !== (string) $tel ? '<a href="' . esc_url('tel:' . $tel) . '">' . esc_html($phone) . '</a>' : esc_html($phone);
			$rows[] = '<div><dt>' . esc_html__('Telefoon', 'ddb-spots') . '</dt><dd>' . $val . '</dd></div>';
			if ('' !== (string) $tel) {
				$actions[] = '<a class="ui-btn ui-btn--secondary ddb-node-btn ddb-node-btn--subtle" href="' . esc_url('tel:' . $tel) . '">' . esc_html__('Bel direct', 'ddb-spots') . '</a>';
			}
		}
		if ('' !== $website && $this->is_affiliate_website($spot_id, $website)) {
			$host = (string) (wp_parse_url($website, PHP_URL_HOST) ?: $website);
			$rows[] = '<div><dt>' . esc_html__('Website', 'ddb-spots') . '</dt><dd><a href="' . esc_url($website) . '" target="_blank" rel="noopener noreferrer sponsored">' . esc_html($host) . '</a></dd></div>';
			$actions[] = '<a class="ui-btn ui-btn--ghost ddb-node-btn ddb-node-btn--ghost" href="' . esc_url($website) . '" target="_blank" rel="noopener noreferrer sponsored">' . esc_html__('Website', 'ddb-spots') . '</a>';
		}
		if ('' !== $maps_url) {
			$actions[] = '<a class="ui-btn ui-btn--ghost ddb-node-btn ddb-node-btn--ghost" href="' . esc_url($maps_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Route plannen', 'ddb-spots') . '</a>';
		}

		$hours_markup = '';
		if (! empty($hours_lines)) {
			$hours_markup = '<details class="ddb-node-hours"><summary>' . esc_html__('Openingstijden', 'ddb-spots') . '</summary><ul>';
			foreach ($hours_lines as $line) {
				$hours_markup .= '<li>' . esc_html($line) . '</li>';
			}
			$hours_markup .= '</ul></details>';
		}

		$map_markup = '';
		if (null !== $lat && null !== $lng) {
			$map_markup = '<button type="button" class="ui-btn ui-btn--ghost ddb-node-btn ddb-node-btn--ghost" data-ddb-map-expand>' . esc_html__('Toon kaart', 'ddb-spots') . '</button>' .
				'<div class="ddb-node-map" data-ddb-map-canvas hidden>' .
					'<iframe title="' . esc_attr__('Locatiekaart', 'ddb-spots') . '" src="' . esc_url($this->build_openstreetmap_embed_url($lat, $lng)) . '" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>' .
					('' !== $maps_url ? '<a href="' . esc_url($maps_url) . '" target="_blank" rel="noopener noreferrer" class="ddb-node-map__route" data-ddb-track="cta_click" data-ddb-context="practical_map" data-ddb-spot-id="' . esc_attr((string) $spot_id) . '" data-ddb-cta-type="route">' . esc_html__('Open route', 'ddb-spots') . '</a>' : '') .
				'</div>';
		}

		$actions_markup = '';
		if (! empty($actions)) {
			$actions_markup = '<div class="ddb-node-practical__actions">' . implode('', $actions) . '</div>';
		}

		return '<div class="ddb-node-practical">' . $actions_markup . '<dl>' . implode('', $rows) . '</dl>' . $hours_markup . $map_markup . '</div>';
	}

	private function build_schema_json_ld(int $spot_id, array $data): string {
		$schema_type = in_array((string) ($data['type_slug'] ?? ''), array('restaurant', 'restaurants'), true) ? 'Restaurant' : 'LocalBusiness';
		$schema = array(
			'@context' => 'https://schema.org',
			'@type' => $schema_type,
			'@id' => get_permalink($spot_id) . '#spot',
			'name' => (string) ($data['title'] ?? ''),
			'description' => (string) ($data['summary'] ?? ''),
			'url' => (string) get_permalink($spot_id),
		);
		$addr = array_filter(array(
			'streetAddress' => (string) ($data['address'] ?? ''),
			'addressLocality' => (string) ($data['city'] ?? ''),
			'addressRegion' => (string) ($data['region'] ?? ''),
			'addressCountry' => (string) ($data['country'] ?? ''),
		));
		if (! empty($addr)) {
			$schema['address'] = array_merge(array('@type' => 'PostalAddress'), $addr);
		}
		$lat = $data['lat'] ?? null;
		$lng = $data['lng'] ?? null;
		if (is_float($lat) && is_float($lng)) {
			$schema['geo'] = array('@type' => 'GeoCoordinates', 'latitude' => $lat, 'longitude' => $lng);
		}
		if ((float) $data['rating'] > 0 && (int) $data['rating_total'] > 0) {
			$schema['aggregateRating'] = array('@type' => 'AggregateRating', 'ratingValue' => round((float) $data['rating'], 1), 'reviewCount' => absint((int) $data['rating_total']));
		}
		if ('' !== trim((string) ($data['phone'] ?? ''))) {
			$schema['telephone'] = (string) $data['phone'];
		}
		if ('' !== trim((string) ($data['website'] ?? ''))) {
			$schema['sameAs'] = array((string) $data['website']);
		}
		return '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
	}
	private function capture_add_to_day_hook_markup(int $spot_id): string {
		ob_start();
		do_action('ddb_add_to_day', $spot_id);
		$markup = (string) ob_get_clean();
		return '' !== trim($markup) ? '<div class="ddb-node-planner-hook">' . $markup . '</div>' : '';
	}

	private function build_context_snapshot(int $spot_id): array {
		$best_time = sanitize_key((string) get_post_meta($spot_id, '_ddb_best_time_slot', true));
		$weather = sanitize_key((string) get_post_meta($spot_id, '_ddb_weather_compatibility', true));
		$group_fit = max(0, min(100, absint((int) get_post_meta($spot_id, '_ddb_group_fit_score', true))));
		$walk_distance = max(0, absint((int) get_post_meta($spot_id, '_ddb_walk_distance_to_core', true)));

		$best_time_labels = array(
			'morning' => __('Ochtend', 'ddb-spots'),
			'lunch' => __('Lunch', 'ddb-spots'),
			'afternoon' => __('Middag', 'ddb-spots'),
			'evening' => __('Avond', 'ddb-spots'),
		);
		$weather_labels = array(
			'rainproof' => __('Regenproof', 'ddb-spots'),
			'outdoor' => __('Outdoor', 'ddb-spots'),
		);

		return array(
			'best_time_slot' => $best_time,
			'best_time_label' => (string) ($best_time_labels[ $best_time ] ?? __('Flexibel', 'ddb-spots')),
			'weather_compatibility' => $weather,
			'weather_label' => (string) ($weather_labels[ $weather ] ?? __('Alle weersomstandigheden', 'ddb-spots')),
			'group_fit_score' => $group_fit,
			'walk_distance_to_core' => $walk_distance,
		);
	}

	private function render_context_snapshot(array $snapshot, string $area_label): string {
		$chips = array();
		if ('' !== $area_label) {
			$chips[] = $area_label;
		}

		$best_time = trim((string) ($snapshot['best_time_label'] ?? ''));
		if ('' !== $best_time && ! in_array($best_time, array(__('Flexibel', 'ddb-spots')), true)) {
			$chips[] = $best_time;
		}

		$weather = trim((string) ($snapshot['weather_label'] ?? ''));
		if ('' !== $weather) {
			$chips[] = $weather;
		}

		$group_fit = absint((int) ($snapshot['group_fit_score'] ?? 0));
		if ($group_fit >= 70) {
			$chips[] = __('Groepen', 'ddb-spots');
		}

		$chips = array_values(array_unique(array_filter($chips)));
		if (empty($chips)) {
			return '<p class="ddb-node-empty">' . esc_html__('Nog geen extra context beschikbaar.', 'ddb-spots') . '</p>';
		}

		$out = '<ul class="ddb-node-context-chips">';
		foreach ($chips as $chip) {
			$out .= '<li class="ui-chip ui-chip--muted">' . esc_html((string) $chip) . '</li>';
		}
		$out .= '</ul>';
		return $out;
	}

	private function render_context_chips(array $snapshot): string {
		$chips = array();
		$best_time = trim((string) ($snapshot['best_time_label'] ?? ''));
		if ('' !== $best_time && ! in_array($best_time, array(__('Flexibel', 'ddb-spots')), true)) {
			$chips[] = $best_time;
		}

		$weather = trim((string) ($snapshot['weather_label'] ?? ''));
		if ('' !== $weather) {
			$chips[] = $weather;
		}

		if (absint((int) ($snapshot['group_fit_score'] ?? 0)) >= 70) {
			$chips[] = __('Groepen', 'ddb-spots');
		}

		if (empty($chips)) {
			return '';
		}

		$out = '<ul class="ddb-node-hero__context">';
		foreach ($chips as $chip) {
			$out .= '<li class="ui-chip">' . esc_html((string) $chip) . '</li>';
		}
		$out .= '</ul>';
		return $out;
	}

	private function build_day_context_items(int $spot_id, string $area_label, int $duration_hint, int $group_max): array {
		$items = array();
		$best_time = sanitize_key((string) get_post_meta($spot_id, '_ddb_best_time_slot', true));
		$weather = sanitize_key((string) get_post_meta($spot_id, '_ddb_weather_compatibility', true));
		$group_fit = max(0, min(100, absint((int) get_post_meta($spot_id, '_ddb_group_fit_score', true))));
		$walk_distance = max(0, absint((int) get_post_meta($spot_id, '_ddb_walk_distance_to_core', true)));
		$time_map = array(
			'morning' => __('Perfect als ochtendstart', 'ddb-spots'),
			'lunch' => __('Ideaal voor lunchmoment', 'ddb-spots'),
			'afternoon' => __('Sterk voor een middagslot', 'ddb-spots'),
			'evening' => __('Mooie avondstop in je route', 'ddb-spots'),
		);
		if (isset($time_map[ $best_time ])) {
			$items[] = $time_map[ $best_time ];
		}
		if ('rainproof' === $weather) {
			$items[] = __('Regen-proof binnenruimte', 'ddb-spots');
		} elseif ('outdoor' === $weather) {
			$items[] = __('Buitenbeleving bij goed weer', 'ddb-spots');
		}
		if ($walk_distance > 0) {
			$items[] = sprintf(__('%d min lopen vanaf de kern', 'ddb-spots'), $walk_distance);
		} elseif ('' !== $area_label) {
			$items[] = sprintf(__('Slim te combineren in %s', 'ddb-spots'), $area_label);
		}
		if ($group_fit >= 70) {
			$items[] = __('Goede match voor groepen', 'ddb-spots');
		}
		if ($duration_hint > 0 && $duration_hint <= 90) {
			$items[] = sprintf(__('Past in een compact slot van %d minuten', 'ddb-spots'), $duration_hint);
		}
		if ($group_max >= 6) {
			$items[] = sprintf(__('Werkt ook voor %d+ personen', 'ddb-spots'), $group_max);
		}
		if (empty($items)) {
			$items = array(__('Logische stop in je stadsroute', 'ddb-spots'), __('Makkelijk te combineren met een tweede spot', 'ddb-spots'), __('Past in een planbare dagstructuur', 'ddb-spots'));
		}
		return array_slice($items, 0, 4);
	}

	private function build_experience_highlights(int $spot_id): array {
		$items = array();
		$highlights = json_decode((string) get_post_meta($spot_id, '_ddb_highlights_json', true), true);
		if (is_array($highlights)) {
			foreach ($highlights as $line) {
				$raw_line = trim((string) $line);
				if ($this->contains_unclean_markup($raw_line)) {
					return array();
				}
				$clean_line = trim(sanitize_text_field(wp_strip_all_tags(html_entity_decode($raw_line, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
				if ('' !== $clean_line) {
					$items[] = $clean_line;
				}
			}
		}
		return array_slice(array_values(array_unique($items)), 0, 4);
	}

	private function contains_unclean_markup(string $value): bool {
		$value = trim($value);
		if ('' === $value) {
			return false;
		}

		$needles = array('<', '>', '&lt;', '&gt;', 'class=', '</', '/>', 'span', 'div');
		foreach ($needles as $needle) {
			if (false !== stripos($value, $needle)) {
				return true;
			}
		}

		return false;
	}

	private function parse_reviews(string $json): array {
		$reviews = json_decode($json, true);
		if (! is_array($reviews)) {
			return array();
		}
		$out = array();
		foreach ($reviews as $row) {
			if (! is_array($row)) {
				continue;
			}
			$text = trim((string) ($row['text'] ?? ''));
			$author = trim((string) ($row['author_name'] ?? ''));
			if ('' === $text && '' === $author) {
				continue;
			}
			$out[] = array('author_name' => '' !== $author ? $author : __('Bezoeker', 'ddb-spots'), 'rating' => (float) ($row['rating'] ?? 0), 'text' => $text);
		}
		return $out;
	}

	private function extract_hours_lines(string $json): array {
		$hours = json_decode($json, true);
		if (! is_array($hours) || ! isset($hours['weekday_text']) || ! is_array($hours['weekday_text'])) {
			return array();
		}
		$out = array();
		foreach ($hours['weekday_text'] as $line) {
			$line = trim(preg_replace('/\s+/', ' ', str_replace(array('\\u202f', '\\u2009', '\\u2013'), array(' ', ' ', ' - '), html_entity_decode((string) $line, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
			if ('' !== $line) {
				$out[] = $line;
			}
		}
		return $out;
	}

	private function resolve_summary(int $spot_id, string $content): string {
		$summary = trim((string) get_post_field('post_excerpt', $spot_id));
		if ('' !== $summary) {
			return $summary;
		}
		$summary = trim((string) get_post_meta($spot_id, '_ddb_google_editorial_summary', true));
		if ('' !== $summary) {
			return $summary;
		}
		$plain = trim(wp_strip_all_tags($content));
		return '' !== $plain ? (string) wp_trim_words($plain, 22, '...') : '';
	}

	private function build_secondary_cta(string $reserve_cta, int $spot_id, string $type_slug, string $maps_url): string {
		if ('' !== trim($reserve_cta)) {
			return '<div class="ddb-node-reserve" data-ddb-track="cta_click" data-ddb-context="single_secondary" data-ddb-spot-id="' . esc_attr((string) $spot_id) . '" data-ddb-spot-type="' . esc_attr($type_slug) . '" data-ddb-cta-type="reserve">' . $reserve_cta . '</div>';
		}
		if ('' !== $maps_url) {
			return '<a class="ui-btn ui-btn--secondary ddb-node-btn ddb-node-btn--subtle" href="' . esc_url($maps_url) . '" target="_blank" rel="noopener noreferrer" data-ddb-track="cta_click" data-ddb-context="single_secondary" data-ddb-spot-id="' . esc_attr((string) $spot_id) . '" data-ddb-spot-type="' . esc_attr($type_slug) . '" data-ddb-cta-type="route">' . esc_html__('Route', 'ddb-spots') . '</a>';
		}
		return '';
	}

	private function get_primary_type_slug(int $spot_id): string {
		$meta = sanitize_title((string) get_post_meta($spot_id, '_ddb_spot_type_primary', true));
		if ('' !== $meta) {
			return $meta;
		}
		$terms = wp_get_post_terms($spot_id, 'ddb_spot_type', array('fields' => 'slugs'));
		if (is_wp_error($terms) || empty($terms)) {
			return '';
		}
		return (string) $terms[0];
	}

	private function get_primary_term_label(int $spot_id, string $taxonomy): string {
		$terms = wp_get_post_terms($spot_id, $taxonomy);
		if (is_wp_error($terms) || empty($terms) || ! isset($terms[0]) || ! $terms[0] instanceof WP_Term) {
			return '';
		}
		return (string) $terms[0]->name;
	}
	private function collect_images(int $spot_id): array {
		$gallery_ids = array_values(array_filter(array_map('absint', array_map('trim', explode(',', (string) get_post_meta($spot_id, '_ddb_gallery_ids', true))))));
		$hero = '';
		foreach (array_slice($gallery_ids, 0, 10) as $id) {
			$html = wp_get_attachment_image((int) $id, 'large', false, array('class' => 'ddb-node-image', 'loading' => 'lazy'));
			if ('' !== (string) $html) {
				$hero = $html;
				break;
			}
		}
		if ('' === $hero) {
			$thumb_id = get_post_thumbnail_id($spot_id);
			if ($thumb_id > 0) {
				$hero = (string) wp_get_attachment_image((int) $thumb_id, 'large', false, array('class' => 'ddb-node-image', 'loading' => 'eager'));
			}
		}
		return array('hero' => $hero);
	}

	private function to_float_or_null(string $value): ?float {
		$value = trim(str_replace(',', '.', $value));
		if ('' === $value || ! is_numeric($value)) {
			return null;
		}
		return (float) $value;
	}

	private function is_affiliate_website(int $spot_id, string $website): bool {
		if ('1' === (string) get_post_meta($spot_id, '_ddb_affiliate_website', true)) {
			return true;
		}
		$needle = strtolower($website . ' ' . (string) wp_parse_url($website, PHP_URL_QUERY));
		return str_contains($needle, 'aff') || str_contains($needle, 'partner');
	}

	private function build_openstreetmap_embed_url(float $lat, float $lng, int $zoom = 15): string {
		$delta = 0.01;
		$left = $lng - $delta;
		$right = $lng + $delta;
		$bottom = $lat - $delta;
		$top = $lat + $delta;
		return 'https://www.openstreetmap.org/export/embed.html?bbox=' . rawurlencode($left . ',' . $bottom . ',' . $right . ',' . $top) . '&layer=mapnik&marker=' . rawurlencode($lat . ',' . $lng) . '&zoom=' . rawurlencode((string) max(8, min(18, $zoom)));
	}

	private function build_add_to_day_button(int $spot_id, string $type_slug): string {
		$url = $this->get_add_to_day_url($spot_id);
		return '<a class="ui-btn ui-btn--primary ddb-node-btn ddb-node-btn--primary" href="' . esc_url($url) . '" data-ddb-add-to-day data-ddb-track="add_to_plan" data-ddb-context="single_primary" data-ddb-spot-id="' . esc_attr((string) $spot_id) . '" data-ddb-spot-type="' . esc_attr($type_slug) . '" data-ddb-cta-type="add_to_day">' . esc_html__('Voeg toe aan mijn dag', 'ddb-spots') . '</a>';
	}

	private function get_add_to_day_url(int $spot_id): string {
		$planner = get_page_by_path('plan-je-dag');
		$base = ($planner instanceof WP_Post) ? get_permalink($planner->ID) : home_url('/plan-je-dag/');
		return (string) add_query_arg(array('add_spot' => $spot_id, 'source' => 'spot_page'), $base);
	}

	private function render_premium_labels(int $spot_id): string {
		if (! function_exists('ddb_spots_get_spot_plan_info')) {
			return '';
		}
		$plan = ddb_spots_get_spot_plan_info($spot_id);
		$entitlements = isset($plan['entitlements']) && is_array($plan['entitlements']) ? $plan['entitlements'] : array();
		$is_top_pick = function_exists('ddb_spots_is_top_pick_active') ? ddb_spots_is_top_pick_active($spot_id) : false;
		$is_sponsored = ! empty($plan['is_paid_active']) && ! empty($entitlements['sponsored']);
		if (! $is_top_pick && ! $is_sponsored) {
			return '';
		}
		$items = '';
		if ($is_top_pick) {
			$items .= '<span class="ui-badge ddb-node-badge ddb-node-badge--top">' . esc_html__('Top Pick', 'ddb-spots') . '</span>';
		}
		if ($is_sponsored) {
			$items .= '<span class="ui-badge ddb-node-badge ddb-node-badge--sponsored">' . esc_html__('Gesponsord', 'ddb-spots') . '</span>';
		}
		return '<div class="ddb-node-hero__labels">' . $items . '</div>';
	}
}

if (! function_exists('ddb_get_smart_combinations')) {
	function ddb_get_smart_combinations(int $spot_id): array {
		$spot_id = absint($spot_id);
		if ($spot_id <= 0) {
			return array();
		}

		$manual = ddb_spots_parse_manual_bundle_combinations($spot_id);
		if (! empty($manual)) {
			return array_slice($manual, 0, 3);
		}

		$base_type = function_exists('ddb_spot_primary_type') ? ddb_spot_primary_type($spot_id) : '';
		$base_slot = sanitize_key((string) get_post_meta($spot_id, '_ddb_best_time_slot', true));
		$base_duration = max(30, absint((int) get_post_meta($spot_id, '_ddb_duration_hint', true)));
		$base_price = absint((int) get_post_meta($spot_id, '_ddb_price_level', true));
		$base_lat = function_exists('ddb_spot_meta_float') ? ddb_spot_meta_float($spot_id, '_ddb_lat') : null;
		$base_lng = function_exists('ddb_spot_meta_float') ? ddb_spot_meta_float($spot_id, '_ddb_lng') : null;
		$category_ids = wp_get_post_terms($spot_id, 'ddb_category', array('fields' => 'ids'));
		if (is_wp_error($category_ids)) {
			$category_ids = array();
		}

		$args = array(
			'post_type' => 'ddb_spot',
			'post_status' => 'publish',
			'post__not_in' => array($spot_id),
			'posts_per_page' => 24,
			'fields' => 'ids',
		);
		if (! empty($category_ids)) {
			$args['tax_query'] = array(array('taxonomy' => 'ddb_category', 'field' => 'term_id', 'terms' => array_map('absint', (array) $category_ids)));
		}
		$ids = array_values(array_filter(array_map('absint', (array) get_posts($args))));
		if (empty($ids)) {
			return array();
		}

		$rows = array();
		foreach ($ids as $candidate_id) {
			$candidate_slot = sanitize_key((string) get_post_meta($candidate_id, '_ddb_best_time_slot', true));
			$candidate_type = function_exists('ddb_spot_primary_type') ? ddb_spot_primary_type($candidate_id) : '';
			$candidate_duration = max(30, absint((int) get_post_meta($candidate_id, '_ddb_duration_hint', true)));
			$candidate_price = absint((int) get_post_meta($candidate_id, '_ddb_price_level', true));
			$candidate_lat = function_exists('ddb_spot_meta_float') ? ddb_spot_meta_float($candidate_id, '_ddb_lat') : null;
			$candidate_lng = function_exists('ddb_spot_meta_float') ? ddb_spot_meta_float($candidate_id, '_ddb_lng') : null;
			$distance = null;
			if (null !== $base_lat && null !== $base_lng && null !== $candidate_lat && null !== $candidate_lng && function_exists('ddb_spot_haversine_km')) {
				$distance = ddb_spot_haversine_km($base_lat, $base_lng, $candidate_lat, $candidate_lng);
			}
			$proximity = null === $distance ? 50.0 : max(0.0, min(100.0, 100.0 - ($distance * 14.0)));
			$time_fit = ('' !== $base_slot && $base_slot === $candidate_slot) ? 100.0 : ('' === $candidate_slot ? 55.0 : 40.0);
			$category_compat = $base_type !== $candidate_type ? 100.0 : 58.0;
			$margin = absint((int) get_post_meta($candidate_id, '_ddb_margin_score', true));
			if ($margin <= 0) {
				$margin = absint((int) get_post_meta($candidate_id, '_ddb_priority', true));
			}
			$margin = max(20, min(100, $margin));
			$score = ($proximity * 0.40) + ($time_fit * 0.25) + ($margin * 0.20) + ($category_compat * 0.15);

			$rows[] = array(
				'combo_key' => $spot_id . '-' . $candidate_id,
				'score' => round($score, 4),
				'spot_ids' => array($spot_id, $candidate_id),
				'title' => sprintf(__('%1$s + %2$s', 'ddb-spots'), (string) get_the_title($spot_id), (string) get_the_title($candidate_id)),
				'summary' => sprintf(__('Combi op %1$s, met logische timing en loopafstand.', 'ddb-spots'), (string) get_the_title($candidate_id)),
				'estimated_minutes' => $base_duration + $candidate_duration + 20,
				'price_range' => ddb_spots_bundle_price_range_label($base_price, $candidate_price),
				'add_to_day_url' => ddb_spots_bundle_add_url($spot_id, $candidate_id),
			);
		}
		usort($rows, static function (array $a, array $b): int {
			$score_a = (float) ($a['score'] ?? 0);
			$score_b = (float) ($b['score'] ?? 0);
			if ($score_a === $score_b) {
				return strcmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
			}
			return $score_b <=> $score_a;
		});
		$top = array_slice($rows, 0, 3);
		foreach ($top as &$row) {
			unset($row['score']);
		}
		unset($row);
		return $top;
	}
}

if (! function_exists('ddb_spots_parse_manual_bundle_combinations')) {
	function ddb_spots_parse_manual_bundle_combinations(int $spot_id): array {
		$raw = (string) get_post_meta($spot_id, '_ddb_bundles_json', true);
		if ('' === trim($raw)) {
			return array();
		}
		$decoded = json_decode($raw, true);
		if (! is_array($decoded)) {
			return array();
		}
		$out = array();
		foreach ($decoded as $row) {
			if (! is_array($row)) {
				continue;
			}
			$ids = isset($row['spot_ids']) && is_array($row['spot_ids']) ? array_map('absint', $row['spot_ids']) : array();
			$ids = array_values(array_filter($ids));
			if (empty($ids)) {
				continue;
			}
			if (! in_array($spot_id, $ids, true)) {
				array_unshift($ids, $spot_id);
			}
			$candidate = isset($ids[1]) ? (int) $ids[1] : 0;
			$out[] = array(
				'combo_key' => sanitize_title((string) ($row['combo_key'] ?? ($spot_id . '-' . $candidate))),
				'spot_ids' => array_slice($ids, 0, 2),
				'title' => trim((string) ($row['title'] ?? '')),
				'summary' => trim((string) ($row['summary'] ?? '')),
				'estimated_minutes' => absint((int) ($row['estimated_minutes'] ?? 0)),
				'price_range' => trim((string) ($row['price_range'] ?? '')),
				'add_to_day_url' => $candidate > 0 ? ddb_spots_bundle_add_url($spot_id, $candidate) : '',
			);
		}
		return $out;
	}
}

if (! function_exists('ddb_spots_bundle_price_range_label')) {
	function ddb_spots_bundle_price_range_label(int $base_price, int $candidate_price): string {
		$base_price = max(1, min(4, $base_price > 0 ? $base_price : 2));
		$candidate_price = max(1, min(4, $candidate_price > 0 ? $candidate_price : 2));
		$min = min($base_price, $candidate_price);
		$max = max($base_price, $candidate_price);
		if ($min === $max) {
			return str_repeat('€', $min);
		}
		return str_repeat('€', $min) . ' - ' . str_repeat('€', $max);
	}
}

if (! function_exists('ddb_spots_bundle_add_url')) {
	function ddb_spots_bundle_add_url(int $spot_id, int $candidate_id): string {
		$planner = get_page_by_path('plan-je-dag');
		$base = ($planner instanceof WP_Post) ? get_permalink($planner->ID) : home_url('/plan-je-dag/');
		return (string) add_query_arg(array('add_spot' => absint($spot_id), 'add_bundle' => absint($candidate_id), 'source' => 'bundle_engine'), $base);
	}
}
