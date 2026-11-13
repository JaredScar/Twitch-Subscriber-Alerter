<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\AlertCatalog;
use App\Auth;
use App\Config;
use App\Http;
use App\View;

Http::securityHeaders();
if (Auth::user()) {
    Http::redirect('dashboard.php');
}
View::render('home', [
    'title' => 'Sub alerts for your stream',
    'nav' => '',
    'configured' => Config::get('TWITCH_CLIENT_ID') !== null && Config::get('TWITCH_CLIENT_SECRET') !== null,
    'catalog' => AlertCatalog::all(),
    'restricted' => Config::restrictLoginToAdmins(),
]);
