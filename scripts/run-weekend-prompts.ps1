param(
    [string]$PromptsPath = 'ops/codex-prompts',
    [string]$OutputPath = 'ops/codex-output/weekend.log'
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path $PromptsPath)) {
    throw "Prompt directory not found: $PromptsPath"
}

for ($i = 0; $i -le 11; $i++) {
    $pattern = '{0:00}_{0:00}_*.sbdp.txt' -f $i
    $matches = Get-ChildItem -Path $PromptsPath -Filter $pattern -ErrorAction SilentlyContinue
    if (-not $matches) {
        $entry = "[{0}] Prompt {1:00}: missing" -f (Get-Date -Format o), $i
        $entry | Out-File $OutputPath -Encoding UTF8 -Append
        continue
    }

    foreach ($match in $matches) {
        $header = "[{0}] Prompt {1:00} ({2})" -f (Get-Date -Format o), $i, $match.Name
        $header | Out-File $OutputPath -Encoding UTF8 -Append
        Get-Content $match.FullName | Out-File $OutputPath -Encoding UTF8 -Append
        "[{0}] Prompt {1:00} complete" -f (Get-Date -Format o), $i | Out-File $OutputPath -Encoding UTF8 -Append
    }
}
