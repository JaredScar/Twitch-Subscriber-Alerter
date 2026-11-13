<?php
declare(strict_types=1);

namespace App;

/** Twitch OAuth, Helix and EventSub management. No user tokens are ever stored. */
final class Twitch
{
    public const SCOPE = 'channel:read:subscriptions';
    private const AUTH = 'https://id.twitch.tv/oauth2';
    private const HELIX = 'https://api.twitch.tv/helix';

    /**
     * Base URLs. TWITCH_AUTH_URL / TWITCH_API_URL let the tests talk to a fake Twitch; they are ignored
     * outside APP_ENV=development so a stray setting can never send the client secret anywhere else.
     */
    private static function authBase(): string
    {
        return rtrim(self::devOverride('TWITCH_AUTH_URL') ?? self::AUTH, '/');
    }

    private static function apiBase(): string
    {
        return rtrim(self::devOverride('TWITCH_API_URL') ?? self::HELIX, '/');
    }

    private static function devOverride(string $key): ?string
    {
        return Config::get('APP_ENV') === 'development' ? Config::get($key) : null;
    }

    /** EventSub types every streamer needs: type => version */
    public const EVENT_TYPES = [
        'channel.subscription.gift' => '1',    // N subs gifted in one go (the main alert trigger)
        'channel.subscribe' => '1',            // new subs (gift recipients are filtered out)
        'channel.subscription.message' => '1', // resub messages
    ];

    public static function redirectUri(): string
    {
        return Config::appUrl() . '/callback.php';
    }

    public static function callbackUrl(): string
    {
        return Config::appUrl() . '/webhook/twitch.php';
    }

    public static function authorizeUrl(string $state): string
    {
        return self::authBase() . '/authorize?' . http_build_query([
            'client_id' => Config::need('TWITCH_CLIENT_ID'),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'state' => $state,
            'force_verify' => 'false',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchange the OAuth code and read the profile. The token is used once and discarded:
     * EventSub webhooks run on an app token, authorised by the scope the user just granted.
     *
     * @return array{id:string,login:string,display_name:string,profile_image_url:string}
     */
    public static function profileFromCode(string $code): array
    {
        $tok = self::request('POST', self::authBase() . '/token', [], [
            'client_id' => Config::need('TWITCH_CLIENT_ID'),
            'client_secret' => Config::need('TWITCH_CLIENT_SECRET'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => self::redirectUri(),
        ], []);
        if ($tok['status'] !== 200 || empty($tok['json']['access_token'])) {
            throw new \RuntimeException('Twitch rejected the login code (HTTP ' . $tok['status'] . ')');
        }
        $scopes = $tok['json']['scope'] ?? [];
        if (!in_array(self::SCOPE, $scopes, true)) {
            throw new \RuntimeException('The ' . self::SCOPE . ' permission was not granted');
        }
        $me = self::request('GET', self::apiBase() . '/users', [], null, [
            'Authorization: Bearer ' . $tok['json']['access_token'],
            'Client-Id: ' . Config::need('TWITCH_CLIENT_ID'),
        ]);
        $u = $me['json']['data'][0] ?? null;
        if ($me['status'] !== 200 || !is_array($u) || empty($u['id'])) {
            throw new \RuntimeException('Could not read the Twitch profile (HTTP ' . $me['status'] . ')');
        }
        return [
            'id' => (string) $u['id'],
            'login' => (string) $u['login'],
            'display_name' => (string) ($u['display_name'] ?? $u['login']),
            'profile_image_url' => (string) ($u['profile_image_url'] ?? ''),
        ];
    }

    /* ------------------------------------------------------------------ app token */

    public static function appToken(bool $forceRefresh = false): string
    {
        if (!$forceRefresh) {
            $row = Database::one("SELECT v FROM app_state WHERE k = 'app_token'");
            if ($row) {
                $t = json_decode($row['v'], true);
                if (is_array($t) && ($t['expires_at'] ?? 0) > time() + 3600) {
                    return (string) $t['token'];
                }
            }
        }
        $res = self::request('POST', self::authBase() . '/token', [], [
            'client_id' => Config::need('TWITCH_CLIENT_ID'),
            'client_secret' => Config::need('TWITCH_CLIENT_SECRET'),
            'grant_type' => 'client_credentials',
        ], []);
        if ($res['status'] !== 200 || empty($res['json']['access_token'])) {
            throw new \RuntimeException('Could not get a Twitch app token (HTTP ' . $res['status'] . ')');
        }
        $value = json_encode([
            'token' => $res['json']['access_token'],
            'expires_at' => time() + (int) ($res['json']['expires_in'] ?? 3600),
        ]);
        Database::run(
            "INSERT INTO app_state (k, v, updated_at) VALUES ('app_token', ?, ?)
             ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = VALUES(updated_at)",
            [$value, Database::now()]
        );
        return (string) $res['json']['access_token'];
    }

    /** Helix call with the app token (one automatic retry on 401). @return array{status:int,json:?array} */
    public static function helix(string $method, string $path, array $query = [], ?array $jsonBody = null): array
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $headers = [
                'Authorization: Bearer ' . self::appToken($attempt > 0),
                'Client-Id: ' . Config::need('TWITCH_CLIENT_ID'),
            ];
            $res = self::request($method, self::apiBase() . $path, $query, null, $headers, $jsonBody);
            if ($res['status'] !== 401) {
                return $res;
            }
        }
        return $res;
    }

    /* ------------------------------------------------------------------ EventSub */

    /**
     * Make sure the streamer has an enabled subscription for every EVENT_TYPES entry and mirror
     * the state into the database.
     *
     * @param array<string,mixed> $user users row
     * @return array<string,string> type => status (or error text)
     */
    public static function syncUser(array $user): array
    {
        $out = [];
        $existing = [];
        $list = self::helix('GET', '/eventsub/subscriptions', ['user_id' => $user['twitch_id'], 'first' => 100]);
        if ($list['status'] !== 200) {
            throw new \RuntimeException('Could not list EventSub subscriptions (HTTP ' . $list['status'] . ')');
        }
        foreach ($list['json']['data'] ?? [] as $s) {
            $cb = $s['transport']['callback'] ?? '';
            if (($s['transport']['method'] ?? '') !== 'webhook' || $cb !== self::callbackUrl()) {
                continue;   // someone else's / old URL
            }
            if ((string) ($s['condition']['broadcaster_user_id'] ?? '') !== (string) $user['twitch_id']) {
                continue;
            }
            $existing[$s['type']][] = $s;
        }
        foreach (self::EVENT_TYPES as $type => $version) {
            $alive = null;
            foreach ($existing[$type] ?? [] as $s) {
                if (in_array($s['status'], ['enabled', 'webhook_callback_verification_pending'], true)) {
                    $alive = $s;
                } else {
                    self::helix('DELETE', '/eventsub/subscriptions', ['id' => $s['id']]);   // dead (revoked / failed)
                }
            }
            if ($alive === null) {
                $create = self::helix('POST', '/eventsub/subscriptions', [], [
                    'type' => $type,
                    'version' => $version,
                    'condition' => ['broadcaster_user_id' => (string) $user['twitch_id']],
                    'transport' => [
                        'method' => 'webhook',
                        'callback' => self::callbackUrl(),
                        'secret' => Config::webhookSecret(),
                    ],
                ]);
                if (in_array($create['status'], [200, 202], true) && !empty($create['json']['data'][0])) {
                    $alive = $create['json']['data'][0];
                } elseif ($create['status'] === 409) {
                    $alive = ['id' => null, 'status' => 'enabled'];   // already exists
                } else {
                    $msg = $create['json']['message'] ?? ('HTTP ' . $create['status']);
                    self::storeStatus((int) $user['id'], $type, null, 'error: ' . mb_substr((string) $msg, 0, 40));
                    $out[$type] = 'error: ' . $msg;
                    continue;
                }
            }
            self::storeStatus((int) $user['id'], $type, $alive['id'] ?? null, (string) $alive['status']);
            $out[$type] = (string) $alive['status'];
        }
        return $out;
    }

    /** Remove all of a streamer's EventSub subscriptions at Twitch (best effort). */
    public static function removeUser(array $user): void
    {
        foreach (Database::all('SELECT twitch_subscription_id FROM eventsub_subscriptions WHERE user_id = ?', [$user['id']]) as $r) {
            if (!empty($r['twitch_subscription_id'])) {
                try {
                    self::helix('DELETE', '/eventsub/subscriptions', ['id' => $r['twitch_subscription_id']]);
                } catch (\Throwable $e) {
                    error_log('EventSub delete failed: ' . $e->getMessage());
                }
            }
        }
        Database::run('DELETE FROM eventsub_subscriptions WHERE user_id = ?', [$user['id']]);
    }

    public static function storeStatus(int $userId, string $type, ?string $subId, string $status): void
    {
        Database::run(
            'INSERT INTO eventsub_subscriptions (user_id, type, twitch_subscription_id, status, updated_at)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE twitch_subscription_id = COALESCE(VALUES(twitch_subscription_id), twitch_subscription_id),
                                     status = VALUES(status), updated_at = VALUES(updated_at)',
            [$userId, $type, $subId, $status, Database::now()]
        );
    }

    /** @return array<string,string> type => status from the database */
    public static function storedStatus(int $userId): array
    {
        $out = [];
        foreach (Database::all('SELECT type, status FROM eventsub_subscriptions WHERE user_id = ?', [$userId]) as $r) {
            $out[$r['type']] = $r['status'];
        }
        return $out;
    }

    /* ------------------------------------------------------------------ HTTP */

    /**
     * @param array<string,scalar> $query
     * @param array<string,scalar>|null $form
     * @param list<string> $headers
     * @return array{status:int,json:?array}
     */
    public static function request(string $method, string $url, array $query = [], ?array $form = null, array $headers = [], ?array $json = null): array
    {
        if ($query) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'TwitchSubscriberAlerter/1.0',
        ];
        if ($ca = Config::get('CURL_CA_BUNDLE')) {
            $opts[CURLOPT_CAINFO] = $ca;
        }
        if ($json !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
        } elseif ($form !== null) {
            $opts[CURLOPT_POSTFIELDS] = http_build_query($form);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('HTTP request failed: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $decoded = json_decode((string) $body, true);
        return ['status' => $status, 'json' => is_array($decoded) ? $decoded : null];
    }
}
