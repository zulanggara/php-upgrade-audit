# Changelog

## 1.2.0 (2026-10-23)

The default target follows the newest PHP release, every rule cites php-src, and libraries are checked for
known vulnerabilities.

### Added

- `--target=latest` (still the default) asks php.net for the newest release of the newest PHP minor the rules
  cover, and a bare minor (`8.3`) means its newest release. Offline or without an answer it is 8.5.12; when a
  newer minor than the rules cover is out, the audit says so.
- `--php=docker` (`docker:8.3`, `docker:<image>`): `php -l`, the scanner and class loading run in the official
  `php:<target>-cli` image, one container per audit, no network, the repo read-only, removed at the end.
- Security advisories: every public package in `composer.lock` is checked against the Packagist security
  advisories API (GitHub Advisory Database, FriendsOfPHP; the data `composer audit` uses). Advisories that
  affect the installed version are listed with GHSA and CVE links and the lowest fixed version that supports
  the target, count as must-fix, and get their own table in the report.
- `scripts/rules_evidence.php`: all 81 scanner rules cite the php-src `UPGRADING` entry of the release that
  made the change, quoted exactly as on the release branch, and the RFCs it cites. The findings table links
  them, with the quote on hover. A test fails for a rule without sources.
- `tests/check_links.php` and a weekly workflow (`links.yml`) that checks every cited URL still answers and
  every quote is still in its file, and opens a `broken-links` issue otherwise.
- SARIF 2.1.0 output for GitHub code scanning: `audit.php --sarif=<file>` and `scripts/sarif.php`.
- Library notes for Doctrine DBAL 3, PHPUnit 10, Swift Mailer (replace with symfony/mailer), phpseclib 3 and
  Laravel 9, 10 and 11, each quoting its upstream guide.
- Agent evals for `claude plugin eval` (`evals/`): default target, a named target, read-only when asked to fix,
  evidence in notes.
- `release.yml`: a new version in `plugin.json` on `main` is tagged and released with its CHANGELOG section;
  a warning when the kit changes without a new version. Issue templates for wrong findings and bugs.

### Changed

- SKILL.md and AGENTS.md: never choose a target or lower it to the installed PHP (an eval run showed an agent
  doing that), and never write a test file into the audited repo.

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
