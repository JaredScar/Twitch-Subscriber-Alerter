#!/bin/sh
# Runs inside the `app` container (see README "Tests"): starts a fake Twitch and a second copy of the app
# against a throw-away database + upload folder, then runs tests/integration.php against them.
set -e
cd /var/www/html

export DB_NAME=tsa_test
export APP_URL=http://127.0.0.1:8099
export APP_SECRET=integration-test-secret-integration-test
export UPLOAD_DIR=/tmp/tsa-test-uploads
export APP_ENV=development
export ALERT_CATALOG=tests/fixtures/alert-catalog.json
export ADMIN_TWITCH_LOGINS=testadmin
export TWITCH_CLIENT_ID=test-client TWITCH_CLIENT_SECRET=test-secret
export TWITCH_AUTH_URL=http://127.0.0.1:8098/oauth2 TWITCH_API_URL=http://127.0.0.1:8098/helix

rm -rf "$UPLOAD_DIR" && mkdir -p "$UPLOAD_DIR"
php bin/migrate.php
php -S 127.0.0.1:8098 tests/fake_twitch.php >/tmp/fake.log 2>&1 &
php -S 127.0.0.1:8099 -t public >/tmp/app.log 2>&1 &
# same app and database, but logins limited to ADMIN_TWITCH_LOGINS
RESTRICT_LOGIN_TO_ADMINS=true php -S 127.0.0.1:8097 -t public >/tmp/app-restricted.log 2>&1 &
sleep 1
status=0
php tests/integration.php http://127.0.0.1:8099 http://127.0.0.1:8098 http://127.0.0.1:8097 || status=$?
rm -rf "$UPLOAD_DIR"
kill %1 %2 %3 2>/dev/null || true
exit $status
