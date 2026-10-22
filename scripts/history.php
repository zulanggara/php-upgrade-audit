<?php
/**
 * Scan history store. Every scan of a repo (or framework comparison) becomes a new version; nothing is
 * overwritten or deleted.
 *
 *   <OUT>/<entity>/index.json                  {"name", "kind": "repo"|"compare", "scans": [{"id", "generated_at", ...}]}
 *   <OUT>/<entity>/scans/<scan-id>/report.json  (repo)   or compare.json (compare)
 *   <OUT>/<entity>/scans/<scan-id>/notes.json   analyst notes for that scan
 *   <OUT>/overview.json                         cross-repo summary (older copies kept in <OUT>/_overview/)
 *
 * CLI:
 *   php history.php list <OUT>                 list entities and their scans
 *   php history.php migrate <OUT>              move legacy flat output (<OUT>/<name>/report.json) into history
 */
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

/** Sortable, filesystem-safe scan id in UTC, e.g. 20261022T131200Z. */
function scan_id(?int $time = null): string
{
    return gmdate('Ymd\THis\Z', $time ?? time());
}

function entity_slug(string $name): string
{
    return trim((string) preg_replace('/[^a-z0-9._-]+/', '-', strtolower($name)), '-');
}

function entity_dir(string $out, string $name): string
{
    return rtrim($out, '/') . '/' . entity_slug($name);
}

function history_index(string $dir): array
{
    $file = "$dir/index.json";
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    return is_array($data) ? $data : ['name' => basename($dir), 'kind' => 'repo', 'scans' => []];
}

/** Register a finished scan. Existing entries are kept; the list stays sorted oldest → newest. */
function history_add(string $dir, string $name, string $kind, string $id, array $meta = []): array
{
    $index = history_index($dir);
    $index['name'] = $name;
    $index['kind'] = $kind;
    $index['scans'] = array_values(array_filter($index['scans'] ?? [], fn ($s) => $s['id'] !== $id));
    $index['scans'][] = ['id' => $id] + $meta;
    usort($index['scans'], fn ($a, $b) => strcmp($a['id'], $b['id']));
    writeJson("$dir/index.json", $index);
    return $index;
}

/** Directory of a new scan; a suffix is added if two scans start in the same second. */
function history_new_scan_dir(string $dir, string $id): array
{
    $final = $id;
    for ($i = 2; is_dir("$dir/scans/$final"); $i++) {
        $final = "$id-$i";
    }
    $path = "$dir/scans/$final";
    if (!mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException("Cannot create $path");
    }
    return [$final, $path];
}

function history_latest(string $dir): ?array
{
    $scans = history_index($dir)['scans'] ?? [];
    return $scans === [] ? null : end($scans);
}

/**
 * Carry the analyst notes of the previous scan into a new scan, marked as not yet reviewed,
 * so a re-scan never shows an empty notes section and never silently drops earlier conclusions.
 */
function history_carry_notes(string $dir, string $prevId, string $newPath): bool
{
    $prev = "$dir/scans/$prevId/notes.json";
    if (!is_file($prev) || is_file("$newPath/notes.json")) {
        return false;
    }
    $notes = json_decode((string) file_get_contents($prev), true);
    if (!is_array($notes)) {
        return false;
    }
    $notes['carried_from'] = $notes['carried_from'] ?? $prevId;
    writeJson("$newPath/notes.json", $notes);
    return true;
}

/** Move legacy flat output (<OUT>/<name>/report.json + friends) into <OUT>/<name>/scans/<id>/. */
function history_migrate(string $out): array
{
    $moved = [];
    foreach (glob(rtrim($out, '/') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        foreach (['report.json' => 'repo', 'compare.json' => 'compare'] as $file => $kind) {
            if (!is_file("$dir/$file")) {
                continue;
            }
            $data = json_decode((string) file_get_contents("$dir/$file"), true) ?? [];
            $time = isset($data['generated_at']) ? strtotime($data['generated_at']) : filemtime("$dir/$file");
            [$id, $path] = history_new_scan_dir($dir, scan_id($time ?: null));
            foreach (glob("$dir/*") ?: [] as $f) {
                $base = basename($f);
                if (in_array($base, ['scans', 'index.json'], true)) {
                    continue;
                }
                rename($f, "$path/$base");
            }
            $name = $data['name'] ?? basename($dir);
            history_add($dir, $name, $kind, $id, ['generated_at' => $data['generated_at'] ?? date('c', $time), 'target' => $data['target'] ?? null]);
            $moved[] = "$name → scans/$id";
        }
    }
    return $moved;
}

/** Keep every distinct version of overview.json in <OUT>/_overview/<id>.json. */
function history_archive_overview(string $out): void
{
    $file = rtrim($out, '/') . '/overview.json';
    if (!is_file($file)) {
        return;
    }
    $dir = rtrim($out, '/') . '/_overview';
    @mkdir($dir, 0700, true);
    $content = (string) file_get_contents($file);
    foreach (glob("$dir/*.json") ?: [] as $old) {
        if (file_get_contents($old) === $content) {
            return;
        }
    }
    file_put_contents("$dir/" . scan_id(filemtime($file) ?: null) . '.json', $content);
}

/** All entities in a store: [['dir', 'index'], ...]. */
function history_entities(string $out): array
{
    $list = [];
    foreach (glob(rtrim($out, '/') . '/*/index.json') ?: [] as $idx) {
        $list[] = ['dir' => dirname($idx), 'index' => history_index(dirname($idx))];
    }
    return $list;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    ini_set('display_errors', 'stderr');
    $cmd = $argv[1] ?? '';
    $out = $argv[2] ?? '';
    handleHelp($argv, <<<'HELP'
    History store helpers.

    Usage:
      php scripts/history.php list <store>      list repos/comparisons and their scans
      php scripts/history.php migrate <store>   move old flat output (<store>/<name>/report.json) into scans/
    HELP);
    if (!in_array($cmd, ['list', 'migrate'], true) || $out === '' || !is_dir($out)) {
        usageError('expected: list|migrate <store>.', 'scripts/history.php');
    }
    if ($cmd === 'migrate') {
        foreach (history_migrate($out) as $m) {
            echo "migrated: $m\n";
        }
    }
    foreach (history_entities($out) as $e) {
        $scans = $e['index']['scans'] ?? [];
        echo $e['index']['name'], ' (', $e['index']['kind'], '): ', count($scans), ' scan(s)', $scans ? ', latest ' . end($scans)['id'] : '', "\n";
    }
}
