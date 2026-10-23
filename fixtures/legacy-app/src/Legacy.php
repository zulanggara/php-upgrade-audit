<?php
// each() in a comment must not be detected: each($x)
class Legacy
{
    public $declared;

    function Legacy()
    {
        $this->declared = 1;
        $this->undeclared = 2;
    }

    public function render($name, $opt = [], $required)
    {
        $s = "Hello ${name}";
        $first = $name{0};
        $n = (integer) "5";
        $out = `ls -la`;
        echo "Total: " . $n + 1;
        if ($name == 0) {}
        if (substr($name, 10) === false) {}
        $cb = 'self::helper';
        $cb2 = ['parent', 'helper'];
        $ok = in_array($type, ['static', 'dynamic']);
        trigger_error('x', E_USER_ERROR);
        return htmlspecialchars($s);
    }

    public function typed(string $x = null, int $y = 1) {}
}

class Collection implements ArrayAccess, Countable, Serializable
{
    public function offsetExists($o) { return true; }
    public function offsetGet($o) { return null; }
    #[\ReturnTypeWillChange]
    public function offsetSet($o, $v) {}
    public function offsetUnset($o): void {}
    public function count(): int { return 0; }
    public function serialize() { return ''; }
    public function unserialize($d) {}
    public function __sleep() { return []; }
}

function __autoload($c) {}

switch ($x) {
    case 1;
        break;
    case 'a':
        break;
}
$parts = implode(['a', 'b'], ',');
parse_str('a=1');
$r = each($arr);
$ch = curl_init();
curl_close($ch);
$fp = fopen('php://memory', 'w');
fputcsv($fp, ['a']);
fputcsv($fp, ['a'], ',', '"', '');
$s = utf8_encode('x');
$t = strftime('%Y');
$c = mysql_connect('h');
$enc = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
$pdo = new PDO('mysql:host=x');
$pdo->setAttribute(PDO::MYSQL_ATTR_INIT_COMMAND, 'SET NAMES utf8');
$fmt = DATE_RFC7231;
error_reporting(E_ALL & ~E_STRICT);
ini_set('track_errors', 1);
$f = (real) $n;
echo get_class();
if (is_resource($ch)) {}
$hash = password_hash($n, PASSWORD_DEFAULT);
$kept = password_hash($n, PASSWORD_BCRYPT, ['cost' => 10]);
