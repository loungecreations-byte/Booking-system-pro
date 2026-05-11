# DDB Cleanup Audit

Date: 2026-04-08
Scope: full repository/runtime cleanup audit
Status: audit complete, safe cleanup executed, legacy `ddb-ui.css` blob archived out of runtime, feature mu-plugins migrated into plugin runtime

## Executive Verdict

The platform is carrying three different kinds of weight at once:

- real runtime code
- development tooling and test debris shipped inside runtime paths
- abandoned or emergency architecture that was never normalized back into the product system

The largest architectural failures are:

1. Two active design-system runtimes exist at the same time.
2. `mu-plugins` is being used as a feature bucket instead of bootstrap/governance only.
3. `booking-pro-module/assets/css/ddb-ui.css` is effectively corrupted output, not a maintainable stylesheet.
4. The custom `dagjedenbosch` child theme contains platform intent, but WordPress is actually running `hello-biz`.

Update after execution:

- the oversized runtime `ddb-ui.css` bundle has been archived out of runtime
- the `ddb-ui` handle now points at the canonical design-system bundle
- the old file path remains as a small compatibility shim only
- app-route style enforcement now keeps the `ddb-core-ui` stack as canonical owner, with the mu-runtime reduced to normalization and token bridging
- feature files formerly living in `wp-content/mu-plugins` now load from `booking-pro-module/mu-plugins`
- emergency planner and tour override files are now rollback-only and disabled by default unless explicitly re-enabled by constant
- `plan-je-dag-ultimate.php` has been split into a loader plus focused runtime files for emergency overrides, planner runtime hooks, and activities overview hooks
- the plugin-internal `mu-plugins` layer has been replaced by `includes/bootstrap`, and duplicate nested bootstrap copies were archived out of runtime

This means the repo currently behaves like a merged runtime + workshop + archive, not a normalized premium platform.

## Active Runtime Truth

- Active theme: `hello-biz`
- Inactive custom child theme: `dagjedenbosch`
- Active plugin design-system runtime: `app/public/wp-content/plugins/ddb-core-ui/core-ui.php`
- Legacy mu design-system file still exists for rollback helpers, but runtime boot is disabled unless `DDB_ENABLE_LEGACY_MU_DESIGN_SYSTEM` is explicitly set

Result:

- active visual runtime truth is now `ddb-core-ui`
- the intended shell/theme truth is still ambiguous because the custom child theme remains inactive in archive

## Top Findings

### 1. Design-system duplication

Paths:

- `app/public/wp-content/mu-plugins/ddb-core-design-system.php`
- `app/public/wp-content/plugins/ddb-core-ui/core-ui.php`
- `app/public/wp-content/plugins/booking-pro-module/assets/css/ddb-ui.css`
- `app/public/wp-content/plugins/booking-pro-module/assets/css/design-system.css`
- `app/public/wp-content/plugins/ddb-core-ui/assets/css/design-system.css`

Verdict:

- Classification: `MERGE / CONSOLIDATE`
- Why: multiple runtime owners for tokens, theme mapping, and shared UI
- Risk: high if changed blindly, because large parts of frontend currently lean on this overlap
- Action: choose one runtime owner, migrate dependent assets, then remove the other owner

### 2. Catastrophic CSS bloat

Primary path:

- `app/public/wp-content/plugins/booking-pro-module/assets/css/ddb-ui.css`

Measured evidence:

- size: `154,505,899` bytes
- lines: `5,143,944`
- `!important` count: about `2,039,096`

Verdict:

- Classification: `MERGE / CONSOLIDATE`, then likely `DELETE`
- Why: this is not normal legacy CSS; it is repeated/generated bloat
- Risk: high if removed before bundle ownership is normalized
- Action: diff against actually-used selectors, rebuild minimal bundle, stop enqueueing this file

### 3. mu-plugin misuse

Primary paths:

- `app/public/wp-content/mu-plugins/plan-je-dag-ultimate.php`
- `app/public/wp-content/mu-plugins/sbdp-planner-domain.php`
- `app/public/wp-content/mu-plugins/sbdp-price-sync.php`
- `app/public/wp-content/mu-plugins/sbdp-single-product-planner.php`
- `app/public/wp-content/mu-plugins/force-elementor-wrapper.php`

Verdict:

- Classification: `MOVE OUT OF MU`
- Why: feature logic, planner UI, pricing sync, frontend assets, and view orchestration are not mu-plugin concerns
- Risk: medium to high depending on call order and initialization assumptions
- Action: move to platform plugin modules with explicit load order and tests

### 4. Runtime shipped with dev/build/test debris

Primary paths:

- `app/public/wp-content/plugins/hello-plus/node_modules`
- `app/public/wp-content/plugins/booking-pro-module/node_modules`
- `app/public/wp-content/plugins/booking-pro-module/build/phpstan`
- `app/public/wp-content/plugins/booking-pro-module/.build-test`
- `app/public/wp-content/plugins/booking-pro-module/tests`
- `app/public/wp-content/plugins/booking-pro-module/spec`
- `app/public/wp-content/plugins/booking-pro-module/composer.phar`

Verdict:

- Classification: mostly `ARCHIVE OUTSIDE RUNTIME` or `DELETE`
- Why: these are not required for runtime execution
- Risk: low if they are not part of the local development workflow on this exact deployed tree
- Action: move development-only material out of `wp-content/plugins`

### 5. Dormant custom theme

Primary paths:

- `app/public/wp-content/themes/dagjedenbosch`
- `app/public/wp-content/themes/hello-biz`

Verdict:

- Classification: `ARCHIVE OUTSIDE RUNTIME` unless reactivated or migrated
- Why: the custom theme contains real overrides, but it is not the active theme
- Risk: medium because some intended shell/template truth may exist there only
- Action: choose one:
  - reactivate and normalize the child theme
  - migrate its useful code into the active runtime and archive it

## Candidate Matrix

| Path | Classification | Evidence | Why | Risk | Recommended Action |
| --- | --- | --- | --- | --- | --- |
| `search_results_full.txt` | DELETE | 3.3 GB root artifact, no runtime references found | pure forensic dump | Low | delete immediately |
| `search_results.csv` and root `search_results_*.txt` | DELETE | generated search output | pure audit debris | Low | delete immediately |
| root `tmp-*`, `tmp_*.php`, `tmp-home.html`, debug PNGs | DELETE | scratch files in repo root | not product code | Low | delete immediately |
| `app/public/wp-content/debug.log` | DELETE | 13.5 MB runtime log | stale log, not runtime requirement | Low | purge, then rotate |
| `app/public/wp-content/uploads/wc-logs/*` | DELETE | accumulated Woo logs | runtime history, not runtime dependency | Low | purge old logs, keep logging policy |
| `app/public/wp-content/plugins/booking-pro-module/build/phpstan` | DELETE | phpstan cache in runtime tree | dev-only | Low | delete |
| `app/public/wp-content/plugins/booking-pro-module/composer.phar` | DELETE | local tool binary inside plugin | dev-only | Low | delete |
| `app/public/wp-content/mu-plugins/PRODUCT-PLANNER-INTEGRATION.md` | DELETE | markdown doc in mu runtime | docs do not belong in mu runtime | Low | move to `docs/` or remove |
| `app/public/wp-content/plugins/booking-pro-module/.build-test` | ARCHIVE OUTSIDE RUNTIME | duplicated test/build tree | not runtime | Low | archive out of `wp-content` |
| `app/public/wp-content/plugins/booking-pro-module/tests` | ARCHIVE OUTSIDE RUNTIME | test suite | not runtime | Low | archive out of runtime |
| `app/public/wp-content/plugins/booking-pro-module/spec` | ARCHIVE OUTSIDE RUNTIME | test/spec files | not runtime | Low | archive out of runtime |
| `app/public/wp-content/plugins/hello-plus/node_modules` | ARCHIVE OUTSIDE RUNTIME | ~395 MB dev dependencies | not runtime | Low | archive or remove from deployed tree |
| `app/public/wp-content/plugins/booking-pro-module/node_modules` | ARCHIVE OUTSIDE RUNTIME | ~93 MB dev dependencies | not runtime | Low | archive or remove from deployed tree |
| `app/public/wp-content/themes/twentytwentyfive` | ARCHIVE OUTSIDE RUNTIME | inactive default theme | not used | Low | archive or delete per rollback policy |
| `app/public/wp-content/themes/dagjedenbosch` | ARCHIVE OUTSIDE RUNTIME | inactive custom theme with business intent | dormant, misleading runtime truth | Medium | decide migrate-or-reactivate, then archive |
| `app/public/wp-content/mu-plugins/ddb-core-design-system.php` | MERGE / CONSOLIDATE | overlaps with `ddb-core-ui` runtime | violates single visual truth | High | choose one owner and migrate |
| `app/public/wp-content/plugins/ddb-core-ui/core-ui.php` | KEEP or MERGE / CONSOLIDATE | active design runtime | may be canonical target | High | keep only if chosen single owner |
| `app/public/wp-content/plugins/booking-pro-module/assets/css/ddb-ui.css` | MERGE / CONSOLIDATE | 147 MB+ CSS blob with massive duplication | fake complexity and runtime bloat | High | rebuild, replace, then remove |
| `app/public/wp-content/plugins/booking-pro-module/assets/product-summary.css` | MERGE / CONSOLIDATE | repeated summary-card definitions | duplicate UI ownership | Medium | dedupe into canonical component CSS |
| `app/public/wp-content/mu-plugins/plan-je-dag-ultimate.php` | MERGE / CONSOLIDATE | 658-line planner feature file in mu | wrong runtime layer | High | move to planner plugin/module |
| `app/public/wp-content/mu-plugins/sbdp-planner-domain.php` | MERGE / CONSOLIDATE | domain bridge in mu | wrong runtime layer | Medium | move to planner domain module |
| `app/public/wp-content/mu-plugins/sbdp-price-sync.php` | MERGE / CONSOLIDATE | pricing sync in mu | business logic leakage into mu | High | move to commerce integration layer |
| `app/public/wp-content/mu-plugins/sbdp-single-product-planner.php` | MERGE / CONSOLIDATE | product planner UI in mu | view/orchestration code in mu | High | move to plugin/theme module |
| `app/public/wp-content/mu-plugins/elementor-safe-mode.php` | DELETE | support/troubleshooting file in mu | not platform runtime | Low | remove |
| `app/public/wp-content/mu-plugins/disable-elementor-ai-cloud.php` | DELETE | environment-level workaround | not platform runtime truth | Low | remove or move to environment ops |
| `app/public/wp-content/mu-plugins/zzz-sbdp-tour-opcache-refresh.php` | DELETE | dev/local hotfix style file | not production runtime | Low | remove |
| `app/public/wp-content/mu-plugins/zzz-sbdp-tour-live-override.php` | ARCHIVE OUTSIDE RUNTIME | emergency live override | unstable architecture | Medium | replace with proper module, then archive |
| `app/public/wp-content/plugins/woocommerce-legacy-rest-api` | KEEP pending review | active plugin, weak local dependency evidence | possible external integration dependency | High | verify logs/integrations before removal |

## mu-Plugin Review

### Keep

- `00-ddb-core-loader.php`
- `ddb-stability-guard.php`
- `ddb-core/modules/performance-api.php`
- `sbdp-wc-after-setup-theme-hotfix.php` only as temporary hotfix

### Move out of mu

- `ddb-activiteiten-fixes.php`
- `ddb-filter-shortcode.php`
- `force-elementor-wrapper.php`
- `plan-je-dag-ultimate.php`
- `sbdp-planner-domain.php`
- `sbdp-planner-domain.js`
- `sbdp-price-sync.php`
- `sbdp-single-product-planner.php`

### Archive or delete

- `ddb-header-global-fix.php`
- `disable-elementor-ai-cloud.php`
- `elementor-safe-mode.php`
- `zzz-sbdp-tour-live-override.php`
- `zzz-sbdp-tour-opcache-refresh.php`
- `PRODUCT-PLANNER-INTEGRATION.md`
- `ddb-core/modules/ui-design-system.php`
- `ddb-core/modules/woocommerce-tweaks.php`

### Structural defect

`app/public/wp-content/mu-plugins/ddb-core/loader.php` references a missing `error-handler.php`. Even if harmless at runtime, this is governance debt and proof that the mu layer is not under control.

## Plugin and Theme Review

### Keep

- `woocommerce`
- `mollie-payments-for-woocommerce`
- `elementor`
- `elementor-pro`
- `ddb-content-model`
- `ddb-spots-0.1.0`
- `ddb-mega-menu`
- `ddb-spinwheel`

### Keep, but clean aggressively

- `booking-pro-module`
- `hello-plus`
- `ddb-core-ui`

### Review before removal

- `woocommerce-legacy-rest-api`
- `admin-site-enhancements`
- `woocommerce-pdf-invoices-packing-slips`

### Themes

- active: `hello-biz`
- inactive but meaningful: `dagjedenbosch`
- inactive default: `twentytwentyfive`

## Front-End Cleanup Priorities

1. Pick one design-system runtime.
2. Remove `ddb-ui.css` from runtime after replacement.
3. Normalize dark/light handling to one mechanism.
4. Collapse duplicate card/button/summary systems into canonical shared components.
5. Stop using emergency override files as long-term frontend architecture.

## Back-End Cleanup Priorities

1. Empty runtime of test/spec/build debris.
2. Reduce mu-plugins to bootstrap/governance/performance only.
3. Review dead admin hooks, dead AJAX handlers, and dead REST endpoints in planner/tour code.
4. Move docs, scripts, and maintenance utilities out of runtime paths.
5. Introduce log rotation and stop accumulating ad hoc debug output in `wp-content`.

## Estimated Savings

- Safe delete now: `3.5 GB+`
- Archive outside runtime: `550 MB to 700 MB`
- After consolidation: `150 MB+`, depending mostly on `ddb-ui.css` replacement and design-system normalization

## Cleanup Sequence

1. Snapshot filesystem and database.
2. Delete root junk and stale logs.
3. Remove build caches and local tool binaries from plugin runtime trees.
4. Archive `node_modules`, tests, specs, docs, and backup bundles outside runtime.
5. Decide the single visual runtime owner.
6. Rebuild CSS ownership and remove the giant legacy CSS blob.
7. Move planner, pricing sync, and feature files out of mu-plugins.
8. Resolve theme truth: migrate `dagjedenbosch` or reactivate it intentionally.
9. Review legacy plugins with external integration evidence.
10. Run cross-journey regression on homepage, overview, detail, planner, cart, checkout, account, portal, and tour.

## Next Execution Pass

Recommended next pass:

1. execute the direct safe-delete set
2. produce an enqueue/reference map for all frontend CSS and JS
3. refactor `mu-plugins` into bootstrap vs feature ownership
4. isolate and replace `ddb-ui.css`
