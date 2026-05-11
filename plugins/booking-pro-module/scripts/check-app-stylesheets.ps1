param(
    [string]$BaseUrl = 'http://dagjedenboschnl.local',
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

    $ddbLinks = @($links | Where-Object { $_ -match 'ddb-ui\.css' })
    if ($ddbLinks.Count -lt 1) {
        $failures += "$url missing required ddb-ui.css"
        continue
    }

    if ($Strict -and $links.Count -ne 1) {
        $failures += "$url strict mode expected exactly 1 stylesheet, got $($links.Count)"
        continue
    }

    if ($Strict -and $links[0] -notmatch 'ddb-ui\.css') {
        $failures += "$url strict mode expected ddb-ui.css only, got $($links[0])"
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
    Write-Host 'App stylesheet check passed (strict: exactly one ddb-ui.css per app route).' -ForegroundColor Green
} else {
    Write-Host 'App stylesheet check passed (migration mode: ddb-ui.css present on every app route).' -ForegroundColor Green
}
