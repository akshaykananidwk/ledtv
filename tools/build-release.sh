#!/usr/bin/env bash
# Builds the upload-ready web application zip: dist/hotelcast-<version>.zip
# Excludes secrets, runtime data and tests. Usage: tools/build-release.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
APP="$ROOT/hotelcast"
VERSION="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["version"];' "$APP/version.json")"
OUT="$ROOT/dist"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

echo "Linting PHP…"
find "$APP" -name '*.php' -not -path '*/tests/*' -print0 | xargs -0 -n1 php -l >/dev/null

mkdir -p "$STAGE/hotelcast" "$OUT"
cd "$APP"
# Copy everything except secrets / runtime data / dev files.
tar --exclude='./.env' --exclude='./config.php' --exclude='./installed.lock' \
    --exclude='./tests' --exclude='./phpunit.xml' \
    --exclude='./uploads/*' --exclude='./backups/*' --exclude='./logs/*' --exclude='./storage/*' \
    --exclude='./install_disabled_*' -cf - . | tar -xf - -C "$STAGE/hotelcast"
# Keep protective .htaccess / placeholders in runtime folders.
for d in uploads backups logs storage storage/cache; do
  mkdir -p "$STAGE/hotelcast/$d"
  [ -f "$APP/$d/.htaccess" ] && cp "$APP/$d/.htaccess" "$STAGE/hotelcast/$d/"
  touch "$STAGE/hotelcast/$d/.gitkeep"
done
# Ship the signed TV app inside the web package for convenience.
if ls "$ROOT"/android/release/*.apk >/dev/null 2>&1; then
  mkdir -p "$STAGE/hotelcast/downloads"
  cp "$ROOT"/android/release/*.apk "$STAGE/hotelcast/downloads/"
fi
cp "$ROOT/README.md" "$STAGE/hotelcast/README.md" 2>/dev/null || true

rm -f "$OUT/hotelcast-$VERSION.zip"
(cd "$STAGE" && zip -qr9 "$OUT/hotelcast-$VERSION.zip" hotelcast)
echo "Built $OUT/hotelcast-$VERSION.zip ($(du -h "$OUT/hotelcast-$VERSION.zip" | cut -f1))"
