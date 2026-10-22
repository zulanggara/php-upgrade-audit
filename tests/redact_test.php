<?php
/**
 * Redaction tests. Token-like strings are assembled at runtime so this file itself contains no secret
 * patterns (and does not trip secret scanners).
 */
declare(strict_types=1);

require __DIR__ . '/../scripts/redact.php';

$gh = 'gh' . 'p_' . str_repeat('a1B2', 9);
$aws = 'AK' . 'IA' . str_repeat('Q', 16);
$jwt = 'ey' . 'JhbGciOiJIUzI1NiJ9.ey' . 'JzdWIiOiIxMjM0In0.' . str_repeat('x', 16);
$key = '-----BEGIN RSA PRIV' . "ATE KEY-----\nMIIEow...\n-----END RSA PRIV" . 'ATE KEY-----';

$mustHide = [
    "\"url\": \"https://$gh@github.com/acme/lib.git\"",
    "{\"github-oauth\": {\"github.com\": \"$gh\"}}",
    "\$config['encryption_key'] = 'Zx9!kq2#notreal';",
    "'password' => 'hunter2',",
    "\$db_pass = \"s3cr3t\";",
    "DB_PASSWORD=supersecret",
    "  api_key: abc123def",
    "mysql://root:toor@127.0.0.1:3306/app",
    "curl_setopt(\$ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer abcdefghijklmnop123']);",
    "\$k = '$aws';",
    "\$jwt = '$jwt';",
    $key,
    "\$pass = \$_SERVER['X'] ?? 'a-very-complex-password-here';",
    "define('API_SECRET', '9f8e7d6c5b4a39281706f5e4d3c2b1a0ffeeddcc');",
];
$mustKeep = [
    "\$password = \$this->input->post('password');",
    "'password' => '',",
    "\$token = bin2hex(random_bytes(16));",
    "'token' => \$token,",
    "fputcsv(\$handle, \$headings, \$delimiter, \$enclosure, '\\\\');",
    "\$config['sess_driver'] = 'files';",
    "if (\$user['status'] == 0) {",
    "\$hash = password_hash(\$pass, PASSWORD_DEFAULT);",
    "git@github.com:acme/php-upgrade-audit.git",
    "strtotime('-' . (\$lifetime - 2) . ' minutes')",
    "COMPOSER_AUTH='{\"github-oauth\":{\"github.com\":\"<new-token>\"}}'",
    "PasswordEncoder::encode('') returns a different value",
    "token == \$expected",
    "Password: see the note below",
    "\"url\": \"https://ghp_••••••@github.com/acme/lib.git\"",
    "COMPOSER_AUTH='{\"github-oauth\":{\"github.com\":\"ghp_••••••\"}}'",
];

$fail = 0;
foreach ($mustHide as $s) {
    $c = 0;
    $r = redact_text($s, $c);
    if ($c === 0 || $r === $s) {
        $fail++;
        echo "NOT REDACTED: $s\n";
    }
}
foreach ($mustKeep as $s) {
    $r = redact_text($s);
    if ($r !== $s) {
        $fail++;
        echo "WRONGLY REDACTED: $s\n  -> $r\n";
    }
}
echo $fail === 0 ? "redaction: ok (" . (count($mustHide) + count($mustKeep)) . " cases)\n" : "redaction: $fail failure(s)\n";
// secret_scan: independent final check
$bad = ["token $gh here", "key $aws", "https://user:pw@example.com/x", $key];
$good = ["ghp_••••••", "https://«REDACTED»@github.com/x", "ghp_«REDACTED»", "plain text with no secrets", "-----BEGIN RSA PRIVATE KEY----- «REDACTED» -----END PRIVATE KEY-----"];
$scanFail = 0;
foreach ($bad as $s) { if (secret_scan($s) === 0) { $scanFail++; echo "secret_scan MISSED: " . substr($s, 0, 30) . "\n"; } }
foreach ($good as $s) { if (secret_scan($s) !== 0) { $scanFail++; echo "secret_scan FALSE POSITIVE: $s\n"; } }
echo $scanFail === 0 ? "secret_scan: ok\n" : "secret_scan: $scanFail failure(s)\n";
exit($fail === 0 && $scanFail === 0 ? 0 : 1);
