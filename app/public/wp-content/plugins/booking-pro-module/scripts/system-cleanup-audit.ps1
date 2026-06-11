param(
    [string]$BaseUrl = 'http://dagjedenbosch.local'
)

$ErrorActionPreference = 'Stop'

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$pluginRoot = Resolve-Path (Join-Path $scriptRoot '..')
$wpContentRoot = Resolve-Path (Join-Path $pluginRoot '..\..')
$projectRoot = Resolve-Path (Join-Path $pluginRoot '..\..\..\..\..')

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

$artifactPatterns = @('*.log', '*.tmp', '*.bak', '*.backup', '*.old', '*.orig')
$artifactHits = @()
foreach ($pattern in $artifactPatterns) {
    $artifactHits += Get-ChildItem -Path $projectRoot -Recurse -File -Filter $pattern -ErrorAction SilentlyContinue |
        Where-Object { $_.FullName -notmatch '\\node_modules\\|\\vendor\\|\\uploads\\|\\cache\\' }
}

$cssIssues = @()
$stylesheetSummary = @()
$patSingle = "<link[^>]+rel='stylesheet'[^>]*href='([^']+)'"
$patDouble = '<link[^>]+rel="stylesheet"[^>]*href="([^"]+)"'

foreach ($route in $routes) {
    $url = "$BaseUrl/$route/"
    try {
        $resp = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 30
    } catch {
        $cssIssues += "Unable to fetch route: $url"
        continue
    }

    $links = @()
    foreach ($m in [regex]::Matches($resp.Content, $patSingle, [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)) {
        $links += $m.Groups[1].Value
    }
    foreach ($m in [regex]::Matches($resp.Content, $patDouble, [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)) {
        $links += $m.Groups[1].Value
    }

    $ddbLinks = @($links | Where-Object { $_ -match 'booking-pro-module|ddb-core-ui|ddb-mega-menu|ddb-spots' })
    $stylesheetSummary += [pscustomobject]@{
        Route = $route
        DdbStylesheets = $ddbLinks.Count
        CoreDesignSystem = @($links | Where-Object { $_ -match 'ddb-core-ui/assets/css/design-system\.css' }).Count
        PluginDesignFallback = @($links | Where-Object { $_ -match 'booking-pro-module/assets/css/design-system\.css' }).Count
        LegacyDdbUi = @($links | Where-Object { $_ -match 'booking-pro-module/assets/css/ddb-ui\.css' }).Count
    }

    if (@($links | Where-Object { $_ -match 'booking-pro-module/assets/css/design-system\.css' }).Count -gt 0) {
        $cssIssues += "$url loads plugin fallback design-system.css while core-ui should own public design truth"
    }
    if (@($links | Where-Object { $_ -match 'booking-pro-module/assets/css/ddb-ui\.css' }).Count -gt 0) {
        $cssIssues += "$url loads legacy ddb-ui.css"
    }
}

$forbiddenPatterns = @(
    'directBookable\s*[:=]\s*true',
    'booking-widget',
    'participants\s*\|\|',
    'form\.participants\s*\|\|',
    'item\.participants\s*\|\|',
    'available\s*:\s*true'
)

$riskHits = @()
foreach ($pattern in $forbiddenPatterns) {
    $riskHits += Get-ChildItem -Path $pluginRoot -Recurse -File -ErrorAction SilentlyContinue |
        Where-Object { $_.FullName -notmatch '\\vendor\\|\\node_modules\\|\\build\\|\\ops\\codex-output\\|\\scripts\\system-cleanup-audit\.ps1$' } |
        Select-String -Pattern $pattern -ErrorAction SilentlyContinue |
        Where-Object { $_.Path -notmatch '\\vendor\\|\\node_modules\\|\\build\\|\\ops\\codex-output\\|\\scripts\\system-cleanup-audit\.ps1$' }
}

Write-Host '== Cleanup artifact candidates =='
if ($artifactHits.Count -eq 0) {
    Write-Host 'No local log/tmp/backup artifacts found outside ignored dependency/runtime folders.'
} else {
    $artifactHits | Select-Object FullName, Length, LastWriteTime | Format-Table -AutoSize
}

Write-Host ''
Write-Host '== Public stylesheet summary =='
$stylesheetSummary | Format-Table -AutoSize

Write-Host ''
Write-Host '== Design runtime issues =='
if ($cssIssues.Count -eq 0) {
    Write-Host 'No public route design-runtime drift found.'
} else {
    $cssIssues | ForEach-Object { Write-Host "- $_" }
}

Write-Host ''
Write-Host '== Governance risk pattern hits =='
if ($riskHits.Count -eq 0) {
    Write-Host 'No configured risk pattern hits found.'
} else {
    $riskHits | Select-Object Path, LineNumber, Line | Format-Table -AutoSize
}

if ($cssIssues.Count -gt 0) {
    exit 1
}
