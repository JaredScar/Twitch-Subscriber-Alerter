<?php
declare(strict_types=1);

namespace App;

/** Event log + overlay queue. */
final class Events
{
    public const FRESH_SECONDS = 120;       // overlay ignores events older than this
    public const TEST_LIMIT_PER_MINUTE = 20;
    public const KEEP_DAYS = 30;

    /**
     * Record an event. Returns the new id, or null when a duplicate Twitch message was already stored.
     *
     * @param array{type:string,message_id?:?string,actor?:?string,gift_count?:?int,tier?:?string,is_anonymous?:bool,months?:?int,alert_id?:?string,note?:?string} $e
     */
    public static function record(int $userId, array $e): ?int
    {
        try {
            Database::run(
                'INSERT INTO events (user_id, type, twitch_message_id, actor, gift_count, tier, is_anonymous, months, alert_id, note, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $userId, $e['type'], $e['message_id'] ?? null,
                    isset($e['actor']) ? mb_substr((string) $e['actor'], 0, 64) : null,
                    $e['gift_count'] ?? null, $e['tier'] ?? null, !empty($e['is_anonymous']) ? 1 : 0,
                    $e['months'] ?? null, $e['alert_id'] ?? null,
                    isset($e['note']) ? mb_substr((string) $e['note'], 0, 64) : null,
                    Database::now(),
                ]
            );
        } catch (\PDOException $ex) {
            if (($ex->errorInfo[1] ?? 0) === 1062) {   // duplicate message id: Twitch retried delivery
                return null;
            }
            throw $ex;
        }
        if (random_int(1, 200) === 1) {
            self::prune();
        }
        return (int) Database::pdo()->lastInsertId();
    }

    public static function recent(int $userId, int $limit = 25): array
    {
        $limit = max(1, min(100, $limit));
        return Database::all(
            "SELECT id, type, actor, gift_count, tier, is_anonymous, months, alert_id, note, created_at
             FROM events WHERE user_id = ? ORDER BY id DESC LIMIT $limit",
            [$userId]
        );
    }

    public static function lastId(int $userId): int
    {
        return (int) (Database::value('SELECT COALESCE(MAX(id), 0) FROM events WHERE user_id = ?', [$userId]) ?? 0);
    }

    /**
     * Playable events after a cursor. Returns the new cursor too (the highest id seen, even
     * for events that had no alert) so the overlay never re-reads old rows.
     *
     * @return array{cursor:int,events:list<array<string,mixed>>}
     */
    public static function since(int $userId, int $after): array
    {
        $rows = Database::all(
            'SELECT id, type, alert_id, actor, gift_count, months, created_at FROM events
             WHERE user_id = ? AND id > ? ORDER BY id ASC LIMIT 50',
            [$userId, $after]
        );
        $cursor = $after;
        $out = [];
        $cutoff = time() - self::FRESH_SECONDS;
        foreach ($rows as $r) {
            $cursor = max($cursor, (int) $r['id']);
            if ($r['alert_id'] === null || !(AlertCatalog::exists($r['alert_id']) || AlertCatalog::isCustomId($r['alert_id']))) {
                continue;   // custom alerts are checked against the streamer's permissions by CustomAlerts::attach()
            }
            if (strtotime($r['created_at'] . ' UTC') < $cutoff) {
                continue;
            }
            $out[] = [
                'id' => (int) $r['id'],
                'type' => $r['type'],
                'alert' => $r['alert_id'],
                'actor' => $r['actor'],
                'count' => $r['gift_count'] !== null ? (int) $r['gift_count'] : null,
                'months' => $r['months'] !== null ? (int) $r['months'] : null,
            ];
        }
        return ['cursor' => $cursor, 'events' => $out];
    }

    public static function recentTestCount(int $userId): int
    {
        return (int) Database::value(
            "SELECT COUNT(*) FROM events WHERE user_id = ? AND type = 'test' AND created_at >= (UTC_TIMESTAMP() - INTERVAL 60 SECOND)",
            [$userId]
        );
    }

    public static function prune(): void
    {
        Database::run('DELETE FROM events WHERE created_at < (UTC_TIMESTAMP() - INTERVAL ' . self::KEEP_DAYS . ' DAY)');
    }

    /** @return array<string,int> user_id => event count in the last 30 days */
    public static function countsByUser(): array
    {
        $out = [];
        foreach (Database::all("SELECT user_id, COUNT(*) c FROM events WHERE type <> 'test' GROUP BY user_id") as $r) {
            $out[(int) $r['user_id']] = (int) $r['c'];
        }
        return $out;
    }
}
