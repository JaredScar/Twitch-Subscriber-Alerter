<?php
declare(strict_types=1);

namespace App;

/** Session handling, CSRF protection and access checks. */
final class Auth
{
    private static bool $started = false;
    private static ?array $user = null;
    private static bool $userLoaded = false;

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        return str_starts_with(Config::get('APP_URL', '') ?? '', 'https://')
            && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    public static function start(): void
    {
        if (self::$started) {
            return;
        }
        session_name('tsa_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', (string) (60 * 60 * 24 * 14));
        session_start();
        self::$started = true;
    }

    public static function user(): ?array
    {
        self::start();
        if (!self::$userLoaded) {
            self::$userLoaded = true;
            $id = (int) ($_SESSION['user_id'] ?? 0);
            self::$user = $id > 0 ? Users::find($id) : null;
            // Login restriction applies to sessions that already exist too (e.g. turned on after someone logged in).
            if (self::$user && !Users::mayLogin((string) self::$user['twitch_id'], (string) self::$user['login'])) {
                self::$user = null;
            }
            // Admin rights follow ADMIN_TWITCH_LOGINS right now, not as it was at the last login.
            if (self::$user) {
                $admin = Users::isAdminIdentity((string) self::$user['twitch_id'], (string) self::$user['login']) ? 1 : 0;
                if ((int) self::$user['is_admin'] !== $admin) {
                    Users::setAdmin((int) self::$user['id'], (bool) $admin);
                    self::$user['is_admin'] = $admin;
                }
            }
            if (!self::$user) {
                unset($_SESSION['user_id']);
            }
        }
        return self::$user;
    }

    public static function login(array $user): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        self::$user = $user;
        self::$userLoaded = true;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'],
                'httponly' => true, 'samesite' => 'Lax']);
        }
        session_destroy();
        self::$started = false;
        self::$user = null;
        self::$userLoaded = false;
    }

    public static function requireUser(): array
    {
        $u = self::user();
        if (!$u) {
            Http::redirect('index.php');
        }
        return $u;
    }

    public static function requireAdmin(): array
    {
        $u = self::requireUser();
        if (!(int) $u['is_admin']) {
            Http::fail(403, 'Forbidden', 'This area is for the site owner.');
        }
        return $u;
    }

    /* ---------------------------------------------------------------- CSRF + flash */

    public static function csrf(): string
    {
        self::start();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(24));
        }
        return $_SESSION['csrf'];
    }

    public static function checkCsrf(): void
    {
        self::start();
        $sent = (string) ($_POST['_csrf'] ?? '');
        if ($sent === '' || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
            Http::fail(400, 'Session expired', 'The form expired or was invalid. Go back, reload the page and try again.');
        }
    }

    public static function flash(string $type, string $message): void
    {
        self::start();
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type:string,message:string}> */
    public static function takeFlash(): array
    {
        self::start();
        $f = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $f;
    }
}
