<?php
declare(strict_types=1);

namespace App;

/** Verifies and processes Twitch EventSub webhook deliveries. */
final class EventSub
{
    public const MAX_BODY = 262144;      // 256 KB
    public const MAX_SKEW = 600;         // Twitch: reject messages older than 10 minutes

    /** Expected `Twitch-Eventsub-Message-Signature` header value. */
    public static function signature(string $messageId, string $timestamp, string $body, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $messageId . $timestamp . $body, $secret);
    }

    /** @param array<string,string> $h lower-cased header name => value */
    public static function verify(array $h, string $body, string $secret, ?int $now = null): bool
    {
        $id = $h['twitch-eventsub-message-id'] ?? '';
        $ts = $h['twitch-eventsub-message-timestamp'] ?? '';
        $sig = $h['twitch-eventsub-message-signature'] ?? '';
        if ($id === '' || $ts === '' || $sig === '') {
            return false;
        }
        $when = strtotime($ts);
        if ($when === false || abs(($now ?? time()) - $when) > self::MAX_SKEW) {
            return false;
        }
        return hash_equals(self::signature($id, $ts, $body, $secret), $sig);
    }

    /**
     * @param array<string,string> $headers lower-cased header names
     * @return array{0:int,1:string,2:string} [http status, body, content type]
     */
    public static function handle(array $headers, string $body, string $secret, ?int $now = null): array
    {
        if (strlen($body) > self::MAX_BODY) {
            return [413, 'too large', 'text/plain'];
        }
        if (!self::verify($headers, $body, $secret, $now)) {
            return [403, 'forbidden', 'text/plain'];
        }
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return [400, 'bad json', 'text/plain'];
        }
        $type = $headers['twitch-eventsub-message-type'] ?? '';
        switch ($type) {
            case 'webhook_callback_verification':
                return [200, (string) ($payload['challenge'] ?? ''), 'text/plain'];
            case 'notification':
                self::notification($headers['twitch-eventsub-message-id'], $payload);
                return [204, '', 'text/plain'];
            case 'revocation':
                self::revocation($payload);
                return [204, '', 'text/plain'];
            default:
                return [204, '', 'text/plain'];
        }
    }

    /** @param array<string,mixed> $p */
    private static function revocation(array $p): void
    {
        $sub = $p['subscription'] ?? [];
        $status = (string) ($sub['status'] ?? 'revoked');
        if (!empty($sub['id'])) {
            Database::run(
                'UPDATE eventsub_subscriptions SET status = ?, updated_at = ? WHERE twitch_subscription_id = ?',
                [mb_substr($status, 0, 48), Database::now(), $sub['id']]
            );
        }
    }

    /** @param array<string,mixed> $p */
    public static function notification(string $messageId, array $p): void
    {
        $sub = $p['subscription'] ?? [];
        $ev = $p['event'] ?? [];
        $type = (string) ($sub['type'] ?? '');
        $broadcaster = (string) ($ev['broadcaster_user_id'] ?? ($sub['condition']['broadcaster_user_id'] ?? ''));
        $user = $broadcaster !== '' ? Users::findByTwitchId($broadcaster) : null;
        if (!$user || (int) $user['is_disabled'] === 1) {
            return;
        }
        $uid = (int) $user['id'];
        $settings = Users::settings($uid);
        $paused = (int) $settings['overlay_enabled'] === 0;

        $row = ['message_id' => $messageId, 'tier' => isset($ev['tier']) ? (string) $ev['tier'] : null];
        $alert = null;
        $note = null;

        switch ($type) {
            case 'channel.subscription.gift':
                $total = max(0, (int) ($ev['total'] ?? 0));
                $anon = !empty($ev['is_anonymous']);
                $row += ['type' => 'gift', 'gift_count' => $total, 'is_anonymous' => $anon,
                    'actor' => $anon ? 'Anonymous' : (string) ($ev['user_name'] ?? $ev['user_login'] ?? '')];
                if (!(int) $settings['react_gift']) {
                    $note = 'gift alerts off';
                } else {
                    $alert = AlertCatalog::resolveGift($total, Users::rules($uid));
                    $note = $alert === null ? 'no alert for ' . $total : null;
                }
                break;

            case 'channel.subscribe':
                if (!empty($ev['is_gift'])) {
                    return;   // the gift is announced by channel.subscription.gift; don't double up
                }
                $row += ['type' => 'sub', 'actor' => (string) ($ev['user_name'] ?? $ev['user_login'] ?? '')];
                if (!(int) $settings['react_sub']) {
                    $note = 'sub alerts off';
                } else {
                    $alert = CustomAlerts::usable($uid, $settings['sub_alert']) ? $settings['sub_alert'] : null;
                    $note = $alert === null ? 'no alert chosen' : null;
                }
                break;

            case 'channel.subscription.message':
                $row += ['type' => 'resub', 'actor' => (string) ($ev['user_name'] ?? $ev['user_login'] ?? ''),
                    'months' => isset($ev['cumulative_months']) ? (int) $ev['cumulative_months'] : null];
                if (!(int) $settings['react_resub']) {
                    $note = 'resub alerts off';
                } else {
                    $alert = CustomAlerts::usable($uid, $settings['resub_alert']) ? $settings['resub_alert'] : null;
                    $note = $alert === null ? 'no alert chosen' : null;
                }
                break;

            default:
                return;
        }

        if ($alert !== null && $paused) {
            $alert = null;
            $note = 'overlay paused';
        }
        $row['alert_id'] = $alert;
        $row['note'] = $note;
        Events::record($uid, $row);
    }
}
