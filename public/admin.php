<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\Database;
use App\Events;
use App\Http;
use App\Twitch;
use App\View;

Http::securityHeaders();
$me = Auth::requireAdmin();

$users = Database::all('SELECT id, twitch_id, login, display_name, profile_image_url, is_admin, is_disabled, created_at, last_login_at FROM users ORDER BY last_login_at DESC');
$subs = [];
foreach (Database::all('SELECT user_id, type, status FROM eventsub_subscriptions') as $r) {
    $subs[(int) $r['user_id']][$r['type']] = $r['status'];
}
$counts = Events::countsByUser();
$lastEvent = [];
foreach (Database::all("SELECT user_id, MAX(created_at) m FROM events WHERE type <> 'test' GROUP BY user_id") as $r) {
    $lastEvent[(int) $r['user_id']] = $r['m'];
}
$overlaySeen = [];
foreach (Database::all('SELECT user_id, seen_at FROM overlay_seen') as $r) {
    $overlaySeen[(int) $r['user_id']] = $r['seen_at'];
}
$customCounts = [];
foreach (Database::all("SELECT owner_id, COUNT(*) c FROM custom_alerts GROUP BY owner_id") as $r) {
    $customCounts[(int) $r['owner_id']] = (int) $r['c'];
}

View::render('admin', [
    'title' => 'Streamers',
    'nav' => 'admin',
    'user' => $me,
    'users' => $users,
    'subs' => $subs,
    'counts' => $counts,
    'lastEvent' => $lastEvent,
    'overlaySeen' => $overlaySeen,
    'customCounts' => $customCounts,
    'eventTypes' => array_keys(Twitch::EVENT_TYPES),
    'callbackUrl' => Twitch::callbackUrl(),
    'flash' => Auth::takeFlash(),
]);
