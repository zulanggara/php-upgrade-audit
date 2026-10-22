<?php
// Load the class definitions of one CodeIgniter 3 application file on top of a given system/ folder (no request, no DB)
// and print every PHP message as JSON. Signature conflicts show up here as fatal errors.
// Usage: php -d error_reporting=-1 -d display_errors=0 link_app.php <system-dir> <app-dir> <file.php>
[$_, $system, $app, $file] = $argv;
define('BASEPATH', rtrim($system, '/') . '/');
define('APPPATH', rtrim($app, '/') . '/');
define('VIEWPATH', APPPATH . 'views/');
define('FCPATH', sys_get_temp_dir() . '/');
define('ENVIRONMENT', 'testing');
define('SYSDIR', 'system');
$GLOBALS['__msgs'] = [];
set_error_handler(function ($no, $str, $f, $l) {
    $GLOBALS['__msgs'][] = [$no, $str, $f, $l];
    return true;
});
register_shutdown_function(function () use ($app, $system) {
    $e = error_get_last();
    $out = [];
    foreach ($GLOBALS['__msgs'] as [$no, $str, $f, $l]) {
        $out[] = ['type' => $no === E_DEPRECATED || $no === E_USER_DEPRECATED ? 'Deprecated' : ($no === E_WARNING ? 'Warning' : 'Notice'), 'msg' => $str, 'where' => str_replace([$app . '/', $system . '/'], ['app/', 'system/'], $f) . ':' . $l];
    }
    if ($e && in_array($e['type'], [E_ERROR, E_COMPILE_ERROR, E_CORE_ERROR, E_PARSE], true)) {
        $out[] = ['type' => 'Fatal', 'msg' => $e['message'], 'where' => str_replace([$app . '/', $system . '/'], ['app/', 'system/'], $e['file']) . ':' . $e['line']];
    }
    echo json_encode($out), "\n";
});
require BASEPATH . 'core/Common.php';
require BASEPATH . 'core/Controller.php';
require BASEPATH . 'core/Model.php';
require BASEPATH . 'libraries/Migration.php';
try {
    // Like CodeIgniter.php: load APPPATH/core/<subclass_prefix>Controller.php first
    $prefix = 'MY_';
    if (is_file(APPPATH . 'config/config.php') && preg_match('/\$config\[\'subclass_prefix\'\]\s*=\s*\'([^\']*)\'/', (string) file_get_contents(APPPATH . 'config/config.php'), $m)) {
        $prefix = $m[1];
    }
    $base = APPPATH . 'core/' . $prefix . 'Controller.php';
    if (realpath($file) !== realpath($base) && is_file($base)) {
        require_once $base;
    }
    require $file;
} catch (Throwable $t) {
    $GLOBALS['__msgs'][] = [E_ERROR, get_class($t) . ': ' . $t->getMessage(), $t->getFile(), $t->getLine()];
}
