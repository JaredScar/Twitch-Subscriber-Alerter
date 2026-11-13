<?php
declare(strict_types=1);

/**
 * Preview a custom alert on a 16:9 stage. The owner and site admins may preview any status
 * (so a pending submission can be checked before it is reviewed); everyone else only approved public alerts.
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\CustomAlerts;
use App\Http;
use App\View;

Http::securityHeaders();
$user = Auth::requireUser();

$id = (isset($_GET['id']) && ctype_digit((string) $_GET['id'])) ? (int) $_GET['id'] : 0;
$alert = $id ? CustomAlerts::find($id) : null;
$allowed = $alert && (
    (int) $alert['owner_id'] === (int) $user['id']
    || (int) $user['is_admin'] === 1
    || ($alert['status'] === 'approved' && $alert['visibility'] === 'public')
);
if (!$allowed) {
    Http::fail(404, 'Alert not found');
}

View::render('preview', [
    'title' => 'Preview: ' . $alert['name'],
    'nav' => (int) $alert['owner_id'] === (int) $user['id'] ? 'alerts' : ((int) $user['is_admin'] === 1 ? 'review' : 'alerts'),
    'alert' => $alert,
    'descriptor' => CustomAlerts::descriptor($alert, null),
    'volume' => (int) (\App\Users::settings((int) $user['id'])['volume'] ?? 80),
    'isAdmin' => (int) $user['is_admin'] === 1,
]);
