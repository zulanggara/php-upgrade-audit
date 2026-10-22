---
name: php-upgrade-audit
description: Read-only audit of how ready a PHP codebase (7.x or older 8.x; Laravel, Symfony, WordPress, CodeIgniter or plain PHP) is for a newer PHP 8 version, by default the latest covered (8.5.12) or any target such as 8.3.30. Finds removed, deprecated and behaviour-changing functions, syntax and helpers from PHP 7.1 to 8.5, proves it with php -l and by loading every class on the target PHP, checks Composer libraries (php constraint, abandoned packages, versions that support the target) and PHP extensions, compares framework versions, and produces a standardised HTML report with one page per repo and a full scan history. Never modifies the audited repo; secrets are redacted. Use when asked to check PHP 8.x compatibility, whether libraries support a PHP version, to audit before a PHP upgrade, or to assess a framework upgrade.
argument-hint: "<repo>... [--target=8.5.12] [--lang=en|id] [--store=<dir>] [--name=<label>]"
---

# PHP Upgrade Audit

A **read-only** audit. The result is notes and a report, never code changes. Modernisation work (strict types,
higher PHPStan levels, using new 8.4/8.5 features) is out of scope.

The same workflow is described for other AI coding agents in `AGENTS.md`; keep both in sync.

## Arguments

When invoked as a command, `$ARGUMENTS` holds what the user typed after it, for example
`/php-upgrade-audit ~/src/shop ~/src/api --target=8.3.30 --lang=id`. Read it as:

- every argument that is not an option: a repo to audit (several are fine, each becomes a page);
- `--target=<version>`: the PHP version to move to. Without it use the version the user names in their
  message, otherwise the default (`latest`, the newest version the rules cover: 8.5.12);
- `--lang=en|id`: report language; without it, the language the user writes in;
- `--store=<dir>`: the history store; default `~/php-audit-store` (keep it outside every repo; do not use a
  temporary folder, the history must survive the session);
- `--name=<label>`: page name when auditing a single repo.

With no arguments, ask which repo(s) to audit and to which PHP version, then continue.

## Hard rules

- Never write anything into the audited repo. Output, tool config and caches go to the store. `audit.php`
  refuses an output folder inside the repo.
- Never run `composer install/update` in the repo. `load_classes.php` installs in a scratch copy.
- Rector only runs with `--dry-run` (enforced by `run_tools.php`).
- Private packages (no Packagist `notification-url`) are never sent to Packagist. Use `--offline` to make no
  network request at all (`--load-classes` then skips the scratch install).
- Do not conclude from memory. Prove PHP behaviour by running a snippet on the target PHP, or cite php.net.
- **Secrets never leave the machine in clear text.** Tool output and the rendered report are redacted
  automatically (`scripts/redact.php`). When you write notes or code samples yourself, mask secrets too
  (e.g. `ghp_••••••`, `«REDACTED»`) and never paste tokens, passwords, keys or connection strings.
- **History is append-only.** Every scan is a new version; never delete or overwrite `scans/<id>/` folders.

## Before the first audit

1. **Tools.** If `${CLAUDE_SKILL_DIR}/tools/vendor/autoload.php` does not exist, run
   `bash ${CLAUDE_SKILL_DIR}/scripts/setup.sh` (installs Rector, PHP_CodeSniffer + PHPCompatibility and
   composer/semver into `${CLAUDE_SKILL_DIR}/tools`; needs PHP 8.1+, Composer, git). It ends by checking the
   tools run. When installed as a plugin, run it again after a plugin update.
2. **Target PHP.** `php -l` and class loading prove behaviour on the PHP that runs them, so use the target:
   - `php -v` matches the target minor version: fine.
   - Otherwise look for the target binary (`php8.3`, `/opt/homebrew/opt/php@8.3/bin/php`, `/usr/bin/php8.3`)
     and pass it as `--php=<binary>`. If there is none, tell the user, offer ways to get it (package manager,
     Homebrew `php@8.3`, or Docker `php:8.3-cli`), and only continue without it if they agree: `audit.php`
     then prints a warning, and the notes must say which PHP the runtime proof ran on.

## Workflow

1. **Audit each repo** (a new version is added to the history store every time):
   ```bash
   php ${CLAUDE_SKILL_DIR}/scripts/audit.php <repo> --out=<STORE> --name=<label> --target=<version> --load-classes \
       [--php=<target php>] [--exclude=tests,docs] [--skip=rector] [--memory=4G] [--offline]
   ```
   Result: `<STORE>/<name>/scans/<scan-id>/{profile,scan,lint,libs,runtime,phpcompat,rector,report}.json`. On a
   re-scan the previous notes are copied in with `carried_from` set. `php scripts/audit.php --help` lists
   every option. Drop `--load-classes` only when the user wants a quick scan; it installs the locked
   packages in a scratch copy (network) and takes longer.

2. **Find the PHP version in production** if the repo does not say it (Dockerfile `FROM`, CI config, base
   image metadata; `profile.json` lists the hints found). Write it in the overview.

3. **Review.** Scanner output is input, not a conclusion:
   - Start with `wajib` (must fix) findings, `lint.json` errors and `runtime.json` `failed`, then libraries
     `tidak-didukung` / `perlu-upgrade`, then `deprecated`, then `perilaku` (behaviour change).
   - Read the code around low/medium confidence findings before keeping them. Drop false positives.
   - Findings with `guard_state: mati` do not run on the target version. `jalan` means the guarded branch does
     run. `?` means the condition could not be evaluated: read it.
   - `runtime.json`: `failed` are real problems on the target PHP (signature conflicts are fatal since PHP
     8.0); `unresolved` means a parent is not in the repo or not installed (e.g. WordPress core classes), which
     is not a PHP problem by itself; `skipped` files run code when included and were not loaded.
   - Cross-check `phpcompat.json`; anything only PHPCompatibility found still has to be checked.
   - Prove doubtful behaviour by running a small snippet on the target PHP.
   - Vendor packages: `scripts/load_classes.php <repo> --vendor-only [--packages=a/b]` loads their classes.

4. **Framework switch or merge** (e.g. an old CodeIgniter `system/` to a maintained fork):
   ```bash
   php ${CLAUDE_SKILL_DIR}/scripts/compare_framework.php --old=<old/system> --new=<new/system> --app=<repo> \
       --official=<official-release-of-old/system> --new-config=<new/application/config> --app-config=<repo/config> \
       --name="Framework merge" --store=<STORE>
   ```
   Reports local patches (old vs its official release, with diffs), removed/changed API, override conflicts
   (PHP signature rules), calls to removed API, and config keys. Framework-specific checks come from an
   adapter (`scripts/adapters/`, CodeIgniter 3 bundled and detected automatically). Parameters whose type got
   stricter (e.g. `array $values`) cannot be proven statically: check the call sites by hand. For CodeIgniter 3
   apps whose `system/` lives elsewhere: `load_classes.php <repo> --adapter=codeigniter3 --framework=<system/>`
   (see `references/ci3.md`).

5. **Write analyst notes** for the scan you reviewed: `<STORE>/<name>/scans/<scan-id>/notes.json`
   (comparisons the same, plus `old_report` / `new_report` naming the two repos for the comparison table).
   Write in the report language. Be specific and actionable:
   ```json
   {
     "status": {"level": "wajib|deprecated|perilaku|ok", "label": "Status after review (overrides the automatic one)"},
     "summary": "2-4 sentences: ready or not, main blockers, rough size of the work.",
     "notes": [
       {"sev": "wajib|deprecated|perilaku|info", "title": "Short title", "body": "What, impact, how to fix.",
        "files": ["path/file.php:123"],
        "code": [{"where": "file:line", "before": "current code (copied exactly, secrets masked)", "after": "suggested code", "note": "optional"}]}
     ]
   }
   ```
   - Order by urgency; merge similar findings into one note; do not repeat the tables.
   - Every note that needs a code change gets `code` (Current vs Suggested). **Run the suggested code on the
     target PHP first**: no deprecation, and the same result as today unless the change is intended.
   - For a re-scan: compare with the previous version (the report shows resolved / new / unchanged), update
     the notes, and remove `carried_from` once reviewed.

6. **Overview** (`<STORE>/overview.json`, older versions are archived automatically):
   ```json
   {"goal": {"title": "...", "text": "...", "points": ["..."], "scope": [{"k": "Target", "v": "PHP 8.5.12"}]},
    "summary": "...", "steps": ["ordered steps"], "notes": [/* cross-repo notes */]}
   ```

7. **Check the notes**: `php ${CLAUDE_SKILL_DIR}/scripts/validate_notes.php --store=<STORE>`. Fix every error
   (wrong `sev`, missing `after`, a secret, `carried_from` left on the latest scan) before rendering.

8. **Render** one report from the whole store:
   ```bash
   php ${CLAUDE_SKILL_DIR}/scripts/render.php --store=<STORE> --out=<STORE>/report.html \
       [--lang=en|id] [--include='shop-*'] [--hide=a,b] [--title="..."] [--url=<artifact link>] [--offline]
   ```
   - One page per repo/comparison, a version selector per page (latest by default; older scans stay
     reachable as `#<repo>~<scan-id>`), and a dashboard.
   - **Claude with Artifacts**: publish `report.html` with the Artifact tool (icon `code`). Re-render to the same
     path and publish again to keep the URL. After the first publish, render again with `--url=<link>` so the
     "Copy link" buttons point at the artifact. The template already follows the artifact page contract.
     The report can describe security problems of the audited code: say so before it is shared.
   - **Anywhere else**: open the file in a browser, or `php -S 127.0.0.1:8085 -t <STORE>` and visit
     `http://127.0.0.1:8085/report.html`. Use `--offline` for a page that makes no external request.
   - Before publishing, check the render log: it prints how many values were redacted.

9. **Reply** with a short summary per repo (status, blockers, libraries to replace), the target and the PHP the
   proof ran on, and the link or file path.

## Scripts

| Script | Purpose |
|---|---|
| `scripts/audit.php` | One scan of one repo into the history store (`--help` explains the whole flow) |
| `scripts/detect.php` | Framework, PHP version hints, minified files, non-Composer libraries |
| `scripts/scan_code.php` | Tokenizer scanner; rules in `scripts/rules.php` (+ `rules_en.php`) |
| `scripts/lint.php` | `php -l` on every file with the target PHP |
| `scripts/check_libs.php` | Composer libraries vs target, abandoned packages, Packagist suggestions, removed extensions |
| `scripts/load_classes.php` | Runtime proof: load every app (or vendor) class on the target PHP |
| `scripts/run_tools.php` | PHPCompatibility + Rector dry-run |
| `scripts/compare_framework.php` | Framework old vs new vs application |
| `scripts/adapters/` | Optional framework adapters (CodeIgniter 3 bundled) |
| `scripts/validate_notes.php` | Check notes.json / overview.json against the format |
| `scripts/history.php` | History store helpers; `list` / `migrate` CLI |
| `scripts/redact.php` | Secret redaction used by every writer and the renderer |
| `scripts/render.php` | Store → one HTML report (`assets/report-template.html`) |

Exit codes of every script: 0 ok, 1 wrong usage, 2 environment problem, 10 findings (`audit.php --fail-on`) or
note errors (`validate_notes.php`). Adding or fixing rules: `references/rules.md`. Tests: `bash tests/run.sh`.
