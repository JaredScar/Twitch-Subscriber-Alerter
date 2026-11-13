#!/bin/sh
# Dev only: a seeded demo copy of the site (fake Twitch, throw-away DB) for taking screenshots.
#   docker compose run --rm -d -p 8099:8099 --entrypoint "" app sh tests/demo-server.sh
set -e
cd /var/www/html
export DB_NAME=tsa_demo
export APP_URL=http://127.0.0.1:8099
export APP_SECRET=demo-secret-demo-secret-demo-secret
export UPLOAD_DIR=/tmp/tsa-demo-uploads
export APP_ENV=development
export ADMIN_TWITCH_LOGINS=testadmin
export TWITCH_CLIENT_ID=test-client TWITCH_CLIENT_SECRET=test-secret
export TWITCH_AUTH_URL=http://127.0.0.1:8098/oauth2 TWITCH_API_URL=http://127.0.0.1:8098/helix

rm -rf "$UPLOAD_DIR" && mkdir -p "$UPLOAD_DIR"
php bin/migrate.php
php -S 127.0.0.1:8098 tests/fake_twitch.php >/tmp/fake.log 2>&1 &
php -S 0.0.0.0:8099 -t public >/tmp/app.log 2>&1 &
sleep 1
php tests/seed-demo.php http://127.0.0.1:8099 http://127.0.0.1:8098
echo "ready"
sleep 1800
