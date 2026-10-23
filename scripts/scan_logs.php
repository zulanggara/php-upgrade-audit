<?php
/**
 * Read runtime error logs (read-only) and group the PHP deprecations, warnings and fatal errors they contain.
 * Logs show what static analysis cannot see: a NULL from a database column passed to strtolower(), a dynamic
 * property set on a class the scanner did not resolve. They matter most when the app already ran on the target
 * PHP (staging, a test run); logs written by the old PHP do not contain the new deprecations.
 *
 * Formats: CodeIgniter 3 (application/logs/log-*.php), Laravel / Monolog (storage/logs/*.log) and the PHP
 * error_log (also php-fpm's "PHP message: ..."). Only E_DEPRECATED / E_USER_DEPRECATED, warnings and fatal
 * errors are kept; notices and the application's own log messages are ignored.
 *
 * Usage: php scan_logs.php <repo> [--logs=<file|dir>]... [--no-auto] [--max-mb=50] > logs.json
 */
declare(strict_types=1);

ini_set('display_errors', 'stderr');
require __DIR__ . '/lib.php';

handleHelp($argv, <<<'HELP'
Read runtime error logs (read-only) and group the PHP deprecations, warnings and fatal errors in them, per
message and per file:line of the repo. Normally called by audit.php (--logs).

Usage: php scripts/scan_logs.php <repo> [--logs=<file|dir>]... [--no-auto] [--max-mb=50]

  --logs     A log file or a folder of logs; repeat for more. Files outside the repo are fine (e.g. copied
             from a server); only their file name appears in the output.
  --no-auto  Do not look for logs inside the repo (application/logs, storage/logs, var/log, logs).
  --max-mb   Total bytes read over all files (default 50). Newest files first, each read from its end.

Formats: CodeIgniter 3, Laravel/Monolog, PHP error_log. Kept: deprecated, warning, fatal.
HELP);

const AUTO_LOG_DIRS = ['application/logs', 'storage/logs', 'var/log', 'logs'];

$repo = '';
foreach (array_slice($argv, 1) as $a) {
    if (!str_starts_with($a, '--')) {
        $repo = $a;
        break;
    }
}
if ($repo === '' || !is_dir($repo)) {
    usageError('no repo folder given.', 'scripts/scan_logs.php');
}
$repo = rtrim((string) realpath($repo), '/');
$budget = (int) round((float) opt($argv, 'max-mb', '50') * 1048576);
$given = optAll($argv, 'logs');
foreach ($given as $g) {
    if (!file_exists($g)) {
        usageError("log file or folder not found: $g", 'scripts/scan_logs.php');
    }
}

// Candidate files: [path, shown name, auto?]
$files = [];
$skipped = [];
$add = function (string $path, bool $auto) use (&$files, &$skipped, $repo): void {
    $real = (string) realpath($path);
    if (isset($files[$real])) {
        return;
    }
    $shown = str_starts_with($real, $repo . '/') ? substr($real, strlen($repo) + 1) : basename($real);
    if (preg_match('/\.(gz|bz2|zip|xz|zst)$/i', $real)) {
        $skipped[] = ['path' => $shown, 'reason' => 'compressed'];
        return;
    }
    $files[$real] = ['path' => $real, 'shown' => $shown, 'auto' => $auto];
};
$collect = function (string $dir, bool $auto, int $depth = 0) use (&$collect, $add): void {
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        $p = "$dir/$e";
        if (is_link($p)) {
            continue;
        }
        if (is_dir($p)) {
            if ($depth < 2) {
                $collect($p, $auto, $depth + 1);
            }
        } elseif (preg_match('/(\.log|\.txt)(\.\d+)?$|^log-.*\.php$|error_log|php_errors|\.(gz|bz2|zip|xz|zst)$/i', $e)) {
            $add($p, $auto);
        }
    }
};
foreach ($given as $g) {
    is_dir($g) ? $collect((string) realpath($g), false) : $add($g, false);
}
if (opt($argv, 'no-auto') === null) {
    foreach (AUTO_LOG_DIRS as $d) {
        if (is_dir("$repo/$d") && !is_link("$repo/$d")) {
            $collect("$repo/$d", true);
        }
    }
}
// Newest first, so the byte budget covers the most recent runs.
uasort($files, fn ($a, $b) => (int) @filemtime($b['path']) <=> (int) @filemtime($a['path']));

$groups = [];
$sources = [];
$remaining = $budget;
foreach ($files as $f) {
    $size = (int) @filesize($f['path']);
    $take = min($size, max(0, $remaining));
    $src = ['path' => $f['shown'], 'auto' => $f['auto'], 'bytes' => $size, 'read' => $take, 'truncated' => $take < $size, 'lines' => 0, 'matched' => 0, 'formats' => []];
    if ($take === 0) {
        $src['skipped'] = $size === 0 ? 'empty' : 'byte limit reached';
        $sources[] = $src;
        continue;
    }
    $remaining -= $take;
    $h = @fopen($f['path'], 'rb');
    if ($h === false) {
        $src['skipped'] = 'not readable';
        $sources[] = $src;
        continue;
    }
    fseek($h, $size - $take);
    if ($take < $size) {
        fgets($h); // the first line is cut off
    }
    while (($line = fgets($h)) !== false) {
        $src['lines']++;
        $e = parse_log_line(rtrim($line, "\r\n"));
        if ($e === null) {
            continue;
        }
        $src['matched']++;
        $src['formats'][$e['format']] = ($src['formats'][$e['format']] ?? 0) + 1;
        [$file, $inRepo] = repo_relative($e['path'], $repo);
        $msg = normalize_log_message($e['message']);
        $key = "{$e['level']}|$msg|$file|{$e['line']}";
        $ts = $e['time'] !== null ? strtotime($e['time']) : false;
        $g = $groups[$key] ?? ['level' => $e['level'], 'rule' => log_rule($msg), 'message' => $msg, 'file' => $file, 'line' => $e['line'], 'in_repo' => $inRepo, 'count' => 0, 'first_seen' => null, 'last_seen' => null, 'formats' => []];
        $g['count']++;
        if ($ts !== false) {
            $iso = gmdate('Y-m-d\TH:i:s\Z', $ts);
            $g['first_seen'] = $g['first_seen'] === null || $iso < $g['first_seen'] ? $iso : $g['first_seen'];
            $g['last_seen'] = $g['last_seen'] === null || $iso > $g['last_seen'] ? $iso : $g['last_seen'];
        }
        $g['formats'][$e['format']] = true;
        $groups[$key] = $g;
    }
    fclose($h);
    arsort($src['formats']);
    $src['formats'] = array_keys($src['formats']);
    $sources[] = $src;
}

$order = ['fatal' => 0, 'deprecated' => 1, 'warning' => 2];
$entries = array_values(array_map(function ($g) {
    $g['formats'] = array_keys($g['formats']);
    return $g;
}, $groups));
usort($entries, fn ($a, $b) => [$order[$a['level']], -$a['count'], $a['file'], $a['line']] <=> [$order[$b['level']], -$b['count'], $b['file'], $b['line']]);
$totals = ['fatal' => 0, 'deprecated' => 0, 'warning' => 0, 'occurrences' => 0];
foreach ($entries as $x) {
    $totals[$x['level']]++;
    $totals['occurrences'] += $x['count'];
}

printJson([
    'tool' => 'scan_logs',
    'limit_mb' => round($budget / 1048576, 1),
    'auto_dirs' => AUTO_LOG_DIRS,
    'sources' => $sources,
    'skipped' => $skipped,
    'totals' => $totals,
    'entries' => $entries,
]);

/**
 * One log line => ['format', 'level' (deprecated|warning|fatal), 'message', 'path', 'line', 'time'] or null.
 */
function parse_log_line(string $l): ?array
{
    // CodeIgniter 3: "ERROR - 2026-10-23 10:00:00 --> Severity: 8192 --> msg /path/file.php 823"
    if (preg_match('/^[A-Z]+ - (.+?) --> Severity: (.+?) --> (.*) (\S+) (\d+)$/', $l, $m)) {
        $level = ci3_level($m[2]);
        if ($level === null) {
            return null;
        }
        $msg = preg_replace('/^Exception: /', '', $m[3]);
        return ['format' => 'codeigniter3', 'level' => $level, 'message' => $msg, 'path' => $m[4], 'line' => (int) $m[5], 'time' => $m[1]];
    }
    // PHP error_log and php-fpm: "[date] PHP Deprecated:  msg in /path on line N" / "PHP Fatal error:  Uncaught X: msg in /path:N"
    if (preg_match('/(?:^\[([^\]]+)\] |^(\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2}(?:\.\d+)?) )?.*?PHP (Deprecated|Warning|Fatal error|Parse error|Recoverable fatal error):\s+(.*)$/', $l, $m)) {
        [, $bracket, $fpm, $kind, $rest] = $m;
        $level = $kind === 'Deprecated' ? 'deprecated' : ($kind === 'Warning' ? 'warning' : 'fatal');
        if (!preg_match('/^(.*) in (\S+?)(?: on line |:)(\d+)$/', $rest, $p)) {
            return null;
        }
        $time = $bracket !== '' ? $bracket : ($fpm !== '' ? $fpm : null);
        return ['format' => 'php', 'level' => $level, 'message' => $p[1], 'path' => $p[2], 'line' => (int) $p[3], 'time' => $time];
    }
    // Laravel / Monolog: "[2026-10-23 10:00:00] production.WARNING: msg in /path on line N"
    if (preg_match('/^\[([^\]]+)\] [\w.-]+\.([A-Z]+): (.*)$/', $l, $m)) {
        $body = preg_replace('/(\s+\[\])+\s*$/', '', $m[3]);
        // A PHP error turned into an exception (the first line holds class, message and location)
        if (preg_match('/\(((?:\w+\\\\)*(\w+))\(code: \d+\): (.*) at (\S+):(\d+)\)/', $body, $x)) {
            $x[3] = stripcslashes($x[3]); // the exception is printed inside the JSON context
            $x[4] = str_replace('\\/', '/', $x[4]);
            $level = $x[2] === 'ErrorException' ? (str_contains($x[3], 'is deprecated') ? 'deprecated' : 'warning')
                : (in_array($x[2], ['Error', 'TypeError', 'ValueError', 'ArgumentCountError', 'ArithmeticError', 'DivisionByZeroError', 'ParseError', 'UnhandledMatchError', 'CompileError'], true) ? 'fatal' : null);
            return $level === null ? null : ['format' => 'laravel', 'level' => $level, 'message' => $x[3], 'path' => $x[4], 'line' => (int) $x[5], 'time' => $m[1]];
        }
        // Deprecations logged by Laravel's HandleExceptions: sprintf('%s in %s on line %s', ...)
        if (preg_match('/^(.*\bis deprecated\b.*|Deprecated: .*) in (\S+) on line (\d+)$/', $body, $x)) {
            return ['format' => 'laravel', 'level' => 'deprecated', 'message' => preg_replace('/^Deprecated: /', '', $x[1]), 'path' => $x[2], 'line' => (int) $x[3], 'time' => $m[1]];
        }
    }
    return null;
}

/** CodeIgniter's "Severity:" field: a name from CI_Exceptions::$levels, an E_* number, or "error" for exceptions. */
function ci3_level(string $s): ?string
{
    $s = strtolower(trim($s));
    if (ctype_digit($s)) {
        $n = (int) $s;
        return match (true) {
            in_array($n, [E_DEPRECATED, E_USER_DEPRECATED], true) => 'deprecated',
            in_array($n, [E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING], true) => 'warning',
            in_array($n, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true) => 'fatal',
            default => null,
        };
    }
    return match (true) {
        str_contains($s, 'deprecated') => 'deprecated',
        str_contains($s, 'warning') => 'warning',
        str_contains($s, 'error') => 'fatal',
        default => null,
    };
}

/** Values that change between occurrences become placeholders, so one problem is one group. */
function normalize_log_message(string $m): string
{
    $m = preg_replace('/[^\s@"\'<>()]+@[^\s@"\'<>()]+\.[a-z]{2,}/i', '…@…', $m);
    $m = preg_replace_callback('/"([^"]*)"|\'([^\']*)\'/', function ($x) {
        $v = $x[1] !== '' ? $x[1] : ($x[2] ?? '');
        $q = $x[0][0];
        return preg_match('/^[A-Za-z_][\w.\\\\-]{0,63}$/', $v) ? $x[0] : $q . '…' . $q;
    }, $m);
    // Numbers, except parameter positions (#1), versions (since 8.2, PHP 8.0) and names (Local::$a1)
    $m = preg_replace('/(?<![#\w.$\\\\])(?<!since )(?<!PHP )\d+(?:\.\d+)?(?![\w.])/', 'N', $m);
    return trim(preg_replace('/\s+/', ' ', $m));
}

/** Rule of the scanner catalogue that covers the message, so the report links the fix. */
function log_rule(string $m): ?string
{
    $map = [
        '/Passing null to parameter #\d+/' => 'P81-NULL-TO-INTERNAL',
        '/^Creation of dynamic property /' => 'P82-DYNAMIC-PROP',
        '/Optional parameter \$\w+ declared before required parameter/' => 'P84-OPTIONAL-BEFORE-REQUIRED',
        '/Implicitly marking parameter \$\w+ as nullable is deprecated/' => 'P84-IMPLICIT-NULLABLE',
        '/^Using \$\{(var|expr)\} in strings is deprecated/' => 'P82-DOLLAR-BRACE',
        '/implements the Serializable interface, which is deprecated/' => 'P81-SERIALIZABLE',
        '/^Return type of .* should either be compatible with /' => 'P81-RETURN-TYPE',
        '/^Function utf8_(en|de)code\(\) is deprecated/' => 'P82-UTF8-ENCODE',
        '/^Function (curl_close|curl_share_close|finfo_close|imagedestroy|xml_parser_free)\(\) is deprecated/' => 'P85-FREE-FUNC',
        '/^Method ReflectionProperty::setAccessible\(\)|^Method ReflectionMethod::setAccessible\(\)/' => 'P85-SET-ACCESSIBLE',
        '/^str_getcsv\(\): the \$escape parameter must be provided/' => 'P84-CSV-ESCAPE-STR',
        '/^SplFileObject::fputcsv\(\): the \$escape parameter must be provided/' => 'P84-CSV-ESCAPE-SPL-PUT',
        '/^SplFileObject::(fgetcsv|setCsvControl)\(\): the \$escape parameter must be provided/' => 'P84-CSV-ESCAPE-SPL',
        '/^(fputcsv|fgetcsv)\(\): the \$escape parameter must be provided/' => 'P84-CSV-ESCAPE',
        '/^Function \w+\(\) is deprecated since 8\.1/' => 'P81-DEPR-FUNC',
        '/^Function \w+\(\) is deprecated since 8\.4/' => 'P84-DEPR-FUNC',
        '/^Function \w+\(\) is deprecated since 8\.5/' => 'P85-DEPR-FUNC',
        '/^Constant \w+ is deprecated since 8\.1/' => 'P81-DEPR-CONST',
        '/^Constant \w+ is deprecated since 8\.4/' => 'P84-DEPR-CONST',
        '/^Constant \w+ is deprecated since 8\.5/' => 'P85-DEPR-CONST',
        '/^Call to undefined function (each|create_function|money_format|get_magic_quotes_\w+|fgetss|is_real|ereg\w*|split|mysql_\w+)\(\)/' => 'P80-REMOVED-FUNC',
    ];
    foreach ($map as $re => $rule) {
        if (preg_match($re, $m)) {
            return $rule;
        }
    }
    return null;
}

/**
 * A path from a log (often from a container: /var/www/html/application/libraries/Local.php) => the path in the
 * repo when a file with that path suffix exists (longest match), otherwise a short tail, never the full path.
 */
function repo_relative(string $path, string $repo): array
{
    $parts = array_values(array_filter(explode('/', str_replace('\\', '/', $path)), 'strlen'));
    for ($k = count($parts); $k >= 1; $k--) {
        $cand = implode('/', array_slice($parts, -$k));
        if (is_file("$repo/$cand")) {
            return [$cand, true];
        }
    }
    foreach (['vendor', 'system'] as $marker) {
        $i = array_search($marker, array_reverse($parts, true), true);
        if ($i !== false) {
            return [implode('/', array_slice($parts, $i)), false];
        }
    }
    return [implode('/', array_slice($parts, -3)), false];
}
