<?php
/**
 * Check Composer libraries against the target PHP version. Read-only: only reads the repo's composer.json/lock.
 * Metadata comes from repo.packagist.org, and only for packages that actually come from Packagist
 * (private packages are never sent anywhere).
 *
 * Usage: php check_libs.php <repo> [--target=8.5.12] [--cache=<dir>] [--offline] > libs.json
 */
declare(strict_types=1);

ini_set('display_errors', 'stderr');
require __DIR__ . '/lib.php';
handleHelp($argv, <<<'HELP'
Check the Composer libraries of a repo against the target PHP version. Reads composer.json/composer.lock only.

Usage: php scripts/check_libs.php <repo> [--target=8.5.12] [--cache=<dir>] [--offline] > libs.json

  For every locked package: its "php" constraint vs the target, release date, abandoned flag, and (from
  repo.packagist.org, public packages only) the latest version in the same major and the latest version that
  supports the target. Extensions required by packages that were removed from PHP are listed too.
  Without composer.lock the composer.json constraints are checked. Without composer.json the result is empty.

  --target   PHP version you move to (default: latest covered).
  --cache    Folder for Packagist metadata (cached for 24 h).
  --offline  No network request; only the lock file is used.
HELP);

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;

/** GA release date of every PHP 8 minor, to flag packages released before that version existed. */
const PHP_GA = ['8.0' => '2020-11-26', '8.1' => '2021-11-25', '8.2' => '2022-12-08', '8.3' => '2023-11-23', '8.4' => '2024-11-21', '8.5' => '2025-11-20'];
/** Extensions removed from core up to 8.5. */
const REMOVED_EXT = ['mysql' => '7.0', 'ereg' => '7.0', 'mssql' => '7.0', 'sybase_ct' => '7.0', 'mcrypt' => '7.2', 'wddx' => '7.4', 'recode' => '7.4',
    'interbase' => '7.4', 'xmlrpc' => '8.0', 'imap' => '8.4', 'pspell' => '8.4', 'oci8' => '8.4', 'pdo_oci' => '8.4'];

$repo = $argv[1] ?? '';
if ($repo === '' || !is_dir($repo)) {
    usageError($repo === '' ? 'no repo given.' : "repo folder not found: $repo", 'scripts/check_libs.php');
}
if (toolsVendor() === null) {
    fwrite(STDERR, "Error: " . SETUP_HINT . "\n");
    exit(EXIT_ENV);
}
require toolsVendor() . '/autoload.php';
$target = targetFrom($argv);
$cacheDir = sys_get_temp_dir() . '/php-upgrade-audit-packagist';
$offline = false;
foreach (array_slice($argv, 2) as $arg) {
    if (str_starts_with($arg, '--cache=')) {
        $cacheDir = substr($arg, 8);
    } elseif ($arg === '--offline') {
        $offline = true;
    }
}
$targetMinor = targetMinor($target);
$out = ['tool' => 'check_libs', 'target' => $target, 'composer' => false, 'root' => null, 'packages' => [], 'extensions' => [], 'errors' => []];

$jsonPath = "$repo/composer.json";
$lockPath = "$repo/composer.lock";
if (!is_file($jsonPath)) {
    printJson($out);
    exit(0);
}
$out['composer'] = true;
$root = json_decode((string) file_get_contents($jsonPath), true) ?? [];
$rootPhp = $root['require']['php'] ?? null;
$platformPhp = $root['config']['platform']['php'] ?? null;
$out['root'] = [
    'php' => $rootPhp,
    'allows_target' => $rootPhp === null ? null : safeSatisfies($target, $rootPhp),
    'platform_php' => $platformPhp,
    'has_lock' => is_file($lockPath),
];

$exts = [];
foreach (array_merge($root['require'] ?? [], $root['require-dev'] ?? []) as $name => $c) {
    if (str_starts_with($name, 'ext-')) {
        $exts[substr($name, 4)][] = '(root)';
    }
}

if (is_file($lockPath)) {
    $lock = json_decode((string) file_get_contents($lockPath), true) ?? [];
    $entries = [];
    foreach (['packages' => false, 'packages-dev' => true] as $key => $dev) {
        foreach ($lock[$key] ?? [] as $p) {
            $entries[] = [$p, $dev];
        }
    }
    foreach ($entries as [$p, $dev]) {
        $name = $p['name'];
        foreach ($p['require'] ?? [] as $req => $_) {
            if (str_starts_with($req, 'ext-')) {
                $exts[substr($req, 4)][] = $name;
            }
        }
        $php = $p['require']['php'] ?? null;
        $released = isset($p['time']) ? substr($p['time'], 0, 10) : null;
        $fromPackagist = str_contains($p['notification-url'] ?? '', 'packagist.org');
        $row = [
            'name' => $name,
            'version' => $p['version'],
            'dev' => $dev,
            'php' => $php,
            'released' => $released,
            'satisfies' => $php === null ? null : safeSatisfies($target, $php),
            'source' => $fromPackagist ? 'packagist' : 'lain',
            'risk' => [],
            'suggest' => null,
        ];
        if ($released !== null && $released < PHP_GA['8.0']) {
            $row['risk'][] = 'dirilis sebelum PHP 8.0';
        } elseif ($released !== null && isset(PHP_GA[$targetMinor]) && $released < PHP_GA[$targetMinor]) {
            $row['risk'][] = "dirilis sebelum PHP $targetMinor";
        }
        if ($php !== null && $row['satisfies'] && !preg_match('/[<^~]|\|\|?|-\s/', $php)) {
            $row['risk'][] = 'constraint terbuka tanpa batas atas';
        }
        if (!$fromPackagist) {
            $row['risk'][] = 'package privat/non-Packagist: cek versi terbaru di repo internal';
        }
        if ($fromPackagist && !$offline && ($row['satisfies'] !== true || $row['risk'] !== [])) {
            $meta = packagistVersions($name, $cacheDir, $out['errors']);
            if ($meta !== null) {
                $row['suggest'] = suggest($meta['versions'], $p['version'], $target);
                $row['abandoned'] = $meta['abandoned'];
            }
        }
        $row['status'] = status($row);
        $out['packages'][] = $row;
    }
} else {
    // No lock file: check whether the composer.json constraint still allows a version that supports the target.
    foreach (array_merge(
        array_map(fn ($c) => [$c, false], $root['require'] ?? []),
        array_map(fn ($c) => [$c, true], $root['require-dev'] ?? [])
    ) as $name => [$constraint, $dev]) {
        if ($name === 'php' || str_starts_with($name, 'ext-') || str_starts_with($name, 'lib-') || !str_contains($name, '/')) {
            continue;
        }
        $row = ['name' => $name, 'version' => null, 'constraint' => $constraint, 'dev' => $dev, 'php' => null, 'released' => null,
            'satisfies' => null, 'source' => 'packagist', 'risk' => ['tidak ada composer.lock'], 'suggest' => null];
        $meta = $offline ? null : packagistVersions($name, $cacheDir, $out['errors']);
        if ($meta !== null) {
            $ok = array_filter($meta['versions'], fn ($v) => isStable($v['version']) && safeSatisfies($v['version_normalized'], $constraint) && phpOk($v, $target));
            $row['satisfies'] = $ok !== [];
            $row['suggest'] = suggest($meta['versions'], null, $target);
            $row['abandoned'] = $meta['abandoned'];
        }
        $row['status'] = status($row);
        $out['packages'][] = $row;
    }
}

ksort($exts);
foreach ($exts as $ext => $by) {
    $out['extensions'][] = [
        'name' => $ext,
        'required_by' => array_values(array_unique($by)),
        // Only extensions already gone in the target version (imap is fine on a move to 8.3, gone in 8.4).
        'removed_in' => isset(REMOVED_EXT[strtolower($ext)]) && appliesTo(REMOVED_EXT[strtolower($ext)], $target) ? REMOVED_EXT[strtolower($ext)] : null,
        'loaded_locally' => extension_loaded($ext),
    ];
}

printJson($out);

function safeSatisfies(string $version, string $constraint): ?bool
{
    try {
        return Semver::satisfies($version, $constraint);
    } catch (Throwable) {
        return null;
    }
}

function isStable(string $version): bool
{
    return VersionParser::parseStability($version) === 'stable';
}

function phpOk(array $v, string $target): bool
{
    $php = $v['require']['php'] ?? null;
    return $php === null || safeSatisfies($target, $php) === true;
}

/** Short status for the report: aman (safe) | perlu-upgrade (upgrade) | tidak-didukung (unsupported) | cek-manual (check). */
function status(array $row): string
{
    if (!empty($row['abandoned'])) {
        return $row['satisfies'] === false ? 'tidak-didukung' : 'cek-manual';
    }
    if ($row['satisfies'] === false) {
        return ($row['suggest']['latest_compatible'] ?? null) !== null ? 'perlu-upgrade' : 'tidak-didukung';
    }
    // An open constraint (">=8.1") alone is normal for maintained packages and stays a tag; measured on a
    // modern Symfony app, all 115 packages flagged only for that loaded cleanly on 8.5.
    if ($row['satisfies'] === null || in_array('dirilis sebelum PHP 8.0', $row['risk'], true)) {
        return 'cek-manual';
    }
    return 'aman';
}

/**
 * Version advice: lowest and latest stable version in the same major, and the latest stable version that supports the target.
 */
function suggest(array $versions, ?string $current, string $target): array
{
    $stable = array_values(array_filter($versions, fn ($v) => isStable($v['version'])));
    usort($stable, fn ($a, $b) => version_compare($a['version_normalized'], $b['version_normalized']));
    $compatible = array_values(array_filter($stable, fn ($v) => phpOk($v, $target)));
    $latest = end($stable) ?: null;
    $latestCompat = end($compatible) ?: null;
    $sameMajor = null;
    $latestSameMajor = null;
    if ($current !== null) {
        $major = explode('.', ltrim($current, 'vV'))[0];
        $currentNorm = (new VersionParser())->normalize($current);
        foreach ($compatible as $v) {
            if (explode('.', ltrim($v['version'], 'vV'))[0] === $major && version_compare($v['version_normalized'], $currentNorm, '>=')) {
                $sameMajor ??= $v;
                $latestSameMajor = $v;
            }
        }
    }
    $pick = fn (?array $v) => $v === null ? null : ['version' => $v['version'], 'php' => $v['require']['php'] ?? null, 'released' => isset($v['time']) ? substr($v['time'], 0, 10) : null];
    return [
        'min_same_major' => $pick($sameMajor),
        'latest_same_major' => $pick($latestSameMajor),
        'latest_compatible' => $pick($latestCompat),
        'latest' => $pick($latest),
    ];
}

/** Fetch p2 metadata from Packagist (minified format) and expand it. */
function packagistVersions(string $name, string $cacheDir, array &$errors): ?array
{
    if (!preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#', $name)) {
        return null;
    }
    @mkdir($cacheDir, 0700, true);
    $cache = $cacheDir . '/' . str_replace('/', '~', $name) . '.json';
    if (is_file($cache) && filemtime($cache) > time() - 86400) {
        $body = file_get_contents($cache);
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 20, 'user_agent' => 'php-upgrade-audit (+local audit skill)', 'ignore_errors' => true]]);
        // Read the status line from the stream metadata: works on every PHP 8.x
        // ($http_response_header is deprecated in 8.5, http_get_last_response_headers() needs 8.4).
        $body = false;
        $code = 0;
        $fp = @fopen("https://repo.packagist.org/p2/$name.json", 'r', false, $ctx);
        if ($fp !== false) {
            $meta = stream_get_meta_data($fp);
            foreach ($meta['wrapper_data'] ?? [] as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $h, $m)) {
                    $code = (int) $m[1];
                }
            }
            $body = stream_get_contents($fp);
            fclose($fp);
        }
        if ($body === false || $code !== 200) {
            $errors[] = "Packagist: gagal ambil $name (HTTP $code)";
            return null;
        }
        file_put_contents($cache, $body);
    }
    $data = json_decode((string) $body, true);
    $list = $data['packages'][$name] ?? null;
    if (!is_array($list)) {
        return null;
    }
    $expanded = [];
    $prev = null;
    foreach ($list as $v) {
        if ($prev === null) {
            $cur = $v;
        } else {
            $cur = $prev;
            foreach ($v as $k => $val) {
                if ($val === '__unset') {
                    unset($cur[$k]);
                } else {
                    $cur[$k] = $val;
                }
            }
        }
        $expanded[] = $cur;
        $prev = $cur;
    }
    $abandoned = false;
    foreach ($expanded as $v) {
        if (!empty($v['abandoned'])) {
            $abandoned = $v['abandoned'];
            break;
        }
    }
    return ['versions' => $expanded, 'abandoned' => $abandoned];
}
