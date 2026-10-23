<?php
/**
 * Run the full audit for one repo and store it as a NEW version in the history store:
 *   <out>/<name>/scans/<scan-id>/{profile,scan,lint,libs,phpcompat,rector,report}.json
 * Earlier scans are never touched. Notes of the previous scan are carried over (marked as not yet reviewed).
 * Read-only towards the repo: outputs, tool config and caches all live under <out>.
 *
 * Run with --help for the options and the whole workflow.
 */
declare(strict_types=1);

ini_set('display_errors', 'stderr');
require __DIR__ . '/lib.php';
require __DIR__ . '/history.php';

const LEVELS = ['wajib' => 3, 'deprecated' => 2, 'perilaku' => 1];

handleHelp($argv, <<<'HELP'
php-upgrade-audit: read-only audit of a PHP 7.x/8.x codebase before a PHP upgrade.

Usage:
  php scripts/audit.php <repo> --out=<store> [options]

Each run adds a NEW scan version to <store>/<name>/scans/<scan-id>/; older scans are never changed.
The audited repo is only read: nothing is written into it, and composer/Rector never run in it.

Options:
  --out=<store>        History store (required). Must be outside the repo, e.g. ~/php-audit-store.
  --name=<label>       Name of the repo in the store and the report (default: folder name).
  --target=<version>   PHP version you are moving to, e.g. 8.3, 8.3.30, 8.5.12 (default and "latest": the
                       newest version the rule catalogue covers). Rules introduced after the target are not
                       reported, and Rector/PHPCompatibility use the target's minor version.
  --php=<binary>       PHP binary used for "php -l" and class loading (default: the PHP running this script).
                       It should be the target version: those two steps prove behaviour on that binary.
  --php=docker         The same in Docker with the official image of the target (php:<target>-cli), when the
                       target PHP is not installed. docker:8.3 picks another version, docker:<image> any image.
                       The container has no network, sees the repo read-only and is removed afterwards.
  --exclude=a,b        Extra folders to skip (vendor, node_modules, .git, cache... are always skipped).
  --skip=a,b           Steps to skip: libs, phpcompat, rector.
  --load-classes       Also load every application class on --php (Composer autoload or plain files), in a
                       scratch copy. Slower; see scripts/load_classes.php --help.
  --memory=<size>      memory_limit for PHPCompatibility and Rector (default 2G); raise it for big repos.
  --offline            No network at all (otherwise public package names go to repo.packagist.org, and the
                       newest PHP version is asked from php.net).
  --sarif=<file>       Also write the findings as SARIF 2.1.0 (GitHub code scanning), outside the repo.
  --fail-on=<level>    For CI: exit 10 when there are findings at this level or above.
                       Levels, highest first: wajib (must fix) > deprecated > perilaku (behaviour change).
  --help               This text.

Exit codes: 0 ok, 1 wrong usage, 2 environment problem, 10 findings at the --fail-on level.

Workflow (an AI agent follows the same steps, see SKILL.md / AGENTS.md):
  1. Scan:     php scripts/audit.php /path/to/repo --out=~/php-audit-store --name=my-app --target=8.5.12
  2. Review:   read <store>/my-app/scans/<id>/report.json (scan, lint, libs, phpcompat, rector combined).
  3. Notes:    write <store>/my-app/scans/<id>/notes.json and <store>/overview.json (format in AGENTS.md),
               then check them: php scripts/validate_notes.php --store=~/php-audit-store
  4. Render:   php scripts/render.php --store=~/php-audit-store --out=~/php-audit-store/report.html --lang=en
  5. Open:     the HTML file in a browser, or php -S 127.0.0.1:8085 -t ~/php-audit-store
  Re-scan later with the same --out and --name: a new version is added and the report shows what changed.
HELP);

$repo = '';
foreach (array_slice($argv, 1) as $a) {
    if (!str_starts_with($a, '--')) {
        $repo = $a;
        break;
    }
}
$out = opt($argv, 'out');
if ($repo === '') {
    usageError('no repo given.', 'scripts/audit.php');
}
if (!is_dir($repo)) {
    usageError("repo folder not found: $repo", 'scripts/audit.php');
}
if ($out === null || $out === '1') {
    usageError('--out=<store> is required (a folder outside the repo, e.g. --out=~/php-audit-store).', 'scripts/audit.php');
}
$sarif = opt($argv, 'sarif');
if ($sarif === '1') {
    usageError('--sarif needs a file, e.g. --sarif=$RUNNER_TEMP/php-upgrade.sarif.', 'scripts/audit.php');
}
$failOn = opt($argv, 'fail-on');
if ($failOn !== null && !isset(LEVELS[$failOn])) {
    usageError("--fail-on must be one of: " . implode(', ', array_keys(LEVELS)) . '.', 'scripts/audit.php');
}
$repo = rtrim(realpath($repo), '/');
$outInput = $out;
$out = absPath($out);
// Checked before anything is created: the audited repo is never written to, not even an empty folder.
if ($sarif !== null && str_starts_with(absPath($sarif) . '/', $repo . '/')) {
    usageError("the SARIF file must not be inside the audited repo ($sarif). Write it to e.g. \$RUNNER_TEMP.", 'scripts/audit.php');
}
if (str_starts_with($out . '/', $repo . '/')) {
    usageError("the output folder must not be inside the audited repo ($outInput). Use a folder outside it, e.g. --out=~/php-audit-store.", 'scripts/audit.php');
}
if (!@mkdir($out, 0700, true) && !is_dir($out)) {
    fwrite(STDERR, "Error: cannot create the output folder $outInput.\n");
    exit(EXIT_ENV);
}
$out = realpath($out);
$target = targetFrom($argv);
fwrite(STDERR, "Target: PHP $target" . (in_array(opt($argv, 'target', 'latest'), ['latest', '1'], true) ? ' (latest)' : '') . "\n");
$name = opt($argv, 'name', basename($repo));
$skip = array_filter(explode(',', (string) opt($argv, 'skip', '')));
$exclude = opt($argv, 'exclude');
$offline = opt($argv, 'offline') !== null;
$php = opt($argv, 'php', PHP_BINARY);
if ($php === 'docker' || str_starts_with($php, 'docker:')) {
    $php = dockerPhp($php, $target, [$repo, dirname(__DIR__)], [$out, rtrim(sys_get_temp_dir(), '/')], "$out/.cache/docker");
}

// The runtime steps (php -l, class loading) prove behaviour on $php, so it should be the target version.
$phpVersion = trim((string) shell_exec(escapeshellarg($php) . ' -r "echo PHP_VERSION;" 2>/dev/null'));
if ($phpVersion === '') {
    fwrite(STDERR, "Error: cannot run the PHP binary $php (--php).\n");
    exit(EXIT_ENV);
}
if (targetMinor($phpVersion) !== targetMinor($target)) {
    fwrite(STDERR, "Warning: target is PHP $target but php -l" . (opt($argv, 'load-classes') ? ' and class loading run' : ' runs') . " on PHP $phpVersion.\n"
        . "         Those results show PHP $phpVersion behaviour, and guards such as function_exists()/class_exists() are left as \"?\".\n"
        . "         Install the target PHP and use it as php, or pass --php=/path/to/php" . targetMinor($target) . ".\n");
}
if (version_compare(targetMinor($target), RULES_COVER, '>')) {
    fwrite(STDERR, "Warning: the rule catalogue covers changes up to PHP " . RULES_COVER . "; changes made in PHP " . targetMinor($target) . " are not checked by the scanner.\n");
}
$newest = latestRelease('8', $offline);
if (in_array(opt($argv, 'target', 'latest'), ['latest', '1'], true) && $newest !== null && version_compare(targetMinor($newest), RULES_COVER, '>')) {
    fwrite(STDERR, "Note: PHP $newest is out, but the rule catalogue covers up to PHP " . RULES_COVER . ", so the target is $target. Update this kit (scripts/update.sh), or pass --target=$newest to check the rest by hand.\n");
}
if (toolsVendor() === null) {
    fwrite(STDERR, "Warning: " . SETUP_HINT . "\n         Without them the library check, PHPCompatibility and Rector are skipped; the scanner and php -l still run.\n");
    $skip = array_values(array_unique([...$skip, 'libs', 'phpcompat', 'rector']));
}

$entity = entity_dir($out, $name);
@mkdir($entity, 0700, true);
history_migrate($out); // older flat output of this store becomes version 1
$previous = history_latest($entity);
[$id, $dir] = history_new_scan_dir($entity, scan_id());

$common = $exclude ? ["--exclude=$exclude"] : [];
$steps = [
    'profile' => ['detect.php', [$repo, ...$common]],
    'scan' => ['scan_code.php', [$repo, "--target=$target", ...$common]],
    'lint' => ['lint.php', [$repo, "--php=$php", ...$common]],
];
if (!in_array('libs', $skip, true)) {
    $steps['libs'] = ['check_libs.php', [$repo, "--target=$target", "--cache=$out/.cache/packagist", ...($offline ? ['--offline'] : [])]];
}
if (opt($argv, 'load-classes') !== null) {
    $steps['runtime'] = ['load_classes.php', [$repo, "--php=$php", "--work=$out/.cache/load-classes", ...($offline ? ['--offline'] : []), ...$common]];
}

$report = ['schema' => 2, 'name' => $name, 'scan_id' => $id, 'target' => $target, 'generated_at' => date('c'), 'php_runtime' => $phpVersion, 'kit' => kitInfo()];
$report['timings'] = [];
foreach ($steps as $key => [$script, $args]) {
    fwrite(STDERR, "[$name] $key...\n");
    $t0 = microtime(true);
    // The scanner evaluates function_exists()/class_exists() guards with the PHP that runs it: use --php when it
    // can run the scripts (8.1+).
    $bin = $key === 'scan' && version_compare($phpVersion, '8.1', '>=') ? $php : PHP_BINARY;
    [$code, $stdout, $stderr] = runScript($script, $args, $bin);
    $report['timings'][$key] = round(microtime(true) - $t0, 2);
    $data = json_decode($stdout, true);
    if (!is_array($data)) {
        $data = ['error' => "Failed to run $script (exit $code)", 'stderr' => substr($stderr, 0, 2000)];
        fwrite(STDERR, "[$name] $key failed (exit $code): " . trim(substr($stderr, 0, 300)) . "\n");
    }
    writeJson("$dir/$key.json", $data);
    $report[$key] = $data;
}

foreach (array_values(array_diff(['phpcompat', 'rector'], $skip)) as $tool) {
    fwrite(STDERR, "[$name] $tool...\n");
    $t0 = microtime(true);
    runScript('run_tools.php', [$repo, "--out=$dir", '--memory=' . opt($argv, 'memory', '2G'), "--cache=$out/.cache/rector-" . entity_slug($name), "--target=$target", "--only=$tool", ...$common]);
    $report[$tool] = json_decode((string) @file_get_contents("$dir/$tool.json"), true) ?? ['error' => "Failed to run $tool"];
    $report['timings'][$tool] = round(microtime(true) - $t0, 2);
    if (!empty($report[$tool]['hint'])) {
        fwrite(STDERR, "[$name] $tool: {$report[$tool]['hint']}\n");
    }
}

writeJson("$dir/report.json", $report);
$carried = $previous !== null && history_carry_notes($entity, $previous['id'], $dir);
history_add($entity, $name, 'repo', $id, ['generated_at' => $report['generated_at'], 'target' => $target, 'git' => $report['profile']['git'] ?? null]);

if ($sarif !== null) {
    [$code, , $err] = runScript('sarif.php', ["$dir/report.json", "--repo=$repo", '--out=' . absPath($sarif)]);
    fwrite(STDERR, $code === 0 ? "[$name] " . trim($err) . "\n" : "[$name] SARIF failed: " . trim($err) . "\n");
}
$counts = countByLevel($report);
fwrite(STDERR, "[$name] done: scan $id" . ($previous ? " (previous: {$previous['id']}" . ($carried ? ', notes carried over for review' : '') . ')' : ' (first scan)') . "\n");
fwrite(STDERR, sprintf("[%s] findings: wajib %d, deprecated %d, perilaku %d (lint errors, unsupported libraries and security advisories count as wajib)\n", $name, $counts['wajib'], $counts['deprecated'], $counts['perilaku']));
echo "$dir/report.json\n";

if ($failOn !== null) {
    $hits = 0;
    foreach (LEVELS as $level => $rank) {
        if ($rank >= LEVELS[$failOn]) {
            $hits += $counts[$level];
        }
    }
    if ($hits > 0) {
        fwrite(STDERR, "[$name] --fail-on=$failOn: $hits finding(s) at that level or above.\n");
        exit(EXIT_FINDINGS);
    }
}

/** Findings per level, guarded code that does not run on the target excluded. */
function countByLevel(array $report): array
{
    $counts = ['wajib' => 0, 'deprecated' => 0, 'perilaku' => 0];
    $rules = $report['scan']['rules'] ?? [];
    foreach ($report['scan']['findings'] ?? [] as $f) {
        $sev = $rules[$f['rule']]['sev'] ?? null;
        if (isset($counts[$sev]) && empty($f['guarded'])) {
            $counts[$sev]++;
        }
    }
    $counts['wajib'] += count($report['lint']['errors'] ?? []);
    $counts['deprecated'] += count($report['lint']['deprecations'] ?? []);
    foreach ($report['libs']['packages'] ?? [] as $p) {
        if (in_array($p['status'] ?? '', ['tidak-didukung', 'perlu-upgrade'], true)) {
            $counts['wajib']++;
        }
    }
    foreach ($report['libs']['packages'] ?? [] as $p) {
        $counts['wajib'] += count($p['advisories'] ?? []); // a known vulnerability in the installed version
    }
    foreach ($report['libs']['extensions'] ?? [] as $e) {
        if (!empty($e['removed_in'])) {
            $counts['wajib']++;
        }
    }
    $counts['wajib'] += count($report['runtime']['failed'] ?? []);
    $counts['deprecated'] += count($report['runtime']['deprecations'] ?? []);
    return $counts;
}

function runScript(string $script, array $args, string $php = PHP_BINARY): array
{
    $cmd = [$php, __DIR__ . "/$script", ...$args];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, sys_get_temp_dir());
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), (string) $stdout, (string) $stderr];
}
