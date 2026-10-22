<?php
/** Shared helpers for the audit scripts. */
declare(strict_types=1);

require_once __DIR__ . '/redact.php';

/** Newest PHP version the rule catalogue covers. It is the default --target, and what --target=latest means. */
const LATEST_TARGET = '8.5.12';

const DEFAULT_EXCLUDES =['vendor', 'node_modules', '.git', '.svn', '.idea', '.vscode', 'storage', 'bootstrap/cache', 'var/cache', 'cache', 'tmp'];
const PHP_EXTS = ['php', 'phtml', 'inc', 'module', 'install', 'theme'];

/** Value of --name=value from argv. */
function opt(array $argv, string $name, ?string $default = null): ?string
{
    foreach ($argv as $a) {
        if (str_starts_with($a, "--$name=")) {
            return substr($a, strlen($name) + 3);
        }
        if ($a === "--$name") {
            return '1';
        }
    }
    return $default;
}

/**
 * Exit codes shared by every script:
 *   0 ok, 1 wrong usage, 2 environment problem (e.g. analysis tools not installed),
 *   4 render.php refused to write a report that still contains a secret,
 *   10 audit.php --fail-on: findings at or above that level.
 */
const EXIT_USAGE = 1;
const EXIT_ENV = 2;
const EXIT_FINDINGS = 10;

/** Print $help to stdout and exit 0 when --help or -h is given. */
function handleHelp(array $argv, string $help): void
{
    if (array_intersect(['--help', '-h', 'help'], array_slice($argv, 1)) !== []) {
        echo trim($help), "\n";
        exit(0);
    }
}

/** Wrong usage: print the problem and how to get help, exit 1. */
function usageError(string $message, string $script): never
{
    fwrite(STDERR, "Error: $message\nRun: php $script --help\n");
    exit(EXIT_USAGE);
}

/** Folder of the analysis tools (Rector, phpcs, composer/semver) or null when setup.sh was not run. */
function toolsVendor(): ?string
{
    $dir = dirname(__DIR__) . '/tools/vendor';
    return is_file("$dir/autoload.php") ? $dir : null;
}

const SETUP_HINT = 'The analysis tools are not installed. Run once: bash scripts/setup.sh (from the php-upgrade-audit folder).';

/** Absolute, normalised path of $path without creating it (the nearest existing parent is resolved). */
function absPath(string $path): string
{
    if (str_starts_with($path, '~/')) {
        $path = rtrim((string) getenv('HOME'), '/') . substr($path, 1);
    }
    if (!str_starts_with($path, '/')) {
        $path = getcwd() . '/' . $path;
    }
    $parts = [];
    foreach (explode('/', $path) as $p) {
        if ($p === '' || $p === '.') {
            continue;
        }
        $p === '..' ? array_pop($parts) : $parts[] = $p;
    }
    $rest = [];
    while ($parts !== [] && !file_exists('/' . implode('/', $parts))) {
        array_unshift($rest, array_pop($parts));
    }
    $base = rtrim((string) realpath('/' . implode('/', $parts)), '/');
    return $base . ($rest === [] ? '' : '/' . implode('/', $rest));
}

/** --target as a full version (x.y or x.y.z); "latest" or no value means LATEST_TARGET. */
function targetFrom(array $argv): string
{
    $t = opt($argv, 'target', LATEST_TARGET);
    if ($t === 'latest' || $t === '1') {
        return LATEST_TARGET;
    }
    if (!preg_match('/^[78]\.\d+(\.\d+)?$/', $t)) {
        fwrite(STDERR, "Invalid --target=$t. Use a PHP version such as 8.3, 8.3.30 or 8.5.12, or --target=latest (" . LATEST_TARGET . ").\n");
        exit(1);
    }
    return $t;
}

/** "8.5.12" → "8.5" */
function targetMinor(string $target): string
{
    return implode('.', array_slice(explode('.', $target), 0, 2));
}

/**
 * Whether a change introduced in $since applies to $target. $since may name several versions
 * ("8.3/8.4", "7.1–8.1"); the change applies from the earliest one.
 */
function appliesTo(string $since, string $target): bool
{
    preg_match_all('/\d+\.\d+/', $since, $m);
    if ($m[0] === []) {
        return true;
    }
    usort($m[0], 'version_compare');
    return version_compare(targetMinor($target), $m[0][0], '>=');
}

function excludesFrom(array $argv): array
{
    $extra = opt($argv, 'exclude');
    return array_values(array_unique(array_merge(DEFAULT_EXCLUDES, $extra ? array_filter(explode(',', $extra)) : [])));
}

/** Every PHP file in the repo (no symlinks, excluded folders skipped). */
function listPhpFiles(string $root, array $excludes): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            function (SplFileInfo $f) use ($root, $excludes): bool {
                if ($f->isLink()) {
                    return false;
                }
                if ($f->isDir()) {
                    $rel = substr($f->getPathname(), strlen($root) + 1);
                    foreach ($excludes as $ex) {
                        if ($rel === $ex || $f->getFilename() === $ex) {
                            return false;
                        }
                    }
                    return true;
                }
                return in_array(strtolower($f->getExtension()), PHP_EXTS, true);
            }
        )
    );
    foreach ($it as $f) {
        $out[] = $f->getPathname();
    }
    sort($out);
    return $out;
}

/**
 * Replace local absolute paths (they reveal user names and folder layout) in every string of $data.
 * $roots maps a known folder to the label shown instead, e.g. [store => '<store>'].
 */
function strip_local_paths(mixed $data, array $roots = []): mixed
{
    if (is_array($data)) {
        foreach ($data as $k => $v) {
            $data[$k] = strip_local_paths($v, $roots);
        }
        return $data;
    }
    if (!is_string($data) || !str_contains($data, '/')) {
        return $data;
    }
    $roots += [dirname(__DIR__) => '<tool>', rtrim(sys_get_temp_dir(), '/') => '<tmp>'];
    uksort($roots, fn ($a, $b) => strlen($b) <=> strlen($a)); // longest first
    foreach ($roots as $path => $label) {
        if ($path !== '' && $path !== '/') {
            $data = str_replace(rtrim((string) $path, '/'), $label, $data);
        }
    }
    return (string) preg_replace(
        ['#(?<![\w.~-])/(?:private/)?var/folders/[^\s"\'<>]*?/T(?=/)#', '#(?<![\w.~-])(?:/Users|/home)/[^/\s"\'<>]+#', '#(?<![\w.~-])/root(?=/)#'],
        ['<tmp>', '~', '~'],
        $data
    );
}

/**
 * Composer installs committed into the repo (e.g. a bundled library with its own vendor/), found by their
 * vendor/composer/installed.json. The root vendor/ is included only when there is no root composer.lock
 * (otherwise the lock already describes it). Returns [['path' => 'include/mpdf/vendor', 'packages' => [...]], ...].
 */
function bundledComposerInstalls(string $repo): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($repo, FilesystemIterator::SKIP_DOTS),
        fn (SplFileInfo $f) => !$f->isLink() && (!$f->isDir() || !in_array($f->getFilename(), ['.git', 'node_modules', '.svn'], true))
    ));
    foreach ($it as $f) {
        if ($f->getFilename() !== 'installed.json' || basename($f->getPath()) !== 'composer' || basename(dirname($f->getPath())) !== 'vendor') {
            continue;
        }
        $vendor = dirname($f->getPath());
        $rel = substr($vendor, strlen($repo) + 1);
        if ($rel === 'vendor' && is_file("$repo/composer.lock")) {
            continue;
        }
        $data = json_decode((string) file_get_contents($f->getPathname()), true);
        $packages = is_array($data) ? ($data['packages'] ?? (array_is_list($data) ? $data : [])) : [];
        if ($packages !== []) {
            $out[] = ['path' => $rel, 'packages' => $packages];
        }
    }
    usort($out, fn ($a, $b) => strcmp($a['path'], $b['path']));
    return $out;
}

/** All tool output goes through here, so secrets are redacted before anything touches disk or stdout. */
function writeJson(string $path, array $data): void
{
    $data = redact_data($data);
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
}

function printJson(array $data): void
{
    $data = redact_data($data);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
}
