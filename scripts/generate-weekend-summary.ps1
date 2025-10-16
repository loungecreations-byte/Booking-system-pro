param(
    [string]$SummaryPath = 'ops/codex-output/weekend_summary.md',
    [string]$AuditPath = 'ops/codex-output/weekend_audit.txt',
    [string]$LogPath = 'ops/codex-output/weekend.log',
    [string]$DistDirectory = 'dist'
)

$ErrorActionPreference = 'Stop'

$modules = Get-ChildItem modules -Directory | Select-Object -ExpandProperty Name | Sort-Object
$modulesList = if ($modules) { ($modules -join ', ') } else { 'None detected' }

$auditStatus = 'Unavailable'
$auditIssues = @()
if (Test-Path $AuditPath) {
    try {
        $audit = Get-Content $AuditPath -Raw | ConvertFrom-Json
        $auditStatus = $audit.status
        if ($audit.issues) {
            $auditIssues = @($audit.issues)
        }
    } catch {
        $auditStatus = 'Parse error'
    }
}

$issuesSummary = if ($auditIssues.Count -gt 0) {
    ($auditIssues | Select-Object -First 5 | ForEach-Object { "  - {0}:{1} – {2}" -f $_.file, $_.line, $_.message }) -join "`n"
} else {
    '  - None'
}

$artifact = Get-ChildItem $DistDirectory -Filter 'booking-pro-module-*.zip' -ErrorAction SilentlyContinue | Sort-Object LastWriteTime -Descending | Select-Object -First 1
$artifactInfo = if ($artifact) {
    $sizeMb = [math]::Round($artifact.Length / 1MB, 2)
    "{0} ({1} bytes / {2} MB)" -f $artifact.Name, $artifact.Length, $sizeMb
} else {
    'No booking-pro-module zip found'
}

$commandsUsed = @()
if (Test-Path $LogPath) {
    $commandsUsed = Select-String -Path $LogPath -Pattern '^\[(.*?)\]' | ForEach-Object { $_.Line }
}
$commandsSection = if ($commandsUsed) {
    ($commandsUsed | Select-Object -Last 10 | ForEach-Object { "  - $_" }) -join "`n"
} else {
    '  - Not available'
}

$now = (Get-Date).ToString('u')
$markdown = @"
## Step 1 – Module Verification
- Modules detected: $modulesList

## Step 2 – Audit Snapshot
- Status: $auditStatus
- Top issues:
$issuesSummary

## Step 3 – Build Artifacts
- Latest zip: $artifactInfo

## Step 4 – Commands Logged
$commandsSection

## Generated
- Timestamp: $now
"@

$markdown | Out-File $SummaryPath -Encoding UTF8
