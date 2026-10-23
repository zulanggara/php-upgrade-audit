<?php
/**
 * Checks scan_logs.php on fixtures/logs-app (+ fixtures/logs-external/php_errors.log): exactly the expected
 * groups, nothing from notices or the application's own messages, and nothing private in the output.
 *
 * Usage: php check_logs.php <logs.json> [<secret that must not appear>]...
 */
declare(strict_types=1);

$d = json_decode((string) @file_get_contents($argv[1] ?? ''), true);
if (!is_array($d)) {
    fwrite(STDERR, "Usage: php check_logs.php <logs.json> [secret]...\n");
    exit(2);
}
$expected = [
    'fatal|-|app/Http/Controllers/Report.php:8|1',
    'fatal|-|src/Legacy.php:6|1',
    'fatal|P80-REMOVED-FUNC|system/core/Loader.php:1035|1',
    'deprecated|P81-NULL-TO-INTERNAL|app/Http/Controllers/Report.php:8|2',
    'deprecated|P81-NULL-TO-INTERNAL|application/libraries/Local.php:7|2',
    'deprecated|P82-DYNAMIC-PROP|src/Legacy.php:6|2',
    'deprecated|P85-FREE-FUNC|src/Legacy.php:6|1',
    'deprecated|P81-NULL-TO-INTERNAL|src/Legacy.php:6|1',
    'deprecated|P81-NULL-TO-INTERNAL|system/core/Input.php:400|1',
    'deprecated|P81-NULL-TO-INTERNAL|vendor/acme/lib/src/Text.php:30|1',
    'warning|-|application/libraries/Local.php:7|2',
    'warning|-|app/Http/Controllers/Report.php:8|1',
    'warning|-|application/libraries/Local.php:7|1',
    'warning|-|src/Legacy.php:6|1',
];
$problems = [];
$got = array_map(fn ($e) => "{$e['level']}|" . ($e['rule'] ?? '-') . "|{$e['file']}:{$e['line']}|{$e['count']}", $d['entries'] ?? []);
$extraLines = array_slice($argv, 2);
if ($extraLines === []) {
    $a = $expected;
    $b = $got;
    sort($a);
    sort($b);
    if ($a !== $b) {
        $problems[] = 'groups differ. missing: ' . implode(', ', array_diff($expected, $got)) . '; unexpected: ' . implode(', ', array_diff($got, $expected));
    }
    if (($d['totals'] ?? []) !== ['fatal' => 3, 'deprecated' => 7, 'warning' => 4, 'occurrences' => 18]) {
        $problems[] = 'totals: ' . json_encode($d['totals'] ?? null);
    }
    $inRepo = array_filter($d['entries'], fn ($e) => $e['in_repo']);
    if (count($inRepo) !== 11) {
        $problems[] = 'expected 11 entries inside the repo, got ' . count($inRepo);
    }
    $formats = array_merge(...array_column($d['sources'], 'formats'));
    sort($formats);
    if ($formats !== ['codeigniter3', 'laravel', 'php']) {
        $problems[] = 'formats: ' . implode(',', $formats);
    }
    foreach ($d['sources'] as $s) {
        if (($s['path'] === 'php_errors.log') === $s['auto']) {
            $problems[] = "auto flag wrong for {$s['path']}";
        }
    }
    foreach ($d['entries'] as $e) {
        if ($e['last_seen'] === null) {
            $problems[] = "no time for {$e['message']}";
        }
    }
}
$json = json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
foreach (['Only variables should be passed', 'Payment gateway', 'Order 77', 'No query results', 'Config Class Initialized', '/var/www/', '/srv/app/'] as $never) {
    if (str_contains($json, $never)) {
        $problems[] = "must not be in the output: $never";
    }
}
foreach ($extraLines as $secret) {
    if (str_contains($json, $secret)) {
        $problems[] = 'a secret or private value is in the output: ' . substr($secret, 0, 6) . '…';
    }
}
foreach ($problems as $p) {
    fwrite(STDERR, "  $p\n");
}
exit($problems === [] ? 0 : 1);
