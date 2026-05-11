param(
    [string]$SitePath = "C:\Users\Gebruiker\Local Sites\dagjedenbosch\app\public",
    [switch]$SkipE2E
)

$ErrorActionPreference = 'Stop'
$pluginRoot = Split-Path -Parent $PSScriptRoot

function Assert-LastExitCode([string]$StepName) {
    if ($LASTEXITCODE -ne 0) {
        throw "$StepName failed with exit code $LASTEXITCODE"
    }
}

Write-Host "== Core Journey Regressions ==" -ForegroundColor Cyan
Write-Host "Site path: $SitePath" -ForegroundColor DarkGray
Write-Host "Plugin path: $pluginRoot" -ForegroundColor DarkGray

Push-Location $pluginRoot
try {
    Write-Host "[1/4] PHP lint" -ForegroundColor Yellow
    php -l "modules\quotes\Service\QuoteRequestOrderBridgeService.php" | Out-Host
    Assert-LastExitCode "Lint QuoteRequestOrderBridgeService"
    php -l "modules\quotes\Rest\Controller.php" | Out-Host
    Assert-LastExitCode "Lint Quotes Rest Controller"
    php -l "modules\commerce\Rest\Controller.php" | Out-Host
    Assert-LastExitCode "Lint Commerce Rest Controller"

    Write-Host "[2/4] Quote handoff contract tests" -ForegroundColor Yellow
    if (Get-Command composer -ErrorAction SilentlyContinue) {
        composer test -- --filter "QuoteRequestOrderBridgeServiceTest|QuoteModuleTest" | Out-Host
        Assert-LastExitCode "Composer contract tests"
    }
    elseif (Test-Path "vendor\bin\phpunit") {
        php "vendor\bin\phpunit" --configuration "tests\phpunit.xml.dist" --filter "QuoteRequestOrderBridgeServiceTest|QuoteModuleTest" | Out-Host
        Assert-LastExitCode "PHPUnit contract tests"
    }
    else {
        throw "No composer or vendor/bin/phpunit found for running contract tests."
    }

    Write-Host "[3/4] WP runtime healthcheck" -ForegroundColor Yellow
    wp --path="$SitePath" eval-file "$pluginRoot\scripts\bsp-healthcheck.php" | Out-Host
    Assert-LastExitCode "WP runtime healthcheck"

    if (-not $SkipE2E) {
        Write-Host "[4/4] E2E journey specs (optional)" -ForegroundColor Yellow
        if (Test-Path "$pluginRoot\node_modules\.bin\playwright.cmd") {
            & "$pluginRoot\node_modules\.bin\playwright.cmd" test "tests/e2e/planner-journey.spec.ts" "tests/e2e/planner-combi.spec.ts" | Out-Host
            Assert-LastExitCode "Playwright E2E journey specs"
        } else {
            Write-Host "Playwright not found under node_modules; skipping E2E." -ForegroundColor DarkYellow
        }
    } else {
        Write-Host "[4/4] E2E skipped by flag" -ForegroundColor DarkYellow
    }

    Write-Host "All selected regression checks completed." -ForegroundColor Green
}
finally {
    Pop-Location
}
