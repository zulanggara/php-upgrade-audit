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
            P84-IMPLICIT-NULLABLE P84-CSV-ESCAPE P84-BCRYPT-COST P85-BACKTICK P85-CAST-NAME P85-CASE-SEMICOLON P85-FREE-FUNC EXT-MYSQL; do
  grep -q "\"rule\": \"$rule\"" "$TMP/scan.json" || fail "rule $rule did not fire"
done
# Regression: a value list in in_array() must not be reported as a relative callable (exactly the 2 real ones)
[ "$(grep -c '"rule": "P82-RELATIVE-CALLABLE"' "$TMP/scan.json")" = "2" ] || fail "relative-callable false positive (in_array value list) or miss"
[ "$(grep -c '"rule": "P84-BCRYPT-COST"' "$TMP/scan.json")" = "1" ] || fail "P84-BCRYPT-COST must fire once (not when the cost is explicit)"
grep -q '"url": "https://wiki.php.net/rfc/bcrypt_cost_2023"' "$TMP/scan.json" || fail "rule evidence is not in scan.json"
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
for rule in P84-IMPLICIT-NULLABLE P84-CSV-ESCAPE P84-BCRYPT-COST P85-BACKTICK P85-CAST-NAME P85-CASE-SEMICOLON P85-FREE-FUNC; do
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

# Guards that depend on the PHP running the scan (function_exists, class_exists...) are only evaluated when that PHP
# is the target's minor version; otherwise they are "?" (check by hand), never a guess
mkdir -p "$TMP/guard"
printf '<?php\nif (function_exists("no_such_function_here")) {\n    each($a);\n}\n' > "$TMP/guard/a.php"
RUNTIME="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
OTHER="8.5"; [ "$RUNTIME" = "8.5" ] && OTHER="8.4"
php "$DIR/scripts/scan_code.php" "$TMP/guard" --target="$RUNTIME" | grep -q '"guard_state": "mati"' || fail "guard not evaluated on the target PHP itself"
php "$DIR/scripts/scan_code.php" "$TMP/guard" --target="$OTHER" | grep -q '"guard_state": "?"' || fail "function_exists() guard evaluated with a PHP that is not the target"
echo "scanner guards: ok"

# Loose comparison with 0: only when the other side can be a non-numeric string (measured false positives)
mkdir -p "$TMP/cmp"
cat > "$TMP/cmp/a.php" <<'PHP'
<?php
if (strcasecmp($a, 'inline') == 0) {}
if (0 == count($list)) {}
if (($flags & $flag) != 0) {}
if (gmp_prob_prime($v, 10) != 0) {}
if ((int) $step == 0) {}
if (strlen($s) == 0) {}
if ($status == 0) {}
PHP
php "$DIR/scripts/scan_code.php" "$TMP/cmp" > "$TMP/cmp.json"
[ "$(grep -c '"rule": "P80-LOOSE-CMP"' "$TMP/cmp.json")" = "1" ] || fail "loose comparison: expected only \$status == 0, got: $(grep -A3 'P80-LOOSE-CMP' "$TMP/cmp.json" | grep '"line"' | tr -d ' \n')"
grep -q '"line": 8' "$TMP/cmp.json" || fail "loose comparison: \$status == 0 not reported"

# is_resource(): only where a resource type that became an object in PHP 8 is used in the file
mkdir -p "$TMP/res"
printf '<?php\n$fp = fopen("x", "r");\nif (is_resource($fp)) { fclose($fp); }\n' > "$TMP/res/file.php"
printf '<?php\n$ch = curl_init();\nif (is_resource($ch)) { curl_exec($ch); }\n' > "$TMP/res/curl.php"
php "$DIR/scripts/scan_code.php" "$TMP/res" > "$TMP/res.json"
grep -q '"file": "file.php"' "$TMP/res.json" && fail "is_resource() on a file handle reported"
grep -q '"file": "curl.php"' "$TMP/res.json" || fail "is_resource() on a curl handle not reported"

# Removed INI settings: reading one (polyfills do) is harmless, setting one has no effect any more
printf '<?php\n$o = ini_get("mbstring.func_overload");\nini_set("track_errors", "1");\n' > "$TMP/res/ini.php"
php "$DIR/scripts/scan_code.php" "$TMP/res" > "$TMP/ini.json"
php -r '$f = array_filter(json_decode(file_get_contents($argv[1]), true)["findings"], fn ($x) => $x["rule"] === "P80-REMOVED-INI");
exit(array_column($f, "line") === [3] ? 0 : 1);' "$TMP/ini.json" || fail "removed INI: only ini_set() must be reported"
echo "scanner heuristics: ok"

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

# Libraries committed into a repo without Composer: found with their versions and checked against the target
php "$DIR/scripts/detect.php" "$DIR/fixtures/plain-app" > "$TMP/plain-profile.json"
grep -q '"source": "plugin.php (Requires PHP)"' "$TMP/plain-profile.json" || fail "WordPress Requires PHP header not used as a PHP hint"
grep -q '"path": "includes/lib/vendor"' "$TMP/plain-profile.json" || fail "committed Composer install not listed"
if [ -f "$DIR/tools/vendor/autoload.php" ]; then
  php "$DIR/scripts/check_libs.php" "$DIR/fixtures/plain-app" --offline > "$TMP/plain-libs.json"
  php -r '$p = array_column(json_decode(file_get_contents($argv[1]), true)["packages"], "status", "name");
  exit($p === ["example/old-pdf" => "tidak-didukung", "example/new-mail" => "aman"] ? 0 : 1);' "$TMP/plain-libs.json" || fail "bundled packages not checked: $(head -c 400 "$TMP/plain-libs.json")"
fi
echo "bundled libraries: ok"

# Library status: an open constraint alone is not a reason to check by hand; a pre-PHP-8.0 release is
if [ -f "$DIR/tools/vendor/autoload.php" ]; then
  mkdir -p "$TMP/libs"
  echo '{"require":{"php":">=8.1"}}' > "$TMP/libs/composer.json"
  cat > "$TMP/libs/composer.lock" <<'JSON'
{"packages":[
 {"name":"acme/modern","version":"2.1.0","require":{"php":">=8.1"},"time":"2025-06-01T00:00:00+00:00","notification-url":"https://packagist.org/downloads/"},
 {"name":"acme/ancient","version":"1.0.0","require":{"php":">=5.3"},"time":"2015-01-01T00:00:00+00:00","notification-url":"https://packagist.org/downloads/"},
 {"name":"acme/old-only","version":"1.0.0","require":{"php":"^7.1"},"time":"2019-01-01T00:00:00+00:00","notification-url":"https://packagist.org/downloads/"}
],"packages-dev":[]}
JSON
  php "$DIR/scripts/check_libs.php" "$TMP/libs" --target=8.5.12 --offline > "$TMP/libs.json"
  php -r '
  $s = array_column(json_decode(file_get_contents($argv[1]), true)["packages"], "status", "name");
  $want = ["acme/modern" => "aman", "acme/ancient" => "cek-manual", "acme/old-only" => "tidak-didukung"];
  if ($s !== $want) { fwrite(STDERR, json_encode($s) . "\n"); exit(1); }' "$TMP/libs.json" || fail "library statuses are wrong"
  echo "library status: ok"
fi

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
    node "$DIR/tests/i18n_check.js" "$DIR/assets/report-template.html" > /dev/null || fail "report template I18N has duplicate or missing keys"
    sed -n '/^<script>$/,/^<\/script>$/p' "$TMP/report-$lang.html" | sed '1d;$d' > "$TMP/check.js"
    node --check "$TMP/check.js" || fail "report script does not parse ($lang)"
  fi
done

# Open every page of the report in a DOM (catches runtime errors and blank pages that a syntax check misses)
if command -v node >/dev/null 2>&1 && command -v npm >/dev/null 2>&1; then
  [ -d "$DIR/tests/node_modules/jsdom" ] || (cd "$DIR/tests" && npm install --silent --no-audit --no-fund >/dev/null 2>&1) || true
  if [ -d "$DIR/tests/node_modules/jsdom" ]; then
    for lang in en id; do
      node "$DIR/tests/page_check.js" "$TMP/report-$lang.html" --expect-link=https://wiki.php.net/rfc/bcrypt_cost_2023 || fail "a report page failed to render ($lang)"
    done
  else
    echo "pages: skipped (could not install jsdom; run 'npm install' in tests/)"
  fi
else
  echo "pages: skipped (node/npm not found)"
fi
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

# Evidence: links a reader can check, and the proof that suggested code ran on the target PHP
EV_URL="https://github.com/php/php-src/blob/PHP-8.4/UPGRADING"
cat > "$TMP/ev-good.json" <<JSON
{"summary":"x","notes":[{"sev":"perilaku","title":"bcrypt cost","body":"b",
 "code":[{"before":"password_hash(\$p, PASSWORD_DEFAULT)","after":"password_hash(\$p, PASSWORD_DEFAULT, ['cost' => 12])",
   "proof":{"php":"8.5.12","run":"php check.php","output":"\$2y\$12\$"}}],
 "evidence":[{"title":"PHP 8.4 UPGRADING","url":"$EV_URL","kind":"php-src","quote":"increased from '10' to '12'"}]}]}
JSON
php "$DIR/scripts/validate_notes.php" "$TMP/ev-good.json" > "$TMP/ev-good.txt" || fail "valid evidence rejected: $(cat "$TMP/ev-good.txt")"
grep -q 'warning' "$TMP/ev-good.txt" && fail "valid evidence gave warnings: $(cat "$TMP/ev-good.txt")"
cat > "$TMP/ev-bad.json" <<'JSON'
{"summary":"x","notes":[{"sev":"wajib","title":"no evidence","body":"b","code":[{"before":"a","after":"b"}]},
 {"sev":"info","title":"bad link","body":"b","evidence":[{"title":"x","url":"javascript:alert(1)"}],
  "code":[{"before":"a","after":"b","proof":{"php":"latest"}}]}]}
JSON
set +e
php "$DIR/scripts/validate_notes.php" "$TMP/ev-bad.json" --json > "$TMP/ev-bad.out"; code=$?
set -e
[ "$code" = 10 ] || fail "javascript: evidence url accepted"
for w in 'notes\[0\].evidence' 'notes\[1\].evidence\[0\].url' 'notes\[1\].code\[0\].proof'; do
  grep -q "\"where\": \"$w\"" "$TMP/ev-bad.out" || fail "validator missed $w"
done
if command -v node >/dev/null 2>&1 && [ -d "$DIR/tests/node_modules/jsdom" ]; then
  EVSTORE="$TMP/ev-store"
  php "$DIR/scripts/audit.php" "$DIR/fixtures/legacy-app" --out="$EVSTORE" --name=ev --skip=rector,phpcompat,libs --offline > /dev/null 2>&1
  php -r '$g = json_decode(file_get_contents($argv[1]), true); $b = json_decode(file_get_contents($argv[2]), true);
    $g["notes"][] = $b["notes"][1]; file_put_contents($argv[3], json_encode($g));' "$TMP/ev-good.json" "$TMP/ev-bad.json" "$(ls -d "$EVSTORE"/ev/scans/*)/notes.json"
  php "$DIR/scripts/render.php" --store="$EVSTORE" --out="$TMP/ev.html" --offline 2> /dev/null
  node "$DIR/tests/page_check.js" "$TMP/ev.html" --expect-link="$EV_URL" > /dev/null || fail "evidence link missing or an unsafe link was rendered"
fi
echo "evidence: ok"

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
     && array_column($d["unresolved"], "missing", "unit") === ["App\\Model\\Optional" => "acme/missing", "App\\Model\\Plugin" => "Vendor\\Sdk\\BasePlugin"]
     && count($d["deprecations"]) === 1 && str_contains($d["deprecations"][0]["message"], "Counter::count()")
     && str_contains($d["library_deprecations"][0]["message"] ?? "", "OldApi") && $d["loaded"] === 4;
  if (!$ok) { fwrite(STDERR, json_encode($d, JSON_PRETTY_PRINT)); exit(1); }' "$TMP/rt.json" || fail "composer mode did not classify the fixture classes"
  [ -d "$DIR/fixtures/composer-app/vendor" ] && fail "load_classes.php installed into the audited repo"
  echo "load classes (composer): ok"
else
  echo "load classes (composer): skipped (composer not installed)"
fi
CHECK_FILES='
$d = json_decode(file_get_contents($argv[1]), true);
$skipped = array_column($d["skipped"], "file"); sort($skipped);
$ok = $d["mode"] === $argv[2] && $skipped === ["index.php", "plugin.php", "tests/stubs.php"]
   && array_column($d["failed"], "type", "unit") === ["includes/class-widget.php" => "signature"]
   && array_column($d["unresolved"], "missing", "unit") === ["includes/class-loader.php" => "constant PLAIN_DIR", "includes/class-rest.php" => "WP_REST_Controller"]
   && $d["loaded"] === 2;
if (!$ok) { fwrite(STDERR, json_encode($d, JSON_PRETTY_PRINT)); exit(1); }'
php "$DIR/scripts/load_classes.php" "$DIR/fixtures/plain-app" --work="$TMP/work" > "$TMP/rt-files.json"
php -r "$CHECK_FILES" "$TMP/rt-files.json" files || fail "files mode did not classify the fixture files"
# composer.json without "autoload" (common in WordPress plugins and CodeIgniter 3): same result through the files mode
if command -v composer > /dev/null 2>&1; then
  cp -r "$DIR/fixtures/plain-app" "$TMP/plain-composer"
  echo '{"name":"example/plain","require":{"php":">=7.2"}}' > "$TMP/plain-composer/composer.json"
  php "$DIR/scripts/load_classes.php" "$TMP/plain-composer" --work="$TMP/work" --offline > "$TMP/rt-pc.json"
  php -r "$CHECK_FILES" "$TMP/rt-pc.json" composer+files || fail "composer.json without autoload did not fall back to the files mode"
fi
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
