# Elementor Install Steps (DDB Mega Menu)

## 1. Activate Plugin
1. Open `WP Admin > Plugins`.
2. Activate `DDB Mega Menu`.

## 2. Configure Menu Settings
1. Open `Settings > DDB Mega Menu`.
2. Fill:
   - Logo URL
   - CTA label and URL
   - Sticky header toggle
   - Transparent header on homepage toggle
   - Mobile bottom bar toggle
   - Default theme mode (`auto`, `light`, `dark`)
3. Open `Mega menu structuur (visual builder)`:
   - Edit top-level items, columns and links.
   - For mega items: edit highlight card text + CTA.
   - Use `Select image` in highlight card om een afbeelding uit de Media Library te kiezen.
   - Save settings.

## 3. Add to Elementor Theme Builder Header
1. Open `Elementor > Theme Builder > Header`.
2. Edit or create the global header template.
3. Add a `Shortcode` widget.
4. Paste:
   - Default: `[ddb_mega_menu]` (or alias `[ddb_new_menu]`)
   - Example forced dark sticky: `[ddb_mega_menu theme="dark" sticky="yes" transparent="no" mobile_bottom_bar="yes"]`
5. Publish display conditions for the full site.

## 4. Footer/Page Templates
- Keep footer as Elementor template.
- Use `ddb-core-ui` classes in sections/cards/buttons for consistent styling:
  - `ui-section`, `ui-card`, `ui-grid`, `ui-btn`.

## 5. Validation Checklist
- Desktop: mega panels open/close with mouse + keyboard.
- Escape closes open mega panel and mobile drawer.
- Mobile: drawer opens from toggle and closes via close button/backdrop.
- Theme mode follows shortcode/admin setting.
