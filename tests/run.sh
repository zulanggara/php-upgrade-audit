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

# Profile: PHP version hints from Dockerfiles and CI config, in nested folders too
mkdir -p "$TMP/hints/docker/php" "$TMP/hints/.github/workflows"
echo 'FROM php:7.3-fpm' > "$TMP/hints/docker/php/Dockerfile"
printf 'steps:\n  - with:\n      php-version: "7.4"\n' > "$TMP/hints/.github/workflows/ci.yml"
echo '<?php echo 1;' > "$TMP/hints/a.php"
php "$DIR/scripts/detect.php" "$TMP/hints" > "$TMP/hints.json"
grep -q '"docker/php/Dockerfile:1"' "$TMP/hints.json" || fail "PHP hint in docker/php/Dockerfile not found"
grep -q '"value": "7.4"' "$TMP/hints.json" || fail "PHP hint in the CI workflow not found"
echo "profile: ok"

# PHPCompatibility must find its standard even when Composer skipped its plugins (root, CI, --no-plugins)
if [ -f "$DIR/tools/vendor/autoload.php" ]; then
  php "$DIR/scripts/run_tools.php" "$DIR/fixtures/legacy-app" --out="$TMP/tools" --only=phpcompat
  grep -q '"ok": true' "$TMP/tools/phpcompat.json" || fail "PHPCompatibility did not run: $(grep -m1 stderr "$TMP/tools/phpcompat.json")"
  echo "phpcompat: ok"
else
  echo "phpcompat: skipped (tools not installed)"
fi

# History: two scans → two versions, the first one byte-identical afterwards
STORE="$TMP/store"
php "$DIR/scripts/audit.php" "$DIR/fixtures/legacy-app" --out="$STORE" --name=legacy-app --skip=rector,phpcompat --offline 2>/dev/null >/dev/null
FIRST="$(ls "$STORE/legacy-app/scans" | head -1)"
SUM1="$(php -r 'echo md5_file($argv[1]);' "$STORE/legacy-app/scans/$FIRST/report.json")"
# Local paths in notes (and in tool stderr) must not reach the report
echo "{\"summary\":\"first review\",\"notes\":[{\"sev\":\"info\",\"title\":\"t\",\"body\":\"see $DIR/tools/x.php and /home/someone/project/a.php and /Users/someone/w/b.php\"}]}" > "$STORE/legacy-app/scans/$FIRST/notes.json"
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
  grep -qE '/home/someone|/Users/someone' "$TMP/report-$lang.html" && fail "report contains a home folder path"
  if command -v node >/dev/null 2>&1; then
    sed -n '/^<script>$/,/^<\/script>$/p' "$TMP/report-$lang.html" | sed '1d;$d' > "$TMP/check.js"
    node --check "$TMP/check.js" || fail "report script does not parse ($lang)"
  fi
done
echo "render: ok"

# Notes validator: the carried-over notes of the second scan are flagged, a good file passes, a bad one fails
set +e
php "$DIR/scripts/validate_notes.php" --store="$STORE" > "$TMP/val.txt"; code=$?
set -e
[ "$code" = 10 ] || fail "validate_notes must exit 10 while carried_from is still set (got $code)"
grep -q 'carried_from' "$TMP/val.txt" || fail "validate_notes did not flag carried_from"
php "$DIR/scripts/render.php" --store="$STORE" --out="$TMP/r.html" 2> "$TMP/render-warn.txt"
grep -q 'carried_from' "$TMP/render-warn.txt" || fail "render.php did not warn about unreviewed notes"
cat > "$TMP/good.json" <<'JSON'
{"status":{"level":"deprecated","label":"Ready after 2 fixes"},"summary":"Runs on the target.",
 "notes":[{"sev":"deprecated","title":"Implicit nullable","body":"Add ?.","files":["a.php:3"],
 "code":[{"where":"a.php:3","before":"function f(Foo $x = null)","after":"function f(?Foo $x = null)"}]}]}
JSON
php "$DIR/scripts/validate_notes.php" "$TMP/good.json" > /dev/null || fail "a valid notes.json was rejected"
GHTOKEN="gh""p_$(printf 'a1B2%.0s' 1 2 3 4 5 6 7 8 9)"
cat > "$TMP/bad.json" <<JSON
{"status":{"level":"must-fix"},"sumary":"x","notes":[{"sev":"high","title":"","body":"token $GHTOKEN",
 "code":[{"before":"x = 1"}]}]}
JSON
set +e
php "$DIR/scripts/validate_notes.php" "$TMP/bad.json" --json > "$TMP/bad-out.json"; code=$?
set -e
[ "$code" = 10 ] || fail "an invalid notes.json was accepted"
for w in status.level sumary 'notes\[0\].sev' 'notes\[0\].title' 'notes\[0\].code\[0\].after' 'notes\[0\].body'; do
  grep -q "\"where\": \"$w\"" "$TMP/bad-out.json" || fail "validator missed $w"
done
grep -q "$GHTOKEN" "$TMP/bad-out.json" && fail "validator output contains the secret"
echo "validate notes: ok"

# First run: --help everywhere, exit codes, --fail-on, PHP version warning
for s in audit scan_code lint check_libs run_tools detect compare_framework render validate_notes load_classes; do
  [ -f "$DIR/scripts/$s.php" ] || continue
  php "$DIR/scripts/$s.php" --help > "$TMP/help.txt" || fail "$s.php --help did not exit 0"
  grep -q 'Usage' "$TMP/help.txt" || fail "$s.php --help has no usage line"
done
set +e
php "$DIR/scripts/audit.php" > /dev/null 2>&1; [ $? = 1 ] || fail "audit.php without arguments must exit 1"
php "$DIR/scripts/audit.php" "$DIR/fixtures/legacy-app" --out="$DIR/fixtures/legacy-app/out" > /dev/null 2>&1; [ $? = 1 ] || fail "output inside the repo must exit 1"
php "$DIR/scripts/audit.php" "$DIR/fixtures/legacy-app" --out="$TMP/x" --target=nine > /dev/null 2>&1; [ $? = 1 ] || fail "invalid --target must exit 1"
php "$DIR/scripts/audit.php" "$DIR/fixtures/legacy-app" --out="$TMP/ci" --skip=rector,phpcompat,libs --offline --fail-on=wajib > /dev/null 2>&1; [ $? = 10 ] || fail "--fail-on=wajib must exit 10 on the fixture"
php "$DIR/scripts/audit.php" "$DIR/fixtures/legacy-app/src/Clean" --out="$TMP/ci" --name=clean --skip=rector,phpcompat,libs --offline --fail-on=perilaku > /dev/null 2>&1; [ $? = 0 ] || fail "--fail-on on clean code must exit 0"
php "$DIR/scripts/audit.php" "$DIR/fixtures/legacy-app" --out="$TMP/ci" --skip=rector,phpcompat,libs --offline --target=7.4 2> "$TMP/warn.txt" > /dev/null
set -e
grep -q 'Warning: target is PHP 7.4' "$TMP/warn.txt" || fail "no warning when the PHP running the audit is not the target"
[ -d "$DIR/fixtures/legacy-app/out" ] && fail "audit created a folder inside the repo"
echo "first run: ok"
echo "all tests passed"
