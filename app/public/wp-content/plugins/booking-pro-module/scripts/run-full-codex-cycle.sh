#!/bin/bash
# ==========================================================
#  Booking Pro Suite - Full Codex Prompt Cycle
# ==========================================================
#  Runs all prompts 00 through 11 sequentially using Codex CLI.
# ==========================================================

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PROMPTS="$ROOT/ops/codex-prompts"
OUTPUT="$ROOT/ops/codex-output"
LOGS="$ROOT/logs"
CODEX_CLI="${CODEX_CLI:-${CODEx_CLI:-codex}}"

mkdir -p "$OUTPUT" "$LOGS"

shopt -s nullglob

START_TIME=$(date +%s)
LOG_FILE="$LOGS/codex-cycle.log"
SEPARATOR="----------------------------------------"

# Start fresh log
echo "== Starting Codex full cycle at $(date) ==" | tee "$LOG_FILE"

for n in 00 01 02 03 04 05 06 07 08 09 10 11; do
  matches=("$PROMPTS"/${n}_*.sbdp.txt)
  if [ ${#matches[@]} -eq 0 ]; then
    continue
  fi

  FILE=${matches[0]}
  NAME=$(basename "$FILE")
  OUT_FILE="$OUTPUT/${n}_$(basename "$NAME" .txt)_$(date +%H%M%S).txt"
  PROMPT_CONTENT=$(cat "$FILE")

  printf '%s\n' "$SEPARATOR" | tee -a "$LOG_FILE"
  printf 'Running Codex prompt: %s\n' "$NAME" | tee -a "$LOG_FILE"
  printf '%s\n' "$SEPARATOR" | tee -a "$LOG_FILE"

  if ! printf '%s' "$PROMPT_CONTENT" | "$CODEX_CLI" exec --full-auto --skip-git-repo-check - > "$OUT_FILE" 2>&1; then
    printf 'ERROR in %s\n' "$NAME" | tee -a "$LOG_FILE"
  fi

  printf 'Completed prompt: %s\n' "$NAME" | tee -a "$LOG_FILE"
  sleep 3
done

END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))
printf '== Full Codex cycle finished in %ss ==\n' "$DURATION" | tee -a "$LOG_FILE"
