# Framework adapters

`compare_framework.php` compares two versions of a framework (or any shared code an application extends) and
the application on top of it. The generic part works for any PHP code:

- removed / added / changed classes, methods, properties and functions (signatures),
- application overrides that break PHP's signature rules (fatal when the class loads),
- application calls to removed methods/functions or to ones that now need more arguments,
- a parent class that the old framework had and the new one does not,
- `$var['key']` config keys (with `--new-config` / `--app-config`).

An **adapter** adds what only makes sense for one framework. It is picked automatically when its `detect`
matches `--old` or `--new`; force one with `--adapter=<name>` or turn them off with `--adapter=none`.

## Bundled

| Adapter | Detects | Adds |
|---|---|---|
| [`codeigniter3`](codeigniter3/) | `core/CodeIgniter.php` with `CI_VERSION` | missing `CI_*` parents, `$this->load->library/helper/driver()` of removed files, `$config` / `$autoload` keys, runtime loader for app files on a given `system/` |

## Writing one

Create `scripts/adapters/<name>/adapter.php` returning an array (all keys except `name`, `label`, `detect` are
optional):

```php
<?php
return [
    'name' => 'myframework',
    'label' => 'My Framework 2',
    'detect' => fn (string $dir): bool => is_file("$dir/src/Kernel.php"),
    'class_prefix' => 'MF_',                  // missing parent with this prefix = fatal
    'loaders' => ['methods' => ['component'], 'dirs' => ['components'], 'suffix' => ''], // load by name
    'config' => ['vars' => ['settings'], 'skip' => ['routes.php']],
    'app_loader' => __DIR__ . '/link_app.php', // optional runtime loader, documented in your README
];
```

Add a small fixture under `fixtures/` and a check in `tests/run.sh` (see the `compare framework` block), and
describe the adapter in a `README.md` next to it.
