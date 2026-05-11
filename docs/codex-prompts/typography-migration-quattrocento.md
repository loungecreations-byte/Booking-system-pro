# Codex CLI Prompt — Typography Migration: Inter/Manrope → Quattrocento

## Authority
`DDB_DESIGN_SYSTEM_SPEC.md` (root) is the canonical typography authority.
The decision has been made: **Quattrocento (Serif) for headlines, Quattrocento Sans for UI/body.**

## Your mission
Replace all occurrences of Inter and Manrope with the canonical Quattrocento family
across all project-owned CSS, PHP, and documentation files.
Do NOT touch vendor/, node_modules/, or WordPress core files.

---

## Replacement rules

### Rule 1 — Body / UI font
All `font-family` values that reference `'Inter'` or `Inter` as the UI or body font
must become `'Quattrocento Sans', sans-serif`.

Examples:
```
font-family: 'Inter', sans-serif;          → font-family: 'Quattrocento Sans', sans-serif;
font-family: 'Inter', system-ui, ...;      → font-family: 'Quattrocento Sans', sans-serif;
--font-body: 'Inter', ...;                 → --font-body: 'Quattrocento Sans', sans-serif;
--font-ui: 'Inter', ...;                   → --font-ui: 'Quattrocento Sans', sans-serif;
--font-family-base: 'Inter', ...;          → --font-family-base: 'Quattrocento Sans', sans-serif;
```

### Rule 2 — Headline / display font
All `font-family` values that reference `'Manrope'` or `Manrope` as headline/display font
must become `'Quattrocento', serif`.

Examples:
```
font-family: 'Manrope', sans-serif;        → font-family: 'Quattrocento', serif;
--font-heading: 'Manrope', ...;            → --font-heading: 'Quattrocento', serif;
--font-display: 'Manrope', ...;            → --font-display: 'Quattrocento', serif;
--font-family-heading: 'Manrope', ...;     → --font-family-heading: 'Quattrocento', serif;
```

### Rule 3 — @font-face declarations
- Remove or replace `@font-face` blocks loading Inter or Manrope from Google Fonts or CDN.
- Replace with `@font-face` blocks loading Quattrocento and Quattrocento Sans, OR
  replace the Google Fonts `@import` URL with one that loads Quattrocento and Quattrocento Sans.

Google Fonts URL for Quattrocento:
```
https://fonts.googleapis.com/css2?family=Quattrocento:wght@400;700&family=Quattrocento+Sans:ital,wght@0,400;0,700;1,400&display=swap
```

### Rule 4 — Documentation references
In all `.md` files under `docs/`, replace:
- "Inter" (when used as a font name) → "Quattrocento Sans"
- "Manrope" → "Quattrocento"

Exception: the audit file `docs/DDB_MARKDOWN_AUDIT_2026.md` records history —
update its "typography conflict" finding to reflect that the conflict is resolved.

---

## Scope — files to change

Search and update ONLY these files (not vendor, not WordPress core, not wp-includes):

### CSS files
- `app/public/wp-content/plugins/ddb-core-ui/assets/css/design-system.css`
- `app/public/wp-content/plugins/ddb-core-ui/assets/css/fonts-local.css`
- `app/public/wp-content/plugins/booking-pro-module/assets/css/design-system.css`
- `app/public/wp-content/plugins/booking-pro-module/assets/css/day-planner.css`
- `app/public/wp-content/plugins/booking-pro-module/assets/css/day-planner-refresh.css`
- `app/public/wp-content/plugins/booking-pro-module/assets/css/plan-je-dag-chrome.css`
- `app/public/wp-content/plugins/booking-pro-module/assets/css/page-overrides.css`
- `app/public/wp-content/plugins/booking-pro-module/assets/global-theme.css`
- `app/public/wp-content/plugins/booking-pro-module/assets/planner.css`
- `app/public/wp-content/themes/hello-biz/assets/css/theme.css`
- `app/public/wp-content/themes/hello-biz/assets/css/theme-rtl.css`

### PHP files
- `app/public/wp-content/mu-plugins/ddb-core-design-system.php`

### Doc files
- `docs/03-design-system-primitives-agent.md` (already updated — verify only)
- `docs/DDB_MARKDOWN_AUDIT_2026.md` (update the "typography conflict" status to resolved)

---

## Do NOT change

- Any file under `vendor/`
- Any file under `node_modules/`
- Any file under `wp-includes/` or `wp-admin/`
- Built/compiled JS dist files (`assets/js/**/dist/*.js`) — these must be rebuilt from source
- Files in `ops/codex-output/` (CI artifacts)

---

## After changes

1. Verify that `@import` or `@font-face` for Quattrocento and Quattrocento Sans is present
   in exactly one place: `ddb-core-ui/assets/css/fonts-local.css` (or equivalent font loader).
   No other CSS file should independently load the fonts.

2. Run a final search for remaining `Manrope` and standalone `'Inter'` font references
   in the changed files to confirm 0 remaining instances.

3. Report: file path + line + old value + new value for every change made.

---

## Success criteria

- 0 occurrences of `font-family.*Manrope` in project-owned files
- 0 occurrences of `font-family.*'Inter'` in project-owned files
- Font load is consolidated in the font loader (not duplicated across CSS files)
- Both Quattrocento weights (400, 700) and Quattrocento Sans weights (400, 700, italic 400) are available
- No `!important` penalties introduced by the migration
