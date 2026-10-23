<?php
/**
 * Check every source this kit cites: the URL still answers, and for a file on GitHub (UPGRADING, UPGRADE.md,
 * CHANGELOG.md at a tag) the quoted text is still in it. Run weekly by .github/workflows/links.yml.
 *
 *   php tests/check_links.php [--skip-host=a.org,b.org] [--json=<file>]
 *
 * Sources: rule "ref" (rules.php), rules_evidence.php, library_notes.php, links in references/*.md and in the
 * sample notes (examples/source/*.json). Exit 0 when all are fine, 10 when something is broken.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/scripts/lib.php';
handleHelp($argv, <<<'HELP'
Check that every cited source still exists, and that quotes are still in the files they quote.

Usage: php tests/check_links.php [--skip-host=a.org,b.org] [--json=<file>]

  --skip-host  Hosts not to check (e.g. when they are blocked where this runs).
  --json       Also write the result as JSON.

Exit codes: 0 all fine, 10 broken links or missing quotes.
HELP);

$root = dirname(__DIR__);
$skip = array_filter(explode(',', (string) opt($argv, 'skip-host', '')));
/** @var array<string, array{quotes: array<string, array<string, true>>, from: array<string, true>}> $links */
$links = [];
$add = function (string $url, string $from, ?string $quote = null) use (&$links): void {
    if (!preg_match('#^https?://#', $url)) {
        return;
    }
    $links[$url]['from'][$from] = true;
    $links[$url]['quotes'] ??= [];
    if ($quote !== null && trim($quote) !== '') {
        $links[$url]['quotes'][$quote][$from] = true;
    }
};

foreach (require "$root/scripts/rules.php" as $r) {
    $add($r['ref'], "rules.php {$r['id']}");
    foreach ($r['evidence'] ?? [] as $e) {
        $add($e['url'], "rules.php {$r['id']}", $e['quote'] ?? null);
    }
}
foreach (require "$root/scripts/rules_evidence.php" as $id => $list) {
    foreach ($list as $e) {
        $add($e['url'], "rules_evidence.php $id", $e['quote'] ?? null);
    }
}
foreach (require "$root/scripts/library_notes.php" as $n) {
    foreach ($n['evidence'] ?? [] as $e) {
        $add($e['url'], "library_notes.php {$n['package']}", $e['quote'] ?? null);
    }
}
foreach (glob("$root/references/*.md") as $f) {
    preg_match_all('#\]\((https?://[^)\s]+)\)#', (string) file_get_contents($f), $m);
    foreach ($m[1] as $u) {
        $add($u, 'references/' . basename($f));
    }
}
foreach (glob("$root/examples/source/*.json") as $f) {
    $walk = function ($v) use (&$walk, $add, $f): void {
        if (is_array($v)) {
            if (isset($v['url']) && is_string($v['url'])) {
                $add($v['url'], 'examples/source/' . basename($f), is_string($v['quote'] ?? null) ? $v['quote'] : null);
            }
            array_map($walk, $v);
        }
    };
    $walk(json_decode((string) file_get_contents($f), true));
}
ksort($links);

$flat = fn (string $s): string => trim((string) preg_replace('/\s+/', ' ', $s));
$broken = [];
$checked = 0;
foreach ($links as $url => $info) {
    $host = (string) parse_url($url, PHP_URL_HOST);
    if (in_array($host, $skip, true)) {
        continue;
    }
    $checked++;
    // Quotes are checked in the raw file of a GitHub blob link; other pages as text.
    $fetch = preg_match('#^https://github\.com/([^/]+/[^/]+)/blob/(.+)$#', $url, $m) && $info['quotes'] !== []
        ? "https://raw.githubusercontent.com/{$m[1]}/{$m[2]}" : $url;
    [$code, $body] = httpGet($fetch);
    if ($code === 429 || $code >= 500 || $code === 0) {
        sleep(5);
        [$code, $body] = httpGet($fetch);
    }
    $problem = null;
    if ($code < 200 || $code >= 400) {
        $problem = "HTTP $code";
    } else {
        // A raw file is compared as it is; a web page without its markup ("<" in plain text is not a tag).
        $text = $flat(str_starts_with($fetch, 'https://raw.githubusercontent.com/') ? $body : html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5));
        foreach ($info['quotes'] as $q => $from) {
            if (!str_contains($text, $flat((string) $q))) {
                $broken[] = ['url' => $url, 'problem' => 'quote not found: "' . substr((string) $q, 0, 120) . '"', 'cited_in' => array_keys($from)];
            }
        }
    }
    if ($problem !== null) {
        $broken[] = ['url' => $url, 'problem' => $problem, 'cited_in' => array_keys($info['from'])];
    }
}
foreach ($broken as $b) {
    echo "BROKEN {$b['url']}: {$b['problem']}\n  cited in: " . implode(', ', array_slice($b['cited_in'], 0, 5)) . (count($b['cited_in']) > 5 ? ', ...' : '') . "\n";
}
echo "links: $checked checked, " . count($broken) . " broken" . ($skip ? ' (skipped hosts: ' . implode(', ', $skip) . ')' : '') . "\n";
if (($json = opt($argv, 'json')) !== null) {
    file_put_contents($json, json_encode(['checked' => $checked, 'broken' => $broken], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}
exit($broken === [] ? 0 : EXIT_FINDINGS);

/** [status, body]; follows redirects. */
function httpGet(string $url): array
{
    $ctx = stream_context_create(['http' => ['timeout' => 25, 'ignore_errors' => true, 'follow_location' => 1, 'max_redirects' => 5,
        'user_agent' => 'php-upgrade-audit link check (+https://github.com/zulanggara/php-upgrade-audit)']]);
    $fp = @fopen($url, 'r', false, $ctx);
    if ($fp === false) {
        return [0, ''];
    }
    $code = 0;
    foreach (stream_get_meta_data($fp)['wrapper_data'] ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $h, $m)) {
            $code = (int) $m[1]; // the last status line is the final one after redirects
        }
    }
    $body = (string) stream_get_contents($fp);
    fclose($fp);
    return [$code, $body];
}
