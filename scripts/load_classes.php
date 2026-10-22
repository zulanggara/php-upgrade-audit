<?php
/**
 * Runtime proof: load every class of the application (or of vendor packages) on the target PHP, each in its own
 * process, and report fatal errors (signature conflicts, missing parents, parse errors) and the deprecations
 * PHP raises while linking the classes. Works on a scratch copy; the audited repo is only read.
 *
 * Run with --help for the modes and options.
 */
declare(strict_types=1);

ini_set('display_errors', 'stderr');
require __DIR__ . '/lib.php';

if (($argv[1] ?? '') === '--child') {
    child(array_slice($argv, 2));
}

handleHelp($argv, <<<'HELP'
Load every class of an application on the target PHP, each in a separate process, to prove it links:
signature conflicts with parents/interfaces, missing parent classes, parse errors, and the deprecations PHP
raises while declaring the classes. Nothing is executed beyond loading the class definitions.

Usage:
  php scripts/load_classes.php <repo> [--php=/path/to/php] [--work=<dir>] [options] > runtime.json

Modes (picked automatically):
  composer   The repo has composer.json. Its PHP files, composer.json and composer.lock are copied to --work,
             "composer install --no-scripts --no-plugins" runs THERE with --php (never in the repo), and every
             class of the app's autoload (psr-4, psr-0, classmap, files) is loaded through vendor/autoload.php.
             If the lock refuses the target PHP, the install is retried ignoring the php requirement, and that
             is reported. If the install fails, the files mode is used instead.
  files      No composer.json (WordPress plugins, legacy code). Files whose top level only declares classes,
             functions or constants (plus guards such as "defined('ABSPATH') or exit" and
             "if (!function_exists(...))") are loaded; a class map of the repo resolves parents across files.
             Files that run code when included are listed as skipped, never executed.
  adapter    --adapter=<name> --framework=<dir>: the adapter's loader loads each app file on that framework
             (e.g. codeigniter3 with a system/ folder, see scripts/adapters/).

Options:
  --php=<binary>      PHP to load with: use the TARGET version (default: the PHP running this script).
  --work=<dir>        Scratch folder (default: a folder in the system temp dir). Must be outside the repo.
  --vendor-only       Load the classes of installed vendor packages instead of the app (composer mode).
  --packages=a/b,c/d  With --vendor-only: only these packages (default: all).
  --dev               Also install require-dev packages and load autoload-dev classes.
  --offline           No network: skip composer install (vendor classes then show as unresolved).
  --adapter=<name> --framework=<dir> [--app-dir=<dir>]   Adapter mode (app dir defaults to the repo).
  --jobs=8            Parallel processes.   --timeout=10   Seconds per class before it counts as hanging.
  --exclude=a,b       Extra folders to skip.

Network: composer install downloads the locked packages from where composer.lock says (Packagist, GitHub,
private repositories with your own credentials), like any install. Use --offline to avoid it.

Output: JSON with "failed" (real problems on this PHP), "unresolved" (needs code that is not in the repo or not
installed), "deprecations", "skipped" and counts. Secrets and local paths are removed.
HELP);

$repo = $argv[1] ?? '';
if ($repo === '' || str_starts_with($repo, '--') || !is_dir($repo)) {
    usageError($repo === '' || str_starts_with($repo, '--') ? 'no repo given.' : "repo folder not found: $repo", 'scripts/load_classes.php');
}
$repo = rtrim((string) realpath($repo), '/');
$php = opt($argv, 'php', PHP_BINARY);
$phpVersion = trim((string) shell_exec(escapeshellarg($php) . ' -r "echo PHP_VERSION;" 2>/dev/null'));
if ($phpVersion === '') {
    fwrite(STDERR, "Error: cannot run the PHP binary $php (--php).\n");
    exit(EXIT_ENV);
}
$work = absPath(opt($argv, 'work', sys_get_temp_dir() . '/php-upgrade-audit-load'));
if (str_starts_with($work . '/', $repo . '/')) {
    usageError('--work must be outside the audited repo.', 'scripts/load_classes.php');
}
$work .= '/' . preg_replace('/[^a-z0-9._-]+/', '-', strtolower(basename($repo))) . '-' . substr(md5($repo), 0, 8);
$excludes = excludesFrom($argv);
$jobs = max(1, (int) opt($argv, 'jobs', '8'));
$timeout = max(1, (int) opt($argv, 'timeout', '10'));
$offline = opt($argv, 'offline') !== null;
$dev = opt($argv, 'dev') !== null;
$vendorOnly = opt($argv, 'vendor-only') !== null;

$out = ['tool' => 'load_classes', 'php' => $phpVersion, 'mode' => null, 'install' => null, 'units' => 0, 'loaded' => 0,
    'failed' => [], 'unresolved' => [], 'deprecations' => [], 'warnings' => [], 'skipped' => [], 'notes' => []];

$adapterName = opt($argv, 'adapter');
if ($adapterName !== null) {
    $file = __DIR__ . '/adapters/' . basename($adapterName) . '/adapter.php';
    $adapter = is_file($file) ? require $file : null;
    $framework = opt($argv, 'framework');
    if ($adapter === null || empty($adapter['app_loader'])) {
        usageError("adapter $adapterName not found or it has no app loader.", 'scripts/load_classes.php');
    }
    if ($framework === null || !is_dir($framework)) {
        usageError('--framework=<dir> is required with --adapter (e.g. the system/ folder).', 'scripts/load_classes.php');
    }
    $out['mode'] = 'adapter:' . $adapter['name'];
    $appDir = rtrim((string) realpath(opt($argv, 'app-dir', $repo)), '/');
    $units = [];
    foreach (listPhpFiles($appDir, $excludes) as $f) {
        if (!preg_match('#/(views|config|language|logs|cache|third_party)/#', $f)) {
            $units[] = ['id' => substr($f, strlen($appDir) + 1), 'cmd' => [$php, '-d', 'error_reporting=-1', '-d', 'display_errors=0', $adapter['app_loader'], realpath($framework), $appDir, $f]];
        }
    }
    collect($out, runUnits($units, $jobs, $timeout), [$appDir => 'app', (string) realpath($framework) => 'framework']);
    finish($out);
}

prepareWork($repo, $work, $excludes);
$composerJson = is_file("$repo/composer.json") ? json_decode((string) file_get_contents("$repo/composer.json"), true) : null;

if (is_array($composerJson)) {
    $out['mode'] = 'composer';
    $out['install'] = composerInstall($php, $work, $offline, $dev);
    if (!$out['install']['ok']) {
        $out['notes'][] = 'composer install failed in the scratch copy, so the files mode was used: ' . $out['install']['message'];
        if ($vendorOnly) {
            finish($out);
        }
        $out['mode'] = 'files';
    }
} else {
    $out['mode'] = 'files';
    $out['notes'][] = 'No composer.json: files that only declare classes/functions are loaded with a class map of the repo; files that run code when included are skipped.';
    if ($vendorOnly) {
        usageError('--vendor-only needs a repo with composer.json.', 'scripts/load_classes.php');
    }
}

$units = [];
if ($out['mode'] === 'composer') {
    $map = is_file("$work/vendor/composer/autoload_classmap.php") ? require "$work/vendor/composer/autoload_classmap.php" : [];
    // Bootstrap first: autoload "files" entries run on every load, a failure there would repeat for each class.
    $boot = runUnits([['id' => '(vendor/autoload.php)', 'cmd' => [$php, __FILE__, '--child', 'composer', "$work/vendor/autoload.php", '']]], 1, $timeout * 3);
    if (($boot[0]['ok'] ?? false) !== true) {
        collect($out, $boot, [$work => '']);
        $out['notes'][] = 'vendor/autoload.php itself fails to load, so no class was loaded.';
        finish($out);
    }
    $packages = array_filter(explode(',', (string) opt($argv, 'packages', '')));
    foreach ($map as $class => $file) {
        $rel = substr((string) realpath($file), strlen($work) + 1);
        $isVendor = str_starts_with($rel, 'vendor/');
        if ($vendorOnly !== $isVendor || $rel === '' || str_starts_with($rel, 'vendor/composer/')) {
            continue;
        }
        if ($vendorOnly && $packages !== [] && !array_filter($packages, fn ($p) => str_starts_with($rel, "vendor/$p/"))) {
            continue;
        }
        $units[] = ['id' => $class, 'file' => $rel, 'cmd' => [$php, __FILE__, '--child', 'composer', "$work/vendor/autoload.php", $class]];
    }
    if (!$vendorOnly && $units === []) {
        $out['notes'][] = 'composer.json declares no autoload for the application (psr-4, psr-0, classmap), so there are no app classes to load. Try --vendor-only, or the files mode by auditing without composer.json.';
    }
} else {
    [$decl, $skipped, $consts] = scanDeclarations($work);
    $out['skipped'] = $skipped;
    file_put_contents("$work/.load-map.json", json_encode(['map' => $decl['map'], 'consts' => $consts, 'root' => $work]));
    foreach ($decl['files'] as $rel) {
        $units[] = ['id' => $rel, 'file' => $rel, 'cmd' => [$php, __FILE__, '--child', 'files', "$work/.load-map.json", "$work/$rel"]];
    }
}
collect($out, runUnits($units, $jobs, $timeout), [$work => '']);
finish($out);

// ---------------------------------------------------------------------------------------------

function finish(array $out): never
{
    $out['failed_count'] = count($out['failed']);
    $out['unresolved_count'] = count($out['unresolved']);
    printJson(strip_local_paths($out));
    exit(0);
}

/** Copy the PHP files and composer.json/lock of the repo into $work (vendor/ of an earlier install is kept). */
function prepareWork(string $repo, string $work, array $excludes): void
{
    if (!is_dir($work) && !mkdir($work, 0700, true)) {
        fwrite(STDERR, "Error: cannot create $work\n");
        exit(EXIT_ENV);
    }
    foreach (scandir($work) ?: [] as $e) {
        if (!in_array($e, ['.', '..', 'vendor', '.install-key'], true)) {
            removeTree("$work/$e");
        }
    }
    foreach (['composer.json', 'composer.lock'] as $f) {
        if (is_file("$repo/$f")) {
            copy("$repo/$f", "$work/$f");
        }
    }
    foreach (listPhpFiles($repo, $excludes) as $f) {
        $dest = $work . substr($f, strlen($repo));
        @mkdir(dirname($dest), 0700, true);
        copy($f, $dest);
    }
}

function removeTree(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $e) {
            if ($e !== '.' && $e !== '..') {
                removeTree("$path/$e");
            }
        }
        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) {
        unlink($path);
    }
}

/** composer install in the scratch copy, with the target PHP. Reused when composer.json/lock did not change. */
function composerInstall(string $php, string $work, bool $offline, bool $dev): array
{
    $composer = trim((string) shell_exec('command -v composer 2>/dev/null'));
    if ($composer === '') {
        return ['ok' => false, 'message' => 'composer not found in PATH'];
    }
    // Run Composer with the target PHP when it is a PHP script or phar, so platform checks use that version.
    $head = (string) @file_get_contents($composer, false, null, 0, 200);
    $base = str_contains($head, 'php') || str_starts_with($head, "\x3c?php") ? [$php, $composer] : [$composer];
    $key = md5(@file_get_contents("$work/composer.json") . @file_get_contents("$work/composer.lock") . $php . ($dev ? 'dev' : '') . ($offline ? 'off' : ''));
    $result = ['ok' => true, 'message' => '', 'platform_php_ignored' => false, 'reused' => false, 'lock' => is_file("$work/composer.lock")];
    $common = ['--no-interaction', '--no-scripts', '--no-plugins', "--working-dir=$work"];
    if ($offline) {
        // No download: only the app's own autoloader; vendor classes will be unresolved.
        [$code, $o] = runCmd([...$base, 'dump-autoload', '--optimize', ...$common]);
        return $code === 0 ? $result + ['offline' => true] : ['ok' => false, 'message' => lastLines($o)];
    }
    if (is_file("$work/.install-key") && file_get_contents("$work/.install-key") === $key && is_dir("$work/vendor")) {
        $result['reused'] = true;
    } else {
        $args = [...$base, 'install', '--no-progress', '--ignore-platform-req=ext-*', ...($dev ? [] : ['--no-dev']), ...$common];
        [$code, $o] = runCmd($args);
        if ($code !== 0 && preg_match('/requires php|php version|__root__ requires php|your php version/i', $o)) {
            $result['platform_php_ignored'] = true;
            $result['message'] = 'composer.lock does not install on PHP ' . trim((string) shell_exec(escapeshellarg($php) . ' -r "echo PHP_VERSION;"'))
                . ' without ignoring its php requirement: ' . lastLines($o);
            [$code, $o] = runCmd([...$args, '--ignore-platform-req=php']);
        }
        if ($code !== 0) {
            return ['ok' => false, 'message' => lastLines($o)];
        }
        file_put_contents("$work/.install-key", $key);
    }
    // The optimized class map lists every class of psr-4/psr-0/classmap, app and vendor.
    [$code, $o] = runCmd([...$base, 'dump-autoload', '--optimize', ...($dev ? [] : ['--no-dev']), ...$common]);
    return $code === 0 ? $result : ['ok' => false, 'message' => lastLines($o)];
}

function lastLines(string $s, int $n = 6): string
{
    $lines = array_values(array_filter(array_map('trim', explode("\n", $s)), fn ($l) => $l !== ''));
    return implode(' | ', array_slice($lines, -$n));
}

function runCmd(array $cmd): array
{
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, getenv() + ['COMPOSER_NO_INTERACTION' => '1']);
    $o = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [proc_close($proc), $o];
}

/**
 * Files mode: which files only declare things (safe to include), a class map, and the constants their guards check.
 * Returns [['files' => [...], 'map' => [class => file]], skipped [{file, reason}], guard constants [...]].
 */
function scanDeclarations(string $root): array
{
    $files = [];
    $map = [];
    $skipped = [];
    $consts = [];
    foreach (listPhpFiles($root, []) as $path) {
        $rel = substr($path, strlen($root) + 1);
        $tokens = array_values(array_filter(token_get_all((string) file_get_contents($path)), fn ($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $ns = '';
        $why = topLevelSideEffect($tokens, $consts);
        foreach ($tokens as $i => $t) {
            if (is_array($t) && $t[0] === T_NAMESPACE && is_array($tokens[$i + 1] ?? null)) {
                $ns = in_array($tokens[$i + 1][0], [T_STRING, T_NAME_QUALIFIED], true) ? $tokens[$i + 1][1] : '';
            }
            if (is_array($t) && in_array($t[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)
                && is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_STRING
                && !(is_array($tokens[$i - 1] ?? null) && in_array($tokens[$i - 1][0], [T_DOUBLE_COLON, T_NEW], true))) {
                $map[strtolower(($ns === '' ? '' : $ns . '\\') . $tokens[$i + 1][1])] = $rel;
            }
        }
        if ($why === null) {
            $files[] = $rel;
        } else {
            $skipped[] = ['file' => $rel, 'reason' => $why];
        }
    }
    return [['files' => $files, 'map' => $map], $skipped, array_values(array_unique($consts))];
}

/** Null when the top level only declares things; otherwise a short reason. Guard constants go to $consts. */
function topLevelSideEffect(array $t, array &$consts): ?string
{
    $n = count($t);
    $id = fn (int $i) => is_array($t[$i] ?? null) ? $t[$i][0] : ($t[$i] ?? null);
    $txt = fn (int $i) => is_array($t[$i] ?? null) ? $t[$i][1] : (string) ($t[$i] ?? '');
    $close = function (int $open) use ($t, $n, $id): int {
        $depth = 0;
        for ($j = $open; $j < $n; $j++) {
            $x = $id($j);
            if (in_array($x, ['{', '(', '[', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
            } elseif (in_array($x, ['}', ')', ']'], true) && --$depth === 0) {
                return $j;
            }
        }
        return $n - 1;
    };
    $stmtEnd = function (int $i) use ($n, $id, $close): int {
        for ($j = $i; $j < $n; $j++) {
            $x = $id($j);
            if ($x === ';' || $x === T_CLOSE_TAG) {
                return $j;
            }
            if (in_array($x, ['(', '[', '{'], true)) {
                $j = $close($j);
            }
        }
        return $n - 1;
    };
    $guardConsts = function (int $a, int $b) use ($txt, $id, &$consts): void {
        for ($k = $a; $k <= $b; $k++) {
            if (strtolower($txt($k)) === 'defined' && $id($k + 1) === '(' && $id($k + 2) === T_CONSTANT_ENCAPSED_STRING) {
                $consts[] = trim($txt($k + 2), '\'"');
            }
        }
    };
    $isDecl = fn (int $i) => in_array($id($i), [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_ABSTRACT, T_FINAL, T_READONLY, T_ATTRIBUTE], true)
        || ($id($i) === T_FUNCTION && $id($i + 1) === T_STRING) || ($id($i) === T_FUNCTION && $id($i + 1) === '&');
    $walk = function (int $from, int $to) use (&$walk, $id, $txt, $close, $stmtEnd, $isDecl, $guardConsts): ?string {
        for ($i = $from; $i <= $to; $i++) {
            $x = $id($i);
            if ($x === null || in_array($x, [T_OPEN_TAG, T_CLOSE_TAG, ';'], true) || ($x === T_INLINE_HTML && trim($txt($i)) === '')) {
                continue;
            }
            if ($x === T_INLINE_HTML) {
                return 'outputs HTML';
            }
            if ($x === T_NAMESPACE) {
                $j = $i + 1;
                while ($j <= $to && !in_array($id($j), [';', '{'], true)) {
                    $j++;
                }
                if ($id($j) === '{') {
                    $c = $close($j);
                    if (($r = $walk($j + 1, $c - 1)) !== null) {
                        return $r;
                    }
                    $j = $c;
                }
                $i = $j;
                continue;
            }
            if (in_array($x, [T_USE, T_DECLARE, T_CONST, T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) {
                $i = $stmtEnd($i);
                continue;
            }
            if ($isDecl($i)) {
                $j = $i;
                while ($j <= $to && $id($j) !== '{') {
                    if ($id($j) === T_ATTRIBUTE) {
                        $j = $close($j);
                    }
                    $j++;
                }
                $i = $close($j);
                continue;
            }
            $end = $stmtEnd($i);
            $stmt = '';
            for ($k = $i; $k <= $end; $k++) {
                $stmt .= strtolower($txt($k)) . ' ';
            }
            // defined('BASEPATH') OR exit('No direct script access allowed');
            if (preg_match('/\bdefined\b/', $stmt) && preg_match('/\b(exit|die)\b/', $stmt) && $x !== T_IF) {
                $guardConsts($i, $end);
                $i = $end;
                continue;
            }
            // if (!defined('ABSPATH')) exit;   if (!function_exists('x')) { function x() {} }
            if ($x === T_IF && $id($i + 1) === '(') {
                $c = $close($i + 1);
                $cond = '';
                for ($k = $i + 2; $k < $c; $k++) {
                    $cond .= strtolower($txt($k));
                }
                if (!preg_match('/defined|function_exists|class_exists|interface_exists|trait_exists|enum_exists|version_compare|php_version/', $cond)) {
                    return 'runs code when included (if)';
                }
                $guardConsts($i, $c);
                if ($id($c + 1) === '{') {
                    $bodyEnd = $close($c + 1);
                    $r = $walk($c + 2, $bodyEnd - 1);
                    if ($r !== null && !preg_match('/^(exits|returns)$/', $r)) {
                        return $r;
                    }
                    $i = $bodyEnd;
                    if ($id($i + 1) === T_ELSE) {
                        return 'runs code when included (else)';
                    }
                    continue;
                }
                $e = $stmtEnd($c + 1);
                $body = '';
                for ($k = $c + 1; $k <= $e; $k++) {
                    $body .= strtolower($txt($k)) . ' ';
                }
                if (!preg_match('/^\s*(exit|die|return)\b/', $body)) {
                    return 'runs code when included (if)';
                }
                $i = $e;
                continue;
            }
            if (in_array($x, [T_EXIT, T_RETURN], true)) {
                return $x === T_EXIT ? 'exits' : 'returns';
            }
            return 'runs code when included (' . (is_int($x) ? token_name($x) : $x) . ')';
        }
        return null;
    };
    $r = $walk(0, $n - 1);
    return $r === 'exits' || $r === 'returns' ? 'runs code when included' : $r;
}

/** Run every unit in its own process ($jobs at a time, $timeout seconds each). */
function runUnits(array $units, int $jobs, int $timeout): array
{
    $results = [];
    $queue = $units;
    $running = [];
    while ($queue !== [] || $running !== []) {
        while (count($running) < $jobs && $queue !== []) {
            $u = array_shift($queue);
            $proc = proc_open($u['cmd'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, sys_get_temp_dir());
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $running[] = ['u' => $u, 'p' => $proc, 'pipes' => $pipes, 'start' => microtime(true), 'out' => '', 'err' => ''];
        }
        foreach ($running as $k => &$r) {
            $r['out'] .= (string) stream_get_contents($r['pipes'][1]);
            $r['err'] .= (string) stream_get_contents($r['pipes'][2]);
            $status = proc_get_status($r['p']);
            if ($status['running'] && microtime(true) - $r['start'] < $timeout) {
                continue;
            }
            if ($status['running']) {
                proc_terminate($r['p'], 9);
                $res = ['ok' => false, 'type' => 'timeout', 'message' => "did not finish within {$timeout}s (code runs while loading?)", 'msgs' => []];
            } else {
                $r['out'] .= (string) stream_get_contents($r['pipes'][1]);
                $res = parseChild($r['out'], $r['err']);
            }
            fclose($r['pipes'][1]);
            fclose($r['pipes'][2]);
            proc_close($r['p']);
            $results[] = $res + ['id' => $r['u']['id'], 'file' => $r['u']['file'] ?? $r['u']['id']];
            unset($running[$k]);
        }
        unset($r);
        usleep(3000);
    }
    return $results;
}

/** The child prints one JSON line; adapter loaders print a list of {type, msg, where}. */
function parseChild(string $out, string $err): array
{
    $line = trim((string) strrchr("\n" . trim($out), "\n"));
    $d = json_decode($line, true);
    if (is_array($d) && array_is_list($d)) { // adapter loader format
        $fatal = array_values(array_filter($d, fn ($m) => ($m['type'] ?? '') === 'Fatal'));
        return ['ok' => $fatal === [], 'type' => $fatal ? 'fatal' : null, 'message' => $fatal[0]['msg'] ?? null, 'where' => $fatal[0]['where'] ?? null,
            'msgs' => array_map(fn ($m) => ['type' => strtolower((string) $m['type']), 'msg' => $m['msg'], 'where' => $m['where']], array_filter($d, fn ($m) => ($m['type'] ?? '') !== 'Fatal'))];
    }
    if (!is_array($d)) {
        return ['ok' => false, 'type' => 'crash', 'message' => trim(substr($err . ' ' . $out, 0, 500)) ?: 'process ended without output', 'msgs' => []];
    }
    return $d;
}

/** Fold child results into the report. $roots: folder => label used to shorten paths. */
function collect(array &$out, array $results, array $roots): void
{
    $short = function (?string $s) use ($roots): ?string {
        if ($s === null) {
            return null;
        }
        foreach ($roots as $dir => $label) {
            $s = str_replace(rtrim($dir, '/') . '/', $label === '' ? '' : "$label/", $s);
        }
        return $s;
    };
    $agg = ['deprecations' => [], 'warnings' => []];
    foreach ($results as $r) {
        $out['units']++;
        foreach ($r['msgs'] ?? [] as $m) {
            // The same message from a shared parent shows up for every child class: keep it once, with a count.
            $bucket = str_contains($m['type'], 'deprecat') ? 'deprecations' : 'warnings';
            $key = $m['msg'] . '|' . $short($m['where']);
            $agg[$bucket][$key] ??= ['message' => $short($m['msg']), 'where' => $short($m['where']), 'count' => 0, 'examples' => []];
            $agg[$bucket][$key]['count']++;
            if (count($agg[$bucket][$key]['examples']) < 3) {
                $agg[$bucket][$key]['examples'][] = $r['id'];
            }
        }
        if ($r['ok'] ?? false) {
            $out['loaded']++;
            continue;
        }
        $msg = (string) $short($r['message'] ?? '');
        $row = ['unit' => $r['id'], 'file' => $short($r['file'] ?? null), 'type' => $r['type'] ?? 'fatal', 'message' => $msg, 'where' => $short($r['where'] ?? null)];
        if (preg_match('/(?:Class|Interface|Trait|Enum) "?([\\\\\w]+)"? not found/i', $msg, $m)) {
            $out['unresolved'][] = $row + ['missing' => ltrim($m[1], '\\')];
        } else {
            $row['type'] = match (true) {
                $row['type'] === 'timeout' => 'timeout',
                str_contains($msg, 'must be compatible') => 'signature',
                str_starts_with($msg, 'ParseError') || str_contains($msg, 'syntax error') => 'parse',
                default => $row['type'],
            };
            $out['failed'][] = $row;
        }
    }
    $out['deprecations'] = array_values($agg['deprecations']);
    $out['warnings'] = array_values($agg['warnings']);
}

/**
 * Child process: load one class (composer mode) or one file (files mode) and print what PHP reported.
 *   --child composer <vendor/autoload.php> <class>
 *   --child files <map.json> <file>
 */
function child(array $a): never
{
    [$mode, $boot, $what] = $a + [null, null, null];
    error_reporting(-1);
    ini_set('display_errors', '0');
    $GLOBALS['__msgs'] = [];
    $GLOBALS['__done'] = false;
    set_error_handler(function (int $no, string $str, string $file, int $line): bool {
        $type = match ($no) {
            E_DEPRECATED, E_USER_DEPRECATED => 'deprecated',
            E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING => 'warning',
            default => 'notice',
        };
        $GLOBALS['__msgs'][] = ['type' => $type, 'msg' => $str, 'where' => "$file:$line"];
        return true;
    });
    register_shutdown_function(function (): void {
        if ($GLOBALS['__done']) {
            return;
        }
        $e = error_get_last();
        $fatal = $e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        echo "\n", json_encode(['ok' => false, 'type' => $fatal ? 'fatal' : 'exit', 'message' => $fatal ? $e['message'] : 'the code stopped the process (exit/die) while loading',
            'where' => $fatal ? "{$e['file']}:{$e['line']}" : null, 'msgs' => $GLOBALS['__msgs']], JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    });
    ob_start();
    $ok = false;
    $fail = null;
    try {
        if ($mode === 'composer') {
            require $boot;
            $ok = $what === '' || class_exists($what) || interface_exists($what) || trait_exists($what) || enum_exists($what);
            $fail = $ok ? null : ['type' => 'fatal', 'message' => "Class \"$what\" not found"];
        } else {
            $cfg = json_decode((string) file_get_contents($boot), true);
            foreach ($cfg['consts'] as $c) {
                if (!defined($c)) {
                    define($c, $cfg['root'] . '/');
                }
            }
            spl_autoload_register(function (string $class) use ($cfg): void {
                $f = $cfg['map'][strtolower(ltrim($class, '\\'))] ?? null;
                if ($f !== null) {
                    require_once $cfg['root'] . '/' . $f;
                }
            });
            require_once $what;
            $ok = true;
        }
    } catch (Throwable $t) {
        $fail = ['type' => $t instanceof ParseError ? 'parse' : 'exception', 'message' => get_class($t) . ': ' . $t->getMessage(), 'where' => $t->getFile() . ':' . $t->getLine()];
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $GLOBALS['__done'] = true;
    echo "\n", json_encode(['ok' => $ok && $fail === null, 'msgs' => $GLOBALS['__msgs']] + ($fail ?? []), JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    exit(0);
}
