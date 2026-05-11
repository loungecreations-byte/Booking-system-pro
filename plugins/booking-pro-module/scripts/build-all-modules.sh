#!/bin/bash
# ==========================================================
#  Booking Pro Suite - Auto Module Builder
# ==========================================================
#  Generates missing modules through the Codex prompt system.
# ==========================================================

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PROMPTS="$ROOT/ops/codex-prompts"
OUTPUT="$ROOT/ops/codex-output"
CODEX_CLI="${CODEX_CLI:-${CODEx_CLI:-codex}}"

mkdir -p "$OUTPUT"

PROMPT_TEMPLATE="$PROMPTS/03_module_generator.sbdp.txt"
ERROR_LOG="$OUTPUT/errors.log"
: > "$ERROR_LOG"

MODULES=("Planner" "Sales" "Finance" "Data" "Support" "Notifications" "Commerce" "Intelligence")

for MODULE in "${MODULES[@]}"; do
  SLUG=$(echo "$MODULE" | tr '[:upper:]' '[:lower:]')
  OUT_FILE="$OUTPUT/module_${SLUG}_$(date +%H%M%S).txt"

  if [ ! -f "$PROMPT_TEMPLATE" ]; then
    echo "Prompt template $PROMPT_TEMPLATE is missing." | tee -a "$ERROR_LOG"
    break
  fi

  PROMPT_CONTENT=$(sed -e "s/{{MODULE_NAME}}/$MODULE/g" -e "s/{{SLUG}}/$SLUG/g" "$PROMPT_TEMPLATE")

  printf 'Generating module: %s\n' "$MODULE"

  if ! printf '%s' "$PROMPT_CONTENT" | "$CODEX_CLI" exec --full-auto --skip-git-repo-check - > "$OUT_FILE" 2>&1; then
    MSG="ERROR Module $MODULE failed"
    echo "$MSG" | tee -a "$ERROR_LOG"
  fi
done

echo "Module generation complete. Output stored in $OUTPUT/"
