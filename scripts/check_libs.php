<?php
/**
 * Check Composer libraries against the target PHP version. Read-only: only reads the repo's composer.json/lock.
 * Metadata and known security advisories come from packagist.org, and only for packages that actually come
 * from Packagist (private packages are never sent anywhere).
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
  Known security advisories (CVE/GHSA, from the Packagist security advisories API, the data "composer audit"
  uses) that affect the installed version are listed per package, with the lowest version that fixes them.
  Without composer.lock the composer.json constraints are checked. Without composer.json the result is empty.

  --target   PHP version you move to (default: latest covered).
  --cache    Folder for Packagist metadata (cached for 24 h) and advisories (cached for 6 h).
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
$out = ['tool' => 'check_libs', 'target' => $target, 'composer' => false, 'root' => null, 'packages' => [], 'extensions' => [], 'errors' => [],
    'advisories' => ['checked' => false, 'source' => 'https://packagist.org/apidoc#list-security-advisories', 'count' => 0]];

$jsonPath = "$repo/composer.json";
$lockPath = "$repo/composer.lock";
$bundled = bundledComposerInstalls($repo);
$exts = [];
if (!is_file($jsonPath)) {
    // No Composer at the root, but libraries may be committed with their own vendor/ (checked below).
    foreach ($bundled as $b) {
        foreach ($b['packages'] as $p) {
            $out['packages'][] = packageRow($p, false, $target, $offline, $cacheDir, $out['errors'], $exts) + ['bundled_in' => $b['path']];
        }
    }
    $out['extensions'] = extensionRows($exts, $target);
    attachAdvisories($out, $target, $offline, $cacheDir);
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
        $out['packages'][] = packageRow($p, $dev, $target, $offline, $cacheDir, $out['errors'], $exts);
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

foreach ($bundled as $b) {
    foreach ($b['packages'] as $p) {
        $out['packages'][] = packageRow($p, false, $target, $offline, $cacheDir, $out['errors'], $exts) + ['bundled_in' => $b['path']];
    }
}
$out['extensions'] = extensionRows($exts, $target);
attachAdvisories($out, $target, $offline, $cacheDir);

printJson($out);

/**
 * Security advisories that affect the installed version of each public package, and the lowest stable version
 * at or above it that none of the package's advisories affects and that supports the target ("fixed_in").
 */
function attachAdvisories(array &$out, string $target, bool $offline, string $cacheDir): void
{
    $names = [];
    foreach ($out['packages'] as $p) {
        if (($p['source'] ?? '') === 'packagist' && ($p['version'] ?? null) !== null) {
            $names[$p['name']] = true;
        }
    }
    if ($offline || $names === []) {
        return;
    }
    [$all, $ok] = packagistAdvisories(array_keys($names), $cacheDir, $out['errors']);
    $out['advisories']['checked'] = $ok;
    $parser = new VersionParser();
    foreach ($out['packages'] as &$p) {
        $list = $all[$p['name']] ?? [];
        if ($list === [] || ($p['version'] ?? null) === null) {
            continue;
        }
        $version = ltrim((string) $p['version'], 'vV');
        $hits = array_values(array_filter($list, fn ($a) => safeSatisfies($version, (string) ($a['affectedVersions'] ?? '')) === true));
        if ($hits === []) {
            continue;
        }
        $p['advisories'] = [];
        foreach ($hits as $a) {
            $ghsa = null;
            foreach (array_merge([['remoteId' => $a['remoteId'] ?? '']], $a['sources'] ?? []) as $src) {
                if (preg_match('/^GHSA(-[23456789cfghjmpqrvwx]{4}){3}$/', (string) ($src['remoteId'] ?? ''))) {
                    $ghsa = $src['remoteId'];
                    break;
                }
            }
            $cve = preg_match('/^CVE-\d{4}-\d+$/', (string) ($a['cve'] ?? '')) ? $a['cve'] : null;
            $key = $cve ?? $ghsa ?? ($a['advisoryId'] ?? '');
            if (isset($p['advisories'][$key])) {
                continue; // the same CVE from two sources
            }
            $link = preg_match('#^https://[^\s]+$#', (string) ($a['link'] ?? '')) ? $a['link'] : null;
            $p['advisories'][$key] = [
                'id' => $ghsa ?? $cve ?? ($a['advisoryId'] ?? null),
                'cve' => $cve,
                'title' => (string) ($a['title'] ?? ''),
                'severity' => $a['severity'] ?? null,
                'affected' => (string) ($a['affectedVersions'] ?? ''),
                'reported' => isset($a['reportedAt']) ? substr((string) $a['reportedAt'], 0, 10) : null,
                'url' => $link ?? ($ghsa !== null ? "https://github.com/advisories/$ghsa" : null),
                'cve_url' => $cve === null ? null : "https://www.cve.org/CVERecord?id=$cve",
            ];
        }
        $p['advisories'] = array_values($p['advisories']);
        $hits = $p['advisories'];
        $out['advisories']['count'] += count($hits);
        $meta = packagistVersions($p['name'], $cacheDir, $out['errors']);
        if ($meta === null) {
            continue;
        }
        try {
            $current = $parser->normalize($version);
        } catch (Throwable) {
            continue;
        }
        $candidates = array_filter($meta['versions'], fn ($v) => isStable($v['version']) && version_compare($v['version_normalized'], $current, '>=')
            && phpOk($v, $target) && !array_filter($list, fn ($a) => safeSatisfies(ltrim($v['version'], 'vV'), (string) ($a['affectedVersions'] ?? '')) === true));
        usort($candidates, fn ($a, $b) => version_compare($a['version_normalized'], $b['version_normalized']));
        $fix = $candidates[0] ?? null;
        $p['fixed_in'] = $fix === null ? null : ['version' => $fix['version'], 'php' => $fix['require']['php'] ?? null, 'released' => isset($fix['time']) ? substr($fix['time'], 0, 10) : null];
    }
    unset($p);
}

/**
 * Advisories per package from the Packagist security advisories API (GitHub Advisory Database and
 * FriendsOfPHP). Returns [name => list, all answered?]. Each package's answer is cached for 6 hours.
 */
function packagistAdvisories(array $names, string $cacheDir, array &$errors): array
{
    @mkdir($cacheDir, 0700, true);
    $result = [];
    $todo = [];
    foreach ($names as $name) {
        if (!preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#', $name)) {
            continue;
        }
        $cache = "$cacheDir/advisories~" . str_replace('/', '~', $name) . '.json';
        $cached = is_file($cache) && filemtime($cache) > time() - 6 * 3600 ? json_decode((string) file_get_contents($cache), true) : null;
        if (is_array($cached)) {
            $result[$name] = $cached;
        } else {
            $todo[] = $name;
        }
    }
    $ok = true;
    foreach (array_chunk($todo, 40) as $chunk) {
        $query = implode('&', array_map(fn ($n) => 'packages[]=' . rawurlencode($n), $chunk));
        $ctx = stream_context_create(['http' => ['timeout' => 20, 'user_agent' => 'php-upgrade-audit (+local audit skill)']]);
        $data = json_decode((string) @file_get_contents("https://packagist.org/api/security-advisories/?$query", false, $ctx), true);
        if (!is_array($data) || !is_array($data['advisories'] ?? null)) {
            $errors[] = 'Packagist: gagal ambil security advisories (' . count($chunk) . ' package)';
            $ok = false;
            continue;
        }
        foreach ($chunk as $name) {
            $list = array_values((array) ($data['advisories'][$name] ?? []));
            $result[$name] = $list;
            file_put_contents("$cacheDir/advisories~" . str_replace('/', '~', $name) . '.json', json_encode($list, JSON_UNESCAPED_SLASHES));
        }
    }
    return [$result, $ok];
}

function extensionRows(array $exts, string $target): array
{
    ksort($exts);
    $rows = [];
    foreach ($exts as $ext => $by) {
        $rows[] = [
            'name' => $ext,
            'required_by' => array_values(array_unique($by)),
            // Only extensions already gone in the target version (imap is fine on a move to 8.3, gone in 8.4).
            'removed_in' => isset(REMOVED_EXT[strtolower($ext)]) && appliesTo(REMOVED_EXT[strtolower($ext)], $target) ? REMOVED_EXT[strtolower($ext)] : null,
            'loaded_locally' => extension_loaded($ext),
        ];
    }
    return $rows;
}

/** One locked (or bundled) package checked against the target; extensions it requires go to $exts. */
function packageRow(array $p, bool $dev, string $target, bool $offline, string $cacheDir, array &$errors, array &$exts): array
{
    $targetMinor = targetMinor($target);
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
    $meta = null;
    if ($fromPackagist && !$offline && ($row['satisfies'] !== true || $row['risk'] !== [])) {
        $meta = packagistVersions($name, $cacheDir, $errors);
        if ($meta !== null) {
            $row['suggest'] = suggest($meta['versions'], $p['version'], $target);
            $row['abandoned'] = $meta['abandoned'];
        }
    }
    $row['status'] = status($row);
    $row['links'] = packageLinks($p, $fromPackagist, $row['suggest'], $meta);
    $row['upgrade_notes'] = upgradeNotes($name, (string) $p['version']);
    return $row;
}

/**
 * Where to check a package: Packagist page, source repository and the release page of the suggested version.
 * Public hosts only; a private package's repository URL is never put in the report.
 */
function packageLinks(array $p, bool $fromPackagist, ?array $suggest, ?array $meta): array
{
    if (!$fromPackagist) {
        return [];
    }
    $links = ['packagist' => 'https://packagist.org/packages/' . $p['name']];
    $latest = $meta === null ? [] : (end($meta['versions']) ?: []);
    $url = (string) ($p['source']['url'] ?? $p['support']['source'] ?? $latest['source']['url'] ?? '');
    $url = (string) preg_replace(['#^git@([^:]+):#', '#\.git$#'], ['https://$1/', ''], $url);
    if (preg_match('#^https://(github\.com|gitlab\.com|bitbucket\.org)/[\w.-]+/[\w.-]+#', $url, $m)) {
        $links['source'] = $m[0];
        $v = $suggest['latest_compatible']['version'] ?? null;
        if ($v !== null && $m[1] === 'github.com') {
            $links['release'] = $m[0] . '/releases/tag/' . rawurlencode($v);
        }
    }
    return $links;
}

/** Entries of library_notes.php whose "from" constraint matches the installed version. */
function upgradeNotes(string $name, string $version): array
{
    static $catalogue = null;
    $catalogue ??= require __DIR__ . '/library_notes.php';
    $out = [];
    foreach ($catalogue as $n) {
        if ($n['package'] === $name && safeSatisfies(ltrim($version, 'vV'), $n['from']) === true) {
            unset($n['package']);
            $out[] = $n;
        }
    }
    return $out;
}

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
