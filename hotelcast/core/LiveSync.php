<?php
declare(strict_types=1);

/**
 * Live video sync (2.6.1): keeps live streams (temple darshan, news, HLS / RTSP / YouTube live) on the
 * TVs close to the real live moment. See docs/modules/live_sync.md.
 *
 * Two per-customer settings (Settings → TV & Display → Live video):
 *   live_sync_refresh_min  re-sync every N minutes (0 = off; 2, 5, 10, 15, 30), default 5
 *   live_sync_max_lag_s    maximum delay behind live (30, 60, 90, 120 seconds), default 60
 *
 * Sent to every TV as `content.live_sync = {refresh_min, max_lag_s}` (ContentResolver::finish()), so a
 * change reaches the TVs on their next poll. Apps older than 2.6.1 ignore the field; 2.6.1 uses the same
 * defaults when it is missing (older server).
 */
final class LiveSync
{
    public const REFRESH_OPTIONS = [0, 2, 5, 10, 15, 30];
    public const MAX_LAG_OPTIONS = [30, 60, 90, 120];
    public const DEFAULT_REFRESH_MIN = 5;
    public const DEFAULT_MAX_LAG_S = 60;

    public const KEY_REFRESH = 'live_sync_refresh_min';
    public const KEY_MAX_LAG = 'live_sync_max_lag_s';

    /** Current customer's values (defaults when unset or invalid). @return array{refresh_min:int, max_lag_s:int} */
    public static function config(): array
    {
        return [
            'refresh_min' => self::pick(Settings::get(self::KEY_REFRESH), self::REFRESH_OPTIONS, self::DEFAULT_REFRESH_MIN),
            'max_lag_s' => self::pick(Settings::get(self::KEY_MAX_LAG), self::MAX_LAG_OPTIONS, self::DEFAULT_MAX_LAG_S),
        ];
    }

    /** The `live_sync` object of the TV content. */
    public static function forTv(): array
    {
        return self::config();
    }

    /**
     * Validate a settings form (only the listed values are accepted).
     * @return array{0: array<string,string>, 1: string[]} [settings to save, errors]
     */
    public static function validate(array $in): array
    {
        $errors = [];
        $refresh = self::strictPick($in['live_sync_refresh_min'] ?? null, self::REFRESH_OPTIONS);
        if ($refresh === null) {
            $errors[] = __('Choose how often live videos are re-synced (Off, 2, 5, 10, 15 or 30 minutes).');
        }
        $lag = self::strictPick($in['live_sync_max_lag_s'] ?? null, self::MAX_LAG_OPTIONS);
        if ($lag === null) {
            $errors[] = __('Choose the maximum delay behind live (30, 60, 90 or 120 seconds).');
        }
        if ($errors) {
            return [[], $errors];
        }
        return [[self::KEY_REFRESH => (string) $refresh, self::KEY_MAX_LAG => (string) $lag], []];
    }

    private static function strictPick(mixed $v, array $allowed): ?int
    {
        if (!is_scalar($v) || !preg_match('/^\d{1,4}$/', trim((string) $v))) {
            return null;
        }
        $i = (int) trim((string) $v);
        return in_array($i, $allowed, true) ? $i : null;
    }

    private static function pick(mixed $v, array $allowed, int $default): int
    {
        return self::strictPick($v, $allowed) ?? $default;
    }
}
