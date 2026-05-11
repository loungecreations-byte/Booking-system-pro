param()

$ErrorActionPreference = 'Stop'

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$pluginRoot = Resolve-Path (Join-Path $scriptRoot '..')
$marker = '!' + 'important'

$targets = @(
    (Join-Path $pluginRoot 'assets/css/ddb-ui.css'),
    (Join-Path $pluginRoot 'assets/js/theme.js'),
    (Join-Path $pluginRoot '..\..\mu-plugins\ddb-core-design-system.php'),
    (Join-Path $pluginRoot '..\..\mu-plugins\ddb-core\modules\admin-optimizer.php')
)

$hits = @()
foreach ($file in $targets) {
    if (-not (Test-Path $file)) {
        continue
    }

    $matches = Select-String -Path $file -Pattern $marker -SimpleMatch
    if ($matches) {
        $hits += $matches
    }
}

if ($hits.Count -gt 0) {
    Write-Host 'Found forbidden important-marker usage:' -ForegroundColor Red
    foreach ($hit in $hits) {
        Write-Host "$($hit.Path):$($hit.LineNumber): $($hit.Line.Trim())"
    }
    exit 1
}

Write-Host 'No important-marker found in managed design-system files.' -ForegroundColor Green
