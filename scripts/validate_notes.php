<?php
/**
 * Check analyst notes (notes.json) and the overview (overview.json) against the format in AGENTS.md.
 * Used on its own (CLI) and by render.php, which prints the problems as warnings.
 *
 * Run with --help for the options.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/history.php';

const NOTE_SEV = ['wajib', 'deprecated', 'perilaku', 'info'];
const STATUS_LEVELS = ['wajib', 'deprecated', 'perilaku', 'ok'];
/** Kinds of evidence a note can cite (see references/evidence.md). */
const EVIDENCE_KINDS = ['php.net', 'rfc', 'php-src', 'changelog', 'release', 'upgrade', 'source', 'issue', 'cve', 'advisory', 'docs', 'run'];

/**
 * Problems in one notes.json (kind "notes") or overview.json (kind "overview").
 * Each problem: ['level' => 'error'|'warning', 'where' => 'notes[2].code[0].after', 'message' => '...'].
 * $repos: names of the repos in the store, to check old_report / new_report.
 */
function validate_document(mixed $doc, string $kind, array $repos = []): array
{
    $p = [];
    $err = function (string $where, string $msg) use (&$p) { $p[] = ['level' => 'error', 'where' => $where, 'message' => $msg]; };
    $warn = function (string $where, string $msg) use (&$p) { $p[] = ['level' => 'warning', 'where' => $where, 'message' => $msg]; };

    if (!is_array($doc) || array_is_list($doc) && $doc !== []) {
        $err('(file)', 'must be a JSON object');
        return $p;
    }
    $known = $kind === 'overview' ? ['goal', 'summary', 'steps', 'notes'] : ['status', 'summary', 'notes', 'old_report', 'new_report', 'carried_from'];
    foreach (array_diff(array_keys($doc), $known) as $k) {
        $warn((string) $k, 'unknown key (typo?); known keys: ' . implode(', ', $known));
    }
    if (isset($doc['summary']) && !is_string($doc['summary'])) {
        $err('summary', 'must be a string');
    } elseif (trim((string) ($doc['summary'] ?? '')) === '') {
        $warn('summary', 'missing: write 2-4 sentences (ready or not, main blockers, size of the work)');
    }

    if ($kind === 'notes') {
        if (array_key_exists('carried_from', $doc)) {
            $err('carried_from', "notes were copied from scan {$doc['carried_from']} and not reviewed yet: check them against this scan, then remove carried_from");
        }
        if (isset($doc['status'])) {
            if (!is_array($doc['status'])) {
                $err('status', 'must be an object {"level", "label"}');
            } else {
                if (!in_array($doc['status']['level'] ?? null, STATUS_LEVELS, true)) {
                    $err('status.level', 'must be one of ' . implode(', ', STATUS_LEVELS) . ' (keys stay as written in every language)');
                }
                if (trim((string) ($doc['status']['label'] ?? '')) === '') {
                    $warn('status.label', 'missing: a short status shown in the report, e.g. "Ready after 3 fixes"');
                }
            }
        }
        foreach (['old_report', 'new_report'] as $k) {
            if (isset($doc[$k]) && $repos !== [] && !in_array($doc[$k], $repos, true)) {
                $warn($k, "\"{$doc[$k]}\" is not a repo in this store (" . implode(', ', $repos) . ')');
            }
        }
    } else {
        $goal = $doc['goal'] ?? null;
        if ($goal !== null) {
            if (!is_array($goal)) {
                $err('goal', 'must be an object {"title", "text", "points", "scope"}');
            } else {
                foreach (['points' => 'strings', 'scope' => '{"k", "v"} objects'] as $k => $what) {
                    if (isset($goal[$k]) && !is_array($goal[$k])) {
                        $err("goal.$k", "must be a list of $what");
                    }
                }
                foreach ($goal['scope'] ?? [] as $i => $s) {
                    if (!is_array($s) || !isset($s['k'], $s['v'])) {
                        $err("goal.scope[$i]", 'must be {"k": "...", "v": "..."}');
                    }
                }
            }
        }
        if (isset($doc['steps']) && (!is_array($doc['steps']) || array_filter($doc['steps'], fn ($s) => !is_string($s)) !== [])) {
            $err('steps', 'must be a list of strings');
        }
    }

    $notes = $doc['notes'] ?? [];
    if (!is_array($notes) || !array_is_list($notes)) {
        $err('notes', 'must be a list');
        $notes = [];
    }
    foreach ($notes as $i => $n) {
        $w = "notes[$i]";
        if (!is_array($n)) {
            $err($w, 'must be an object');
            continue;
        }
        $title = is_string($n['title'] ?? null) && trim($n['title']) !== '' ? ' "' . mb_strimwidth($n['title'], 0, 40, '...') . '"' : '';
        if (!in_array($n['sev'] ?? null, NOTE_SEV, true)) {
            $err("$w.sev", 'must be one of ' . implode(', ', NOTE_SEV) . $title);
        }
        foreach (['title', 'body'] as $k) {
            if (!is_string($n[$k] ?? null) || trim($n[$k]) === '') {
                $err("$w.$k", "missing or empty$title");
            }
        }
        foreach (array_diff(array_keys($n), ['sev', 'title', 'body', 'files', 'code', 'evidence']) as $k) {
            $warn("$w.$k", 'unknown key (typo?)');
        }
        if (isset($n['files']) && (!is_array($n['files']) || array_filter($n['files'], fn ($f) => !is_string($f)) !== [])) {
            $err("$w.files", 'must be a list of "path/file.php:line" strings');
        }
        $code = $n['code'] ?? [];
        if (!is_array($code) || !array_is_list($code)) {
            $err("$w.code", 'must be a list of {"where", "before", "after", "note"}');
            $code = [];
        }
        if ($code === [] && in_array($n['sev'] ?? null, ['wajib', 'deprecated'], true)) {
            $warn("$w.code", "no Current/Suggested code pair$title; add one if the note needs a code change");
        }
        foreach ($code as $j => $c) {
            if (!is_array($c)) {
                $err("$w.code[$j]", 'must be an object');
                continue;
            }
            foreach (['before', 'after'] as $k) {
                if (!is_string($c[$k] ?? null) || trim($c[$k]) === '') {
                    $err("$w.code[$j].$k", "missing or empty: every pair needs both the current and the suggested code$title");
                }
            }
            if (is_string($c['before'] ?? null) && ($c['before'] === ($c['after'] ?? null))) {
                $warn("$w.code[$j]", 'before and after are identical');
            }
            foreach (array_diff(array_keys($c), ['where', 'before', 'after', 'note', 'proof']) as $k) {
                $warn("$w.code[$j].$k", 'unknown key (typo?)');
            }
            // proof: what the agent ran on the target PHP to check the suggested code.
            if (isset($c['proof'])) {
                $pr = $c['proof'];
                if (!is_array($pr) || !is_string($pr['php'] ?? null) || !preg_match('/^\d+\.\d+(\.\d+)?$/', $pr['php'])) {
                    $err("$w.code[$j].proof", 'must be {"php": "8.5.12", "run": "command or snippet", "output": "what it printed"}');
                } elseif (trim((string) ($pr['output'] ?? '')) === '') {
                    $warn("$w.code[$j].proof.output", 'empty: paste what the run printed, so readers can compare');
                }
            }
        }
        // evidence: where a reader can check the claim (php.net, RFC, changelog, release notes, source).
        $evidence = $n['evidence'] ?? [];
        if (!is_array($evidence) || !array_is_list($evidence)) {
            $err("$w.evidence", 'must be a list of {"title", "url", "kind", "quote"}');
            $evidence = [];
        }
        if ($evidence === [] && in_array($n['sev'] ?? null, ['wajib', 'deprecated', 'perilaku'], true)) {
            $warn("$w.evidence", "no evidence$title; link the source a reader can check (php.net, RFC, changelog, release notes, source code)");
        }
        foreach ($evidence as $j => $e) {
            if (!is_array($e)) {
                $err("$w.evidence[$j]", 'must be an object {"title", "url", "kind", "quote"}');
                continue;
            }
            if (!is_string($e['url'] ?? null) || !preg_match('#^https?://[^\s]+$#i', $e['url'])) {
                $err("$w.evidence[$j].url", 'missing or not an http(s) URL (other schemes are never linked in the report)');
            } elseif (str_starts_with(strtolower($e['url']), 'http://')) {
                $warn("$w.evidence[$j].url", 'use https:// where the source offers it');
            }
            if (trim((string) ($e['title'] ?? '')) === '') {
                $warn("$w.evidence[$j].title", 'missing: say what the source is, e.g. "PHP 8.4 UPGRADING: bcrypt cost"');
            }
            if (isset($e['kind']) && !in_array($e['kind'], EVIDENCE_KINDS, true)) {
                $warn("$w.evidence[$j].kind", 'unknown kind; known: ' . implode(', ', EVIDENCE_KINDS));
            }
            foreach (array_diff(array_keys($e), ['title', 'url', 'kind', 'quote']) as $k) {
                $warn("$w.evidence[$j].$k", 'unknown key (typo?)');
            }
        }
    }

    // Secrets anywhere in the document (they are redacted in the report, but should not be written at all).
    $walk = function (mixed $v, string $path) use (&$walk, $err): void {
        if (is_array($v)) {
            foreach ($v as $k => $x) {
                $walk($x, $path === '' ? (string) $k : (is_int($k) ? "{$path}[$k]" : "$path.$k"));
            }
        } elseif (is_string($v)) {
            $n = 0;
            redact_text($v, $n);
            if ($n > 0) {
                $err($path, 'contains what looks like a secret (token, password, key); mask it, e.g. ghp_•••••• or «REDACTED»');
            }
        }
    };
    $walk($doc, '');
    return $p;
}

/** Validate every notes.json and overview.json in a store: [relative file => problems]. */
function validate_store(string $store): array
{
    $store = rtrim($store, '/');
    $out = [];
    $repos = [];
    foreach (history_entities($store) as $e) {
        if (($e['index']['kind'] ?? 'repo') === 'repo') {
            $repos[] = $e['index']['name'];
        }
    }
    $check = function (string $file, string $kind) use (&$out, $store, $repos) {
        $rel = substr($file, strlen($store) + 1);
        $doc = json_decode((string) file_get_contents($file), true);
        $out[$rel] = $doc === null && json_last_error() !== JSON_ERROR_NONE
            ? [['level' => 'error', 'where' => '(file)', 'message' => 'invalid JSON: ' . json_last_error_msg()]]
            : validate_document($doc, $kind, $repos);
    };
    if (is_file("$store/overview.json")) {
        $check("$store/overview.json", 'overview');
    }
    foreach (history_entities($store) as $e) {
        $scans = $e['index']['scans'] ?? [];
        foreach ($scans as $i => $scan) {
            $file = "{$e['dir']}/scans/{$scan['id']}/notes.json";
            if (is_file($file)) {
                $check($file, 'notes');
                if ($i < count($scans) - 1) {
                    // Older scans keep their notes as they were; only the latest needs to be clean.
                    $out[substr($file, strlen($store) + 1)] = array_map(fn ($x) => ['level' => 'warning'] + $x, $out[substr($file, strlen($store) + 1)]);
                }
            }
        }
    }
    return $out;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    ini_set('display_errors', 'stderr');
    handleHelp($argv, <<<'HELP'
    Check analyst notes and the overview against the format in AGENTS.md.

    Usage:
      php scripts/validate_notes.php --store=<store> [--json]     every notes.json + overview.json in the store
      php scripts/validate_notes.php <file.json>... [--json]      single files (overview.json is detected by name)

    Checks: valid JSON, known keys, sev/status level values, title and body present, every code pair has both
    "before" and "after", no secrets, and no "carried_from" left on the latest scan (notes copied from the
    previous scan that were not reviewed yet). Problems in older scans are only warnings.

    Exit codes: 0 no errors (warnings allowed), 1 wrong usage, 10 errors found.
    HELP);
    $store = opt($argv, 'store');
    $files = array_values(array_filter(array_slice($argv, 1), fn ($a) => !str_starts_with($a, '--')));
    if (($store === null || $store === '1') && $files === []) {
        usageError('give --store=<store> or one or more JSON files.', 'scripts/validate_notes.php');
    }
    if ($store !== null && !is_dir($store)) {
        usageError("store folder not found: $store", 'scripts/validate_notes.php');
    }
    $result = [];
    if ($store !== null) {
        $result = validate_store(realpath($store));
    }
    foreach ($files as $f) {
        if (!is_file($f)) {
            usageError("file not found: $f", 'scripts/validate_notes.php');
        }
        $doc = json_decode((string) file_get_contents($f), true);
        $result[$f] = $doc === null && json_last_error() !== JSON_ERROR_NONE
            ? [['level' => 'error', 'where' => '(file)', 'message' => 'invalid JSON: ' . json_last_error_msg()]]
            : validate_document($doc, basename($f) === 'overview.json' ? 'overview' : 'notes');
    }
    $errors = 0;
    foreach ($result as $problems) {
        $errors += count(array_filter($problems, fn ($x) => $x['level'] === 'error'));
    }
    if (opt($argv, 'json') !== null) {
        echo json_encode(['files' => $result, 'errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    } else {
        foreach ($result as $file => $problems) {
            echo $problems === [] ? "ok       $file\n" : "$file\n";
            foreach ($problems as $x) {
                printf("  %-8s %s: %s\n", $x['level'], $x['where'], $x['message']);
            }
        }
        echo $errors === 0 ? "No errors.\n" : "$errors error(s).\n";
    }
    exit($errors === 0 ? 0 : EXIT_FINDINGS);
}
