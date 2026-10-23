# PHP Upgrade Audit: instructions for AI coding agents

These instructions are for any AI coding agent (Codex, Cursor, GitHub Copilot, Gemini CLI, Windsurf, Cline,
Aider, Claude Code, ...). Claude Code users get the same workflow through `SKILL.md`.

`<TOOL>` below means the folder where this repository is cloned.

## What you are doing

A **read-only** audit of a PHP codebase (7.x or older 8.x; Laravel, Symfony, WordPress, CodeIgniter or plain
PHP) before upgrading to a newer PHP 8 version. You produce notes and a standardised HTML report. You never
change the audited code.

Inputs to settle first (ask the user when they did not say):

- **Repo(s)** to audit.
- **Target PHP version**: what the user asked for (e.g. `8.3.30`); otherwise `latest`, the newest version the
  rules cover (8.5.12). Rules introduced after the target are not reported.
- **Report language**: `en` or `id` (the language the user writes in).
- **Store**: where the history lives; default `~/php-audit-store`, always outside the audited repos and not a
  temporary folder.

## Rules you must follow

1. Never write into the audited repo. All output goes to the store (`--out` / `--store`) outside it.
2. Never run `composer install/update` in the audited repo. `load_classes.php` installs in a scratch copy.
3. Rector only with `--dry-run` (the scripts enforce this).
4. No data leaves the machine except public package names sent to repo.packagist.org (private packages are
   skipped), and, with `--load-classes`, the downloads of a normal `composer install` in the scratch copy. Use
   `--offline` to disable both. Render with `--offline` for a page without web fonts. `scripts/update.sh`
   checks GitHub once a day for a new version of this kit (`PHP_UPGRADE_AUDIT_NO_UPDATE=1` turns it off).
8. Every claim in a note is checkable: link its sources in `evidence` (only sources you opened) and record the
   run of suggested code in `proof`.
5. Never put secrets in notes or code samples. Tool output and the report are redacted automatically, but you
   must also mask anything you type yourself (`ghp_••••••`, `«REDACTED»`).
6. History is append-only: every scan is a new version. Never delete or overwrite `<store>/<name>/scans/<id>/`.
7. Prove claims about PHP behaviour by running a small snippet on the target PHP, or cite php.net.

## Setup (once), and update (every run)

```bash
bash <TOOL>/scripts/update.sh  # every run: updates a git clone (once a day); prints "update: <state>"
bash <TOOL>/scripts/setup.sh   # once: PHP 8.1+, Composer, git; installs and checks the tools in <TOOL>/tools
php -v                         # php -l and class loading prove behaviour on THIS version
```

When `update.sh` prints `update: updated`, read this file again before continuing.

If `php -v` is not the target version, find the target binary (`php8.3`, `/opt/homebrew/opt/php@8.3/bin/php`)
and pass `--php=<binary>` to `audit.php`. If there is none, tell the user (package manager, Homebrew, or
Docker `php:8.3-cli` can provide it) and continue only if they agree; the notes must then say which PHP ran
the proof.

## Steps

1. **Scan** each repo (adds a version to the store):
   `php <TOOL>/scripts/audit.php <repo> --out=<STORE> --name=<name> --target=<version> --load-classes [--php=<binary>] [--exclude=tests]`
   (`php <TOOL>/scripts/audit.php --help` lists every option.)
2. **Find the production PHP version** if the repo does not state it (Dockerfile, CI, base image;
   `profile.json` lists the hints found).
3. **Review** `<STORE>/<name>/scans/<id>/`: `report.json` (all tools combined), `scan.json`, `lint.json`,
   `runtime.json`, `libs.json`, `phpcompat.json`, `rector.json`. Order: must-fix (`wajib`), lint errors and
   classes that fail to load → libraries → deprecated → behaviour changes (`perilaku`). Read the code around
   low/medium confidence findings and drop false positives. `guard_state`: `mati` = does not run on the target,
   `jalan` = runs, `?` = check by hand. In `runtime.json`, `unresolved` (a parent not in the repo or not
   installed) is not a PHP problem by itself; `failed` is.
4. **Framework switch/merge** (optional):
   `php <TOOL>/scripts/compare_framework.php --old=<old> --new=<new> --app=<repo> [--official=<release>] [--new-config=<dir>] [--app-config=<dir>] --name="<name>" --store=<STORE>`
   Framework adapters live in `scripts/adapters/` (CodeIgniter 3 bundled, detected automatically).
5. **Write notes** to `<STORE>/<name>/scans/<id>/notes.json` (format below). Every note that needs a code
   change has a Current/Suggested `code` pair that you have run on the target PHP (record it in `proof`), and
   every `wajib`/`deprecated`/`perilaku` note has `evidence`: php.net, RFC, php-src `UPGRADING`, the library's
   `UPGRADE`/`CHANGELOG`/release notes at a tag, or the source line. Reuse the links of scanner rules and of
   `libs.json` `upgrade_notes`; `references/evidence.md` lists checked sources for common changes.
6. **Write the overview** to `<STORE>/overview.json`.
7. **Check** them: `php <TOOL>/scripts/validate_notes.php --store=<STORE>` and fix every error.
8. **Render** the report:
   `php <TOOL>/scripts/render.php --store=<STORE> --out=<STORE>/report.html --lang=en|id [--include='prefix-*'] [--offline]`
9. **Open it**: open `report.html` in a browser, or run `php -S 127.0.0.1:8085 -t <STORE>` and visit
   `http://127.0.0.1:8085/report.html`. Each page has an anchor (`#<repo>`, older scans `#<repo>~<scan-id>`).
   If your environment can publish pages (e.g. Claude Artifacts), publish the same file instead, and say that
   the report can describe security problems of the audited code before it is shared.
10. **Reply** with a short summary per repo (status, blockers, libraries to replace), the target, the PHP the
    proof ran on, and where the report is.

## notes.json

```json
{
  "status": {"level": "wajib|deprecated|perilaku|ok", "label": "Status after review"},
  "summary": "2-4 sentences: ready or not, main blockers, size of the work.",
  "notes": [
    {
      "sev": "wajib|deprecated|perilaku|info",
      "title": "Short title",
      "body": "What is wrong, the impact, how to fix it.",
      "files": ["path/file.php:123"],
      "code": [{"where": "path/file.php:123", "before": "current code, secrets masked", "after": "suggested code", "note": "optional",
                "proof": {"php": "8.5.12", "run": "php check.php", "output": "what it printed"}}],
      "evidence": [{"kind": "php.net|rfc|php-src|changelog|release|upgrade|source|issue|cve|advisory|docs|run",
                    "title": "What the source is", "url": "https://...", "quote": "short exact quote"}]
    }
  ]
}
```

Severity keys stay as written above in both languages: `wajib` = must fix, `deprecated`, `perilaku` = behaviour
change, `info`, `ok` = ready. For comparisons add `"old_report"` and `"new_report"` (repo names in the store).
After a re-scan the previous notes are copied with `"carried_from": "<scan-id>"`; review them against the new
results and remove `carried_from`.

## overview.json

```json
{
  "goal": {"title": "Scan goal", "text": "...", "points": ["..."], "scope": [{"k": "Target", "v": "PHP 8.5.12"}]},
  "summary": "Overall conclusion",
  "steps": ["Ordered, actionable steps"],
  "notes": [{"sev": "info", "title": "...", "body": "...", "code": []}]
}
```

## CI

`audit.php --fail-on=wajib|deprecated|perilaku` exits 10 when there are findings at that level or above.
Exit codes of every script: 0 ok, 1 wrong usage, 2 environment problem, 4 render refused (a secret is still in
the page data), 10 findings / note errors.

More detail: `README.md` (English), `README.id.md` (Bahasa Indonesia), `references/`.
