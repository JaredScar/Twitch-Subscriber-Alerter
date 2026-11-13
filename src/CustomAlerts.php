<?php
declare(strict_types=1);

namespace App;

/**
 * Streamer-submitted alerts.
 *
 * Lifecycle:  submit (pending)  ->  admin approves (private | public) or rejects  ->  streamers "use" it.
 * Nothing that is not approved is ever served to an overlay. Submissions cannot be edited.
 *
 * Two kinds:
 *   media  an image / GIF / video, optional sound, and a text line, rendered by assets/js/custom-player.js
 *   html   one self-contained HTML file, run inside a sandboxed iframe (see public/media.php for the CSP)
 */
final class CustomAlerts
{
    public const MEDIA_MAX = 8 * 1024 * 1024;
    public const SOUND_MAX = 3 * 1024 * 1024;
    public const HTML_MAX = 2 * 1024 * 1024;
    public const MAX_PIXELS = 4096;
    public const MAX_PER_USER = 25;
    public const MAX_PENDING_PER_USER = 5;
    public const DEFAULT_MIN = 5;           // gift minimum given to a custom alert when a streamer starts using it
    public const DEFAULT_TEXT = '{name} gifted {count} subs!';

    public const POSITIONS = ['tl', 't', 'tr', 'l', 'c', 'r', 'bl', 'b', 'br'];
    public const ANIMATIONS = ['fade', 'pop', 'slide'];
    public const TEXT_POSITIONS = ['below', 'above', 'none'];

    /** detected mime => stored extension */
    private const IMAGE_MIMES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    private const VIDEO_MIMES = ['video/webm' => 'webm', 'video/mp4' => 'mp4'];
    private const SOUND_MIMES = ['audio/ogg' => 'ogg', 'application/ogg' => 'ogg', 'audio/mpeg' => 'mp3',
        'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/wave' => 'wav', 'audio/vnd.wave' => 'wav'];
    /** stored extension => mime we serve */
    private const SERVE_MIME = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'webm' => 'video/webm', 'mp4' => 'video/mp4', 'ogg' => 'audio/ogg', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav',
        'html' => 'text/html; charset=utf-8'];

    /* ------------------------------------------------------------------ ids */

    public static function alertId(int $n): string
    {
        return 'custom-' . $n;
    }

    /** "custom-12" -> 12, anything else -> null */
    public static function parseId(?string $id): ?int
    {
        return $id !== null && preg_match('/^custom-([1-9][0-9]{0,8})$/', $id, $m) === 1 ? (int) $m[1] : null;
    }

    /* ------------------------------------------------------------------ storage */

    public static function uploadRoot(): string
    {
        return rtrim(Config::get('UPLOAD_DIR') ?? (APP_ROOT . '/storage/uploads'), '/\\');
    }

    public static function dir(int $id): string
    {
        return self::uploadRoot() . '/custom/' . $id;
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f !== '.' && $f !== '..') {
                @unlink($dir . '/' . $f);
            }
        }
        @rmdir($dir);
    }

    /* ------------------------------------------------------------------ reading */

    /** @param array<string,mixed>|null $row @return array<string,mixed>|null */
    private static function hydrate(?array $row): ?array
    {
        if (!$row) {
            return null;
        }
        $row['config'] = json_decode((string) ($row['config'] ?? '{}'), true) ?: [];
        $row['scan'] = json_decode((string) ($row['scan'] ?? '{}'), true) ?: [];
        return $row;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return self::hydrate(Database::one('SELECT a.*, u.display_name AS owner_name FROM custom_alerts a JOIN users u ON u.id = a.owner_id WHERE a.id = ?', [$id]));
    }

    /** Alerts the user submitted, newest first. */
    public static function forOwner(int $userId): array
    {
        $rows = Database::all(
            "SELECT a.*, (r.user_id IS NOT NULL) AS added
               FROM custom_alerts a
               LEFT JOIN alert_rules r ON r.user_id = a.owner_id AND r.alert_id = CONCAT('custom-', a.id)
              WHERE a.owner_id = ? ORDER BY a.id DESC",
            [$userId]
        );
        return array_map(fn($r) => self::hydrate($r), $rows);
    }

    /** Approved + public alerts from other streamers, with whether $userId already uses them. */
    public static function library(int $userId): array
    {
        $rows = Database::all(
            "SELECT a.*, u.display_name AS owner_name, (r.user_id IS NOT NULL) AS added
               FROM custom_alerts a
               JOIN users u ON u.id = a.owner_id
               LEFT JOIN alert_rules r ON r.user_id = ? AND r.alert_id = CONCAT('custom-', a.id)
              WHERE a.status = 'approved' AND a.visibility = 'public' AND a.owner_id <> ?
              ORDER BY a.id DESC",
            [$userId, $userId]
        );
        return array_map(fn($r) => self::hydrate($r), $rows);
    }

    /** Custom alerts the user has switched on (has a rule for) and is still allowed to use. */
    public static function addedFor(int $userId): array
    {
        $rows = Database::all(
            "SELECT a.*, u.display_name AS owner_name
               FROM custom_alerts a
               JOIN users u ON u.id = a.owner_id
               JOIN alert_rules r ON r.user_id = ? AND r.alert_id = CONCAT('custom-', a.id)
              WHERE a.status = 'approved' AND (a.owner_id = ? OR a.visibility = 'public')
              ORDER BY a.id",
            [$userId, $userId]
        );
        return array_map(fn($r) => self::hydrate($r), $rows);
    }

    /** @return array<int,true> ids of approved alerts this user may use */
    public static function availableIds(int $userId): array
    {
        $out = [];
        foreach (Database::all("SELECT id FROM custom_alerts WHERE status = 'approved' AND (owner_id = ? OR visibility = 'public')", [$userId]) as $r) {
            $out[(int) $r['id']] = true;
        }
        return $out;
    }

    /** Built-in alert, or an approved custom alert this user may use. */
    public static function usable(int $userId, ?string $alertId): bool
    {
        if (AlertCatalog::exists($alertId)) {
            return true;
        }
        $n = self::parseId($alertId);
        return $n !== null && Database::value(
            "SELECT 1 FROM custom_alerts WHERE id = ? AND status = 'approved' AND (owner_id = ? OR visibility = 'public')",
            [$n, $userId]
        ) !== null;
    }

    /** @param list<string|null> $alertIds @return array<string,string> alert id => display name */
    public static function labels(array $alertIds): array
    {
        $ids = [];
        foreach ($alertIds as $a) {
            if (($n = self::parseId($a)) !== null) {
                $ids[$n] = $n;
            }
        }
        $out = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach (Database::all("SELECT id, name FROM custom_alerts WHERE id IN ($in)", array_values($ids)) as $r) {
                $out[self::alertId((int) $r['id'])] = $r['name'];
            }
        }
        return $out;
    }

    public static function pendingCount(): int
    {
        return (int) (Database::value("SELECT COUNT(*) FROM custom_alerts WHERE status = 'pending'") ?? 0);
    }

    /** Admin list. @return list<array<string,mixed>> */
    public static function adminList(string $status): array
    {
        $rows = Database::all(
            'SELECT a.*, u.display_name AS owner_name, u.login AS owner_login
               FROM custom_alerts a JOIN users u ON u.id = a.owner_id
              WHERE a.status = ? ORDER BY a.id DESC LIMIT 200',
            [$status]
        );
        return array_map(fn($r) => self::hydrate($r), $rows);
    }

    /* ------------------------------------------------------------------ serving */

    /**
     * Where a stored file lives. @param 'media'|'sound'|'html' $which
     * @return array{path:string,mime:string,size:int}|null
     */
    public static function fileMeta(array $alert, string $which): ?array
    {
        $f = $alert['config'][$which]['file'] ?? null;
        if (!is_string($f) || !preg_match('/^[a-z]+\.[a-z0-9]{2,4}$/', $f)) {
            return null;
        }
        $path = self::dir((int) $alert['id']) . '/' . $f;
        $mime = self::SERVE_MIME[strtolower(pathinfo($f, PATHINFO_EXTENSION))] ?? null;
        if ($mime === null || !is_file($path)) {
            return null;
        }
        return ['path' => $path, 'mime' => $mime, 'size' => (int) filesize($path)];
    }

    /**
     * What an overlay (or the preview page) needs to play the alert.
     * @return array<string,mixed>
     */
    public static function descriptor(array $alert, ?string $overlayKey): array
    {
        $cfg = $alert['config'];
        $url = static fn(string $f): string => 'media.php?a=' . (int) $alert['id'] . '&f=' . $f
            . ($overlayKey !== null ? '&key=' . rawurlencode($overlayKey) : '');
        $d = [
            'id' => self::alertId((int) $alert['id']),
            'kind' => $alert['kind'],
            'name' => $alert['name'],
            'duration' => (int) ($cfg['duration'] ?? 8),
        ];
        if ($alert['kind'] === 'html') {
            $d['html'] = ['url' => $url('html')];
            return $d;
        }
        $d['media'] = ['url' => $url('media'), 'type' => $cfg['media']['type'] ?? 'image'];
        $d['sound'] = isset($cfg['sound']['file']) ? ['url' => $url('sound')] : null;
        foreach (['text', 'textPos', 'textColor', 'fontSize', 'width', 'position', 'animation'] as $k) {
            $d[$k] = $cfg[$k] ?? null;
        }
        return $d;
    }

    /**
     * Drop poll events whose custom alert is no longer allowed and attach player descriptors.
     *
     * @param list<array<string,mixed>> $events from Events::since
     * @return array{events:list<array<string,mixed>>,custom:array<string,array<string,mixed>>}
     */
    public static function attach(int $userId, string $overlayKey, array $events): array
    {
        $available = null;
        $custom = [];
        $out = [];
        foreach ($events as $ev) {
            $n = self::parseId($ev['alert'] ?? null);
            if ($n === null) {
                $out[] = $ev;
                continue;
            }
            $available ??= self::availableIds($userId);
            if (!isset($available[$n])) {
                continue;   // revoked, deleted or never allowed: silently skipped
            }
            if (!isset($custom[$ev['alert']])) {
                $alert = self::find($n);
                if (!$alert) {
                    continue;
                }
                $custom[$ev['alert']] = self::descriptor($alert, $overlayKey);
            }
            $out[] = $ev;
        }
        return ['events' => $out, 'custom' => $custom];
    }

    /* ------------------------------------------------------------------ using an alert */

    public static function useAlert(int $userId, int $alertId): void
    {
        if (!self::usable($userId, self::alertId($alertId))) {
            throw new \InvalidArgumentException('That alert is not available to you.');
        }
        Database::run(
            'INSERT IGNORE INTO alert_rules (user_id, alert_id, enabled, min_count) VALUES (?, ?, 1, ?)',
            [$userId, self::alertId($alertId), self::DEFAULT_MIN]
        );
    }

    public static function stopUsing(int $userId, int $alertId): void
    {
        $id = self::alertId($alertId);
        Database::run('DELETE FROM alert_rules WHERE user_id = ? AND alert_id = ?', [$userId, $id]);
        Database::run('UPDATE user_settings SET sub_alert = NULL WHERE user_id = ? AND sub_alert = ?', [$userId, $id]);
        Database::run('UPDATE user_settings SET resub_alert = NULL WHERE user_id = ? AND resub_alert = ?', [$userId, $id]);
    }

    /* ------------------------------------------------------------------ review */

    public static function approve(int $id, int $adminId, string $visibility, ?string $note): void
    {
        if (!in_array($visibility, ['private', 'public'], true)) {
            throw new \InvalidArgumentException('Choose private or public.');
        }
        Database::run(
            "UPDATE custom_alerts SET status = 'approved', visibility = ?, review_note = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ?",
            [$visibility, self::note($note), $adminId, Database::now(), $id]
        );
    }

    /** Reject a pending submission, or take an approved one offline. */
    public static function reject(int $id, int $adminId, string $note): void
    {
        $note = self::note($note);
        if ($note === null) {
            throw new \InvalidArgumentException('Give the streamer a reason so they know what to change.');
        }
        Database::run(
            "UPDATE custom_alerts SET status = 'rejected', visibility = NULL, review_note = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ?",
            [$note, $adminId, Database::now(), $id]
        );
    }

    private static function note(?string $note): ?string
    {
        $note = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $note) ?? '');
        return $note === '' ? null : mb_substr($note, 0, 255);
    }

    public static function delete(int $id): void
    {
        $alertId = self::alertId($id);
        Database::run('DELETE FROM alert_rules WHERE alert_id = ?', [$alertId]);
        Database::run('UPDATE user_settings SET sub_alert = NULL WHERE sub_alert = ?', [$alertId]);
        Database::run('UPDATE user_settings SET resub_alert = NULL WHERE resub_alert = ?', [$alertId]);
        Database::run("UPDATE events SET alert_id = NULL, note = 'alert removed' WHERE alert_id = ?", [$alertId]);
        Database::run('DELETE FROM custom_alerts WHERE id = ?', [$id]);
        self::removeDir(self::dir($id));
    }

    /** Called before a user is deleted: their files and everyone's references to their alerts. */
    public static function deleteAllForOwner(int $userId): void
    {
        foreach (Database::all('SELECT id FROM custom_alerts WHERE owner_id = ?', [$userId]) as $r) {
            self::delete((int) $r['id']);
        }
    }

    /* ------------------------------------------------------------------ submitting */

    /**
     * Validate and store a submission as "pending". Throws InvalidArgumentException with a message
     * that is safe to show to the streamer.
     *
     * @param array<string,mixed> $post
     * @param array<string,mixed> $files $_FILES
     */
    public static function submit(int $ownerId, array $post, array $files): int
    {
        $kind = (string) ($post['kind'] ?? '');
        if (!in_array($kind, ['media', 'html'], true)) {
            throw new \InvalidArgumentException('Choose what kind of alert you are submitting.');
        }
        $name = self::cleanName((string) ($post['name'] ?? ''));
        $desc = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($post['description'] ?? '')) ?? '');
        $desc = $desc === '' ? null : mb_substr($desc, 0, 255);

        $total = (int) Database::value('SELECT COUNT(*) FROM custom_alerts WHERE owner_id = ?', [$ownerId]);
        $pending = (int) Database::value("SELECT COUNT(*) FROM custom_alerts WHERE owner_id = ? AND status = 'pending'", [$ownerId]);
        if ($total >= self::MAX_PER_USER) {
            throw new \InvalidArgumentException('You have reached the limit of ' . self::MAX_PER_USER . ' custom alerts. Delete one first.');
        }
        if ($pending >= self::MAX_PENDING_PER_USER) {
            throw new \InvalidArgumentException('You already have ' . self::MAX_PENDING_PER_USER . ' alerts waiting for review. Wait for those first.');
        }

        // Inspect everything before touching the database or disk.
        $parts = [];
        if ($kind === 'media') {
            $parts['media'] = self::inspectUpload($files['media'] ?? null, 'media', true);
            $parts['sound'] = self::inspectUpload($files['sound'] ?? null, 'sound', false);
            $config = self::sanitizeMediaConfig($post);
            $scan = ['media' => ['mime' => $parts['media']['mime'], 'bytes' => $parts['media']['size'],
                'width' => $parts['media']['width'] ?? null, 'height' => $parts['media']['height'] ?? null]];
            if ($parts['sound']) {
                $scan['sound'] = ['mime' => $parts['sound']['mime'], 'bytes' => $parts['sound']['size']];
            }
        } else {
            $parts['html'] = self::inspectUpload($files['html'] ?? null, 'html', true);
            $config = ['duration' => self::clampInt($post['html_duration'] ?? $post['duration'] ?? null, 1, 60, 10)];
            $scan = ['html' => $parts['html']['scan']];
        }

        Database::run(
            "INSERT INTO custom_alerts (owner_id, name, description, kind, status, config, scan, created_at)
             VALUES (?, ?, ?, ?, 'pending', '{}', NULL, ?)",
            [$ownerId, $name, $desc, $kind, Database::now()]
        );
        $id = (int) Database::pdo()->lastInsertId();
        try {
            $dir = self::dir($id);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Could not create the upload folder.');
            }
            foreach ($parts as $slot => $p) {
                if ($p === null) {
                    continue;
                }
                $file = $slot . '.' . $p['ext'];
                if (!move_uploaded_file($p['tmp'], $dir . '/' . $file)) {
                    throw new \RuntimeException('Could not store the uploaded file.');
                }
                $config[$slot] = ['file' => $file] + ($slot === 'media' ? ['type' => $p['type']] : []);
            }
            Database::run('UPDATE custom_alerts SET config = ?, scan = ? WHERE id = ?', [
                json_encode($config, JSON_UNESCAPED_SLASHES), json_encode($scan, JSON_UNESCAPED_SLASHES), $id,
            ]);
        } catch (\Throwable $e) {
            self::removeDir(self::dir($id));
            Database::run('DELETE FROM custom_alerts WHERE id = ?', [$id]);
            throw $e instanceof \InvalidArgumentException ? $e : new \RuntimeException('Could not save the submission: ' . $e->getMessage(), 0, $e);
        }
        return $id;
    }

    public static function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '') ?? '');
        if (mb_strlen($name) < 3) {
            throw new \InvalidArgumentException('Give the alert a name (at least 3 characters).');
        }
        return mb_substr($name, 0, 60);
    }

    private static function clampInt(mixed $v, int $min, int $max, int $default): int
    {
        if (!is_numeric($v)) {
            return $default;
        }
        return max($min, min($max, (int) $v));
    }

    /**
     * Display settings of a media alert from the submit form. Pure: unknown / malformed values fall
     * back to defaults. @param array<string,mixed> $post
     * @return array{text:string,textPos:string,textColor:string,fontSize:int,width:int,position:string,duration:int,animation:string}
     */
    public static function sanitizeMediaConfig(array $post): array
    {
        $pick = static fn(mixed $v, array $allowed, string $default): string => is_string($v) && in_array($v, $allowed, true) ? $v : $default;
        $textPos = $pick($post['textPos'] ?? null, self::TEXT_POSITIONS, 'below');
        $text = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($post['text'] ?? '')) ?? '');
        $text = mb_substr($text, 0, 120);
        if ($text === '' && $textPos !== 'none') {
            $text = self::DEFAULT_TEXT;
        }
        $color = (string) ($post['textColor'] ?? '');
        return [
            'text' => $text,
            'textPos' => $textPos,
            'textColor' => preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? strtolower($color) : '#ffffff',
            'fontSize' => self::clampInt($post['fontSize'] ?? null, 16, 160, 56),
            'width' => self::clampInt($post['width'] ?? null, 5, 100, 40),
            'position' => $pick($post['position'] ?? null, self::POSITIONS, 'c'),
            'duration' => self::clampInt($post['duration'] ?? null, 1, 30, 8),
            'animation' => $pick($post['animation'] ?? null, self::ANIMATIONS, 'pop'),
        ];
    }

    /**
     * Check one upload slot ($_FILES entry). Returns null for "nothing chosen" on an optional slot.
     *
     * @param array<string,mixed>|null $f
     * @param 'media'|'sound'|'html' $type
     * @return array<string,mixed>|null
     */
    private static function inspectUpload(?array $f, string $type, bool $required): ?array
    {
        $labels = ['media' => 'picture / video file', 'sound' => 'sound file', 'html' => 'HTML file'];
        $err = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            if ($required) {
                throw new \InvalidArgumentException('Choose a ' . $labels[$type] . '.');
            }
            return null;
        }
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new \InvalidArgumentException('The ' . $labels[$type] . ' is too large.');
        }
        if ($err !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('The ' . $labels[$type] . ' did not upload correctly. Try again.');
        }
        $tmp = (string) ($f['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \InvalidArgumentException('The ' . $labels[$type] . ' did not upload correctly. Try again.');
        }
        return self::inspectFile($tmp, $type) + ['tmp' => $tmp];
    }

    /**
     * Content-based checks (the file name and the browser-reported type are never trusted).
     *
     * @param 'media'|'sound'|'html' $type
     * @return array<string,mixed> ext, mime, type, size, [width, height], [scan]
     */
    public static function inspectFile(string $path, string $type): array
    {
        $size = (int) @filesize($path);
        $limit = ['media' => self::MEDIA_MAX, 'sound' => self::SOUND_MAX, 'html' => self::HTML_MAX][$type];
        if ($size <= 0) {
            throw new \InvalidArgumentException('That file is empty.');
        }
        if ($size > $limit) {
            throw new \InvalidArgumentException('That file is too large (limit ' . self::formatBytes($limit) . ').');
        }

        if ($type === 'html') {
            $html = (string) file_get_contents($path);
            if (!mb_check_encoding($html, 'UTF-8') || str_contains($html, "\0")) {
                throw new \InvalidArgumentException('The HTML file must be plain UTF-8 text.');
            }
            if (!preg_match('/<\s*[a-z!]/i', $html)) {
                throw new \InvalidArgumentException('That does not look like an HTML file.');
            }
            return ['ext' => 'html', 'mime' => 'text/html', 'type' => 'html', 'size' => $size, 'scan' => self::scanHtml($html)];
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($type === 'sound') {
            if (!isset(self::SOUND_MIMES[$mime])) {
                throw new \InvalidArgumentException('Unsupported sound file. Use OGG, MP3 or WAV.');
            }
            return ['ext' => self::SOUND_MIMES[$mime], 'mime' => $mime, 'type' => 'sound', 'size' => $size];
        }
        if (isset(self::IMAGE_MIMES[$mime])) {
            $info = @getimagesize($path);
            if ($info === false || $info[0] < 1 || $info[1] < 1) {
                throw new \InvalidArgumentException('That image could not be read.');
            }
            if ($info[0] > self::MAX_PIXELS || $info[1] > self::MAX_PIXELS) {
                throw new \InvalidArgumentException('That image is too big (max ' . self::MAX_PIXELS . ' px on each side).');
            }
            return ['ext' => self::IMAGE_MIMES[$mime], 'mime' => $mime, 'type' => 'image', 'size' => $size, 'width' => $info[0], 'height' => $info[1]];
        }
        if (isset(self::VIDEO_MIMES[$mime])) {
            return ['ext' => self::VIDEO_MIMES[$mime], 'mime' => $mime, 'type' => 'video', 'size' => $size];
        }
        throw new \InvalidArgumentException('Unsupported file. Use PNG, JPG, GIF, WebP, WebM or MP4.');
    }

    /**
     * Quick static findings to help the reviewer. The sandbox + CSP are the actual protection;
     * this just points the reviewer at what to read.
     *
     * @return array{bytes:int,flags:list<string>,urls:list<string>,data_uris:int}
     */
    public static function scanHtml(string $html): array
    {
        $checks = [
            'iframe' => '/<\s*iframe\b/i',
            'object / embed' => '/<\s*(object|embed)\b/i',
            'eval / Function()' => '/\beval\s*\(|new\s+Function\s*\(/i',
            'network calls (fetch, XHR, WebSocket, beacon)' => '/\bfetch\s*\(|XMLHttpRequest|\bWebSocket\b|\bEventSource\b|sendBeacon/i',
            'navigation (location, window.open)' => '/\b(?:window\.|top\.|parent\.)?location\s*(?:\.\s*(?:href|assign|replace)\s*)?=(?!=)|window\s*\.\s*open\s*\(|\.\s*location\s*\.\s*(?:assign|replace)\s*\(/i',
            'form' => '/<\s*form\b/i',
            'meta refresh / base tag' => '/<\s*meta\b[^>]*http-equiv\s*=\s*["\']?refresh|<\s*base\b/i',
            'external script / stylesheet' => '/<\s*(?:script|link)\b[^>]*\b(?:src|href)\s*=\s*["\']?\s*(?:https?:)?\/\//i',
        ];
        $flags = [];
        foreach ($checks as $label => $re) {
            if (preg_match($re, $html)) {
                $flags[] = $label;
            }
        }
        $urls = [];
        if (preg_match_all('#(?:https?:)?//[a-z0-9][a-z0-9.-]*\.[a-z]{2,}[^\s"\'<>)\\\\]*#i', $html, $m)) {
            foreach ($m[0] as $u) {
                if (!str_contains($u, 'w3.org') && !in_array($u, $urls, true)) {
                    $urls[] = mb_substr($u, 0, 120);
                }
                if (count($urls) >= 8) {
                    break;
                }
            }
        }
        return ['bytes' => strlen($html), 'flags' => $flags, 'urls' => $urls, 'data_uris' => substr_count($html, 'data:')];
    }

    /** HTML source for the reviewer: giant base64 blobs are elided and the rest is truncated. */
    public static function reviewSource(array $alert, int $maxChars = 60000): ?string
    {
        $f = self::fileMeta($alert, 'html');
        if (!$f) {
            return null;
        }
        $src = (string) file_get_contents($f['path']);
        $src = preg_replace_callback('#data:[a-z0-9.+/-]+;base64,[A-Za-z0-9+/=]{200,}#i', static fn($m) => 'data:...[' . strlen($m[0]) . ' bytes elided]', $src) ?? $src;
        return mb_strlen($src) > $maxChars ? mb_substr($src, 0, $maxChars) . "\n... [truncated]" : $src;
    }

    public static function formatBytes(int $b): string
    {
        return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, (int) round($b / 1024)) . ' KB';
    }
}
