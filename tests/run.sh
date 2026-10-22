#!/usr/bin/env bash
# End-to-end tests: redaction, scanner rules on the fixture, append-only history, rendering in both languages.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

php "$DIR/tests/redact_test.php"

# Scanner: every listed rule must fire on the fixture
php "$DIR/scripts/scan_code.php" "$DIR/fixtures/legacy-app" > "$TMP/scan.json"
for rule in P80-CURLY-OFFSET P80-REMOVED-FUNC P80-PHP4-CTOR P80-CONCAT-PRECEDENCE P82-DOLLAR-BRACE P82-DYNAMIC-PROP \
            P84-IMPLICIT-NULLABLE P84-CSV-ESCAPE P85-BACKTICK P85-CAST-NAME P85-CASE-SEMICOLON P85-FREE-FUNC EXT-MYSQL; do
  grep -q "\"rule\": \"$rule\"" "$TMP/scan.json" || fail "rule $rule did not fire"
done
echo "scanner: ok"

# Scanner with an older target: rules introduced after the target must not be reported
php "$DIR/scripts/scan_code.php" "$DIR/fixtures/legacy-app" --target=8.3.30 > "$TMP/scan83.json"
grep -q '"rule": "P80-' "$TMP/scan83.json" || fail "target 8.3: PHP 8.0 rules no longer fire"
for rule in P84-IMPLICIT-NULLABLE P84-CSV-ESCAPE P85-BACKTICK P85-CAST-NAME P85-CASE-SEMICOLON P85-FREE-FUNC; do
  grep -q "\"rule\": \"$rule\"" "$TMP/scan83.json" && fail "target 8.3: $rule (introduced after 8.3) was reported"
done
echo "scanner target: ok"

# Lint must report the curly-offset parse error
php "$DIR/scripts/lint.php" "$DIR/fixtures/legacy-app" > "$TMP/lint.json"
grep -q '"Parse error"' "$TMP/lint.json" || fail "lint did not report the parse error"
echo "lint: ok"

# History: two scans → two versions, the first one byte-identical afterwards
STORE="$TMP/store"
php "$DIR/scripts/audit.php" "$DIR/fixtures/legacy-app" --out="$STORE" --name=legacy-app --skip=rector,phpcompat --offline 2>/dev/null >/dev/null
FIRST="$(ls "$STORE/legacy-app/scans" | head -1)"
SUM1="$(php -r 'echo md5_file($argv[1]);' "$STORE/legacy-app/scans/$FIRST/report.json")"
echo '{"summary":"first review","notes":[{"sev":"info","title":"t","body":"b"}]}' > "$STORE/legacy-app/scans/$FIRST/notes.json"
sleep 1
php "$DIR/scripts/audit.php" "$DIR/fixtures/legacy-app" --out="$STORE" --name=legacy-app --skip=rector,phpcompat --offline 2>/dev/null >/dev/null
[ "$(ls "$STORE/legacy-app/scans" | wc -l | tr -d ' ')" = "2" ] || fail "expected 2 scan versions"
[ "$SUM1" = "$(php -r 'echo md5_file($argv[1]);' "$STORE/legacy-app/scans/$FIRST/report.json")" ] || fail "first scan was modified"
SECOND="$(ls "$STORE/legacy-app/scans" | tail -1)"
grep -q '"carried_from"' "$STORE/legacy-app/scans/$SECOND/notes.json" || fail "notes were not carried over"
echo "history: ok"

# Render in both languages, offline, without local paths
for lang in en id; do
  php "$DIR/scripts/render.php" --store="$STORE" --out="$TMP/report-$lang.html" --lang="$lang" --offline 2>/dev/null
  grep -q 'fonts.googleapis' "$TMP/report-$lang.html" && fail "offline report still loads web fonts"
  grep -q "$DIR" "$TMP/report-$lang.html" && fail "report contains a local absolute path"
  if command -v node >/dev/null 2>&1; then
    sed -n '/^<script>$/,/^<\/script>$/p' "$TMP/report-$lang.html" | sed '1d;$d' > "$TMP/check.js"
    node --check "$TMP/check.js" || fail "report script does not parse ($lang)"
  fi
done
echo "render: ok"
echo "all tests passed"
