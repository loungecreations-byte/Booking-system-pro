<?php

/**
 * Elementor widget for private tour experience navigation.
 *
 * @package Booking_Pro_Module
 */

if (! defined('ABSPATH')) {
    exit;
}

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

/**
 * Interactive two-column tour navigation widget.
 */
class SBDP_Elementor_Tour_Navigation_Widget extends Widget_Base
{
    /**
     * Widget slug.
     *
     * @return string
     */
    public function get_name()
    {
        return 'sbdp_tour_navigation';
    }

    /**
     * Widget title.
     *
     * @return string
     */
    public function get_title()
    {
        return __('Prive tour experience', 'sbdp');
    }

    /**
     * Widget icon.
     *
     * @return string
     */
    public function get_icon()
    {
        return 'eicon-navigation-horizontal';
    }

    /**
     * Widget categories.
     *
     * @return array<int, string>
     */
    public function get_categories()
    {
        return array('general');
    }

    /**
     * Register Elementor controls.
     */
    protected function register_controls()
    {
        $this->start_controls_section(
            'section_content',
            array(
                'label' => __('Instellingen', 'sbdp'),
            )
        );

        $this->add_control(
            'show_map',
            array(
                'label'        => __('Kaart tonen', 'sbdp'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => __('Ja', 'sbdp'),
                'label_off'    => __('Nee', 'sbdp'),
                'return_value' => 'yes',
                'default'      => 'yes',
            )
        );

        $this->add_control(
            'show_step_list',
            array(
                'label'        => __('Stappenlijst tonen', 'sbdp'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => __('Ja', 'sbdp'),
                'label_off'    => __('Nee', 'sbdp'),
                'return_value' => 'yes',
                'default'      => 'yes',
            )
        );

        $this->add_control(
            'map_height',
            array(
                'label'     => __('Kaart hoogte (px)', 'sbdp'),
                'type'      => Controls_Manager::NUMBER,
                'default'   => 520,
                'min'       => 420,
                'max'       => 680,
                'step'      => 20,
                'condition' => array(
                    'show_map' => 'yes',
                ),
            )
        );

        $this->add_control(
            'completion_cta_label',
            array(
                'label'       => __('Completion CTA label', 'sbdp'),
                'type'        => Controls_Manager::TEXT,
                'default'     => __('Plan je volgende experience', 'sbdp'),
                'placeholder' => __('Plan je volgende experience', 'sbdp'),
            )
        );

        $this->add_control(
            'completion_cta_url',
            array(
                'label'       => __('Completion CTA URL', 'sbdp'),
                'type'        => Controls_Manager::URL,
                'placeholder' => home_url('/plan-je-dag/'),
                'default'     => array(
                    'url' => home_url('/plan-je-dag/'),
                ),
            )
        );

        $this->end_controls_section();
    }

    /**
     * Render widget output.
     */
    protected function render()
    {
        $post_id = get_the_ID();
        $post = $post_id ? get_post($post_id) : null;

        if (! $post || SBDP_Private_Tours::POST_TYPE_TOUR !== $post->post_type) {
            echo '<div class="sbdp-tour-navigation sbdp-tour-navigation--notice">';
            esc_html_e('Deze widget werkt alleen op prive tour pagina\'s.', 'sbdp');
            echo '</div>';
            return;
        }

        $settings = $this->get_settings_for_display();
        $steps = SBDP_Private_Tours_Tickets::get_steps_for_tour($post_id);

        if (empty($steps)) {
            echo '<div class="sbdp-tour-navigation sbdp-tour-navigation--empty">';
            esc_html_e('Geen stappen gevonden. Voeg eerst tourstappen toe in de admin.', 'sbdp');
            echo '</div>';
            return;
        }

        wp_enqueue_style('sbdp-tour-navigation');
        wp_enqueue_script('sbdp-tour-navigation');

        if ('yes' === $settings['show_map'] && wp_style_is('leaflet', 'registered') && wp_script_is('leaflet', 'registered')) {
            wp_enqueue_style('leaflet');
            wp_enqueue_script('leaflet');
        }

        $map_height = isset($settings['map_height']) ? absint($settings['map_height']) : 520;
        $map_height = max(420, min(680, $map_height));
        $default_map_tiles = 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png';
        $map_tiles_url = (string) apply_filters('sbdp/private_tours/map_tiles_url', $default_map_tiles, $post_id);
        $map_attribution = (string) apply_filters(
            'sbdp/private_tours/map_tiles_attribution',
            '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
            $post_id
        );
        $route_endpoint = esc_url_raw(rest_url('sbdp/v1/private-tours/navigation/route'));
        $embed_diagnostics_endpoint = esc_url_raw(rest_url('sbdp/v1/private-tours/navigation/embed-diagnostics'));
        $route_profile = (string) apply_filters('sbdp/private_tours/navigation_profile', 'walking', $post_id);
        $completion_cta_url = isset($settings['completion_cta_url']['url'])
            ? esc_url_raw((string) $settings['completion_cta_url']['url'])
            : home_url('/plan-je-dag/');
        $completion_cta_label = isset($settings['completion_cta_label'])
            ? sanitize_text_field((string) $settings['completion_cta_label'])
            : __('Plan je volgende experience', 'sbdp');
        $tour_summary = wp_strip_all_tags((string) get_post_meta($post_id, '_sbdp_tour_summary', true));
        $tour_duration = absint((int) get_post_meta($post_id, '_sbdp_tour_duration', true));
        $tour_support_email = sanitize_email((string) get_post_meta($post_id, '_sbdp_tour_support_email', true));
        $preview_session = SBDP_Private_Tours::canonical_preview_session((int) $post_id);
        $google_maps_api_key = '';
        foreach (array(get_option('sbdp_google_maps_api_key', ''), get_option('elementor_google_maps_api_key', '')) as $candidate) {
            if (is_string($candidate) && '' !== trim($candidate)) {
                $google_maps_api_key = trim($candidate);
                break;
            }
        }
        $site_locale = function_exists('determine_locale') ? (string) determine_locale() : (string) get_locale();
        $locale_parts = preg_split('/[_-]/', $site_locale) ?: array();
        $google_maps_language = strtolower((string) ($locale_parts[0] ?? 'nl'));
        $google_maps_region = strtoupper((string) ($locale_parts[1] ?? ''));
        $google_maps_units = (string) apply_filters('sbdp/private_tours/google_maps_embed_units', 'metric', $site_locale);
        $google_maps_units = 'imperial' === strtolower($google_maps_units) ? 'imperial' : 'metric';

        $steps_data = array_map(
            static function ($step) {
                $lat = isset($step['lat']) && is_numeric($step['lat']) ? (float) $step['lat'] : null;
                $lng = isset($step['lng']) && is_numeric($step['lng']) ? (float) $step['lng'] : null;
                $altitude_m = isset($step['altitude_m']) && is_numeric($step['altitude_m']) ? (float) $step['altitude_m'] : null;
                $area = (string) ($step['area'] ?? '');
                $location_type = (string) ($step['locationType'] ?? '');
                $location_label = (string) ($step['locationLabel'] ?? '');
                $spot_name = trim((string) ($step['title'] ?? ''));
                if ('' === $spot_name) {
                    $spot_name = $location_label;
                }

                return array(
                    'id'            => (int) ($step['id'] ?? 0),
                    'title'         => (string) ($step['title'] ?? ''),
                    'content'       => (string) ($step['content'] ?? ''),
                    'type'          => (string) ($step['type'] ?? 'text'),
                    'contentType'   => (string) ($step['contentType'] ?? ($step['type'] ?? 'text')),
                    'points'        => (int) ($step['points'] ?? 0),
                    'lat'           => $lat,
                    'lng'           => $lng,
                    'altitudeM'     => $altitude_m,
                    'altitude_m'    => $altitude_m,
                    'area'          => $area,
                    'locationType'  => $location_type,
                    'locationLabel' => $location_label,
                    'spot'          => array(
                        'name'       => $spot_name,
                        'lat'        => $lat,
                        'lng'        => $lng,
                        'altitude_m' => $altitude_m,
                        'area'       => $area,
                        'type'       => $location_type,
                    ),
                    'videoUrl'      => (string) ($step['videoUrl'] ?? ''),
                    'audioUrl'      => (string) ($step['audioUrl'] ?? ''),
                    'imageUrl'      => (string) ($step['imageUrl'] ?? ''),
                    'heygenVideoUrl' => (string) ($step['heygenVideoUrl'] ?? ''),
                    'heygenEmbedUrl' => (string) ($step['heygenEmbedUrl'] ?? ''),
                    'gamification'  => is_array($step['gamification'] ?? null) ? $step['gamification'] : array(),
                    'quiz'          => is_array($step['quiz'] ?? null) ? $step['quiz'] : array(),
                );
            },
            $steps
        );

        $container_classes = array('sbdp-tour-navigation', 'sbdp-tour-navigation--experience');
        if ('yes' !== $settings['show_step_list']) {
            $container_classes[] = 'sbdp-tour-navigation--no-list';
        }
        if ('yes' !== $settings['show_map']) {
            $container_classes[] = 'sbdp-tour-navigation--no-map';
        }
        ?>
        <div
            class="<?php echo esc_attr(implode(' ', $container_classes)); ?>"
            data-tour-navigation
            data-tour-id="<?php echo esc_attr((string) $post_id); ?>"
            data-tour-title="<?php echo esc_attr(get_the_title($post)); ?>"
            data-tour-summary="<?php echo esc_attr($tour_summary); ?>"
            data-tour-duration="<?php echo esc_attr((string) $tour_duration); ?>"
            data-tour-support-email="<?php echo esc_attr($tour_support_email); ?>"
            data-tour-step-count="<?php echo esc_attr((string) count($steps)); ?>"
            data-tour-steps="<?php echo esc_attr(wp_json_encode($steps_data)); ?>"
            data-ticket-session="<?php echo esc_attr((string) ($preview_session['session'] ?? '')); ?>"
            data-ticket-session-api-base="<?php echo esc_attr((string) ($preview_session['api_base'] ?? '')); ?>"
            data-ticket-progress="<?php echo esc_attr(wp_json_encode($preview_session['progress'] ?? array())); ?>"
            data-map-tiles="<?php echo esc_attr($map_tiles_url); ?>"
            data-map-attribution="<?php echo esc_attr($map_attribution); ?>"
            data-route-endpoint="<?php echo esc_attr($route_endpoint); ?>"
            data-embed-diagnostics-endpoint="<?php echo esc_attr($embed_diagnostics_endpoint); ?>"
            data-route-profile="<?php echo esc_attr($route_profile); ?>"
            data-google-maps-api-key="<?php echo esc_attr($google_maps_api_key); ?>"
            data-google-maps-language="<?php echo esc_attr($google_maps_language); ?>"
            data-google-maps-region="<?php echo esc_attr($google_maps_region); ?>"
            data-google-maps-units="<?php echo esc_attr($google_maps_units); ?>"
            data-map-height="<?php echo esc_attr((string) $map_height); ?>"
            data-completion-cta-url="<?php echo esc_attr($completion_cta_url); ?>"
            data-completion-cta-label="<?php echo esc_attr($completion_cta_label); ?>"
        >
            <div class="tour-shell tour-shell--guided">
                <section class="tour-summary-panel" data-tour-summary-panel></section>

                <div class="tour-shell__body">
                    <aside class="tour-route-rail" data-tour-step-list></aside>

                    <section class="tour-stage" aria-live="polite">
                        <section class="tour-stage__panel tour-stage__panel--story" data-tour-story-panel></section>

                        <section class="tour-stage__panel tour-stage__panel--navigation" data-tour-navigation-panel hidden>
                            <div class="tour-navigation-layout<?php echo 'yes' === $settings['show_map'] ? '' : ' tour-navigation-layout--no-map'; ?>">
                                <?php if ('yes' === $settings['show_map']) : ?>
                                    <section class="tour-navigation-map-panel" data-tour-map-panel>
                                        <div class="tour-map-meta" data-tour-map-meta></div>
                                        <div class="tour-map" data-tour-map style="--tour-map-height: <?php echo esc_attr((string) $map_height); ?>px;"></div>
                                        <p class="tour-map-status" data-tour-map-status aria-live="polite"></p>
                                    </section>
                                <?php endif; ?>

                                <aside class="tour-navigation-sidebar" data-tour-navigation-copy></aside>
                            </div>
                        </section>
                    </section>
                </div>
            </div>
        </div>
        <?php
    }
}
