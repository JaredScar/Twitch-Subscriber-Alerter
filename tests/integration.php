<?php
declare(strict_types=1);

/**
 * End-to-end test against a running app + a fake Twitch. See tests/README section in the main README.
 *
 *   php tests/integration.php http://127.0.0.1:8099 http://127.0.0.1:8098
 *                              (app under test)       (php -S ... tests/fake_twitch.php)
 *
 * The app must run with the same environment as this script (DB_*, APP_SECRET, TWITCH_*, ADMIN_TWITCH_LOGINS=testadmin,
 * TWITCH_AUTH_URL=<fake>/oauth2, TWITCH_API_URL=<fake>/helix) against a THROW-AWAY database: the script wipes it.
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\CustomAlerts;
use App\Database;
use App\EventSub;

$app = rtrim($argv[1] ?? 'http://127.0.0.1:8099', '/');
$fake = rtrim($argv[2] ?? 'http://127.0.0.1:8098', '/');
$restricted = isset($argv[3]) ? rtrim($argv[3], '/') : null;   // optional: same app started with RESTRICT_LOGIN_TO_ADMINS=true
if (!str_contains((string) Config::get('DB_NAME'), 'test')) {
    fwrite(STDERR, "Refusing to run: DB_NAME must contain 'test' (this script wipes the database).\n");
    exit(2);
}

$total = 0;
$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $total, $failed;
    $total++;
    if (!$ok) {
        $failed++;
    }
    echo ($ok ? 'ok    ' : 'FAIL  ') . $name . (!$ok && $detail !== '' ? "   -> $detail" : '') . "\n";
}

/** @return array{status:int,headers:array<string,string>,body:string} */
function http(string $method, string $url, array $o = []): array
{
    $ch = curl_init($url);
    $headers = [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HEADERFUNCTION => static function ($c, $line) use (&$headers) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
            return strlen($line);
        },
    ]);
    if (isset($o['jar'])) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $o['jar']);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $o['jar']);
    }
    if (isset($o['form'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($o['form']));
    }
    if (isset($o['multipart'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $o['multipart']);
    }
    if (isset($o['raw'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $o['raw']);
    }
    if (isset($o['headers'])) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $o['headers']);
    }
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'headers' => $headers, 'body' => $body];
}

function csrfFrom(string $html): string
{
    return preg_match('/name="_csrf" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : '';
}

function post(string $url, string $jar, array $form): array
{
    return http('POST', $url, ['jar' => $jar, 'form' => $form]);
}

/** Send a signed EventSub message to the app's webhook. */
function webhook(string $app, string $type, array $payload, ?string $messageId = null, ?string $secret = null, ?string $ts = null): array
{
    $body = json_encode($payload);
    $id = $messageId ?? bin2hex(random_bytes(8));
    $ts ??= gmdate('Y-m-d\TH:i:s\Z');
    $secret ??= Config::webhookSecret();
    return http('POST', "$app/webhook/twitch.php", ['raw' => $body, 'headers' => [
        'Content-Type: application/json',
        "Twitch-Eventsub-Message-Id: $id",
        "Twitch-Eventsub-Message-Timestamp: $ts",
        'Twitch-Eventsub-Message-Signature: ' . EventSub::signature($id, $ts, $body, $secret),
        "Twitch-Eventsub-Message-Type: $type",
    ]]);
}

function giftPayload(string $broadcaster, int $total, bool $anon = false): array
{
    return ['subscription' => ['type' => 'channel.subscription.gift', 'version' => '1', 'condition' => ['broadcaster_user_id' => $broadcaster]],
        'event' => ['user_id' => '777', 'user_login' => 'generous', 'user_name' => 'Generous', 'broadcaster_user_id' => $broadcaster,
            'total' => $total, 'tier' => '1000', 'cumulative_total' => 99, 'is_anonymous' => $anon]];
}

/* ------------------------------------------------------------------------------------ reset */
foreach (['events', 'eventsub_subscriptions', 'alert_rules', 'user_settings', 'custom_alerts', 'overlay_seen', 'users', 'app_state'] as $t) {
    Database::run("DELETE FROM `$t`");
}
http('GET', "$fake/reset");
$jar1 = tempnam(sys_get_temp_dir(), 'jar');
$jar2 = tempnam(sys_get_temp_dir(), 'jar');
$jarAnon = tempnam(sys_get_temp_dir(), 'jar');

/* ------------------------------------------------------------------------------ anonymous */
$r = http('GET', "$app/index.php", ['jar' => $jarAnon]);
check('home page renders', $r['status'] === 200 && str_contains($r['body'], 'login.php'), "status {$r['status']}");
check('CSP + nosniff headers on pages', isset($r['headers']['content-security-policy']) && ($r['headers']['x-content-type-options'] ?? '') === 'nosniff');
$r = http('GET', "$app/dashboard.php", ['jar' => $jarAnon]);
check('dashboard needs login (redirect)', in_array($r['status'], [302, 303], true), "status {$r['status']}");
$r = http('GET', "$app/overlay.php?key=" . str_repeat('a', 32));
check('overlay with unknown key is 404', $r['status'] === 404);
$r = http('GET', "$app/overlay.php?key=nope");
check('overlay with malformed key is 404', $r['status'] === 404);
$r = http('GET', "$app/api/poll.php?key=" . str_repeat('b', 32));
check('poll with unknown key is 404', $r['status'] === 404);
$r = post("$app/actions.php", $jarAnon, ['action' => 'regen_key']);
check('actions without CSRF/session rejected', $r['status'] === 400, "status {$r['status']}");
$r = http('GET', "$app/actions.php", ['jar' => $jarAnon]);
check('actions rejects GET', $r['status'] === 405, "status {$r['status']}");
$r = http('GET', "$app/webhook/twitch.php");
check('webhook rejects GET', in_array($r['status'], [403, 405], true), "status {$r['status']}");
$r = http('POST', "$app/webhook/twitch.php", ['raw' => '{}', 'headers' => ['Twitch-Eventsub-Message-Type: notification']]);
check('unsigned webhook is 403', $r['status'] === 403, "status {$r['status']}");

/* --------------------------------------------------------------------------- OAuth login */
$r = http('GET', "$app/login.php", ['jar' => $jar1]);
$loc = $r['headers']['location'] ?? '';
parse_str((string) parse_url($loc, PHP_URL_QUERY), $q);
check('login redirects to Twitch authorize', $r['status'] === 302 && str_starts_with($loc, "$fake/oauth2/authorize"), "status {$r['status']} loc $loc");
check('authorize url has client_id, scope, state, redirect', ($q['client_id'] ?? '') === Config::get('TWITCH_CLIENT_ID')
    && ($q['scope'] ?? '') === 'channel:read:subscriptions' && strlen($q['state'] ?? '') >= 16
    && ($q['redirect_uri'] ?? '') === "$app/callback.php", json_encode($q));
$state = $q['state'] ?? '';

$r = http('GET', "$app/callback.php?code=good-code&state=WRONG", ['jar' => $jar1]);
check('callback with wrong state rejected', $r['status'] === 400, "status {$r['status']}");

$r = http('GET', "$app/login.php", ['jar' => $jar1]);                       // new state (the failed attempt consumed the old one)
parse_str((string) parse_url($r['headers']['location'] ?? '', PHP_URL_QUERY), $q);
$r = http('GET', "$app/callback.php?code=no-scope-code&state=" . ($q['state'] ?? ''), ['jar' => $jar1]);
check('callback without the subscription scope rejected', $r['status'] === 502, "status {$r['status']}");

$r = http('GET', "$app/login.php", ['jar' => $jar1]);
parse_str((string) parse_url($r['headers']['location'] ?? '', PHP_URL_QUERY), $q);
$r = http('GET', "$app/callback.php?code=good-code&state=" . ($q['state'] ?? ''), ['jar' => $jar1]);
check('callback logs the streamer in -> dashboard', in_array($r['status'], [302, 303], true) && str_contains($r['headers']['location'] ?? '', 'dashboard.php'), "status {$r['status']} " . substr($r['body'], 0, 200));

$u1 = Database::one("SELECT * FROM users WHERE twitch_id = '1234'");
check('user row created', $u1 && $u1['login'] === 'streamer_one' && (int) $u1['is_admin'] === 0 && preg_match('/^[a-f0-9]{32}$/', $u1['overlay_key']) === 1);
$subs = json_decode(http('GET', "$fake/dump")['body'], true) ?: [];
check('3 EventSub subscriptions created at (fake) Twitch', count($subs) === 3, 'got ' . count($subs));
check('subscriptions use our callback URL', count($subs) > 0 && $subs[0]['transport']['callback'] === "$app/webhook/twitch.php");
$st = Database::all('SELECT type, status FROM eventsub_subscriptions WHERE user_id = ?', [$u1['id']]);
check('subscription status mirrored in DB', count($st) === 3);
check('default rules + settings created', count(Database::all('SELECT 1 FROM alert_rules WHERE user_id = ?', [$u1['id']])) === 5
    && Database::one('SELECT 1 FROM user_settings WHERE user_id = ?', [$u1['id']]) !== null);
check('no user token stored anywhere', Database::one("SELECT 1 FROM app_state WHERE k <> 'app_token'") === null);

$r = http('GET', "$app/dashboard.php", ['jar' => $jar1]);
$dash = $r['body'];
check('dashboard renders for streamer', $r['status'] === 200 && str_contains($dash, 'Streamer_One'), "status {$r['status']}");
check('dashboard shows overlay url', str_contains($dash, 'overlay.php?key=' . $u1['overlay_key']));
check('dashboard shows all 5 alerts', substr_count($dash, 'name="alert_id"') === 5, (string) substr_count($dash, 'name="alert_id"'));
check('streamer cannot open admin panel', http('GET', "$app/admin.php", ['jar' => $jar1])['status'] === 403);
$csrf = csrfFrom($dash);
check('csrf token present', strlen($csrf) >= 32);

/* ---------------------------------------------------------------------------- overlay page */
$key = $u1['overlay_key'];
$r = http('GET', "$app/overlay.php?key=$key");
check('overlay page renders', $r['status'] === 200 && str_contains($r['body'], 'engine.js') && str_contains($r['body'], 'gifted-100.js') && str_contains($r['body'], 'overlay.js'));
$r = http('GET', "$app/api/poll.php?key=$key");
$j = json_decode($r['body'], true);
check('poll without cursor returns cursor only', $r['status'] === 200 && $j['events'] === [] && $j['cursor'] === 0 && $j['enabled'] === true, $r['body']);

/* ---------------------------------------------------------------------- test button action */
$r = post("$app/actions.php", $jar1, ['_csrf' => $csrf, 'action' => 'test', 'alert_id' => 'gifted-050']);
check('test alert action redirects', in_array($r['status'], [302, 303], true));
$j = json_decode(http('GET', "$app/api/poll.php?key=$key&after=0")['body'], true);
check('test alert reaches the poll feed', count($j['events']) === 1 && $j['events'][0]['alert'] === 'gifted-050', json_encode($j));
$cursor = $j['cursor'];
$r = post("$app/actions.php", $jar1, ['_csrf' => $csrf, 'action' => 'test', 'alert_id' => 'evil']);
$j = json_decode(http('GET', "$app/api/poll.php?key=$key&after=$cursor")['body'], true);
check('unknown alert id creates no event', $j['events'] === [] && $j['cursor'] === $cursor);
post("$app/actions.php", $jar1, ['_csrf' => $csrf, 'action' => 'test_gift', 'count' => '3']);
$j = json_decode(http('GET', "$app/api/poll.php?key=$key&after=$cursor")['body'], true);
check('simulate 3 gifts plays nothing', $j['events'] === []);
post("$app/actions.php", $jar1, ['_csrf' => $csrf, 'action' => 'test_gift', 'count' => '37']);
$j = json_decode(http('GET', "$app/api/poll.php?key=$key&after=$cursor")['body'], true);
check('simulate 37 gifts plays the 20 alert', count($j['events']) === 1 && $j['events'][0]['alert'] === 'gifted-020', json_encode($j));
$cursor = $j['cursor'];

/* ------------------------------------------------------------------------------- webhooks */
$secret = Config::webhookSecret();
$r = webhook($app, 'webhook_callback_verification', ['challenge' => 'abc123', 'subscription' => ['id' => 'x']]);
check('webhook handshake echoes challenge', $r['status'] === 200 && $r['body'] === 'abc123', "status {$r['status']} body {$r['body']}");
$r = webhook($app, 'notification', giftPayload('1234', 12), null, 'wrong-secret-wrong-secret');
check('webhook with wrong secret is 403', $r['status'] === 403);
$r = webhook($app, 'notification', giftPayload('1234', 12), null, null, gmdate('Y-m-d\TH:i:s\Z', time() - 3600));
check('webhook with stale timestamp is 403', $r['status'] === 403);

$mid = 'msg-gift-12';
$r = webhook($app, 'notification', giftPayload('1234', 12), $mid);
check('gift of 12 accepted (204)', $r['status'] === 204, "status {$r['status']}");
$j = json_decode(http('GET', "$app/api/poll.php?key=$key&after=$cursor")['body'], true);
check('gift of 12 -> alert gifted-010 in feed', count($j['events']) === 1 && $j['events'][0]['alert'] === 'gifted-010', json_encode($j));
check('feed event has gift details', ($j['events'][0]['count'] ?? null) === 12 || ($j['events'][0]['gift_count'] ?? null) === 12 || str_contains(json_encode($j['events'][0]), '12'), json_encode($j['events'][0] ?? []));
$cursor = $j['cursor'];
webhook($app, 'notification', giftPayload('1234', 12), $mid);          // Twitch retry of the same message
$j = json_decode(http('GET', "$app/api/poll.php?key=$key&after=$cursor")['body'], true);
check('duplicate message id ignored', $j['events'] === []);
check('duplicate not stored twice', (int) Database::one("SELECT COUNT(*) c FROM events WHERE twitch_message_id = ?", [$mid])['c'] === 1);

webhook($app, 'notification', giftPayload('1234', 2));
$j = json_decode(http('GET', "$app/api/poll.php?key=$key&after=$cursor")['body'], true);
check('gift of 2 logged but no alert', $j['events'] === []);
$row = Database::one("SELECT * FROM events WHERE type = 'gift' AND gift_count = 2");
check('gift of 2 recorded in log with note', $row && $row['alert_id'] === null && $row['note'] !== null);

webhook($app, 'notification', giftPayload('1234', 150, true));
$j = json_decode(http('GET', "$app/api/poll.php?key=$key&after=$cursor")['body'], true);
check('anonymous gift of 150 -> gifted-100', count($j['events']) === 1 && $j['events'][0]['alert'] === 'gifted-100', json_encode($j));
$anon = Database::one("SELECT * FROM events WHERE gift_count = 150");
check('anonymous gifter name hidden', $anon && $anon['actor'] === 'Anonymous' && (int) $anon['is_anonymous'] === 1);
$cursor = $j['cursor'];

$sub = static fn(bool $gift) => ['subscription' => ['type' => 'channel.subscribe', 'version' => '1', 'condition' => ['broadcaster_user_id' => '1234']],
    'event' => ['user_name' => 'NewFan', 'user_login' => 'newfan', 'broadcaster_user_id' => '1234', 'tier' => '1000', 'is_gift' => $gift]];
webhook($app, 'notification', $sub(true));
$j = json_decode(http('GET', "$app/api/poll.php?key=$key&after=$cursor")['body'], true);
check('gift-recipient sub does not double alert', $j['events'] === []);
check('gift-recipient sub not logged', Database::one("SELECT 1 FROM events WHERE actor = 'NewFan'") === null);
webhook($app, 'notification', $sub(false));
$j = json_decode(http('GET', "$app/api/poll.php?key=$key&after=$cursor")['body'], true);
check('new sub plays the default sub alert', count($j['events']) === 1 && $j['events'][0]['alert'] === 'gifted-005', json_encode($j));
$cursor = $j['cursor'];

$resub = ['subscription' => ['type' => 'channel.subscription.message', 'version' => '1', 'condition' => ['broadcaster_user_id' => '1234']],
    'event' => ['user_name' => 'Loyal', 'broadcaster_user_id' => '1234', 'tier' => '1000', 'cumulative_months' => 14, 'message' => ['text' => 'hi']]];
webhook($app, 'notification', $resub);
$j = json_decode(http('GET', "$app/api/poll.php?key=$key&after=$cursor")['body'], true);
check('resub plays the resub alert', count($j['events']) === 1 && $j['events'][0]['alert'] === 'gifted-005', json_encode($j));
$cursor = $j['cursor'];
check('resub months recorded', (int) Database::one("SELECT months FROM events WHERE actor = 'Loyal'")['months'] === 14);

$r = webhook($app, 'notification', giftPayload('424242', 55));
check('event for unknown broadcaster is acknowledged and dropped', $r['status'] === 204 && (int) Database::one('SELECT COUNT(*) c FROM events WHERE gift_count = 55')['c'] === 0);

/* ------------------------------------------------------------------------ settings + rules */
$form = ['_csrf' => $csrf, 'action' => 'save_settings', 'react_gift' => '1', 'react_sub' => '1', 'overlay_enabled' => '1',
    'sub_alert' => 'gifted-020', 'resub_alert' => 'gifted-010', 'volume' => '35',
    'rules' => ['gifted-005' => ['enabled' => '1', 'min_count' => '3'], 'gifted-010' => ['min_count' => '10'],   // 010 disabled, resub off
        'gifted-020' => ['enabled' => '1', 'min_count' => '20'], 'gifted-050' => ['enabled' => '1', 'min_count' => '50'],
        'gifted-100' => ['enabled' => '1', 'min_count' => '100']]];
post("$app/actions.php", $jar1, $form);
$j = json_decode(http('GET', "$app/api/poll.php?key=$key&after=$cursor")['body'], true);
check('volume setting exposed to overlay', abs($j['volume'] - 0.35) < 0.001, json_encode($j));
check('play mode defaults to queue (form sent none)', $j['mode'] === 'queue', json_encode($j));
$j0 = json_decode(http('GET', "$app/api/poll.php?key=$key")['body'], true);
check('play mode is also in the first poll (no cursor yet)', ($j0['mode'] ?? null) === 'queue', json_encode($j0));
check('dashboard shows the play mode choice', str_contains(http('GET', "$app/dashboard.php", ['jar' => $jar1])['body'], 'name="play_mode"'));
post("$app/actions.php", $jar1, $form + ['play_mode' => 'stack']);
$j = json_decode(http('GET', "$app/api/poll.php?key=$key&after=$cursor")['body'], true);
check('play mode stack is saved and reaches the overlay', $j['mode'] === 'stack', json_encode($j));
check('dashboard marks stack as the chosen mode', (bool) preg_match('/value="stack"\s+checked/', http('GET', "$app/dashboard.php", ['jar' => $jar1])['body']));
post("$app/actions.php", $jar1, $form + ['play_mode' => 'everything-at-once']);
$j = json_decode(http('GET', "$app/api/poll.php?key=$key&after=$cursor")['body'], true);
check('an unknown play mode is stored as queue', $j['mode'] === 'queue', json_encode($j));
post("$app/actions.php", $jar1, $form + ['play_mode' => 'stack']);
post("$app/actions.php", $jar1, $form);
$j = json_decode(http('GET', "$app/api/poll.php?key=$key&after=$cursor")['body'], true);
check('a form without a play mode goes back to queue', $j['mode'] === 'queue', json_encode($j));
webhook($app, 'notification', giftPayload('1234', 4));
webhook($app, 'notification', giftPayload('1234', 13));
$j = json_decode(http('GET', "$app/api/poll.php?key=$key&after=$cursor")['body'], true);
$alerts = array_column($j['events'], 'alert');
check('custom rules: 4 -> 005 (min 3), 13 -> 005 (010 disabled)', $alerts === ['gifted-005', 'gifted-005'], json_encode($alerts));
$cursor = $j['cursor'];
webhook($app, 'notification', $sub(false));
webhook($app, 'notification', $resub);
$j = json_decode(http('GET', "$app/api/poll.php?key=$key&after=$cursor")['body'], true);
check('sub uses chosen alert (020), resub switched off', array_column($j['events'], 'alert') === ['gifted-020'], json_encode($j['events']));
$cursor = $j['cursor'];
check('resub with react off still logged with note', Database::one("SELECT note FROM events WHERE type = 'resub' ORDER BY id DESC LIMIT 1")['note'] === 'resub alerts off');

$form['overlay_enabled'] = '';        // pause
unset($form['overlay_enabled']);
post("$app/actions.php", $jar1, $form);
webhook($app, 'notification', giftPayload('1234', 60));
$j = json_decode(http('GET', "$app/api/poll.php?key=$key&after=$cursor")['body'], true);
check('paused overlay: event logged but not delivered', $j['events'] === [] && (int) Database::one('SELECT COUNT(*) c FROM events WHERE gift_count = 60')['c'] === 1);
$form['overlay_enabled'] = '1';
post("$app/actions.php", $jar1, $form);

$dash = http('GET', "$app/dashboard.php", ['jar' => $jar1])['body'];
check('dashboard events table lists events', str_contains($dash, 'Generous') && str_contains($dash, 'Loyal'));

$r = http('GET', "$app/api/events.php", ['jar' => $jar1]);
check('events api (session) returns json', $r['status'] === 200 && is_array(json_decode($r['body'], true)), "status {$r['status']} " . substr($r['body'], 0, 100));
check('events api requires login', in_array(http('GET', "$app/api/events.php")['status'], [401, 302, 303, 403], true));

/* ---------------------------------------------------------------------- test rate limiting */
$sent = 0;
for ($i = 0; $i < 30; $i++) {
    post("$app/actions.php", $jar1, ['_csrf' => $csrf, 'action' => 'test', 'alert_id' => 'gifted-005']);
}
$tests = (int) Database::one("SELECT COUNT(*) c FROM events WHERE type = 'test' AND user_id = ?", [$u1['id']])['c'];
check('test alerts are rate limited', $tests <= 22, "$tests test events");

/* -------------------------------------------------------------------------- key regeneration */
post("$app/actions.php", $jar1, ['_csrf' => $csrf, 'action' => 'regen_key']);
check('old overlay key stops working', http('GET', "$app/overlay.php?key=$key")['status'] === 404 && http('GET', "$app/api/poll.php?key=$key")['status'] === 404);
$u1 = Database::one('SELECT * FROM users WHERE id = ?', [$u1['id']]);
check('new overlay key works', http('GET', "$app/overlay.php?key={$u1['overlay_key']}")['status'] === 200 && $u1['overlay_key'] !== $key);

/* -------------------------------------------------------------------------------- resync */
$r = post("$app/actions.php", $jar1, ['_csrf' => $csrf, 'action' => 'resync']);
check('resync keeps exactly 3 subscriptions', count(json_decode(http('GET', "$fake/dump")['body'], true)) === 3);

/* -------------------------------------------------------------------------- admin (owner) */
$r = http('GET', "$app/login.php", ['jar' => $jar2]);
parse_str((string) parse_url($r['headers']['location'] ?? '', PHP_URL_QUERY), $q);
http('GET', "$app/callback.php?code=admin-code&state=" . ($q['state'] ?? ''), ['jar' => $jar2]);
$admin = Database::one("SELECT * FROM users WHERE twitch_id = '9001'");
check('admin flagged from ADMIN_TWITCH_LOGINS', $admin && (int) $admin['is_admin'] === 1);
$r = http('GET', "$app/admin.php", ['jar' => $jar2]);
check('admin panel lists streamers', $r['status'] === 200 && str_contains($r['body'], 'Streamer_One'), "status {$r['status']}");
check('admin panel never shows overlay keys', !str_contains($r['body'], $u1['overlay_key']));
$acsrf = csrfFrom($r['body']);
post("$app/actions.php", $jar2, ['_csrf' => $acsrf, 'action' => 'admin_toggle', 'user_id' => $u1['id']]);
check('admin can disable a streamer', (int) Database::one('SELECT is_disabled FROM users WHERE id = ?', [$u1['id']])['is_disabled'] === 1);
$j = json_decode(http('GET', "$app/api/poll.php?key={$u1['overlay_key']}&after=0")['body'], true);
check('disabled streamer overlay gets no events', $j['events'] === [] && $j['enabled'] === false);
$before = (int) Database::one('SELECT COUNT(*) c FROM events WHERE user_id = ?', [$u1['id']])['c'];
webhook($app, 'notification', giftPayload('1234', 30));
check('disabled streamer webhook ignored', (int) Database::one('SELECT COUNT(*) c FROM events WHERE user_id = ?', [$u1['id']])['c'] === $before);
post("$app/actions.php", $jar2, ['_csrf' => $acsrf, 'action' => 'admin_toggle', 'user_id' => $u1['id']]);
check('admin can re-enable', (int) Database::one('SELECT is_disabled FROM users WHERE id = ?', [$u1['id']])['is_disabled'] === 0);
post("$app/actions.php", $jar2, ['_csrf' => $acsrf, 'action' => 'admin_toggle', 'user_id' => $admin['id']]);
check('admin cannot disable themselves', (int) Database::one('SELECT is_disabled FROM users WHERE id = ?', [$admin['id']])['is_disabled'] === 0);
post("$app/actions.php", $jar1, ['_csrf' => $csrf, 'action' => 'admin_toggle', 'user_id' => $admin['id']]);
check('non-admin cannot use admin actions', (int) Database::one('SELECT is_disabled FROM users WHERE id = ?', [$admin['id']])['is_disabled'] === 0);
post("$app/actions.php", $jar2, ['_csrf' => $acsrf, 'action' => 'admin_resync_all']);
check('admin resync all keeps subscriptions at 6 (2 streamers x 3)', count(json_decode(http('GET', "$fake/dump")['body'], true)) === 6);

/* ------------------------------------------------------------------ restricted logins */
if ($restricted !== null) {
    $jarR = tempnam(sys_get_temp_dir(), 'jar');
    $loginAt = static function (string $base, string $jar, string $code): array {
        $r = http('GET', "$base/login.php", ['jar' => $jar]);
        parse_str((string) parse_url($r['headers']['location'] ?? '', PHP_URL_QUERY), $q);
        return http('GET', "$base/callback.php?code=$code&state=" . ($q['state'] ?? ''), ['jar' => $jar]);
    };
    $subsBefore = count(json_decode(http('GET', "$fake/dump")['body'], true));
    $seenBefore = Database::value('SELECT last_login_at FROM users WHERE id = ?', [$u1['id']]);
    $r = $loginAt($restricted, $jarR, 'good-code');                               // streamer_one is not in ADMIN_TWITCH_LOGINS
    check('restricted: a non-admin Twitch account is refused (403)', $r['status'] === 403 && str_contains($r['body'], 'private'), "status {$r['status']}");
    check('restricted: refused login is not logged in', in_array(http('GET', "$restricted/dashboard.php", ['jar' => $jarR])['status'], [302, 303], true));
    check('restricted: refusal touches nothing (no login recorded, no Twitch links)', Database::value('SELECT last_login_at FROM users WHERE id = ?', [$u1['id']]) === $seenBefore
        && count(json_decode(http('GET', "$fake/dump")['body'], true)) === $subsBefore);
    check('restricted: the home page says the site is private', str_contains(http('GET', "$restricted/index.php")['body'], 'private site'));
    // a session created while logins were open (sessions are shared between the two instances through the session files)
    $jarOld = tempnam(sys_get_temp_dir(), 'jar');
    $loginAt($app, $jarOld, 'good-code');
    check('restricted: (setup) a session from before the restriction works on the open instance', http('GET', "$app/dashboard.php", ['jar' => $jarOld])['status'] === 200);
    check('restricted: that existing session is signed out once logins are restricted', in_array(http('GET', "$restricted/dashboard.php", ['jar' => $jarOld])['status'], [302, 303], true));
    @unlink($jarOld);
    $r = $loginAt($restricted, $jarR, 'admin-code');                              // testadmin is listed
    check('restricted: an admin can log in', in_array($r['status'], [302, 303], true) && str_contains($r['headers']['location'] ?? '', 'dashboard.php'), "status {$r['status']}");
    check('restricted: and reach the dashboard', http('GET', "$restricted/dashboard.php", ['jar' => $jarR])['status'] === 200);
    @unlink($jarR);
}

/* ------------------------------------------------------------------------- custom alerts */
$k1 = $u1['overlay_key'];
$kAdmin = $admin['overlay_key'];
$upload = rtrim((string) Config::get('UPLOAD_DIR'), '/') ;
check('UPLOAD_DIR points at a throw-away folder', $upload !== '' && str_contains($upload, 'test'), $upload);
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$wav = 'RIFF' . pack('V', 36 + 800) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8) . 'data' . pack('V', 800) . str_repeat("\x80", 800);
$htmlAlert = '<!doctype html><html><body><script>addEventListener("message",function(){parent.postMessage({type:"done"},"*")})</script></body></html>';
$tmp = sys_get_temp_dir() . '/tsa-' . bin2hex(random_bytes(4));
mkdir($tmp);
file_put_contents("$tmp/a.png", $png);
file_put_contents("$tmp/s.wav", $wav);
file_put_contents("$tmp/alert.html", $htmlAlert);
file_put_contents("$tmp/evil.png", '<?php echo "pwned";');
file_put_contents("$tmp/evil.html", "\x00\x01\x02\x03 not html");
file_put_contents("$tmp/big.png", $png . str_repeat('0', CustomAlerts::MEDIA_MAX));
$submit = static fn(string $jar, string $token, array $fields, array $files = []) => http('POST', "$app/alert-actions.php", [
    'jar' => $jar,
    'multipart' => ['_csrf' => $token, 'action' => 'submit'] + $fields + array_map(static fn($p) => new CURLFile($p), $files),
]);
$pendingFor = static fn(int $uid) => (int) Database::value("SELECT COUNT(*) FROM custom_alerts WHERE owner_id = ?", [$uid]);

$r = http('GET', "$app/my-alerts.php", ['jar' => $jar1]);
check('alerts page renders for streamer', $r['status'] === 200 && str_contains($r['body'], 'Submit your own alert') && str_contains($r['body'], 'enctype="multipart/form-data"'), "status {$r['status']}");
check('review page is admin only', http('GET', "$app/admin-alerts.php", ['jar' => $jar1])['status'] === 403 && in_array(http('GET', "$app/admin-alerts.php", ['jar' => $jarAnon])['status'], [302, 303], true));
$r = post("$app/alert-actions.php", $jarAnon, ['action' => 'submit']);
check('submitting needs a session', $r['status'] !== 200 && in_array($r['status'], [302, 303, 400, 403], true), "status {$r['status']}");

// --- rejected submissions
$base = ['name' => 'Bad one', 'kind' => 'media', 'text' => 'hi', 'textPos' => 'below'];
$submit($jar1, $csrf, $base, ['media' => "$tmp/evil.png"]);
check('a PHP file renamed .png is rejected by content', $pendingFor((int) $u1['id']) === 0);
$submit($jar1, $csrf, $base, ['media' => "$tmp/a.png", 'sound' => "$tmp/evil.png"]);
check('a non-audio file in the sound slot is rejected (and nothing is stored)', $pendingFor((int) $u1['id']) === 0);
$submit($jar1, $csrf, $base, []);
check('media alert without a file is rejected', $pendingFor((int) $u1['id']) === 0);
$submit($jar1, $csrf, ['name' => 'Bad html', 'kind' => 'html'], ['html' => "$tmp/evil.html"]);
check('binary junk as an HTML alert is rejected', $pendingFor((int) $u1['id']) === 0);
$submit($jar1, $csrf, ['name' => 'Bad kind', 'kind' => 'php'], ['media' => "$tmp/a.png"]);
check('unknown kind rejected', $pendingFor((int) $u1['id']) === 0);
$submit($jar1, $csrf, ['name' => 'x', 'kind' => 'media'], ['media' => "$tmp/a.png"]);
check('too-short name rejected', $pendingFor((int) $u1['id']) === 0);
$submit($jar1, $csrf, ['name' => 'Huge', 'kind' => 'media'], ['media' => "$tmp/big.png"]);
check('oversized file rejected', $pendingFor((int) $u1['id']) === 0);
$r = http('POST', "$app/alert-actions.php", ['jar' => $jar1, 'multipart' => ['action' => 'submit', 'name' => 'No csrf', 'kind' => 'media', 'media' => new CURLFile("$tmp/a.png")]]);
check('submission without CSRF token rejected', $r['status'] === 400 && $pendingFor((int) $u1['id']) === 0, "status {$r['status']}");

// --- a good media alert and a good HTML alert
$r = $submit($jar1, $csrf, ['name' => '  Confetti   burst ', 'description' => 'party time', 'kind' => 'media', 'text' => '{name} -> {count}!', 'textPos' => 'above',
    'textColor' => '#ff00aa', 'fontSize' => '9999', 'width' => '50', 'position' => 'tr', 'animation' => 'slide', 'duration' => '6'], ['media' => "$tmp/a.png", 'sound' => "$tmp/s.wav"]);
check('valid media submission accepted', in_array($r['status'], [302, 303], true) && $pendingFor((int) $u1['id']) === 1, "status {$r['status']}");
$ca = Database::one("SELECT * FROM custom_alerts WHERE owner_id = ?", [$u1['id']]);
$caId = (int) ($ca['id'] ?? 0);
$cfg = json_decode((string) ($ca['config'] ?? ''), true) ?: [];
check('submission starts as pending, name tidied', ($ca['status'] ?? '') === 'pending' && ($ca['name'] ?? '') === 'Confetti burst' && ($ca['kind'] ?? '') === 'media', json_encode($ca));
check('display settings sanitised (font size clamped, position kept)', ($cfg['fontSize'] ?? 0) === 160 && ($cfg['position'] ?? '') === 'tr' && ($cfg['textColor'] ?? '') === '#ff00aa'
    && ($cfg['animation'] ?? '') === 'slide' && ($cfg['duration'] ?? 0) === 6 && ($cfg['textPos'] ?? '') === 'above', json_encode($cfg));
check('files stored outside the web root', is_file("$upload/custom/$caId/media.png") && is_file("$upload/custom/$caId/sound.wav") && !str_contains($upload, '/public'), $upload);

$r = $submit($jar1, $csrf, ['name' => 'Fireworks HTML', 'kind' => 'html', 'html_duration' => '7'], ['html' => "$tmp/alert.html"]);
$ha = Database::one("SELECT * FROM custom_alerts WHERE owner_id = ? AND kind = 'html'", [$u1['id']]);
$haId = (int) ($ha['id'] ?? 0);
check('valid HTML submission accepted (pending, duration kept)', $ha && $ha['status'] === 'pending' && (json_decode($ha['config'], true)['duration'] ?? 0) === 7, json_encode($ha));

// --- nothing unvetted is served to a live overlay
$mediaUrl = static fn(int $id, string $f, ?string $key = null) => "$app/media.php?a=$id&f=$f" . ($key !== null ? "&key=$key" : '');
check('pending alert is NOT served to the overlay (owner key)', http('GET', $mediaUrl($caId, 'media', $k1))['status'] === 404);
check('pending alert is not served to an anonymous visitor', http('GET', $mediaUrl($caId, 'media'))['status'] === 404);
$r = http('GET', $mediaUrl($caId, 'media'), ['jar' => $jar1]);
check('owner can fetch their pending file (preview)', $r['status'] === 200 && ($r['headers']['content-type'] ?? '') === 'image/png' && $r['body'] === $png, "status {$r['status']} " . ($r['headers']['content-type'] ?? ''));
check('admin can fetch a pending file (review)', http('GET', $mediaUrl($caId, 'sound'), ['jar' => $jar2])['status'] === 200);
$r = http('GET', $mediaUrl($haId, 'html'), ['jar' => $jar1]);
check('html alert is served with a sandbox CSP', $r['status'] === 200 && str_contains($r['headers']['content-security-policy'] ?? '', 'sandbox allow-scripts') && str_contains($r['headers']['content-security-policy'] ?? '', "connect-src 'none'"), json_encode($r['headers']));
check('media.php rejects odd parameters', http('GET', "$app/media.php?a=abc&f=media", ['jar' => $jar1])['status'] === 404
    && http('GET', "$app/media.php?a=$caId&f=../../x", ['jar' => $jar1])['status'] === 404
    && http('GET', "$app/media.php?a=$caId&f=config", ['jar' => $jar1])['status'] === 404);
$r = http('GET', $mediaUrl($caId, 'media'), ['jar' => $jar1, 'headers' => ['Range: bytes=0-9']]);
check('media.php supports Range requests', $r['status'] === 206 && strlen($r['body']) === 10 && $r['body'] === substr($png, 0, 10), "status {$r['status']}");
check('preview page works for the owner, 404 for strangers', http('GET', "$app/preview.php?id=$caId", ['jar' => $jar1])['status'] === 200
    && http('GET', "$app/preview.php?id=$caId", ['jar' => $jar2])['status'] === 200 && http('GET', "$app/preview.php?id=999999", ['jar' => $jar1])['status'] === 404);

// --- cannot use or test a pending alert
post("$app/alert-actions.php", $jar1, ['_csrf' => $csrf, 'action' => 'use', 'alert_id' => $caId]);
check('a pending alert cannot be added', Database::one("SELECT 1 FROM alert_rules WHERE user_id = ? AND alert_id = ?", [$u1['id'], "custom-$caId"]) === null);
Database::run("DELETE FROM events WHERE type = 'test'");
post("$app/actions.php", $jar1, ['_csrf' => $csrf, 'action' => 'test', 'alert_id' => "custom-$caId"]);
check('a pending alert cannot be test-fired at the overlay', Database::one("SELECT 1 FROM events WHERE alert_id = ?", ["custom-$caId"]) === null);
post("$app/alert-actions.php", $jar1, ['_csrf' => $csrf, 'action' => 'admin_approve', 'alert_id' => $caId, 'visibility' => 'public']);
check('streamers cannot approve their own alerts', (string) Database::value('SELECT status FROM custom_alerts WHERE id = ?', [$caId]) === 'pending');

// --- admin review
$r = http('GET', "$app/admin-alerts.php", ['jar' => $jar2]);
check('review queue lists the pending alerts with a source view', $r['status'] === 200 && str_contains($r['body'], 'Confetti burst') && str_contains($r['body'], 'Fireworks HTML') && str_contains($r['body'], 'View source'), "status {$r['status']}");
$r = post("$app/alert-actions.php", $jar2, ['_csrf' => $acsrf, 'action' => 'admin_reject', 'alert_id' => $haId, 'note' => '']);
check('rejecting needs a reason', (string) Database::value('SELECT status FROM custom_alerts WHERE id = ?', [$haId]) === 'pending');
post("$app/alert-actions.php", $jar2, ['_csrf' => $acsrf, 'action' => 'admin_approve', 'alert_id' => $caId, 'visibility' => 'maybe']);
check('approve needs private or public', (string) Database::value('SELECT status FROM custom_alerts WHERE id = ?', [$caId]) === 'pending');
post("$app/alert-actions.php", $jar2, ['_csrf' => $acsrf, 'action' => 'admin_approve', 'alert_id' => $caId, 'visibility' => 'private', 'note' => 'looks fine']);
post("$app/alert-actions.php", $jar2, ['_csrf' => $acsrf, 'action' => 'admin_approve', 'alert_id' => $haId, 'visibility' => 'public']);
$ca = Database::one('SELECT * FROM custom_alerts WHERE id = ?', [$caId]);
check('admin approved #1 as private', $ca['status'] === 'approved' && $ca['visibility'] === 'private' && $ca['review_note'] === 'looks fine' && (int) $ca['reviewed_by'] === (int) $admin['id']);
check('admin approved #2 as public', (string) Database::value('SELECT visibility FROM custom_alerts WHERE id = ?', [$haId]) === 'public');

// --- who may fetch approved files
check('approved private alert served to the owner overlay', http('GET', $mediaUrl($caId, 'media', $k1))['status'] === 200 && http('GET', $mediaUrl($caId, 'sound', $k1))['status'] === 200);
check('approved private alert NOT served to another streamer overlay', http('GET', $mediaUrl($caId, 'media', $kAdmin))['status'] === 404);
check('approved public alert served to another streamer overlay', http('GET', $mediaUrl($haId, 'html', $kAdmin))['status'] === 200);
check('wrong overlay key gets nothing', http('GET', $mediaUrl($caId, 'media', str_repeat('c', 32)))['status'] === 404);
http('GET', "$app/my-alerts.php", ['jar' => $jar2]);                    // first view shows (and clears) the admin's approval flash messages
$lib = http('GET', "$app/my-alerts.php", ['jar' => $jar2])['body'];
check('public alert appears in other streamers\' library, private one does not', str_contains($lib, 'Fireworks HTML') && !str_contains($lib, 'Confetti burst'),
    (str_contains($lib, 'Fireworks HTML') ? 'public listed' : 'public MISSING') . ', ' . (str_contains($lib, 'Confetti burst') ? 'private LEAKED' : 'private hidden'));
$mine = http('GET', "$app/my-alerts.php", ['jar' => $jar1])['body'];
check('owner sees status + reviewer note', str_contains($mine, 'Approved') && str_contains($mine, 'looks fine'));

// --- using approved alerts
post("$app/alert-actions.php", $jar2, ['_csrf' => $acsrf, 'action' => 'use', 'alert_id' => $caId]);
check('a private alert cannot be added by someone else', Database::one("SELECT 1 FROM alert_rules WHERE user_id = ? AND alert_id = ?", [$admin['id'], "custom-$caId"]) === null);
post("$app/alert-actions.php", $jar1, ['_csrf' => $csrf, 'action' => 'use', 'alert_id' => $caId]);
$rule = Database::one("SELECT * FROM alert_rules WHERE user_id = ? AND alert_id = ?", [$u1['id'], "custom-$caId"]);
check('owner can use an approved alert (default minimum 5, enabled)', $rule && (int) $rule['enabled'] === 1 && (int) $rule['min_count'] === 5);
post("$app/alert-actions.php", $jar2, ['_csrf' => $acsrf, 'action' => 'use', 'alert_id' => $haId]);
check('another streamer can use a public alert', Database::one("SELECT 1 FROM alert_rules WHERE user_id = ? AND alert_id = ?", [$admin['id'], "custom-$haId"]) !== null);

$dash = http('GET', "$app/dashboard.php", ['jar' => $jar1])['body'];
check('dashboard lists the custom alert (rule row + test button)', str_contains($dash, 'rules[custom-' . $caId . '][min_count]') && str_contains($dash, 'value="custom-' . $caId . '"') && str_contains($dash, 'preview.php?id=' . $caId));
check('dashboard dropdowns offer the custom alert', substr_count($dash, '<option value="custom-' . $caId . '"') === 2, (string) substr_count($dash, '<option value="custom-' . $caId . '"'));
check('dashboard does not offer alerts the streamer does not use', !str_contains($dash, "custom-$haId"));
$form['sub_alert'] = "custom-$caId";
$form['resub_alert'] = "custom-$haId";                        // approved + public but not added by this streamer
$form['rules']["custom-$caId"] = ['enabled' => '1', 'min_count' => '5'];
post("$app/actions.php", $jar1, $form);
$set = Database::one('SELECT sub_alert, resub_alert FROM user_settings WHERE user_id = ?', [$u1['id']]);
check('new-sub alert can be a custom alert; unadded ones are refused', $set['sub_alert'] === "custom-$caId" && $set['resub_alert'] === null, json_encode($set));

// --- they play through the overlay feed
$j = json_decode(http('GET', "$app/api/poll.php?key=$k1")['body'], true);
check('poll without cursor lists no custom descriptors', $j['custom'] === [] || (array) $j['custom'] === []);
$cursor = $j['cursor'];
post("$app/actions.php", $jar1, ['_csrf' => $csrf, 'action' => 'test', 'alert_id' => "custom-$caId"]);
$j = json_decode(http('GET', "$app/api/poll.php?key=$k1&after=$cursor")['body'], true);
$d = $j['custom']["custom-$caId"] ?? null;
check('test button plays a custom alert through the feed', count($j['events']) === 1 && $j['events'][0]['alert'] === "custom-$caId" && $d !== null, json_encode($j));
check('descriptor has the player settings and keyed media urls', $d && $d['kind'] === 'media' && $d['position'] === 'tr' && $d['textPos'] === 'above' && $d['duration'] === 6
    && str_contains($d['media']['url'], "a=$caId") && str_contains($d['media']['url'], "key=$k1") && isset($d['sound']['url']), json_encode($d));
$cursor = $j['cursor'];
check('the overlay has been marked as seen', Database::value('SELECT seen_at FROM overlay_seen WHERE user_id = ?', [$u1['id']]) !== null);
$ev = json_decode(http('GET', "$app/api/events.php", ['jar' => $jar1])['body'], true);
check('events api reports overlay_seen_at and the custom alert name', !empty($ev['overlay_seen_at']) && in_array('Confetti burst', array_column($ev['events'], 'label'), true), json_encode($ev));

webhook($app, 'notification', giftPayload('1234', 6));
webhook($app, 'notification', giftPayload('1234', 4));
$j = json_decode(http('GET', "$app/api/poll.php?key=$k1&after=$cursor")['body'], true);
check('gift rules resolve to a custom alert (6 -> custom min 5, 4 -> built-in)', array_column($j['events'], 'alert') === ["custom-$caId", 'gifted-005'], json_encode(array_column($j['events'], 'alert')));
$cursor = $j['cursor'];
webhook($app, 'notification', $sub(false));
$j = json_decode(http('GET', "$app/api/poll.php?key=$k1&after=$cursor")['body'], true);
check('new sub plays the chosen custom alert', array_column($j['events'], 'alert') === ["custom-$caId"], json_encode($j['events']));
$cursor = $j['cursor'];

// --- taking one offline stops it everywhere, at once
post("$app/alert-actions.php", $jar2, ['_csrf' => $acsrf, 'action' => 'admin_revoke', 'alert_id' => $caId, 'note' => '']);
check('taking offline needs a reason', (string) Database::value('SELECT status FROM custom_alerts WHERE id = ?', [$caId]) === 'approved');
Database::run("INSERT INTO events (user_id, type, actor, gift_count, alert_id, created_at) VALUES (?, 'gift', 'Queued', 7, ?, ?)", [$u1['id'], "custom-$caId", Database::now()]);
post("$app/alert-actions.php", $jar2, ['_csrf' => $acsrf, 'action' => 'admin_revoke', 'alert_id' => $caId, 'note' => 'copyright']);
check('admin took it offline with a reason', (string) Database::value('SELECT status FROM custom_alerts WHERE id = ?', [$caId]) === 'rejected'
    && (string) Database::value('SELECT review_note FROM custom_alerts WHERE id = ?', [$caId]) === 'copyright');
$j = json_decode(http('GET', "$app/api/poll.php?key=$k1&after=$cursor")['body'], true);
check('queued events for an offline alert are dropped, not played', $j['events'] === [] && (array) $j['custom'] === [], json_encode($j));
check('offline alert files are no longer served to the overlay', http('GET', $mediaUrl($caId, 'media', $k1))['status'] === 404);
Database::run("DELETE FROM events WHERE type = 'test'");
post("$app/actions.php", $jar1, ['_csrf' => $csrf, 'action' => 'test', 'alert_id' => "custom-$caId"]);
check('offline alert cannot be test-fired', Database::one("SELECT 1 FROM events WHERE type = 'test' AND alert_id = ?", ["custom-$caId"]) === null);
webhook($app, 'notification', giftPayload('1234', 8));
$j = json_decode(http('GET', "$app/api/poll.php?key=$k1&after=$cursor")['body'], true);
check('gift rules skip an offline alert (8 -> built-in)', array_column($j['events'], 'alert') === ['gifted-005'], json_encode($j['events']));
$dash = http('GET', "$app/dashboard.php", ['jar' => $jar1])['body'];
check('offline alert disappears from the dashboard', !str_contains($dash, "rules[custom-$caId]") && !str_contains($dash, '<option value="custom-' . $caId . '"'));
$mine = http('GET', "$app/my-alerts.php", ['jar' => $jar1])['body'];
check('owner sees the reason it is offline', str_contains($mine, 'copyright'));

// --- deleting
$cursor = $j['cursor'];
check('owner cannot delete someone else\'s alert', (function () use ($app, $jar2, $acsrf, $haId) {
    // the admin is not the owner of the HTML alert: the streamer-side "delete" action must refuse
    post("$app/alert-actions.php", $jar2, ['_csrf' => $acsrf, 'action' => 'delete', 'alert_id' => $haId]);
    return Database::one('SELECT 1 FROM custom_alerts WHERE id = ?', [$haId]) !== null;
})());
post("$app/alert-actions.php", $jar1, ['_csrf' => $csrf, 'action' => 'delete', 'alert_id' => $caId]);
check('owner can delete their alert: row, files, rules gone', Database::one('SELECT 1 FROM custom_alerts WHERE id = ?', [$caId]) === null
    && !is_dir("$upload/custom/$caId") && Database::one('SELECT 1 FROM alert_rules WHERE alert_id = ?', ["custom-$caId"]) === null);
check('events keep their history but lose the alert reference', Database::one('SELECT 1 FROM events WHERE alert_id = ?', ["custom-$caId"]) === null
    && Database::one("SELECT 1 FROM events WHERE actor = 'Queued' AND alert_id IS NULL") !== null);
check('deleting also clears it from the new-sub setting', Database::value('SELECT sub_alert FROM user_settings WHERE user_id = ?', [$u1['id']]) === null);

// admin removes a public alert another streamer uses
post("$app/alert-actions.php", $jar2, ['_csrf' => $acsrf, 'action' => 'admin_delete', 'alert_id' => $haId]);
check('admin can delete a public alert; users of it lose the rule', Database::one('SELECT 1 FROM custom_alerts WHERE id = ?', [$haId]) === null
    && Database::one('SELECT 1 FROM alert_rules WHERE alert_id = ?', ["custom-$haId"]) === null && !is_dir("$upload/custom/$haId"));

// limits
for ($i = 1; $i <= CustomAlerts::MAX_PENDING_PER_USER + 1; $i++) {
    $submit($jar1, $csrf, ['name' => "Pending $i", 'kind' => 'html'], ['html' => "$tmp/alert.html"]);
}
check('at most ' . CustomAlerts::MAX_PENDING_PER_USER . ' pending submissions per streamer', (int) Database::value("SELECT COUNT(*) FROM custom_alerts WHERE owner_id = ? AND status = 'pending'", [$u1['id']]) === CustomAlerts::MAX_PENDING_PER_USER);
$leftover = Database::all('SELECT id FROM custom_alerts WHERE owner_id = ?', [$u1['id']]);

// cleanup of test files
foreach (glob("$tmp/*") ?: [] as $f) { @unlink($f); }
@rmdir($tmp);

/* ------------------------------------------------------------------------ account deletion */
$r = post("$app/actions.php", $jar1, ['_csrf' => $csrf, 'action' => 'delete_account', 'confirm' => 'nope']);
check('delete needs confirmation word', Database::one('SELECT 1 FROM users WHERE id = ?', [$u1['id']]) !== null);
post("$app/actions.php", $jar1, ['_csrf' => $csrf, 'action' => 'delete_account', 'confirm' => 'DELETE']);
check('delete removes user and cascades', Database::one('SELECT 1 FROM users WHERE id = ?', [$u1['id']]) === null
    && (int) Database::one('SELECT COUNT(*) c FROM events WHERE user_id = ?', [$u1['id']])['c'] === 0
    && (int) Database::one('SELECT COUNT(*) c FROM alert_rules WHERE user_id = ?', [$u1['id']])['c'] === 0);
check('delete also removes the streamer\'s custom alerts and their files', $leftover !== [] && (int) Database::value('SELECT COUNT(*) FROM custom_alerts') === 0
    && array_reduce($leftover, static fn($ok, $r) => $ok && !is_dir("$upload/custom/{$r['id']}"), true));
check('delete also removes subscriptions at Twitch', count(json_decode(http('GET', "$fake/dump")['body'], true)) === 3);
check('deleted streamer overlay is gone', http('GET', "$app/overlay.php?key={$u1['overlay_key']}")['status'] === 404);
check('deleted session is logged out', in_array(http('GET', "$app/dashboard.php", ['jar' => $jar1])['status'], [302, 303], true));

echo "\n" . ($total - $failed) . "/$total passed\n";
foreach ([$jar1, $jar2, $jarAnon] as $j) {
    @unlink($j);
}
exit($failed ? 1 : 0);
