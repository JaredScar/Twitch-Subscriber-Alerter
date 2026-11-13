<?php
declare(strict_types=1);

namespace App;

final class Http
{
    public static function securityHeaders(bool $csp = true): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        // Not in development: HSTS covers every port of the host, which would break other http://localhost apps.
        if (Auth::isHttps() && Config::get('APP_ENV', 'production') !== 'development') {
            header('Strict-Transport-Security: max-age=31536000');
        }
        if ($csp) {
            header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https://static-cdn.jtvnw.net; "
                . "style-src 'self'; script-src 'self'; connect-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
        }
    }

    public static function redirect(string $path): never
    {
        header('Location: ' . $path, true, 303);
        exit;
    }

    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function fail(int $status, string $title, string $message = ''): never
    {
        http_response_code($status);
        self::securityHeaders();
        View::render('error', ['title' => $title, 'message' => $message, 'status' => $status], 'layout');
        exit;
    }

    public static function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Allow: POST');
            self::fail(405, 'Method not allowed');
        }
    }

    /** @return array<string,string> lower-cased request headers */
    public static function requestHeaders(): array
    {
        $out = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $out[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        return $out;
    }
}
