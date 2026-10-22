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
