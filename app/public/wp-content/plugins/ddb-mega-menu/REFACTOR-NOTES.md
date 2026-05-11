# Refactor Notes

## Architecture Decisions
- Chosen rendering strategy: shortcode-first (`[ddb_mega_menu]`) for stable Elementor integration.
- Data is separated from rendering:
  - `class-ddb-megamenu-data.php`: menu structure + actions.
  - `class-ddb-megamenu-shortcode.php`: HTML output and accessibility attributes.
- Settings are isolated in `class-ddb-megamenu-admin.php` under WordPress Settings API.

## Why This Approach
- Works in Elementor without editing theme PHP directly.
- Keeps DOM compact and avoids page-builder-specific runtime dependencies.
- Ensures mobile + desktop menu behavior from one JS file and one CSS file.
- Supports theme mode at component level (`data-theme`) while remaining compatible with site-level `html[data-theme]`.

## Extension Points
- Filter top-level menu data with `ddb_megamenu_items`.
- Filter action links with `ddb_megamenu_actions`.
- Override enqueue strategy with `ddb_megamenu_always_enqueue`.

## Avoided on Purpose
- No external JS/CSS frameworks.
- No jQuery dependency.
- No webfont/CDN coupling.
