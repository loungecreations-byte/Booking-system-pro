param()

$ErrorActionPreference = 'Stop'

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$pluginRoot = Resolve-Path (Join-Path $scriptRoot '..')
$wpContentRoot = Resolve-Path (Join-Path $pluginRoot '..\..')
$coreUiRoot = Join-Path $wpContentRoot 'plugins\ddb-core-ui'

$darkMode = Join-Path $coreUiRoot 'assets\css\dark-mode.css'
$designSystem = Join-Path $coreUiRoot 'assets\css\design-system.css'
$listingCards = Join-Path $coreUiRoot 'assets\css\listing-card-system.css'
$planChrome = Join-Path $pluginRoot 'assets\css\plan-je-dag-chrome.css'
$productPlanner = Join-Path $pluginRoot 'assets\css\sbdp-single-product-planner.css'

$failures = @()

if (Test-Path $darkMode) {
    $content = Get-Content $darkMode -Raw
    if ($content -match '(?m)^\s*html\s*,\s*$') {
        $failures += 'dark-mode.css must not include a bare `html,` selector that forces dark tokens globally.'
    }
    if ($content -match '(?m)^html\.ddb-has-mega-menu') {
        $failures += 'dark-mode.css mega-menu overrides must be scoped to html[data-theme="dark"] or system dark media.'
    }
    if ($content -match '(?m)^body\.woocommerce-account') {
        $failures += 'dark-mode.css Woo/account overrides must be scoped to html[data-theme="dark"] or system dark media.'
    }
}

if (Test-Path $designSystem) {
    $content = Get-Content $designSystem -Raw
    if ($content -match '(?s)html\.ddb-has-mega-menu,\s*html\.ddb-has-mega-menu body\.theme-hello-biz\s*\{[^}]*background:\s*#0') {
        $failures += 'design-system.css mega-menu shell must use theme tokens, not a hard-coded dark background.'
    }
    if ($content -match '(?s)html\.ddb-has-mega-menu body\.theme-hello-biz \.elementor-location-header,\s*html\.ddb-has-mega-menu body\.theme-hello-biz \.elementor-292\s*\{[^}]*background:\s*#0') {
        $failures += 'design-system.css Elementor header/footer shell must use theme tokens, not a hard-coded dark background.'
    }
}

if (Test-Path $listingCards) {
    $content = Get-Content $listingCards -Raw
    if ($content -match 'html\[data-theme="dark"\]\s*,\s*[\r\n]+\s*html\[data-theme="system"\]\s*\{') {
        $failures += 'listing-card-system.css must not treat data-theme="system" as dark outside prefers-color-scheme media.'
    }
}

if (Test-Path $planChrome) {
    $content = Get-Content $planChrome -Raw
    foreach ($pattern in @(
        '(?s)html,\s*body\s*\{[^}]*background:\s*#000',
        '(?s)\.sbdp-day-planner,\s*\.sbdp-day-planner--single-view\s*\{[^}]*background:\s*#000',
        '(?m)^\s*background:\s*#0a0a0a;',
        '(?m)^\s*background:\s*#101010;'
    )) {
        if ($content -match $pattern) {
            $failures += "plan-je-dag-chrome.css contains a hard-coded dark shell background: $pattern"
        }
    }
}

if (Test-Path $productPlanner) {
    $content = Get-Content $productPlanner -Raw
    foreach ($pattern in @(
        'var\(--ui-color-bg,\s*#000',
        'var\(--ui-color-text,\s*#fff',
        'var\(--ui-color-text-muted,\s*rgba\(255,\s*255,\s*255',
        'var\(--ui-color-surface-2,\s*rgba\(255,\s*255,\s*255',
        'var\(--ui-color-border-subtle,\s*rgba\(255,\s*255,\s*255'
    )) {
        if ($content -match $pattern) {
            $failures += "sbdp-single-product-planner.css contains a dark-first fallback: $pattern"
        }
    }
}

if ($failures.Count -gt 0) {
    Write-Host 'Theme mode guard failed:' -ForegroundColor Red
    foreach ($failure in $failures) {
        Write-Host "- $failure"
    }
    exit 1
}

Write-Host 'Theme mode guard passed.' -ForegroundColor Green
