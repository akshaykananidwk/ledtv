<?php
declare(strict_types=1);

/** Fixed-window rate limiter backed by the `rate_limits` table. */
final class RateLimiter
{
    /**
     * Register a hit. Returns 0 when allowed, otherwise seconds until the window resets.
     */
    public static function hit(string $key, int $maxHits, int $windowSec): int
    {
        $now = time();
        $windowStart = $now - ($now % $windowSec);
        DB::query(
            'INSERT INTO rate_limits (rl_key, hits, window_start) VALUES (:k, 1, :w)
             ON DUPLICATE KEY UPDATE
               hits = IF(window_start = VALUES(window_start), hits + 1, 1),
               window_start = VALUES(window_start)',
            ['k' => substr($key, 0, 190), 'w' => $windowStart]
        );
        $hits = (int) DB::value('SELECT hits FROM rate_limits WHERE rl_key = :k', ['k' => substr($key, 0, 190)]);
        if ($hits > $maxHits) {
            return max(1, $windowStart + $windowSec - $now);
        }
        return 0;
    }

    public static function cleanup(): void
    {
        DB::query('DELETE FROM rate_limits WHERE window_start < :t', ['t' => time() - 3600]);
    }
}
