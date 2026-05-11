<#
=====================================================================
  Booking Pro Suite — Weekend Codex Loop (PowerShell Edition)
=====================================================================
  • Runs the weekend_master_run prompt every 3 hours.
  • Logs each run into ops\codex-output\ with timestamp.
  • Keeps Windows awake while the loop is active.
=====================================================================
#>

# ---------------- CONFIG ----------------
$Root   = "C:\booking-pro-module-4.0"    # <-- change if your project lives elsewhere
$Prompt = Join-Path $Root "ops\codex-prompts\weekend_master_run.sbdp.txt"
$OutDir = Join-Path $Root "ops\codex-output"
$LogDir = Join-Path $Root "logs"
$LoopLog = Join-Path $LogDir "weekend-loop.log"

# Ensure directories exist
New-Item -ItemType Directory -Force -Path $OutDir, $LogDir | Out-Null

# Prevent the computer from sleeping (set timeout = 0)
powercfg -change -standby-timeout-ac 0 | Out-Null
powercfg -change -monitor-timeout-ac 0 | Out-Null

Write-Host "🌙 Weekend Codex loop started at $(Get-Date)" | Tee-Object -FilePath $LoopLog -Append
Write-Host "Working directory: $Root" | Tee-Object -FilePath $LoopLog -Append

# ---------------- MAIN LOOP ----------------
while ($true) {
    $Timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    $OutFile = Join-Path $OutDir ("weekend_{0}.log" -f (Get-Date -Format "HHmm"))

    "🕐 $Timestamp  Running Codex weekend cycle..." | Tee-Object -FilePath $LoopLog -Append

    try {
        # ---- Codex CLI command ----
        codex run --input "$Prompt" | Tee-Object -FilePath $OutFile
        "✅ Finished run at $(Get-Date -Format 'HH:mm:ss'). Log: $OutFile" | Tee-Object -FilePath $LoopLog -Append
    }
    catch {
        "❌ Error during Codex run: $($_.Exception.Message)" | Tee-Object -FilePath $LoopLog -Append
    }

    "💤 Sleeping 3 hours..." | Tee-Object -FilePath $LoopLog -Append
    Start-Sleep -Seconds 10800   # 3 hours (10800 sec)
}

# ---------------- CLEANUP ----------------
Register-EngineEvent PowerShell.Exiting -Action {
    Write-Host "💤 Restoring power settings..."
    powercfg -restoredefaultschemes | Out-Null
    Write-Host "✅ Power settings restored."
}
