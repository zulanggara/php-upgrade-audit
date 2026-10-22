# Changelog

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
