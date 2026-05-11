param(
    [string]$AuditFile = 'ops/codex-output/weekend_audit.txt',
    [string]$OutputFile = 'ops/codex-output/weekend_fixes.txt'
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path $AuditFile)) {
    throw "Audit file not found: $AuditFile"
}

try {
    $content = Get-Content $AuditFile -Raw | ConvertFrom-Json
} catch {
    throw "Audit file is not valid JSON: $AuditFile"
}

$issues = @()
if ($content -and $content.issues) {
    $issues = @($content.issues)
}

$recommendations = @()

foreach ($issue in $issues) {
    $type = $issue.type
    $filePath = $issue.file
    $line = $issue.line
    $message = $issue.message

    switch ($type) {
        'superglobal' {
            if ($message -like '*$_POST*') {
                $recommendations += "- ${filePath}:${line} => Wrap \'$_POST\' access with filter_input/filter_input_array before usage (see ResourceMeta::save() for a reference implementation)."
            } elseif ($message -like '*$_GET*') {
                $recommendations += "- ${filePath}:${line} => Use filter_input(INPUT_GET, ...) or sanitize_text_field() before reading query variables."
            } else {
                $recommendations += "- ${filePath}:${line} => Sanitize superglobal usage (${message})."
            }
        }
        'hook' {
            if ($message -like '*permission_callback*') {
                $recommendations += "- ${filePath}:${line} => Add a permission_callback when registering REST routes to satisfy audit requirements."
            } else {
                $recommendations += "- ${filePath}:${line} => Ensure callback exists or is autoloadable (${message})."
            }
        }
        'rest' {
            $recommendations += "- ${filePath}:${line} => Provide a permission_callback or capability check for REST endpoint (${message})."
        }
        Default {
            $recommendations += "- ${filePath}:${line} => ${message}"
        }
    }
}

if (-not $recommendations) {
    $recommendations = @('Audit returned no actionable issues. No fixes required.')
}

$header = "# Audit Auto-Fix Suggestions`n`nGenerated: $([DateTime]::UtcNow.ToString('u'))`n`n"
$body = ($recommendations -join "`n") + "`n"
$header + $body | Out-File $OutputFile -Encoding UTF8
