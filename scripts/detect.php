<?php
/**
 * Project profile: framework, hints about the PHP version in use, non-Composer library folders, minified files.
 * Read-only.
 *
 * Usage: php detect.php <repo> [--exclude=a,b] > profile.json
 */
declare(strict_types=1);

ini_set('display_errors', 'stderr');
require __DIR__ . '/lib.php';

handleHelp($argv, <<<'HELP'
Project profile: framework and version, hints about the PHP version in use today (composer.json, Dockerfile,
CI config, .php-version), code size, minified files, and libraries committed into the repo.

Usage: php scripts/detect.php <repo> [--exclude=a,b] > profile.json
HELP);

$repo = $argv[1] ?? '';
if ($repo === '' || !is_dir($repo)) {
    usageError($repo === '' ? 'no repo given.' : "repo folder not found: $repo", 'scripts/detect.php');
}
$repo = rtrim(realpath($repo), '/');
$files = listPhpFiles($repo, excludesFrom($argv));

$profile = [
    'tool' => 'detect',
    'name' => basename($repo),
    'path' => $repo,
    'git' => gitInfo($repo),
    'framework' => null,
    'php_hints' => [],
    'php_files' => count($files),
    'php_lines' => 0,
    'minified_files' => [],
    'third_party_dirs' => [],
];

$composer = is_file("$repo/composer.json") ? (json_decode((string) file_get_contents("$repo/composer.json"), true) ?? []) : [];
$lock = is_file("$repo/composer.lock") ? (json_decode((string) file_get_contents("$repo/composer.lock"), true) ?? []) : [];
$locked = [];
foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $p) {
    $locked[$p['name']] = $p['version'];
}

// Framework
foreach (['system/core/CodeIgniter.php', 'core/CodeIgniter.php'] as $ci) {
    if (is_file("$repo/$ci") && preg_match("/CI_VERSION'?\s*(?:,|=)\s*'([^']+)'/", (string) file_get_contents("$repo/$ci"), $m)) {
        $profile['framework'] = ['name' => 'CodeIgniter', 'version' => $m[1], 'source' => $ci];
    }
}
$known = ['laravel/framework' => 'Laravel', 'symfony/framework-bundle' => 'Symfony', 'yiisoft/yii2' => 'Yii 2', 'codeigniter4/framework' => 'CodeIgniter 4',
    'slim/slim' => 'Slim', 'laminas/laminas-mvc' => 'Laminas', 'cakephp/cakephp' => 'CakePHP', 'pocketarc/codeigniter' => 'CodeIgniter (pocketarc)',
    'codeigniter/framework' => 'CodeIgniter'];
foreach ($known as $pkg => $label) {
    if ($profile['framework'] === null && (isset($locked[$pkg]) || isset($composer['require'][$pkg]))) {
        $profile['framework'] = ['name' => $label, 'version' => $locked[$pkg] ?? ($composer['require'][$pkg] ?? null), 'source' => 'composer'];
    }
}

// Hints about the PHP version in use today
if (isset($composer['require']['php'])) {
    $profile['php_hints'][] = ['source' => 'composer.json require.php', 'value' => $composer['require']['php']];
}
if (isset($composer['config']['platform']['php'])) {
    $profile['php_hints'][] = ['source' => 'composer.json config.platform.php', 'value' => $composer['config']['platform']['php']];
}
foreach (['.php-version', '.tool-versions', 'runtime.txt'] as $f) {
    if (is_file("$repo/$f") && preg_match('/(\d+\.\d+(?:\.\d+)?)/', (string) file_get_contents("$repo/$f"), $m)) {
        $profile['php_hints'][] = ['source' => $f, 'value' => $m[1]];
    }
}
// Plain patterns instead of GLOB_BRACE, which php.net documents as unavailable on some non-GNU systems.
$candidates = [];
foreach (['Dockerfile', 'Dockerfile.*', '*.Dockerfile', 'docker-compose*.yml', 'docker-compose*.yaml', 'compose*.yml', 'compose*.yaml',
    '.gitlab-ci.yml', 'Jenkinsfile', 'bitbucket-pipelines.yml', '.travis.yml', 'docker/*', '.docker/*', 'deploy/*', '.github/workflows/*',
    'docker/*/*', '.docker/*/*'] as $pattern) {
    array_push($candidates, ...(glob("$repo/$pattern") ?: []));
}
$candidates = array_values(array_unique($candidates));
foreach ($candidates as $f) {
    if (!is_file($f) || filesize($f) > 200_000) {
        continue;
    }
    foreach (file($f) ?: [] as $i => $l) {
        if (preg_match('/(?:php[:@-]?|php-version:\s*[\'"]?|PHP_VERSION[=:\s]+[\'"]?)(\d\.\d+(?:\.\d+)?)/i', $l, $m)) {
            $profile['php_hints'][] = ['source' => substr($f, strlen($repo) + 1) . ':' . ($i + 1), 'value' => $m[1], 'line' => trim($l)];
        }
    }
}

// Code size and minified files (very long average line length)
foreach ($files as $f) {
    $content = (string) file_get_contents($f);
    $lines = substr_count($content, "\n") + 1;
    $profile['php_lines'] += $lines;
    if (strlen($content) > 2000 && strlen($content) / $lines > 400) {
        $profile['minified_files'][] = substr($f, strlen($repo) + 1);
    }
}

// Libraries committed into the repo (not via Composer)
foreach (['application/third_party', 'application/libraries', 'app/ThirdParty', 'lib', 'libs', 'libraries', 'third_party', 'vendor_custom'] as $d) {
    if (is_dir("$repo/$d")) {
        $entries = array_values(array_filter(scandir("$repo/$d") ?: [], fn ($e) => $e[0] !== '.' && !in_array($e, ['index.html', 'index.php'], true)));
        if ($entries !== []) {
            $profile['third_party_dirs'][] = ['dir' => $d, 'entries' => $entries];
        }
    }
}

printJson($profile);

function gitInfo(string $repo): ?array
{
    if (!is_dir("$repo/.git")) {
        return null;
    }
    $git = fn (string $args) => trim((string) shell_exec('git -C ' . escapeshellarg($repo) . " $args 2>/dev/null"));
    return [
        'branch' => $git('branch --show-current'),
        'commit' => $git('log -1 --format=%h'),
        'date' => $git('log -1 --format=%cs'),
        'remote' => preg_replace('#//[^@/]+@#', '//', $git('remote get-url origin')),
    ];
}
