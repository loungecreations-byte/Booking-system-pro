<?php
namespace AngieSnippets;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Partner_Comparison_af636d83 extends \Elementor\Widget_Base {
    public function get_name() { return 'partner_comparison_af636d83'; }
    public function get_title() { return esc_html__( 'Partner Comparison', 'angie-snippets' ); }
    public function get_icon() { return 'eicon-price-table'; }
    public function get_categories() { return [ 'angie-widgets', 'general' ]; }
    public function get_style_depends() { return [ 'partner-comparison-style-af636d83' ]; }

    protected function register_controls() {
        // Content Tab
        $this->start_controls_section( 'section_content', [
            'label' => esc_html__( 'Content', 'angie-snippets' ),
            'tab' => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );

        $this->add_control( 'section_title', [
            'label' => esc_html__( 'Section Title', 'angie-snippets' ),
            'type' => \Elementor\Controls_Manager::TEXT,
            'default' => esc_html__( 'Hoe kunnen we aanvragen ontvangen?', 'angie-snippets' ),
        ] );

        $this->add_control( 'intro_text', [
            'label' => esc_html__( 'Intro Text', 'angie-snippets' ),
            'type' => \Elementor\Controls_Manager::TEXTAREA,
            'default' => esc_html__( 'Je kunt laagdrempelig starten met zichtbaarheid, of actiever meedraaien in arrangementen, groepsuitjes en aanvragen. We werken resultaatgericht: commissie geldt alleen wanneer er via DagjeDenBosch.nl een boeking, aanvraag of arrangement ontstaat.', 'angie-snippets' ),
        ] );

        $this->add_control( 'footer_text', [
            'label' => esc_html__( 'Footer Note', 'angie-snippets' ),
            'type' => \Elementor\Controls_Manager::TEXTAREA,
            'default' => esc_html__( 'Commissie geldt alleen bij gerealiseerde boekingen, aanvragen of arrangementen via DagjeDenBosch.nl. Bij arrangementen kunnen we ook werken met vaste inkoopprijs, verkoopprijs of marge-afspraak.', 'angie-snippets' ),
        ] );

        $this->end_controls_section();

        // Style Tab
        $this->start_controls_section( 'section_style', [
            'label' => esc_html__( 'Style', 'angie-snippets' ),
            'tab' => \Elementor\Controls_Manager::TAB_STYLE,
        ] );

        $this->add_control( 'primary_color', [
            'label' => esc_html__( 'Primary Color', 'angie-snippets' ),
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => [
                '{{WRAPPER}} .pc-card.recommended' => 'border-color: {{VALUE}};',
                '{{WRAPPER}} .pc-badge' => 'background-color: {{VALUE}};',
                '{{WRAPPER}} .pc-button' => 'background-color: {{VALUE}};',
            ],
        ] );

        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        ?>
        <div class="partner-comparison-wrapper-af636d83">
            <div class="pc-header">
                <h2><?php echo esc_html( $settings['section_title'] ); ?></h2>
                <p><?php echo wp_kses_post( $settings['intro_text'] ); ?></p>
            </div>
            
            <div class="pc-grid">
                <!-- Card 1 -->
                <div class="pc-card">
                    <div class="pc-label">A-partner</div>
                    <h3>Basis Partner</h3>
                    <div class="pc-price">10% commissie</div>
                    <p class="pc-desc">Voor ondernemers die laagdrempelig zichtbaar willen zijn op DagjeDenBosch.nl.</p>
                    <ul class="pc-features">
                        <li>Basisvermelding op DagjeDenBosch.nl</li>
                        <li>Partnerprofiel of korte spotvermelding</li>
                        <li>Mogelijkheid tot opname in arrangementen</li>
                        <li>Geschikt om de samenwerking te testen</li>
                        <li>Commissie alleen bij gerealiseerd resultaat</li>
                    </ul>
                    <div class="pc-best-for"><strong>Beste keuze als:</strong> Je eerst wilt ontdekken of DagjeDenBosch.nl relevante aanvragen kan opleveren.</div>
                    <a href="#partner-worden" class="pc-button">Start als Basis Partner</a>
                </div>

                <!-- Card 2 -->
                <div class="pc-card recommended">
                    <div class="pc-badge">Aanbevolen</div>
                    <div class="pc-label">B-partner</div>
                    <h3>Actief Partner</h3>
                    <div class="pc-price">15% commissie</div>
                    <p class="pc-desc">Voor ondernemers die actief aanvragen, boekingen of arrangementen willen ontvangen via DagjeDenBosch.nl.</p>
                    <ul class="pc-features">
                        <li>Uitgebreider partnerprofiel</li>
                        <li>Zichtbaarheid op relevante pagina’s</li>
                        <li>Opname in arrangementen en groepsuitjes</li>
                        <li>Aanvragen via DagjeDenBosch.nl</li>
                        <li>Combinaties met horeca, gidsen, workshops en activiteiten</li>
                        <li>Commissie alleen bij gerealiseerd resultaat</li>
                    </ul>
                    <div class="pc-best-for"><strong>Beste keuze als:</strong> Je structureel onderdeel wilt worden van dagprogramma’s, groepsuitjes en aanvragen.</div>
                    <a href="#partner-worden" class="pc-button">Word Actief Partner</a>
                </div>

                <!-- Card 3 -->
                <div class="pc-card">
                    <div class="pc-label">C-partner</div>
                    <h3>Premium Partner</h3>
                    <div class="pc-price">20% commissie</div>
                    <p class="pc-desc">Voor partners die maximale zichtbaarheid en actieve promotie willen.</p>
                    <ul class="pc-features">
                        <li>Premium zichtbaarheid op relevante pagina’s</li>
                        <li>Actieve promotie in arrangementen</li>
                        <li>Voorrang bij passende groepsaanvragen</li>
                        <li>Meedoen in campagnes en themadeals</li>
                        <li>Mogelijkheid tot eigen boekbare producten</li>
                        <li>Actieve verkoopondersteuning</li>
                    </ul>
                    <div class="pc-best-for"><strong>Beste keuze als:</strong> Je als voorkeurslocatie of premium aanbieder actief verkocht wilt worden.</div>
                    <a href="#partner-worden" class="pc-button">Bespreek Premium Partner</a>
                </div>
            </div>

            <div class="pc-footer">
                <p><?php echo wp_kses_post( $settings['footer_text'] ); ?></p>
            </div>
        </div>
        <?php
    }

    protected function content_template() {
        ?>
        <div class="partner-comparison-wrapper-af636d83">
            <div class="pc-header">
                <h2>{{{ settings.section_title }}}</h2>
                <p>{{{ settings.intro_text }}}</p>
            </div>
            
            <div class="pc-grid">
                <!-- Hardcoded structure for preview matching render() -->
                <div class="pc-card">
                    <div class="pc-label">A-partner</div>
                    <h3>Basis Partner</h3>
                    <div class="pc-price">10% commissie</div>
                    <p class="pc-desc">Voor ondernemers die laagdrempelig zichtbaar willen zijn op DagjeDenBosch.nl.</p>
                    <a href="#partner-worden" class="pc-button">Start als Basis Partner</a>
                </div>
                <div class="pc-card recommended">
                    <div class="pc-badge">Aanbevolen</div>
                    <div class="pc-label">B-partner</div>
                    <h3>Actief Partner</h3>
                    <div class="pc-price">15% commissie</div>
                    <p class="pc-desc">Voor ondernemers die actief aanvragen, boekingen of arrangementen willen ontvangen via DagjeDenBosch.nl.</p>
                    <a href="#partner-worden" class="pc-button">Word Actief Partner</a>
                </div>
                <div class="pc-card">
                    <div class="pc-label">C-partner</div>
                    <h3>Premium Partner</h3>
                    <div class="pc-price">20% commissie</div>
                    <p class="pc-desc">Voor partners die maximale zichtbaarheid en actieve promotie willen.</p>
                    <a href="#partner-worden" class="pc-button">Bespreek Premium Partner</a>
                </div>
            </div>

            <div class="pc-footer">
                <p>{{{ settings.footer_text }}}</p>
            </div>
        </div>
        <?php
    }
}
