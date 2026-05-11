#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

if ! command -v wp >/dev/null 2>&1; then
  echo "wp CLI is required for this script." >&2
  exit 1
fi

echo "[1/6] Deactivating plugin (if active)..."
wp plugin deactivate booking-pro-module --quiet || true

echo "[2/6] Activating plugin..."
wp plugin activate booking-pro-module --quiet

echo "[3/6] Seeding demo data..."
wp eval "do_action('sbdp_seed_demo_data');"

echo "[4/6] Rebuilding yield cache..."
wp bsp-sales yield run

echo "[5/6] Syncing all sales channels..."
wp bsp-sales channels sync --channel=all

echo "[6/6] Generating weekly analytics report..."
wp bsp-sales analytics report --range=week

echo "Running quick syntax sweep via PowerShell script..."
if command -v pwsh >/dev/null 2>&1; then
  pwsh -File "$ROOT_DIR/scripts/run-quality-checks.ps1" -NoPhpcs
else
  echo "pwsh not available, skipping PowerShell syntax sweep." >&2
fi

echo "Staging checks completed."