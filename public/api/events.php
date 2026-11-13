<?php
declare(strict_types=1);

/** Session-authenticated JSON feed of the streamer's recent events (dashboard auto-refresh). */

require __DIR__ . '/../../src/bootstrap.php';

use App\AlertCatalog;
use App\Auth;
use App\CustomAlerts;
use App\Events;
use App\Http;
use App\Users;

header('X-Content-Type-Options: nosniff');
$user = Auth::user();
if (!$user) {
    Http::json(['error' => 'not logged in'], 401);
}
session_write_close();   // do not hold the session lock while polling

$uid = (int) $user['id'];
$rows = Events::recent($uid, 25);
$labels = CustomAlerts::labels(array_column($rows, 'alert_id'));
foreach (AlertCatalog::all() as $id => $a) {
    $labels[$id] = $a['label'];
}

Http::json([
    'events' => array_map(static function (array $r) use ($labels): array {
        return [
            'id' => (int) $r['id'],
            'type' => $r['type'],
            'actor' => $r['actor'],
            'count' => $r['gift_count'] !== null ? (int) $r['gift_count'] : null,
            'months' => $r['months'] !== null ? (int) $r['months'] : null,
            'alert' => $r['alert_id'],
            'label' => $r['alert_id'] ? ($labels[$r['alert_id']] ?? $r['alert_id']) : null,
            'note' => $r['note'],
            'at' => $r['created_at'],
        ];
    }, $rows),
    'overlay_seen_at' => Users::overlaySeenAt($uid),
    'now' => gmdate('Y-m-d H:i:s'),
]);
