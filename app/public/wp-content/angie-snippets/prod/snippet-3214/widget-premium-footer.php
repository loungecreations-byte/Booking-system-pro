<?php
namespace AngieSnippets;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Premium_Footer_3ea31cf2 extends \Elementor\Widget_Base {
    public function get_name() { return 'premium_footer_3ea31cf2'; }
    public function get_title() { return esc_html__( 'Premium Minimalist Footer', 'angie-snippets' ); }
    public function get_icon() { return 'eicon-footer'; }
    public function get_categories() { return [ 'angie-widgets', 'general' ]; }
    public function get_style_depends() { return [ 'premium-footer-style-3ea31cf2' ]; }

    protected function register_controls() {
        $this->start_controls_section(
            'section_content',
            [
                'label' => esc_html__( 'Content', 'angie-snippets' ),
                'tab' => \Elementor\Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'company_name',
            [
                'label' => esc_html__( 'Company Name', 'angie-snippets' ),
                'type' => \Elementor\Controls_Manager::TEXT,
                'default' => esc_html__( 'Your Brand', 'angie-snippets' ),
                'label_block' => true,
            ]
        );

        $this->add_control(
            'description',
            [
                'label' => esc_html__( 'Description', 'angie-snippets' ),
                'type' => \Elementor\Controls_Manager::TEXTAREA,
                'default' => esc_html__( 'Crafting digital experiences with elegance and precision.', 'angie-snippets' ),
            ]
        );

        $this->add_control(
            'copyright',
            [
                'label' => esc_html__( 'Copyright Text', 'angie-snippets' ),
                'type' => \Elementor\Controls_Manager::TEXT,
                'default' => esc_html__( '© 2023 Your Brand. All rights reserved.', 'angie-snippets' ),
            ]
        );

        $this->end_controls_section();

        // Style Tab
        $this->start_controls_section(
            'section_style',
            [
                'label' => esc_html__( 'Style', 'angie-snippets' ),
                'tab' => \Elementor\Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            'bg_color',
            [
                'label' => esc_html__( 'Background Color', 'angie-snippets' ),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .premium-footer-wrapper' => 'background-color: {{VALUE}};',
                ],
                'default' => '#0a0a0a',
            ]
        );

        $this->add_control(
            'text_color',
            [
                'label' => esc_html__( 'Text Color', 'angie-snippets' ),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .premium-footer-desc, {{WRAPPER}} .premium-footer-copy' => 'color: {{VALUE}};',
                ],
                'default' => '#a0a0a0',
            ]
        );

        $this->add_control(
            'accent_color',
            [
                'label' => esc_html__( 'Accent Color (Gold)', 'angie-snippets' ),
                'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .premium-footer-title' => 'color: {{VALUE}};',
                    '{{WRAPPER}} .premium-footer-wrapper' => 'border-top-color: {{VALUE}};',
                ],
                'default' => '#d4af37',
            ]
        );

        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            [
                'name' => 'title_typography',
                'label' => esc_html__( 'Title Typography', 'angie-snippets' ),
                'selector' => '{{WRAPPER}} .premium-footer-title',
            ]
        );

        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        ?>
        <div class="premium-footer-wrapper">
            <div class="premium-footer-content">
                <h2 class="premium-footer-title"><?php echo esc_html( $settings['company_name'] ); ?></h2>
                <p class="premium-footer-desc"><?php echo esc_html( $settings['description'] ); ?></p>
            </div>
            <div class="premium-footer-bottom">
                <p class="premium-footer-copy"><?php echo esc_html( $settings['copyright'] ); ?></p>
            </div>
        </div>
        <?php
    }

    protected function content_template() {
        ?>
        <div class="premium-footer-wrapper">
            <div class="premium-footer-content">
                <h2 class="premium-footer-title">{{{ settings.company_name }}}</h2>
                <p class="premium-footer-desc">{{{ settings.description }}}</p>
            </div>
            <div class="premium-footer-bottom">
                <p class="premium-footer-copy">{{{ settings.copyright }}}</p>
            </div>
        </div>
        <?php
    }
}
