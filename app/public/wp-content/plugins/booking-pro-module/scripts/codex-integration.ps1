<#
    scripts/codex-integration.ps1

    Small helper to detect the `codex` CLI, run existing quality checks and optionally run PHPUnit.
    Designed for Windows PowerShell (pwsh.exe). Lightweight and non-destructive.
#>

param(
    [switch]$RunQuality,
    [switch]$RunTests
)

function Get-CommandExists {
    param([string]$Name)
    $cmd = Get-Command $Name -ErrorAction SilentlyContinue
    return $null -ne $cmd
}

Write-Host "== Codex integration helper =="

if (Get-CommandExists -Name 'codex') {
    Write-Host "Found codex:" -NoNewline; codex --version
} else {
    Write-Host "Codex CLI not found in PATH." -ForegroundColor Yellow
}

if (Get-CommandExists -Name 'composer') {
    Write-Host "Found composer:" -NoNewline; composer --version
} else {
    Write-Host "Composer not found in PATH." -ForegroundColor Yellow
}

if ($RunQuality) {
    if (Test-Path -Path ./scripts/run-quality-checks.ps1) {
        Write-Host "\nRunning repository quality checks..." -ForegroundColor Cyan
        try {
            pwsh -File ./scripts/run-quality-checks.ps1
        } catch {
            Write-Host "Quality checks script exited with an error. See output above." -ForegroundColor Red
        }
    } else {
        Write-Host "Quality checks script not found at ./scripts/run-quality-checks.ps1" -ForegroundColor Yellow
    }
}

if ($RunTests) {
    $phpunitPath = './vendor/bin/phpunit'
    if (Test-Path -Path $phpunitPath) {
        Write-Host "\nRunning PHPUnit..." -ForegroundColor Cyan
        try {
            & $phpunitPath --configuration tests/phpunit.xml.dist
        } catch {
            Write-Host "PHPUnit execution failed. Ensure vendor dependencies are installed." -ForegroundColor Red
        }
    } else {
        Write-Host "PHPUnit not found at $phpunitPath. Run 'composer install' first if needed." -ForegroundColor Yellow
    }
}

Write-Host "\nDone. Review output above for warnings/errors." -ForegroundColor Green
