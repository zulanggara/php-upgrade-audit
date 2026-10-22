<?php
/**
 * Tokenizer-based PHP 7.x -> 8.5 compatibility scanner. Read-only: it only reads files, never runs them.
 *
 * Usage: php scan_code.php <repo> [--target=8.5.12] [--exclude=dir1,dir2] > scan.json
 */
declare(strict_types=1);

ini_set('display_errors', 'stderr');
require __DIR__ . '/lib.php';

const MAX_FILE_BYTES = 2_000_000;

$repo = $argv[1] ?? '';
if ($repo === '' || !is_dir($repo)) {
    fwrite(STDERR, "Usage: php scan_code.php <repo> [--exclude=dir1,dir2]\n");
    exit(2);
}
$repo = rtrim(realpath($repo), '/');
$excludes = excludesFrom($argv);

$target = targetFrom($argv);
// Only changes that exist in the target version: a rule added in 8.5 says nothing about a move to 8.3.
$allRules = require __DIR__ . '/rules.php';
$rules = array_values(array_filter($allRules, fn ($r) => appliesTo($r['ver'], $target)));
$scanner = new Scanner($rules);
Scanner::$target = $target;
[$maj, $min, $pat] = array_map('intval', array_pad(explode('.', Scanner::$target), 3, 0));
Scanner::$targetId = $maj * 10000 + $min * 100 + $pat;
$files = listPhpFiles($repo, $excludes);
$skipped = [];
foreach ($files as $path) {
    $rel = substr($path, strlen($repo) + 1);
    if (filesize($path) > MAX_FILE_BYTES) {
        $skipped[] = ['file' => $rel, 'reason' => 'lebih dari 2 MB'];
        continue;
    }
    $scanner->scanFile($path, $rel);
}

$meta = [];
foreach ($rules as $r) {
    $meta[$r['id']] = array_intersect_key($r, array_flip(['sev', 'ver', 'conf', 'title', 'fix', 'ref', 'rector']));
}
printJson([
    'tool' => 'scan_code',
    'repo' => $repo,
    'php_runtime' => PHP_VERSION,
    'files_scanned' => count($files) - count($skipped),
    'files_skipped' => $skipped,
    'excludes' => $excludes,
    'target' => $target,
    'rules_above_target' => count($allRules) - count($rules),
    'findings' => $scanner->findings,
    'rules' => $meta,
]);

final class Scanner
{
    public array $findings = [];
    private array $byKind = [];
    private array $special = [];
    private array $seen = [];
    private array $sig = [];
    private array $all = [];
    private array $lines = [];
    private string $file = '';
    /** Index (in $sig) of the token being reported, for the code excerpt. */
    private int $at = 0;

    private const SUBSTR_FUNCS = ['substr', 'mb_substr', 'iconv_substr', 'grapheme_substr'];
    private const INI_FUNCS = ['ini_set', 'ini_get', 'ini_restore', 'ini_alter'];
    /** Built-in interface/class => methods that need a return type since 8.1. */
    private const INTERNAL_METHODS = [
        'arrayaccess' => ['offsetexists', 'offsetget', 'offsetset', 'offsetunset'],
        'iterator' => ['current', 'key', 'next', 'rewind', 'valid'],
        'iteratoraggregate' => ['getiterator'],
        'countable' => ['count'],
        'jsonserializable' => ['jsonserialize'],
        'seekableiterator' => ['seek', 'current', 'key', 'next', 'rewind', 'valid'],
        'recursiveiterator' => ['haschildren', 'getchildren', 'current', 'key', 'next', 'rewind', 'valid'],
        'sessionhandlerinterface' => ['open', 'close', 'read', 'write', 'destroy', 'gc'],
        'sessionhandler' => ['open', 'close', 'read', 'write', 'destroy', 'gc'],
        'php_user_filter' => ['filter', 'oncreate', 'onclose'],
        'arrayobject' => ['offsetexists', 'offsetget', 'offsetset', 'offsetunset', 'count', 'getiterator'],
        'arrayiterator' => ['offsetexists', 'offsetget', 'offsetset', 'offsetunset', 'count', 'current', 'key', 'next', 'rewind', 'valid'],
        'filteriterator' => ['accept', 'current', 'key', 'next', 'rewind', 'valid'],
        'iteratoriterator' => ['current', 'key', 'next', 'rewind', 'valid'],
    ];

    public function __construct(array $rules)
    {
        foreach ($rules as $r) {
            if ($r['kind'] === 'special') {
                $this->special[$r['check']] = $r['id'];
            } else {
                $this->byKind[$r['kind']][] = $r;
            }
        }
    }

    public function scanFile(string $path, string $rel): void
    {
        $code = (string) file_get_contents($path);
        $this->file = $rel;
        $this->lines = preg_split('/\R/', $code) ?: [];
        $this->tokenize($code);
        $this->scanTokens();
        $this->scanClasses();
        $this->scanSignatures();
        $this->scanExpressions();
    }

    private function tokenize(string $code): void
    {
        $this->all = [];
        $line = 1;
        foreach (token_get_all($code) as $t) {
            if (is_array($t)) {
                $this->all[] = [$t[0], $t[1], $t[2]];
                $line = $t[2] + substr_count($t[1], "\n");
            } else {
                $this->all[] = [$t, $t, $line];
            }
        }
        $this->sig = [];
        foreach ($this->all as $k => $t) {
            if (!in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $this->sig[] = [$t[0], $t[1], $t[2], $k];
            }
        }
    }

    private function add(string $rule, int $line): void
    {
        $code = trim($this->lines[$line - 1] ?? '');
        $long = strlen($code) > 200 && isset($this->sig[$this->at]);
        $key = $rule . '|' . $this->file . '|' . $line . ($long ? '|' . $this->at : '');
        if (isset($this->seen[$key])) {
            return;
        }
        $this->seen[$key] = true;
        if ($long) {
            // Long line (e.g. a minified file): take an excerpt of the tokens around the finding.
            $raw = $this->sig[$this->at][3];
            $win = array_slice($this->all, max(0, $raw - 14), 29);
            $code = '... ' . trim(preg_replace('/\s+/', ' ', implode('', array_column($win, 1)))) . ' ...';
        }
        if (strlen($code) > 220) {
            $code = substr($code, 0, 217) . '...';
        }
        $finding = ['rule' => $rule, 'file' => $this->file, 'line' => $line, 'code' => $code];
        $guard = $this->guardAt($this->at);
        if ($guard !== null) {
            $state = $this->evalGuard($guard);
            $finding['guard'] = ($guard['branch'] === 'else' ? 'else dari: ' : '') . $guard['cond'];
            $finding['guard_state'] = $state; // mati | jalan | ?
            if ($state === 'mati') {
                $finding['guarded'] = true; // this branch does not run on the target version
            }
        }
        $this->findings[] = $finding;
    }

    private const GUARD_RE = '/\b(is_php|function_exists|extension_loaded|defined|class_exists|interface_exists|method_exists|version_compare)\s*\(|PHP_VERSION|PHP_MAJOR_VERSION|PHP_MINOR_VERSION/i';

    /**
     * Version/extension check guarding this token, or null.
     * Checked: the same statement, an if without braces, and enclosing if/elseif/else blocks (max 3 levels,
     * stopping at function/class boundaries so "if (!function_exists('x')) { function x() {} }" does not count).
     */
    private function guardAt(int $at): ?array
    {
        if (!isset($this->sig[$at])) {
            return null;
        }
        // Same statement (e.g. "! is_php('8.0') && ini_get(...)") and an if without braces.
        $paren = 0;
        for ($k = $at - 1; $k >= 0; $k--) {
            $id = $this->id($k);
            if ($id === ')') {
                $open = $this->matchBack($k);
                if ($open !== null && $paren === 0 && in_array($this->id($open - 1), [T_IF, T_ELSEIF], true)) {
                    $cond = $this->textRange($open + 1, $k - 1);
                    if (preg_match(self::GUARD_RE, $cond)) {
                        return ['cond' => $cond, 'branch' => 'if'];
                    }
                    break; // an if without braces that is not a guard; continue with enclosing blocks
                }
                $paren++;
            } elseif ($id === '(') {
                $paren--;
            } elseif (in_array($id, [';', '{', '}', T_OPEN_TAG], true) || ($id === T_ELSE && $paren === 0)) {
                if ($id === T_ELSE) {
                    $c = $this->conditionBefore($k);
                    if ($c !== null && preg_match(self::GUARD_RE, $c['cond'])) {
                        return $c;
                    }
                }
                break;
            }
            if ($paren === 0 && is_int($id) && preg_match(self::GUARD_RE, $this->text($k) . '(')) {
                // guard call in the same statement
                if (in_array($this->id($k + 1), ['('], true) || str_starts_with(strtoupper($this->text($k)), 'PHP_')) {
                    return ['cond' => trim($this->textRange($k, min($at - 1, $k + 8))), 'branch' => 'stmt'];
                }
            }
        }
        // Blok pembungkus
        $depth = 0;
        $levels = 0;
        for ($k = $at - 1; $k >= 0 && $levels < 3; $k--) {
            $id = $this->id($k);
            if ($id === '}') {
                $depth++;
            } elseif ($id === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                if ($depth > 0) {
                    $depth--;
                    continue;
                }
                if ($id !== '{') {
                    continue;
                }
                if ($this->isFunctionOrClassOpener($k)) {
                    return null;
                }
                $levels++;
                $c = $this->conditionBefore($k);
                if ($c !== null && preg_match(self::GUARD_RE, $c['cond'])) {
                    return $c;
                }
            }
        }
        return null;
    }

    /** Condition of the if/elseif opening the block at $brace; for else, the condition of its if (branch=else). */
    private function conditionBefore(int $brace): ?array
    {
        $prev = $this->id($brace - 1);
        if ($prev === ')') {
            $open = $this->matchBack($brace - 1);
            if ($open !== null && in_array($this->id($open - 1), [T_IF, T_ELSEIF], true)) {
                return ['cond' => $this->textRange($open + 1, $brace - 2), 'branch' => 'if'];
            }
            return null;
        }
        if ($prev === T_ELSE || $this->id($brace) === T_ELSE) {
            $e = $prev === T_ELSE ? $brace - 1 : $brace;
            if ($this->id($e - 1) === '}') {
                $openBrace = $this->matchBack($e - 1);
                if ($openBrace !== null) {
                    $c = $this->conditionBefore($openBrace);
                    return $c === null ? null : ['cond' => $c['cond'], 'branch' => 'else'];
                }
            }
        }
        return null;
    }

    private function isFunctionOrClassOpener(int $brace): bool
    {
        for ($k = $brace - 1; $k >= 0 && $k >= $brace - 12; $k--) {
            $id = $this->id($k);
            if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                return true;
            }
            if ($id === ')') {
                $open = $this->matchBack($k);
                if ($open === null) {
                    return false;
                }
                $before = $this->id($open - 1);
                $before2 = $this->id($open - 2);
                return in_array($before, [T_FUNCTION, T_FN, T_USE], true)
                    || ($before2 === T_FUNCTION || ($before2 === '&' && $this->id($open - 3) === T_FUNCTION));
            }
            if (in_array($id, [';', '{', '}'], true)) {
                return false;
            }
        }
        return false;
    }

    /**
     * Evaluate a guard condition against the target version. Only safe tokens (version constants, existence
     * checks, literals, operators) are accepted; anything else yields '?'.
     * *_exists/defined/extension_loaded are evaluated with the PHP running the scanner (the local target).
     */
    private function evalGuard(array $guard): string
    {
        if ($guard['branch'] === 'stmt') {
            return '?';
        }
        $allowedFuncs = ['is_php', 'version_compare', 'function_exists', 'class_exists', 'interface_exists', 'defined', 'extension_loaded'];
        $consts = ['PHP_VERSION_ID' => (string) self::$targetId, 'PHP_VERSION' => var_export(self::$target, true),
            'PHP_MAJOR_VERSION' => (string) intdiv(self::$targetId, 10000), 'PHP_MINOR_VERSION' => (string) (intdiv(self::$targetId, 100) % 100),
            'TRUE' => 'true', 'FALSE' => 'false', 'NULL' => 'null'];
        $expr = '';
        foreach (token_get_all('<?php ' . $guard['cond'] . ';') as $t) {
            [$id, $text] = is_array($t) ? [$t[0], $t[1]] : [$t, $t];
            if ($id === T_OPEN_TAG || $id === T_WHITESPACE || $text === ';') {
                continue;
            }
            if ($id === T_STRING) {
                $u = strtoupper($text);
                if (isset($consts[$u])) {
                    $expr .= $consts[$u];
                } elseif (in_array(strtolower($text), $allowedFuncs, true)) {
                    $expr .= strtolower($text) === 'is_php' ? '\\Scanner::isPhp' : '\\' . strtolower($text);
                } else {
                    return '?';
                }
            } elseif (in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER, T_IS_EQUAL, T_IS_NOT_EQUAL, T_IS_IDENTICAL, T_IS_NOT_IDENTICAL,
                T_IS_SMALLER_OR_EQUAL, T_IS_GREATER_OR_EQUAL, T_BOOLEAN_AND, T_BOOLEAN_OR, T_LOGICAL_AND, T_LOGICAL_OR], true)
                || in_array($text, ['(', ')', ',', '!', '<', '>'], true)) {
                $expr .= ' ' . $text . ' ';
            } else {
                return '?';
            }
        }
        try {
            $value = (bool) eval('return (' . $expr . ');');
        } catch (\Throwable) {
            return '?';
        }
        $runs = $guard['branch'] === 'else' ? !$value : $value;
        return $runs ? 'jalan' : 'mati';
    }

    public static string $target = '8.5.12';
    public static int $targetId = 80512;

    public static function isPhp(string $version): bool
    {
        return version_compare(self::$target, $version, '>=');
    }

    /** Index of the opener matching the closer ) ] } at $close. */
    private function matchBack(int $close): ?int
    {
        $depth = 0;
        for ($k = $close; $k >= 0; $k--) {
            $id = $this->id($k);
            if (self::isClose($id)) {
                $depth++;
            } elseif (self::isOpen($id)) {
                if (--$depth === 0) {
                    return $k;
                }
            }
        }
        return null;
    }

    private function textRange(int $a, int $b): string
    {
        $out = '';
        for ($k = $a; $k <= $b; $k++) {
            $out .= ($out === '' ? '' : ' ') . $this->text($k);
        }
        return preg_replace('/\s+/', ' ', $out);
    }

    private function special(string $check, int $line): void
    {
        if (isset($this->special[$check])) {
            $this->add($this->special[$check], $line);
        }
    }

    private function id(int $i): int|string|null
    {
        return $this->sig[$i][0] ?? null;
    }

    private function text(int $i): string
    {
        return $this->sig[$i][1] ?? '';
    }

    /** Lowercase global function name (no namespace), or null. */
    private function bareName(int $i): ?string
    {
        $id = $this->id($i);
        if ($id === T_STRING) {
            return strtolower($this->text($i));
        }
        if ($id === T_NAME_FULLY_QUALIFIED) {
            $n = ltrim($this->text($i), '\\');
            return str_contains($n, '\\') ? null : strtolower($n);
        }
        return null;
    }

    private static function lastSegment(string $name): string
    {
        $p = strrpos($name, '\\');
        return strtolower($p === false ? $name : substr($name, $p + 1));
    }

    private static function isOpen(int|string|null $id): bool
    {
        return $id === '(' || $id === '[' || $id === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES || $id === T_ATTRIBUTE;
    }

    private static function isClose(int|string|null $id): bool
    {
        return $id === ')' || $id === ']' || $id === '}';
    }

    /** @return array{0: list<array{0:int,1:int}>, 1: int, 2: bool} [rentang argumen, index penutup, ada spread] */
    private function parseArgs(int $open): array
    {
        $depth = 0;
        $args = [];
        $start = $open + 1;
        $spread = false;
        $n = count($this->sig);
        for ($j = $open; $j < $n; $j++) {
            $id = $this->sig[$j][0];
            if (self::isOpen($id)) {
                $depth++;
            } elseif (self::isClose($id)) {
                $depth--;
                if ($depth === 0) {
                    if ($j > $start) {
                        $args[] = [$start, $j - 1];
                    }
                    return [$args, $j, $spread];
                }
            } elseif ($depth === 1 && $id === ',') {
                $args[] = [$start, $j - 1];
                $start = $j + 1;
            } elseif ($depth === 1 && $id === T_ELLIPSIS) {
                $spread = true;
            }
        }
        return [$args, $n - 1, true];
    }

    private function matchingClose(int $open): int
    {
        return $this->parseArgs($open)[1];
    }

    private function argMatches(array $rule, array $args, bool $spread): bool
    {
        if (isset($rule['argc'])) {
            if ($spread) {
                return false;
            }
            [$op, $n] = $rule['argc'];
            $c = count($args);
            $ok = match ($op) {
                '==' => $c === $n, '<' => $c < $n, '>' => $c > $n, '>=' => $c >= $n, '<=' => $c <= $n,
            };
            if (!$ok) {
                return false;
            }
        }
        if (isset($rule['arg_token'])) {
            $found = false;
            foreach ($args as [$a, $b]) {
                for ($k = $a; $k <= $b; $k++) {
                    if (in_array(ltrim($this->text($k), '\\'), $rule['arg_token'], true)) {
                        $found = true;
                    }
                }
            }
            if (!$found) {
                return false;
            }
        }
        if (isset($rule['arg_is_string'])) {
            $arg = $args[$rule['arg_is_string']] ?? null;
            if ($arg === null || $arg[0] !== $arg[1] || $this->id($arg[0]) !== T_CONSTANT_ENCAPSED_STRING) {
                return false;
            }
        }
        if (isset($rule['arg_string'])) {
            $idx = $rule['arg_string']['index'];
            $values = array_map('strtolower', $rule['arg_string']['values']);
            $targets = $idx === 'any' ? $args : (isset($args[$idx]) ? [$args[$idx]] : []);
            $found = false;
            foreach ($targets as [$a, $b]) {
                if ($a === $b && $this->id($a) === T_CONSTANT_ENCAPSED_STRING && in_array(strtolower(substr($this->text($a), 1, -1)), $values, true)) {
                    $found = true;
                }
            }
            if (!$found) {
                return false;
            }
        }
        return true;
    }

    /** Functions, methods, constants, variables, casts, new, ini, and simple special tokens. */
    private function scanTokens(): void
    {
        $n = count($this->sig);
        $backtickOpen = false;
        for ($i = 0; $i < $n; $i++) {
            $this->at = $i;
            [$id, $text, $line] = $this->sig[$i];
            $prev = $this->id($i - 1);
            $next = $this->id($i + 1);

            if ($id === '`') {
                if (!$backtickOpen) {
                    $this->special('backtick', $line);
                }
                $backtickOpen = !$backtickOpen;
                continue;
            }
            if ($id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $this->special('dollar_brace', $line);
                continue;
            }
            if ($id === '{' && $i > 0 && $this->sig[$i - 1][3] === $this->sig[$i][3] - 1) {
                $p = $this->id($i - 1);
                if ($p === T_VARIABLE || $p === ']' || ($p === T_STRING && in_array($this->id($i - 2), [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true))) {
                    $this->special('curly_offset', $line);
                }
            }
            if ($id === '(' && ($this->id($i + 2) === ')')
                && (($this->id($i + 1) === T_STRING && strtolower($this->text($i + 1)) === 'real') || $this->id($i + 1) === T_UNSET)
                && !in_array($prev, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_VARIABLE, ')', ']', T_FUNCTION, T_ISSET, T_EMPTY, T_UNSET, T_LIST, T_ARRAY], true)) {
                $this->special('removed_cast', $line);
            }
            if ((defined('T_UNSET_CAST') && $id === T_UNSET_CAST)) {
                $this->special('removed_cast', $line);
            }
            if (in_array($id, [T_INT_CAST, T_BOOL_CAST, T_DOUBLE_CAST, T_STRING_CAST], true)) {
                $norm = strtolower(preg_replace('/\s+/', '', $text));
                if ($norm === '(real)' || $norm === '(unset)') {
                    $this->special('removed_cast', $line);
                }
                foreach ($this->byKind['cast'] ?? [] as $r) {
                    if (in_array($norm, $r['casts'], true)) {
                        $this->add($r['id'], $line);
                    }
                }
                continue;
            }
            if ($id === T_VARIABLE) {
                foreach ($this->byKind['var'] ?? [] as $r) {
                    if (in_array($text, $r['names'], true)) {
                        $this->add($r['id'], $line);
                    }
                }
                continue;
            }
            if ($id === T_NEW) {
                $cls = $this->id($i + 1);
                if (in_array($cls, [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                    $name = self::lastSegment($this->text($i + 1));
                    if ($cls === T_STRING || substr_count($this->text($i + 1), '\\') === 1) {
                        foreach ($this->byKind['new'] ?? [] as $r) {
                            if (in_array($name, array_map('strtolower', $r['classes']), true)) {
                                $this->add($r['id'], $line);
                            }
                        }
                    }
                }
                continue;
            }
            if ($id === T_CONSTANT_ENCAPSED_STRING) {
                $this->checkRelativeCallable($i);
                continue;
            }
            if ($id === T_CASE) {
                $this->checkCase($i);
                continue;
            }
            if ($id === T_DEFAULT && $next === ';') {
                $this->special('case_semicolon', $line);
                continue;
            }

            if ($id !== T_STRING && $id !== T_NAME_FULLY_QUALIFIED) {
                continue;
            }

            $isMember = in_array($prev, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
            $isDecl = in_array($prev, [T_FUNCTION, T_FN, T_CONST, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_EXTENDS, T_IMPLEMENTS, T_NEW, T_INSTANCEOF, T_GOTO, T_USE, T_INSTEADOF, T_AS], true)
                || ($prev === '&' && $this->id($i - 2) === T_FUNCTION);

            if ($next === '(') {
                if ($isMember) {
                    $this->checkMethod($i);
                } elseif (!$isDecl) {
                    $this->checkFunction($i);
                }
                continue;
            }
            if ($next === T_DOUBLE_COLON || $next === T_NS_SEPARATOR || $isDecl) {
                continue;
            }
            if ($prev === T_DOUBLE_COLON) {
                $this->checkClassConst($i);
            } elseif (!$isMember) {
                if ($prev === T_CASE && in_array($next, ['=', ';'], true)) {
                    continue; // enum case
                }
                $this->checkConst($i);
            }
        }
    }

    private function checkFunction(int $i): void
    {
        $name = $this->bareName($i);
        if ($name === null) {
            return;
        }
        $line = $this->sig[$i][2];
        [$args, $close, $spread] = $this->parseArgs($i + 1);
        foreach ($this->byKind['func'] ?? [] as $r) {
            if (in_array($name, $r['names'], true) && $this->argMatches($r, $args, $spread)) {
                $this->add($r['id'], $line);
            }
        }
        foreach ($this->byKind['func_prefix'] ?? [] as $r) {
            foreach ($r['prefixes'] as $p) {
                if (str_starts_with($name, $p)) {
                    $this->add($r['id'], $line);
                    break;
                }
            }
        }
        if (in_array($name, self::INI_FUNCS, true) && isset($args[0]) && $args[0][0] === $args[0][1] && $this->id($args[0][0]) === T_CONSTANT_ENCAPSED_STRING) {
            $ini = strtolower(substr($this->text($args[0][0]), 1, -1));
            foreach ($this->byKind['ini'] ?? [] as $r) {
                if (in_array($ini, $r['names'] ?? [], true) && in_array($name, $r['funcs'] ?? self::INI_FUNCS, true)) {
                    $this->add($r['id'], $line);
                }
            }
        }
        if (in_array($name, self::SUBSTR_FUNCS, true)) {
            // Only parentheses directly wrapping this call, e.g. (($x = substr(...)) === false).
            $wrap = 0;
            for ($b = $i - 1; $b > 0;) {
                if ($this->id($b) === '(' && !in_array($this->id($b - 1), [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_VARIABLE, ')', ']', T_ISSET, T_EMPTY, T_ARRAY, T_LIST], true)) {
                    $wrap++;
                    $b--;
                } elseif ($this->id($b) === '=' && $this->id($b - 1) === T_VARIABLE) {
                    $b -= 2;
                } else {
                    break;
                }
            }
            $k = $close + 1;
            while ($wrap-- > 0 && $this->id($k) === ')') {
                $k++;
            }
            $after = in_array($this->id($k), [T_IS_IDENTICAL, T_IS_NOT_IDENTICAL], true) && strtolower($this->text($k + 1)) === 'false';
            $before = in_array($this->id($i - 1), [T_IS_IDENTICAL, T_IS_NOT_IDENTICAL], true) && strtolower($this->text($i - 2)) === 'false';
            if ($after || $before) {
                $this->special('substr_false', $line);
            }
        }
    }

    private function checkMethod(int $i): void
    {
        $name = strtolower($this->text($i));
        [$args, , $spread] = $this->parseArgs($i + 1);
        foreach ($this->byKind['method'] ?? [] as $r) {
            if (in_array($name, array_map('strtolower', $r['names']), true) && $this->argMatches($r, $args, $spread)) {
                $this->add($r['id'], $this->sig[$i][2]);
            }
        }
    }

    private function checkConst(int $i): void
    {
        $name = ltrim($this->text($i), '\\');
        if (str_contains($name, '\\')) {
            return;
        }
        foreach ($this->byKind['const'] ?? [] as $r) {
            $hit = in_array($name, $r['names'] ?? [], true);
            foreach ($r['prefixes'] ?? [] as $p) {
                $hit = $hit || str_starts_with($name, $p);
            }
            if ($hit) {
                $this->add($r['id'], $this->sig[$i][2]);
            }
        }
    }

    private function checkClassConst(int $i): void
    {
        $name = $this->text($i);
        $cls = self::lastSegment($this->text($i - 2));
        foreach ($this->byKind['class_const'] ?? [] as $r) {
            if ($r['class'] !== '*' && strtolower($r['class']) !== $cls) {
                continue;
            }
            $hit = in_array($name, $r['names'] ?? [], true);
            foreach ($r['prefixes'] ?? [] as $p) {
                $hit = $hit || str_starts_with($name, $p);
            }
            if ($hit) {
                $this->add($r['id'], $this->sig[$i][2]);
            }
        }
    }

    private function checkRelativeCallable(int $i): void
    {
        $v = substr($this->text($i), 1, -1);
        $line = $this->sig[$i][2];
        if (preg_match('/^(self|parent|static)::[A-Za-z_]\w*$/i', $v)) {
            $this->special('relative_callable', $line);
            return;
        }
        if (in_array(strtolower($v), ['self', 'parent', 'static'], true)
            && ($this->id($i - 1) === '[' || ($this->id($i - 1) === '(' && $this->id($i - 2) === T_ARRAY))
            && $this->id($i + 1) === ','
            && $this->id($i + 2) === T_CONSTANT_ENCAPSED_STRING
            && in_array($this->id($i + 3), [']', ')'], true)) {
            $this->special('relative_callable', $line);
        }
    }

    private function checkCase(int $i): void
    {
        $line = $this->sig[$i][2];
        $depth = 0;
        $ternary = 0;
        $n = count($this->sig);
        if ($this->isZeroOrEmpty($i + 1) && $this->id($i + 2) === ':') {
            $this->special('loose_cmp', $line);
        }
        for ($k = $i + 1; $k < $n; $k++) {
            $id = $this->sig[$k][0];
            if (self::isOpen($id)) {
                $depth++;
            } elseif (self::isClose($id)) {
                if (--$depth < 0) {
                    return;
                }
            } elseif ($depth === 0 && $id === '?') {
                $ternary++;
            } elseif ($depth === 0 && $id === ':') {
                if ($ternary === 0) {
                    return;
                }
                $ternary--;
            } elseif ($depth === 0 && $id === ';') {
                // enum case: "case Foo;" or "case Foo = 'x';" is not a switch
                if (!$this->insideEnum($i)) {
                    $this->special('case_semicolon', $line);
                }
                return;
            } elseif ($depth === 0 && $id === '=') {
                return; // enum case with a value
            }
        }
    }

    private array $enumRanges = [];

    private function insideEnum(int $i): bool
    {
        foreach ($this->enumRanges as [$a, $b]) {
            if ($i > $a && $i < $b) {
                return true;
            }
        }
        return false;
    }

    private function isZeroOrEmpty(int $i): bool
    {
        $id = $this->id($i);
        $t = $this->text($i);
        if ($id === T_LNUMBER) {
            return $t === '0';
        }
        if ($id === T_DNUMBER) {
            return (float) $t === 0.0;
        }
        return $id === T_CONSTANT_ENCAPSED_STRING && ($t === "''" || $t === '""');
    }

    private function isLiteral(int $i): bool
    {
        $id = $this->id($i);
        return in_array($id, [T_LNUMBER, T_DNUMBER, T_CONSTANT_ENCAPSED_STRING], true)
            || ($id === T_STRING && in_array(strtolower($this->text($i)), ['null', 'true', 'false'], true));
    }

    /** Analisa per class: PHP4 constructor, Serializable, return type interface bawaan, dynamic property, __sleep/__wakeup, __autoload. */
    private function scanClasses(): void
    {
        $n = count($this->sig);
        $namespaced = false;
        foreach ($this->sig as $t) {
            if ($t[0] === T_NAMESPACE) {
                $namespaced = true;
                break;
            }
        }
        $classRanges = [];
        $this->enumRanges = [];
        for ($i = 0; $i < $n; $i++) {
            $id = $this->id($i);
            if (!in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) || $this->id($i - 1) === T_DOUBLE_COLON) {
                continue;
            }
            if ($this->id($i + 1) !== T_STRING) {
                continue; // class anonim
            }
            $className = $this->text($i + 1);
            $extends = [];
            $implements = [];
            $mode = null;
            $k = $i + 2;
            while ($k < $n && $this->id($k) !== '{') {
                $kid = $this->id($k);
                if ($kid === T_EXTENDS) {
                    $mode = 'e';
                } elseif ($kid === T_IMPLEMENTS) {
                    $mode = 'i';
                } elseif (in_array($kid, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    if ($mode === 'e') {
                        $extends[] = self::lastSegment($this->text($k));
                    } elseif ($mode === 'i') {
                        $implements[] = self::lastSegment($this->text($k));
                    }
                }
                $k++;
            }
            $open = $k;
            $close = $this->matchingClose($open);
            $classRanges[] = [$open, $close];
            if ($id === T_ENUM) {
                $this->enumRanges[] = [$open, $close];
            }

            $allowDynamic = false;
            for ($b = $i - 1; $b >= 0 && !in_array($this->id($b), [';', '}', '{', T_OPEN_TAG], true); $b--) {
                if (str_ends_with($this->text($b), 'AllowDynamicProperties')) {
                    $allowDynamic = true;
                }
            }

            $methods = [];
            $declared = [];
            $assigned = [];
            $usesTrait = false;
            $depth = 0;
            for ($k = $open + 1; $k < $close; $k++) {
                $kid = $this->id($k);
                if (self::isOpen($kid)) {
                    $depth++;
                } elseif (self::isClose($kid)) {
                    $depth--;
                }
                if ($depth === 0 && $kid === T_USE) {
                    $usesTrait = true;
                } elseif ($depth === 0 && $kid === T_VARIABLE) {
                    $declared[substr($this->text($k), 1)] = true;
                } elseif ($depth === 0 && $kid === T_FUNCTION) {
                    $m = $k + 1;
                    if ($this->id($m) === '&') {
                        $m++;
                    }
                    $mname = strtolower($this->text($m));
                    $paren = $m + 1;
                    if ($this->id($paren) !== '(') {
                        continue;
                    }
                    [$params, $pclose] = $this->parseArgs($paren);
                    $rtwc = false;
                    for ($b = $k - 1; $b > $open && !in_array($this->id($b), [';', '}', '{'], true); $b--) {
                        if (str_ends_with($this->text($b), 'ReturnTypeWillChange')) {
                            $rtwc = true;
                        }
                    }
                    $methods[$mname] = ['line' => $this->sig[$k][2], 'idx' => $k, 'rt' => $this->id($pclose + 1) === ':', 'rtwc' => $rtwc];
                    if ($mname === '__construct') {
                        foreach ($params as [$a, $z]) {
                            $promoted = false;
                            $var = null;
                            for ($q = $a; $q <= $z; $q++) {
                                if (in_array($this->id($q), [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY], true)) {
                                    $promoted = true;
                                }
                                if ($this->id($q) === T_VARIABLE && $var === null) {
                                    $var = substr($this->text($q), 1);
                                }
                            }
                            if ($promoted && $var !== null) {
                                $declared[$var] = true;
                            }
                        }
                    }
                }
                if ($kid === T_VARIABLE && $this->text($k) === '$this' && $this->id($k + 1) === T_OBJECT_OPERATOR && $this->id($k + 2) === T_STRING) {
                    $after = $this->id($k + 3);
                    if ($after === '=' || ($after === '[' && $this->id($k + 4) === ']' && $this->id($k + 5) === '=')) {
                        $prop = $this->text($k + 2);
                        $assigned[$prop] ??= [$this->sig[$k][2], $k];
                    }
                }
            }

            $classLine = $this->sig[$i][2];
            $ctor = $methods[strtolower($className)] ?? null;
            if ($id === T_CLASS && !$namespaced && $ctor !== null && !isset($methods['__construct'])) {
                $this->at = $ctor['idx'];
                $this->special('php4_ctor', $ctor['line']);
            }
            if (in_array('serializable', $implements, true) && !isset($methods['__serialize'])) {
                $this->at = $i;
                $this->special('serializable', $classLine);
            }
            $needRt = [];
            foreach (array_merge($implements, $extends) as $parent) {
                foreach (self::INTERNAL_METHODS[$parent] ?? [] as $m) {
                    $needRt[$m] = true;
                }
            }
            foreach ($needRt as $m => $_) {
                if (isset($methods[$m]) && !$methods[$m]['rt'] && !$methods[$m]['rtwc']) {
                    $this->at = $methods[$m]['idx'];
                    $this->special('internal_return_type', $methods[$m]['line']);
                }
            }
            foreach (['__sleep', '__wakeup'] as $m) {
                if (isset($methods[$m])) {
                    $this->at = $methods[$m]['idx'];
                    $this->special('sleep_wakeup', $methods[$m]['line']);
                }
            }
            if ($id === T_CLASS && $extends === [] && !$usesTrait && !$allowDynamic && !isset($methods['__set'])) {
                foreach ($assigned as $prop => [$line, $idx]) {
                    if (!isset($declared[$prop])) {
                        $this->at = $idx;
                        $this->special('dynamic_prop', $line);
                    }
                }
            }
        }

        for ($i = 0; $i < $n; $i++) {
            if ($this->id($i) === T_FUNCTION && strtolower($this->text($i + 1)) === '__autoload') {
                $inClass = false;
                foreach ($classRanges as [$a, $b]) {
                    $inClass = $inClass || ($i > $a && $i < $b);
                }
                if (!$inClass) {
                    $this->at = $i;
                    $this->special('autoload_def', $this->sig[$i][2]);
                }
            }
        }
    }

    /** Implicitly nullable parameters and optional parameters before required ones. */
    private function scanSignatures(): void
    {
        $n = count($this->sig);
        for ($i = 0; $i < $n; $i++) {
            $id = $this->id($i);
            if (($id !== T_FUNCTION && $id !== T_FN) || $this->id($i - 1) === T_USE) {
                continue;
            }
            $this->at = $i;
            $p = $i + 1;
            while ($p < $n && $p <= $i + 3 && $this->id($p) !== '(') {
                $p++;
            }
            if ($this->id($p) !== '(') {
                continue;
            }
            [$params] = $this->parseArgs($p);
            $info = [];
            foreach ($params as [$a, $z]) {
                $type = [];
                $hasDefault = false;
                $defaultNull = false;
                $variadic = false;
                $seenVar = false;
                for ($q = $a; $q <= $z; $q++) {
                    $qid = $this->id($q);
                    if ($qid === T_ATTRIBUTE) {
                        $q = $this->matchingClose($q);
                        continue;
                    }
                    if ($qid === T_VARIABLE) {
                        $seenVar = true;
                        continue;
                    }
                    if ($qid === T_ELLIPSIS) {
                        $variadic = true;
                        continue;
                    }
                    if (!$seenVar) {
                        $name = is_int($qid) ? token_name($qid) : '';
                        if (in_array($qid, [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY, '&'], true) || str_ends_with($name, '_SET') || $qid === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG || $qid === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG) {
                            continue;
                        }
                        $type[] = $this->text($q);
                    } elseif ($qid === '=') {
                        $hasDefault = true;
                        $defaultNull = $q + 1 === $z && strtolower(ltrim($this->text($z), '\\')) === 'null';
                        break;
                    }
                }
                $info[] = ['type' => strtolower(implode('', $type)), 'default' => $hasDefault, 'null' => $defaultNull, 'variadic' => $variadic];
            }
            $line = $this->sig[$i][2];
            foreach ($info as $prm) {
                if ($prm['type'] !== '' && $prm['null'] && !str_starts_with($prm['type'], '?')) {
                    $members = preg_split('/[|&()]/', $prm['type']);
                    if (!in_array('null', $members, true) && !in_array('mixed', $members, true)) {
                        $this->special('implicit_nullable', $line);
                    }
                }
            }
            $lastRequired = -1;
            foreach ($info as $idx => $prm) {
                if (!$prm['default'] && !$prm['variadic']) {
                    $lastRequired = $idx;
                }
            }
            for ($idx = 0; $idx < $lastRequired; $idx++) {
                if ($info[$idx]['default']) {
                    $this->special('optional_before_required', $line);
                    break;
                }
            }
        }
    }

    /** "." vs "+"/"-" precedence and loose comparison with 0 / "". */
    private const OPERAND_END = [T_VARIABLE, T_LNUMBER, T_DNUMBER, T_CONSTANT_ENCAPSED_STRING, T_STRING, T_NAME_QUALIFIED,
        T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, ')', ']', '"', T_END_HEREDOC, T_INC, T_DEC, T_LINE, T_FILE, T_DIR, T_CLASS_C, T_FUNC_C, T_METHOD_C, T_NS_C];
    private const DELIMITERS = [',', ';', '=', '?', ':', '<', '>', '&', '|', '^', T_DOUBLE_ARROW, T_COALESCE, T_IS_EQUAL, T_IS_NOT_EQUAL,
        T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_SMALLER_OR_EQUAL, T_IS_GREATER_OR_EQUAL, T_SPACESHIP, T_BOOLEAN_AND, T_BOOLEAN_OR,
        T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR, T_SL, T_SR, T_RETURN, T_ECHO, T_PRINT, T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG,
        T_INLINE_HTML, T_YIELD, T_THROW, T_CASE, T_CONCAT_EQUAL, T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_MOD_EQUAL,
        T_POW_EQUAL, T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL, T_COALESCE_EQUAL, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG,
        T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG];

    private function scanExpressions(): void
    {
        $stack = [['dot' => false, 'arith' => false, 'done' => false]];
        $n = count($this->sig);
        for ($i = 0; $i < $n; $i++) {
            $this->at = $i;
            $id = $this->id($i);
            if (self::isOpen($id)) {
                $stack[] = ['dot' => false, 'arith' => false, 'done' => false];
                continue;
            }
            if (self::isClose($id)) {
                if (count($stack) > 1) {
                    array_pop($stack);
                }
                continue;
            }
            if ($id === T_IS_EQUAL || $id === T_IS_NOT_EQUAL) {
                $l = $i - 1;
                $r = $i + 1;
                if (($this->isZeroOrEmpty($l) && !$this->isLiteral($r)) || ($this->isZeroOrEmpty($r) && !$this->isLiteral($l))) {
                    $this->special('loose_cmp', $this->sig[$i][2]);
                }
            }
            $top = &$stack[count($stack) - 1];
            if (in_array($id, self::DELIMITERS, true)) {
                $top = ['dot' => false, 'arith' => false, 'done' => false];
            } elseif ($id === '.') {
                $top['dot'] = true;
            } elseif (($id === '+' || $id === '-') && in_array($this->id($i - 1), self::OPERAND_END, true)) {
                $top['arith'] = true;
            }
            if ($top['dot'] && $top['arith'] && !$top['done']) {
                $this->special('concat_arith', $this->sig[$i][2]);
                $top['done'] = true;
            }
            unset($top);
        }
    }
}
