<?php
declare(strict_types=1);

/**
 * Serves the files of custom alerts. They live outside the web root, so every byte goes through here.
 *
 *   media.php?a=<alert id>&f=media|sound|html&key=<overlay key>     overlay (OBS) access
 *   media.php?a=<alert id>&f=media|sound|html                       logged-in access (preview)
 *
 * Who may fetch what:
 *   overlay key  - only alerts that are approved and the streamer's own or public (so nothing unvetted goes live)
 *   session      - the owner and site admins (any status, so submissions can be previewed and reviewed),
 *                  plus everyone for approved public alerts
 * Anything else is a plain 404 so ids cannot be probed.
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\CustomAlerts;
use App\Users;

function not_found(): never
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo "Not found\n";
    exit;
}

$id = (isset($_GET['a']) && ctype_digit((string) $_GET['a'])) ? (int) $_GET['a'] : 0;
$which = (string) ($_GET['f'] ?? '');
if ($id < 1 || !in_array($which, ['media', 'sound', 'html'], true)) {
    not_found();
}
$alert = CustomAlerts::find($id);
if (!$alert) {
    not_found();
}

$approved = $alert['status'] === 'approved';
if (isset($_GET['key'])) {
    $viewer = Users::findByOverlayKey((string) $_GET['key']);
    $allowed = $viewer && !(int) $viewer['is_disabled'] && $approved
        && ((int) $alert['owner_id'] === (int) $viewer['id'] || $alert['visibility'] === 'public');
} else {
    $viewer = Auth::user();
    $allowed = $viewer && (
        (int) $alert['owner_id'] === (int) $viewer['id']
        || (int) $viewer['is_admin'] === 1
        || ($approved && $alert['visibility'] === 'public')
    );
    session_write_close();   // do not hold the session lock while streaming a file
}
if (!$allowed) {
    not_found();
}
$meta = CustomAlerts::fileMeta($alert, $which);
if (!$meta) {
    not_found();
}

header('Content-Type: ' . $meta['mime']);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cross-Origin-Resource-Policy: same-origin');
header('Cache-Control: private, max-age=300');
header('Accept-Ranges: bytes');
if ($which === 'html') {
    // The page is untrusted code. `sandbox` (without allow-same-origin) gives it a unique origin even if opened
    // directly, so it can never touch this site's cookies or DOM. It may run scripts and animate, but it cannot
    // load anything from the network or navigate anywhere meaningful: assets must be inlined as data: URIs.
    header("Content-Security-Policy: sandbox allow-scripts; default-src 'none'; script-src 'unsafe-inline'; "
        . "style-src 'unsafe-inline'; img-src data: blob:; media-src data: blob:; font-src data:; connect-src 'none'; "
        . "frame-src 'none'; object-src 'none'; form-action 'none'; base-uri 'none'; frame-ancestors 'self'");
    header('X-Frame-Options: SAMEORIGIN');
} else {
    header("Content-Security-Policy: default-src 'none'; sandbox");
}

$size = $meta['size'];
$start = 0;
$end = $size - 1;
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $m) && ($m[1] !== '' || $m[2] !== '')) {
    if ($m[1] === '') {
        $start = max(0, $size - (int) $m[2]);
    } else {
        $start = (int) $m[1];
        if ($m[2] !== '') {
            $end = min($end, (int) $m[2]);
        }
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header("Content-Range: bytes */$size");
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: ' . ($end - $start + 1));

$fh = fopen($meta['path'], 'rb');
if ($fh === false) {
    not_found();
}
fseek($fh, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fh)) {
    $chunk = fread($fh, min(65536, $left));
    if ($chunk === false || $chunk === '') {
        break;
    }
    echo $chunk;
    $left -= strlen($chunk);
}
fclose($fh);
