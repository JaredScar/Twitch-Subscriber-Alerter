<?php
declare(strict_types=1);

namespace App;

/**
 * Settings come from three layers. The first non-empty value wins:
 *   1. Config::set() (tests)
 *   2. the process environment (Docker Compose, the host shell)
 *   3. the .env file
 */
final class Config
{
    /** @var array<string,string> */
    private static array $file = [];

    /** @var array<string,string> */
    private static array $override = [];

    public static function load(string $file): void
    {
        self::$file = is_file($file) ? self::parse((string) file_get_contents($file)) : [];
    }

    /** @return array<string,string> */
    public static function parse(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
                $value = substr($value, 1, -1);
            } else {
                // strip trailing " # comment"
                $value = trim(preg_replace('/\s+#.*$/', '', $value) ?? $value);
            }
            if ($key !== '') {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$override) && self::$override[$key] !== '') {
            return self::$override[$key];
        }
        $env = getenv($key);
        if ($env !== false && $env !== '') {
            return $env;
        }
        if (array_key_exists($key, self::$file) && self::$file[$key] !== '') {
            return self::$file[$key];
        }
        return $default;
    }

    public static function need(string $key): string
    {
        $v = self::get($key);
        if ($v === null) {
            throw new \RuntimeException("Missing required setting $key (see .env.example)");
        }
        return $v;
    }

    public static function appUrl(): string
    {
        return rtrim(self::need('APP_URL'), '/');
    }

    /** @return list<string> lower-cased admin logins / ids */
    public static function adminIdentifiers(): array
    {
        $raw = self::get('ADMIN_TWITCH_LOGINS', '') ?? '';
        $list = array_filter(array_map(static fn($s) => strtolower(trim($s)), explode(',', $raw)));
        return array_values($list);
    }

    /**
     * RESTRICT_LOGIN_TO_ADMINS=true: only the accounts in ADMIN_TWITCH_LOGINS may log in.
     * Fails closed: with no admins listed, nobody can log in.
     */
    public static function restrictLoginToAdmins(): bool
    {
        $v = strtolower(trim(self::get('RESTRICT_LOGIN_TO_ADMINS', '') ?? ''));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    /** Secret used for EventSub webhook signatures (derived so it never has to be stored). */
    public static function webhookSecret(): string
    {
        $secret = self::need('APP_SECRET');
        if (strlen($secret) < 32) {
            throw new \RuntimeException('APP_SECRET must be at least 32 characters (see .env.example)');
        }
        return hash_hmac('sha256', 'twitch-eventsub-webhook', $secret);
    }

    /** Test helper. Overrides both the process environment and the .env file. */
    public static function set(array $values): void
    {
        self::$override = $values;
    }
}
