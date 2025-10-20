param(
    [string]$Composer = "php composer.phar"
)

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$root = Join-Path $root '..'
$root = (Resolve-Path $root).Path
$dist = Join-Path $root 'dist'
$zipName = 'booking-pro-suite.zip'

Write-Host "Running composer install (no-dev)..."
& $Composer install --no-dev --working-dir="$root"

Write-Host "Optimizing autoloader..."
& $Composer dump-autoload --working-dir="$root" --optimize

Write-Host "Linting PHP files..."
Get-ChildItem -Path $root -Recurse -Include '*.php' -File |
    Where-Object { $_.FullName -notmatch '\\vendor\\' } |
    ForEach-Object {
        & php -l $_.FullName | Out-Null
    }

Write-Host "Packaging plugin..."
New-Item -ItemType Directory -Path $dist -Force | Out-Null
$zipPath = Join-Path $dist $zipName
if (Test-Path $zipPath) {
    Remove-Item $zipPath -Force
}

Add-Type -AssemblyName 'System.IO.Compression.FileSystem'
[System.IO.Compression.ZipFile]::CreateFromDirectory($root, $zipPath)

Write-Host "Build package written to $zipPath"