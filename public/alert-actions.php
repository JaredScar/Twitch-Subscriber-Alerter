<?php
declare(strict_types=1);

/**
 * Form posts for custom alerts: submit / delete / use (streamer) and approve / reject / revoke (admin).
 * Every one needs POST, a session and a CSRF token. Submissions are multipart.
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\CustomAlerts;
use App\Http;

Http::securityHeaders();
Http::requirePost();
$user = Auth::requireUser();
$uid = (int) $user['id'];

// A body bigger than post_max_size arrives as an empty $_POST: say so instead of "session expired".
if (!$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    Auth::flash('error', 'That upload was too large. Keep it under ' . ini_get('upload_max_filesize') . ' in total.');
    Http::redirect('my-alerts.php');
}
Auth::checkCsrf();

$action = (string) ($_POST['action'] ?? '');
$alertId = (isset($_POST['alert_id']) && ctype_digit((string) $_POST['alert_id'])) ? (int) $_POST['alert_id'] : 0;
$back = str_starts_with($action, 'admin_') ? 'admin-alerts.php' : 'my-alerts.php';

try {
    switch ($action) {
        case 'submit':
            if ((int) $user['is_disabled']) {
                throw new InvalidArgumentException('Your account is disabled.');
            }
            CustomAlerts::submit($uid, $_POST, $_FILES);
            Auth::flash('ok', 'Submitted! It is now waiting for review and will not play on stream until it has been approved.');
            Http::redirect('my-alerts.php');

        case 'delete':
            $alert = $alertId ? CustomAlerts::find($alertId) : null;
            if (!$alert || (int) $alert['owner_id'] !== $uid) {
                throw new InvalidArgumentException('Alert not found.');
            }
            CustomAlerts::delete($alertId);
            Auth::flash('ok', '"' . $alert['name'] . '" was deleted.');
            Http::redirect('my-alerts.php');

        case 'use':
            CustomAlerts::useAlert($uid, $alertId);
            Auth::flash('ok', 'Added to your alerts. It is in your gift rules at ' . CustomAlerts::DEFAULT_MIN . '+ gifted subs: adjust it, or pick it for new subs and resubs, on the dashboard.');
            Http::redirect('my-alerts.php');

        case 'unuse':
            CustomAlerts::stopUsing($uid, $alertId);
            Auth::flash('ok', 'Removed from your alerts.');
            Http::redirect('my-alerts.php');

        /* ---------------------------------------------------------- site owner */
        case 'admin_approve':
        case 'admin_reject':
        case 'admin_revoke':
        case 'admin_delete':
            Auth::requireAdmin();
            $alert = $alertId ? CustomAlerts::find($alertId) : null;
            if (!$alert) {
                throw new InvalidArgumentException('Alert not found.');
            }
            if ($action === 'admin_approve') {
                CustomAlerts::approve($alertId, $uid, (string) ($_POST['visibility'] ?? ''), $_POST['note'] ?? null);
                Auth::flash('ok', '"' . $alert['name'] . '" approved (' . $_POST['visibility'] . ').');
            } elseif ($action === 'admin_delete') {
                CustomAlerts::delete($alertId);
                Auth::flash('ok', '"' . $alert['name'] . '" deleted.');
            } else {
                CustomAlerts::reject($alertId, $uid, (string) ($_POST['note'] ?? ''));
                Auth::flash('ok', '"' . $alert['name'] . '" ' . ($action === 'admin_revoke' ? 'taken offline' : 'rejected') . '.');
            }
            Http::redirect('admin-alerts.php');

        default:
            Auth::flash('error', 'Unknown action.');
            Http::redirect('my-alerts.php');
    }
} catch (InvalidArgumentException $e) {
    Auth::flash('error', $e->getMessage());
    Http::redirect($back);
} catch (Throwable $e) {
    error_log('alert action ' . $action . ': ' . $e->getMessage());
    Auth::flash('error', 'Something went wrong. Please try again.');
    Http::redirect($back);
}
