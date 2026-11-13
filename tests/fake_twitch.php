<?php
declare(strict_types=1);

/**
 * Tiny stand-in for id.twitch.tv + api.twitch.tv used by tests/integration.php.
 *   php -S 127.0.0.1:8098 tests/fake_twitch.php
 * State (created subscriptions) is kept in a temp file.
 */

$store = sys_get_temp_dir() . '/fake_twitch_subs.json';
$subs = is_file($store) ? (json_decode((string) file_get_contents($store), true) ?: []) : [];
$save = static function () use (&$subs, $store): void {
    file_put_contents($store, json_encode($subs));
};
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$send = static function (int $status, mixed $json): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($json);
    exit;
};
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];

if ($path === '/reset') {
    @unlink($store);
    $send(200, ['ok' => true]);
}
if ($path === '/dump') {
    $send(200, $subs);
}
if ($path === '/oauth2/token' && $method === 'POST') {
    if (($_POST['grant_type'] ?? '') === 'client_credentials') {
        $send(200, ['access_token' => 'app-token', 'expires_in' => 5000000, 'token_type' => 'bearer']);
    }
    if (($_POST['code'] ?? '') === 'good-code' || ($_POST['code'] ?? '') === 'admin-code') {
        $send(200, ['access_token' => 'user-' . $_POST['code'], 'scope' => ['channel:read:subscriptions'], 'token_type' => 'bearer']);
    }
    if (($_POST['code'] ?? '') === 'no-scope-code') {
        $send(200, ['access_token' => 'user-x', 'scope' => [], 'token_type' => 'bearer']);
    }
    $send(400, ['status' => 400, 'message' => 'Invalid authorization code']);
}
if ($path === '/helix/users') {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($auth === 'Bearer user-admin-code') {
        $send(200, ['data' => [['id' => '9001', 'login' => 'testadmin', 'display_name' => 'TestAdmin', 'profile_image_url' => 'https://static-cdn.jtvnw.net/x.png']]]);
    }
    if ($auth === 'Bearer user-good-code') {
        $send(200, ['data' => [['id' => '1234', 'login' => 'streamer_one', 'display_name' => 'Streamer_One', 'profile_image_url' => 'https://static-cdn.jtvnw.net/y.png']]]);
    }
    $send(401, ['message' => 'bad token']);
}
if ($path === '/helix/eventsub/subscriptions') {
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer app-token') {
        $send(401, ['message' => 'Invalid OAuth token']);
    }
    if ($method === 'GET') {
        $uid = $_GET['user_id'] ?? '';
        $out = array_values(array_filter($subs, static fn($s) => ($s['condition']['broadcaster_user_id'] ?? '') === $uid));
        $send(200, ['data' => $out, 'total' => count($out)]);
    }
    if ($method === 'POST') {
        foreach ($subs as $s) {
            if ($s['type'] === $body['type'] && $s['condition'] === $body['condition']) {
                $send(409, ['message' => 'subscription already exists']);
            }
        }
        if (strlen($body['transport']['secret'] ?? '') < 10) {
            $send(400, ['message' => 'secret too short']);
        }
        $sub = ['id' => bin2hex(random_bytes(8)), 'status' => 'webhook_callback_verification_pending', 'type' => $body['type'],
            'version' => $body['version'], 'condition' => $body['condition'],
            'transport' => ['method' => 'webhook', 'callback' => $body['transport']['callback']]];
        $subs[] = $sub;
        $save();
        $send(202, ['data' => [$sub]]);
    }
    if ($method === 'DELETE') {
        $subs = array_values(array_filter($subs, static fn($s) => $s['id'] !== ($_GET['id'] ?? '')));
        $save();
        http_response_code(204);
        exit;
    }
}
$send(404, ['message' => 'not found: ' . $path]);
