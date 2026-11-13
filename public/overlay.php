<?php
declare(strict_types=1);

/**
 * OBS Browser Source: https://<site>/overlay.php?key=<overlay key>
 * Transparent page that polls for events and plays the matching alert (built-in canvas alerts,
 * or approved custom alerts).
 */

require __DIR__ . '/../src/bootstrap.php';

use App\AlertCatalog;
use App\Http;
use App\Users;
use App\View;

$key = (string) ($_GET['key'] ?? '');
$user = Users::findByOverlayKey($key);

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
// frame-src: custom HTML alerts run in a sandboxed iframe served by media.php (same origin).
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; media-src 'self'; style-src 'self'; script-src 'self'; connect-src 'self'; frame-src 'self'; frame-ancestors 'none'; base-uri 'none'");

if (!$user) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Unknown overlay key. Copy the Browser Source URL from your dashboard.\n";
    exit;
}

$alerts = array_keys(AlertCatalog::all());
$ver = '4';   // bump to bust caches of the alert assets after an update
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Subscriber alerts</title>
<link rel="stylesheet" href="assets/css/overlay.css?v=<?= $ver ?>">
<link rel="stylesheet" href="assets/css/custom-alert.css?v=<?= $ver ?>">
</head>
<body data-key="<?= View::e($key) ?>" data-poll="api/poll.php" data-interval="1500">
<canvas id="stage" width="1920" height="1080"></canvas>
<div id="vig"></div>
<div id="custom"></div>
<div id="status" hidden></div>
<?php if ($alerts): ?>
<script src="alerts/engine.js?v=1"></script>
<?php foreach ($alerts as $id): ?>
<script src="alerts/data/<?= View::e($id) ?>.js?v=1"></script>
<?php endforeach; ?>
<?php endif; ?>
<script src="assets/js/custom-player.js?v=<?= $ver ?>"></script>
<script src="assets/js/overlay.js?v=<?= $ver ?>"></script>
</body>
</html>
