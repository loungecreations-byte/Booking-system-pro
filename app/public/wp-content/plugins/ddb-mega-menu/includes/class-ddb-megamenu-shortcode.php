<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DDB_MegaMenu_Shortcode
{
    private DDB_MegaMenu $plugin;

    public function __construct(DDB_MegaMenu $plugin)
    {
        $this->plugin = $plugin;
        add_action('init', [$this, 'register_shortcode']);
    }

    public function register_shortcode(): void
    {
        add_shortcode('ddb_mega_menu', [$this, 'render']);
        add_shortcode('ddb_new_menu', [$this, 'render']);
    }

    public function render($atts = []): string
    {
        if (!is_array($atts)) {
            $atts = [];
        }
        
        $settings = $this->plugin->get_settings();

        $atts = shortcode_atts(
            [
                'theme' => '',
                'sticky' => '',
                'transparent' => '',
                'mobile_bottom_bar' => '',
            ],
            $atts,
            'ddb_mega_menu'
        );

        $requested_theme = trim((string) $atts['theme']);
        $theme_mode = $requested_theme === ''
            ? 'auto'
            : $this->plugin->resolve_theme_mode($requested_theme, (string) $settings['default_theme_mode']);
        $sticky = $this->plugin->resolve_yes_no($atts['sticky'], !empty($settings['enable_sticky_header']));
        $transparent = $this->plugin->resolve_yes_no($atts['transparent'], !empty($settings['enable_transparent_header_home']));
        $mobile_bottom_bar = $this->plugin->resolve_yes_no($atts['mobile_bottom_bar'], !empty($settings['enable_mobile_bottom_bar']));

        $is_home = is_front_page() || is_home();
        $use_transparent_home = $transparent && $is_home;

        $menu_items = DDB_MegaMenu_Data::get_menu_items($settings);
        $actions = DDB_MegaMenu_Data::get_actions($settings);

        $logo_url = $this->resolve_logo_url((string) $settings['logo_url']);
        $logo_alt = get_bloginfo('name');

        $cta_label = $settings['cta_label'] !== '' ? $settings['cta_label'] : 'Plan je dag';
        $cta_url = $settings['cta_url'] !== '' ? $settings['cta_url'] : home_url('/plan-je-dag/');

        $uid = wp_unique_id('ddb-mm-');
        $header_classes = ['ddb-header'];
        if ($sticky) {
            $header_classes[] = 'is-sticky-enabled';
        }
        if ($use_transparent_home) {
            $header_classes[] = 'is-transparent';
            $header_classes[] = 'is-top';
        }

        ob_start();
        ?>
        <div
            id="<?php echo esc_attr($uid); ?>"
            class="ddb-mega-menu ui-mega-menu"
            data-theme="<?php echo esc_attr($theme_mode); ?>"
            data-sticky="<?php echo $sticky ? '1' : '0'; ?>"
            data-transparent-home="<?php echo $use_transparent_home ? '1' : '0'; ?>"
            data-mobile-bottom="<?php echo $mobile_bottom_bar ? '1' : '0'; ?>"
        >
            <header class="<?php echo esc_attr(implode(' ', $header_classes)); ?> ui-header">
                <div class="ddb-header__inner">
                    <div class="ddb-header__logo">
                        <a href="<?php echo esc_url(home_url('/')); ?>" aria-label="<?php echo esc_attr($logo_alt); ?>">
                            <?php if ($logo_url !== '') : ?>
                                <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($logo_alt); ?>" loading="eager" decoding="async" />
                            <?php else : ?>
                                <span class="ddb-header__brand-text"><?php echo esc_html($logo_alt); ?></span>
                            <?php endif; ?>
                        </a>
                    </div>

                    <nav class="ddb-header__nav" aria-label="Hoofdnavigatie">
                        <ul class="ddb-header__menu">
                            <?php foreach ($menu_items as $item) :
                                $item_id = sanitize_key((string) ($item['id'] ?? 'item'));
                                $panel_id = $uid . '-panel-' . $item_id;
                                $kind = (string) ($item['kind'] ?? 'link');
                                $label = (string) ($item['label'] ?? 'Menu');
                                $url = (string) ($item['url'] ?? '#');
                                ?>
                                <li class="ddb-header__item ddb-header__item--<?php echo esc_attr($item_id); ?> ddb-header__item--<?php echo esc_attr($kind); ?>">
                                    <?php if (in_array($kind, ['mega', 'dropdown'], true)) : ?>
                                        <button
                                            type="button"
                                            class="ddb-header__trigger"
                                            data-ddb-mega-trigger="<?php echo esc_attr($item_id); ?>"
                                            aria-expanded="false"
                                            aria-controls="<?php echo esc_attr($panel_id); ?>"
                                        >
                                            <span><?php echo esc_html($label); ?></span>
                                            <span class="ddb-header__chevron" aria-hidden="true">▾</span>
                                        </button>
                                    <?php else : ?>
                                        <a class="ddb-header__link" href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </nav>

                    <div class="ddb-header__actions" aria-label="Snelle acties">
                        <?php foreach ($actions as $action) : ?>
                            <a class="ddb-header__action" href="<?php echo esc_url((string) $action['url']); ?>" aria-label="<?php echo esc_attr((string) $action['label']); ?>">
                                <span class="ddb-header__action-icon" aria-hidden="true"><?php echo $this->render_icon((string) ($action['icon'] ?? 'dot')); ?></span>
                                <span class="ddb-header__action-text"><?php echo esc_html((string) $action['label']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <div class="ddb-header__planner-controls" aria-label="Thema en planner">
                        <button type="button" class="ddb-header__theme-toggle" data-ddb-theme-toggle aria-label="Schakel thema" title="Schakel thema">☾</button>
                        <a class="ddb-btn ddb-btn--primary ui-btn ddb-header__cta" href="<?php echo esc_url($cta_url); ?>"><?php echo esc_html($cta_label); ?></a>
                    </div>

                    <button
                        type="button"
                        class="ddb-header__mobile-toggle"
                        aria-expanded="false"
                        aria-controls="<?php echo esc_attr($uid . '-drawer'); ?>"
                    >
                        <span class="ddb-header__mobile-toggle-icon" aria-hidden="true">☰</span>
                        <span class="screen-reader-text">Open menu</span>
                    </button>
                </div>

                <div class="ddb-mega" aria-hidden="true">
                    <?php foreach ($menu_items as $item) :
                        $kind = (string) ($item['kind'] ?? 'link');
                        if (!in_array($kind, ['mega', 'dropdown'], true)) {
                            continue;
                        }

                        $item_id = sanitize_key((string) ($item['id'] ?? 'item'));
                        $panel_id = $uid . '-panel-' . $item_id;
                        ?>
                        <section
                            id="<?php echo esc_attr($panel_id); ?>"
                            class="ddb-mega__panel"
                            data-ddb-mega-panel="<?php echo esc_attr($item_id); ?>"
                            hidden
                        >
                            <div class="ddb-mega__panel-inner <?php echo $kind === 'dropdown' ? 'ddb-mega__panel-inner--dropdown' : ''; ?>">
                                <?php if ($kind === 'mega') : ?>
                                    <div class="ddb-mega__columns">
                                        <?php foreach ((array) ($item['columns'] ?? []) as $column) : ?>
                                            <div class="ddb-mega__column">
                                                <h3 class="ddb-mega__title"><?php echo esc_html((string) ($column['title'] ?? '')); ?></h3>
                                                <ul class="ddb-mega__list">
                                                    <?php foreach ((array) ($column['links'] ?? []) as $link) : ?>
                                                        <li><a class="ddb-mega__link" href="<?php echo esc_url((string) ($link['url'] ?? '#')); ?>"><?php echo esc_html((string) ($link['label'] ?? 'Link')); ?></a></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <?php if (!empty($item['highlight']) && is_array($item['highlight'])) : ?>
                                        <aside class="ddb-mega__highlight">
                                            <?php if (!empty($item['highlight']['image_url'])) :
                                                $highlight_image_alt = (string) ($item['highlight']['image_alt'] ?? '');
                                                if ($highlight_image_alt === '') {
                                                    $highlight_image_alt = (string) ($item['highlight']['title'] ?? '');
                                                }
                                                ?>
                                                <figure class="ddb-mega__highlight-media">
                                                    <img
                                                        src="<?php echo esc_url((string) $item['highlight']['image_url']); ?>"
                                                        alt="<?php echo esc_attr($highlight_image_alt); ?>"
                                                        loading="lazy"
                                                        decoding="async"
                                                    />
                                                </figure>
                                            <?php endif; ?>
                                            <?php if (!empty($item['highlight']['eyebrow'])) : ?>
                                                <p class="ddb-mega__eyebrow"><?php echo esc_html((string) $item['highlight']['eyebrow']); ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($item['highlight']['title'])) : ?>
                                                <h3 class="ddb-mega__highlight-title"><?php echo esc_html((string) $item['highlight']['title']); ?></h3>
                                            <?php endif; ?>
                                            <?php if (!empty($item['highlight']['text'])) : ?>
                                                <p class="ddb-mega__highlight-text"><?php echo esc_html((string) $item['highlight']['text']); ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($item['highlight']['cta_url']) && !empty($item['highlight']['cta_label'])) : ?>
                                                <a class="ddb-btn ddb-btn--secondary ui-btn" href="<?php echo esc_url((string) $item['highlight']['cta_url']); ?>"><?php echo esc_html((string) $item['highlight']['cta_label']); ?></a>
                                            <?php endif; ?>
                                        </aside>
                                    <?php endif; ?>

                                    <?php if (!empty($item['footer_cta']) && is_array($item['footer_cta'])) : ?>
                                        <div class="ddb-mega__footer">
                                            <a class="ddb-mega__footer-link" href="<?php echo esc_url((string) ($item['footer_cta']['url'] ?? '#')); ?>"><?php echo esc_html((string) ($item['footer_cta']['label'] ?? 'Meer bekijken')); ?></a>
                                        </div>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <div class="ddb-mega__column">
                                        <h3 class="ddb-mega__title"><?php echo esc_html((string) ($item['label'] ?? '')); ?></h3>
                                        <ul class="ddb-mega__list">
                                            <?php foreach ((array) ($item['links'] ?? []) as $link) : ?>
                                                <li><a class="ddb-mega__link" href="<?php echo esc_url((string) ($link['url'] ?? '#')); ?>"><?php echo esc_html((string) ($link['label'] ?? 'Link')); ?></a></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div>
            </header>

            <aside id="<?php echo esc_attr($uid . '-drawer'); ?>" class="ddb-mobile-drawer" hidden>
                <div class="ddb-mobile-drawer__header">
                    <span class="ddb-mobile-drawer__title">Menu</span>
                    <button type="button" class="ddb-mobile-drawer__close" aria-label="Sluit menu">✕</button>
                </div>

                <nav class="ddb-mobile-drawer__nav" aria-label="Mobiele navigatie">
                    <ul class="ddb-mobile-drawer__list">
                        <?php foreach ($menu_items as $item) :
                            $item_id = sanitize_key((string) ($item['id'] ?? 'item'));
                            $kind = (string) ($item['kind'] ?? 'link');
                            $mobile_panel_id = $uid . '-mobile-' . $item_id;
                            ?>
                            <li class="ddb-mobile-drawer__item ddb-mobile-drawer__item--<?php echo esc_attr($kind); ?>">
                                <?php if (in_array($kind, ['mega', 'dropdown'], true)) : ?>
                                    <button
                                        type="button"
                                        class="ddb-mobile-drawer__trigger"
                                        data-ddb-mobile-trigger="<?php echo esc_attr($item_id); ?>"
                                        aria-expanded="false"
                                        aria-controls="<?php echo esc_attr($mobile_panel_id); ?>"
                                    >
                                        <span><?php echo esc_html((string) ($item['label'] ?? 'Menu')); ?></span>
                                        <span aria-hidden="true">+</span>
                                    </button>
                                    <div id="<?php echo esc_attr($mobile_panel_id); ?>" class="ddb-mobile-drawer__panel" data-ddb-mobile-panel="<?php echo esc_attr($item_id); ?>" hidden>
                                        <?php if ($kind === 'mega') : ?>
                                            <?php foreach ((array) ($item['columns'] ?? []) as $column) : ?>
                                                <h4 class="ddb-mobile-drawer__title-sm"><?php echo esc_html((string) ($column['title'] ?? '')); ?></h4>
                                                <ul class="ddb-mobile-drawer__sublist">
                                                    <?php foreach ((array) ($column['links'] ?? []) as $link) : ?>
                                                        <li><a class="ddb-mobile-drawer__link" href="<?php echo esc_url((string) ($link['url'] ?? '#')); ?>"><?php echo esc_html((string) ($link['label'] ?? 'Link')); ?></a></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php endforeach; ?>

                                            <?php if (!empty($item['highlight']) && is_array($item['highlight'])) :
                                                $mobile_highlight_image_alt = (string) ($item['highlight']['image_alt'] ?? '');
                                                if ($mobile_highlight_image_alt === '') {
                                                    $mobile_highlight_image_alt = (string) ($item['highlight']['title'] ?? '');
                                                }
                                                ?>
                                                <div class="ddb-mobile-drawer__highlight">
                                                    <?php if (!empty($item['highlight']['image_url'])) : ?>
                                                        <figure class="ddb-mobile-drawer__highlight-media">
                                                            <img
                                                                src="<?php echo esc_url((string) $item['highlight']['image_url']); ?>"
                                                                alt="<?php echo esc_attr($mobile_highlight_image_alt); ?>"
                                                                loading="lazy"
                                                                decoding="async"
                                                            />
                                                        </figure>
                                                    <?php endif; ?>
                                                    <?php if (!empty($item['highlight']['eyebrow'])) : ?>
                                                        <p class="ddb-mobile-drawer__eyebrow"><?php echo esc_html((string) $item['highlight']['eyebrow']); ?></p>
                                                    <?php endif; ?>
                                                    <?php if (!empty($item['highlight']['title'])) : ?>
                                                        <p class="ddb-mobile-drawer__highlight-title"><?php echo esc_html((string) $item['highlight']['title']); ?></p>
                                                    <?php endif; ?>
                                                    <?php if (!empty($item['highlight']['text'])) : ?>
                                                        <p class="ddb-mobile-drawer__highlight-text"><?php echo esc_html((string) $item['highlight']['text']); ?></p>
                                                    <?php endif; ?>
                                                    <?php if (!empty($item['highlight']['cta_url']) && !empty($item['highlight']['cta_label'])) : ?>
                                                        <a class="ddb-mobile-drawer__footer-link" href="<?php echo esc_url((string) $item['highlight']['cta_url']); ?>"><?php echo esc_html((string) $item['highlight']['cta_label']); ?></a>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($item['footer_cta']) && is_array($item['footer_cta'])) : ?>
                                                <a class="ddb-mobile-drawer__footer-link" href="<?php echo esc_url((string) ($item['footer_cta']['url'] ?? '#')); ?>"><?php echo esc_html((string) ($item['footer_cta']['label'] ?? 'Meer bekijken')); ?></a>
                                            <?php endif; ?>
                                        <?php else : ?>
                                            <ul class="ddb-mobile-drawer__sublist">
                                                <?php foreach ((array) ($item['links'] ?? []) as $link) : ?>
                                                    <li><a class="ddb-mobile-drawer__link" href="<?php echo esc_url((string) ($link['url'] ?? '#')); ?>"><?php echo esc_html((string) ($link['label'] ?? 'Link')); ?></a></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </div>
                                <?php else : ?>
                                    <a class="ddb-mobile-drawer__link" href="<?php echo esc_url((string) ($item['url'] ?? '#')); ?>"><?php echo esc_html((string) ($item['label'] ?? 'Link')); ?></a>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </nav>

                <div class="ddb-mobile-drawer__actions">
                    <?php foreach ($actions as $action) : ?>
                        <a class="ddb-mobile-drawer__action" href="<?php echo esc_url((string) $action['url']); ?>">
                            <span class="ddb-mobile-drawer__action-icon" aria-hidden="true"><?php echo $this->render_icon((string) ($action['icon'] ?? 'dot')); ?></span>
                            <span><?php echo esc_html((string) $action['label']); ?></span>
                        </a>
                    <?php endforeach; ?>
                    <button type="button" class="ddb-mobile-drawer__theme-toggle" data-ddb-theme-toggle aria-label="Schakel thema" title="Schakel thema">☾</button>
                </div>

                <a class="ddb-btn ddb-btn--primary ui-btn ddb-mobile-drawer__cta" href="<?php echo esc_url($cta_url); ?>"><?php echo esc_html($cta_label); ?></a>
            </aside>

            <button type="button" class="ddb-mobile-backdrop" hidden aria-label="Sluit menu"></button>

            <?php if ($mobile_bottom_bar) : ?>
                <nav class="ddb-mobile-bottom" aria-label="Mobiele quick acties">
                    <?php foreach ($actions as $action) : ?>
                        <a class="ddb-mobile-bottom__item" href="<?php echo esc_url((string) $action['url']); ?>">
                            <span class="ddb-mobile-bottom__icon" aria-hidden="true"><?php echo $this->render_icon((string) ($action['icon'] ?? 'dot')); ?></span>
                            <span class="ddb-mobile-bottom__label"><?php echo esc_html((string) $action['label']); ?></span>
                        </a>
                    <?php endforeach; ?>
                    <a class="ddb-mobile-bottom__item ddb-mobile-bottom__item--cta" href="<?php echo esc_url($cta_url); ?>">
                        <span class="ddb-mobile-bottom__label"><?php echo esc_html($cta_label); ?></span>
                    </a>
                </nav>
            <?php endif; ?>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    private function resolve_logo_url(string $configured): string
    {
        if ($configured !== '') {
            return esc_url_raw($configured);
        }

        if (function_exists('has_custom_logo') && has_custom_logo()) {
            $custom_logo_id = (int) get_theme_mod('custom_logo');
            $logo = wp_get_attachment_image_url($custom_logo_id, 'full');
            if (is_string($logo) && $logo !== '') {
                return $logo;
            }
        }

        return '';
    }

    private function render_icon(string $name): string
    {
        $icons = [
            'search' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" aria-hidden="true"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.8"></circle><path d="M20 20L16.65 16.65" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"></path></svg>',
            'heart' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" aria-hidden="true"><path d="M12 20.5S4 15.2 4 9.8C4 7 6.2 5 8.9 5c1.6 0 2.9.8 3.8 2 0.9-1.2 2.2-2 3.8-2C19.8 5 22 7 22 9.8c0 5.4-8 10.7-10 10.7Z" stroke="currentColor" stroke-width="1.8"></path></svg>',
            'calendar' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.8"></rect><path d="M8 3V7M16 3V7M3 10H21" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"></path></svg>',
            'user' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" aria-hidden="true"><circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="1.8"></circle><path d="M4 20c0-3.3 3.6-6 8-6s8 2.7 8 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"></path></svg>',
            'dot' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><circle cx="12" cy="12" r="3.5"></circle></svg>',
        ];

        return $icons[$name] ?? $icons['dot'];
    }
}
