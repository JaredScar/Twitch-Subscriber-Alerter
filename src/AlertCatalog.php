<?php
declare(strict_types=1);

namespace App;

/**
 * Built-in alerts (an optional "alert pack") and the gift-count -> alert mapping logic.
 *
 * The pack is not part of the repository. It lives in public/alerts/ (engine.js, data/<id>.js, assets) and is
 * described by a catalog file, public/alerts/catalog.json by default (ALERT_CATALOG overrides the path):
 *
 *   { "alerts": { "gifted-005": { "label": "Gifted subs - 5", "min": 5, "page": "gifted-subs-005.html" }, ... } }
 *
 * Without a catalog the site runs with custom alerts only.
 */
final class AlertCatalog
{
    public const MAX_MIN_COUNT = 100000;

    /** @var array<string,array{label:string,min:int,page:?string}>|null */
    private static ?array $cache = null;

    public static function path(): string
    {
        $p = Config::get('ALERT_CATALOG') ?? 'public/alerts/catalog.json';
        return preg_match('#^([a-zA-Z]:)?[/\\\\]#', $p) ? $p : APP_ROOT . '/' . $p;
    }

    /**
     * id => [label, default minimum gifts, standalone preview page or null], in catalog order.
     * @return array<string,array{label:string,min:int,page:?string}>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $out = [];
        $file = self::path();
        if (is_file($file)) {
            $json = json_decode((string) file_get_contents($file), true);
            foreach ((is_array($json['alerts'] ?? null) ? $json['alerts'] : []) as $id => $a) {
                $id = (string) $id;
                // ids end up in script URLs and the database: keep them boring, and never in the custom-N namespace
                if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $id) || str_starts_with($id, 'custom-') || !is_array($a)) {
                    error_log("alert catalog: skipped invalid entry '$id'");
                    continue;
                }
                $page = isset($a['page']) && is_string($a['page']) && preg_match('/^[A-Za-z0-9_-]+\.html$/', $a['page']) ? $a['page'] : null;
                $out[$id] = [
                    'label' => mb_substr(trim((string) ($a['label'] ?? $id)), 0, 60) ?: $id,
                    'min' => max(1, min(self::MAX_MIN_COUNT, (int) ($a['min'] ?? 1))),
                    'page' => $page,
                ];
            }
        }
        return self::$cache = $out;
    }

    /** Test helper: forget the loaded catalog (after changing ALERT_CATALOG). */
    public static function reset(): void
    {
        self::$cache = null;
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::all());
    }

    /** The built-in alert new streamers get for new subs / resubs (the first one), if there is a pack. */
    public static function defaultId(): ?string
    {
        return self::ids()[0] ?? null;
    }

    public static function exists(?string $id): bool
    {
        return $id !== null && isset(self::all()[$id]);
    }

    /** "custom-12": a streamer-submitted alert (see CustomAlerts). */
    public static function isCustomId(?string $id): bool
    {
        return CustomAlerts::parseId($id) !== null;
    }

    /**
     * Pick the alert for a batch of gifted subs: the enabled rule with the highest min_count
     * that is <= $total. Ties go to the later rule, so the order of $rules matters: built-in
     * alerts first (catalog order), then custom ones by id. Null = nothing plays.
     * Callers (Users::rules) only pass custom alerts the streamer is allowed to use.
     *
     * @param array<string,array{enabled:bool|int,min_count:int}> $rules keyed by alert id
     */
    public static function resolveGift(int $total, array $rules): ?string
    {
        $best = null;
        $bestMin = -1;
        foreach ($rules as $id => $rule) {
            $id = (string) $id;
            if (!(int) $rule['enabled'] || !(self::exists($id) || self::isCustomId($id))) {
                continue;
            }
            $min = (int) $rule['min_count'];
            if ($min <= $total && $min >= $bestMin) {
                $best = $id;
                $bestMin = $min;
            }
        }
        return $best;
    }

    /** @return array<string,array{enabled:int,min_count:int}> */
    public static function defaultRules(): array
    {
        $rules = [];
        foreach (self::all() as $id => $a) {
            $rules[$id] = ['enabled' => 1, 'min_count' => $a['min']];
        }
        return $rules;
    }
}
