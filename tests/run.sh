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

# Every rule has an English title and fix
php -r '
$ids = array_column(require $argv[1], "id");
$en = require $argv[2];
$missing = array_diff($ids, array_keys($en));
$extra = array_diff(array_keys($en), $ids);
foreach ($en as $id => $t) { if (!is_array($t) || count($t) !== 2 || trim($t[0]) === "" || trim($t[1]) === "") $missing[] = "$id (incomplete)"; }
if ($missing || $extra) { fwrite(STDERR, "missing: " . implode(", ", $missing) . "; unknown: " . implode(", ", $extra) . "\n"); exit(1); }
' "$DIR/scripts/rules.php" "$DIR/scripts/rules_en.php" || fail "rules_en.php does not match rules.php"
echo "translations: ok"

# Scanner with an older target: rules introduced after the target must not be reported
php "$DIR/scripts/scan_code.php" "$DIR/fixtures/legacy-app" --target=8.3.30 > "$TMP/scan83.json"
grep -q '"rule": "P80-' "$TMP/scan83.json" || fail "target 8.3: PHP 8.0 rules no longer fire"
for rule in P84-IMPLICIT-NULLABLE P84-CSV-ESCAPE P85-BACKTICK P85-CAST-NAME P85-CASE-SEMICOLON P85-FREE-FUNC; do
  grep -q "\"rule\": \"$rule\"" "$TMP/scan83.json" && fail "target 8.3: $rule (introduced after 8.3) was reported"
done
echo "scanner target: ok"

# Generic method names: SplObjectStorage::contains() only counts where SplObjectStorage is used at all
mkdir -p "$TMP/spl"
printf '<?php\nclass Post { public function add($c) { if (!$this->comments->contains($c)) { $this->comments->add($c); } } }\n' > "$TMP/spl/Collection.php"
printf '<?php\n$s = new SplObjectStorage();\n$s->attach(new stdClass());\n' > "$TMP/spl/Storage.php"
php "$DIR/scripts/scan_code.php" "$TMP/spl" > "$TMP/spl.json"
grep -q '"file": "Collection.php"' "$TMP/spl.json" && fail "->contains() on a collection reported as SplObjectStorage"
grep -q '"file": "Storage.php"' "$TMP/spl.json" || fail "SplObjectStorage::attach() not reported"
echo "scanner generic methods: ok"

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

# Redaction end to end: a token in notes (summary, body, code) never reaches the HTML
TOKEN="gh""p_$(printf 'Z9y8%.0s' 1 2 3 4 5 6 7 8 9)"
PASSWD="hunter2-$(date +%s)"
RSTORE="$TMP/redact-store"
cp -r "$STORE" "$RSTORE"
LAST="$(ls "$RSTORE/legacy-app/scans" | tail -1)"
cat > "$RSTORE/legacy-app/scans/$LAST/notes.json" <<JSON
{"summary":"Token $TOKEN was found in composer.json.",
 "notes":[{"sev":"wajib","title":"Credentials","body":"Remove https://$TOKEN@github.com/acme/x.git",
  "code":[{"where":"config.php:3","before":"\$db['password'] = '$PASSWD';","after":"\$db['password'] = getenv('DB_PASSWORD');"}]}]}
JSON
php "$DIR/scripts/render.php" --store="$RSTORE" --out="$TMP/redact.html" --offline 2> "$TMP/redact.log"
grep -q "$TOKEN" "$TMP/redact.html" && fail "a token from notes.json reached the report"
grep -q "$PASSWD" "$TMP/redact.html" && fail "a password from a code sample reached the report"
grep -q 'REDACTED' "$TMP/redact.html" || fail "nothing was redacted"
grep -qE '[1-9][0-9]* value\(s\) redacted' "$TMP/redact.log" || fail "render log does not report the redactions"
echo "redaction end to end: ok"

# Robustness: a repo without composer.json, and one with a minified file, do not crash any step
mkdir -p "$TMP/nocomposer" "$TMP/minified"
printf '<?php\nfunction f($a) { return each($a); }\n' > "$TMP/nocomposer/a.php"
php -r 'echo "<?php "; for ($i = 0; $i < 300; $i++) echo "function f$i(\$a){return \$a{0}.mysql_query(\"x\");} "; echo "\n";' > "$TMP/minified/min.php"
for r in nocomposer minified; do
  php "$DIR/scripts/audit.php" "$TMP/$r" --out="$TMP/robust" --name=$r --skip=rector --offline > /dev/null 2> "$TMP/robust-$r.log" || fail "audit.php crashed on $r: $(tail -3 "$TMP/robust-$r.log")"
  grep -qiE 'fatal|failed \(exit' "$TMP/robust-$r.log" && fail "a step failed on $r: $(grep -iE 'fatal|failed' "$TMP/robust-$r.log" | head -2)"
done
grep -q '"composer": false' "$TMP/robust"/nocomposer/scans/*/libs.json || fail "no-composer repo: libs.json should say composer false"
grep -q 'min.php' "$TMP/robust"/minified/scans/*/profile.json || fail "minified file not detected"
[ "$(grep -c '"rule": "EXT-MYSQL"' "$TMP/robust"/minified/scans/*/scan.json)" -ge 300 ] || fail "findings on one minified line were deduplicated away"
php "$DIR/scripts/render.php" --store="$TMP/robust" --out="$TMP/robust.html" --offline 2> /dev/null || fail "render failed on the robustness store"
echo "robustness: ok"

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

# Framework comparison: deliberate conflicts are found, and --store adds a version per run
F="$DIR/fixtures/framework"
CMP=(--old="$F/old" --new="$F/new" --app="$F/app" --official="$F/official" --new-config="$F/new-config" --app-config="$F/app/config")
php "$DIR/scripts/compare_framework.php" "${CMP[@]}" 2>/dev/null > "$TMP/cmp.json"
php -r '
$d = json_decode(file_get_contents($argv[1]), true);
$o = array_map(fn ($x) => $x["class"] . "::" . $x["member"], $d["overrides"]["new"]);
$u = array_column($d["usage"], "what");
$need = ["MY_Input::post()", "MY_Email::send()", "Thing::"];
$use = ["libraries/Encrypt.php", "helpers/legacy_helper.php", "CI_DB_query_builder::where_in()", "CI_Input::_clean_input_keys()", "legacy_thing()"];
$miss = array_merge(array_diff($need, $o), array_diff($use, $u));
if (($d["adapter"] ?? null) !== "codeigniter3") $miss[] = "adapter codeigniter3 not detected";
if ($d["local_patches"]["changed"] !== ["libraries/Email.php"]) $miss[] = "local patch in libraries/Email.php";
if (($d["config"][0]["new_keys"] ?? []) !== ["\$config[\x27new_key\x27]"]) $miss[] = "new config key";
if ($miss) { fwrite(STDERR, "compare_framework missed: " . implode(", ", $miss) . "\n"); exit(1); }
' "$TMP/cmp.json" || fail "compare_framework.php did not report the fixture conflicts"
grep -q '<?phpclass' "$TMP/cmp.json" && fail "local patch diff glues the open tag to the next token"
php "$DIR/scripts/compare_framework.php" "${CMP[@]}" --adapter=none 2>/dev/null > "$TMP/cmp-generic.json"
grep -q '"MY_Input"' "$TMP/cmp-generic.json" || fail "generic comparison lost the signature conflict"
grep -q 'Encrypt.php"' "$TMP/cmp-generic.json" && grep -q '"kind": "file framework dihapus"' "$TMP/cmp-generic.json" && fail "generic comparison used CodeIgniter loader rules"
php "$DIR/scripts/compare_framework.php" "${CMP[@]}" --store="$STORE" --name="Framework merge" 2>/dev/null > /dev/null
sleep 1
php "$DIR/scripts/compare_framework.php" "${CMP[@]}" --store="$STORE" --name="Framework merge" 2>/dev/null > /dev/null
[ "$(ls "$STORE/framework-merge/scans" | wc -l | tr -d ' ')" = "2" ] || fail "second comparison did not add a version"
echo "compare framework: ok"

# Runtime proof: classes load in a scratch copy; the PHP 8 signature conflict fails, the rest is classified
if command -v composer > /dev/null 2>&1; then
  php "$DIR/scripts/load_classes.php" "$DIR/fixtures/composer-app" --work="$TMP/work" > "$TMP/rt.json" 2> "$TMP/rt.err" || fail "load_classes.php failed: $(tail -3 "$TMP/rt.err")"
  php -r '
  $d = json_decode(file_get_contents($argv[1]), true);
  $ok = $d["mode"] === "composer" && array_column($d["failed"], "type", "unit") === ["App\\Model\\Report" => "signature"]
     && array_column($d["unresolved"], "missing") === ["Vendor\\Sdk\\BasePlugin"]
     && str_contains($d["deprecations"][0]["message"] ?? "", "Counter::count()") && $d["loaded"] === 3;
  if (!$ok) { fwrite(STDERR, json_encode($d, JSON_PRETTY_PRINT)); exit(1); }' "$TMP/rt.json" || fail "composer mode did not classify the fixture classes"
  [ -d "$DIR/fixtures/composer-app/vendor" ] && fail "load_classes.php installed into the audited repo"
  echo "load classes (composer): ok"
else
  echo "load classes (composer): skipped (composer not installed)"
fi
php "$DIR/scripts/load_classes.php" "$DIR/fixtures/plain-app" --work="$TMP/work" > "$TMP/rt-files.json"
php -r '
$d = json_decode(file_get_contents($argv[1]), true);
$skipped = array_column($d["skipped"], "file"); sort($skipped);
$ok = $d["mode"] === "files" && $skipped === ["index.php", "plugin.php"]
   && array_column($d["failed"], "type", "unit") === ["includes/class-widget.php" => "signature"]
   && array_column($d["unresolved"], "missing") === ["WP_REST_Controller"] && $d["loaded"] === 2;
if (!$ok) { fwrite(STDERR, json_encode($d, JSON_PRETTY_PRINT)); exit(1); }' "$TMP/rt-files.json" || fail "files mode did not classify the fixture files"
php "$DIR/scripts/audit.php" "$DIR/fixtures/plain-app" --out="$TMP/rtstore" --skip=rector,phpcompat,libs --offline --load-classes > /dev/null 2>&1
grep -q '"tool": "load_classes"' "$TMP/rtstore"/plain-app/scans/*/report.json || fail "audit.php --load-classes did not store the runtime proof"
echo "load classes (files): ok"

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
