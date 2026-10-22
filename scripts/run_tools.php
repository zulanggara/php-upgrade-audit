<?php
/**
 * Run PHPCompatibility (phpcs) and Rector --dry-run on a repo. Read-only:
 * config and cache go to the output folder, never into the repo. Rector never runs without --dry-run.
 *
 * Usage: php run_tools.php <repo> --out=<dir> [--cache=<dir>] [--target=8.5.12] [--only=phpcompat|rector] [--exclude=a,b]
 */
declare(strict_types=1);

ini_set('display_errors', 'stderr');
require __DIR__ . '/lib.php';

$repo = $argv[1] ?? '';
$outDir = opt($argv, 'out');
if ($repo === '' || !is_dir($repo) || $outDir === null) {
    fwrite(STDERR, "Usage: php run_tools.php <repo> --out=<dir> [--target=8.5.12] [--only=phpcompat|rector]\n");
    exit(2);
}
$repo = rtrim(realpath($repo), '/');
@mkdir($outDir, 0700, true);
$outDir = realpath($outDir);
$target = targetFrom($argv);
$minor = targetMinor($target);
$only = opt($argv, 'only');
$excludes = excludesFrom($argv);
$bin = realpath(__DIR__ . '/../tools/vendor/bin');

if ($only === null || $only === 'phpcompat') {
    // Absolute patterns relative to the repo, so a repo whose own path contains "/vendor/" is still scanned.
    $ignore = implode(',', array_map(fn ($e) => "$repo/$e/*", $excludes));
    $cmd = [PHP_BINARY, '-d', 'memory_limit=2G', "$bin/phpcs", '--standard=PHPCompatibility', '--runtime-set', 'testVersion', $minor,
        '--extensions=' . implode(',', PHP_EXTS), "--ignore=$ignore", '--report=json', '-q', '--no-cache', '--parallel=8', $repo];
    [$code, $stdout, $stderr] = run($cmd, $outDir);
    $raw = json_decode($stdout, true);
    $messages = [];
    foreach ($raw['files'] ?? [] as $path => $f) {
        foreach ($f['messages'] as $msg) {
            $messages[] = [
                'file' => substr($path, strlen($repo) + 1),
                'line' => $msg['line'],
                'type' => $msg['type'],
                'source' => preg_replace('/^PHPCompatibility\./', '', $msg['source']),
                'message' => $msg['message'],
            ];
        }
    }
    writeJson("$outDir/phpcompat.json", [
        'tool' => 'phpcompat',
        'testVersion' => $minor,
        'ok' => is_array($raw),
        'stderr' => $raw === null ? substr($stderr . $stdout, 0, 2000) : null,
        'totals' => $raw['totals'] ?? null,
        'messages' => $messages,
    ]);
}

if ($only === null || $only === 'rector') {
    $constant = 'UP_TO_PHP_' . str_replace('.', '', $minor);
    $skip = var_export(array_map(fn ($e) => "$repo/$e/*", $excludes), true);
    $repoLit = var_export($repo, true);
    $cacheLit = var_export(opt($argv, 'cache', $outDir . '/rector-cache'), true);
    $config = <<<PHP
    <?php
    use Rector\\Config\\RectorConfig;
    use Rector\\Set\\ValueObject\\LevelSetList;

    return RectorConfig::configure()
        ->withPaths([$repoLit])
        ->withSkip($skip)
        ->withSets([LevelSetList::$constant])
        ->withCache($cacheLit);
    PHP;
    file_put_contents("$outDir/rector-audit.php", $config);
    $cmd = [PHP_BINARY, '-d', 'memory_limit=2G', "$bin/rector", 'process', '--dry-run', '--config=' . "$outDir/rector-audit.php",
        '--output-format=json', '--no-progress-bar'];
    [$code, $stdout, $stderr] = run($cmd, $outDir);
    $raw = json_decode(substr($stdout, (int) strpos($stdout, '{')), true);
    $byRule = [];
    $files = [];
    foreach ($raw['file_diffs'] ?? [] as $d) {
        $rel = str_starts_with($d['file'], $repo . '/') ? substr($d['file'], strlen($repo) + 1) : $d['file'];
        $rules = array_map(fn ($r) => substr($r, (int) strrpos($r, '\\') + 1), $d['applied_rectors'] ?? []);
        $files[] = ['file' => $rel, 'rules' => $rules];
        foreach ($rules as $r) {
            $byRule[$r] = ($byRule[$r] ?? 0) + 1;
        }
    }
    arsort($byRule);
    writeJson("$outDir/rector.json", [
        'tool' => 'rector',
        'set' => $constant,
        'ok' => is_array($raw),
        'stderr' => $raw === null ? substr($stderr . $stdout, 0, 2000) : null,
        'changed_files' => $raw['totals']['changed_files'] ?? count($files),
        'errors' => array_map(fn ($e) => ['file' => isset($e['file']) ? str_replace($repo . '/', '', $e['file']) : null, 'message' => $e['message'] ?? ''], $raw['errors'] ?? []),
        'by_rule' => $byRule,
        'files' => $files,
    ]);
}

/** Run a process with the output folder as cwd; returns [exit code, stdout, stderr]. */
function run(array $cmd, string $cwd): array
{
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), (string) $stdout, (string) $stderr];
}
