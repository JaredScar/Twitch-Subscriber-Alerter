<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\Http;
use App\Twitch;

Http::securityHeaders();
Auth::start();
try {
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $url = Twitch::authorizeUrl($state);
} catch (Throwable $e) {
    error_log('login: ' . $e->getMessage());
    Http::fail(500, 'Twitch login is not configured', 'The site owner still needs to set TWITCH_CLIENT_ID, TWITCH_CLIENT_SECRET and APP_URL in .env.');
}
header('Location: ' . $url, true, 302);
