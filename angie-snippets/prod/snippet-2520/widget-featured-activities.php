<?php
namespace AngieSnippets;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Featured_Activities_ca9727ba extends \Elementor\Widget_Base {
    public function get_name() { return 'featured_activities_ca9727ba'; }
    public function get_title() { return esc_html__( 'Featured Activities', 'angie-snippets' ); }
    public function get_icon() { return 'eicon-posts-grid'; }
    public function get_categories() { return [ 'angie-widgets', 'general' ]; }
    public function get_style_depends() { return [ 'featured-activities-style-ca9727ba' ]; }

    protected function register_controls() {
        $this->start_controls_section( 'section_content', [
            'label' => esc_html__( 'Settings', 'angie-snippets' ),
            'tab' => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );

        $this->add_control( 'activity_ids', [
            'label' => esc_html__( 'Activity IDs', 'angie-snippets' ),
            'type' => \Elementor\Controls_Manager::TEXT,
            'default' => '123,456,789,101',
            'description' => esc_html__( 'Comma-separated IDs.', 'angie-snippets' ),
        ] );

        $this->add_control( 'limit', [
            'label' => esc_html__( 'Limit', 'angie-snippets' ),
            'type' => \Elementor\Controls_Manager::NUMBER,
            'default' => 4,
        ] );

        $this->add_control( 'display_type', [
            'label' => esc_html__( 'Display', 'angie-snippets' ),
            'type' => \Elementor\Controls_Manager::SELECT,
            'default' => 'grid',
            'options' => [
                'grid' => esc_html__( 'Grid', 'angie-snippets' ),
                'list' => esc_html__( 'List', 'angie-snippets' ),
            ],
        ] );

        $this->end_controls_section();

        $this->start_controls_section( 'section_style', [
            'label' => esc_html__( 'Design', 'angie-snippets' ),
            'tab' => \Elementor\Controls_Manager::TAB_STYLE,
        ] );
        
        $this->add_control( 'bg_color', [
            'label' => esc_html__( 'Card Background Color', 'angie-snippets' ),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .fa-dark-wrapper-ca9727ba > *, {{WRAPPER}} .fa-dark-wrapper-ca9727ba li, {{WRAPPER}} .fa-dark-wrapper-ca9727ba .activity-item, {{WRAPPER}} .fa-dark-wrapper-ca9727ba > div > div' => 'background-color: {{VALUE}} !important;',
            ],
        ] );

        $this->add_control( 'text_color', [
            'label' => esc_html__( 'Text Color', 'angie-snippets' ),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .fa-dark-wrapper-ca9727ba li, {{WRAPPER}} .fa-dark-wrapper-ca9727ba .activity-item, {{WRAPPER}} .fa-dark-wrapper-ca9727ba > div > div, {{WRAPPER}} .fa-dark-wrapper-ca9727ba p' => 'color: {{VALUE}} !important;',
            ],
        ] );

        $this->add_control( 'heading_color', [
            'label' => esc_html__( 'Heading Color', 'angie-snippets' ),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .fa-dark-wrapper-ca9727ba h2, {{WRAPPER}} .fa-dark-wrapper-ca9727ba h3, {{WRAPPER}} .fa-dark-wrapper-ca9727ba h4' => 'color: {{VALUE}} !important;',
            ],
        ] );

        $this->add_control( 'button_bg_color', [
            'label' => esc_html__( 'Button Background Color', 'angie-snippets' ),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .fa-dark-wrapper-ca9727ba a.button, {{WRAPPER}} .fa-dark-wrapper-ca9727ba button, {{WRAPPER}} .fa-dark-wrapper-ca9727ba .btn' => 'background-color: {{VALUE}} !important; border-color: {{VALUE}} !important;',
            ],
        ] );

        $this->add_control( 'button_text_color', [
            'label' => esc_html__( 'Button Text Color', 'angie-snippets' ),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .fa-dark-wrapper-ca9727ba a.button, {{WRAPPER}} .fa-dark-wrapper-ca9727ba button, {{WRAPPER}} .fa-dark-wrapper-ca9727ba .btn' => 'color: {{VALUE}} !important;',
            ],
        ] );

        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        $ids = esc_attr( $settings['activity_ids'] );
        $limit = esc_attr( $settings['limit'] );
        $display = esc_attr( $settings['display_type'] );

        echo '<div class="fa-dark-wrapper-ca9727ba">';
        echo do_shortcode( '[ddb_featured_activities ids="' . $ids . '" limit="' . $limit . '" display="' . $display . '"]' );
        echo '</div>';
    }

    protected function content_template() {
        ?>
        <div class="fa-dark-wrapper-ca9727ba">
            <div class="fa-preview-ca9727ba">
                <p><?php esc_html_e('Shortcode Preview:', 'angie-snippets'); ?> [ddb_featured_activities ids="{{ settings.activity_ids }}" limit="{{ settings.limit }}" display="{{ settings.display_type }}"]</p>
                <div class="fa-dummy-cards-ca9727ba">
                    <div class="fa-dummy-card-ca9727ba">
                        <h3>Premium Activity 1</h3>
                        <p>Experience the best we have to offer.</p>
                        <div class="price">€99</div>
                        <a href="#" class="button">View Details</a>
                    </div>
                    <div class="fa-dummy-card-ca9727ba">
                        <h3>Premium Activity 2</h3>
                        <p>Exclusive access and top-tier service.</p>
                        <div class="price">€149</div>
                        <a href="#" class="button">View Details</a>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
