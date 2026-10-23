# Changelog

## 1.1.0 (2026-10-23)

Every finding can now be checked at its source, and installed copies keep themselves up to date.

### Added

- Evidence in `notes.json`: `evidence` (kind, title, url, optional quote) on every note and `proof` (PHP
  version, command, output) on every Current/Suggested code pair. The report links each source and shows the
  run under the code. `validate_notes.php` warns when a must-fix, deprecated or behaviour note has no evidence,
  and refuses links that are not `http(s)`. Checked sources and how to cite them: `references/evidence.md`.
- `evidence` on scanner rules, linked next to the php.net reference in the findings table.
- Rule `P84-BCRYPT-COST`: `password_hash()` / `password_needs_rehash()` with the default bcrypt cost, which
  goes from 10 to 12 in PHP 8.4 (about 4 times slower per hash; old hashes need a rehash).
- Library upgrade notes with their upstream sources (`scripts/library_notes.php`): firebase/php-jwt 7.0
  (HMAC keys shorter than the algorithm are rejected; run on PHP 8.5.12), Guzzle 7, Monolog 2 and 3.
  `libs.json` also links each public package to Packagist, its source repository and the release to move to.
- `scripts/update.sh`, run by SKILL.md and AGENTS.md at the start of every audit (at most once a day): a git
  clone fast-forwards to the published version when it has no local changes; a Claude Code plugin is updated
  with `claude plugin update`, or the command is printed. `PHP_UPGRADE_AUDIT_NO_UPDATE=1` or `--offline`
  turns it off.
- The kit version and commit in `report.json` and the report footer.

### Fixed

- `validate_notes.php` checked `overview.en.json` / `overview.id.json` as notes files.
- `tests/page_check.js` fails on any link that is not `http(s)` or an anchor.

## 1.0.0 (2026-10-22)

First release meant for use by other people and agents, on any kind of PHP project.

### Added

- `load_classes.php` and `audit.php --load-classes`: runtime proof that every application class links on the
  target PHP, in a scratch copy (Composer autoload; plain files for projects without Composer or without
  `autoload`; adapter mode for CodeIgniter 3). Signature conflicts, parse and fatal errors are "failed";
  missing dependencies and bootstrap needs are "unresolved"; library deprecations are kept apart from PHP's.
  `--vendor-only` replaces `load_vendor.php`.
- `validate_notes.php`, also run by `render.php`: checks `notes.json` / `overview.json` against the format.
- `--help` on every script; exit codes 0 / 1 (usage) / 2 (environment) / 10 (findings); `audit.php --fail-on`
  for CI, a findings summary per level, a warning when the PHP running the proof is not the target, `--php`,
  `--memory`, step timings and tool peak memory.
- `--target` for any PHP version (`8.3.30`, `latest`, ...): rules introduced after the target are not
  reported, and removed extensions only count once the target has dropped them.
- Framework adapters (`scripts/adapters/`); the CodeIgniter 3 specifics of `compare_framework.php` and the
  former `ci3_link_app.php` moved into `adapters/codeigniter3/`, with identical results.
- Libraries committed into the repo: bundled Composer installs (with versions, checked against the target),
  nested `composer.json`, PEAR, WordPress plugins/themes; WordPress `Requires PHP` as a version hint.
- Claude Code plugin and marketplace manifests; `/php-upgrade-audit` takes repos and options as arguments.
- GitHub Actions on PHP 8.1 to 8.5; sample reports in English and Indonesian (`examples/build.sh`); README
  sections on using the kit from any AI agent, with or without installing it first; CONTRIBUTING, SECURITY.

### Fixed

- The overview page crashed when a store had `overview.json` (duplicate I18N key).
- Local absolute paths could reach the report through tool errors or notes.
- `audit.php` created the output folder inside the audited repo before refusing it.
- PHPCompatibility silently did not run when Composer skipped its plugins (root, CI).
- `function_exists()` / `class_exists()` guards were evaluated with the PHP running the scan instead of the
  target.
- False positives measured on public projects: `SplObjectStorage::contains()` on collections, `is_resource()` on
  file handles, reading removed INI settings, `== 0` against numeric built-ins, and "check by hand" for every
  library with an open `php` constraint.
- `detect.php` no longer needs `GLOB_BRACE`; local patch diffs keep a space after `<?php`.

## 0.1.0

Initial release: scanner, `php -l`, library check, PHPCompatibility and Rector dry-run, framework comparison,
scan history, redaction, HTML report in English and Indonesian.
