#!/usr/bin/env bash
# Rebuild examples/sample-report.html (English) and examples/sample-report.id.html (Indonesian) from the bundled
# fixtures and the analyst notes in examples/source/. Run it with the target PHP (8.5.12) as `php`, after
# scripts/setup.sh. Needs network for Packagist metadata (public package names only).
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$DIR/examples/source"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
STORE="$TMP/store"
TARGET=8.5.12

[ "$(php -r 'echo PHP_VERSION;')" = "$TARGET" ] || echo "Warning: php is $(php -r 'echo PHP_VERSION;'), the samples are meant to show PHP $TARGET." >&2
latest() { ls "$STORE/$1/scans" | tail -1; }

# legacy-app: first scan, then a second one on a copy with one fix (curly brace offset), to show the history
php "$DIR/scripts/audit.php" "$DIR/fixtures/legacy-app" --out="$STORE" --name=legacy-app --target=$TARGET
cp "$SRC/legacy-app-1.en.json" "$STORE/legacy-app/scans/$(latest legacy-app)/notes.json"
cp -r "$DIR/fixtures/legacy-app" "$TMP/legacy-app"
sed -i.bak 's/\$name{0}/$name[0]/' "$TMP/legacy-app/src/Legacy.php" && rm "$TMP/legacy-app/src/Legacy.php.bak"
sleep 1
php "$DIR/scripts/audit.php" "$TMP/legacy-app" --out="$STORE" --name=legacy-app --target=$TARGET
cp "$SRC/legacy-app-2.en.json" "$STORE/legacy-app/scans/$(latest legacy-app)/notes.json"

# composer-app: runtime proof (installs nothing: the fixture has no dependencies)
php "$DIR/scripts/audit.php" "$DIR/fixtures/composer-app" --out="$STORE" --name=composer-app --target=$TARGET --load-classes
cp "$SRC/composer-app.en.json" "$STORE/composer-app/scans/$(latest composer-app)/notes.json"
cp "$SRC/overview.en.json" "$STORE/overview.json"

php "$DIR/scripts/validate_notes.php" --store="$STORE"
php "$DIR/scripts/render.php" --store="$STORE" --out="$DIR/examples/sample-report.html" --lang=en --offline --title="Sample: PHP Upgrade Audit"

# Indonesian: same scans, Indonesian notes
cp -r "$STORE" "$TMP/store-id"
for n in 1 2; do
  cp "$SRC/legacy-app-$n.id.json" "$TMP/store-id/legacy-app/scans/$(ls "$TMP/store-id/legacy-app/scans" | sed -n "${n}p")/notes.json"
done
cp "$SRC/composer-app.id.json" "$TMP/store-id/composer-app/scans/$(ls "$TMP/store-id/composer-app/scans" | tail -1)/notes.json"
cp "$SRC/overview.id.json" "$TMP/store-id/overview.json"
php "$DIR/scripts/validate_notes.php" --store="$TMP/store-id"
php "$DIR/scripts/render.php" --store="$TMP/store-id" --out="$DIR/examples/sample-report.id.html" --lang=id --offline --title="Contoh: Audit Upgrade PHP"

# The samples are committed: they must not carry local paths
if grep -lE "$TMP|$DIR|/Users/|/home/" "$DIR/examples/sample-report.html" "$DIR/examples/sample-report.id.html"; then
  echo "Error: a sample report contains a local path." >&2
  exit 1
fi
echo "Samples rebuilt."
