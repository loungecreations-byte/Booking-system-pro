# Codex Nightly Local Run

## Overview
- Automates a full Codex build plus Booking Pro smoke coverage during unattended nightly windows.
- Orchestrated by `scripts\codex-nightly.ps1`; wrapper `codex-nightly.ps1` keeps back-compat for existing shortcuts and `nightly-run.cmd`.
- Designed to run on Windows PowerShell 5.x or later. The script auto-detects `pwsh` when available but falls back to `powershell.exe`.

## Prerequisites
- WP-CLI available on PATH (`wp --info`) with access to the target WordPress install.
- Codex CLI installed locally (`codex --version`).
- Composer dependencies installed (`composer install`) so PHPUnit and PHPCS binaries exist.
- Booking Pro module plugin available at `C:\booking-pro-module-4.0` (override with `-ProjectRoot` if relocated).
- Optional: REST smoke test prerequisites (`scripts\rest-smoke.ps1`, accessible BaseUrl, known QuoteProductId) and a notification hook script.

## What the Script Does
1. Normalises the working directory to the project root and prepares `logs\codex-nightly\YYYYMMDD\codex-nightly-HHmmss.log`.
2. Rotates logs: archives folders older than `-ArchiveAfterDays` into `logs\codex-nightly\archive` and purges data older than `-RetentionDays`.
3. Activates the plugin and reseeds demo data via WP-CLI (unless `-SkipSeed`).
4. Runs `codex exec --full-auto --skip-git-repo-check` to execute the latest Codex plan (unless `-SkipCodex`).
5. Executes `scripts\run-quality-checks.ps1` (adds `-NoPhpcs` passthrough) and `vendor\bin\phpunit.bat` (unless their respective `-Skip*` switches are set).
6. Optionally drives `scripts\rest-smoke.ps1 -BaseUrl ... -QuoteProductId ...` when both REST parameters are provided.
7. Captures stdout and stderr for every step in the log and surfaces the final status through the optional notification hook.

All steps support granular skip switches so you can build bespoke runs, for example `-SkipCodex -SkipPhpUnit` for a fast lint plus REST-only sweep.

## Running Manually
```
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\codex-nightly.ps1 ^
  -RestBaseUrl https://site.local/wp-json ^
  -RestQuoteProductId 321 ^
  -AdditionalNotes 'nightly smoke'
```
Key parameters:
- `-ProjectRoot`: override repository root when not executing from `C:\booking-pro-module-4.0`.
- `-LogRoot`: relocate log storage (default `logs\codex-nightly`).
- `-RetentionDays` / `-ArchiveAfterDays`: adjust rotation policy (archive must remain lower than retention).
- `-NotificationScript`: path to a custom PowerShell or batch script invoked with `status` and `message` arguments when the run finishes or fails.
- `-NoPhpcs`: forward to `run-quality-checks.ps1` to skip PHPCS for faster overnight feedback.

## Scheduling with Task Scheduler
1. Open Task Scheduler and choose **Create Task**.
2. General tab: run with highest privileges under the service account that maintains the WordPress instance.
3. Triggers tab: click **New...**, choose **Daily**, set the overnight start time.
4. Actions tab: click **New...**. Program/script = `powershell.exe`. Arguments = `-NoProfile -ExecutionPolicy Bypass -File "C:\booking-pro-module-4.0\codex-nightly.ps1"` plus any desired parameters.
5. Conditions tab: enable **Wake the computer to run this task** if the workstation sleeps.
6. Settings tab: allow running on demand and stop the task if it runs longer than your SLA (4h recommended).
7. Save the task, then run it once manually to verify permissions and log creation.

## Log Review and Recovery
- Primary log files live under `logs\codex-nightly\YYYYMMDD\`. Archived `.zip` bundles accumulate under `logs\codex-nightly\archive` until manual cleanup.
- Failures surface inline with `[ERROR]` prefixes. Use timestamps to correlate with WP debug logs or system event logs.
- Re-run targeted steps manually by invoking the same script and passing skip switches for already validated stages.
- Notification hooks should exit 0; non-zero exits are logged as warnings but do not fail the run.

## Related Helpers
- `nightly-run.cmd`: double-click wrapper that delegates to `codex-nightly.ps1` for quick manual kicks.
- `scripts\run-staging-checks.sh`: staging pipeline entry point if you need parity with the staging routine.
- `vendor\bin\phpunit.bat --configuration tests\phpunit.xml.dist`: run directly when iterating on unit failures identified by the nightly job.

## Contact and Ownership
- Booking Pro Build Engineering: owns Codex automation and Codex CLI upgrades.
- Partner Integrations QA: point-of-contact for REST smoke catalogue and `QuoteProductId` rotation.
- Submit log excerpts and the RunId when escalating issues to keep traceability across planner, pricing, and channel analytics teams.
