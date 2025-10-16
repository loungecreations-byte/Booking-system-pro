param(
    [string]$OutputPath = 'ops/codex-output/weekend_pr.md'
)

$ErrorActionPreference = 'Stop'

function Invoke-Git([string[]]$Args) {
    & git @Args
}

$latestCommit = Invoke-Git @('log','-1','--pretty=format:%h %s')
$changes = Invoke-Git @('status','-sb')
$zip = Get-ChildItem dist -Filter 'booking-pro-module-*.zip' -ErrorAction SilentlyContinue | Sort-Object LastWriteTime -Descending | Select-Object -First 1
if ($zip) {
    $sizeMb = [math]::Round($zip.Length / 1MB, 2)
    $zipInfo = "{0} ({1} bytes / {2} MB)" -f $zip.Name, $zip.Length, $sizeMb
} else {
    $zipInfo = 'No booking-pro-module zip found'
}

$lines = @()
$lines += '## Summary'
$lines += "- Latest commit: $latestCommit"
$lines += "- Zip artifact: $zipInfo"
$lines += ''
$lines += '## Testing'
$lines += '- php audit_booking_module.php --path=modules --json'
$lines += ''
$lines += '## Notes'
$lines += ''
$lines += 'git status:'
$lines += '```'
$lines += $changes
$lines += '```'

$lines -join [Environment]::NewLine | Out-File $OutputPath -Encoding UTF8
