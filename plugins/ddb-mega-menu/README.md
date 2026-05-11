# DDB Mega Menu

Production-ready mega menu plugin for WordPress + Elementor Pro Theme Builder.

## Features
- Shortcodes:
  - `[ddb_mega_menu]`
  - `[ddb_new_menu]` (alias)
- Optional shortcode attributes:
  - `theme="auto|light|dark"`
  - `sticky="yes|no"`
  - `transparent="yes|no"`
  - `mobile_bottom_bar="yes|no"`
- Desktop mega menu + mobile drawer + optional mobile bottom bar
- Right-side header actions: Search, Favorites, Planner, Account
- Primary CTA: Plan je dag
- Keyboard accessibility and Escape-close behavior
- Lightweight vanilla JS (no jQuery dependency)
- Settings page: `Settings > DDB Mega Menu`
- Visual Mega Menu Builder in settings (edit full columns/links/highlights, inclusief highlight-afbeelding, zonder code)

## Files
- `ddb-mega-menu.php`
- `includes/class-ddb-megamenu.php`
- `includes/class-ddb-megamenu-data.php`
- `includes/class-ddb-megamenu-shortcode.php`
- `includes/class-ddb-megamenu-admin.php`
- `assets/css/megamenu.css`
- `assets/js/megamenu.js`
- `templates/elementor/*.json` (starter page templates)

## Install
1. Activate `DDB Mega Menu` in WordPress plugins.
2. Go to `Settings > DDB Mega Menu`.
3. Configure logo, CTA, default theme mode, sticky/transparent behavior.
4. Build menu content in `Mega menu structuur (visual builder)` and save.
5. Add shortcode `[ddb_mega_menu]` in Elementor Theme Builder header template (Shortcode widget).
6. Import starter templates from `templates/elementor` if needed.

## Developer Notes
- Menu data defaults are centralized in `class-ddb-megamenu-data.php`.
- Top-level label/url overrides can be configured via JSON in settings (`custom_menu_json`).
- For custom projects, use filters:
  - `ddb_megamenu_items`
  - `ddb_megamenu_actions`
  - `ddb_megamenu_always_enqueue`
