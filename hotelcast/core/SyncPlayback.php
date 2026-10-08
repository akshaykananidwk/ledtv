<?php
declare(strict_types=1);

/**
 * Synchronized playback (2.4, #37): every TV showing the same synced playlist (or the same video wall)
 * shows the same item at the same moment. See docs/modules/video_wall_sync.md.
 *
 * The server sends a deterministic schedule with the content:
 *   "sync": {"epoch_ms": 1760000000000, "cycle_ms": 45000, "item_offsets_ms": [0, 10000, 25000]}
 * and `server_time_ms` in every poll response. A TV estimates its offset to the server clock (NTP-like
 * midpoint of the poll request, minimum-RTT sample) and computes
 *   position = (server_now - epoch_ms) mod cycle_ms  →  current item + offset into it.
 *
 * Every synced item needs a fixed duration: its own (or the playlist's per-item) seconds; items that are
 * not videos fall back to the TV's defaults (10 s, layouts 60 s); a video with duration 0 uses the media
 * length when the server knows it (settings.media_duration_ms), otherwise the admin must enter one.
 * Apps older than 2.4.0 (code < MIN_APP_CODE) ignore `sync` and simply play the items.
 */
final class SyncPlayback
{
    /** First TV app version code that understands `sync` and `wall` (2.4.0). */
    public const MIN_APP_CODE = 11;
    /** The TV's own defaults for items with duration 0 inside a playlist (ContentPlayer). */
    public const DEFAULT_ITEM_MS = 10000;
    public const DEFAULT_LAYOUT_MS = 60000;
    /** Longest schedule (one day) — longer cycles are clamped by the per-item limit of 86400 s anyway. */
    public const MAX_CYCLE_MS = 86400000 * 7;

    /** Server time in milliseconds (also sent as `server_time_ms` in poll responses). */
    public static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /** Stored media length of a video row (ms) or null. Uploads may record it in settings.media_duration_ms. */
    public static function mediaMs(array $row): ?int
    {
        $s = ContentManager::settings($row);
        $ms = (int) ($s['media_duration_ms'] ?? 0);
        if ($ms <= 0 && isset($s['media_duration'])) {
            $ms = (int) round((float) $s['media_duration'] * 1000);
        }
        return $ms > 0 ? $ms : null;
    }

    /**
     * Length (ms) an item gets in a synced schedule, or null when it cannot be known (a video with
     * duration 0 and no stored media length). $durationSec is the effective duration (playlist override
     * or the item's own); $row is the content_items row.
     */
    public static function itemMs(string $type, int $durationSec, ?array $row = null): ?int
    {
        if ($durationSec > 0) {
            return $durationSec * 1000;
        }
        if ($type === 'video') {
            return $row ? self::mediaMs($row) : null;
        }
        return $type === 'layout' ? self::DEFAULT_LAYOUT_MS : self::DEFAULT_ITEM_MS;
    }

    /**
     * Build the `sync` object for TV items (ContentItems as toTvItem() made them) and fix their durations
     * so that the TV's own timing matches the schedule. Returns null when an item has no usable duration.
     * @param array<int, array> $items  modified in place
     */
    public static function plan(array &$items, int $epochMs): ?array
    {
        if (!$items) {
            return null;
        }
        $offsets = [];
        $t = 0;
        $single = count($items) === 1;
        foreach ($items as $i => $it) {
            $type = (string) ($it['type'] ?? '');
            $dur = (int) ($it['duration'] ?? 0);
            $row = null;
            if ($dur <= 0 && $type === 'video' && !empty($it['id'])) {
                $row = ContentManager::findOwn((int) $it['id']);
            }
            $ms = self::itemMs($type, $dur, $row);
            if ($ms === null) {
                return null;
            }
            $offsets[] = $t;
            $t += $ms;
            if (!$single && $dur <= 0) {
                $items[$i]['duration'] = (int) ceil($ms / 1000);
            }
            if ($single && $type === 'video') {
                $items[$i]['loop'] = true; // one synced video repeats every cycle
            }
        }
        if ($t <= 0 || $t > self::MAX_CYCLE_MS) {
            return null;
        }
        return ['epoch_ms' => max(0, $epochMs), 'cycle_ms' => $t, 'item_offsets_ms' => $offsets];
    }

    /** Epoch of a synced playlist (set when it is saved; the last change time for rows written elsewhere). */
    public static function playlistEpoch(array $pl): int
    {
        $e = (int) ($pl['sync_epoch_ms'] ?? 0);
        if ($e > 0) {
            return $e;
        }
        $ts = strtotime((string) ($pl['updated_at'] ?? $pl['created_at'] ?? '')) ?: 0;
        return $ts * 1000;
    }

    /**
     * Titles of playlist items that cannot be synced (video with no seconds and no known media length).
     * $items = [[content_id, duration|null], …] as the playlist form posts them.
     */
    public static function missingDurations(array $items): array
    {
        $bad = [];
        foreach ($items as [$cid, $dur]) {
            $row = ContentManager::findOwn((int) $cid);
            if (!$row || !(int) $row['is_active']) {
                continue;
            }
            $eff = $dur !== null ? (int) $dur : (int) $row['duration'];
            if (self::itemMs((string) $row['type'], $eff, $row) === null) {
                $bad[(int) $row['id']] = (string) $row['title'];
            }
        }
        return array_values($bad);
    }

    /** Is the playlist marked "Sync playback"? (false on servers without migration 024). */
    public static function playlistSynced(int $playlistId): ?array
    {
        try {
            $pl = DB::one('SELECT id, sync_playback, sync_epoch_ms, created_at, updated_at FROM content_playlists WHERE id = :id AND hotel_id = :h', ['id' => $playlistId, 'h' => Tenant::id()]);
        } catch (Throwable) {
            return null;
        }
        return $pl && (int) $pl['sync_playback'] ? $pl : null;
    }

    /**
     * ContentExtension body: add `sync` to content that plays a synced playlist (normal, group, default and
     * scheduled screens, after sponsor ads were inserted so the schedule covers exactly what the TV plays).
     */
    public static function applyToContent(array &$content): void
    {
        if (isset($content['sync']) || !in_array($content['mode'] ?? '', ['assigned', 'group', 'default', 'scheduled'], true)
            || empty($content['items']) || empty($content['playlist']['id'])) {
            return;
        }
        $pl = self::playlistSynced((int) $content['playlist']['id']);
        if (!$pl) {
            return;
        }
        $items = $content['items'];
        $plan = self::plan($items, self::playlistEpoch($pl));
        if ($plan === null) {
            Logger::warning('Sync playback skipped for playlist ' . (int) $pl['id'] . ': an item has no fixed duration');
            return;
        }
        $content['items'] = $items;
        $content['sync'] = $plan;
    }
}
