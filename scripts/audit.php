<?php
/**
 * Run the full audit for one repo and store it as a NEW version in the history store:
 *   <out>/<name>/scans/<scan-id>/{profile,scan,lint,libs,phpcompat,rector,report}.json
 * Earlier scans are never touched. Notes of the previous scan are carried over (marked as not yet reviewed).
 * Read-only towards the repo: outputs, tool config and caches all live under <out>.
 *
 * Usage: php audit.php <repo> --out=<dir> [--target=8.5.12] [--name=label] [--exclude=a,b]
 *                             [--skip=phpcompat,rector,libs] [--offline]
 */
declare(strict_types=1);

ini_set('display_errors', 'stderr');
require __DIR__ . '/lib.php';
require __DIR__ . '/history.php';

$repo = $argv[1] ?? '';
$out = opt($argv, 'out');
if ($repo === '' || !is_dir($repo) || $out === null) {
    fwrite(STDERR, "Usage: php audit.php <repo> --out=<dir> [--target=8.5.12] [--name=label] [--exclude=a,b] [--skip=phpcompat,rector,libs] [--offline]\n");
    exit(2);
}
$repo = rtrim(realpath($repo), '/');
@mkdir($out, 0700, true);
$out = realpath($out);
if (str_starts_with($out . '/', $repo . '/')) {
    fwrite(STDERR, "The output folder must not be inside the audited repo.\n");
    exit(2);
}
$target = targetFrom($argv);
$name = opt($argv, 'name', basename($repo));
$skip = array_filter(explode(',', (string) opt($argv, 'skip', '')));
$exclude = opt($argv, 'exclude');

$entity = entity_dir($out, $name);
@mkdir($entity, 0700, true);
history_migrate($out); // older flat output of this store becomes version 1
$previous = history_latest($entity);
[$id, $dir] = history_new_scan_dir($entity, scan_id());

$common = $exclude ? ["--exclude=$exclude"] : [];
$steps = [
    'profile' => ['detect.php', [$repo, ...$common]],
    'scan' => ['scan_code.php', [$repo, "--target=$target", ...$common]],
    'lint' => ['lint.php', [$repo, ...$common]],
];
if (!in_array('libs', $skip, true)) {
    $steps['libs'] = ['check_libs.php', [$repo, "--target=$target", "--cache=$out/.cache/packagist", ...(opt($argv, 'offline') ? ['--offline'] : [])]];
}

$report = ['schema' => 2, 'name' => $name, 'scan_id' => $id, 'target' => $target, 'generated_at' => date('c'), 'php_runtime' => PHP_VERSION];
foreach ($steps as $key => [$script, $args]) {
    fwrite(STDERR, "[$name] $key...\n");
    [$code, $stdout, $stderr] = runScript($script, $args);
    $data = json_decode($stdout, true);
    if (!is_array($data)) {
        $data = ['error' => "Failed to run $script (exit $code)", 'stderr' => substr($stderr, 0, 2000)];
    }
    writeJson("$dir/$key.json", $data);
    $report[$key] = $data;
}

foreach (array_values(array_diff(['phpcompat', 'rector'], $skip)) as $tool) {
    fwrite(STDERR, "[$name] $tool...\n");
    runScript('run_tools.php', [$repo, "--out=$dir", "--cache=$out/.cache/rector-" . entity_slug($name), "--target=$target", "--only=$tool", ...$common]);
    $report[$tool] = json_decode((string) @file_get_contents("$dir/$tool.json"), true) ?? ['error' => "Failed to run $tool"];
}

writeJson("$dir/report.json", $report);
$carried = $previous !== null && history_carry_notes($entity, $previous['id'], $dir);
history_add($entity, $name, 'repo', $id, ['generated_at' => $report['generated_at'], 'target' => $target, 'git' => $report['profile']['git'] ?? null]);

fwrite(STDERR, "[$name] done: scan $id" . ($previous ? " (previous: {$previous['id']}" . ($carried ? ', notes carried over for review' : '') . ')' : ' (first scan)') . "\n");
echo "$dir/report.json\n";

function runScript(string $script, array $args): array
{
    $cmd = [PHP_BINARY, __DIR__ . "/$script", ...$args];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, sys_get_temp_dir());
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), (string) $stdout, (string) $stderr];
}
