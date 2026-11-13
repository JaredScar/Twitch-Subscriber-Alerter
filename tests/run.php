<?php
declare(strict_types=1);

/**
 * Dependency-free unit tests (no database / network):   php tests/run.php
 */

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/src/Config.php';
require APP_ROOT . '/src/AlertCatalog.php';
require APP_ROOT . '/src/CustomAlerts.php';
require APP_ROOT . '/src/EventSub.php';
require APP_ROOT . '/src/Database.php';

use App\AlertCatalog;
use App\Config;
use App\EventSub;

$failures = 0;
$total = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $total;
    $total++;
    if (!$ok) {
        $failures++;
        echo "FAIL  $name" . ($detail !== '' ? "  ($detail)" : '') . "\n";
    } else {
        echo "ok    $name\n";
    }
}

/* ---- gift count -> alert mapping ---------------------------------------------------------- */
putenv('ALERT_CATALOG=tests/fixtures/alert-catalog.json');   // the real alert pack is not in the repository
$rules = AlertCatalog::defaultRules();
$cases = [1 => null, 4 => null, 5 => 'gifted-005', 9 => 'gifted-005', 10 => 'gifted-010', 19 => 'gifted-010',
    20 => 'gifted-020', 49 => 'gifted-020', 50 => 'gifted-050', 99 => 'gifted-050', 100 => 'gifted-100', 5000 => 'gifted-100'];
foreach ($cases as $n => $want) {
    $got = AlertCatalog::resolveGift($n, $rules);
    check("default mapping: $n gifts", $got === $want, 'got ' . var_export($got, true));
}

$custom = $rules;
$custom['gifted-010']['enabled'] = 0;                      // disabled tier is skipped
check('disabled tier falls back to the lower alert', AlertCatalog::resolveGift(12, $custom) === 'gifted-005');
$custom['gifted-005']['min_count'] = 1;                    // customised threshold
check('custom threshold: 1 gift plays the 5 alert', AlertCatalog::resolveGift(1, $custom) === 'gifted-005');
$tie = $rules;
$tie['gifted-010']['min_count'] = 20;                      // tie with gifted-020 -> later alert wins
check('tie goes to the later alert', AlertCatalog::resolveGift(25, $tie) === 'gifted-020');
$none = $rules;
foreach ($none as &$r) { $r['enabled'] = 0; }
unset($r);
check('all disabled plays nothing', AlertCatalog::resolveGift(500, $none) === null);
check('catalog ids exist', AlertCatalog::exists('gifted-100') && !AlertCatalog::exists('nope') && !AlertCatalog::exists(null));

/* ---- every alert in the catalog ships its assets ---------------------------------------------- */
foreach (AlertCatalog::all() as $id => $a) {
    foreach (["public/alerts/data/$id.js", "public/alerts/audio/$id.ogg", 'public/alerts/' . $a['page']] as $f) {
        check("asset present: $f", is_file(APP_ROOT . '/' . $f));
    }
}

/* ---- EventSub signature verification --------------------------------------------------------- */
$secret = 'unit-test-secret-0123456789';
$body = '{"subscription":{"type":"channel.subscription.gift"},"event":{"total":10}}';
$id = 'abc-123';
$ts = gmdate('Y-m-d\TH:i:s\Z');
$headers = [
    'twitch-eventsub-message-id' => $id,
    'twitch-eventsub-message-timestamp' => $ts,
    'twitch-eventsub-message-signature' => EventSub::signature($id, $ts, $body, $secret),
    'twitch-eventsub-message-type' => 'webhook_callback_verification',
];
check('valid signature accepted', EventSub::verify($headers, $body, $secret));
check('tampered body rejected', !EventSub::verify($headers, $body . ' ', $secret));
check('wrong secret rejected', !EventSub::verify($headers, $body, 'another-secret-0123456789'));
$bad = $headers;
unset($bad['twitch-eventsub-message-signature']);
check('missing signature rejected', !EventSub::verify($bad, $body, $secret));
$old = $headers;
$oldTs = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);
$old['twitch-eventsub-message-timestamp'] = $oldTs;
$old['twitch-eventsub-message-signature'] = EventSub::signature($id, $oldTs, $body, $secret);
check('stale timestamp rejected (replay)', !EventSub::verify($old, $body, $secret));

// verification handshake echoes the challenge (no database needed for this message type)
$challengeBody = '{"challenge":"pogchamp-kappa-360noscope","subscription":{"id":"x","status":"webhook_callback_verification_pending"}}';
$h2 = $headers;
$h2['twitch-eventsub-message-signature'] = EventSub::signature($id, $ts, $challengeBody, $secret);
[$status, $out, $type] = EventSub::handle($h2, $challengeBody, $secret);
check('handshake returns 200 + challenge', $status === 200 && $out === 'pogchamp-kappa-360noscope' && $type === 'text/plain');
[$status] = EventSub::handle($headers, $challengeBody, $secret);   // signature belongs to another body
check('handshake with bad signature is 403', $status === 403);
[$status] = EventSub::handle($h2, str_repeat('a', EventSub::MAX_BODY + 1), $secret);
check('oversized body is 413', $status === 413);

/* ---- .env parsing -------------------------------------------------------------------------------- */
$env = Config::parse("# comment\nA=1\nB = two words \nC=\"quoted # not comment\"\nD=plain # trailing\nE='single'\n\nBAD LINE\n");
check('env: simple', ($env['A'] ?? null) === '1');
check('env: trims', ($env['B'] ?? null) === 'two words');
check('env: quoted keeps #', ($env['C'] ?? null) === 'quoted # not comment');
check('env: strips trailing comment', ($env['D'] ?? null) === 'plain');
check('env: single quotes', ($env['E'] ?? null) === 'single');

Config::set(['APP_SECRET' => 'x', 'ADMIN_TWITCH_LOGINS' => ' Foo, 12345 ,']);
check('admin identifiers lower-cased', Config::adminIdentifiers() === ['foo', '12345']);
require_once APP_ROOT . '/src/Users.php';
Config::set(['ADMIN_TWITCH_LOGINS' => ' Foo, 12345 ,', 'RESTRICT_LOGIN_TO_ADMINS' => '']);
check('login is open unless restricted', !Config::restrictLoginToAdmins() && \App\Users::mayLogin('1', 'anyone'));
foreach (['true', 'TRUE', '1', 'yes', ' on '] as $v) {
    Config::set(['APP_SECRET' => 'x', 'ADMIN_TWITCH_LOGINS' => ' Foo, 12345 ,', 'RESTRICT_LOGIN_TO_ADMINS' => $v]);
    check("RESTRICT_LOGIN_TO_ADMINS='$v' turns the restriction on", Config::restrictLoginToAdmins());
}
foreach (['false', '0', 'no', '', 'maybe'] as $v) {
    Config::set(['RESTRICT_LOGIN_TO_ADMINS' => $v]);
    check("RESTRICT_LOGIN_TO_ADMINS='$v' leaves login open", !Config::restrictLoginToAdmins());
}
Config::set(['ADMIN_TWITCH_LOGINS' => ' Foo, 12345 ,', 'RESTRICT_LOGIN_TO_ADMINS' => 'true']);
check('restricted: admin login (any case) or numeric id may log in', \App\Users::mayLogin('9', 'FOO') && \App\Users::mayLogin('12345', 'someone'));
check('restricted: everyone else is refused', !\App\Users::mayLogin('9', 'stranger') && !\App\Users::mayLogin('123456', 'foo2'));
Config::set(['ADMIN_TWITCH_LOGINS' => '', 'RESTRICT_LOGIN_TO_ADMINS' => 'true']);
check('restricted with an empty admin list refuses everyone (fails closed)', !\App\Users::mayLogin('1', 'anyone'));
Config::set(['APP_SECRET' => str_repeat('k', 32), 'ADMIN_TWITCH_LOGINS' => ' Foo, 12345 ,']);
check('webhook secret is 64 hex chars', (bool) preg_match('/^[a-f0-9]{64}$/', Config::webhookSecret()));
Config::set(['APP_SECRET' => 'short']);
$threw = false;
try { Config::webhookSecret(); } catch (RuntimeException) { $threw = true; }
check('a short APP_SECRET is refused', $threw);

// cPanel install script: import this in phpMyAdmin after the panel has created the database
$installSql = (string) file_get_contents(APP_ROOT . '/database/install.sql');
foreach (['users', 'user_settings', 'alert_rules', 'eventsub_subscriptions', 'events', 'custom_alerts', 'overlay_seen', 'app_state'] as $table) {
    check("install.sql creates $table", str_contains($installSql, "CREATE TABLE IF NOT EXISTS $table ("));
}
check('install.sql sets the play mode column', str_contains($installSql, 'play_mode'));
check('install.sql does not create a database (cPanel does that)', !preg_match('/CREATE\s+DATABASE/i', $installSql));
check('install.sql comments contain no semicolons (the importer splits on them)', !preg_match('/^\\s*--.*;/m', $installSql));

// play mode: how alerts that arrive close together play
check('play mode: queue and stack are the only modes', \App\Users::PLAY_MODES === ['queue', 'stack']);
check('play mode: stack is kept', \App\Users::playMode(['play_mode' => 'stack']) === 'stack');
check('play mode: queue is kept', \App\Users::playMode(['play_mode' => 'queue']) === 'queue');
check('play mode: a settings row from before the column existed means queue', \App\Users::playMode([]) === 'queue');
foreach ([null, '', 'STACK', 'stack ', 'parallel', 1, ['stack']] as $junk) {
    check('play mode: ' . json_encode($junk) . ' falls back to queue', \App\Users::playMode(['play_mode' => $junk]) === 'queue');
}

// optional alert pack
putenv('ALERT_CATALOG=tests/fixtures/missing.json');
AlertCatalog::reset();
check('alert pack: no catalog file = no built-in alerts', AlertCatalog::all() === [] && AlertCatalog::defaultId() === null && AlertCatalog::defaultRules() === []);
check('alert pack: custom alerts still resolve without a pack', AlertCatalog::resolveGift(9, ['custom-4' => ['enabled' => 1, 'min_count' => 5]]) === 'custom-4');
$cat = tempnam(sys_get_temp_dir(), 'cat');
file_put_contents($cat, json_encode(['alerts' => ['ok-1' => ['label' => 'Fine', 'min' => 3, 'page' => 'ok.html'], 'custom-9' => ['min' => 1],
    '../evil' => ['min' => 1], 'Bad Id' => ['min' => 1], 'no-page' => ['min' => 0, 'page' => '../x.html']]]));
putenv("ALERT_CATALOG=$cat");
AlertCatalog::reset();
check('alert pack: invalid ids skipped, custom-N namespace reserved', AlertCatalog::ids() === ['ok-1', 'no-page'], json_encode(AlertCatalog::ids()));
check('alert pack: values sanitised', AlertCatalog::all()['no-page']['page'] === null && AlertCatalog::all()['no-page']['min'] === 1 && AlertCatalog::defaultId() === 'ok-1');
@unlink($cat);
putenv('ALERT_CATALOG=tests/fixtures/alert-catalog.json');
AlertCatalog::reset();

$envFile = tempnam(sys_get_temp_dir(), 'tsa-env');
file_put_contents($envFile, "DB_HOST=from-file\n");
Config::load($envFile);
Config::set([]);
putenv('DB_HOST=from-env');
check('process env overrides the .env file', Config::get('DB_HOST') === 'from-env');
putenv('DB_HOST=');
check('empty process env falls back to .env', Config::get('DB_HOST') === 'from-file');
Config::set(['DB_HOST' => 'from-set']);
putenv('DB_HOST=from-env');
check('Config::set overrides the process env', Config::get('DB_HOST') === 'from-set');
putenv('DB_HOST');
Config::set([]);
Config::load(APP_ROOT . '/.env.missing');
@unlink($envFile);

/* ------------------------------------------------------------------ custom alerts (pure parts) */
use App\CustomAlerts;

check('alertId / parseId round-trip', CustomAlerts::alertId(42) === 'custom-42' && CustomAlerts::parseId('custom-42') === 42);
check('parseId rejects junk', CustomAlerts::parseId('custom-0') === null && CustomAlerts::parseId('custom-01') === null
    && CustomAlerts::parseId('custom-1; DROP') === null && CustomAlerts::parseId('gifted-005') === null && CustomAlerts::parseId(null) === null
    && CustomAlerts::parseId('custom-' . str_repeat('9', 12)) === null && CustomAlerts::parseId('../custom-1') === null);
check('isCustomId matches the same shape', AlertCatalog::isCustomId('custom-7') && !AlertCatalog::isCustomId('custom-x') && !AlertCatalog::isCustomId('gifted-010'));
check('cleanName tidies whitespace and control characters', CustomAlerts::cleanName("  My \t cool\nalert \x00 ") === 'My cool alert');
$threw = false;
try { CustomAlerts::cleanName('ab'); } catch (InvalidArgumentException) { $threw = true; }
check('cleanName rejects short names', $threw);
check('cleanName caps the length', mb_strlen(CustomAlerts::cleanName(str_repeat('é', 200))) === 60);

$c = CustomAlerts::sanitizeMediaConfig([]);
check('media config defaults', $c['position'] === 'c' && $c['animation'] === 'pop' && $c['textPos'] === 'below' && $c['duration'] === 8
    && $c['fontSize'] === 56 && $c['width'] === 40 && $c['textColor'] === '#ffffff' && $c['text'] === CustomAlerts::DEFAULT_TEXT, json_encode($c));
$c = CustomAlerts::sanitizeMediaConfig(['position' => 'nope', 'animation' => '<script>', 'textPos' => 'x', 'duration' => '-5', 'fontSize' => '1', 'width' => '500',
    'textColor' => 'red;background:url(x)', 'text' => str_repeat('a', 500)]);
check('media config clamps and whitelists', $c['position'] === 'c' && $c['animation'] === 'pop' && $c['textPos'] === 'below' && $c['duration'] === 1
    && $c['fontSize'] === 16 && $c['width'] === 100 && $c['textColor'] === '#ffffff' && mb_strlen($c['text']) === 120, json_encode($c));
$c = CustomAlerts::sanitizeMediaConfig(['textPos' => 'none', 'text' => '', 'textColor' => '#AbCdEf']);
check('no text is allowed when the text is hidden; colours are lower-cased', $c['text'] === '' && $c['textColor'] === '#abcdef');

$scan = CustomAlerts::scanHtml('<html><body>hi</body></html>');
check('scan: clean html has no flags', $scan['flags'] === [] && $scan['urls'] === []);
$scan = CustomAlerts::scanHtml('<script src="https://evil.example/x.js"></script><script>fetch("//e.example/a");eval("1");location.href="http://x.example"</script><iframe src=a></iframe><form></form><meta http-equiv="refresh" content="0;url=x">');
check('scan flags script src, network, eval, navigation, iframe, form and meta refresh', count($scan['flags']) === 7 && in_array('iframe', $scan['flags'], true), json_encode($scan['flags']));
check('scan lists external urls (deduplicated, capped)', in_array('https://evil.example/x.js', $scan['urls'], true) && count($scan['urls']) <= 8, json_encode($scan['urls']));
check('scan ignores namespace urls', CustomAlerts::scanHtml('<svg xmlns="http://www.w3.org/2000/svg"></svg>')['urls'] === []);

$dir = sys_get_temp_dir() . '/tsa-unit-' . bin2hex(random_bytes(4));
mkdir($dir);
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$wav = 'RIFF' . pack('V', 36 + 800) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8) . 'data' . pack('V', 800) . str_repeat("\x80", 800);
$file = static function (string $name, string $bytes) use ($dir): string {
    file_put_contents("$dir/$name", $bytes);
    return "$dir/$name";
};
$rejects = static function (callable $fn): bool {
    try { $fn(); } catch (InvalidArgumentException) { return true; }
    return false;
};
if (class_exists('finfo')) {
    $r = CustomAlerts::inspectFile($file('ok.png', $png), 'media');
    check('inspect: real PNG accepted, size read', $r['ext'] === 'png' && $r['type'] === 'image' && $r['width'] === 1 && $r['height'] === 1);
    check('inspect: the extension comes from the content, not the name', CustomAlerts::inspectFile($file('wrong.gif', $png), 'media')['ext'] === 'png');
    check('inspect: php disguised as an image is refused', $rejects(fn() => CustomAlerts::inspectFile($file('x.png', '<?php system($_GET[0]);'), 'media')));
    check('inspect: svg is refused (scriptable)', $rejects(fn() => CustomAlerts::inspectFile($file('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>1</script></svg>'), 'media')));
    check('inspect: empty file refused', $rejects(fn() => CustomAlerts::inspectFile($file('empty.png', ''), 'media')));
    check('inspect: WAV accepted as sound', CustomAlerts::inspectFile($file('s.wav', $wav), 'sound')['ext'] === 'wav');
    check('inspect: an image is not a sound', $rejects(fn() => CustomAlerts::inspectFile($file('i.mp3', $png), 'sound')));
    check('inspect: HTML accepted and scanned', CustomAlerts::inspectFile($file('a.html', '<!doctype html><p>hi</p>'), 'html')['scan']['flags'] === []);
    check('inspect: non-text "HTML" refused', $rejects(fn() => CustomAlerts::inspectFile($file('b.html', "\x00\x01\x02 binary"), 'html')));
    check('inspect: plain text without tags refused as HTML', $rejects(fn() => CustomAlerts::inspectFile($file('c.html', 'just words'), 'html')));
    check('inspect: oversized sound refused', $rejects(fn() => CustomAlerts::inspectFile($file('big.wav', $wav . str_repeat("\x80", CustomAlerts::SOUND_MAX)), 'sound')));
} else {
    echo "skip  inspectFile tests (fileinfo extension not loaded)\n";
}
foreach (glob("$dir/*") ?: [] as $f) { @unlink($f); }
@rmdir($dir);

check('formatBytes', CustomAlerts::formatBytes(500) === '1 KB' && CustomAlerts::formatBytes(2048) === '2 KB' && CustomAlerts::formatBytes(3 * 1048576) === '3 MB');

$rules = ['gifted-005' => ['enabled' => 1, 'min_count' => 5], 'custom-3' => ['enabled' => 1, 'min_count' => 5], 'custom-9' => ['enabled' => 1, 'min_count' => 25],
    'gifted-020' => ['enabled' => 1, 'min_count' => 20]];
check('resolveGift: a tie goes to the later rule (custom after built-in)', AlertCatalog::resolveGift(6, $rules) === 'custom-3');
check('resolveGift: highest minimum wins, custom or built-in', AlertCatalog::resolveGift(30, $rules) === 'custom-9' && AlertCatalog::resolveGift(22, $rules) === 'gifted-020');
check('resolveGift: nothing below every minimum', AlertCatalog::resolveGift(4, $rules) === null);
$rules['custom-9']['enabled'] = 0;
check('resolveGift: disabled custom rules are skipped', AlertCatalog::resolveGift(30, $rules) === 'gifted-020');
check('resolveGift: junk ids in rules are ignored', AlertCatalog::resolveGift(30, ['evil' => ['enabled' => 1, 'min_count' => 1], 'custom-x' => ['enabled' => 1, 'min_count' => 1]]) === null);

echo "\n" . ($total - $failures) . "/$total passed\n";
exit($failures ? 1 : 0);
