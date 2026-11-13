#!/bin/sh
set -e

echo "Waiting for MariaDB..."
i=0
until php -r '
    try {
        new PDO(
            "mysql:host=" . getenv("DB_HOST") . ";port=" . (getenv("DB_PORT") ?: "3306"),
            getenv("DB_USER") ?: "root",
            getenv("DB_PASS") !== false ? getenv("DB_PASS") : ""
        );
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
'; do
    i=$((i + 1))
    if [ "$i" -gt 60 ]; then
        echo "Database did not become ready." >&2
        exit 1
    fi
    sleep 1
done

php /var/www/html/bin/migrate.php
exec "$@"
