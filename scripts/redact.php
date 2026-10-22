<?php
/**
 * Redaction of secrets in any text that ends up in scan output or reports
 * (code snippets, lint messages, config hints, diffs, analyst notes, suggested fixes).
 *
 * Applied twice: when each tool writes its JSON, and again on the whole report before rendering.
 * Conservative on purpose: a false positive only hides a value in the report, a false negative leaks it.
 */
declare(strict_types=1);

const REDACTED = '«REDACTED»';

/** Key names whose values are treated as secrets. */
const SECRET_KEY_RE = '(?:pass(?:word|wd|phrase)?|pwd|secret|token|api[_-]?key|apikey|access[_-]?key|private[_-]?key|client[_-]?secret|auth[_-]?key|encryption[_-]?key|signing[_-]?key|salt|credential|dsn)';

function redact_text(string $s, int &$count = 0): string
{
    if ($s === '') {
        return $s;
    }
    $before = $s;
    $n = 0;
    $rules = [
        // Private key blocks (complete or cut off in a snippet)
        '/-----BEGIN ([A-Z ]*)PRIVATE KEY-----.*?(?:-----END [A-Z ]*PRIVATE KEY-----|$)/s' => '-----BEGIN $1PRIVATE KEY----- ' . REDACTED . ' -----END PRIVATE KEY-----',
        // Well-known token formats (prefix kept so the reader knows what it was)
        '/\b(gh[pousr])_(?!•)[A-Za-z0-9]{20,}/' => '$1_' . REDACTED,
        '/\bgithub_pat_[A-Za-z0-9_]{20,}/' => 'github_pat_' . REDACTED,
        '/\bglpat-[A-Za-z0-9_-]{16,}/' => 'glpat-' . REDACTED,
        '/\bxox[abprs]-[A-Za-z0-9-]{10,}/' => 'xox-' . REDACTED,
        '/\b(AKIA|ASIA)[0-9A-Z]{16}\b/' => '$1' . REDACTED,
        '/\bAIza[0-9A-Za-z_-]{35}\b/' => 'AIza' . REDACTED,
        '/\b([sprk]k)_(live|test)_[0-9A-Za-z]{10,}/' => '$1_$2_' . REDACTED,
        '/\bSG\.[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{16,}/' => 'SG.' . REDACTED,
        '/\beyJ[A-Za-z0-9_-]{8,}\.eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/' => 'eyJ' . REDACTED,
        // Credentials inside URLs: scheme://user:pass@host or scheme://token@host
        // (skipped when the value is already a placeholder such as ghp_•••••• or «REDACTED»)
        '#\b([a-z][a-z0-9+.-]*://)(?![^/\s@]*(?:•|«))[^/\s:@\'"<>]+(?::[^@\s/\'"<>]*)?@#i' => '$1' . REDACTED . '@',
        // Authorization headers
        '/(Authorization\s*["\']?\s*[:=]\s*["\']?\s*(?:Bearer|Basic|Token)\s+)[A-Za-z0-9._~+\/=-]{8,}/i' => '$1' . REDACTED,
        // 'password' => 'value'   /   "token": "value"
        '/([\'"][A-Za-z0-9_.-]*' . SECRET_KEY_RE . '[A-Za-z0-9_.-]*[\'"]\s*(?:=>|:)\s*)([\'"])(?!\2)(?!' . REDACTED . ')((?:\\\\.|(?!\2).)+)\2/i' => '$1$2' . REDACTED . '$2',
        // $password = 'value'   /   $config['encryption_key'] = 'value'
        '/(\$[A-Za-z0-9_]*' . SECRET_KEY_RE . '[A-Za-z0-9_]*(?:\[[^\]]*\])*\s*\.?=\s*)([\'"])(?!\2)(?!' . REDACTED . ')((?:\\\\.|(?!\2).)+)\2/i' => '$1$2' . REDACTED . '$2',
        '/(\$[A-Za-z0-9_]+\[\s*[\'"][A-Za-z0-9_.-]*' . SECRET_KEY_RE . '[A-Za-z0-9_.-]*[\'"]\s*\]\s*=\s*)([\'"])(?!\2)(?!' . REDACTED . ')((?:\\\\.|(?!\2).)+)\2/i' => '$1$2' . REDACTED . '$2',
        // $password = $_SERVER['X'] ?? 'fallback'  (fallback literal after ?? or ?:)
        '/(\$[A-Za-z0-9_]*' . SECRET_KEY_RE . '[A-Za-z0-9_]*\s*=[^;\n]*?(?:\?\?|\?:)\s*)([\'"])(?!\2)(?!' . REDACTED . ')((?:\\\\.|(?!\2).)+)\2/i' => '$1$2' . REDACTED . '$2',
        // define('API_SECRET', 'value')
        '/(define\(\s*[\'"][A-Za-z0-9_]*' . SECRET_KEY_RE . '[A-Za-z0-9_]*[\'"]\s*,\s*)([\'"])(?!\2)(?!' . REDACTED . ')((?:\\\\.|(?!\2).)+)\2/i' => '$1$2' . REDACTED . '$2',
        // ENV / ini / yaml: DB_PASSWORD=value, api_key: value
        '/^(\s*(?:export\s+)?[A-Za-z0-9_.-]*' . SECRET_KEY_RE . '[A-Za-z0-9_.-]*\s*(?:=(?!=)|:(?=[ \t]))\s*)(?![\'"]?\s*$)(?!\$\{)(?!' . REDACTED . ')(["\']?)[^\s"\'#]+\2(?=[ \t]*(?:#.*)?$)/im' => '$1$2' . REDACTED . '$2',
        // Long random-looking literals (>= 32 chars, letters and digits mixed)
        '/([\'"])(?=[A-Za-z0-9+\/=_-]{32,}\1)(?=[^\'"]*[0-9])(?=[^\'"]*[A-Za-z])[A-Za-z0-9+\/=_-]{32,}\1/' => '$1' . REDACTED . '$1',
    ];
    foreach ($rules as $re => $rep) {
        $s = preg_replace($re, $rep, $s, -1, $c) ?? $s;
        $n += $c;
    }
    if ($s !== $before) {
        $count += max(1, $n);
    }
    return $s;
}

/** Redact every string value in a nested array (keys are left as they are). */
function redact_data(mixed $data, int &$count = 0): mixed
{
    if (is_string($data)) {
        return redact_text($data, $count);
    }
    if (is_array($data)) {
        foreach ($data as $k => $v) {
            $data[$k] = redact_data($v, $count);
        }
    }
    return $data;
}
