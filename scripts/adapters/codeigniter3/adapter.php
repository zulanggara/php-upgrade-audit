<?php
/**
 * CodeIgniter 3 adapter: the framework-specific knowledge compare_framework.php needs for a CI3 system/ folder.
 * The generic checks (API diff, PHP signature rules, calls to removed API) work without it; see ../README.md.
 */
return [
    'name' => 'codeigniter3',
    'label' => 'CodeIgniter 3',
    // A framework folder is CI3 when it has core/CodeIgniter.php with CI_VERSION.
    'detect' => fn (string $dir): bool => is_file("$dir/core/CodeIgniter.php")
        && str_contains((string) file_get_contents("$dir/core/CodeIgniter.php"), 'CI_VERSION'),
    // An application class whose parent has this prefix but is missing from the framework is a fatal error.
    'class_prefix' => 'CI_',
    // $this->load->library('x') / helper('x') / driver('x') load framework files by name.
    'loaders' => ['methods' => ['library', 'helper', 'driver'], 'dirs' => ['libraries', 'helpers', 'drivers'], 'suffix' => '_helper'],
    // Config arrays to compare between --new-config and --app-config, and files that are not config.
    'config' => ['vars' => ['config', 'autoload'], 'skip' => ['routes.php']],
    // Loads one application file on top of a given system/ folder (runtime proof), see link_app.php.
    'app_loader' => __DIR__ . '/link_app.php',
];
