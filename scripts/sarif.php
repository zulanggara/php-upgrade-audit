<?php
/**
 * Convert a scan (report.json) to SARIF 2.1.0, so CI can show the findings as annotations on a pull request
 * (GitHub code scanning: github/codeql-action/upload-sarif). Read-only; writes to stdout or --out.
 *
 * Run with --help for the options.
 */
declare(strict_types=1);

ini_set('display_errors', 'stderr');
require __DIR__ . '/lib.php';

handleHelp($argv, <<<'HELP'
Convert one scan to SARIF 2.1.0 for code scanning in CI.

Usage:
  php scripts/sarif.php <store>/<name>/scans/<id>/report.json [--repo=<repo>] [--out=<file.sarif>]
  (or: php scripts/audit.php <repo> --out=<store> --sarif=<file.sarif>)

Contents: scanner findings (guarded code that does not run on the target left out), php -l errors and
deprecations, classes that fail to load, libraries that do not support the target, security advisories and
removed extensions. Levels: wajib = error, deprecated = warning, perilaku/info = note. Messages are in
English; every rule links to its php.net page.

  --repo   The audited repo, to point library results at their line in composer.lock (default: line 1).
  --out    Write here instead of stdout. Must be outside the audited repo.

GitHub Actions:
  - run: php scripts/audit.php . --out="$RUNNER_TEMP/store" --sarif="$RUNNER_TEMP/php-upgrade.sarif"
  - uses: github/codeql-action/upload-sarif@v3
    with: { sarif_file: "${{ runner.temp }}/php-upgrade.sarif", category: php-upgrade-audit }
HELP);

$input = '';
foreach (array_slice($argv, 1) as $a) {
    if (!str_starts_with($a, '--')) {
        $input = $a;
        break;
    }
}
if ($input === '' || !is_file($input)) {
    usageError($input === '' ? 'no report.json given.' : "file not found: $input", 'scripts/sarif.php');
}
$report = json_decode((string) file_get_contents($input), true);
if (!is_array($report) || !isset($report['scan'])) {
    usageError("$input is not a report.json of audit.php.", 'scripts/sarif.php');
}
$repo = opt($argv, 'repo');
$repo = $repo !== null && is_dir($repo) ? rtrim((string) realpath($repo), '/') : null;
$out = opt($argv, 'out');
if ($out !== null && $repo !== null && str_starts_with(absPath($out) . '/', $repo . '/')) {
    usageError('--out must be outside the audited repo.', 'scripts/sarif.php');
}

$sarif = toSarif($report, $repo);
$json = json_encode(redact_data($sarif), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
if ($out !== null) {
    file_put_contents($out, $json);
    fwrite(STDERR, 'SARIF: ' . count($sarif['runs'][0]['results']) . " result(s) -> $out\n");
} else {
    echo $json;
}

function toSarif(array $report, ?string $repo): array
{
    $en = require __DIR__ . '/rules_en.php';
    $level = ['wajib' => 'error', 'deprecated' => 'warning', 'perilaku' => 'note', 'info' => 'note'];
    $target = (string) ($report['target'] ?? '');
    $rules = [];
    $results = [];
    $rule = function (string $id, string $sev, string $title, string $help, ?string $uri, array $extra = []) use (&$rules, $level): void {
        $rules[$id] ??= array_filter([
            'id' => $id,
            'shortDescription' => ['text' => $title],
            'fullDescription' => ['text' => $help !== '' ? $help : $title],
            'helpUri' => $uri,
            'defaultConfiguration' => ['level' => $level[$sev] ?? 'note'],
            'properties' => ['tags' => array_values(array_filter(['php-upgrade', $sev, ...$extra]))],
        ], fn ($v) => $v !== null);
    };
    $result = function (string $id, string $sev, string $message, string $file, int $line, array $props = []) use (&$results, $level): void {
        $results[] = array_filter([
            'ruleId' => $id,
            'level' => $level[$sev] ?? 'note',
            'message' => ['text' => $message],
            'locations' => [['physicalLocation' => [
                'artifactLocation' => ['uri' => ltrim(str_replace('\\', '/', $file), '/'), 'uriBaseId' => '%SRCROOT%'],
                'region' => ['startLine' => max(1, $line)],
            ]]],
            'properties' => $props ?: null,
        ], fn ($v) => $v !== null);
    };

    $meta = $report['scan']['rules'] ?? [];
    foreach ($report['scan']['findings'] ?? [] as $f) {
        if (!empty($f['guarded'])) {
            continue; // in a branch that does not run on the target
        }
        $m = $meta[$f['rule']] ?? [];
        $sev = $m['sev'] ?? 'info';
        [$title, $fix] = $en[$f['rule']] ?? [$m['title'] ?? $f['rule'], $m['fix'] ?? ''];
        $rule($f['rule'], $sev, $title, $fix, $m['ref'] ?? null, ['PHP ' . ($m['ver'] ?? '?')]);
        $detail = isset($f['detail']) && is_array($f['detail']) ? finding_detail_text($f['detail']) . ' ' : '';
        $result($f['rule'], $sev, $detail . rtrim($title, '.') . ". $fix (target PHP $target)" . (($f['guard_state'] ?? '') === '?' ? ' The code is guarded by a check that needs a look by hand.' : ''), $f['file'], (int) $f['line'],
            array_filter(['confidence' => $f['conf'] ?? $m['conf'] ?? null]));
    }
    foreach ($report['lint']['errors'] ?? [] as $e) {
        $rule('LINT-ERROR', 'wajib', 'php -l error on the target PHP', 'The file does not compile on the PHP that ran php -l.', null);
        $result('LINT-ERROR', 'wajib', "php -l on PHP " . ($report['lint']['php'] ?? '?') . ": {$e['type']}: {$e['message']}", $e['file'], (int) $e['line']);
    }
    foreach ($report['lint']['deprecations'] ?? [] as $e) {
        $rule('LINT-DEPRECATED', 'deprecated', 'php -l deprecation on the target PHP', 'Compile-time deprecation reported by php -l.', null);
        $result('LINT-DEPRECATED', 'deprecated', "php -l on PHP " . ($report['lint']['php'] ?? '?') . ": {$e['message']}", $e['file'], (int) $e['line']);
    }
    foreach ($report['runtime']['failed'] ?? [] as $e) {
        [$file, $line] = array_pad(explode(':', (string) ($e['where'] ?? $e['file'] ?? '')), 2, '1');
        $rule('RUNTIME-LOAD-FAILED', 'wajib', 'Class does not load on the target PHP', 'Loading the class on the target PHP stopped with an error (signature conflict, parse or fatal error).', null);
        $result('RUNTIME-LOAD-FAILED', 'wajib', "Loading {$e['unit']} on PHP " . ($report['runtime']['php'] ?? '?') . " failed: {$e['message']}", $file !== '' ? $file : ($e['file'] ?? 'composer.json'), (int) $line);
    }
    $lockLine = lockLines($repo);
    foreach ($report['libs']['packages'] ?? [] as $p) {
        $where = isset($p['bundled_in']) ? $p['bundled_in'] . '/composer/installed.json' : (($report['libs']['root']['has_lock'] ?? false) ? 'composer.lock' : 'composer.json');
        $line = $where === 'composer.lock' ? ($lockLine[$p['name']] ?? 1) : 1;
        if (in_array($p['status'] ?? '', ['tidak-didukung', 'perlu-upgrade'], true)) {
            $to = $p['suggest']['latest_compatible']['version'] ?? null;
            $rule('LIB-UNSUPPORTED', 'wajib', 'Library does not support the target PHP', 'The installed version does not allow the target PHP; upgrade or replace it.', null);
            $result('LIB-UNSUPPORTED', 'wajib', "{$p['name']} {$p['version']} requires php {$p['php']}, which does not allow PHP $target." . ($to ? " Upgrade to $to." : ' No version supports the target: replace it.'), $where, $line);
        }
        foreach ($p['advisories'] ?? [] as $a) {
            $rule('LIB-ADVISORY', 'wajib', 'Known security advisory in an installed library', 'A published security advisory (GitHub Advisory Database, FriendsOfPHP) affects the installed version.', null, ['security']);
            $fix = ($p['fixed_in']['version'] ?? null) ? " Fixed in {$p['fixed_in']['version']}." : '';
            $result('LIB-ADVISORY', 'wajib', trim("{$p['name']} {$p['version']}: " . strtoupper((string) ($a['severity'] ?? '')) . " {$a['id']} " . ($a['cve'] && $a['cve'] !== $a['id'] ? "({$a['cve']}) " : '') . "{$a['title']}.$fix" . ($a['url'] ? " {$a['url']}" : '')), $where, $line);
        }
    }
    foreach ($report['libs']['extensions'] ?? [] as $e) {
        if (!empty($e['removed_in'])) {
            $rule('EXT-REMOVED', 'wajib', 'Required extension is no longer part of PHP', 'An extension required by the project or a library was removed from PHP core.', null);
            $result('EXT-REMOVED', 'wajib', "ext-{$e['name']} (required by " . implode(', ', $e['required_by']) . ") was removed from PHP in {$e['removed_in']}.", 'composer.json', 1);
        }
    }
    $kit = $report['kit'] ?? [];
    return [
        '$schema' => 'https://json.schemastore.org/sarif-2.1.0.json',
        'version' => '2.1.0',
        'runs' => [[
            'tool' => ['driver' => array_filter([
                'name' => 'php-upgrade-audit',
                'version' => $kit['version'] ?? null,
                'informationUri' => 'https://github.com/zulanggara/php-upgrade-audit',
                'rules' => array_values($rules),
            ], fn ($v) => $v !== null)],
            'automationDetails' => ['id' => 'php-upgrade-audit/' . ($report['name'] ?? 'repo') . '/' . ($report['scan_id'] ?? '')],
            'properties' => ['target' => $target, 'php_runtime' => $report['php_runtime'] ?? null],
            'results' => $results,
        ]],
    ];
}

/** Line of each package's "name" in composer.lock, so the annotation lands on the package. */
function lockLines(?string $repo): array
{
    $lines = [];
    if ($repo === null || !is_file("$repo/composer.lock")) {
        return $lines;
    }
    foreach (file("$repo/composer.lock") as $i => $l) {
        if (preg_match('/^\s*"name":\s*"([^"]+)"/', $l, $m)) {
            $lines[$m[1]] ??= $i + 1;
        }
    }
    return $lines;
}
