<?php
/**
 * Compare two versions of a framework (e.g. an old and a new CodeIgniter system/) and the impact on an application. Read-only.
 *
 *  - old vs new API: files, classes, methods (signatures), properties, global functions
 *  - local patches: old framework vs its official release (--official)
 *  - override compatibility in the application (PHP signature rules; a violation is fatal when the class loads)
 *  - application calls to removed/changed API
 *  - config keys that are new / no longer read (--new-config vs --app-config)
 *
 * Usage:
 *   php compare_framework.php --old=<dir> --new=<dir> --app=<dir> [--official=<dir>] [--name=label]
 *       [--new-config=<dir>] [--app-config=<dir>] [--exclude=a,b] [--diff-dir=<dir>] [--store=<OUT>]
 * Without --store the JSON is printed to stdout; with --store it becomes a new version in <OUT>/<name>/scans/.
 */
declare(strict_types=1);

ini_set('display_errors', 'stderr');
require __DIR__ . '/lib.php';
require __DIR__ . '/history.php';

$old = opt($argv, 'old');
$new = opt($argv, 'new');
$app = opt($argv, 'app');
if (!$old || !$new || !$app || !is_dir($old) || !is_dir($new) || !is_dir($app)) {
    fwrite(STDERR, "Usage: php compare_framework.php --old=<dir> --new=<dir> --app=<dir> [--official=<dir>] [--new-config=<dir>] [--app-config=<dir>] [--diff-dir=<dir>]\n");
    exit(2);
}
$excludes = excludesFrom($argv);
$official = opt($argv, 'official');
$diffDir = opt($argv, 'diff-dir');
// --store=<OUT>: save as a new version in the history store instead of printing to stdout
$store = opt($argv, 'store');
$storeEntity = $storeDir = $storeId = $storePrev = null;
if ($store !== null) {
    @mkdir($store, 0700, true);
    $storeEntity = entity_dir(realpath($store), opt($argv, 'name', 'Framework comparison'));
    @mkdir($storeEntity, 0700, true);
    $storePrev = history_latest($storeEntity);
    [$storeId, $storeDir] = history_new_scan_dir($storeEntity, scan_id());
    $diffDir ??= "$storeDir/diffs";
}

$oldApi = extractApi(realpath($old), $excludes);
$newApi = extractApi(realpath($new), $excludes);
$appApi = extractApi(realpath($app), $excludes);

$result = [
    'tool' => 'compare_framework',
    'type' => 'compare',
    'name' => opt($argv, 'name', 'Perbandingan framework'),
    'generated_at' => date('c'),
    'old' => ['path' => basename(dirname(realpath($old))) . '/' . basename(realpath($old)), 'files' => count($oldApi['files']), 'classes' => count($oldApi['classes']), 'functions' => count($oldApi['functions'])],
    'new' => ['path' => basename(dirname(realpath($new))) . '/' . basename(realpath($new)), 'files' => count($newApi['files']), 'classes' => count($newApi['classes']), 'functions' => count($newApi['functions'])],
    'app' => ['path' => basename(realpath($app)), 'files' => count($appApi['files']), 'classes' => count($appApi['classes'])],
];

// 1. Patch lokal: framework lama vs rilis resmi
if ($official && is_dir($official)) {
    $offApi = extractApi(realpath($official), $excludes);
    $local = diffFiles($offApi['files'], $oldApi['files']);
    $local['diffs'] = [];
    foreach ($local['changed'] as $f) {
        $a = normalizedSource(realpath($official) . "/$f");
        $b = normalizedSource(realpath($old) . "/$f");
        $diff = unifiedDiff($a, $b, "resmi/$f", "lama/$f");
        $local['diffs'][$f] = strlen($diff) > 20000 ? substr($diff, 0, 20000) . "\n... (dipotong)" : $diff;
        if ($diffDir) {
            @mkdir($diffDir, 0700, true);
            file_put_contents("$diffDir/local-" . str_replace('/', '__', $f) . '.diff', redact_text($diff));
        }
    }
    $result['local_patches'] = $local;
}

// 2. API lama vs baru
$files = diffFiles($oldApi['files'], $newApi['files']);
$result['files'] = ['added' => $files['added'], 'removed' => $files['removed'], 'changed_count' => count($files['changed']), 'same_count' => $files['same']];
$result['api'] = diffApi($oldApi, $newApi);

// 3. Kompatibilitas override aplikasi
$result['overrides'] = [
    'new' => checkOverrides($appApi, $newApi),
    'old' => checkOverrides($appApi, $oldApi),
];
$result['app_parents'] = appParents($appApi, $newApi, $oldApi);

// 4. Calls to removed/changed API
$result['usage'] = findUsage(realpath($app), $excludes, $result['api'], $files['removed']);

// 5. Config
$newCfg = opt($argv, 'new-config');
$appCfg = opt($argv, 'app-config');
if ($newCfg && $appCfg && is_dir($newCfg) && is_dir($appCfg)) {
    $result['config'] = diffConfig(realpath($newCfg), realpath($appCfg), stringLiterals(realpath($old), $excludes), stringLiterals(realpath($new), $excludes));
}

if ($storeDir !== null) {
    $result['scan_id'] = $storeId;
    writeJson("$storeDir/compare.json", $result);
    $carried = $storePrev !== null && history_carry_notes($storeEntity, $storePrev['id'], $storeDir);
    history_add($storeEntity, $result['name'], 'compare', $storeId, ['generated_at' => $result['generated_at']]);
    fwrite(STDERR, "[{$result['name']}] stored as scan $storeId" . ($carried ? ' (notes carried over for review)' : '') . "\n");
    echo "$storeDir/compare.json\n";
} else {
    printJson($result);
}

// ---------------------------------------------------------------------------------------------

function sigTokens(string $code): array
{
    $sig = [];
    $line = 1;
    foreach (token_get_all($code) as $t) {
        if (is_array($t)) {
            if (!in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $sig[] = [$t[0], $t[1], $t[2]];
            }
        } else {
            $sig[] = [$t, $t, $line];
        }
        if (is_array($t)) {
            $line = $t[2] + substr_count($t[1], "\n");
        }
    }
    return $sig;
}

/** Normalised source: no comments, collapsed whitespace; fair hashes and diffs for minified files. */
function normalizedSource(string $file): string
{
    $out = [];
    $buf = '';
    foreach (token_get_all((string) file_get_contents($file)) as $t) {
        $id = is_array($t) ? $t[0] : $t;
        $text = is_array($t) ? $t[1] : $t;
        if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
            continue;
        }
        if ($id === T_WHITESPACE) {
            $buf .= ' ';
            continue;
        }
        if ($id === T_OPEN_TAG) {
            $text = '<?php';
        }
        $buf .= $text;
        if (in_array($text, [';', '{', '}'], true)) {
            $out[] = trim(preg_replace('/\s+/', ' ', $buf));
            $buf = '';
        }
    }
    $out[] = trim(preg_replace('/\s+/', ' ', $buf));
    return implode("\n", array_filter($out, fn ($l) => $l !== '')) . "\n";
}

function extractApi(string $root, array $excludes): array
{
    $api = ['files' => [], 'classes' => [], 'functions' => []];
    foreach (listPhpFiles($root, $excludes) as $path) {
        $rel = substr($path, strlen($root) + 1);
        $code = (string) file_get_contents($path);
        $sig = sigTokens($code);
        $api['files'][$rel] = md5(implode(' ', array_map(fn ($t) => $t[0] === T_OPEN_TAG ? '<?php' : $t[1], $sig)));
        parseFile($sig, $rel, $api);
    }
    return $api;
}

function matchClose(array $sig, int $open): int
{
    $depth = 0;
    $n = count($sig);
    for ($j = $open; $j < $n; $j++) {
        $id = $sig[$j][0];
        if (in_array($id, ['(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE], true)) {
            $depth++;
        } elseif (in_array($id, [')', ']', '}'], true)) {
            if (--$depth === 0) {
                return $j;
            }
        }
    }
    return $n - 1;
}

function parseFile(array $sig, string $rel, array &$api): void
{
    $n = count($sig);
    $classRanges = [];
    for ($i = 0; $i < $n; $i++) {
        $id = $sig[$i][0];
        if (!in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) || ($sig[$i - 1][0] ?? null) === T_DOUBLE_COLON || ($sig[$i + 1][0] ?? null) !== T_STRING) {
            continue;
        }
        $mods = [];
        for ($b = $i - 1; $b >= 0 && in_array($sig[$b][0], [T_ABSTRACT, T_FINAL, T_READONLY], true); $b--) {
            $mods[] = strtolower($sig[$b][1]);
        }
        $name = $sig[$i + 1][1];
        $extends = null;
        $implements = [];
        $k = $i + 2;
        $mode = null;
        while ($k < $n && $sig[$k][0] !== '{') {
            if ($sig[$k][0] === T_EXTENDS) {
                $mode = 'e';
            } elseif ($sig[$k][0] === T_IMPLEMENTS) {
                $mode = 'i';
            } elseif (in_array($sig[$k][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $mode === 'e' ? $extends ??= ltrim($sig[$k][1], '\\') : $implements[] = ltrim($sig[$k][1], '\\');
            }
            $k++;
        }
        $close = matchClose($sig, $k);
        $classRanges[] = [$k, $close];
        $class = ['name' => $name, 'kind' => strtolower($sig[$i][1]), 'file' => $rel, 'line' => $sig[$i][2], 'extends' => $extends,
            'implements' => $implements, 'final' => in_array('final', $mods, true), 'abstract' => in_array('abstract', $mods, true),
            'methods' => [], 'props' => []];
        $depth = 0;
        $memberStart = $k + 1;
        for ($m = $k + 1; $m < $close; $m++) {
            $mid = $sig[$m][0];
            if (in_array($mid, ['(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE], true)) {
                $depth++;
                continue;
            }
            if (in_array($mid, [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0 && $mid === '}') {
                    $memberStart = $m + 1;
                }
                continue;
            }
            if ($depth !== 0) {
                continue;
            }
            if ($mid === ';') {
                $memberStart = $m + 1;
                continue;
            }
            $modsHere = [];
            for ($q = $memberStart; $q < $m; $q++) {
                if (is_int($sig[$q][0]) && in_array($sig[$q][0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT, T_VAR, T_READONLY], true)) {
                    $modsHere[] = strtolower($sig[$q][1]);
                }
            }
            $vis = in_array('private', $modsHere, true) ? 'private' : (in_array('protected', $modsHere, true) ? 'protected' : 'public');
            if ($mid === T_FUNCTION) {
                $p = $m + 1;
                $byRefReturn = false;
                if ($sig[$p][0] === '&') {
                    $byRefReturn = true;
                    $p++;
                }
                $mname = $sig[$p][1];
                $paren = $p + 1;
                if (($sig[$paren][0] ?? null) !== '(') {
                    continue;
                }
                $pclose = matchClose($sig, $paren);
                $ret = null;
                $q = $pclose + 1;
                if (($sig[$q][0] ?? null) === ':') {
                    $ret = '';
                    for ($q++; $q < $close && !in_array($sig[$q][0], ['{', ';'], true); $q++) {
                        $ret .= $sig[$q][1];
                    }
                }
                $class['methods'][strtolower($mname)] = [
                    'name' => $mname, 'line' => $sig[$m][2], 'visibility' => $vis, 'static' => in_array('static', $modsHere, true),
                    'final' => in_array('final', $modsHere, true), 'abstract' => in_array('abstract', $modsHere, true),
                    'byref' => $byRefReturn, 'params' => parseParams($sig, $paren, $pclose), 'return' => $ret,
                ];
                // skip the method body
                $body = $q;
                while ($body < $close && !in_array($sig[$body][0], ['{', ';'], true)) {
                    $body++;
                }
                if (($sig[$body][0] ?? null) === '{') {
                    $m = matchClose($sig, $body);
                }
                $memberStart = $m + 1;
                continue;
            }
            if ($mid === T_VARIABLE && $modsHere !== []) {
                $type = '';
                for ($q = $memberStart; $q < $m; $q++) {
                    if (!is_int($sig[$q][0]) || !in_array($sig[$q][0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_VAR, T_READONLY, T_ATTRIBUTE], true)) {
                        $type .= $sig[$q][1];
                    }
                }
                $class['props'][substr($sig[$m][1], 1)] = ['visibility' => $vis, 'static' => in_array('static', $modsHere, true), 'type' => $type === '' ? null : $type, 'line' => $sig[$m][2]];
            }
        }
        $api['classes'][strtolower($name)] = $class;
        $i = $close;
    }
    for ($i = 0; $i < $n; $i++) {
        if ($sig[$i][0] !== T_FUNCTION || ($sig[$i + 1][0] ?? null) !== T_STRING || ($sig[$i + 2][0] ?? null) !== '(') {
            continue;
        }
        foreach ($classRanges as [$a, $b]) {
            if ($i > $a && $i < $b) {
                continue 2;
            }
        }
        $pclose = matchClose($sig, $i + 2);
        $api['functions'][strtolower($sig[$i + 1][1])] = ['name' => $sig[$i + 1][1], 'file' => $rel, 'line' => $sig[$i][2], 'params' => parseParams($sig, $i + 2, $pclose)];
    }
}

function parseParams(array $sig, int $open, int $close): array
{
    $params = [];
    $depth = 0;
    $cur = [];
    for ($j = $open + 1; $j < $close; $j++) {
        $id = $sig[$j][0];
        if (in_array($id, ['(', '[', '{', T_ATTRIBUTE], true)) {
            $depth++;
        } elseif (in_array($id, [')', ']', '}'], true)) {
            $depth--;
        }
        if ($depth === 0 && $id === ',') {
            $params[] = $cur;
            $cur = [];
            continue;
        }
        $cur[] = $sig[$j];
    }
    if ($cur !== []) {
        $params[] = $cur;
    }
    $out = [];
    foreach ($params as $toks) {
        $p = ['name' => null, 'type' => null, 'default' => null, 'byref' => false, 'variadic' => false];
        $type = '';
        $seenVar = false;
        $skip = 0;
        foreach ($toks as $k => $t) {
            if ($skip > 0) {
                $skip--;
                continue;
            }
            if ($t[0] === T_ATTRIBUTE) {
                // skip the attribute up to its closing ]
                $d = 0;
                foreach (array_slice($toks, $k) as $u => $tt) {
                    if (in_array($tt[0], ['[', T_ATTRIBUTE], true)) {
                        $d++;
                    } elseif ($tt[0] === ']' && --$d === 0) {
                        $skip = $u;
                        break;
                    }
                }
                continue;
            }
            if (!$seenVar) {
                if ($t[0] === T_VARIABLE) {
                    $p['name'] = $t[1];
                    $seenVar = true;
                } elseif ($t[0] === T_ELLIPSIS) {
                    $p['variadic'] = true;
                } elseif (in_array($t[0], ['&', T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG], true)) {
                    $p['byref'] = true;
                } elseif (!in_array($t[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY], true)) {
                    $type .= $t[1];
                }
            } elseif ($t[0] === '=') {
                $p['default'] = implode('', array_map(fn ($x) => $x[1], array_slice($toks, $k + 1)));
                break;
            }
        }
        $p['type'] = $type === '' ? null : $type;
        $out[] = $p;
    }
    return $out;
}

function sigString(array $m): string
{
    $params = array_map(fn ($p) => trim(($p['type'] ? $p['type'] . ' ' : '') . ($p['byref'] ? '&' : '') . ($p['variadic'] ? '...' : '') . $p['name'] . ($p['default'] !== null ? ' = ' . $p['default'] : '')), $m['params']);
    $mods = trim(($m['final'] ?? false ? 'final ' : '') . ($m['abstract'] ?? false ? 'abstract ' : '') . ($m['visibility'] ?? '') . ($m['static'] ?? false ? ' static' : ''));
    return trim($mods . ' function ' . ($m['byref'] ?? false ? '&' : '') . $m['name'] . '(' . implode(', ', $params) . ')' . (($m['return'] ?? null) !== null ? ': ' . $m['return'] : ''));
}

function diffFiles(array $a, array $b): array
{
    $added = array_values(array_diff(array_keys($b), array_keys($a)));
    $removed = array_values(array_diff(array_keys($a), array_keys($b)));
    $changed = [];
    $same = 0;
    foreach ($a as $f => $h) {
        if (isset($b[$f])) {
            $h === $b[$f] ? $same++ : $changed[] = $f;
        }
    }
    sort($added);
    sort($removed);
    sort($changed);
    return ['added' => $added, 'removed' => $removed, 'changed' => $changed, 'same' => $same];
}

function requiredCount(array $params): int
{
    $n = 0;
    foreach ($params as $i => $p) {
        if ($p['default'] === null && !$p['variadic']) {
            $n = $i + 1;
        }
    }
    return $n;
}

function diffApi(array $old, array $new): array
{
    $out = ['classes_removed' => [], 'classes_added' => [], 'methods_removed' => [], 'methods_changed' => [], 'methods_added' => 0,
        'props_changed' => [], 'functions_removed' => [], 'functions_changed' => [], 'functions_added' => []];
    foreach ($old['classes'] as $k => $c) {
        if (!isset($new['classes'][$k])) {
            $out['classes_removed'][] = ['class' => $c['name'], 'file' => $c['file']];
            continue;
        }
        $nc = $new['classes'][$k];
        foreach ($c['methods'] as $mk => $m) {
            if ($m['visibility'] === 'private') {
                continue;
            }
            if (!isset($nc['methods'][$mk])) {
                // may be inherited from a new parent; check the parent chain
                if (findMethod($new, $nc['extends'], $mk) === null) {
                    $out['methods_removed'][] = ['class' => $c['name'], 'method' => $m['name'], 'old' => sigString($m), 'file' => $nc['file']];
                }
                continue;
            }
            $nm = $nc['methods'][$mk];
            if (sigString($m) !== sigString($nm)) {
                $out['methods_changed'][] = ['class' => $c['name'], 'method' => $m['name'], 'old' => sigString($m), 'new' => sigString($nm),
                    'more_required' => requiredCount($nm['params']) > requiredCount($m['params']), 'new_required' => requiredCount($nm['params']),
                    'return_added' => $m['return'] === null && $nm['return'] !== null,
                    'file' => $nc['file']];
            }
        }
        $out['methods_added'] += count(array_diff_key($nc['methods'], $c['methods']));
        foreach ($c['props'] as $pk => $p) {
            $np = $nc['props'][$pk] ?? null;
            if ($np !== null && ($np['type'] !== $p['type'] || $np['visibility'] !== $p['visibility'] || $np['static'] !== $p['static'])) {
                $out['props_changed'][] = ['class' => $c['name'], 'prop' => $pk, 'old' => $p, 'new' => $np];
            }
        }
    }
    foreach ($new['classes'] as $k => $c) {
        if (!isset($old['classes'][$k])) {
            $out['classes_added'][] = ['class' => $c['name'], 'file' => $c['file']];
        }
    }
    foreach ($old['functions'] as $k => $f) {
        if (!isset($new['functions'][$k])) {
            $out['functions_removed'][] = ['function' => $f['name'], 'file' => $f['file']];
        } else {
            $a = sigString($f + ['visibility' => '']);
            $b = sigString($new['functions'][$k] + ['visibility' => '']);
            if ($a !== $b) {
                $out['functions_changed'][] = ['function' => $f['name'], 'old' => $a, 'new' => $b,
                    'more_required' => requiredCount($new['functions'][$k]['params']) > requiredCount($f['params']), 'new_required' => requiredCount($new['functions'][$k]['params']),
                    'file' => $new['functions'][$k]['file']];
            }
        }
    }
    foreach ($new['functions'] as $k => $f) {
        if (!isset($old['functions'][$k])) {
            $out['functions_added'][] = $f['name'];
        }
    }
    return $out;
}

/** Find a method in the parent chain (framework classes only). */
function findMethod(array $api, ?string $class, string $method): ?array
{
    $seen = 0;
    while ($class !== null && $seen++ < 20) {
        $c = $api['classes'][strtolower($class)] ?? null;
        if ($c === null) {
            return null;
        }
        if (isset($c['methods'][$method])) {
            return $c['methods'][$method] + ['class' => $c['name'], 'class_final' => $c['final']];
        }
        $class = $c['extends'];
    }
    return null;
}

function findProp(array $api, ?string $class, string $prop): ?array
{
    $seen = 0;
    while ($class !== null && $seen++ < 20) {
        $c = $api['classes'][strtolower($class)] ?? null;
        if ($c === null) {
            return null;
        }
        if (isset($c['props'][$prop])) {
            return $c['props'][$prop] + ['class' => $c['name']];
        }
        $class = $c['extends'];
    }
    return null;
}

/** Nearest framework parent of an application class (skipping other application classes). */
function frameworkParent(array $app, array $fw, ?string $class): ?string
{
    $seen = 0;
    while ($class !== null && $seen++ < 20) {
        $k = strtolower($class);
        if (isset($fw['classes'][$k]) && !isset($app['classes'][$k])) {
            return $fw['classes'][$k]['name'];
        }
        $class = $app['classes'][$k]['extends'] ?? null;
    }
    return null;
}

function normType(?string $t): string
{
    return strtolower(ltrim((string) $t, '\\?'));
}

/** Aturan kompatibilitas signature PHP (disederhanakan). Kembalikan daftar masalah. */
function checkOverrides(array $app, array $fw): array
{
    $issues = [];
    foreach ($app['classes'] as $c) {
        $parent = frameworkParent($app, $fw, $c['extends']);
        if ($parent === null) {
            if ($c['extends'] !== null && !isset($app['classes'][strtolower($c['extends'])]) && str_starts_with($c['extends'], 'CI_')) {
                $issues[] = ['level' => 'fatal', 'class' => $c['name'], 'member' => null, 'file' => $c['file'], 'line' => $c['line'],
                    'problem' => "Class induk {$c['extends']} tidak ada di framework ini", 'child' => null, 'parent' => null];
            }
            continue;
        }
        if ($fw['classes'][strtolower($parent)]['final'] ?? false) {
            $issues[] = ['level' => 'fatal', 'class' => $c['name'], 'member' => null, 'file' => $c['file'], 'line' => $c['line'],
                'problem' => "$parent bersifat final", 'child' => null, 'parent' => null];
        }
        foreach ($c['methods'] as $mk => $m) {
            // a method already defined by an application ancestor is compared with that one, not the framework
            $appAncestor = $c['extends'];
            while ($appAncestor !== null && isset($app['classes'][strtolower($appAncestor)])) {
                if (isset($app['classes'][strtolower($appAncestor)]['methods'][$mk])) {
                    continue 2;
                }
                $appAncestor = $app['classes'][strtolower($appAncestor)]['extends'];
            }
            $p = findMethod($fw, $parent, $mk);
            if ($p === null || $p['visibility'] === 'private') {
                continue;
            }
            $problems = [];
            $level = 'fatal';
            if ($p['final']) {
                $problems[] = 'method induk final';
            }
            if ($mk !== '__construct') {
                if ($p['static'] !== $m['static']) {
                    $problems[] = $p['static'] ? 'induk static, anak tidak' : 'anak static, induk tidak';
                }
                $rank = ['public' => 3, 'protected' => 2, 'private' => 1];
                if ($rank[$m['visibility']] < $rank[$p['visibility']]) {
                    $problems[] = "visibility anak ({$m['visibility']}) lebih sempit dari induk ({$p['visibility']})";
                }
                $childVariadic = array_filter($m['params'], fn ($x) => $x['variadic']) !== [];
                if (!$childVariadic && count($m['params']) < count($p['params'])) {
                    $problems[] = 'anak menerima lebih sedikit parameter (' . count($m['params']) . ') dari induk (' . count($p['params']) . ')';
                }
                if (requiredCount($m['params']) > requiredCount($p['params'])) {
                    $problems[] = 'anak mewajibkan lebih banyak parameter (' . requiredCount($m['params']) . ') dari induk (' . requiredCount($p['params']) . ')';
                }
                foreach ($p['params'] as $i => $pp) {
                    $cp = $m['params'][$i] ?? null;
                    if ($cp === null) {
                        continue;
                    }
                    if ($cp['byref'] !== $pp['byref']) {
                        $problems[] = "parameter #" . ($i + 1) . " beda by-reference";
                    }
                    if ($cp['type'] !== null && normType($cp['type']) !== 'mixed') {
                        if ($pp['type'] === null) {
                            $problems[] = "parameter #" . ($i + 1) . " bertipe {$cp['type']} di anak, tanpa tipe di induk";
                        } elseif (normType($cp['type']) !== normType($pp['type'])) {
                            $problems[] = "parameter #" . ($i + 1) . " tipe beda ({$cp['type']} vs {$pp['type']}), periksa variance";
                            $level = $level === 'fatal' && count($problems) === 1 ? 'periksa' : $level;
                        }
                    }
                }
                if ($p['return'] !== null) {
                    if ($m['return'] === null) {
                        $problems[] = "induk punya return type {$p['return']}, anak tidak";
                    } elseif (normType($m['return']) !== normType($p['return'])) {
                        $problems[] = "return type beda ({$m['return']} vs {$p['return']}), periksa variance";
                        $level = $level === 'fatal' && count($problems) === 1 ? 'periksa' : $level;
                    }
                }
            }
            if ($problems !== []) {
                $issues[] = ['level' => $level, 'class' => $c['name'], 'member' => $m['name'] . '()', 'file' => $c['file'], 'line' => $m['line'],
                    'problem' => implode('; ', $problems), 'child' => sigString($m), 'parent' => $p['class'] . '::' . sigString($p)];
            }
        }
        foreach ($c['props'] as $pk => $prop) {
            $pp = findProp($fw, $parent, $pk);
            if ($pp === null || $pp['visibility'] === 'private') {
                continue;
            }
            $problems = [];
            if (normType($pp['type']) !== normType($prop['type'])) {
                $problems[] = $pp['type'] === null ? "properti bertipe {$prop['type']} di anak, tanpa tipe di induk" : "properti induk bertipe {$pp['type']}, anak " . ($prop['type'] ?? 'tanpa tipe');
            }
            if ($pp['static'] !== $prop['static']) {
                $problems[] = 'static/non-static beda';
            }
            $rank = ['public' => 3, 'protected' => 2, 'private' => 1];
            if ($rank[$prop['visibility']] < $rank[$pp['visibility']]) {
                $problems[] = "visibility anak ({$prop['visibility']}) lebih sempit dari induk ({$pp['visibility']})";
            }
            if ($problems !== []) {
                $issues[] = ['level' => 'fatal', 'class' => $c['name'], 'member' => '$' . $pk, 'file' => $c['file'], 'line' => $prop['line'],
                    'problem' => implode('; ', $problems), 'child' => trim(($prop['type'] ?? '') . ' $' . $pk), 'parent' => $pp['class'] . '::' . trim(($pp['type'] ?? '') . ' $' . $pk)];
            }
        }
    }
    return $issues;
}

function appParents(array $app, array $new, array $old): array
{
    $count = [];
    foreach ($app['classes'] as $c) {
        $p = frameworkParent($app, $new, $c['extends']) ?? frameworkParent($app, $old, $c['extends']);
        if ($p !== null) {
            $count[$p] = ($count[$p] ?? 0) + 1;
        }
    }
    arsort($count);
    return $count;
}

/** Calls to removed methods/functions or ones with more required parameters, plus libraries/helpers whose file is gone. */
function findUsage(string $app, array $excludes, array $api, array $removedFiles): array
{
    $methods = [];
    foreach ($api['methods_removed'] as $m) {
        $methods[strtolower($m['method'])][] = ['kind' => 'method dihapus', 'what' => $m['class'] . '::' . $m['method'] . '()'];
    }
    foreach ($api['methods_changed'] as $m) {
        if ($m['more_required']) {
            $methods[strtolower($m['method'])][] = ['kind' => 'parameter wajib bertambah', 'what' => $m['class'] . '::' . $m['method'] . '()', 'required' => $m['new_required']];
        }
    }
    unset($methods['__construct']); // parent::__construct() selalu ke induk langsung; dicek lewat override
    $funcs = [];
    foreach ($api['functions_removed'] as $f) {
        if (function_exists($f['function'])) {
            continue; // polyfill lama; sekarang fungsi bawaan PHP
        }
        $funcs[strtolower($f['function'])][] = ['kind' => 'fungsi dihapus', 'what' => $f['function'] . '()'];
    }
    foreach ($api['functions_changed'] as $f) {
        if ($f['more_required']) {
            $funcs[strtolower($f['function'])][] = ['kind' => 'parameter wajib bertambah', 'what' => $f['function'] . '()', 'required' => $f['new_required']];
        }
    }
    $libs = [];
    foreach ($removedFiles as $f) {
        if (preg_match('#(?:^|/)(libraries|helpers|drivers)/(?:.*/)?([A-Za-z_]+?)(?:_helper)?\.php$#', $f, $mm)) {
            $libs[strtolower($mm[2])] = $f;
        }
    }
    $hits = [];
    foreach (listPhpFiles($app, $excludes) as $path) {
        $rel = substr($path, strlen($app) + 1);
        $sig = sigTokens((string) file_get_contents($path));
        $n = count($sig);
        for ($i = 0; $i < $n; $i++) {
            if ($sig[$i][0] !== T_STRING || ($sig[$i + 1][0] ?? null) !== '(') {
                if ($sig[$i][0] === T_CONSTANT_ENCAPSED_STRING && $libs !== []) {
                    $v = strtolower(substr($sig[$i][1], 1, -1));
                    $v = preg_replace('/_helper$/', '', $v);
                    if (isset($libs[$v]) && in_array(strtolower($sig[$i - 2][1] ?? ''), ['library', 'helper', 'driver'], true)) {
                        $hits[] = ['file' => $rel, 'line' => $sig[$i][2], 'kind' => 'file framework dihapus', 'what' => $libs[$v], 'code' => $sig[$i - 2][1] . '(' . $sig[$i][1] . ')'];
                    }
                }
                continue;
            }
            $name = strtolower($sig[$i][1]);
            $prev = $sig[$i - 1][0] ?? null;
            $isMember = in_array($prev, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
            $candidates = $isMember ? ($methods[$name] ?? []) : (in_array($prev, [T_FUNCTION, T_NEW], true) ? [] : ($funcs[$name] ?? []));
            if ($candidates === []) {
                continue;
            }
            $argc = callArgCount($sig, $i + 1);
            foreach ($candidates as $x) {
                if (isset($x['required']) && $argc !== null && $argc >= $x['required']) {
                    continue; // argumen yang dikirim sudah cukup
                }
                $hits[] = ['file' => $rel, 'line' => $sig[$i][2], 'kind' => $x['kind'], 'what' => $x['what'],
                    'code' => ($isMember ? '->' : '') . $sig[$i][1] . '(' . ($argc === null ? '...' : $argc . ' argumen') . ')'];
            }
        }
    }
    return $hits;
}

/** Number of arguments of the call opened at $open; null when a spread is used. */
function callArgCount(array $sig, int $open): ?int
{
    $depth = 0;
    $count = 0;
    $any = false;
    $n = count($sig);
    for ($j = $open; $j < $n; $j++) {
        $id = $sig[$j][0];
        if (in_array($id, ['(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
            $depth++;
            if ($j > $open) {
                $any = true;
            }
            continue;
        }
        if (in_array($id, [')', ']', '}'], true)) {
            if (--$depth === 0) {
                return $any ? $count + 1 : 0;
            }
            continue;
        }
        if ($depth === 1) {
            if ($id === T_ELLIPSIS) {
                return null;
            }
            if ($id === ',') {
                $count++;
                // trailing comma
                if (($sig[$j + 1][0] ?? null) === ')') {
                    $count--;
                }
                continue;
            }
            $any = true;
        }
    }
    return null;
}

function configKeys(string $file): array
{
    $keys = [];
    $sig = sigTokens((string) file_get_contents($file));
    $n = count($sig);
    for ($i = 0; $i < $n; $i++) {
        if ($sig[$i][0] === T_VARIABLE && ($sig[$i + 1][0] ?? null) === '[' && ($sig[$i + 2][0] ?? null) === T_CONSTANT_ENCAPSED_STRING && ($sig[$i + 3][0] ?? null) === ']') {
            $key = $sig[$i][1] . "['" . substr($sig[$i + 2][1], 1, -1) . "']";
            $keys[$key] ??= $sig[$i][2];
        }
    }
    return $keys;
}

/** Every string literal in the framework code, to know which config keys are read. */
function stringLiterals(string $root, array $excludes): array
{
    $set = [];
    foreach (listPhpFiles($root, $excludes) as $path) {
        foreach (token_get_all((string) file_get_contents($path)) as $t) {
            if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $set[substr($t[1], 1, -1)] = true;
            }
        }
    }
    return $set;
}

/**
 * $config[...] keys of the new framework's default config vs the application config:
 *  - new_keys: in the new default config, not in the application, and read by the new framework
 *  - unused_keys: in the application, read by the old framework, no longer read by the new one
 */
function diffConfig(string $newDir, string $appDir, array $oldLits, array $newLits): array
{
    $out = [];
    foreach (glob("$newDir/*.php") ?: [] as $f) {
        $base = basename($f);
        if ($base === 'routes.php') {
            continue;
        }
        $appFile = "$appDir/$base";
        if (!is_file($appFile)) {
            $out[] = ['file' => $base, 'missing_in_app' => true, 'new_keys' => [], 'unused_keys' => []];
            continue;
        }
        $key = fn (string $k) => preg_match("/\\['([^']+)'\\]$/", $k, $m) ? $m[1] : $k;
        $onlyConfig = fn (array $keys) => array_filter($keys, fn ($k) => preg_match('/^\\$(config|autoload)\\[/', $k));
        $a = $onlyConfig(array_keys(configKeys($f)));
        $b = $onlyConfig(array_keys(configKeys($appFile)));
        $newKeys = array_values(array_filter(array_diff($a, $b), fn ($k) => isset($newLits[$key($k)])));
        $unused = array_values(array_filter($b, fn ($k) => isset($oldLits[$key($k)]) && !isset($newLits[$key($k)])));
        if ($newKeys || $unused) {
            $out[] = ['file' => $base, 'missing_in_app' => false, 'new_keys' => $newKeys, 'unused_keys' => $unused];
        }
    }
    return $out;
}

function unifiedDiff(string $a, string $b, string $labelA, string $labelB): string
{
    $fa = tempnam(sys_get_temp_dir(), 'cmpa');
    $fb = tempnam(sys_get_temp_dir(), 'cmpb');
    file_put_contents($fa, $a);
    file_put_contents($fb, $b);
    $out = (string) shell_exec('diff -u --label ' . escapeshellarg($labelA) . ' --label ' . escapeshellarg($labelB) . ' ' . escapeshellarg($fa) . ' ' . escapeshellarg($fb));
    unlink($fa);
    unlink($fb);
    return $out;
}
