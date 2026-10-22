# PHP Upgrade Audit: instructions for AI coding agents

These instructions are for any AI coding agent (Codex, Cursor, GitHub Copilot, Gemini CLI, Windsurf, Cline,
Aider, Claude Code, ...). Claude Code users get the same workflow through `SKILL.md`.

`<TOOL>` below means the folder where this repository is cloned.

## What you are doing

A **read-only** audit of a PHP 7.x codebase before upgrading to PHP 8.x (default target 8.5.12). You produce
notes and a standardised HTML report. You never change the audited code.

## Rules you must follow

1. Never write into the audited repo. All output goes to an output store (`--out` / `--store`) outside it.
2. Never run `composer install/update` in the audited repo. Copy `composer.json` + `composer.lock` to a scratch
   folder if you need installed libraries.
3. Rector only with `--dry-run` (the scripts enforce this).
4. No data leaves the machine except public package names sent to repo.packagist.org (private packages are
   skipped). Use `--offline` to disable even that. Render with `--offline` for a page without web fonts.
5. Never put secrets in notes or code samples. Tool output and the report are redacted automatically, but you
   must also mask anything you type yourself (`ghp_••••••`, `«REDACTED»`).
6. History is append-only: every scan is a new version. Never delete or overwrite `<store>/<name>/scans/<id>/`.
7. Prove claims about PHP behaviour by running a small snippet on the target PHP, or cite php.net.

## Setup (once)

```bash
php -v                     # must be the target version, e.g. 8.5.12
bash <TOOL>/scripts/setup.sh
```

## Steps

1. **Scan** each repo (adds a version to the store):
   `php <TOOL>/scripts/audit.php <repo> --out=<STORE> --name=<name> [--target=8.5.12] [--exclude=tests]`
2. **Find the production PHP version** if the repo does not state it (Dockerfile, CI, base image).
3. **Review** `<STORE>/<name>/scans/<id>/`: `report.json` (all tools combined), `scan.json`, `lint.json`,
   `libs.json`, `phpcompat.json`, `rector.json`. Order: must-fix (`wajib`) and lint errors → libraries →
   deprecated → behaviour changes (`perilaku`). Read the code around low/medium confidence findings and drop
   false positives. `guard_state`: `mati` = does not run on the target, `jalan` = runs, `?` = check by hand.
4. **Framework switch/merge** (optional):
   `php <TOOL>/scripts/compare_framework.php --old=<old> --new=<new> --app=<repo> [--official=<release>] [--new-config=<dir>] [--app-config=<dir>] --name="<name>" --store=<STORE>`
5. **Write notes** to `<STORE>/<name>/scans/<id>/notes.json` (format below). Every note that needs a code
   change has a Current/Suggested `code` pair that you have run on the target PHP.
6. **Write the overview** to `<STORE>/overview.json`.
7. **Render** the report:
   `php <TOOL>/scripts/render.php --store=<STORE> --out=<STORE>/report.html --lang=en|id [--include='prefix-*'] [--offline]`
8. **Open it**: open `report.html` in a browser, or run `php -S 127.0.0.1:8085 -t <STORE>` and visit
   `http://127.0.0.1:8085/report.html`. Each page has an anchor (`#<repo>`, older scans `#<repo>~<scan-id>`).
   If your environment can publish pages (e.g. Claude Artifacts), publish the same file instead.

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
      "code": [{"where": "path/file.php:123", "before": "current code, secrets masked", "after": "suggested code", "note": "optional"}]
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

More detail: `README.md` (English), `README.id.md` (Bahasa Indonesia), `references/`.
