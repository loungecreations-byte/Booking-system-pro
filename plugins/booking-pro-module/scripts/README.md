# scripts/

This folder contains helper scripts for local developer workflows.

## codex-integration.ps1

Purpose:
- Probe for a local `codex` CLI installation and print its version.
- Run the repository's `run-quality-checks.ps1` script.
- Optionally run PHPUnit if installed under `vendor/bin/phpunit`.

Usage (PowerShell / pwsh):

```powershell
pwsh -File .\scripts\codex-integration.ps1 -RunQuality -RunTests
```

Flags:
- `-RunQuality` : Run `scripts/run-quality-checks.ps1` if present.
- `-RunTests`   : Run PHPUnit if `vendor/bin/phpunit` is present.

Notes:
- This script is lightweight and non-destructive. It only runs existing scripts and tools.
- If `composer` or `vendor` dependencies are missing, run `composer install` first.
