param(
    [string]$BaseUrl = 'http://dagjedenbosch.local',
    [switch]$Strict
)

$ErrorActionPreference = 'Stop'

$routes = @(
    'plan-je-dag',
    'activiteiten',
    'plattegrond',
    'private-tour/jeroen-bosch-tour',
    'bossche-wiel',
    'my-account',
    'partner-profile',
    'premium-members',
    'cart',
    'checkout'
)

$failures = @()
$patSingle = "<link[^>]+rel='stylesheet'[^>]*href='([^']+)'"
$patDouble = '<link[^>]+rel="stylesheet"[^>]*href="([^"]+)"'

foreach ($route in $routes) {
    $url = "$BaseUrl/$route/"
    try {
        $resp = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 30
    } catch {
        $failures += "Unable to fetch route: $url"
        continue
    }

    $single = [regex]::Matches($resp.Content, $patSingle, [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)
    $double = [regex]::Matches($resp.Content, $patDouble, [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)
    $links = @()

    foreach ($m in $single) {
        $links += $m.Groups[1].Value
    }
    foreach ($m in $double) {
        $links += $m.Groups[1].Value
    }

    $coreDesignSystemLinks = @($links | Where-Object { $_ -match 'ddb-core-ui/assets/css/design-system\.css' })
    if ($coreDesignSystemLinks.Count -lt 1) {
        $failures += "$url missing required ddb-core-ui design-system.css"
        continue
    }

    if ($Strict -and $coreDesignSystemLinks.Count -ne 1) {
        $failures += "$url strict mode expected exactly 1 ddb-core-ui design-system.css, got $($coreDesignSystemLinks.Count)"
        continue
    }

    $legacyDdbUiLinks = @($links | Where-Object { $_ -match 'booking-pro-module/assets/css/ddb-ui\.css' })
    if ($Strict -and $legacyDdbUiLinks.Count -gt 0) {
        $failures += "$url strict mode found legacy ddb-ui.css"
    }

    $pluginFallbackDesignSystemLinks = @($links | Where-Object { $_ -match 'booking-pro-module/assets/css/design-system\.css' })
    if ($Strict -and $pluginFallbackDesignSystemLinks.Count -gt 0) {
        $failures += "$url strict mode found plugin fallback design-system.css while ddb-core-ui is active"
    }
}

if ($failures.Count -gt 0) {
    Write-Host 'App stylesheet check failed:' -ForegroundColor Red
    foreach ($failure in $failures) {
        Write-Host "- $failure"
    }
    exit 1
}

if ($Strict) {
    Write-Host 'App stylesheet check passed (strict: exactly one ddb-core-ui design-system.css per app route; no legacy ddb-ui.css or plugin fallback design-system.css).' -ForegroundColor Green
} else {
    Write-Host 'App stylesheet check passed (migration mode: ddb-core-ui design-system.css present on every app route).' -ForegroundColor Green
}
