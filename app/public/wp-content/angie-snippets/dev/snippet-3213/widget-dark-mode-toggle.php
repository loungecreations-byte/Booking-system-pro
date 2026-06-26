<?php
namespace AngieSnippets;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Dark_Mode_Toggle_a59c2cb1 extends \Elementor\Widget_Base {
    public function get_name() { return 'dark_mode_toggle_a59c2cb1'; }
    public function get_title() { return esc_html__( 'Theme Toggle', 'angie-snippets' ); }
    public function get_icon() { return 'eicon-adjust'; }
    public function get_categories() { return [ 'angie-widgets', 'general' ]; }
    public function get_script_depends() { return [ 'dark-mode-toggle-script-a59c2cb1' ]; }
    public function get_style_depends() { return [ 'dark-mode-toggle-style-a59c2cb1' ]; }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [ 
            'label' => esc_html__( 'Content', 'angie-snippets' ), 
            'tab' => \Elementor\Controls_Manager::TAB_CONTENT 
        ] );
        
        $this->add_control( 'icon_light', [ 
            'label' => esc_html__( 'Light Mode Icon', 'angie-snippets' ), 
            'type' => \Elementor\Controls_Manager::ICONS, 
            'default' => [ 'value' => 'fas fa-sun', 'library' => 'fa-solid' ] 
        ] );
        
        $this->add_control( 'icon_dark', [ 
            'label' => esc_html__( 'Dark Mode Icon', 'angie-snippets' ), 
            'type' => \Elementor\Controls_Manager::ICONS, 
            'default' => [ 'value' => 'fas fa-moon', 'library' => 'fa-solid' ] 
        ] );
        
        $this->end_controls_section();

        $this->start_controls_section( 'style_section', [ 
            'label' => esc_html__( 'Style', 'angie-snippets' ), 
            'tab' => \Elementor\Controls_Manager::TAB_STYLE 
        ] );
        
        $this->add_control( 'icon_color', [ 
            'label' => esc_html__( 'Icon Color', 'angie-snippets' ), 
            'type' => \Elementor\Controls_Manager::COLOR, 
            'selectors' => [ 
                '{{WRAPPER}} .theme-toggle-btn' => 'color: {{VALUE}};', 
                '{{WRAPPER}} .theme-toggle-btn svg' => 'fill: {{VALUE}};' 
            ] 
        ] );
        
        $this->add_responsive_control( 'icon_size', [ 
            'label' => esc_html__( 'Icon Size', 'angie-snippets' ), 
            'type' => \Elementor\Controls_Manager::SLIDER, 
            'selectors' => [ 
                '{{WRAPPER}} .theme-toggle-btn' => 'font-size: {{SIZE}}{{UNIT}};', 
                '{{WRAPPER}} .theme-toggle-btn svg' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' 
            ] 
        ] );
        
        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        ?>
        <button class="theme-toggle-btn theme-toggle-a59c2cb1" aria-label="Toggle Light/Dark Mode">
            <span class="icon-light" style="display:none;">
                <?php \Elementor\Icons_Manager::render_icon( $settings['icon_light'], [ 'aria-hidden' => 'true' ] ); ?>
            </span>
            <span class="icon-dark">
                <?php \Elementor\Icons_Manager::render_icon( $settings['icon_dark'], [ 'aria-hidden' => 'true' ] ); ?>
            </span>
        </button>
        <?php
    }

    protected function content_template() {
        ?>
        <#
        var lightIcon = elementor.helpers.renderIcon( view, settings.icon_light, { 'aria-hidden': 'true' }, 'i', 'object' );
        var darkIcon = elementor.helpers.renderIcon( view, settings.icon_dark, { 'aria-hidden': 'true' }, 'i', 'object' );
        #>
        <button class="theme-toggle-btn theme-toggle-a59c2cb1" aria-label="Toggle Light/Dark Mode">
            <span class="icon-light" style="display:none;">
                <# if ( lightIcon && lightIcon.value ) { #>{{{ lightIcon.value }}}<# } #>
            </span>
            <span class="icon-dark">
                <# if ( darkIcon && darkIcon.value ) { #>{{{ darkIcon.value }}}<# } #>
            </span>
        </button>
        <?php
    }
}