param(
    [string]$OutputPath = 'ops/codex-output/weekend_pr.md'
)

$ErrorActionPreference = 'Stop'

function Invoke-Git {
    param(
        [Parameter(ValueFromRemainingArguments = $true)]
        [string[]]$Arguments
    )

    & git @Arguments
}

$commitHash = [string](Invoke-Git log -1 '--pretty=%h')
$commitSubject = [string](Invoke-Git log -1 '--pretty=%s')
$latestCommit = "{0} {1}" -f $commitHash, $commitSubject
$changesRaw = Invoke-Git status -sb
if ($changesRaw -is [System.Array]) {
    $changes = $changesRaw | Select-Object -First 20
} else {
    $changes = $changesRaw
}
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
$lines += 'git status (first 20 lines):'
$lines += '```'
if ($changes -is [System.Array]) {
    $lines += $changes
} else {
    $lines += $changes.ToString()
}
$lines += '```'

$lines -join [Environment]::NewLine | Out-File $OutputPath -Encoding UTF8
