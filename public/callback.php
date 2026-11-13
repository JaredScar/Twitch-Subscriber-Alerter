<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\Config;
use App\Http;
use App\Twitch;
use App\Users;

Http::securityHeaders();
Auth::start();

$expected = (string) ($_SESSION['oauth_state'] ?? '');
unset($_SESSION['oauth_state']);
$state = (string) ($_GET['state'] ?? '');

if (isset($_GET['error'])) {
    // the error text arrives in the URL, so anyone could craft it: do not show it
    Http::fail(400, 'Login cancelled', 'Twitch did not authorise the login. Please try again.');
}
if ($expected === '' || !hash_equals($expected, $state) || empty($_GET['code']) || !is_string($_GET['code'])) {
    Http::fail(400, 'Login failed', 'The login session expired. Please try again.');
}

try {
    $profile = Twitch::profileFromCode($_GET['code']);
    // Private site: refuse before anything is stored or linked for this account.
    if (!Users::mayLogin($profile['id'], $profile['login'])) {
        error_log('callback: login refused for ' . $profile['login'] . ' (RESTRICT_LOGIN_TO_ADMINS)');
        Http::fail(403, 'This site is private', Config::adminIdentifiers() === []
            ? 'Login is restricted to the accounts in ADMIN_TWITCH_LOGINS, but that list is empty. Add your Twitch login to .env.'
            : 'Only accounts approved by the site owner can log in here.');
    }
    $user = Users::upsertFromTwitch($profile);
} catch (Throwable $e) {
    error_log('callback: ' . $e->getMessage());
    Http::fail(502, 'Login failed', 'Could not complete the Twitch login. Make sure you allow the requested permission, then try again.');
}

Auth::login($user);

if ((int) $user['is_disabled'] === 0) {
    try {
        $status = Twitch::syncUser($user);
        $bad = array_filter($status, static fn($s) => !in_array($s, ['enabled', 'webhook_callback_verification_pending'], true));
        if ($bad) {
            Auth::flash('warn', 'Connected, but Twitch did not accept every subscription event: ' . implode('; ', $bad));
        } else {
            Auth::flash('ok', 'Twitch connected. Subscriber events are now being listened for.');
        }
    } catch (Throwable $e) {
        error_log('eventsub sync: ' . $e->getMessage());
        Auth::flash('warn', 'Logged in, but linking Twitch subscriber events failed. Use "Re-sync" on the dashboard to retry.');
    }
}
Http::redirect('dashboard.php');
