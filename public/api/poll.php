<?php
declare(strict_types=1);

/**
 * Overlay polling endpoint:  GET /api/poll.php?key=<overlay key>[&after=<event id>]
 *   - without `after` it only returns the current cursor (so a page reload never replays old alerts)
 *   - with `after` it returns events newer than that, plus the streamer's live volume/pause/play-mode settings
 */

require __DIR__ . '/../../src/bootstrap.php';

use App\CustomAlerts;
use App\Events;
use App\Http;
use App\Users;

header('X-Content-Type-Options: nosniff');

$user = Users::findByOverlayKey((string) ($_GET['key'] ?? ''));
if (!$user) {
    Http::json(['error' => 'unknown key'], 404);
}

try {
    $uid = (int) $user['id'];
    $settings = Users::settings($uid);
    $active = !(int) $user['is_disabled'];
    $volume = max(0, min(100, (int) $settings['volume'])) / 100;
    $mode = Users::playMode($settings);   // 'queue' | 'stack', applied by the overlay as it polls

    Users::touchOverlay($uid);   // drives the "overlay connected" indicator on the dashboard

    if (!isset($_GET['after']) || !ctype_digit((string) $_GET['after'])) {
        Http::json(['cursor' => Events::lastId($uid), 'events' => [], 'custom' => new stdClass(), 'volume' => $volume, 'enabled' => $active, 'mode' => $mode]);
    }
    $res = Events::since($uid, (int) $_GET['after']);
    $playable = $active ? CustomAlerts::attach($uid, (string) $user['overlay_key'], $res['events']) : ['events' => [], 'custom' => []];
    Http::json([
        'cursor' => $res['cursor'],
        'events' => $playable['events'],
        'custom' => (object) $playable['custom'],   // descriptors for the custom alerts in `events`
        'volume' => $volume,
        'enabled' => $active,
        'mode' => $mode,
    ]);
} catch (Throwable $e) {
    error_log('poll: ' . $e->getMessage());
    Http::json(['error' => 'server error'], 500);
}
