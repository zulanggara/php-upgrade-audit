# Adding or fixing scanner rules

Rules live in `scripts/rules.php` (Indonesian title/fix) and `scripts/rules_en.php` (English title/fix, keyed by
rule id). One entry is one kind of problem. Required fields: `id`, `sev`, `ver`, `conf`, `kind`, `title`, `fix`,
`ref`. Optional `rector` names the Rector rule that fixes it; the report uses it for the "Rector" column. Optional
`evidence` lists sources beyond `ref` (`[['kind' => 'rfc', 'title' => ..., 'url' => ...]]`, kinds and rules in
[`evidence.md`](evidence.md)); the report links them next to php.net.

- Take the content from the official pages
  `https://www.php.net/manual/en/migrationXY.incompatible.php` / `.deprecated.php`, not from memory.
- `kind` values and their parameters are documented at the top of `rules.php`. Checks that need custom logic
  (`kind => special`) live in `scripts/scan_code.php` (`check` is the name passed to `special()`).
- Be honest with `conf`. Generic method names (`->attach(`) and heuristics are always `rendah` (low) or
  `sedang` (medium).
- Add the English title and fix to `rules_en.php`; `tests/run.sh` fails when a rule has no translation.
- `ver` is the PHP version that introduced the change (e.g. `8.4`, or `8.0/8.4` when it was extended later).
  The scanner skips rules newer than `--target`, starting from the earliest version named.

After changing rules:

1. Add an example to `fixtures/legacy-app/src/` (that file is never executed).
2. Add the rule id to the list in `tests/run.sh` and run `bash tests/run.sh`.
3. For runtime or compile-time deprecations, prove it on the target PHP: put a small file in a scratch folder
   and run `php -d error_reporting=-1 file.php`.
4. Run the scanner on a real project that already supports PHP 8 to measure false positives.

## Null passed to built-in functions (`P81-NULL-TO-INTERNAL`)

- The parameter list is `scripts/null_params.php`: for every internal function, constructor (`new:class`) and
  static method (`class::method`), the 0-based positions of scalar parameters that reject `null`. Regenerate it
  on the newest PHP with the php-src stubs of that release (instructions at the top of
  `tests/gen_null_params.php`); the scanner also adds what reflection finds on the PHP that runs it.
- Sources of a possible `null` are listed in `nullableExpr()` in `scripts/scan_code.php`. Add one only when the
  framework's own code or documentation says it returns `null` for a missing value, and add a `// FIND` line and
  an `// ok` counterpart (with a default, a cast, a check) to `fixtures/null-args/`.
- `tests/check_null_args.php` requires the findings to match the `// FIND` markers exactly: every marked line,
  no line marked `// ok`, no unmarked line. Before adding a marker, run the line with a stand-in that returns
  `null` on the target PHP (`php -d error_reporting=-1`) and check that it emits the deprecation.
- Values from a database or another runtime source cannot be seen statically. Runtime logs cover them:
  `scripts/scan_logs.php` maps log messages to rules in `log_rule()`; when a new rule has a runtime message, add
  its pattern there, with the message copied from what the target PHP prints.

## Redaction

`scripts/redact.php` hides secrets in everything the tools write and in the rendered report. When you add a
pattern, add both a must-hide and a must-keep case to `tests/redact_test.php`, and build token-like test
strings at runtime (see the existing tests) so the repo itself never contains secret-looking strings.
