param(
    [string] $RemoteHost = "212.227.224.239",
    [string] $RemoteUser = "dagjedb123",
    [string] $RemoteWpRoot = "/var/www/vhosts/dagjedenbosch.nl/site1",
    [string] $LocalUploadsPath = "app/public/wp-content/uploads",
    [string[]] $IncludePaths = @("2026"),
    [switch] $Apply,
    [switch] $RunMediaAudit
)

$ErrorActionPreference = "Stop"

function Format-Bytes {
    param([double] $Bytes)

    if ($Bytes -ge 1GB) {
        return "{0:N2} GB" -f ($Bytes / 1GB)
    }
    if ($Bytes -ge 1MB) {
        return "{0:N2} MB" -f ($Bytes / 1MB)
    }
    if ($Bytes -ge 1KB) {
        return "{0:N2} KB" -f ($Bytes / 1KB)
    }
    return "{0:N0} B" -f $Bytes
}

function Invoke-CheckedNative {
    param(
        [string] $FilePath,
        [string[]] $Arguments
    )

    & $FilePath @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "$FilePath failed with exit code $LASTEXITCODE"
    }
}

$uploadsRoot = Resolve-Path -LiteralPath $LocalUploadsPath
$remote = "$RemoteUser@$RemoteHost"
$remoteUploads = "$RemoteWpRoot/wp-content/uploads"

$existingIncludes = @()
foreach ($include in $IncludePaths) {
    $candidate = Join-Path $uploadsRoot.Path $include
    if (Test-Path -LiteralPath $candidate) {
        $existingIncludes += $include
    } else {
        Write-Warning "Skipping missing local uploads path: $include"
    }
}

if ($existingIncludes.Count -eq 0) {
    throw "No existing local upload paths matched IncludePaths."
}

$files = foreach ($include in $existingIncludes) {
    Get-ChildItem -LiteralPath (Join-Path $uploadsRoot.Path $include) -Recurse -File
}
$totalBytes = ($files | Measure-Object -Property Length -Sum).Sum
if ($null -eq $totalBytes) {
    $totalBytes = 0
}

Write-Host "Local uploads root: $($uploadsRoot.Path)"
Write-Host "Remote uploads root: ${remote}:$remoteUploads"
Write-Host "Include paths: $($existingIncludes -join ', ')"
Write-Host "Files to send: $($files.Count)"
Write-Host "Approx size: $(Format-Bytes $totalBytes)"

$remoteCountCommand = "mkdir -p '$remoteUploads' && find '$remoteUploads' -type f | wc -l"
Write-Host "Remote file count before:"
Invoke-CheckedNative -FilePath "ssh" -Arguments @($remote, $remoteCountCommand)

if (-not $Apply) {
    Write-Host ""
    Write-Host "Dry-run only. Re-run with -Apply to upload these media files."
    if ($RunMediaAudit) {
        Write-Host ""
        Write-Host "Running DBSpots media audit:"
        Invoke-CheckedNative -FilePath "ssh" -Arguments @(
            $remote,
            "cd '$RemoteWpRoot' && php -d memory_limit=512M `$(command -v wp) ddb-spots media audit"
        )
    }
    exit 0
}

Write-Host ""
Write-Host "Uploading media. This is non-destructive: no remote files are deleted."
$archiveName = "ddb-uploads-sync-{0}.tar.gz" -f (Get-Date -Format "yyyyMMddHHmmss")
$localArchive = Join-Path ([System.IO.Path]::GetTempPath()) $archiveName
$remoteArchive = "/tmp/$archiveName"

try {
    Invoke-CheckedNative -FilePath "tar" -Arguments (@("-czf", $localArchive, "-C", $uploadsRoot.Path) + $existingIncludes)
    Invoke-CheckedNative -FilePath "scp" -Arguments @($localArchive, "${remote}:$remoteArchive")
    Invoke-CheckedNative -FilePath "ssh" -Arguments @(
        $remote,
        "mkdir -p '$remoteUploads' && tar -xzf '$remoteArchive' -C '$remoteUploads' && rm -f '$remoteArchive'"
    )
} finally {
    if (Test-Path -LiteralPath $localArchive) {
        Remove-Item -LiteralPath $localArchive -Force
    }
}

Write-Host ""
Write-Host "Remote file count after:"
Invoke-CheckedNative -FilePath "ssh" -Arguments @($remote, "find '$remoteUploads' -type f | wc -l")

if ($RunMediaAudit) {
    Write-Host ""
    Write-Host "Running DBSpots media audit:"
    Invoke-CheckedNative -FilePath "ssh" -Arguments @(
        $remote,
        "cd '$RemoteWpRoot' && php -d memory_limit=512M `$(command -v wp) ddb-spots media audit"
    )
}
