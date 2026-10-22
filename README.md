# php-upgrade-audit

[![tests](https://github.com/zulanggara/php-upgrade-audit/actions/workflows/tests.yml/badge.svg)](https://github.com/zulanggara/php-upgrade-audit/actions/workflows/tests.yml)

**English** · [Bahasa Indonesia](README.id.md)

A read-only audit kit for moving a PHP codebase to a newer PHP 8 version: PHP 7.x or older 8.x, with Laravel,
Symfony, WordPress, CodeIgniter or no framework. The default target is the newest version the rules cover
(**8.5.12**); any other target works too, for example `--target=8.3.30`.

It is built to be driven by an AI coding agent (a Claude Code skill or plugin, or any agent through
`AGENTS.md`), but every step is a plain PHP script you can run yourself or in CI.

The output is standardised: JSON per tool, analyst notes in a fixed format, and one HTML report with a page
per repository, a version history of every scan, and a dashboard.

- [Use it with an AI agent](#use-it-with-an-ai-agent): example prompts, with or without installing first
- [Install](#install): Claude Code skill or plugin, other agents, by hand
- [Use it by hand](#use-it-by-hand) · [CI](#ci) · [Choosing the target](#choosing-the-target-php)
- [What it checks](#what-it-checks) · [Report](#report) · [Privacy](#privacy-and-network) · [Limits](#limits)

## Use it with an AI agent

Any agent that can run shell commands works (Claude Code, Codex, Cursor, Gemini CLI, Copilot agent mode,
Cline, Aider, Windsurf...). Say which repo, which PHP version, and which language the report should be in.

### 1. Nothing installed yet

Give the agent the repository link; it clones the kit, reads the instructions and runs it:

> Use the PHP upgrade audit kit from https://github.com/zulanggara/php-upgrade-audit: clone it into
> `~/tools/php-upgrade-audit`, run `scripts/setup.sh`, then follow its `AGENTS.md` to audit `~/src/my-shop`
> for PHP 8.5.12. Write the report in English and keep the store in `~/php-audit-store`.

Shorter, with a specific target:

> With the skill at https://github.com/zulanggara/php-upgrade-audit, analyse the repo `~/src/legacy-api`:
> is it ready for PHP 8.3.30? Report in Indonesian.

### 2. Already cloned by hand

Point the agent at the instructions file:

> Follow `~/tools/php-upgrade-audit/AGENTS.md` and audit `~/src/my-shop` and `~/src/admin` for PHP 8.4.
> Load every class on the target PHP, write notes with suggested code, and render the report.

For agents that read project rules automatically, add one line to your project's instructions file
(`AGENTS.md` for Codex, `.cursor/rules` for Cursor, `.github/copilot-instructions.md` for Copilot,
`GEMINI.md` for Gemini CLI, `CONVENTIONS.md` for Aider, `.clinerules` for Cline):

```text
For PHP upgrade audits follow ~/tools/php-upgrade-audit/AGENTS.md (read-only; never modify the audited repo).
```

### 3. Installed as a Claude Code skill or plugin

Type the command, with the repo(s) and options as arguments:

```text
/php-upgrade-audit ~/src/my-shop                                  # latest target (8.5.12), your language
/php-upgrade-audit ~/src/my-shop ~/src/admin --target=8.3.30 --lang=id
/php-upgrade-audit ~/src/legacy --target=8.2 --store=~/audits --name=legacy
```

As a plugin the command is namespaced: `/php-upgrade-audit:php-upgrade-audit ~/src/my-shop --target=8.4`.
Plain requests work too, Claude picks the skill by itself:

> audit ~/src/my-shop for PHP 8.3 and tell me which libraries block the upgrade

### Asking for a specific PHP version

The target is whatever you name: `8.1`, `8.3.30`, `8.4.27`, `8.5.12`, or `latest`. Changes introduced after
the target are not reported (an audit for 8.3 shows no 8.4/8.5 deprecations), and Rector and
PHPCompatibility check against the target's minor version. For the runtime proof (`php -l`, class loading)
the agent needs that PHP version installed; it tells you when it is missing (see
[Choosing the target](#choosing-the-target-php)).

## Install

Requirements: PHP **8.1+** to run the scripts, Composer (once, to install the analysis tools), git, `diff`.
Linux and macOS are supported; on Windows use WSL (native Windows is not tested). For real proof you also need
the **target** PHP version.

### Claude Code: personal skill (`/php-upgrade-audit`)

```bash
git clone https://github.com/zulanggara/php-upgrade-audit.git ~/.claude/skills/php-upgrade-audit
bash ~/.claude/skills/php-upgrade-audit/scripts/setup.sh
```

For one project only, clone into `<project>/.claude/skills/php-upgrade-audit` instead. Update with `git pull`.

### Claude Code: plugin (`/php-upgrade-audit:php-upgrade-audit`)

```text
/plugin install php-upgrade-audit --marketplace zulanggara/php-upgrade-audit
```

On older Claude Code versions, in two steps:

```text
/plugin marketplace add zulanggara/php-upgrade-audit
/plugin install php-upgrade-audit@php-upgrade-audit
```

The analysis tools are not part of the plugin download; the skill runs `scripts/setup.sh` on first use (and
after a plugin update).

### Other AI agents, or by hand

```bash
git clone https://github.com/zulanggara/php-upgrade-audit.git ~/tools/php-upgrade-audit
bash ~/tools/php-upgrade-audit/scripts/setup.sh   # installs Rector, PHP_CodeSniffer + PHPCompatibility, composer/semver into tools/
```

Then point your agent at `AGENTS.md` (see [above](#2-already-cloned-by-hand)). `setup.sh` checks that the tools
run and prints the next commands.

## Use it by hand

```bash
TOOL=~/tools/php-upgrade-audit
STORE=~/php-audit-store

# 1. scan (run again later: a new version is added, nothing is overwritten)
php $TOOL/scripts/audit.php /path/to/repo --out=$STORE --name=my-app --target=8.5.12 --load-classes

# 2. optional: framework switch or merge
php $TOOL/scripts/compare_framework.php --old=/path/old/system --new=/path/new/system --app=/path/to/repo \
    --name="Framework merge" --store=$STORE

# 3. write $STORE/my-app/scans/<scan-id>/notes.json and $STORE/overview.json (format in AGENTS.md), check them
php $TOOL/scripts/validate_notes.php --store=$STORE

# 4. render and open
php $TOOL/scripts/render.php --store=$STORE --out=$STORE/report.html --lang=en --offline
php -S 127.0.0.1:8085 -t $STORE     # then open http://127.0.0.1:8085/report.html
```

`php scripts/audit.php --help` explains every option and the whole flow. Every script answers `--help`.

See [`examples/sample-report.html`](examples/sample-report.html) (English) and
[`examples/sample-report.id.html`](examples/sample-report.id.html) (Indonesian): two scans of the bundled
fixture (`fixtures/legacy-app`, deliberately broken PHP 7 code) with analyst notes. Download and open them in
a browser.

## CI

```bash
php scripts/audit.php . --out="$RUNNER_TEMP/php-audit" --target=8.4 --skip=rector --fail-on=wajib
```

Exit codes of every script: `0` ok, `1` wrong usage, `2` environment problem (e.g. tools not installed), `10`
findings at the `--fail-on` level or above (`wajib` > `deprecated` > `perilaku`). The end of the output
summarises the findings per level.

## Choosing the target PHP

- `--target` decides **what is checked**: rules up to that version, Rector `UP_TO_PHP_xx`, PHPCompatibility
  `testVersion`, Composer constraints.
- The PHP that **runs** `php -l` and class loading decides **what is proven**. Use the target version as `php`,
  or pass `--php=/path/to/php8.3`. `audit.php` warns when they differ.
- No target PHP installed? Homebrew `php@8.3`, your distribution's packages (`php8.3-cli`), or Docker:
  `docker run --rm -v "$PWD:$PWD" -w "$PWD" php:8.3-cli php ...` (the analysis tools then need Composer in
  the image, e.g. `composer:2` copied in).
- A target newer than the rule catalogue (e.g. 8.6 before the rules are updated) is accepted with a warning:
  changes made in that version are not checked by the scanner.

## What it checks

| Area | How |
|---|---|
| Removed, deprecated and behaviour-changing functions, syntax and helpers (PHP 7.1 → 8.5) | Tokenizer-based scanner, ~80 rules taken from the official php.net migration guides, filtered by the target |
| Code guarded by version checks | `if (PHP_VERSION_ID < 80000)`, `is_php()`, `function_exists()`… are evaluated against the target, so dead legacy branches are not counted |
| Parse errors and compile-time deprecations | `php -l` on every file with the target PHP |
| Classes that no longer load (`--load-classes`) | Every class of the app loaded on the target PHP in a scratch copy: signature conflicts (fatal since 8.0), missing parents, deprecations while linking. Composer autoload, or plain files for projects without Composer |
| Composer libraries | `php` constraint of every package in `composer.lock` vs the target, abandoned packages, latest version in the same major, latest version supporting the target (Packagist) |
| PHP extensions | Extensions required by packages that were removed from core by the target (mcrypt, imap, …) |
| Cross-checks | PHPCompatibility (phpcs) and Rector `--dry-run` (shows what can be fixed automatically) |
| Framework switch or merge (optional) | Local patches vs the official release, removed/changed API, override conflicts with the app (PHP signature rules), calls to removed API, config keys. Framework adapters add specific checks ([`scripts/adapters/`](scripts/adapters/), CodeIgniter 3 bundled) |

It never modifies the audited code and never runs `composer` or Rector in it.

## Report

- One self-contained HTML file. Works as a [Claude Artifact](https://claude.ai) or as a local page.
- Docs-style layout: sidebar menu, one page per repo and per comparison, dashboard with the scan goal.
- **History:** every scan is kept. Each repo page has a version selector (latest by default) and shows how many
  findings were resolved, are new, or unchanged since the previous scan.
- Every page has an anchor: `#<repo>` (latest scan) or `#<repo>~<scan-id>` (a specific scan).
- Analyst notes with **Current vs Suggested** code, which the agent must run on the target PHP before
  suggesting it. `validate_notes.php` checks their format.
- English or Indonesian (`--lang=en|id`), light and dark theme, phone layout.

## Privacy and network

- **Secrets are redacted** in every tool output and again on the whole report (tokens such as `ghp_…`,
  `glpat-…`, AWS/Google/Stripe/Slack keys, JWTs, private keys, `user:password@` in URLs, `password => '…'`,
  `$secret = '…'`, `DB_PASSWORD=…`, long random literals). They show up as `«REDACTED»`.
- Local absolute paths (home folders, temp folders, the store, this tool's folder) are removed from the report.
- Network during an audit: public package names to `repo.packagist.org` (private packages are skipped) and,
  with `--load-classes`, a normal `composer install` of the locked packages in a scratch copy (from wherever
  `composer.lock` points, with your own Composer credentials). `--offline` disables both.
- The HTML loads IBM Plex from Google Fonts. Render with `--offline` for a page with no external request at all.
- No telemetry. Reports can describe security problems of the audited code: share them with care.

## Store layout

```
<store>/
  overview.json                     cross-repo summary (older versions kept in _overview/)
  <repo>/index.json                 list of scans
  <repo>/scans/<scan-id>/           report.json, scan.json, lint.json, libs.json, runtime.json, phpcompat.json, rector.json, notes.json
  <comparison>/scans/<scan-id>/     compare.json, notes.json, diffs/
  .cache/                           Packagist metadata, Rector cache, scratch copies for class loading
```

Scan ids are UTC timestamps (`20261022T131200Z`), so folders sort chronologically.

## Severity

| Key | Meaning |
|---|---|
| `wajib` | Must fix: error/fatal, or the code no longer runs as before |
| `deprecated` | Still runs on the target but emits `E_DEPRECATED`; breaks in a later version |
| `perilaku` | Behaviour change: no error, the result can differ. Needs review |
| `info` | Note, not urgent |

The keys are the same in both report languages.

## Limits

Tested on these public projects with PHP 8.5.12 (`audit.php --load-classes`, defaults otherwise). Times are
for the whole audit; Composer installs ran through git in the test environment, so a normal install is faster.
False positives: 15 random scanner findings per project, read by hand, before and after the fixes they led to.

| Project type | Project (commit) | PHP files / lines | Time | Rector peak memory | Findings: must fix / deprecated / behaviour | Class loading on 8.5.12 | False positives |
|---|---|---|---|---|---|---|---|
| Composer + PSR-4 (Symfony) | symfony/demo (8d2e2ef) | 51 / 4,680 | 29 s | 142 MB | 0 / 0 / 0 | 33 of 34 loaded, 0 failed, 1 unresolved (a require-dev class) | 2 of 2 before, none left |
| WordPress plugin, composer.json without lock or autoload | WordPress/wordpress-importer (2fd6589) | 239 / 52,985 | 35 s | 262 MB | 0 / 2 / 9 | 164 of 215 loaded, 0 failed, 51 unresolved (PHPUnit, WordPress core), 24 skipped | 7 of 15 before, 2 of 11 after (18%) |
| Legacy, no framework, no Composer | osTicket (8d38b06) | 717 / 181,252 | 296 s (Rector 212 s) | 1,333 MB | 57 / 150 / 144 | 210 of 287 loaded, 0 failed, 77 unresolved (bootstrap includes and constants), 430 skipped, 27 deprecations; 19 bundled packages checked | 6 of 15 before, 3 of 15 after (20%) |
| CodeIgniter 3 | pocketarc/codeigniter (795dc3f) | 292 / 79,346 | 39 s | 275 MB | 113 / 17 / 30 (+34 in branches that do not run on 8.5) | 139 of 228 loaded, 0 failed, 89 unresolved (PHPUnit, `CI_DB`), 8 deprecations | 7 of 15 before, 1 of 15 after (7%) |

- Most remaining false positives are loose comparisons (`== 0`) on values that are integers at runtime and
  code in branches that only run on old PHP; finding them needs data flow analysis, which the scanner does not
  do. Findings marked low confidence deserve a look before they go into notes.
- In CodeIgniter 3, 11 of the 15 sampled findings were correct but sat in database drivers the application may
  not use (`mysql`, `mssql`, `ibase`, `oci8`); see `references/ci3.md`.
- Laravel was not tested as a separate project; it is a Composer + PSR-4 project like symfony/demo. Drupal,
  Magento and other frameworks were not tested.
- Rector needs about 1.3 GB on a 180k-line codebase; the default `--memory=2G` was enough. Raise it for larger
  code bases, or use `--skip=rector`.

- Static analysis cannot see runtime values (e.g. `null` passed to built-in functions). The report lists what
  to watch for while running on the target PHP with `E_ALL`.
- Class loading proves that classes **link** on the target PHP, not that the code behaves the same; it does
  not execute methods. Files that run code when included (entry scripts, templates) are skipped, not loaded, and
  every class file is loaded, also one the application only picks on an older PHP version.
- The rule catalogue covers PHP 7.1 → 8.5 as published on php.net. Update it when a new PHP version is
  released (`references/rules.md`).
- PHPCompatibility 10 is still alpha; the scanner, `php -l` and class loading do not depend on it.
- Supported: Linux, macOS, WSL. Native Windows is not tested (the scripts use `diff`, `git` and POSIX paths).

## Repository layout

```
SKILL.md              Claude Code skill (also the plugin's only skill)
AGENTS.md             instructions for any AI coding agent
.claude-plugin/       Claude Code plugin + marketplace manifests
scripts/              audit, scanner, lint, libs, class loading, tools, compare, history, redaction, validation, render
scripts/adapters/     optional framework adapters (codeigniter3)
assets/               report template
references/           rules guide, CodeIgniter 3 notes
fixtures/             sample projects used by the tests (broken PHP 7 code, Composer app, plain app, framework)
examples/             sample reports
tests/                run.sh (end-to-end) and redaction tests
tools/composer.json   analysis tools (installed by setup.sh, vendor/ is not committed)
```

## Contributing, security, license

- [CONTRIBUTING.md](CONTRIBUTING.md): adding rules, adapters and tests; redaction rules.
- [SECURITY.md](SECURITY.md): how to report a secret that the redaction missed.
- [CHANGELOG.md](CHANGELOG.md).
- MIT, see [LICENSE](LICENSE). The analysis tools installed by `setup.sh` keep their own licenses
  (Rector MIT, PHP_CodeSniffer BSD-3-Clause, PHPCompatibility LGPL-3.0, composer/semver MIT).
