<?php
declare(strict_types=1);

/**
 * Dev only: fills a throw-away demo site (see tests/demo-server.sh) with a streamer, an admin and a few
 * custom alerts in different states, then writes session cookies to work/ so a headless browser can look around.
 *
 *   php tests/seed-demo.php http://127.0.0.1:8099 http://127.0.0.1:8098
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Database;

$app = rtrim($argv[1], '/');
$fake = rtrim($argv[2], '/');
if (!str_contains((string) \App\Config::get('DB_NAME'), 'demo')) {
    fwrite(STDERR, "DB_NAME must contain 'demo'\n");
    exit(2);
}
foreach (['events', 'eventsub_subscriptions', 'alert_rules', 'user_settings', 'custom_alerts', 'overlay_seen', 'users', 'app_state'] as $t) {
    Database::run("DELETE FROM `$t`");
}
http('GET', "$fake/reset");

function http(string $method, string $url, array $o = []): array
{
    $ch = curl_init($url);
    $headers = [];
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20,
        CURLOPT_HEADERFUNCTION => static function ($c, $l) use (&$headers) {
            if (str_contains($l, ':')) { [$k, $v] = explode(':', $l, 2); $headers[strtolower(trim($k))] = trim($v); }
            return strlen($l);
        }]);
    if (isset($o['jar'])) { curl_setopt($ch, CURLOPT_COOKIEJAR, $o['jar']); curl_setopt($ch, CURLOPT_COOKIEFILE, $o['jar']); }
    if (isset($o['post'])) { curl_setopt($ch, CURLOPT_POSTFIELDS, $o['post']); }
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'headers' => $headers, 'body' => $body];
}

function login(string $app, string $code, string $jar): void
{
    $r = http('GET', "$app/login.php", ['jar' => $jar]);
    parse_str((string) parse_url($r['headers']['location'] ?? '', PHP_URL_QUERY), $q);
    http('GET', "$app/callback.php?code=$code&state=" . ($q['state'] ?? ''), ['jar' => $jar]);
}

function csrf(string $app, string $jar): string
{
    preg_match('/name="_csrf" value="([a-f0-9]+)"/', http('GET', "$app/dashboard.php", ['jar' => $jar])['body'], $m);
    return $m[1] ?? '';
}

function cookieValue(string $jar): string
{
    foreach (file($jar) ?: [] as $line) {
        $f = explode("\t", rtrim(ltrim($line, '#HttpOnly_'), "\n"));
        if (count($f) >= 7 && $f[5] !== '') { return $f[5] . '=' . $f[6]; }
    }
    return '';
}

$tmp = sys_get_temp_dir() . '/demo-' . bin2hex(random_bytes(3));
mkdir($tmp);
// a 400x300 gradient "image" made with GD-free raw PNG would be heavy: use the tiny pixel, scaled by the player
file_put_contents("$tmp/a.png", base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
file_put_contents("$tmp/a.html", '<!doctype html><body style="margin:0"><div style="position:fixed;inset:0;display:grid;place-items:center;color:#fff;font:900 8vw sans-serif;text-shadow:0 0 2vw #f0f">HTML ALERT</div></body>');

$jar1 = "$tmp/j1";
$jar2 = "$tmp/j2";
login($app, 'good-code', $jar1);
login($app, 'admin-code', $jar2);
$t1 = csrf($app, $jar1);
$t2 = csrf($app, $jar2);
$u1 = Database::one("SELECT * FROM users WHERE login = 'streamer_one'");

$submit = static fn(array $f) => http('POST', "$app/alert-actions.php", ['jar' => $GLOBALS['jar1'], 'post' => ['_csrf' => $GLOBALS['t1'], 'action' => 'submit'] + $f]);
$submit(['name' => 'Confetti burst', 'description' => 'Pixel party', 'kind' => 'media', 'text' => '{name} gifted {count} subs!', 'media' => new CURLFile("$tmp/a.png")]);
$submit(['name' => 'Neon HTML welcome', 'description' => 'Pure HTML, no network', 'kind' => 'html', 'html' => new CURLFile("$tmp/a.html")]);
$submit(['name' => 'Disco ball', 'kind' => 'media', 'media' => new CURLFile("$tmp/a.png")]);
$submit(['name' => 'Spooky ghost', 'kind' => 'media', 'media' => new CURLFile("$tmp/a.png")]);
$ids = array_column(Database::all('SELECT id, name FROM custom_alerts'), 'id', 'name');
$act = static fn(string $jar, string $t, array $f) => http('POST', "$GLOBALS[app]/alert-actions.php", ['jar' => $jar, 'post' => ['_csrf' => $t] + $f]);
$act($jar2, $t2, ['action' => 'admin_approve', 'alert_id' => $ids['Confetti burst'], 'visibility' => 'public', 'note' => 'Nice one']);
$act($jar2, $t2, ['action' => 'admin_approve', 'alert_id' => $ids['Neon HTML welcome'], 'visibility' => 'private']);
$act($jar2, $t2, ['action' => 'admin_reject', 'alert_id' => $ids['Spooky ghost'], 'note' => 'Contains copyrighted music']);
$act($jar1, $t1, ['action' => 'use', 'alert_id' => $ids['Confetti burst']]);
http('POST', "$app/actions.php", ['jar' => $jar1, 'post' => ['_csrf' => $t1, 'action' => 'test', 'alert_id' => 'gifted-050']]);
http('POST', "$app/actions.php", ['jar' => $jar1, 'post' => ['_csrf' => $t1, 'action' => 'test', 'alert_id' => 'custom-' . $ids['Confetti burst']]]);
Database::run("INSERT INTO overlay_seen (user_id, seen_at) VALUES (?, ?) ON DUPLICATE KEY UPDATE seen_at = VALUES(seen_at)", [$u1['id'], Database::now()]);

@mkdir(APP_ROOT . '/work', 0775, true);
file_put_contents(APP_ROOT . '/work/cookie-streamer.txt', cookieValue($jar1));
file_put_contents(APP_ROOT . '/work/cookie-admin.txt', cookieValue($jar2));
file_put_contents(APP_ROOT . '/work/demo-ids.json', json_encode($ids));
echo "seeded: " . json_encode($ids) . "\n";
