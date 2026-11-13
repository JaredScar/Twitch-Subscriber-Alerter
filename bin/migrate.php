<?php
declare(strict_types=1);

/**
 * Creates the tables.   Usage:  php bin/migrate.php
 * Safe to run repeatedly (all statements are CREATE ... IF NOT EXISTS).
 *
 * When the database user is allowed to, this also creates the database. Shared
 * hosting (cPanel) only lets that user into a database made in the control panel,
 * so a failed CREATE DATABASE is ignored and the existing database is used.
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Config;

$name = Config::need('DB_NAME');
if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
    fwrite(STDERR, "DB_NAME may only contain letters, digits and underscores.\n");
    exit(1);
}

$user = Config::get('DB_USER', 'root');
$pass = Config::get('DB_PASS', '');
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
$dsn = static function (?string $db = null): string {
    $base = sprintf(
        'mysql:host=%s;port=%d;charset=utf8mb4',
        Config::get('DB_HOST', '127.0.0.1'),
        (int) Config::get('DB_PORT', '3306')
    );
    return $db === null ? $base : $base . ';dbname=' . $db;
};

try {
    try {
        $server = new PDO($dsn(), $user, $pass, $options);
        $server->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $server->exec("USE `$name`");
    } catch (PDOException) {
        $server = new PDO($dsn($name), $user, $pass, $options);
    }

    $sql = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;     // strip comment lines
    $count = 0;
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        $server->exec($statement);
        $count++;
    }

    // schema.sql only creates missing tables, so columns added in later versions are added here.
    // (MySQL has no ADD COLUMN IF NOT EXISTS, so look first.)
    $columns = [
        ['user_settings', 'play_mode', "VARCHAR(8) NOT NULL DEFAULT 'queue' AFTER resub_alert"],
    ];
    $has = $server->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    foreach ($columns as [$table, $column, $definition]) {
        $has->execute([$table, $column]);
        if ((int) $has->fetchColumn() === 0) {
            $server->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            echo "Added $table.$column\n";
            $count++;
        }
    }
    echo "Database `$name` ready ($count statements).\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    if (stripos($e->getMessage(), 'Access denied') !== false || stripos($e->getMessage(), 'Unknown database') !== false) {
        fwrite(STDERR, "Create the database and user in your host's control panel, grant that user all rights on it, and set DB_* in .env. You can also import database/schema.sql in phpMyAdmin.\n");
    }
    exit(1);
}
