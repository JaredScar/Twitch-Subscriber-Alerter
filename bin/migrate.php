<?php
declare(strict_types=1);

/**
 * Creates the database (if needed) and the tables.   Usage:  php bin/migrate.php
 * Safe to run repeatedly (all statements are CREATE ... IF NOT EXISTS).
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Config;

$name = Config::need('DB_NAME');
if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
    fwrite(STDERR, "DB_NAME may only contain letters, digits and underscores.\n");
    exit(1);
}

try {
    $server = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', Config::get('DB_HOST', '127.0.0.1'), (int) Config::get('DB_PORT', '3306')),
        Config::get('DB_USER', 'root'),
        Config::get('DB_PASS', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $server->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $server->exec("USE `$name`");

    $sql = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;     // strip comment lines
    $count = 0;
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        $server->exec($statement);
        $count++;
    }
    echo "Database `$name` ready ($count statements).\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
