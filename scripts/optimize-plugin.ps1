param(
    [string]$ComposerExecutable = ''php'',
    [string]$ComposerScript = ''composer.phar''
)

$ErrorActionPreference = 'Stop'

$root = Join-Path (Split-Path -Parent $PSCommandPath) '..'
$root = (Resolve-Path $root).Path

$composerPhar = Join-Path $root $ComposerScript
$hasComposerPhar = Test-Path $composerPhar

if ($hasComposerPhar) {
    $composerCmd = { param([string[]]$Args) & $ComposerExecutable $composerPhar @Args }
} else {
    $composerCmd = { param([string[]]$Args) & composer @Args }
}

& $composerCmd @('dump-autoload','-o')

if (-not $env:SKIP_VERSION_BUMP) {
    $env:SKIP_VERSION_BUMP = '1'
}
$env:SKIP_GIT = '1'

$buildScript = Join-Path $root 'scripts/build-plugin.ps1'
if (Test-Path $buildScript) {
    $composerParam = if ($hasComposerPhar) { "$ComposerExecutable $ComposerScript" } else { 'composer' }
    & pwsh -NoLogo -NoProfile -File $buildScript -Composer $composerParam
} else {
    throw "Missing build script: $buildScript"
}

& $composerCmd @('install','--no-interaction')
