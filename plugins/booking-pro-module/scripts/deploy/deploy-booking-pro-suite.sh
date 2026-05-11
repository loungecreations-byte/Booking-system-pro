#!/bin/bash
set -euo pipefail

if [ "$#" -ne 2 ]; then
  echo "Usage: $0 <ZIP_PATH> <WP_ROOT>" >&2
  exit 1
fi

ZIP_PATH="$1"
WP_ROOT="$2"

if [ ! -f "$ZIP_PATH" ]; then
  echo "ZIP not found: $ZIP_PATH" >&2
  exit 1
fi

cd "$WP_ROOT"
wp plugin deactivate booking-pro-suite --quiet || true
wp plugin install "$ZIP_PATH" --force --activate
wp plugin status booking-pro-suite
