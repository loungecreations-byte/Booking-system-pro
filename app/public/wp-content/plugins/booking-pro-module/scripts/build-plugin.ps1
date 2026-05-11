param(
    [string]$Composer = "php composer.phar"
)

$ErrorActionPreference = 'Stop'

function Invoke-ComposerCommand {
    param(
        [string[]]$Arguments
    )

    $nullRef = $null
    $tokens = [System.Management.Automation.PSParser]::Tokenize($Composer, [ref]$nullRef) |
        Where-Object { $_.Type -in 'Command', 'CommandArgument' }

    if (-not $tokens) {
        throw "Unable to parse composer command: $Composer"
    }

    $parts = @()
    foreach ($token in $tokens) {
        $parts += $token.Content
    }

    $command = $parts[0]
    $baseArgs = @()
    if ($parts.Count -gt 1) {
        $baseArgs = $parts[1..($parts.Count - 1)]
    }

    & $command @baseArgs @Arguments
}

$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$root = Join-Path $root '..'
$root = (Resolve-Path $root).Path
$dist = Join-Path $root 'dist'
$zipName = 'booking-pro-suite.zip'
$packageRoot = Join-Path $root '.build-package'
$packageContent = Join-Path $packageRoot 'booking-pro-suite'

Write-Host "Running composer install (no-dev)..."
Invoke-ComposerCommand @('install', '--no-dev', "--working-dir=$root")

Write-Host "Optimizing autoloader..."
Invoke-ComposerCommand @('dump-autoload', "--working-dir=$root", '--optimize')

Write-Host "Linting PHP files..."
Get-ChildItem -Path $root -Recurse -Include '*.php' -File |
    Where-Object {
        $_.FullName -notmatch '\\vendor\\' -and
        $_.FullName -notmatch '\\dist\\' -and
        $_.FullName -notmatch '\\node_modules\\' -and
        $_.FullName -notmatch '\\\\.build-package\\'
    } |
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

if (Test-Path $packageRoot) {
    Remove-Item $packageRoot -Recurse -Force
}
New-Item -ItemType Directory -Path $packageContent -Force | Out-Null

$includeDirs = @(
    'assets',
    'booking-core',
    'build',
    'configs',
    'core',
    'includes',
    'js',
    'languages',
    'modules',
    'mu-plugins',
    'templates',
    'views',
    'vendor'
)

foreach ($dir in $includeDirs) {
    $source = Join-Path $root $dir
    if (Test-Path $source) {
        Copy-Item -Path $source -Destination (Join-Path $packageContent $dir) -Recurse -Force
    }
}

$includeFiles = @(
    'booking-pro-module.php',
    'booking-pro-suite.php',
    'composer.json',
    'composer.lock',
    'README.md'
)

foreach ($file in $includeFiles) {
    $source = Join-Path $root $file
    if (Test-Path $source) {
        Copy-Item -Path $source -Destination (Join-Path $packageContent $file) -Force
    }
}

[System.IO.Compression.ZipFile]::CreateFromDirectory($packageContent, $zipPath)
Remove-Item $packageRoot -Recurse -Force

Write-Host "Build package written to $zipPath"
