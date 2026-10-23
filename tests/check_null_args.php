<?php
/**
 * Compares the P81-NULL-TO-INTERNAL findings of scan_code.php with the markers in a fixture folder:
 * every line with "// FIND" must be reported, no line with "// ok" may be. Each finding needs a detail.
 *
 * Usage: php check_null_args.php <fixture dir> <scan.json>
 */
declare(strict_types=1);

[$root, $scanFile] = [rtrim($argv[1] ?? '', '/'), $argv[2] ?? ''];
$scan = json_decode((string) @file_get_contents($scanFile), true);
if (!is_dir($root) || !is_array($scan)) {
    fwrite(STDERR, "Usage: php check_null_args.php <fixture dir> <scan.json>\n");
    exit(2);
}

$expected = $forbidden = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') {
        continue;
    }
    $rel = substr($f->getPathname(), strlen($root) + 1);
    foreach (file($f->getPathname()) as $i => $line) {
        if (preg_match('#//\s*FIND\b#', $line)) {
            $expected[] = "$rel:" . ($i + 1);
        } elseif (preg_match('#//\s*ok\b#', $line)) {
            $forbidden[] = "$rel:" . ($i + 1);
        }
    }
}

$found = [];
$problems = [];
foreach ($scan['findings'] ?? [] as $x) {
    if ($x['rule'] !== 'P81-NULL-TO-INTERNAL') {
        continue;
    }
    $key = "{$x['file']}:{$x['line']}";
    $found[] = $key;
    $d = $x['detail'] ?? null;
    if (!is_array($d) || empty($d['func']) || empty($d['param']) || empty($d['kind']) || !isset($d['source'])) {
        $problems[] = "no complete detail at $key";
    }
    if (($d['kind'] ?? '') === 'variable' && (empty($d['via']) || empty($d['via_line']))) {
        $problems[] = "variable finding without via/via_line at $key";
    }
}
sort($expected);
sort($found);
foreach (array_diff($expected, $found) as $k) {
    $problems[] = "missed (marked // FIND): $k";
}
foreach (array_diff($found, $expected) as $k) {
    $problems[] = in_array($k, $forbidden, true) ? "reported a line marked // ok: $k" : "reported an unmarked line: $k";
}
if (count($found) !== count(array_unique($found))) {
    $problems[] = 'a line was reported twice';
}
if ($expected === []) {
    $problems[] = 'no // FIND markers found in the fixture';
}
foreach ($problems as $p) {
    fwrite(STDERR, "  $p\n");
}
exit($problems === [] ? 0 : 1);
