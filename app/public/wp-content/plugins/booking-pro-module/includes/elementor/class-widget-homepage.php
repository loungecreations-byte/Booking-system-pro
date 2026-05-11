<?php

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if (! defined('ABSPATH')) {
    exit;
}

class SBDP_Elementor_Homepage_Widget extends Widget_Base
{
    public function get_name()
    {
        return 'sbdp_homepage_block';
    }

    public function get_title()
    {
        return __('Booking Homepage Block', 'sbdp');
    }

    public function get_icon()
    {
        return 'eicon-home';
    }

    public function get_categories()
    {
        return array('general');
    }

    public function get_keywords()
    {
        return array('homepage', 'hero', 'composer', 'trust', 'cta', 'booking');
    }

    public function get_style_depends()
    {
        return array('ddb-ui', 'ddb-design-system', 'ddb-homepage');
    }

    protected function register_controls()
    {
        $this->start_controls_section(
            'section_content',
            array(
                'label' => __('Content', 'sbdp'),
            )
        );

        $this->add_control(
            'variant',
            array(
                'label'   => __('Block type', 'sbdp'),
                'type'    => Controls_Manager::SELECT,
                'default' => 'hero',
                'options' => array(
                    'hero'     => __('Hero intro', 'sbdp'),
                    'composer' => __('Planner composer', 'sbdp'),
                    'trust'    => __('Trust cards', 'sbdp'),
                    'cta'      => __('Final CTA', 'sbdp'),
                ),
            )
        );

        $this->add_control(
            'eyebrow',
            array(
                'label'       => __('Eyebrow', 'sbdp'),
                'type'        => Controls_Manager::TEXT,
                'default'     => __('DagjeDenBosch.nl', 'sbdp'),
                'condition'   => array('variant' => array('hero', 'composer')),
            )
        );

        $this->add_control(
            'title',
            array(
                'label'       => __('Title', 'sbdp'),
                'type'        => Controls_Manager::TEXT,
                'default'     => __('Plan je perfecte dag in Den Bosch', 'sbdp'),
                'condition'   => array('variant' => array('hero', 'composer', 'cta')),
            )
        );

        $this->add_control(
            'lede',
            array(
                'label'       => __('Text', 'sbdp'),
                'type'        => Controls_Manager::TEXTAREA,
                'rows'        => 4,
                'default'     => __('Kies, vergelijk en voeg toe aan je dag.', 'sbdp'),
                'condition'   => array('variant' => array('hero', 'composer', 'cta')),
            )
        );

        $this->add_control(
            'visitDate',
            array(
                'label'       => __('Default date', 'sbdp'),
                'type'        => Controls_Manager::TEXT,
                'placeholder' => 'YYYY-MM-DD',
                'condition'   => array('variant' => array('composer')),
            )
        );

        $this->add_control(
            'count',
            array(
                'label'       => __('Default persons', 'sbdp'),
                'type'        => Controls_Manager::NUMBER,
                'min'         => 1,
                'max'         => 50,
                'step'        => 1,
                'default'     => 2,
                'condition'   => array('variant' => array('composer')),
            )
        );

        $this->add_control(
            'primary_label',
            array(
                'label'       => __('Primary button', 'sbdp'),
                'type'        => Controls_Manager::TEXT,
                'default'     => __('Start met plannen', 'sbdp'),
                'condition'   => array('variant' => array('hero', 'composer', 'cta')),
            )
        );

        $this->add_control(
            'primary_url',
            array(
                'label'       => __('Primary URL', 'sbdp'),
                'type'        => Controls_Manager::URL,
                'options'     => array('url'),
                'default'     => array('url' => '/plan-je-dag'),
                'condition'   => array('variant' => array('hero', 'composer', 'cta')),
            )
        );

        $this->add_control(
            'secondary_label',
            array(
                'label'       => __('Secondary button', 'sbdp'),
                'type'        => Controls_Manager::TEXT,
                'default'     => __('Ontdek activiteiten', 'sbdp'),
                'condition'   => array('variant' => array('hero', 'composer', 'cta')),
            )
        );

        $this->add_control(
            'secondary_url',
            array(
                'label'       => __('Secondary URL', 'sbdp'),
                'type'        => Controls_Manager::URL,
                'options'     => array('url'),
                'default'     => array('url' => '/activiteiten'),
                'condition'   => array('variant' => array('hero', 'composer', 'cta')),
            )
        );

        $this->add_control(
            'trust_title_1',
            array(
                'label'       => __('Trust item 1 title', 'sbdp'),
                'type'        => Controls_Manager::TEXT,
                'default'     => __('Lokaal samengesteld', 'sbdp'),
                'condition'   => array('variant' => array('trust')),
            )
        );

        $this->add_control(
            'trust_text_1',
            array(
                'label'       => __('Trust item 1 text', 'sbdp'),
                'type'        => Controls_Manager::TEXTAREA,
                'default'     => __('Alle aanbevelingen sluiten aan op Den Bosch en jouw dagindeling.', 'sbdp'),
                'condition'   => array('variant' => array('trust')),
            )
        );

        $this->add_control(
            'trust_title_2',
            array(
                'label'       => __('Trust item 2 title', 'sbdp'),
                'type'        => Controls_Manager::TEXT,
                'default'     => __('Snel kiezen', 'sbdp'),
                'condition'   => array('variant' => array('trust')),
            )
        );

        $this->add_control(
            'trust_text_2',
            array(
                'label'       => __('Trust item 2 text', 'sbdp'),
                'type'        => Controls_Manager::TEXTAREA,
                'default'     => __('Scanbare kaarten en duidelijke CTA’s houden het overzicht rustig.', 'sbdp'),
                'condition'   => array('variant' => array('trust')),
            )
        );

        $this->add_control(
            'trust_title_3',
            array(
                'label'       => __('Trust item 3 title', 'sbdp'),
                'type'        => Controls_Manager::TEXT,
                'default'     => __('Premium ervaring', 'sbdp'),
                'condition'   => array('variant' => array('trust')),
            )
        );

        $this->add_control(
            'trust_text_3',
            array(
                'label'       => __('Trust item 3 text', 'sbdp'),
                'type'        => Controls_Manager::TEXTAREA,
                'default'     => __('Donkere en lichte thema’s delen dezelfde visuele grammatica.', 'sbdp'),
                'condition'   => array('variant' => array('trust')),
            )
        );

        $this->end_controls_section();
    }

    protected function render(): void
    {
        $settings = $this->get_settings_for_display();
        $variant  = sanitize_key($settings['variant'] ?? 'hero');

        if ($variant === 'composer' && class_exists('\\SBDP\\PlanningSessions\\Controller')) {
            echo \SBDP\PlanningSessions\Controller::render_home_widget(
                array(
                    'count' => $settings['count'] ?? '',
                )
            ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            return;
        }

        if (! class_exists('BSPModule\\Core\\Shortcodes\\Shortcodes')) {
            echo esc_html__('Homepage blocks are not available.', 'sbdp');
            return;
        }

        $atts = array(
            'eyebrow'       => $settings['eyebrow'] ?? '',
            'title'         => $settings['title'] ?? '',
            'lede'          => $settings['lede'] ?? '',
            'visitDate'     => $settings['visitDate'] ?? '',
            'count'         => $settings['count'] ?? '',
            'primary_label' => $settings['primary_label'] ?? '',
            'primary_url'   => $this->extract_url($settings['primary_url'] ?? array()),
            'secondary_label' => $settings['secondary_label'] ?? '',
            'secondary_url' => $this->extract_url($settings['secondary_url'] ?? array()),
            'trust_title_1' => $settings['trust_title_1'] ?? '',
            'trust_text_1'  => $settings['trust_text_1'] ?? '',
            'trust_title_2' => $settings['trust_title_2'] ?? '',
            'trust_text_2'  => $settings['trust_text_2'] ?? '',
            'trust_title_3' => $settings['trust_title_3'] ?? '',
            'trust_text_3'  => $settings['trust_text_3'] ?? '',
        );

        switch ($variant) {
            case 'trust':
                echo \BSPModule\Core\Shortcodes\Shortcodes::render_home_trust($atts); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                return;
            case 'cta':
                echo \BSPModule\Core\Shortcodes\Shortcodes::render_home_cta($atts); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                return;
            case 'hero':
            default:
                echo \BSPModule\Core\Shortcodes\Shortcodes::render_home_hero($atts); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                return;
        }
    }

    private function extract_url($value): string
    {
        if (is_array($value)) {
            return (string) ($value['url'] ?? '');
        }

        return is_string($value) ? $value : '';
    }
}
