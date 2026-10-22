<?php
/**
 * php -l on every file with the target PHP. Catches parse/fatal errors and compile-time deprecations.
 * Read-only: php -l only compiles, it never runs the code.
 *
 * Usage: php lint.php <repo> [--php=/path/php] [--jobs=8] [--exclude=a,b] > lint.json
 */
declare(strict_types=1);

ini_set('display_errors', 'stderr');
require __DIR__ . '/lib.php';

handleHelp($argv, <<<'HELP'
Run "php -l" on every PHP file with the given PHP binary: parse errors and compile-time deprecations.
php -l only compiles, it never executes the code. Normally called by audit.php.

Usage: php scripts/lint.php <repo> [--php=/path/to/php] [--jobs=8] [--exclude=a,b] > lint.json

  --php   PHP binary to lint with; use the TARGET version (default: the PHP running this script).
  --jobs  Parallel processes (default 8).
HELP);

$repo = $argv[1] ?? '';
if ($repo === '' || !is_dir($repo)) {
    usageError($repo === '' ? 'no repo given.' : "repo folder not found: $repo", 'scripts/lint.php');
}
$repo = rtrim(realpath($repo), '/');
$php = opt($argv, 'php', PHP_BINARY);
$jobs = max(1, (int) opt($argv, 'jobs', '8'));
$files = listPhpFiles($repo, excludesFrom($argv));
$version = trim((string) shell_exec(escapeshellarg($php) . ' -r "echo PHP_VERSION;" 2>/dev/null'));
if ($version === '') {
    fwrite(STDERR, "Error: cannot run the PHP binary $php (--php).\n");
    exit(EXIT_ENV);
}

$results = [];
$queue = $files;
$running = [];
while ($queue !== [] || $running !== []) {
    while (count($running) < $jobs && $queue !== []) {
        $file = array_shift($queue);
        $cmd = [$php, '-n', '-d', 'error_reporting=-1', '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-l', $file];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $running[] = [$proc, $pipes, $file];
    }
    foreach ($running as $k => [$proc, $pipes, $file]) {
        if (proc_get_status($proc)['running']) {
            continue;
        }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        unset($running[$k]);
        if (preg_match_all('/^(?:PHP )?(Parse error|Fatal error|Deprecated|Warning|Notice):\s+(.*?) in (.+?) on line (\d+)\s*$/m', $output, $m, PREG_SET_ORDER)) {
            foreach ($m as [, $type, $msg, $path, $line]) {
                $results[] = [
                    'type' => $type,
                    'file' => str_starts_with($path, $repo . '/') ? substr($path, strlen($repo) + 1) : $path,
                    'line' => (int) $line,
                    'message' => $msg,
                ];
            }
        }
    }
    usleep(5000);
}
usort($results, fn ($a, $b) => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);
$results = array_values(array_unique($results, SORT_REGULAR));

printJson([
    'tool' => 'lint',
    'php' => $version,
    'files_linted' => count($files),
    'errors' => array_values(array_filter($results, fn ($r) => in_array($r['type'], ['Parse error', 'Fatal error'], true))),
    'deprecations' => array_values(array_filter($results, fn ($r) => !in_array($r['type'], ['Parse error', 'Fatal error'], true))),
]);
