<?php
declare(strict_types=1);

namespace App;

/** Users, their settings and alert rules. */
final class Users
{
    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public static function findByTwitchId(string $twitchId): ?array
    {
        return Database::one('SELECT * FROM users WHERE twitch_id = ?', [$twitchId]);
    }

    public static function findByOverlayKey(string $key): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $key)) {
            return null;
        }
        return Database::one('SELECT * FROM users WHERE overlay_key = ?', [$key]);
    }

    public static function newOverlayKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function isAdminIdentity(string $twitchId, string $login): bool
    {
        $admins = Config::adminIdentifiers();
        return in_array(strtolower($twitchId), $admins, true) || in_array(strtolower($login), $admins, true);
    }

    /** May this Twitch account log in? Always yes, unless RESTRICT_LOGIN_TO_ADMINS limits it to the admin list. */
    public static function mayLogin(string $twitchId, string $login): bool
    {
        return !Config::restrictLoginToAdmins() || self::isAdminIdentity($twitchId, $login);
    }

    /**
     * Create or update the user after a successful Twitch login.
     * @param array{id:string,login:string,display_name:string,profile_image_url?:string} $profile
     * @return array<string,mixed> the user row
     */
    public static function upsertFromTwitch(array $profile): array
    {
        $now = Database::now();
        $isAdmin = self::isAdminIdentity($profile['id'], $profile['login']) ? 1 : 0;
        $existing = self::findByTwitchId($profile['id']);
        if ($existing) {
            Database::run(
                'UPDATE users SET login = ?, display_name = ?, profile_image_url = ?, is_admin = ?, last_login_at = ? WHERE id = ?',
                [$profile['login'], $profile['display_name'], $profile['profile_image_url'] ?? null, $isAdmin, $now, $existing['id']]
            );
            $id = (int) $existing['id'];
        } else {
            Database::run(
                'INSERT INTO users (twitch_id, login, display_name, profile_image_url, overlay_key, is_admin, created_at, last_login_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$profile['id'], $profile['login'], $profile['display_name'], $profile['profile_image_url'] ?? null,
                    self::newOverlayKey(), $isAdmin, $now, $now]
            );
            $id = (int) Database::pdo()->lastInsertId();
        }
        self::ensureDefaults($id);
        return self::find($id) ?? throw new \RuntimeException('User vanished');
    }

    public static function ensureDefaults(int $userId): void
    {
        $now = Database::now();
        Database::run(
            'INSERT IGNORE INTO user_settings (user_id, overlay_enabled, volume, react_gift, react_sub, react_resub, sub_alert, resub_alert, updated_at)
             VALUES (?, 1, 80, 1, 1, 1, ?, ?, ?)',
            [$userId, AlertCatalog::defaultId(), AlertCatalog::defaultId(), $now]
        );
        foreach (AlertCatalog::defaultRules() as $alertId => $r) {
            Database::run(
                'INSERT IGNORE INTO alert_rules (user_id, alert_id, enabled, min_count) VALUES (?, ?, ?, ?)',
                [$userId, $alertId, $r['enabled'], $r['min_count']]
            );
        }
    }

    /** @return array<string,mixed> */
    public static function settings(int $userId): array
    {
        self::ensureDefaults($userId);
        return Database::one('SELECT * FROM user_settings WHERE user_id = ?', [$userId]) ?? [];
    }

    /**
     * Built-in alerts first (catalog order), then the custom alerts this streamer uses and may still
     * use (approved, and theirs or public), ordered by id. The order decides ties in resolveGift().
     *
     * @return array<string,array{enabled:int,min_count:int}>
     */
    public static function rules(int $userId): array
    {
        $rules = AlertCatalog::defaultRules();
        $available = CustomAlerts::availableIds($userId);
        $custom = [];
        foreach (Database::all('SELECT alert_id, enabled, min_count FROM alert_rules WHERE user_id = ?', [$userId]) as $r) {
            $row = ['enabled' => (int) $r['enabled'], 'min_count' => (int) $r['min_count']];
            if (AlertCatalog::exists($r['alert_id'])) {
                $rules[$r['alert_id']] = $row;
            } elseif (($n = CustomAlerts::parseId($r['alert_id'])) !== null && isset($available[$n])) {
                $custom[$n] = $row;
            }
        }
        ksort($custom);
        foreach ($custom as $n => $row) {
            $rules[CustomAlerts::alertId($n)] = $row;
        }
        return $rules;
    }

    /**
     * Validate + store the dashboard settings form.
     * @param array<string,mixed> $post
     */
    public static function saveSettings(int $userId, array $post): void
    {
        $custom = array_map(static fn(array $a): string => CustomAlerts::alertId((int) $a['id']), CustomAlerts::addedFor($userId));
        $alertOrNull = static function ($v) use ($custom): ?string {
            $v = is_string($v) ? $v : '';
            return AlertCatalog::exists($v) || in_array($v, $custom, true) ? $v : null;
        };
        $volume = max(0, min(100, (int) ($post['volume'] ?? 80)));
        Database::run(
            'UPDATE user_settings SET overlay_enabled = ?, volume = ?, react_gift = ?, react_sub = ?, react_resub = ?,
                    sub_alert = ?, resub_alert = ?, updated_at = ? WHERE user_id = ?',
            [
                empty($post['overlay_enabled']) ? 0 : 1,
                $volume,
                empty($post['react_gift']) ? 0 : 1,
                empty($post['react_sub']) ? 0 : 1,
                empty($post['react_resub']) ? 0 : 1,
                $alertOrNull($post['sub_alert'] ?? null),
                $alertOrNull($post['resub_alert'] ?? null),
                Database::now(),
                $userId,
            ]
        );
        $rules = is_array($post['rules'] ?? null) ? $post['rules'] : [];
        foreach (array_merge(AlertCatalog::ids(), $custom) as $alertId) {
            $r = is_array($rules[$alertId] ?? null) ? $rules[$alertId] : [];
            $min = (int) ($r['min_count'] ?? (AlertCatalog::all()[$alertId]['min'] ?? CustomAlerts::DEFAULT_MIN));
            $min = max(1, min(AlertCatalog::MAX_MIN_COUNT, $min));
            Database::run(
                'INSERT INTO alert_rules (user_id, alert_id, enabled, min_count) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), min_count = VALUES(min_count)',
                [$userId, $alertId, empty($r['enabled']) ? 0 : 1, $min]
            );
        }
    }

    public static function regenerateOverlayKey(int $userId): string
    {
        $key = self::newOverlayKey();
        Database::run('UPDATE users SET overlay_key = ? WHERE id = ?', [$key, $userId]);
        return $key;
    }

    public static function setAdmin(int $userId, bool $admin): void
    {
        Database::run('UPDATE users SET is_admin = ? WHERE id = ?', [$admin ? 1 : 0, $userId]);
    }

    public static function setDisabled(int $userId, bool $disabled): void
    {
        Database::run('UPDATE users SET is_disabled = ? WHERE id = ?', [$disabled ? 1 : 0, $userId]);
    }

    public static function delete(int $userId): void
    {
        CustomAlerts::deleteAllForOwner($userId);   // uploaded files + other streamers' references
        Database::run('DELETE FROM users WHERE id = ?', [$userId]);   // children cascade
    }

    /** Record that the overlay is alive (throttled: writes at most every 15 s per streamer). */
    public static function touchOverlay(int $userId): void
    {
        $now = time();
        Database::run(
            'INSERT INTO overlay_seen (user_id, seen_at) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE seen_at = IF(seen_at < ?, VALUES(seen_at), seen_at)',
            [$userId, gmdate('Y-m-d H:i:s', $now), gmdate('Y-m-d H:i:s', $now - 15)]
        );
    }

    /** UTC "Y-m-d H:i:s" of the overlay's last poll, or null. */
    public static function overlaySeenAt(int $userId): ?string
    {
        $v = Database::value('SELECT seen_at FROM overlay_seen WHERE user_id = ?', [$userId]);
        return $v === null ? null : (string) $v;
    }
}
