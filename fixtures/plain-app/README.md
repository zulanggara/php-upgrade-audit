# Project without Composer fixture

Shaped like a WordPress plugin or legacy code without Composer, used by `tests/run.sh` to test the files mode of
`scripts/load_classes.php`. Nothing here is a real plugin.

- `plugin.php` and `index.php` run code when included: they must be skipped, never executed. The plugin header
  says `Requires PHP: 7.0` (a PHP version hint).
- `includes/lib/vendor/` is a Composer install committed into the repo: `example/old-pdf` does not support PHP 8.
- `includes/class-widget.php` overrides `render()` with an extra required parameter: fatal on PHP 8.
- `includes/functions.php` declares a function inside `if (!function_exists(...))`: loads fine.
- `includes/class-rest.php` extends a class that is not in the repo: unresolved.
- `includes/class-loader.php` requires a file through a constant only the bootstrap defines: unresolved.
- `tests/stubs.php` redeclares a built-in class and copies `Plain_Base`: skipped, and `Plain_Base` still
  comes from `includes/class-base.php`.
