#!/bin/bash
set -e
cd "$(dirname "$0")/.."

echo "🌙 Nightly run started at $(date)"
./scripts/build-all-modules.sh
./scripts/run-full-codex-cycle.sh
./scripts/optimize-plugin.sh
echo "✅ Nightly build completed at $(date)"
