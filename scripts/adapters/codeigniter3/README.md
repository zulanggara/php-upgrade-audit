# CodeIgniter 3 adapter

Used by `compare_framework.php` when `--old` or `--new` is a CodeIgniter 3 `system/` folder (detected by
`core/CodeIgniter.php` with `CI_VERSION`), for example when moving from an official 3.1.x release to a maintained
fork that supports PHP 8.x. See `references/ci3.md` for the review checklist.

What it adds to the generic comparison:

- an application class extending a `CI_*` class that the framework does not have is reported as fatal;
- `$this->load->library('x')`, `helper('x')` and `driver('x')` calls whose file was removed;
- `$config[...]` and `$autoload[...]` keys of `--new-config` vs `--app-config` (`routes.php` skipped).

## Runtime proof: `link_app.php`

Loads the class definitions of one application file on top of a given `system/` folder, without a request or
a database, and prints every PHP message as JSON. A signature conflict shows up as a fatal error.

```bash
php -d error_reporting=-1 -d display_errors=0 scripts/adapters/codeigniter3/link_app.php <system-dir> <app-dir> <file.php>
```

It loads `core/<subclass_prefix>Controller.php` first (the prefix is read from `config/config.php`), like
CodeIgniter does. "Cannot redeclare class" for classes already loaded that way is an artefact of loading files
one by one, not a real problem.
