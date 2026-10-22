# Composer project fixture

Small PSR-4 project (no dependencies, so it installs offline) used by `tests/run.sh` to test
`scripts/load_classes.php`:

- `src/Model/Report.php` overrides `handle()` with an extra required parameter. PHP 7 only warned; PHP 8
  refuses to load the class (fatal), so it must be reported as a signature failure.
- `src/Model/Counter.php` implements `Countable` without the return type PHP 8.1 expects (a deprecation).
- `src/Model/Plugin.php` extends a class that is not installed (unresolved, not a PHP 8 problem).
- `src/Model/Optional.php` refuses to load because an optional package is missing (unresolved as well).
- `src/Model/OldApi.php` triggers a library deprecation (`E_USER_DEPRECATED`), kept apart from PHP's own.
