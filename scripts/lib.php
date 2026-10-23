<?php
/** Shared helpers for the audit scripts. */
declare(strict_types=1);

require_once __DIR__ . '/redact.php';

/** Newest PHP minor version the rule catalogue covers. */
const RULES_COVER = '8.5';
/**
 * Newest release of RULES_COVER known when this kit was released. --target=latest (the default) asks php.net for
 * the newest release instead and falls back to this when php.net cannot be reached or --offline is given.
 */
const LATEST_TARGET = '8.5.12';
const RELEASES_URL = 'https://www.php.net/releases/index.php?json&version={version}';

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

/** Every value of a repeatable option: --name=a --name=b => ['a', 'b']. */
function optAll(array $argv, string $name): array
{
    $out = [];
    foreach ($argv as $a) {
        if (str_starts_with($a, "--$name=") && strlen($a) > strlen($name) + 3) {
            $out[] = substr($a, strlen($name) + 3);
        }
    }
    return $out;
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

/** Version of this kit (from .claude-plugin/plugin.json) and, in a git clone, its commit. */
function kitInfo(): array
{
    $root = dirname(__DIR__);
    $json = json_decode((string) @file_get_contents("$root/.claude-plugin/plugin.json"), true);
    $commit = is_dir("$root/.git") ? trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse --short HEAD 2>/dev/null')) : '';
    return ['version' => is_array($json) ? ($json['version'] ?? null) : null, 'commit' => $commit === '' ? null : $commit];
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

/**
 * --target as a full version. No value or "latest": the newest release of the newest minor the rules cover
 * (RULES_COVER), from php.net; a minor alone ("8.3") becomes its newest release. Without network (--offline,
 * php.net unreachable) "latest" is LATEST_TARGET and a minor stays as given.
 */
function targetFrom(array $argv): string
{
    $t = opt($argv, 'target', 'latest');
    if ($t === 'latest' || $t === '1') {
        return latestRelease(RULES_COVER, opt($argv, 'offline') !== null) ?? LATEST_TARGET;
    }
    if (!preg_match('/^[78]\.\d+(\.\d+)?$/', $t)) {
        fwrite(STDERR, "Invalid --target=$t. Use a PHP version such as 8.3, 8.3.30 or 8.5.12, or --target=latest (the newest PHP " . RULES_COVER . " release).\n");
        exit(1);
    }
    if (preg_match('/^\d+\.\d+$/', $t)) {
        return latestRelease($t, opt($argv, 'offline') !== null) ?? $t;
    }
    return $t;
}

/**
 * Newest released version of $branch ("8.5", or "8" for the newest PHP 8 of all) according to php.net, or null
 * when offline or unreachable. Answers are cached for a day in tools/.php-releases.json.
 * PHP_UPGRADE_AUDIT_RELEASES_URL replaces the php.net URL ({version} is the branch), e.g. for tests.
 */
function latestRelease(string $branch, bool $offline = false): ?string
{
    if ($offline) {
        return null;
    }
    static $memo = [];
    if (array_key_exists($branch, $memo)) {
        return $memo[$branch];
    }
    $custom = getenv('PHP_UPGRADE_AUDIT_RELEASES_URL') ?: null;
    $cacheFile = dirname(__DIR__) . '/tools/.php-releases.json';
    $cache = $custom === null ? json_decode((string) @file_get_contents($cacheFile), true) : null;
    $cache = is_array($cache) ? $cache : [];
    if (isset($cache[$branch]['version'], $cache[$branch]['at']) && $cache[$branch]['at'] > time() - 86400) {
        return $memo[$branch] = $cache[$branch]['version'];
    }
    $url = str_replace('{version}', rawurlencode($branch), $custom ?? RELEASES_URL);
    $ctx = stream_context_create(['http' => ['timeout' => 8, 'user_agent' => 'php-upgrade-audit']]);
    $json = json_decode((string) @file_get_contents($url, false, $ctx), true);
    $version = is_array($json) && is_string($json['version'] ?? null) && preg_match('/^\d+\.\d+\.\d+$/', $json['version'])
        && ($branch === explode('.', $json['version'])[0] || str_starts_with($json['version'], "$branch.")) ? $json['version'] : null;
    if ($version !== null && $custom === null && is_dir(dirname($cacheFile)) && is_writable(dirname($cacheFile))) {
        $cache[$branch] = ['version' => $version, 'at' => time()];
        @file_put_contents($cacheFile, json_encode($cache, JSON_PRETTY_PRINT) . "\n");
    }
    return $memo[$branch] = $version;
}

/**
 * --php=docker or --php=docker:<version|image>: run PHP in a container instead of a local binary. One container
 * is started for the whole audit (no network, the repo and the tool folder read-only, $writable read-write) and a
 * small wrapper script that runs "docker exec ... php" is returned, so every script can use it like a binary.
 * "docker" uses the official image of the target (php:8.5.12-cli); "docker:8.3" uses php:8.3-cli; anything with
 * a ":" or "/" after "docker:" is used as the image name. The container is removed when this process ends.
 */
function dockerPhp(string $spec, string $target, array $readOnly, array $writable, string $workDir): string
{
    $tag = $spec === 'docker' ? $target : substr($spec, 7);
    if ($tag === '' || !preg_match('#^[A-Za-z0-9._/:@-]+$#', $tag)) {
        usageError("--php=$spec: expected docker, docker:<version> (e.g. docker:8.3) or docker:<image>.", 'scripts/audit.php');
    }
    $image = str_contains($tag, ':') || str_contains($tag, '/') ? $tag : "php:$tag-cli";
    if (trim((string) shell_exec('command -v docker 2>/dev/null')) === '') {
        fwrite(STDERR, "Error: --php=$spec needs Docker, and docker is not installed.\n");
        exit(EXIT_ENV);
    }
    $name = 'php-upgrade-audit-' . getmypid() . '-' . bin2hex(random_bytes(3));
    $mounts = [];
    foreach (array_unique(array_filter($readOnly)) as $dir) {
        $mounts[] = '-v ' . escapeshellarg("$dir:$dir:ro");
    }
    foreach (array_unique(array_filter($writable)) as $dir) {
        $mounts[] = '-v ' . escapeshellarg("$dir:$dir");
    }
    $user = function_exists('posix_getuid') ? posix_getuid() . ':' . posix_getgid() : trim((string) shell_exec('id -u')) . ':' . trim((string) shell_exec('id -g'));
    // "sleep" bounds the life of a container left behind by a killed audit; --rm removes it once it stops.
    $cmd = 'docker run -d --rm --network none --name ' . escapeshellarg($name) . ' -u ' . escapeshellarg($user) . ' -e HOME=/tmp '
        . implode(' ', $mounts) . ' --entrypoint sleep ' . escapeshellarg($image) . ' 21600 2>&1';
    fwrite(STDERR, "Starting PHP in Docker ($image; the image is downloaded the first time)...\n");
    exec($cmd, $output, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Error: could not start $image: " . trim(implode("\n", array_slice($output, -3))) . "\n");
        exit(EXIT_ENV);
    }
    register_shutdown_function(fn () => exec('docker rm -f ' . escapeshellarg($name) . ' >/dev/null 2>&1'));
    @mkdir($workDir, 0700, true);
    $wrapper = "$workDir/php-$name";
    $inside = implode('|', array_map(fn ($d) => preg_replace('/[^A-Za-z0-9._\/-]/', '?', $d) . '|' . preg_replace('/[^A-Za-z0-9._\/-]/', '?', $d) . '/*', array_unique(array_filter([...$readOnly, ...$writable]))));
    file_put_contents($wrapper, "#!/bin/sh\n# PHP $image in container $name (removed when the audit ends).\n"
        . "case \"\$PWD\" in $inside) w=\"\$PWD\" ;; *) w=/ ;; esac\n"
        . 'exec docker exec -i -w "$w" ' . escapeshellarg($name) . " php \"\$@\"\n");
    chmod($wrapper, 0700);
    register_shutdown_function(fn () => @unlink($wrapper));
    return $wrapper;
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
                // CodeIgniter writes its logs as log-YYYY-MM-DD.php: logs, not code (scan_logs.php reads them)
                return in_array(strtolower($f->getExtension()), PHP_EXTS, true) && !preg_match('/^log-\d{4}-\d{2}-\d{2}\.php$/', $f->getFilename());
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

/**
 * One sentence for the "detail" of a finding (P81-NULL-TO-INTERNAL): which argument can be null and where the
 * value comes from. The report template has the same wording in English and Indonesian.
 */
function finding_detail_text(array $d): string
{
    $call = str_starts_with((string) ($d['func'] ?? ''), 'new ') ? $d['func'] . '()' : ($d['func'] ?? '?') . '()';
    $arg = "Argument {$d['param']} of $call";
    $from = ($d['source'] ?? '') . (isset($d['label']) ? " ({$d['label']})" : '');
    return match ($d['kind'] ?? '') {
        'null' => "$arg is a literal null.",
        'coalesce-null' => "$arg can be null: {$d['source']} falls back to null.",
        'superglobal' => "$arg can be null: {$d['source']} is read without isset().",
        'variable' => "$arg can be null via {$d['via']} (line {$d['via_line']}): $from.",
        default => "$arg can be null: $from.",
    };
}
