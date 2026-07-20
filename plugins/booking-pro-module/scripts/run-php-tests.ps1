[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$pluginRoot = Split-Path -Parent $PSScriptRoot
$phpunit = Join-Path $pluginRoot 'vendor/bin/phpunit'
$configuration = Join-Path $pluginRoot 'tests/phpunit.xml.dist'

if (-not (Test-Path -LiteralPath $phpunit)) {
    throw "PHPUnit executable not found at $phpunit"
}

& php $phpunit --configuration $configuration
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

$bookingTests = Get-ChildItem -LiteralPath (Join-Path $pluginRoot 'tests/booking') -Filter '*Test.php' -File | Sort-Object Name
foreach ($testFile in $bookingTests) {
    Write-Output ("Running isolated booking truth suite: {0}" -f $testFile.Name)
    & php $phpunit --configuration $configuration $testFile.FullName
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
}
