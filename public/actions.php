<?php
declare(strict_types=1);

/** All state-changing form posts (dashboard + admin). Every one needs a POST, a CSRF token and a session. */

require __DIR__ . '/../src/bootstrap.php';

use App\AlertCatalog;
use App\Auth;
use App\CustomAlerts;
use App\Events;
use App\Http;
use App\Twitch;
use App\Users;

Http::securityHeaders();
Http::requirePost();
Auth::checkCsrf();
$user = Auth::requireUser();
$uid = (int) $user['id'];
$action = (string) ($_POST['action'] ?? '');

$statusText = static function (array $status): string {
    $bad = array_filter($status, static fn($s) => !in_array($s, ['enabled', 'webhook_callback_verification_pending'], true));
    return $bad ? 'Some events were not accepted: ' . implode('; ', $bad) : 'Subscriber events are linked.';
};

try {
    switch ($action) {
        case 'save_settings':
            Users::saveSettings($uid, $_POST);
            Auth::flash('ok', 'Settings saved.');
            Http::redirect('dashboard.php');

        case 'regen_key':
            Users::regenerateOverlayKey($uid);
            Auth::flash('warn', 'New overlay URL generated. Update the Browser Source in OBS - the old URL no longer works.');
            Http::redirect('dashboard.php');

        case 'test':
        case 'test_gift':
            if ((int) $user['is_disabled']) {
                Auth::flash('error', 'Your account is disabled.');
                Http::redirect('dashboard.php');
            }
            if (Events::recentTestCount($uid) >= Events::TEST_LIMIT_PER_MINUTE) {
                Auth::flash('error', 'Slow down a little - too many test alerts in the last minute.');
                Http::redirect('dashboard.php');
            }
            if ($action === 'test') {
                $alertId = (string) ($_POST['alert_id'] ?? '');
                if (!CustomAlerts::usable($uid, $alertId)) {   // built-in, or an approved custom alert of theirs
                    Auth::flash('error', 'Unknown alert.');
                    Http::redirect('dashboard.php');
                }
                $count = AlertCatalog::all()[$alertId]['min'] ?? (Users::rules($uid)[$alertId]['min_count'] ?? CustomAlerts::DEFAULT_MIN);
            } else {
                $count = max(1, min(AlertCatalog::MAX_MIN_COUNT, (int) ($_POST['count'] ?? 0)));
                $alertId = AlertCatalog::resolveGift($count, Users::rules($uid));
                if ($alertId === null) {
                    Auth::flash('warn', "With your current rules, $count gifted subs would not play any alert.");
                    Http::redirect('dashboard.php');
                }
            }
            Events::record($uid, ['type' => 'test', 'actor' => 'Test', 'gift_count' => $count, 'alert_id' => $alertId]);
            $label = AlertCatalog::all()[$alertId]['label'] ?? (CustomAlerts::labels([$alertId])[$alertId] ?? $alertId);
            Auth::flash('ok', 'Test sent: ' . $label . '. It should appear in your overlay within a couple of seconds.');
            Http::redirect('dashboard.php');

        case 'resync':
            Auth::flash('ok', $statusText(Twitch::syncUser($user)));
            Http::redirect('dashboard.php');

        case 'delete_account':
            if (($_POST['confirm'] ?? '') !== 'DELETE') {
                Auth::flash('error', 'Type DELETE to confirm.');
                Http::redirect('dashboard.php');
            }
            Twitch::removeUser($user);
            Users::delete($uid);
            Auth::logout();
            Auth::start();
            Auth::flash('ok', 'Your account and all its data were deleted. You can also revoke the app at twitch.tv/settings/connections.');
            Http::redirect('index.php');

        /* ---------------------------------------------------------- site owner */
        case 'admin_toggle':
        case 'admin_resync':
        case 'admin_delete':
        case 'admin_resync_all':
            Auth::requireAdmin();
            if ($action === 'admin_resync_all') {
                $ok = 0;
                $failed = 0;
                foreach (\App\Database::all('SELECT * FROM users WHERE is_disabled = 0') as $u) {
                    try {
                        Twitch::syncUser($u);
                        $ok++;
                    } catch (Throwable $e) {
                        $failed++;
                        error_log('resync-all user ' . $u['id'] . ': ' . $e->getMessage());
                    }
                }
                Auth::flash($failed ? 'warn' : 'ok', "Re-synced $ok streamer(s)" . ($failed ? ", $failed failed (see log)." : '.'));
                Http::redirect('admin.php');
            }
            $target = Users::find((int) ($_POST['user_id'] ?? 0));
            if (!$target) {
                Auth::flash('error', 'User not found.');
                Http::redirect('admin.php');
            }
            if ((int) $target['id'] === $uid && $action !== 'admin_resync') {
                Auth::flash('error', 'You cannot do that to your own account here.');
                Http::redirect('admin.php');
            }
            if ($action === 'admin_toggle') {
                $disable = !(int) $target['is_disabled'];
                Users::setDisabled((int) $target['id'], $disable);
                Auth::flash('ok', $target['display_name'] . ($disable ? ' disabled.' : ' enabled.'));
            } elseif ($action === 'admin_resync') {
                Auth::flash('ok', $target['display_name'] . ': ' . $statusText(Twitch::syncUser($target)));
            } else {
                Twitch::removeUser($target);
                Users::delete((int) $target['id']);
                Auth::flash('ok', $target['display_name'] . ' deleted.');
            }
            Http::redirect('admin.php');

        default:
            Auth::flash('error', 'Unknown action.');
            Http::redirect('dashboard.php');
    }
} catch (Throwable $e) {
    error_log('action ' . $action . ': ' . $e->getMessage());
    Auth::flash('error', 'Something went wrong. Please try again; if it keeps happening, the site owner can find the details in the error log.');
    Http::redirect(str_starts_with($action, 'admin_') ? 'admin.php' : 'dashboard.php');
}
