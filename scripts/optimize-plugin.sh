#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export PATH="${ROOT}:$PATH"

composer dump-autoload -o

export SKIP_VERSION_BUMP="${SKIP_VERSION_BUMP:-1}"
export SKIP_GIT=1

bash "$ROOT/scripts/build-plugin.sh"

composer install --no-interaction
