<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\AlertCatalog;
use App\Auth;
use App\CustomAlerts;
use App\Http;
use App\View;

Http::securityHeaders();
$user = Auth::requireUser();
$uid = (int) $user['id'];

View::render('my-alerts', [
    'title' => 'Alerts',
    'nav' => 'alerts',
    'user' => $user,
    'catalog' => AlertCatalog::all(),
    'mine' => CustomAlerts::forOwner($uid),
    'library' => CustomAlerts::library($uid),
    'flash' => Auth::takeFlash(),
]);
