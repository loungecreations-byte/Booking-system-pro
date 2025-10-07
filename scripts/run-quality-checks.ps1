param(
    [string]$Root = '.',
    [switch]$NoPhpcs
)

$ErrorActionPreference = 'Stop'

Write-Host "Running PHP syntax check..." -ForegroundColor Cyan
$phpFiles = Get-ChildItem -Path $Root -Recurse -Filter '*.php' -File |
    Where-Object { $_.FullName -notmatch '\\vendor\\' -and $_.FullName -notmatch '\\dist\\' }

foreach ($file in $phpFiles) {
    $result = php -l $file.FullName 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Syntax error in $($file.FullName): $result"
    }
}

Write-Host "Syntax check passed for $($phpFiles.Count) files." -ForegroundColor Green

if ($NoPhpcs) {
    Write-Host "Skipping PHPCS because -NoPhpcs was provided." -ForegroundColor Yellow
    exit 0
}

$phpcsCommand   = $null
$vendorPhpcs    = Join-Path $Root 'vendor/bin/phpcs'
$vendorPhpcsBat = Join-Path $Root 'vendor/bin/phpcs.bat'

if (Test-Path $vendorPhpcs) {
    $phpcsCommand = $vendorPhpcs
} elseif (Test-Path $vendorPhpcsBat) {
    $phpcsCommand = $vendorPhpcsBat
} elseif (Get-Command composer -ErrorAction SilentlyContinue) {
    $phpcsCommand = 'composer phpcs'
}

if (-not $phpcsCommand) {
    Write-Warning 'PHP_CodeSniffer not found. Install via Composer or make it available in PATH.'
    exit 0
}

Write-Host "Running $phpcsCommand..." -ForegroundColor Cyan
if ($phpcsCommand -like 'composer*') {
    iex $phpcsCommand
} else {
    & $phpcsCommand
}

if ($LASTEXITCODE -ne 0) {
    throw "PHPCS reported issues."
}

Write-Host "PHPCS completed without errors." -ForegroundColor Green