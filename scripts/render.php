<?php
/**
 * Render the whole history store into ONE self-contained HTML report:
 * a page per repo / comparison, every scan version selectable (latest is the default), plus a dashboard.
 * Works as a Claude artifact and as a plain local page (open the file in a browser, or serve the folder).
 *
 * Every string goes through redaction again right before it is written, including analyst notes and
 * the "current" / "suggested" code samples.
 *
 * Usage:
 *   php render.php --store=<OUT> --out=<file.html> [--lang=en|id] [--title="..."]
 *                  [--include=glob,glob] [--hide=name,name] [--url=<published link>] [--offline]
 *
 *   --include  only these entities get a page (glob on the name, e.g. "shop-*"); others stay as data
 *   --hide     keep as data (e.g. for comparison tables) but no page
 *   --url      published link, used by the "Copy link" button (local pages use their own address)
 *   --offline  no external requests at all (drops the web font link; system fonts are used)
 */
declare(strict_types=1);

ini_set('display_errors', 'stderr');
require __DIR__ . '/lib.php';
require __DIR__ . '/history.php';

$store = opt($argv, 'store');
$outFile = opt($argv, 'out');
if ($store === null || !is_dir($store) || $outFile === null) {
    fwrite(STDERR, "Usage: php render.php --store=<OUT> --out=<file.html> [--lang=en|id] [--include=glob] [--hide=a,b] [--url=...] [--offline]\n");
    exit(2);
}
$store = realpath($store);
$lang = opt($argv, 'lang', 'en') === 'id' ? 'id' : 'en';
$include = array_filter(explode(',', (string) opt($argv, 'include', '')));
$hidden = array_filter(explode(',', (string) opt($argv, 'hide', '')));

history_migrate($store); // never lose legacy flat output
history_archive_overview($store);

$rulesEn = $lang === 'en' ? require __DIR__ . '/rules_en.php' : [];
$entities = [];
foreach (history_entities($store) as ['dir' => $dir, 'index' => $index]) {
    $name = $index['name'];
    $isHidden = in_array($name, $hidden, true) || ($include !== [] && !array_filter($include, fn ($g) => fnmatch($g, $name)));
    $versions = [];
    $scans = $index['scans'] ?? [];
    foreach ($scans as $i => $scan) {
        $isLatest = $i === count($scans) - 1;
        if ($isHidden && !$isLatest) {
            continue; // hidden entities only feed comparison tables, the latest scan is enough
        }
        $file = $index['kind'] === 'compare' ? 'compare.json' : 'report.json';
        $data = json_decode((string) @file_get_contents("$dir/scans/{$scan['id']}/$file"), true);
        if (!is_array($data)) {
            continue;
        }
        $notesFile = "$dir/scans/{$scan['id']}/notes.json";
        if (is_file($notesFile)) {
            foreach (json_decode((string) file_get_contents($notesFile), true) ?? [] as $k => $v) {
                $data[$k] = $v;
            }
        }
        $data['scan_id'] = $scan['id'];
        // Local absolute paths (they reveal user names and folder layout) never go into the report.
        unset($data['profile']['path'], $data['scan']['repo'], $data['rector']['stderr'], $data['phpcompat']['stderr'], $data['rector']['files']);
        if ($rulesEn) {
            foreach ($data['scan']['rules'] ?? [] as $rid => $meta) {
                if (isset($rulesEn[$rid])) {
                    $data['scan']['rules'][$rid]['title'] = $rulesEn[$rid][0];
                    $data['scan']['rules'][$rid]['fix'] = $rulesEn[$rid][1];
                }
            }
        }
        if ($isHidden) {
            $data['scan']['findings'] = array_map(fn ($x) => array_intersect_key($x, array_flip(['rule', 'guarded'])), $data['scan']['findings'] ?? []);
            unset($data['phpcompat'], $data['rector']);
            $data['lint']['deprecations'] = [];
        }
        $versions[] = $data;
    }
    if ($versions !== []) {
        $entities[] = ['name' => $name, 'kind' => $index['kind'], 'hidden' => $isHidden, 'versions' => $versions];
    }
}
usort($entities, fn ($a, $b) => [$a['kind'] === 'compare', $a['name']] <=> [$b['kind'] === 'compare', $b['name']]);

$overview = is_file("$store/overview.json") ? json_decode((string) file_get_contents("$store/overview.json"), true) : null;
$data = [
    'title' => opt($argv, 'title', 'PHP Upgrade Audit'),
    'lang' => $lang,
    'generated_at' => date('c'),
    'url' => opt($argv, 'url'),
    'overview' => $overview,
    'entities' => $entities,
];

$redacted = 0;
$data = redact_data($data, $redacted);
$data['redacted'] = $redacted;

$template = (string) file_get_contents(__DIR__ . '/../assets/report-template.html');
if (opt($argv, 'offline')) {
    $template = preg_replace('#<link[^>]+fonts\.(googleapis|gstatic)\.com[^>]*>\s*#', '', $template);
}
$json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE);
$html = str_replace(['{{TITLE}}', '{{DATA}}'], [htmlspecialchars($data['title'], ENT_QUOTES), $json], $template);
file_put_contents($outFile, $html);

$versionCount = array_sum(array_map(fn ($e) => count($e['versions']), $entities));
fwrite(STDERR, sprintf("Report: %s (%.1f KB, %d entities, %d scan versions, %d value(s) redacted)\n", $outFile, strlen($html) / 1024, count($entities), $versionCount, $redacted));
