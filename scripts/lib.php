<?php
/** Shared helpers for the audit scripts. */
declare(strict_types=1);

require_once __DIR__ . '/redact.php';

const DEFAULT_EXCLUDES = ['vendor', 'node_modules', '.git', '.svn', '.idea', '.vscode', 'storage', 'bootstrap/cache', 'var/cache', 'cache', 'tmp'];
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
