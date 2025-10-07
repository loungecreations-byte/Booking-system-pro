param(
    [string] = '.',
    [switch]
)

Continue = 'Stop'

Write-Host "Running PHP syntax check..." -ForegroundColor Cyan
 = Get-ChildItem -Path  -Recurse -Filter '*.php' -File |
    Where-Object { .FullName -notmatch '\\vendor\\' -and .FullName -notmatch '\\dist\\' }

foreach ( in ) {
     = php -l .FullName 2>&1
    if ( -ne 0) {
        throw "Syntax error in : "
    }
}

Write-Host "Syntax check passed for 0 files." -ForegroundColor Green

if () {
    Write-Host "Skipping PHPCS because -NoPhpcs was provided." -ForegroundColor Yellow
    exit 0
}

 = 
   = Join-Path  'vendor/bin/phpcs'
 = Join-Path  'vendor/bin/phpcs.bat'

if (Test-Path ) {
     = 
} elseif (Test-Path ) {
     = 
} elseif (Get-Command composer -ErrorAction SilentlyContinue) {
     = 'composer phpcs'
}

if (-not ) {
    Write-Warning 'PHP_CodeSniffer not found. Install via Composer or make it available in PATH.'
    exit 0
}

Write-Host "Running ..." -ForegroundColor Cyan
if ( -like 'composer*') {
    iex 
} else {
    & 
}

if ( -ne 0) {
    throw "PHPCS reported issues."
}

Write-Host "PHPCS completed without errors." -ForegroundColor Green