<?php

/**
 * Elementor widget for displaying prive tour meta.
 *
 * @package Booking_Pro_Module
 */

if (! defined('ABSPATH')) {
    exit;
}

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

/**
 * Outputs core metadata for a prive tour inside Elementor layouts.
 */
class SBDP_Elementor_Tour_Meta_Widget extends Widget_Base
{
    /**
     * Widget slug.
     *
     * @return string
     */
    public function get_name()
    {
        return 'sbdp_tour_meta';
    }

    /**
     * Widget label.
     *
     * @return string
     */
    public function get_title()
    {
        return __('Prive tour meta', 'sbdp');
    }
    /**
     * Widget icon.
     *
     * @return string
     */
    public function get_icon()
    {
        return 'eicon-info-box';
    }

    /**
     * Widget category.
     *
     * @return array<int, string>
     */
    public function get_categories()
    {
        return array( 'general' );
    }

    /**
     * Register content controls.
     */
    protected function register_controls()
    {
        $this->start_controls_section('section_content', array(
                'label' => __('Inhoud', 'sbdp'),
            ));
        $this->add_control('show_summary', array(
                'label'        => __('Samenvatting tonen', 'sbdp'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => __('Ja', 'sbdp'),
                'label_off'    => __('Nee', 'sbdp'),
                'return_value' => 'yes',
                'default'      => 'yes',
            ));
        $this->add_control('show_duration', array(
                'label'        => __('Duur tonen', 'sbdp'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => __('Ja', 'sbdp'),
                'label_off'    => __('Nee', 'sbdp'),
                'return_value' => 'yes',
                'default'      => 'yes',
            ));
        $this->add_control('show_steps', array(
                'label'        => __('Aantal stappen tonen', 'sbdp'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => __('Ja', 'sbdp'),
                'label_off'    => __('Nee', 'sbdp'),
                'return_value' => 'yes',
                'default'      => 'yes',
            ));
        $this->add_control('show_support', array(
                'label'        => __('Support e-mail tonen', 'sbdp'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => __('Ja', 'sbdp'),
                'label_off'    => __('Nee', 'sbdp'),
                'return_value' => 'yes',
                'default'      => '',
            ));
        $this->end_controls_section();
    }

    /**
     * Render the widget output.
     */
    protected function render()
    {
        $post_id = get_the_ID();
        $post    = $post_id ? get_post($post_id) : null;
        if (! $post || SBDP_Private_Tours::POST_TYPE_TOUR !== $post->post_type) {
            echo '<div class="sbdp-elementor-tour-meta sbdp-elementor-tour-meta--notice">';
            esc_html_e('Deze widget werkt alleen binnen een prive tour template.', 'sbdp');
            echo '</div>';
            return;
        }
        $settings      = $this->get_settings_for_display();
        $summary       = (string) get_post_meta($post_id, '_sbdp_tour_summary', true);
        $duration      = (int) get_post_meta($post_id, '_sbdp_tour_duration', true);
        $support_email = (string) get_post_meta($post_id, '_sbdp_tour_support_email', true);
        $step_count    = SBDP_Private_Tours_Tickets::get_step_count($post_id);
        $output = array();
        if ('yes' === $settings['show_summary'] && '' !== trim($summary)) {
            $output[] = sprintf('<div class="sbdp-tour-meta__summary">%s</div>', wp_kses_post(wpautop($summary)));
        }

        $meta_items = array();
        if ('yes' === $settings['show_duration'] && $duration > 0) {
            $meta_items[] = sprintf('<span class="sbdp-tour-meta__chip">%s</span>', esc_html(sprintf(/* translators: %d: duration in minutes. */
                __('%d minuten', 'sbdp'),
                $duration
            )));
        }

        if ('yes' === $settings['show_steps'] && $step_count >= 0) {
            $meta_items[] = sprintf('<span class="sbdp-tour-meta__chip">%s</span>', esc_html(sprintf(/* translators: %d: amount of steps. */
                _n('%d stap', '%d stappen', $step_count, 'sbdp'),
                $step_count
            )));
        }

        if (! empty($meta_items)) {
            $output[] = sprintf('<div class="sbdp-tour-meta__chips">%s</div>', implode('', $meta_items));
        }

        if ('yes' === $settings['show_support'] && is_email($support_email)) {
            $output[] = sprintf('<div class="sbdp-tour-meta__support">%s <a href="%s">%s</a></div>', esc_html__('Vragen?', 'sbdp'), esc_url('mailto:' . $support_email), esc_html($support_email));
        }

        if (empty($output)) {
            return;
        }

        printf('<div class="sbdp-elementor-tour-meta">%s</div>', implode('', $output));
    }
}
