# php-upgrade-audit

**English** · [Bahasa Indonesia](README.id.md)

A read-only audit kit for moving PHP 7.x codebases to PHP 8.x (default target **8.5.12**). It is built to be
driven by an AI coding agent (Claude Code skill, or any agent via `AGENTS.md`), but every step is a plain PHP
script you can run yourself.

The output is standardised: JSON per tool, analyst notes in a fixed format, and one HTML report with a page
per repository, a version history of every scan, and a dashboard.

## What it checks

| Area | How |
|---|---|
| Removed, deprecated and behaviour-changing functions, syntax and helpers (PHP 7.1 → 8.5) | Tokenizer-based scanner, ~80 rules taken from the official php.net migration guides |
| Code guarded by version checks | `if (PHP_VERSION_ID < 80000)`, `is_php()`, `function_exists()`… are evaluated against the target, so dead legacy branches are not counted |
| Parse errors and compile-time deprecations | `php -l` on every file with the target PHP |
| Composer libraries | `php` constraint of every package in `composer.lock` vs the target, abandoned packages, latest version in the same major, latest version supporting the target (Packagist) |
| PHP extensions | Extensions required by packages that were removed from core (mcrypt, imap, …) |
| Cross-checks | PHPCompatibility (phpcs) and Rector `--dry-run` (shows what can be fixed automatically) |
| Framework switch or merge (optional) | Local patches vs the official release, removed/changed API, override conflicts with the app (PHP signature rules), calls to removed API, config keys |

It never modifies the audited code and never runs `composer` or Rector in it.

## Report

- One self-contained HTML file. Works as a [Claude Artifact](https://claude.ai) or as a local page.
- Docs-style layout: sidebar menu, one page per repo and per comparison, dashboard with the scan goal.
- **History:** every scan is kept. Each repo page has a version selector (latest by default) and shows how many
  findings were resolved, are new, or unchanged since the previous scan.
- Every page has an anchor: `#<repo>` (latest scan) or `#<repo>~<scan-id>` (a specific scan).
- Analyst notes with **Current vs Suggested** code, which the agent must run on the target PHP before
  suggesting it.
- English or Indonesian (`--lang=en|id`), light and dark theme, phone layout.

## Privacy and network

- **Secrets are redacted** in every tool output and again on the whole report (tokens such as `ghp_…`,
  `glpat-…`, AWS/Google/Stripe/Slack keys, JWTs, private keys, `user:password@` in URLs, `password => '…'`,
  `$secret = '…'`, `DB_PASSWORD=…`, long random literals). They show up as `«REDACTED»`.
- Local absolute paths are removed from the report.
- The only network call during an audit is to `repo.packagist.org`, with public package names only. Private
  packages are skipped. `--offline` disables it.
- The HTML loads IBM Plex from Google Fonts. Render with `--offline` for a page with no external request at all.
- No telemetry.

## Requirements

- PHP **8.1+** to run the scripts; for real proof, the **target** PHP version (e.g. 8.5.12) as `php`.
- Composer (to install the analysis tools once), git, `diff`.
- Optional: Node.js is not needed. A browser to read the report.

## Install

```bash
git clone https://github.com/zulanggara/php-upgrade-audit.git
cd php-upgrade-audit
bash scripts/setup.sh        # installs Rector, PHP_CodeSniffer + PHPCompatibility, composer/semver into tools/
```

### Claude Code

Clone into your skills folder so Claude can load `SKILL.md`:

```bash
git clone https://github.com/zulanggara/php-upgrade-audit.git ~/.claude/skills/php-upgrade-audit
bash ~/.claude/skills/php-upgrade-audit/scripts/setup.sh
```

Then ask, for example: *"audit /path/to/repo for PHP 8.5.12"*.

### Other AI coding agents

Clone anywhere and point the agent at `AGENTS.md`, for example:

- **Codex / Gemini CLI / Aider / Cline / Windsurf:** add to your project instructions: *"For PHP upgrade audits
  follow `/path/to/php-upgrade-audit/AGENTS.md`."*
- **Cursor:** add a project rule that references `AGENTS.md`.
- **GitHub Copilot:** reference it from `.github/copilot-instructions.md`.

## Use it by hand

```bash
STORE=~/php-audit-store

# 1. scan (run again later: a new version is added, nothing is overwritten)
php scripts/audit.php /path/to/repo --out=$STORE --name=my-app --target=8.5.12

# 2. optional: framework switch
php scripts/compare_framework.php --old=/path/old/system --new=/path/new/system --app=/path/to/repo \
    --name="Framework merge" --store=$STORE

# 3. write $STORE/my-app/scans/<scan-id>/notes.json and $STORE/overview.json (see AGENTS.md)

# 4. render and open
php scripts/render.php --store=$STORE --out=$STORE/report.html --lang=en --offline
php -S 127.0.0.1:8085 -t $STORE     # then open http://127.0.0.1:8085/report.html
```

See [`examples/sample-report.html`](examples/sample-report.html) for a report generated from the bundled
fixture (`fixtures/legacy-app`, deliberately broken PHP 7 code).

## Store layout

```
<store>/
  overview.json                     cross-repo summary (older versions kept in _overview/)
  <repo>/index.json                 list of scans
  <repo>/scans/<scan-id>/           report.json, scan.json, lint.json, libs.json, phpcompat.json, rector.json, notes.json
  <comparison>/scans/<scan-id>/     compare.json, notes.json, diffs/
  .cache/                           Packagist metadata and Rector cache
```

Scan ids are UTC timestamps (`20261022T131200Z`), so folders sort chronologically.

## Severity

| Key | Meaning |
|---|---|
| `wajib` | Must fix: error/fatal, or the code no longer runs as before |
| `deprecated` | Still runs on the target but emits `E_DEPRECATED`; breaks in PHP 9 |
| `perilaku` | Behaviour change: no error, the result can differ. Needs review |
| `info` | Note, not urgent |

## Limits

- Static analysis cannot see runtime values (e.g. `null` passed to built-in functions). The report lists what
  to watch for while running on the target PHP with `E_ALL`.
- The rule catalogue covers PHP 7.1 → 8.5 as published on php.net. Update it when a new PHP version is
  released (`references/rules.md`).
- Class-loading proof (`scripts/adapters/codeigniter3/link_app.php`) is CodeIgniter 3 specific; other frameworks need their own loader.
- PHPCompatibility 10 is still alpha; the scanner and `php -l` do not depend on it.

## Repository layout

```
SKILL.md            Claude Code skill
AGENTS.md           instructions for any AI coding agent
scripts/            audit, scanner, lint, libs, tools, compare, history, redaction, render
assets/             report template
references/         rules guide, CodeIgniter 3 notes
fixtures/           sample broken PHP 7 project used by the tests
examples/           sample report
tests/              redaction and scanner tests
tools/composer.json analysis tools (installed by setup.sh, vendor/ is not committed)
```

## License

MIT, see [LICENSE](LICENSE). The analysis tools installed by `setup.sh` keep their own licenses
(Rector MIT, PHP_CodeSniffer BSD-3-Clause, PHPCompatibility LGPL-3.0, composer/semver MIT).
