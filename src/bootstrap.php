<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = APP_ROOT . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

App\Config::load(APP_ROOT . '/.env');

date_default_timezone_set('UTC');

$isProd = App\Config::get('APP_ENV', 'production') !== 'development';
ini_set('display_errors', $isProd ? '0' : '1');
ini_set('log_errors', '1');
$logDir = APP_ROOT . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
if (is_dir($logDir) && is_writable($logDir)) {
    ini_set('error_log', $logDir . '/php-error.log');
}
