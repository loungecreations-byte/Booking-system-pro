# Booking Pro Module Guidelines

## Project Structure & Module Organization
Core bootstrap lives in `booking-pro-module.php`, which wires services from `includes/`, keeping each `class-*.php` focused on a single capability such as REST, product types, or availability sync. Front-end and admin assets belong under `assets/`. Place REST overrides in `mu-plugins/`, generated zips in `dist/` (never edit), translations inside `languages/`, and future automated tests under `tests/` mirroring the PHP namespace layout.

## Build, Test, and Development Commands
- `wp plugin activate booking-pro-module` — enable the plugin locally after syncing or pulling new code.
- `wp eval "do_action('sbdp_seed_demo_data');"` — reseed demo planner data to reproduce booking scenarios.
- `Get-ChildItem -Recurse -Filter '*.php' | ForEach-Object { php -l $_.FullName }` — quick syntax sweep; use `composer phpcs` for full style linting.
- `curl https://site.local/wp-json/sbdp/v1/services` — fast REST smoke test; confirm expected service payloads.

## Coding Style & Naming Conventions
Target PHP 7.4+ with four-space indentation, PSR-12 ordering, and trailing commas in multiline arrays. Prefix new PHP classes with `SBDP_` and store them in matching `includes/class-*.php` files. Keep function names snake_case per WordPress norms, wrap user-facing strings in `__()` or `_x()` with the `sbdp` text domain, and structure JavaScript as ES6 modules that expose named selector constants.

## Testing Guidelines
While PHPUnit wiring is pending, lint PHP before pushing and document manual verification covering planner drag-and-drop, both pay and request flows on `compose_booking`, admin availability edits, and weather/channel/pricing edge cases. Add temporary coverage scripts under `tests/` when needed, commit them only if broadly useful, and remove ad-hoc helpers before release.

## Commit & Pull Request Guidelines
Write focused, imperative commits (for example, "Add SBDP_Rule_Engine adjustments") and explain intent plus rollout notes in the body. Pull requests should summarize domain impact, list manual test results, link related issues, and include screenshots or REST traces for UI or API changes. Highlight any revenue, logistics, or hardware implications so reviewers can assess cross-channel effects early.

## Operations & Domain Reminders
Respect outlet scoping for commercial channels such as GetYourGuide, Viator, Tripadvisor, and Briq. Preserve audit trails when adjusting pricing or capacity, safeguard personalization rules, and ensure hardware resilience and notification flows remain intact when touching booking logic or partner integrations.
