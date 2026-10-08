<?php
declare(strict_types=1);

/**
 * Proof of play report (#40, admin/play_report.php): what was actually shown, from the plays the TVs report
 * (POST /api/device/played → broadcast_logs rows with event "played": device, room, content item, start
 * time, seconds on screen and — for sponsor ads — the ad campaign).
 *
 * Filters: date range (max 366 days, Analytics::range), content item, playlist (its items), room, group,
 * ad campaign. Users limited to some TVs (core/Access.php) only get plays of their rooms. Every query starts
 * on the index (hotel_id, event, created_at), (room_id, created_at) or (hotel_id, content_id, created_at); the table is
 * paginated and the CSV is streamed in keyset chunks, so a year of plays stays fast.
 */
final class PlayReport
{
    public const PER_PAGE = 100;
    private const CHUNK = 2000;

    /**
     * Normalised filters from GET input. Ids of another hotel → 404 (Tenant::deny), a room / group outside a
     * limited user's TVs → 403 (Access::deny), unknown ids are dropped.
     */
    public static function filters(array $in): array
    {
        [$from, $to] = Analytics::range($in['from'] ?? null, $in['to'] ?? null);
        $id = static fn (string $k): int => isset($in[$k]) && is_scalar($in[$k]) && ctype_digit((string) $in[$k]) ? (int) $in[$k] : 0;
        $f = ['from' => $from, 'to' => $to, 'content_id' => 0, 'playlist_id' => 0, 'room_id' => 0, 'group_id' => 0, 'campaign_id' => 0];
        if ($id('content_id') && Tenant::find('content_items', $id('content_id'))) {
            $f['content_id'] = $id('content_id');
        }
        if ($id('playlist_id') && Tenant::find('content_playlists', $id('playlist_id'))) {
            $f['playlist_id'] = $id('playlist_id');
        }
        if ($id('room_id') && Tenant::find('rooms', $id('room_id'))) {
            Access::requireRoom($id('room_id'));
            $f['room_id'] = $id('room_id');
        }
        if ($id('group_id') && Tenant::find('room_groups', $id('group_id'))) {
            Access::requireGroup($id('group_id'));
            $f['group_id'] = $id('group_id');
        }
        if ($id('campaign_id') && Tenant::isTenantTable('ad_campaigns') && Tenant::find('ad_campaigns', $id('campaign_id'))) {
            $f['campaign_id'] = $id('campaign_id');
        }
        return $f;
    }

    /** Query string of the filters (links, CSV, print view). */
    public static function query(array $f): array
    {
        return array_filter($f, static fn ($v) => $v !== 0 && $v !== '' && $v !== null);
    }

    /** WHERE clause on broadcast_logs l for the filters + the user's TV access. */
    private static function where(array $f): array
    {
        $sql = "l.hotel_id = :h AND l.event = 'played' AND l.created_at >= :f AND l.created_at < :t";
        $p = ['h' => Tenant::id(), 'f' => $f['from'] . ' 00:00:00', 't' => date('Y-m-d', (int) strtotime($f['to'] . ' +1 day')) . ' 00:00:00'];
        if ($f['content_id']) {
            $sql .= ' AND l.content_id = :c';
            $p['c'] = $f['content_id'];
        }
        if ($f['playlist_id']) {
            $sql .= ' AND l.content_id IN (SELECT pi.content_id FROM playlist_items pi WHERE pi.playlist_id = :pl)';
            $p['pl'] = $f['playlist_id'];
        }
        if ($f['room_id']) {
            $sql .= ' AND l.room_id = :r';
            $p['r'] = $f['room_id'];
        }
        if ($f['group_id']) {
            $sql .= ' AND l.room_id IN (SELECT m.room_id FROM room_group_members m WHERE m.group_id = :g)';
            $p['g'] = $f['group_id'];
        }
        if ($f['campaign_id']) {
            $sql .= ' AND l.ad_campaign_id = :ad';
            $p['ad'] = $f['campaign_id'];
        }
        [$acc, $ap] = Access::roomSql('l.room_id', 'pr');
        return [$sql . $acc, $p + $ap];
    }

    /** Totals: plays, airtime seconds, TVs, rooms, content items, days with plays. */
    public static function summary(array $f): array
    {
        [$w, $p] = self::where($f);
        $r = DB::one(
            "SELECT COUNT(*) AS plays, COALESCE(SUM(l.duration_sec), 0) AS seconds, COUNT(DISTINCT l.device_id) AS tvs,
                    COUNT(DISTINCT l.room_id) AS rooms, COUNT(DISTINCT l.content_id) AS items, COUNT(DISTINCT DATE(l.created_at)) AS days
             FROM broadcast_logs l WHERE $w",
            $p
        ) ?? [];
        return array_map('intval', $r + ['plays' => 0, 'seconds' => 0, 'tvs' => 0, 'rooms' => 0, 'items' => 0, 'days' => 0]);
    }

    /** Plays + airtime per day (every day of the range). */
    public static function perDay(array $f): array
    {
        [$w, $p] = self::where($f);
        $out = [];
        foreach (Analytics::days($f['from'], $f['to']) as $d) {
            $out[$d] = ['day' => $d, 'plays' => 0, 'seconds' => 0, 'tvs' => 0];
        }
        foreach (DB::all("SELECT DATE(l.created_at) AS d, COUNT(*) AS plays, COALESCE(SUM(l.duration_sec), 0) AS seconds, COUNT(DISTINCT l.device_id) AS tvs
                          FROM broadcast_logs l WHERE $w GROUP BY DATE(l.created_at)", $p) as $r) {
            if (isset($out[(string) $r['d']])) {
                $out[(string) $r['d']] = ['day' => (string) $r['d'], 'plays' => (int) $r['plays'], 'seconds' => (int) $r['seconds'], 'tvs' => (int) $r['tvs']];
            }
        }
        return array_values($out);
    }

    /** Plays + airtime per TV (device), sorted by room. */
    public static function perTv(array $f): array
    {
        [$w, $p] = self::where($f);
        $rows = DB::all(
            "SELECT x.device_id, x.room_id, x.plays, x.seconds, x.items, x.first_at, x.last_at, r.room_number, d.model, d.device_uid
             FROM (SELECT l.device_id, l.room_id, COUNT(*) AS plays, COALESCE(SUM(l.duration_sec), 0) AS seconds,
                          COUNT(DISTINCT l.content_id) AS items, MIN(l.created_at) AS first_at, MAX(l.created_at) AS last_at
                   FROM broadcast_logs l WHERE $w GROUP BY l.device_id, l.room_id) x
             LEFT JOIN rooms r ON r.id = x.room_id AND r.hotel_id = :h2
             LEFT JOIN devices d ON d.id = x.device_id AND d.hotel_id = :h3",
            $p + ['h2' => Tenant::id(), 'h3' => Tenant::id()]
        );
        usort($rows, fn ($a, $b) => strnatcmp((string) $a['room_number'], (string) $b['room_number']) ?: ((int) $a['device_id'] <=> (int) $b['device_id']));
        return array_map(static fn ($r) => [
            'device_id' => (int) $r['device_id'], 'room_id' => (int) $r['room_id'],
            'room' => (string) ($r['room_number'] ?? '-'), 'tv' => trim((string) ($r['model'] ?? '') . ' ' . ($r['device_uid'] ? '· ' . substr((string) $r['device_uid'], -6) : '')),
            'plays' => (int) $r['plays'], 'seconds' => (int) $r['seconds'], 'items' => (int) $r['items'],
            'first_at' => (string) $r['first_at'], 'last_at' => (string) $r['last_at'],
        ], $rows);
    }

    /** Plays + airtime per content item, most played first. */
    public static function perContent(array $f, int $limit = 200): array
    {
        [$w, $p] = self::where($f);
        return array_map(static fn ($r) => [
            'content_id' => (int) $r['content_id'],
            'title' => $r['title'] !== null ? (string) $r['title'] : '#' . $r['content_id'] . ' (' . __('deleted') . ')',
            'type' => (string) ($r['type'] ?? ''),
            'plays' => (int) $r['plays'], 'seconds' => (int) $r['seconds'], 'tvs' => (int) $r['tvs'],
        ], DB::all(
            "SELECT x.*, c.title, c.type FROM (
                SELECT l.content_id, COUNT(*) AS plays, COALESCE(SUM(l.duration_sec), 0) AS seconds, COUNT(DISTINCT l.device_id) AS tvs
                FROM broadcast_logs l WHERE $w AND l.content_id IS NOT NULL GROUP BY l.content_id) x
             LEFT JOIN content_items c ON c.id = x.content_id AND c.hotel_id = :h2
             ORDER BY x.plays DESC, x.seconds DESC LIMIT " . max(1, min(5000, $limit)),
            $p + ['h2' => Tenant::id()]
        ));
    }

    public static function count(array $f): int
    {
        [$w, $p] = self::where($f);
        return (int) DB::value("SELECT COUNT(*) FROM broadcast_logs l WHERE $w", $p);
    }

    /** One page of plays (newest first). */
    public static function rows(array $f, int $page, int $perPage = self::PER_PAGE): array
    {
        $perPage = max(1, min(1000, $perPage));
        $offset = max(0, $page - 1) * $perPage;
        return self::fetch($f, '', [], 'LIMIT ' . $perPage . ' OFFSET ' . $offset);
    }

    /** Every play of the filters, newest first, in (time, id) keyset chunks (CSV export of large ranges). */
    public static function iterate(array $f): Generator
    {
        $last = null;
        do {
            $rows = $last === null
                ? self::fetch($f, '', [], 'LIMIT ' . self::CHUNK)
                : self::fetch($f, ' AND (l.created_at < :kat OR (l.created_at = :kat2 AND l.id < :kid))', ['kat' => $last['played_at'], 'kat2' => $last['played_at'], 'kid' => $last['id']], 'LIMIT ' . self::CHUNK);
            foreach ($rows as $r) {
                yield $r;
            }
            $last = $rows ? end($rows) : null;
        } while (count($rows) === self::CHUNK);
    }

    private static function fetch(array $f, string $extra, array $extraParams, string $limit): array
    {
        [$w, $p] = self::where($f);
        $hid = Tenant::id();
        $ads = Tenant::isTenantTable('ad_campaigns');
        $rows = DB::all(
            "SELECT l.id, l.created_at, l.duration_sec, l.content_id, l.device_id, l.room_id, l.ad_campaign_id,
                    c.title, c.type, r.room_number, d.model, d.device_uid" . ($ads ? ', a.name AS campaign' : ', NULL AS campaign') . "
             FROM broadcast_logs l
             LEFT JOIN content_items c ON c.id = l.content_id AND c.hotel_id = :h2
             LEFT JOIN rooms r ON r.id = l.room_id AND r.hotel_id = :h3
             LEFT JOIN devices d ON d.id = l.device_id AND d.hotel_id = :h4" .
             ($ads ? ' LEFT JOIN ad_campaigns a ON a.id = l.ad_campaign_id AND a.hotel_id = :h5' : '') . "
             WHERE $w$extra ORDER BY l.created_at DESC, l.id DESC $limit",
            $p + $extraParams + ['h2' => $hid, 'h3' => $hid, 'h4' => $hid] + ($ads ? ['h5' => $hid] : [])
        );
        return array_map(static fn ($r) => [
            'id' => (int) $r['id'],
            'played_at' => (string) $r['created_at'],
            'seconds' => $r['duration_sec'] !== null ? (int) $r['duration_sec'] : null,
            'content_id' => (int) $r['content_id'],
            'title' => $r['title'] !== null ? (string) $r['title'] : ($r['content_id'] ? '#' . $r['content_id'] . ' (' . __('deleted') . ')' : '-'),
            'type' => (string) ($r['type'] ?? ''),
            'room' => (string) ($r['room_number'] ?? '-'),
            'tv' => trim((string) ($r['model'] ?? '') . ($r['device_uid'] ? ' · ' . substr((string) $r['device_uid'], -6) : '')),
            'campaign' => (string) ($r['campaign'] ?? ''),
        ], $rows);
    }

    /** Human description of the active filters (print view / CSV name). */
    public static function describe(array $f): array
    {
        $hid = ['h' => Tenant::id()];
        $out = [];
        if ($f['content_id']) {
            $out[__('Content')] = (string) DB::value('SELECT title FROM content_items WHERE id = :id AND hotel_id = :h', ['id' => $f['content_id']] + $hid);
        }
        if ($f['playlist_id']) {
            $out[__('Playlist')] = (string) DB::value('SELECT name FROM content_playlists WHERE id = :id AND hotel_id = :h', ['id' => $f['playlist_id']] + $hid);
        }
        if ($f['room_id']) {
            $out[__('Screen')] = (string) DB::value('SELECT room_number FROM rooms WHERE id = :id AND hotel_id = :h', ['id' => $f['room_id']] + $hid);
        }
        if ($f['group_id']) {
            $out[__('Group')] = (string) DB::value('SELECT name FROM room_groups WHERE id = :id AND hotel_id = :h', ['id' => $f['group_id']] + $hid);
        }
        if ($f['campaign_id']) {
            $out[__('Ad campaign')] = (string) DB::value('SELECT name FROM ad_campaigns WHERE id = :id AND hotel_id = :h', ['id' => $f['campaign_id']] + $hid);
        }
        if (Access::restricted()) {
            $out[__('TVs')] = __('Only your TVs');
        }
        return $out;
    }
}
