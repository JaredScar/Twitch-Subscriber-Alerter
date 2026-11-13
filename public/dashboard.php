<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\AlertCatalog;
use App\Auth;
use App\Config;
use App\CustomAlerts;
use App\Events;
use App\Http;
use App\Twitch;
use App\Users;
use App\View;

Http::securityHeaders();
$user = Auth::requireUser();
$uid = (int) $user['id'];

$base = Config::get('APP_URL') ? Config::appUrl() : ((Auth::isHttps() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));

$custom = [];
foreach (CustomAlerts::addedFor($uid) as $a) {
    $custom[CustomAlerts::alertId((int) $a['id'])] = $a;
}
$events = Events::recent($uid, 25);
$labels = CustomAlerts::labels(array_column($events, 'alert_id'));
foreach (AlertCatalog::all() as $id => $a) {
    $labels[$id] = $a['label'];
}

View::render('dashboard', [
    'title' => 'Dashboard',
    'nav' => 'dashboard',
    'user' => $user,
    'settings' => Users::settings($uid),
    'rules' => Users::rules($uid),
    'catalog' => AlertCatalog::all(),
    'custom' => $custom,
    'labels' => $labels,
    'statuses' => Twitch::storedStatus($uid),
    'eventTypes' => array_keys(Twitch::EVENT_TYPES),
    'events' => $events,
    'overlaySeenAt' => Users::overlaySeenAt($uid),
    'overlayUrl' => $base . '/overlay.php?key=' . $user['overlay_key'],
    'previewBase' => $base . '/alerts/',
    'flash' => Auth::takeFlash(),
]);
