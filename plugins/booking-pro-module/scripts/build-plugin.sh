#!/bin/bash
# ==========================================================
#  Booking Pro Suite  Advanced Plugin Optimizer & Build Script
# ==========================================================
#  Doel:
#   - Composer vendors opschonen
#   - Dev-dependencies verwijderen
#   - Assets minifyen (JS/CSS)
#   - Version bump & changelog update
#   - ZIP + SHA256 checksum maken
#   - Compacte distributie genereren
#   - Rapportage tonen
# ==========================================================

set -euo pipefail
START_TIME=$(date +%s)

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="$ROOT/dist"
ASSETS="$ROOT/assets"
BUILD_TMP="$ROOT/.build_tmp"
PACKAGE_SLUG="booking-pro-suite"
PACKAGE_ROOT="$BUILD_TMP/$PACKAGE_SLUG"
PLUGIN_FILE="$ROOT/booking-pro-suite.php"

# Detect changelog file
if [[ -f "$ROOT/CHANGELOG.md" ]]; then
  CHANGELOG_FILE="$ROOT/CHANGELOG.md"
elif [[ -f "$ROOT/docs/CHANGELOG.md" ]]; then
  CHANGELOG_FILE="$ROOT/docs/CHANGELOG.md"
elif [[ -f "$ROOT/docs/release-notes.md" ]]; then
  CHANGELOG_FILE="$ROOT/docs/release-notes.md"
elif [[ -f "$ROOT/docs/release-notes-4.03.md" ]]; then
  CHANGELOG_FILE="$ROOT/docs/release-notes-4.03.md"
else
  CHANGELOG_FILE=""
fi

if [[ ! -f "$PLUGIN_FILE" ]]; then
  echo "❌ Plugin bootstrap $PLUGIN_FILE niet gevonden."
  exit 1
fi

if command -v composer >/dev/null 2>&1; then
  COMPOSER_CMD=(composer)
elif [ -f "$ROOT/composer.phar" ]; then
  COMPOSER_CMD=(php "$ROOT/composer.phar")
else
  echo "❌ Composer executable not found. Install Composer or place composer.phar in $ROOT."
  exit 1
fi
echo "🧼 Cleaning previous builds..."
rm -rf "$DIST" "$BUILD_TMP"
mkdir -p "$DIST" "$PACKAGE_ROOT"

echo "📦 Installing production dependencies..."
cd "$ROOT"
"${COMPOSER_CMD[@]}" install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction

if [[ -d "$ASSETS" ]]; then
  echo "🪶 Minifying assets..."
  if command -v npx >/dev/null 2>&1; then
    # Minify JS in place
    while IFS= read -r -d '' js; do
      tmp_file="${js}.tmp"
      npx terser "$js" -c -m -o "$tmp_file"
      mv "$tmp_file" "$js"
    done < <(find "$ASSETS" -type f -name "*.js" ! -name "*.min.js" -print0)

    # Minify CSS in place
    while IFS= read -r -d '' css; do
      tmp_file="${css}.tmp"
      npx cssnano "$css" "$tmp_file"
      mv "$tmp_file" "$css"
    done < <(find "$ASSETS" -type f -name "*.css" ! -name "*.min.css" -print0)
  else
    echo "  npx niet gevonden; sla asset-minificatie over."
  fi
else
  echo "ℹ️ Geen assets-map gevonden, minificatie overgeslagen."
fi

CURRENT_VERSION=$(grep -m1 "Version:" "$PLUGIN_FILE" | awk '{print $3}')
NEW_VERSION=${NEW_VERSION:-$(date +"1.%m%d.%H%M")}

if [[ -z "${SKIP_VERSION_BUMP:-}" ]]; then
  echo "🔢 Updating plugin version from ${CURRENT_VERSION:-unknown} to $NEW_VERSION..."
  php <<'PHP' "$PLUGIN_FILE" "$NEW_VERSION"
<?php
[$file, $version] = $argv;
$contents = file_get_contents($file);
if ($contents === false) {
    fwrite(STDERR, "Failed to read plugin file\n");
    exit(1);
}
$updated = preg_replace('/^ \* Version:.*$/m', ' * Version: ' . $version, $contents, 1, $count);
if ($count === 0) {
    fwrite(STDERR, "Version line not found in plugin file\n");
    exit(1);
}
file_put_contents($file, $updated);
PHP

  if [[ -n "$CHANGELOG_FILE" ]]; then
    echo "🗒  Updating changelog ($CHANGELOG_FILE)..."
    tmp_file="${CHANGELOG_FILE}.tmp"
    {
      echo "### [v${NEW_VERSION}] - $(date +%F)"
      echo "- Optimized build, minified assets, refreshed vendors"
      echo "- Automated build created at $(date)"
      echo
      cat "$CHANGELOG_FILE"
    } > "$tmp_file"
    mv "$tmp_file" "$CHANGELOG_FILE"
  else
    echo "ℹ️ Geen changelog-bestand gevonden; sla update over."
  fi
else
  echo "ℹ️ Version bump overgeslagen (SKIP_VERSION_BUMP=1)."
  if [[ -z "$NEW_VERSION" ]]; then
    NEW_VERSION=${CURRENT_VERSION:-dev}
  fi
fi

# ----------------------------------------------------------
# 2  Files kopiëren naar tijdelijke build-map
# ----------------------------------------------------------
echo "?? Copying project files..."
copy_project() {
  if command -v rsync >/dev/null 2>&1; then
    rsync -a \
      --exclude ''.git/'' \
      --exclude ''.github/'' \
      --exclude ''.build_tmp/'' \
      --exclude ''node_modules/'' \
      --exclude ''dist/'' \
      --exclude ''logs/'' \
      --exclude ''docker/'' \
      --exclude ''ops/'' \
      --exclude ''tests/'' \
      --exclude ''composer.lock'' \
      --exclude ''*.neon'' \
      --exclude ''*.yml'' \
      --exclude ''*.dist'' \
      "$ROOT/" "$PACKAGE_ROOT/"
  else
    echo "  rsync not available; using tar fallback..."
    (cd "$ROOT" && tar -cf - \
      --exclude=''./.git'' \
      --exclude=''./.github'' \
      --exclude=''./.build_tmp'' \
      --exclude=''./node_modules'' \
      --exclude=''./dist'' \
      --exclude=''./logs'' \
      --exclude=''./docker'' \
      --exclude=''./ops'' \
      --exclude=''./tests'' \
      --exclude=''./composer.lock'' \
      --exclude=''*.neon'' \
      --exclude=''*.yml'' \
      --exclude=''*.dist'' \
      .) | tar -xf - -C "$PACKAGE_ROOT"
  fi
}
copy_project

# ----------------------------------------------------------
# 3  Vendors opruimen
# ----------------------------------------------------------
echo "🧩 Removing dev and stub packages..."
rm -rf "$PACKAGE_ROOT/vendor/phpunit" \
       "$PACKAGE_ROOT/vendor/brain/monkey" \
       "$PACKAGE_ROOT/vendor/mockery" \
       "$PACKAGE_ROOT/vendor/hamcrest" \
       "$PACKAGE_ROOT/vendor/php-stubs" \
       "$PACKAGE_ROOT/vendor/squizlabs" \
       "$PACKAGE_ROOT/vendor/dealerdirect" \
       "$PACKAGE_ROOT/vendor/wp-coding-standards"

# ----------------------------------------------------------
# 4  ZIP + checksum genereren
# ----------------------------------------------------------
echo "📁 Creating ZIP archive..."
cd "$BUILD_TMP"
ZIP_NAME="${PACKAGE_SLUG}_${NEW_VERSION}.zip"
if command -v zip >/dev/null 2>&1; then
  zip -rq "$DIST/$ZIP_NAME" "$PACKAGE_SLUG" \
    -x ''*.git*'' ''*/*.git*'' ''*/*.neon'' ''*/*.yml'' ''*/*.dist'' ''*/*.log''
else
  echo "  zip not available; using PowerShell fallback..."
  POWERSHELL_EXE=${POWERSHELL_EXE:-$(command -v pwsh.exe 2>/dev/null || command -v powershell.exe 2>/dev/null || echo '')}
  if [ -z "$POWERSHELL_EXE" ]; then
    if [ -x "/c/Program Files/PowerShell/7/pwsh.exe" ]; then
      POWERSHELL_EXE="/c/Program Files/PowerShell/7/pwsh.exe"
    elif [ -x "/c/WINDOWS/System32/WindowsPowerShell/v1.0/powershell.exe" ]; then
      POWERSHELL_EXE="/c/WINDOWS/System32/WindowsPowerShell/v1.0/powershell.exe"
    else
      echo "  No zip-compatible tool available. Install zip or PowerShell."
      exit 1
    fi
  fi
  pwsh_build_tmp=$(cygpath -aw "$BUILD_TMP")
  pwsh_zip=$(cygpath -aw "$DIST/$ZIP_NAME")
  "$POWERSHELL_EXE" -NoLogo -NoProfile -Command "Remove-Item -LiteralPath '$pwsh_zip' -ErrorAction SilentlyContinue; Compress-Archive -LiteralPath (Join-Path '$pwsh_build_tmp' '$PACKAGE_SLUG') -DestinationPath '$pwsh_zip'"
fi

cd "$DIST"
if command -v shasum >/dev/null 2>&1; then
  shasum -a 256 "$ZIP_NAME" > "${ZIP_NAME}.sha256"
elif command -v sha256sum >/dev/null 2>&1; then
  sha256sum "$ZIP_NAME" > "${ZIP_NAME}.sha256"
elif command -v pwsh.exe >/dev/null 2>&1 || command -v powershell.exe >/dev/null 2>&1; then
  POWERSHELL_EXE=${POWERSHELL_EXE:-$(command -v pwsh.exe 2>/dev/null || command -v powershell.exe 2>/dev/null || echo '')}
  if [ -z "$POWERSHELL_EXE" ]; then
    if [ -x "/c/Program Files/PowerShell/7/pwsh.exe" ]; then
      POWERSHELL_EXE="/c/Program Files/PowerShell/7/pwsh.exe"
    elif [ -x "/c/WINDOWS/System32/WindowsPowerShell/v1.0/powershell.exe" ]; then
      POWERSHELL_EXE="/c/WINDOWS/System32/WindowsPowerShell/v1.0/powershell.exe"
    else
      echo "?? Geen SHA256-tool gevonden; checksum niet aangemaakt."
      exit 1
    fi
  fi
  pwsh_zip=$(cygpath -aw "$DIST/$ZIP_NAME")
  pwsh_hash=$(cygpath -aw "$DIST/${ZIP_NAME}.sha256")
  "$POWERSHELL_EXE" -NoLogo -NoProfile -Command "Set-StrictMode -Version Latest; $hash = (Get-FileHash -LiteralPath '$pwsh_zip' -Algorithm SHA256).Hash; [System.IO.File]::WriteAllText('$pwsh_hash', \"$hash  $(Split-Path -Leaf '$pwsh_zip')\", [System.Text.Encoding]::ASCII)"
else
  echo "?? Geen SHA256-tool gevonden; checksum niet aangemaakt."
fi

# ----------------------------------------------------------
# 5  Validatie & rapportage
# ----------------------------------------------------------
echo "🧪 Validating build..."
php -l "$PLUGIN_FILE" || true
"${COMPOSER_CMD[@]}" validate --no-check-publish || true

END_TIME=$(date +%s)
BUILD_DURATION=$((END_TIME - START_TIME))

echo " Compact plugin build complete:"
ls -lh "$DIST/$ZIP_NAME"
if [[ -f "${ZIP_NAME}.sha256" ]]; then
  echo "🔐 SHA256 checksum saved to ${ZIP_NAME}.sha256"
fi
echo "  Build duration: ${BUILD_DURATION}s"

# ----------------------------------------------------------
# 6  Optioneel: Git commit + tag
# ----------------------------------------------------------
if [[ -z "${SKIP_GIT:-}" ]] && git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "💾 Committing version bump..."
  git add "$PLUGIN_FILE"
  if [[ -n "$CHANGELOG_FILE" ]]; then
    git add "$CHANGELOG_FILE"
  fi
  git commit -m "chore(build): release v${NEW_VERSION}" || echo "Nothing to commit."
  git tag -a "v${NEW_VERSION}" -m "Booking Pro Suite v${NEW_VERSION}" || echo "Tag already exists."
else
  if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    echo "ℹ️ Git commit/tag stap overgeslagen (SKIP_GIT=1 of buiten repo)."
  fi
fi

# ----------------------------------------------------------
# 7  Samenvattend rapport
# ----------------------------------------------------------
echo "📊 BUILD SUMMARY"
echo "----------------------------------------------------------"
du -sh "$PACKAGE_ROOT" 2>/dev/null | awk '{print "Build size: " $1}'
du -sh "$DIST/$ZIP_NAME" 2>/dev/null | awk '{print "ZIP size: " $1}'
echo "Plugin version: v${NEW_VERSION}"
echo "----------------------------------------------------------"
echo "🎉 Done. Distributie beschikbaar in: $DIST/$ZIP_NAME"