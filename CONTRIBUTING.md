# Contributing

Thanks for helping. This kit is used by people and AI agents on code they cannot share, so two things matter
more than features: **it never changes the audited repo**, and **it never leaks a secret**.

## Setup

```bash
git clone https://github.com/zulanggara/php-upgrade-audit.git && cd php-upgrade-audit
bash scripts/setup.sh
bash tests/run.sh          # must end with "all tests passed"
```

CI runs the same on PHP 8.1 to 8.5 (`.github/workflows/tests.yml`). If you have the newest PHP, run the tests with
it too: several checks prove behaviour on the PHP that runs them.

## Ground rules

- Read-only towards the audited repo: no writes, no `composer install`, Rector only with `--dry-run`.
- Prove PHP behaviour by running code on the PHP version in question (Docker `php:X.Y-cli` is fine), or cite
  php.net. Never from memory.
- Keep formats stable: script names, the store layout (`<store>/<repo>/scans/<id>/`), `notes.json` and
  `overview.json`. People and agents already depend on them. Add fields; do not rename or remove them.
- A bug fix starts with a test that fails without the fix (`tests/run.sh`).
- No company names, credentials, tokens or local paths (`/Users/...`, `/home/<you>/...`) in the repo,
  including fixtures and examples. Test secrets are assembled at runtime (see `tests/redact_test.php`).

## Adding or fixing a scanner rule

See [`references/rules.md`](references/rules.md). In short:

1. Add the rule to `scripts/rules.php` (Indonesian title/fix) and `scripts/rules_en.php` (English); the tests
   fail when a translation is missing. Take the content from the php.net migration guide.
2. Set `ver` to the version that introduced the change; rules newer than `--target` are skipped.
3. Be honest with `conf`. Generic names (`->contains()`, `is_resource()`) need `only_if_mentioned` or a low
   confidence.
4. Add an example to `fixtures/legacy-app/src/` and the rule id to the list in `tests/run.sh`.
5. Run the scanner on a real project that already supports the target and read what it reports. A rule that
   fires on correct code is a bug.

## Adding a framework adapter

See [`scripts/adapters/README.md`](scripts/adapters/README.md): one `adapter.php` returning an array, a small
fixture under `fixtures/`, and a check in `tests/run.sh`. Adapters only add checks; the generic comparison must
keep working without them.

## Redaction

`scripts/redact.php` hides secrets in everything the tools write and again in the rendered report. When you
add a pattern, add a must-hide **and** a must-keep case to `tests/redact_test.php` (normal code must not be
hidden). Prefer a pattern that hides too much over one that leaks. Found a leak? See [SECURITY.md](SECURITY.md).

## Pull requests

Small and focused, with tests. Describe what you measured (which project, how many findings, how many were
wrong). Update `README.md`, `README.id.md`, `SKILL.md` and `AGENTS.md` together when behaviour changes, and add
a line to `CHANGELOG.md`.
