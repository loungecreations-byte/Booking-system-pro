<?php
/**
 * DagjeDenBosch Mega Navigation
 * Shortcode: [ddb_mega_nav]
 */

namespace AngieSnippets\DagjeDenBoschMegaNavigation_c4c3399d;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'DDB_MEGA_NAV_ASSETS_VERSION_c4c3399d', '1.3.2' );

/* Flag to prevent duplicate rendering */
$GLOBALS['ddb_mega_nav_rendered_c4c3399d'] = false;

add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_assets_c4c3399d' );
function enqueue_assets_c4c3399d() {
    wp_enqueue_style(
        'ddb-mega-nav-c4c3399d',
        angie_cs_get_snippet_asset_url( __FILE__, 'style.css' ),
        [],
        DDB_MEGA_NAV_ASSETS_VERSION_c4c3399d
    );
    wp_enqueue_script(
        'ddb-mega-nav-c4c3399d',
        angie_cs_get_snippet_asset_url( __FILE__, 'script.js' ),
        [],
        DDB_MEGA_NAV_ASSETS_VERSION_c4c3399d,
        true
    );
}

/* Register shortcode on init to ensure it is available before content parsing */
add_action( 'init', __NAMESPACE__ . '\\register_shortcode_c4c3399d' );
function register_shortcode_c4c3399d() {
    add_shortcode( 'ddb_mega_nav', __NAMESPACE__ . '\\shortcode_mega_nav_c4c3399d' );
}

function shortcode_mega_nav_c4c3399d( $atts ) {
    if ( ! empty( $GLOBALS['ddb_mega_nav_rendered_c4c3399d'] ) ) {
        return '';
    }
    /* Ensure assets are enqueued when shortcode is used */
    enqueue_assets_c4c3399d();
    ob_start();
    render_mega_nav_c4c3399d();
    return ob_get_clean();
}

/* Auto-render via wp_body_open hook */
add_action( 'wp_body_open', __NAMESPACE__ . '\\auto_render_mega_nav_c4c3399d' );
function auto_render_mega_nav_c4c3399d() {
    /* Skip auto-render if shortcode is used on this page */
    global $post;
    if ( $post && is_a( $post, 'WP_Post' ) ) {
        $content = $post->post_content ?? '';
        /* Check raw post_content and Elementor data for shortcode usage */
        if ( strpos( $content, '[ddb_mega_nav' ) !== false ) {
            return;
        }
        /* Also check Elementor data where shortcode might be in a widget */
        $elementor_data = get_post_meta( $post->ID, '_elementor_data', true );
        if ( is_string( $elementor_data ) && strpos( $elementor_data, 'ddb_mega_nav' ) !== false ) {
            return;
        }
    }
    render_mega_nav_c4c3399d();
}

function render_mega_nav_c4c3399d() {
    if ( ! empty( $GLOBALS['ddb_mega_nav_rendered_c4c3399d'] ) ) {
        return;
    }
    $GLOBALS['ddb_mega_nav_rendered_c4c3399d'] = true;

    $current_path = trailingslashit( wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) );
    ?>
    <header class="ddb-mega-nav" role="navigation" aria-label="<?php echo esc_attr__( 'Hoofdnavigatie', 'angie-snippets' ); ?>">
        <div class="ddb-mega-nav__bar">
            <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="ddb-mega-nav__logo" aria-label="<?php echo esc_attr__( 'DagjeDenBosch Home', 'angie-snippets' ); ?>">
                <?php
                $custom_logo_id = get_theme_mod( 'custom_logo' );
                if ( $custom_logo_id ) {
                    echo wp_get_attachment_image( $custom_logo_id, 'medium', false, [
                        'class' => 'ddb-mega-nav__logo-img',
                        'alt'   => esc_attr( get_bloginfo( 'name' ) ),
                    ] );
                } else {
                    ?><span class="ddb-mega-nav__logo-text">Dagje<strong>DenBosch</strong></span><?php
                }
                ?>
            </a>

            <button class="ddb-mega-nav__burger" aria-expanded="false" aria-controls="ddb-mega-nav-mobile-c4c3399d" aria-label="<?php echo esc_attr__( 'Menu openen', 'angie-snippets' ); ?>">
                <span class="ddb-mega-nav__burger-line"></span>
                <span class="ddb-mega-nav__burger-line"></span>
                <span class="ddb-mega-nav__burger-line"></span>
            </button>

            <ul class="ddb-mega-nav__list" id="ddb-mega-nav-mobile-c4c3399d" role="menubar">
                <?php
                $menu_items = get_menu_items_c4c3399d();
                foreach ( $menu_items as $index => $item ) :
                    $has_panel = ! empty( $item['panel'] );
                    $is_active = false;
                    if ( ! empty( $item['url'] ) ) {
                        $is_active = ( $current_path === trailingslashit( $item['url'] ) );
                    }
                    if ( ! $is_active && ! empty( $item['active_paths'] ) ) {
                        foreach ( $item['active_paths'] as $ap ) {
                            if ( strpos( $current_path, $ap ) === 0 ) {
                                $is_active = true;
                                break;
                            }
                        }
                    }
                ?>
                <li class="ddb-mega-nav__item<?php echo $has_panel ? ' ddb-mega-nav__item--has-panel' : ''; ?><?php echo $is_active ? ' ddb-mega-nav__item--active' : ''; ?>"
                    role="none">
                    <?php if ( $has_panel ) : ?>
                        <button class="ddb-mega-nav__trigger"
                                role="menuitem"
                                aria-expanded="false"
                                aria-haspopup="true"
                                aria-controls="ddb-panel-<?php echo esc_attr( $index ); ?>-c4c3399d">
                            <?php echo esc_html( $item['label'] ); ?>
                            <svg class="ddb-mega-nav__chevron" width="10" height="6" viewBox="0 0 10 6" fill="none" aria-hidden="true"><path d="M1 1L5 5L9 1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                        <div class="ddb-mega-nav__panel" id="ddb-panel-<?php echo esc_attr( $index ); ?>-c4c3399d" role="menu" aria-hidden="true">
                            <div class="ddb-mega-nav__panel-inner">
                                <?php echo $item['panel']; ?>
                            </div>
                        </div>
                    <?php else : ?>
                        <a href="<?php echo esc_url( home_url( $item['url'] ) ); ?>" class="ddb-mega-nav__link" role="menuitem">
                            <?php echo esc_html( $item['label'] ); ?>
                        </a>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>

            <div class="ddb-mega-nav__planner-controls" aria-label="<?php echo esc_attr__( 'Thema en planner', 'angie-snippets' ); ?>">
                <button type="button" class="ddb-mega-nav__theme-toggle" data-ddb-theme-toggle aria-label="<?php echo esc_attr__( 'Schakel naar donker thema', 'angie-snippets' ); ?>" title="<?php echo esc_attr__( 'Donker thema', 'angie-snippets' ); ?>">☾</button>
                <a href="<?php echo esc_url( home_url( '/plan-je-dag/' ) ); ?>" class="ddb-mega-nav__cta-btn">
                    <svg class="ddb-mega-nav__cta-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/>
                        <path d="M3 10H21" stroke="currentColor" stroke-width="2"/>
                        <path d="M8 2V6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <path d="M16 2V6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <circle cx="12" cy="16" r="1.5" fill="currentColor"/>
                    </svg>
                    Planner
                </a>
            </div>
        </div>
    </header>
    <div class="ddb-mega-nav__overlay" aria-hidden="true"></div>
    <?php
}

function get_menu_items_c4c3399d() {
    return [
        [
            'label' => 'Groepsuitjes',
            'url' => '/groepsuitjes/',
            'active_paths' => ['/groepsuitjes/', '/familie-uitje/'],
            'panel' => render_panel_groepsuitjes_c4c3399d(),
        ],
        [
            'label' => 'Activiteiten',
            'url' => '/activiteiten-den-bosch/',
            'active_paths' => ['/activiteiten-den-bosch/'],
            'panel' => render_panel_activiteiten_c4c3399d(),
        ],
        [
            'label' => 'Arrangementen',
            'url' => '/about/maatwerk/',
            'active_paths' => [],
            'panel' => render_panel_arrangementen_c4c3399d(),
        ],
        [
            'label' => 'Eten & drinken',
            'url' => '/restaurant-den-bosch/',
            'active_paths' => ['/restaurant-den-bosch/', '/cafe-den-bosch/'],
            'panel' => render_panel_eten_c4c3399d(),
        ],
        [
            'label' => 'Ontdek Den Bosch',
            'url' => '/den-bosch/',
            'active_paths' => ['/den-bosch/', '/bezienswaardigheden/', '/evenementen/', '/hotels/', '/musea/', '/winkels/'],
            'panel' => render_panel_ontdek_c4c3399d(),
        ],
        [
            'label' => 'Zakelijk & maatwerk',
            'url' => '/about/maatwerk/',
            'active_paths' => ['/samenwerken/'],
            'panel' => render_panel_zakelijk_c4c3399d(),
        ],
        [
            'label' => 'Contact',
            'url' => '/contact/',
            'active_paths' => ['/contact/'],
            'panel' => '',
        ],
    ];
}

function render_panel_groepsuitjes_c4c3399d() {
    ob_start();
    ?>
    <div class="ddb-mega-nav__columns ddb-mega-nav__columns--3">
        <div class="ddb-mega-nav__section">
            <h3 class="ddb-mega-nav__section-title">Populaire groepen</h3>
            <ul class="ddb-mega-nav__links">
                <li><a href="<?php echo esc_url( home_url( '/groepsuitjes/bedrijfsuitje-den-bosch/' ) ); ?>">Bedrijfsuitje Den Bosch</a></li>
                <li><a href="<?php echo esc_url( home_url( '/groepsuitjes/vrienden-uitje/' ) ); ?>">Vriendenuitje</a></li>
                <li><a href="<?php echo esc_url( home_url( '/familie-uitje/' ) ); ?>">Familie-uitje</a></li>
                <li><a href="<?php echo esc_url( home_url( '/groepsuitjes/vrijgezellenfeest/' ) ); ?>">Vrijgezellenfeest</a></li>
                <li><a href="<?php echo esc_url( home_url( '/groepsuitjes/vrijgezellenfeest/vrijgezellenfeest-mannen/' ) ); ?>">Vrijgezellenfeest mannen</a></li>
            </ul>
            <a href="<?php echo esc_url( home_url( '/groepsuitjes/' ) ); ?>" class="ddb-mega-nav__view-all">Alle groepsuitjes &#8594;</a>
        </div>
        <div class="ddb-mega-nav__section">
            <h3 class="ddb-mega-nav__section-title">Snel kiezen</h3>
            <div class="ddb-mega-nav__chips">
                <span class="ddb-mega-nav__chip">Voor 10&#8211;20 personen</span>
                <span class="ddb-mega-nav__chip">Voor 20&#8211;50 personen</span>
                <span class="ddb-mega-nav__chip">Voor 50+ personen</span>
                <span class="ddb-mega-nav__chip">Met lunch of diner</span>
                <span class="ddb-mega-nav__chip">Met meerdere activiteiten</span>
            </div>
        </div>
        <div class="ddb-mega-nav__section ddb-mega-nav__cta">
            <div class="ddb-mega-nav__cta-card">
                <h3 class="ddb-mega-nav__cta-title">Laat ons je groepsuitje samenstellen</h3>
                <p class="ddb-mega-nav__cta-text">Geef datum, groepsgrootte en wensen door. Wij maken een passend programma.</p>
                <a href="<?php echo esc_url( home_url( '/plan-je-dag/' ) ); ?>" class="ddb-mega-nav__cta-button">
                    <svg class="ddb-mega-nav__cta-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/><path d="M3 10H21" stroke="currentColor" stroke-width="2"/><path d="M8 2V6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M16 2V6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    Plan je dag
                </a>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function render_panel_activiteiten_c4c3399d() {
    ob_start();
    ?>
    <div class="ddb-mega-nav__columns ddb-mega-nav__columns--3">
        <div class="ddb-mega-nav__section">
            <h3 class="ddb-mega-nav__section-title">Categorie&#235;n</h3>
            <ul class="ddb-mega-nav__links">
                <li><a href="<?php echo esc_url( home_url( '/activiteiten-den-bosch/' ) ); ?>">Alle activiteiten</a></li>
                <li><a href="<?php echo esc_url( home_url( '/activiteiten-den-bosch/tours/' ) ); ?>">Tours</a></li>
                <li><a href="<?php echo esc_url( home_url( '/activiteiten-den-bosch/stadswandeling-den-bosch/' ) ); ?>">Stadswandelingen</a></li>
                <li><a href="<?php echo esc_url( home_url( '/activiteiten-den-bosch/workshop-den-bosch/' ) ); ?>">Workshops</a></li>
            </ul>
        </div>
        <div class="ddb-mega-nav__section">
            <h3 class="ddb-mega-nav__section-title">Meer activiteiten</h3>
            <ul class="ddb-mega-nav__links">
                <li><a href="<?php echo esc_url( home_url( '/activiteiten-den-bosch/spellen/' ) ); ?>">Spellen</a></li>
                <li><a href="<?php echo esc_url( home_url( '/activiteiten-den-bosch/experience/' ) ); ?>">Experiences</a></li>
                <li><a href="<?php echo esc_url( home_url( '/activiteiten-den-bosch/varen-den-bosch/' ) ); ?>">Varen</a></li>
            </ul>
        </div>
        <div class="ddb-mega-nav__section ddb-mega-nav__cta">
            <div class="ddb-mega-nav__cta-card">
                <h3 class="ddb-mega-nav__cta-title">Activiteit combineren?</h3>
                <p class="ddb-mega-nav__cta-text">Combineer een activiteit met lunch, borrel of diner.</p>
                <a href="<?php echo esc_url( home_url( '/plan-je-dag/' ) ); ?>" class="ddb-mega-nav__cta-button">
                    <svg class="ddb-mega-nav__cta-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/><path d="M3 10H21" stroke="currentColor" stroke-width="2"/><path d="M8 2V6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M16 2V6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    Maak een programma
                </a>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function render_panel_arrangementen_c4c3399d() {
    ob_start();
    ?>
    <div class="ddb-mega-nav__columns ddb-mega-nav__columns--3">
        <div class="ddb-mega-nav__section">
            <h3 class="ddb-mega-nav__section-title">Arrangementen</h3>
            <ul class="ddb-mega-nav__links">
                <li><a href="<?php echo esc_url( home_url( '/about/maatwerk/' ) ); ?>">Compleet dagprogramma</a></li>
                <li><a href="<?php echo esc_url( home_url( '/restaurant-den-bosch/lunch/' ) ); ?>">Activiteit + lunch</a></li>
                <li><a href="<?php echo esc_url( home_url( '/restaurant-den-bosch/diner/' ) ); ?>">Activiteit + diner</a></li>
                <li><a href="<?php echo esc_url( home_url( '/cafe-den-bosch/' ) ); ?>">Activiteit + borrel</a></li>
            </ul>
        </div>
        <div class="ddb-mega-nav__section">
            <h3 class="ddb-mega-nav__section-title">Inspiratie</h3>
            <div class="ddb-mega-nav__chips">
                <span class="ddb-mega-nav__chip">Teambuilding</span>
                <span class="ddb-mega-nav__chip">Bourgondisch</span>
                <span class="ddb-mega-nav__chip">Actief</span>
                <span class="ddb-mega-nav__chip">Historisch</span>
                <span class="ddb-mega-nav__chip">Regenproof</span>
                <span class="ddb-mega-nav__chip">Premium groep</span>
            </div>
        </div>
        <div class="ddb-mega-nav__section ddb-mega-nav__cta">
            <div class="ddb-mega-nav__cta-card">
                <h3 class="ddb-mega-nav__cta-title">Maatwerk voor groepen</h3>
                <p class="ddb-mega-nav__cta-text">Voor bedrijven, teams en grotere gezelschappen maken we een dagplanning op maat.</p>
                <a href="<?php echo esc_url( home_url( '/plan-je-dag/' ) ); ?>" class="ddb-mega-nav__cta-button">
                    <svg class="ddb-mega-nav__cta-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/><path d="M3 10H21" stroke="currentColor" stroke-width="2"/><path d="M8 2V6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M16 2V6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    Plan je dag
                </a>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function render_panel_eten_c4c3399d() {
    ob_start();
    ?>
    <div class="ddb-mega-nav__columns ddb-mega-nav__columns--2">
        <div class="ddb-mega-nav__section">
            <h3 class="ddb-mega-nav__section-title">Eten &amp; drinken</h3>
            <ul class="ddb-mega-nav__links">
                <li><a href="<?php echo esc_url( home_url( '/restaurant-den-bosch/' ) ); ?>">Restaurants</a></li>
                <li><a href="<?php echo esc_url( home_url( '/restaurant-den-bosch/ontbijt/' ) ); ?>">Ontbijt</a></li>
                <li><a href="<?php echo esc_url( home_url( '/restaurant-den-bosch/lunch/' ) ); ?>">Lunch</a></li>
                <li><a href="<?php echo esc_url( home_url( '/restaurant-den-bosch/diner/' ) ); ?>">Diner</a></li>
                <li><a href="<?php echo esc_url( home_url( '/cafe-den-bosch/' ) ); ?>">Caf&#233; / borrel</a></li>
            </ul>
        </div>
        <div class="ddb-mega-nav__section ddb-mega-nav__cta">
            <div class="ddb-mega-nav__cta-card">
                <h3 class="ddb-mega-nav__cta-title">Horeca toevoegen?</h3>
                <p class="ddb-mega-nav__cta-text">Wij combineren activiteiten met restaurants en borrellocaties.</p>
                <a href="<?php echo esc_url( home_url( '/plan-je-dag/' ) ); ?>" class="ddb-mega-nav__cta-button">
                    <svg class="ddb-mega-nav__cta-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/><path d="M3 10H21" stroke="currentColor" stroke-width="2"/><path d="M8 2V6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M16 2V6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    Combineer met horeca
                </a>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function render_panel_ontdek_c4c3399d() {
    ob_start();
    ?>
    <div class="ddb-mega-nav__columns ddb-mega-nav__columns--1">
        <div class="ddb-mega-nav__section">
            <h3 class="ddb-mega-nav__section-title">Ontdek Den Bosch</h3>
            <ul class="ddb-mega-nav__links ddb-mega-nav__links--grid">
                <li><a href="<?php echo esc_url( home_url( '/den-bosch/' ) ); ?>">Den Bosch</a></li>
                <li><a href="<?php echo esc_url( home_url( '/bezienswaardigheden/' ) ); ?>">Bezienswaardigheden</a></li>
                <li><a href="<?php echo esc_url( home_url( '/evenementen/' ) ); ?>">Evenementen</a></li>
                <li><a href="<?php echo esc_url( home_url( '/hotels/' ) ); ?>">Hotels</a></li>
                <li><a href="<?php echo esc_url( home_url( '/musea/' ) ); ?>">Musea</a></li>
                <li><a href="<?php echo esc_url( home_url( '/winkels/' ) ); ?>">Winkels</a></li>
            </ul>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function render_panel_zakelijk_c4c3399d() {
    ob_start();
    ?>
    <div class="ddb-mega-nav__columns ddb-mega-nav__columns--3">
        <div class="ddb-mega-nav__section">
            <h3 class="ddb-mega-nav__section-title">Zakelijk</h3>
            <ul class="ddb-mega-nav__links">
                <li><a href="<?php echo esc_url( home_url( '/groepsuitjes/bedrijfsuitje-den-bosch/' ) ); ?>">Bedrijfsuitje Den Bosch</a></li>
                <li><a href="<?php echo esc_url( home_url( '/about/maatwerk/' ) ); ?>">Maatwerk programma</a></li>
                <li><a href="<?php echo esc_url( home_url( '/samenwerken/' ) ); ?>">Samenwerken</a></li>
                <li><a href="<?php echo esc_url( home_url( '/veel-gestelde-vragen/' ) ); ?>">Veelgestelde vragen</a></li>
                <li><a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>">Contact</a></li>
            </ul>
        </div>
        <div class="ddb-mega-nav__section">
            <h3 class="ddb-mega-nav__section-title">Zakelijke voordelen</h3>
            <ul class="ddb-mega-nav__benefits">
                <li>E&#233;n aanspreekpunt</li>
                <li>Programma op maat</li>
                <li>Activiteiten + horeca</li>
                <li>Geschikt voor grotere groepen</li>
                <li>Offerte en planning via Quote OS</li>
            </ul>
        </div>
        <div class="ddb-mega-nav__section ddb-mega-nav__cta">
            <div class="ddb-mega-nav__cta-card">
                <h3 class="ddb-mega-nav__cta-title">Zakelijk uitje organiseren?</h3>
                <p class="ddb-mega-nav__cta-text">Vertel ons datum, groepsgrootte en doel van de dag.</p>
                <a href="<?php echo esc_url( home_url( '/plan-je-dag/' ) ); ?>" class="ddb-mega-nav__cta-button">
                    <svg class="ddb-mega-nav__cta-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/><path d="M3 10H21" stroke="currentColor" stroke-width="2"/><path d="M8 2V6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M16 2V6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    Plan je zakelijke dag
                </a>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
