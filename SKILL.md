---
name: php-upgrade-audit
description: Read-only audit of how ready a PHP 7.x codebase is for PHP 8.x (default target 8.5.12). Finds removed, deprecated and behaviour-changing functions, syntax and helpers from PHP 7.1 to 8.5, checks Composer libraries (php constraint, abandoned packages, versions that support the target) and PHP extensions, compares framework versions (e.g. CodeIgniter 3 system/ folders), and produces a standardised HTML report with one page per repo and a full scan history. Never modifies the audited repo; secrets are redacted. Use when asked to check PHP 8 / 8.5 compatibility, whether libraries support a PHP version, to audit before a PHP upgrade, or to assess a CodeIgniter 3 upgrade.
---

# PHP Upgrade Audit (7.x → 8.x)

A **read-only** audit. The result is notes and a report, never code changes. Modernisation work (strict types,
higher PHPStan levels, using new 8.4/8.5 features) is out of scope.

The same workflow is described for other AI coding agents in `AGENTS.md`; keep both in sync.

## Hard rules

- Never write anything into the audited repo. Output, tool config and caches go to the output store
  (`--out`), by default a folder in the session scratchpad. `audit.php` refuses an output folder inside the repo.
- Never run `composer install/update` in the repo. If you need installed libraries, copy `composer.json` and
  `composer.lock` to a scratch folder and install there.
- Rector only runs with `--dry-run` (enforced by `run_tools.php`).
- Private packages (no Packagist `notification-url`) are never sent to Packagist. Use `--offline` to make no
  network request at all.
- Do not conclude from memory. Prove PHP behaviour by running a snippet on the target PHP, or cite php.net.
- **Secrets never leave the machine in clear text.** Tool output and the rendered report are redacted
  automatically (`scripts/redact.php`). When you write notes or code samples yourself, mask secrets too
  (e.g. `ghp_••••••`, `«REDACTED»`) and never paste tokens, passwords, keys or connection strings.
- **History is append-only.** Every scan is a new version; never delete or overwrite `scans/<id>/` folders.

## Prerequisites

- `php -v` must show the target version (e.g. 8.5.12). Keep the old PHP available if other projects need it.
- Tools: `bash ${CLAUDE_SKILL_DIR}/scripts/setup.sh` (installs Rector, PHP_CodeSniffer + PHPCompatibility and
  composer/semver into `${CLAUDE_SKILL_DIR}/tools`).

## Workflow

1. **Audit each repo** (a new version is added to the history store every time):
   ```bash
   php ${CLAUDE_SKILL_DIR}/scripts/audit.php <repo> --out=<STORE> [--name=label] [--target=8.5.12] [--exclude=tests,docs] [--skip=rector] [--offline]
   ```
   Result: `<STORE>/<name>/scans/<scan-id>/{profile,scan,lint,libs,phpcompat,rector,report}.json` and
   `<STORE>/<name>/index.json`. On a re-scan the previous notes are copied in with `carried_from` set.
   If the target version is not released yet, say so; the library check still works (Composer constraints
   rarely distinguish patch versions).

2. **Find the PHP version in production** if the repo does not say it (Dockerfile `FROM`, CI config, base
   image metadata). Write it in the overview.

3. **Review.** Scanner output is input, not a conclusion:
   - Start with `wajib` (must fix) findings and `lint.json` errors, then libraries `tidak-didukung` /
     `perlu-upgrade`, then `deprecated`, then `perilaku` (behaviour change).
   - Read the code around low/medium confidence findings before keeping them. Drop false positives.
   - Findings with `guard_state: mati` do not run on the target version. `jalan` means the guarded branch does
     run. `?` means the condition could not be evaluated: read it.
   - Cross-check `phpcompat.json`; anything only PHPCompatibility found still has to be checked.
   - Prove doubtful behaviour by running a small snippet on the target PHP.
   - Runtime proof without booting the app: `audit.php --load-classes` (or `scripts/load_classes.php <repo>`)
     loads every application class on the target PHP in a scratch copy (Composer autoload, or plain files
     without Composer); `--vendor-only` does the same for installed packages. For CodeIgniter 3 apps whose
     `system/` lives elsewhere: `load_classes.php <repo> --adapter=codeigniter3 --framework=<system/>`
     (see `references/ci3.md`).

4. **Framework switch or merge** (e.g. an old CodeIgniter `system/` to a maintained fork):
   ```bash
   php ${CLAUDE_SKILL_DIR}/scripts/compare_framework.php --old=<old/system> --new=<new/system> --app=<repo> \
       --official=<official-release-of-old/system> --new-config=<new/application/config> --app-config=<repo/config> \
       --name="Framework merge" --store=<STORE>
   ```
   Reports local patches (old vs its official release, with diffs), removed/changed API, override conflicts
   (PHP signature rules), calls to removed API, and config keys. Parameters whose type got stricter (e.g.
   `array $values`) cannot be proven statically: check the call sites by hand. Compare `index.php` and files
   outside the framework folder by hand.

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

7. **Render** one report from the whole store:
   ```bash
   php ${CLAUDE_SKILL_DIR}/scripts/render.php --store=<STORE> --out=<STORE>/report.html \
       [--lang=en|id] [--include='shop-*'] [--hide=a,b] [--title="..."] [--url=<artifact link>] [--offline]
   ```
   - One page per repo/comparison, a version selector per page (latest by default; older scans stay
     reachable as `#<repo>~<scan-id>`), and a dashboard.
   - **Claude with Artifacts**: publish `report.html` with the Artifact tool (icon `code`). Re-render to the same
     path and publish again to keep the URL. After the first publish, render again with `--url=<link>` so the
     "Copy link" buttons point at the artifact. The template already follows the artifact page contract.
   - **Anywhere else**: open the file in a browser, or `php -S 127.0.0.1:8085 -t <STORE>` and visit
     `http://127.0.0.1:8085/report.html`. Use `--offline` for a page that makes no external request.
   - Before publishing, check the render log: it prints how many values were redacted.

8. **Reply** with a short summary per repo (status, blockers, libraries to replace) and the link or file path.

## Scripts

| Script | Purpose |
|---|---|
| `scripts/audit.php` | One scan of one repo into the history store |
| `scripts/detect.php` | Framework, PHP version hints, minified files, non-Composer libraries |
| `scripts/scan_code.php` | Tokenizer scanner; rules in `scripts/rules.php` (+ `rules_en.php`) |
| `scripts/lint.php` | `php -l` on every file with the target PHP |
| `scripts/check_libs.php` | Composer libraries vs target, abandoned packages, Packagist suggestions, removed extensions |
| `scripts/run_tools.php` | PHPCompatibility + Rector dry-run |
| `scripts/compare_framework.php` | Framework old vs new vs application |
| `scripts/load_classes.php` | Runtime proof: load every app (or vendor) class on the target PHP |
| `scripts/validate_notes.php` | Check notes.json / overview.json against the format |
| `scripts/adapters/` | Optional framework adapters (CodeIgniter 3 bundled) |
| `scripts/history.php` | History store helpers; `list` / `migrate` CLI |
| `scripts/redact.php` | Secret redaction used by every writer and the renderer |
| `scripts/render.php` | Store → one HTML report (`assets/report-template.html`) |

Adding or fixing rules: `references/rules.md`. Tests: `bash tests/run.sh`.
