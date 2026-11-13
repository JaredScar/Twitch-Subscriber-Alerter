<?php
declare(strict_types=1);

/** Twitch EventSub webhook callback. */

require __DIR__ . '/../../src/bootstrap.php';

use App\Config;
use App\EventSub;
use App\Http;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

try {
    $body = (string) file_get_contents('php://input', false, null, 0, EventSub::MAX_BODY + 1);
    [$status, $out, $type] = EventSub::handle(Http::requestHeaders(), $body, Config::webhookSecret());
} catch (Throwable $e) {
    error_log('webhook: ' . $e->getMessage());
    // 5xx makes Twitch retry the delivery, which is what we want for transient database errors
    $status = 500;
    $out = 'error';
    $type = 'text/plain';
}

http_response_code($status);
if ($out !== '') {
    header('Content-Type: ' . $type);
    header('Content-Length: ' . strlen($out));
    echo $out;
}
