# CodeIgniter 3 and PHP 8.x

## When reviewing

- **`system/` is the framework.** Official CodeIgniter 3.1.x (bcit-ci) supports up to roughly PHP 8.1/8.2.
  Maintained forks (for example `pocketarc/codeigniter`, 3.4.x) target recent PHP 8.x. Check the fork's
  changelog for the supported versions; do not rely on memory.
- **Database drivers** under `system/database/drivers/<driver>/` only matter if the app uses them
  (`dbdriver`, and `subdriver` for PDO, in `application/config/database.php`). `mysql_*`, `ibase_*`, `oci_*` and
  `mcrypt_*` findings in unused drivers are not blockers. Say so in the notes.
- **mysqli and PHP 8.1:** mysqli throws exceptions by default (`MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT`).
  Official CI 3.1.x does not set a report mode, so a failed query or connection becomes an uncaught
  `mysqli_sql_exception` instead of `FALSE` handled by `db_debug` / `if ($query)`. Maintained forks set
  `MYSQLI_REPORT_OFF` in the mysqli driver.
- **Dynamic properties (8.2):** CI attaches libraries, models and `db` as dynamic properties of controllers and
  models. `#[\AllowDynamicProperties]` is inherited, so it is enough on `CI_Controller` / `CI_Model` /
  `CI_Loader`. Grep `AllowDynamicProperties` in `system/`. The scanner does not check subclasses.
- **Minified `system/` folders** (comments and whitespace stripped): line numbers are useless, so findings show
  a code excerpt. Compare with an official release after normalising both sides (`php -w`, or
  `compare_framework.php`, which hashes token streams).
- **`application/core/<prefix>*.php` and `application/libraries/<prefix>*.php`** extend CI classes. If a method
  signature in the new `system/` changes (added types or return types), an override with the old signature is
  a **fatal error when the class loads**. `php -l` does not catch it; `compare_framework.php` and
  `adapters/codeigniter3/link_app.php` do.
- **`index.php`** is often patched per company (e.g. an extra environment name). An environment that is not in
  the `switch (ENVIRONMENT)` answers every request with HTTP 503. Compare it by hand.
- **Profiler** in CI 3.1.x uses `$this->_compile_{$section}`, which PHP 7 parsed as a string offset on
  `_compile_`. PHP 8 refuses to parse it. Fixed forks use `$this->{'_compile_'.$section}`.

## Checklist: replacing `system/` with a new version

Only when asked. Everything stays read-only; work on copies.

1. Confirm the current version (`CI_VERSION` in `system/core/CodeIgniter.php`). Download the official release
   of that version and run `compare_framework.php --official=...` to find local patches. They must be
   re-applied to the new `system/`.
2. Run `compare_framework.php --old=... --new=... --app=...` (with `--new-config` / `--app-config`).
3. Load every application class on the new `system/`:
   ```bash
   cd <app> && find controllers models core libraries migrations -name '*.php' | while read f; do
     printf '%s\t' "$f"
     php -d error_reporting=-1 -d display_errors=0 <TOOL>/scripts/adapters/codeigniter3/link_app.php <new/system> "$PWD" "$PWD/$f" | tail -1
   done
   ```
   "Cannot redeclare class" for classes already loaded through `core/<prefix>Controller.php` is an artefact of
   loading each file separately, not a real problem.
4. Check parameters whose type got stricter (e.g. `where_in($key, array $values)`): grep the call sites and make
   sure the argument is always an array.
5. Check `index.php`, config defaults (session, cookie, CSRF, encryption) and removed libraries (`Encrypt`
   needs mcrypt) by hand.
6. Run `audit.php` on a merged copy to see what is left.
