<?php
// Load every class of the given vendor packages on the target PHP and collect errors/deprecations.
// Usage: php -d error_reporting=-1 load_vendor.php <vendor-dir> <vendor/package>...
$vendor = $argv[1];
$packages = array_slice($argv, 2);
$msgs = [];
set_error_handler(function ($no, $str, $file, $line) use (&$msgs, $vendor) {
    $file = str_replace($vendor . '/', '', $file);
    $msgs[$str . ' @ ' . $file . ':' . $line] = ($msgs[$str . ' @ ' . $file . ':' . $line] ?? 0) + 1;
    return true;
});
require $vendor . '/autoload.php';
$loaded = 0; $failed = [];
foreach ($packages as $pkg) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$vendor/$pkg/src", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') continue;
        $ns = ''; $cls = null;
        $t = token_get_all(file_get_contents($f->getPathname()));
        for ($i = 0; $i < count($t); $i++) {
            if (is_array($t[$i]) && $t[$i][0] === T_NAMESPACE) { $ns = trim($t[$i + 2][1] ?? ''); }
            if (is_array($t[$i]) && in_array($t[$i][0], [T_CLASS, T_INTERFACE, T_TRAIT], true) && is_array($t[$i + 2] ?? null) && $t[$i + 2][0] === T_STRING && !(is_array($t[$i-1]) && $t[$i-1][0] === T_DOUBLE_COLON)) { $cls = ($ns ? $ns . '\\' : '') . $t[$i + 2][1]; break; }
        }
        if (!$cls) continue;
        try {
            if (class_exists($cls) || interface_exists($cls) || trait_exists($cls)) $loaded++; else $failed[] = $cls;
        } catch (Throwable $e) { $failed[] = "$cls: " . get_class($e) . ': ' . $e->getMessage(); }
    }
}
echo "PHP ", PHP_VERSION, " | class dimuat: $loaded | gagal: ", count($failed), "\n";
foreach ($failed as $f) echo "  GAGAL $f\n";
foreach ($msgs as $m => $n) echo "  [$n] $m\n";
