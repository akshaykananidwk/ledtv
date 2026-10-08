<?php
declare(strict_types=1);

/**
 * Sponsors & ad campaigns (V2_SPEC §3, #11).
 *
 * - CRUD helpers for sponsors / ad_campaigns (always scoped to the current hotel).
 * - apply(): called by core/Extensions/AdsExtension.php — inserts the ad items of all eligible campaigns
 *   into a room's playlist ("after every N items" or "every M minutes"), or turns a single item into a
 *   rotation [main item for M minutes, ad, …]. Ad items carry "ad_campaign_id"; the TV reports them in
 *   POST /api/device/played and DeviceManager::played() stores the id in broadcast_logs.ad_campaign_id.
 * - report(): impressions / screen time / rooms per day for the sponsor report (billing). Days before
 *   today come from the ad_stats_daily roll-up so they survive the broadcast_logs retention.
 */
final class Ads
{
    /** Content modes in which ads may be inserted (never: emergency, off, suspended, empty/welcome, preview). */
    public const AD_MODES = ['assigned', 'group', 'default', 'scheduled'];
    public const STATUSES = ['active', 'paused'];
    /** Ads shown back-to-back at one break at most. */
    public const MAX_ADS_PER_BREAK = 3;
    /** Screen time of an ad whose content item has duration 0 (videos keep 0 = play to the end). */
    public const DEFAULT_AD_SECONDS = 15;
    /** Assumed length of a playlist item with duration 0 when counting "every M minutes". */
    public const DEFAULT_ITEM_SECONDS = 10;
    /** Rotation length for single items when no campaign gives minutes (never happens: freq_minutes is required). */
    public const DEFAULT_ROTATION_MINUTES = 10;

    public static function enabled(): bool
    {
        return Tenant::feature('ads');
    }

    // ------------------------------------------------------------------ sponsors

    public static function sponsors(): array
    {
        return DB::all(
            'SELECT s.*, (SELECT COUNT(*) FROM ad_campaigns c WHERE c.sponsor_id = s.id AND c.hotel_id = s.hotel_id) AS campaign_count
             FROM sponsors s WHERE s.hotel_id = :h ORDER BY s.name',
            ['h' => Tenant::id()]
        );
    }

    public static function findSponsor(int $id): ?array
    {
        return Tenant::find('sponsors', $id);
    }

    /** @return array{0: array, 1: string[]} */
    public static function validateSponsor(array $in): array
    {
        $errors = [];
        $str = static fn (string $k, int $max) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
        $data = [
            'name' => $str('name', 150),
            'contact_name' => $str('contact_name', 120) ?: null,
            'phone' => $str('phone', 40) ?: null,
            'email' => $str('email', 190) ?: null,
            'contract_start' => self::date($in['contract_start'] ?? null),
            'contract_end' => self::date($in['contract_end'] ?? null),
            'notes' => $str('notes', 1000) ?: null,
        ];
        if ($data['name'] === '') {
            $errors[] = __('Sponsor name is required.');
        }
        if ($data['email'] !== null && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = __('Invalid email address.');
        }
        if ($data['phone'] !== null && !preg_match('/^[0-9+() .\-]{3,40}$/', $data['phone'])) {
            $errors[] = __('Invalid phone number.');
        }
        if ($data['contract_start'] && $data['contract_end'] && $data['contract_end'] < $data['contract_start']) {
            $errors[] = __('Contract end must be after the start.');
        }
        return [$data, $errors];
    }

    public static function saveSponsor(?int $id, array $data): int
    {
        if ($id) {
            DB::update('sponsors', $data, 'id = :id', ['id' => $id]);
            return $id;
        }
        return DB::insert('sponsors', $data + ['created_at' => now()]);
    }

    public static function deleteSponsor(int $id): void
    {
        if (self::findSponsor($id)) {
            DB::delete('ad_campaigns', 'sponsor_id = :s', ['s' => $id]);
            DB::delete('sponsors', 'id = :id', ['id' => $id]);
            Settings::bumpContentVersion();
        }
    }

    // ------------------------------------------------------------------ campaigns

    public static function campaigns(?int $sponsorId = null): array
    {
        $p = ['h' => Tenant::id()];
        $where = 'c.hotel_id = :h';
        if ($sponsorId) {
            $where .= ' AND c.sponsor_id = :s';
            $p['s'] = $sponsorId;
        }
        return DB::all(
            "SELECT c.*, s.name AS sponsor_name, ci.title AS content_title, ci.type AS content_type, ci.is_active AS content_active
             FROM ad_campaigns c
             JOIN sponsors s ON s.id = c.sponsor_id AND s.hotel_id = c.hotel_id
             LEFT JOIN content_items ci ON ci.id = c.content_id AND ci.hotel_id = c.hotel_id
             WHERE $where ORDER BY c.status, c.priority DESC, c.id DESC",
            $p
        );
    }

    public static function findCampaign(int $id): ?array
    {
        return Tenant::find('ad_campaigns', $id);
    }

    /**
     * Validate campaign form input. Sponsor / content / rooms / groups of another hotel → 404 (Tenant::deny).
     * @return array{0: array, 1: string[]}
     */
    public static function validateCampaign(array $in): array
    {
        $errors = [];
        $sponsorId = (int) ($in['sponsor_id'] ?? 0);
        $contentId = (int) ($in['content_id'] ?? 0);
        if (!$sponsorId || !self::findSponsor($sponsorId)) {
            $errors[] = __('Choose a sponsor.');
        }
        if (!$contentId || !Tenant::find('content_items', $contentId)) {
            $errors[] = __('Choose the ad content.');
        }
        [$targetType, $targetIds] = Broadcaster::parseTarget($in + ['target_type' => 'all']);
        if ($targetType !== 'all' && !$targetIds) {
            $errors[] = __('Select at least one target.');
        }
        $start = self::date($in['start_date'] ?? null) ?? date('Y-m-d');
        $end = self::date($in['end_date'] ?? null);
        if ($end !== null && $end < $start) {
            $errors[] = __('End date must be on or after the start date.');
        }
        $ds = self::time($in['daily_start'] ?? null);
        $de = self::time($in['daily_end'] ?? null);
        if (($ds === null) !== ($de === null) || ($ds !== null && $ds === $de)) {
            $errors[] = __('Give both daily times (from and to) or leave both empty.');
            $ds = $de = null;
        }
        $freqType = ($in['freq_type'] ?? 'items') === 'minutes' ? 'minutes' : 'items';
        $freqItems = max(1, min(100, (int) ($in['freq_items'] ?? 3)));
        $freqMinutes = max(1, min(720, (int) ($in['freq_minutes'] ?? self::DEFAULT_ROTATION_MINUTES)));
        $max = trim((string) ($in['max_per_day'] ?? ''));
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 150);
        if ($name === '') {
            $errors[] = __('Campaign name is required.');
        }
        return [[
            'sponsor_id' => $sponsorId,
            'name' => $name,
            'content_id' => $contentId ?: null,
            'start_date' => $start,
            'end_date' => $end,
            'daily_start' => $ds,
            'daily_end' => $de,
            'target_type' => $targetType,
            'target_ids' => json_out(array_values($targetIds)),
            'freq_items' => $freqType === 'items' ? $freqItems : null,
            'freq_minutes' => $freqMinutes,
            'max_per_day' => $max === '' || (int) $max <= 0 ? null : min(100000, (int) $max),
            'priority' => max(-100, min(100, (int) ($in['priority'] ?? 0))),
            'status' => in_array($in['status'] ?? 'active', self::STATUSES, true) ? (string) ($in['status'] ?? 'active') : 'active',
        ], $errors];
    }

    public static function saveCampaign(?int $id, array $data, ?int $userId = null): int
    {
        if ($id) {
            DB::update('ad_campaigns', $data, 'id = :id', ['id' => $id]);
        } else {
            $id = DB::insert('ad_campaigns', $data + ['created_by' => $userId, 'created_at' => now()]);
        }
        Settings::bumpContentVersion();
        return $id;
    }

    public static function setStatus(int $id, string $status): void
    {
        if (in_array($status, self::STATUSES, true) && self::findCampaign($id)) {
            DB::update('ad_campaigns', ['status' => $status], 'id = :id', ['id' => $id]);
            Settings::bumpContentVersion();
        }
    }

    public static function deleteCampaign(int $id): void
    {
        if (self::findCampaign($id)) {
            DB::delete('ad_campaigns', 'id = :id', ['id' => $id]);
            DB::delete('ad_stats_daily', 'campaign_id = :id', ['id' => $id]);
            Settings::bumpContentVersion();
        }
    }

    /** "every 3 items" / "every 10 min" for lists. */
    public static function frequencyLabel(array $c): string
    {
        $parts = [];
        if ($c['freq_items']) {
            $parts[] = __('after every :n items', ['n' => (int) $c['freq_items']]);
        }
        $parts[] = ($c['freq_items'] ? __('single items:') . ' ' : '') . __('every :m min', ['m' => (int) $c['freq_minutes']]);
        return implode(' · ', $parts);
    }

    /** Is the campaign running at $ts (status, date range, daily window)? Content is checked separately. */
    public static function isLive(array $c, ?int $ts = null): bool
    {
        $ts ??= time();
        $day = date('Y-m-d', $ts);
        if ($c['status'] !== 'active' || $c['start_date'] > $day || ($c['end_date'] !== null && $c['end_date'] < $day)) {
            return false;
        }
        if (!empty($c['daily_start']) && !empty($c['daily_end'])) {
            return ContentResolver::windowActive(['daily_start' => $c['daily_start'], 'daily_end' => $c['daily_end']], $ts);
        }
        return true;
    }

    // ------------------------------------------------------------------ TV content

    /** Running campaigns of the current hotel with their (active) ad content rows, by priority. */
    public static function liveCampaigns(?int $ts = null): array
    {
        $ts ??= time();
        $day = date('Y-m-d', $ts);
        $rows = DB::all(
            "SELECT c.*, ci.id AS ci_id, ci.title AS ci_title, ci.type AS ci_type, ci.file_path AS ci_file_path, ci.url AS ci_url,
                    ci.body AS ci_body, ci.settings AS ci_settings, ci.duration AS ci_duration,
                    ci.approval_status AS ci_approval_status, ci.valid_from AS ci_valid_from, ci.valid_to AS ci_valid_to
             FROM ad_campaigns c
             JOIN content_items ci ON ci.id = c.content_id AND ci.hotel_id = c.hotel_id AND ci.is_active = 1
             WHERE c.hotel_id = :h AND c.status = 'active' AND c.start_date <= :d AND (c.end_date IS NULL OR c.end_date >= :d2)
             ORDER BY c.priority DESC, c.id",
            ['h' => Tenant::id(), 'd' => $day, 'd2' => $day]
        );
        // 2.4 security review: an ad is content on air too — only approved items inside their validity window.
        return array_values(array_filter($rows, fn ($c) => self::isLive($c, $ts) && ContentRules::playable(
            ['is_active' => 1, 'approval_status' => $c['ci_approval_status'] ?? 'approved', 'valid_from' => $c['ci_valid_from'] ?? null, 'valid_to' => $c['ci_valid_to'] ?? null],
            $ts
        )));
    }

    /** TV ContentItem of a campaign's ad (with ad_campaign_id). */
    public static function adItem(array $c): array
    {
        $row = [
            'id' => $c['ci_id'], 'title' => $c['ci_title'], 'type' => $c['ci_type'], 'file_path' => $c['ci_file_path'],
            'url' => $c['ci_url'], 'body' => $c['ci_body'], 'settings' => $c['ci_settings'], 'duration' => $c['ci_duration'],
        ];
        $item = ContentManager::toTvItem($row);
        if ((int) $item['duration'] <= 0 && $item['type'] !== 'video') {
            $item['duration'] = self::DEFAULT_AD_SECONDS;
        }
        $item['ad_campaign_id'] = (int) $c['id'];
        return $item;
    }

    /**
     * Impressions today per campaign for a room: the busiest TV of the room counts ("max per TV per day").
     * @return array<int, int> campaign id => impressions
     */
    public static function impressionsToday(array $campaignIds, int $roomId, ?int $ts = null): array
    {
        $campaignIds = array_values(array_filter(array_map('intval', $campaignIds)));
        if (!$campaignIds) {
            return [];
        }
        $ts ??= time();
        [$in, $p] = DB::in($campaignIds, 'ac');
        $rows = DB::all(
            "SELECT ad_campaign_id, device_id, COUNT(*) AS n FROM broadcast_logs
             WHERE hotel_id = :h AND room_id = :r AND event = 'played' AND ad_campaign_id IN $in AND created_at >= :from AND created_at < :to
             GROUP BY ad_campaign_id, device_id",
            $p + ['h' => Tenant::id(), 'r' => $roomId, 'from' => date('Y-m-d 00:00:00', $ts), 'to' => date('Y-m-d 00:00:00', strtotime('+1 day', $ts))]
        );
        $out = [];
        foreach ($rows as $r) {
            $cid = (int) $r['ad_campaign_id'];
            $out[$cid] = max($out[$cid] ?? 0, (int) $r['n']);
        }
        return $out;
    }

    /** Campaigns that target this room right now and are below their daily cap. */
    public static function eligibleFor(array $room, ?int $ts = null, ?array $groupIds = null): array
    {
        $live = self::liveCampaigns($ts);
        if (!$live) {
            return [];
        }
        $groupIds ??= array_map('intval', DB::column(
            'SELECT m.group_id FROM room_group_members m JOIN room_groups g ON g.id = m.group_id WHERE m.room_id = :r AND g.hotel_id = :h',
            ['r' => $room['id'], 'h' => Tenant::id()]
        ));
        $live = array_values(array_filter($live, fn ($c) => ContentResolver::targets($c, $room, $groupIds)));
        $capped = array_filter($live, fn ($c) => $c['max_per_day'] !== null);
        if ($capped) {
            $counts = self::impressionsToday(array_map(fn ($c) => (int) $c['id'], $capped), (int) $room['id'], $ts);
            $live = array_values(array_filter($live, fn ($c) => $c['max_per_day'] === null || ($counts[(int) $c['id']] ?? 0) < (int) $c['max_per_day']));
        }
        return $live;
    }

    /**
     * Insert ads into the room's Content object (called by AdsExtension). Never during emergency,
     * power-off, suspended or the idle welcome / empty screen. The personal welcome card (#1) needs no
     * server rule: the TV app pauses the playlist while the card is visible (GuestUi.blocksPlayback), and
     * GuestExtension (runs later) empties the items when it switches a vacant room off / to the idle screen.
     */
    public static function apply(array &$content, array $room, ?int $ts = null): void
    {
        if (!self::enabled() || !in_array($content['mode'] ?? '', self::AD_MODES, true) || empty($content['items'])
            || empty($content['screen_on']) || !empty($content['emergency'])) {
            return;
        }
        $campaigns = self::eligibleFor($room, $ts);
        if (!$campaigns) {
            return;
        }
        $content['items'] = $content['playlist'] === null && count($content['items']) === 1
            ? self::rotation($content['items'][0], $campaigns)
            : self::insertIntoPlaylist($content['items'], $campaigns);
        $content['ads'] = array_map(fn ($c) => (int) $c['id'], $campaigns);
    }

    /**
     * Single item (normally duration 0 = stays forever) → rotation: the main item for M minutes (the
     * smallest freq_minutes of the eligible campaigns), then up to MAX_ADS_PER_BREAK ads, then again.
     */
    public static function rotation(array $main, array $campaigns): array
    {
        $minutes = min(array_map(fn ($c) => max(1, (int) $c['freq_minutes']), $campaigns)) ?: self::DEFAULT_ROTATION_MINUTES;
        $main['duration'] = $minutes * 60;
        $items = [$main];
        foreach (array_slice($campaigns, 0, self::MAX_ADS_PER_BREAK) as $c) {
            $items[] = self::adItem($c);
        }
        return $items;
    }

    /**
     * Playlist: walk the items, count items (freq_items) or seconds (freq_minutes; duration 0 counts as
     * DEFAULT_ITEM_SECONDS) per campaign and insert the ad when due. Every campaign appears at least once
     * per playlist loop (a playlist shorter than N items gets the ad at its end). At most
     * MAX_ADS_PER_BREAK ads per break; campaigns that did not fit wait for the next break.
     */
    public static function insertIntoPlaylist(array $items, array $campaigns): array
    {
        $out = [];
        $count = [];
        $secs = [];
        $placed = [];
        $last = count($items) - 1;
        foreach (array_values($items) as $i => $item) {
            $out[] = $item;
            $d = (int) ($item['duration'] ?? 0) > 0 ? (int) $item['duration'] : self::DEFAULT_ITEM_SECONDS;
            $due = [];
            foreach ($campaigns as $c) {
                $id = (int) $c['id'];
                if ($c['freq_items']) {
                    $count[$id] = ($count[$id] ?? 0) + 1;
                    $isDue = $count[$id] >= (int) $c['freq_items'];
                } else {
                    $secs[$id] = ($secs[$id] ?? 0) + $d;
                    $isDue = $secs[$id] >= (int) $c['freq_minutes'] * 60;
                }
                if ($isDue || ($i === $last && empty($placed[$id]))) {
                    $due[] = $c;
                }
            }
            foreach ($due as $n => $c) {
                $id = (int) $c['id'];
                if ($n >= self::MAX_ADS_PER_BREAK && $i !== $last) {
                    continue; // wait for the next break
                }
                $out[] = self::adItem($c);
                $placed[$id] = true;
                $count[$id] = 0;
                $secs[$id] = 0;
            }
        }
        return $out;
    }

    /** Remove inserted ads again (for extensions that later switch a room to a non-ad mode). */
    public static function strip(array &$content): void
    {
        if (!empty($content['items'])) {
            $content['items'] = array_values(array_filter($content['items'], fn ($i) => empty($i['ad_campaign_id'])));
        }
        unset($content['ads']);
    }

    /**
     * ad_campaign_id of a played item if it is a campaign of the current hotel (else null). Used by
     * DeviceManager::played(); never throws (e.g. before the migration ran).
     */
    public static function impressionCampaign(mixed $value): ?int
    {
        static $known = [];
        $id = is_numeric($value) ? (int) $value : 0;
        if ($id <= 0) {
            return null;
        }
        try {
            $key = Tenant::id() . ':' . $id;
            if (!array_key_exists($key, $known)) {
                $known[$key] = (bool) DB::value('SELECT 1 FROM ad_campaigns WHERE id = :id AND hotel_id = :h', ['id' => $id, 'h' => Tenant::id()]);
            }
            return $known[$key] ? $id : null;
        } catch (Throwable) {
            return null;
        }
    }

    // ------------------------------------------------------------------ reports

    /**
     * Roll raw impressions (broadcast_logs) up into ad_stats_daily for the completed days in [from, to]
     * that still have raw logs (newer than the log retention). Idempotent. Returns rows written.
     */
    public static function rollup(string $from, string $to): int
    {
        $hid = Tenant::id();
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $retention = max(7, Settings::int('log_retention_days', 90));
        $oldestComplete = date('Y-m-d', strtotime('-' . ($retention - 1) . ' days'));
        $from = max($from, $oldestComplete);
        $to = min($to, $yesterday);
        if ($from > $to) {
            return 0;
        }
        return (int) DB::transaction(function () use ($hid, $from, $to) {
            DB::query('DELETE FROM ad_stats_daily WHERE hotel_id = :h AND day BETWEEN :f AND :t', ['h' => $hid, 'f' => $from, 't' => $to]);
            return DB::query(
                "INSERT INTO ad_stats_daily (hotel_id, campaign_id, day, room_id, impressions, seconds)
                 SELECT l.hotel_id, l.ad_campaign_id, DATE(l.created_at), COALESCE(l.room_id, 0), COUNT(*), COALESCE(SUM(l.duration_sec), 0)
                 FROM broadcast_logs l JOIN ad_campaigns c ON c.id = l.ad_campaign_id AND c.hotel_id = l.hotel_id
                 WHERE l.hotel_id = :h AND l.event = 'played' AND l.ad_campaign_id IS NOT NULL AND l.created_at >= :f AND l.created_at < :t
                 GROUP BY l.hotel_id, l.ad_campaign_id, DATE(l.created_at), COALESCE(l.room_id, 0)",
                ['h' => $hid, 'f' => $from . ' 00:00:00', 't' => date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00']
            )->rowCount();
        });
    }

    /**
     * Sponsor report for campaigns of the current hotel between two dates (Y-m-d, inclusive).
     * Returns totals, per_day (every day of the range), per_room, per_campaign.
     */
    public static function report(array $campaignIds, string $from, string $to): array
    {
        $hid = Tenant::id();
        $campaignIds = Tenant::assertOwnsAll('ad_campaigns', $campaignIds);
        $empty = ['totals' => ['impressions' => 0, 'seconds' => 0, 'rooms' => 0, 'days_active' => 0], 'per_day' => [], 'per_room' => [], 'per_campaign' => []];
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $empty['per_day'][$d] = ['day' => $d, 'impressions' => 0, 'seconds' => 0, 'rooms' => 0];
        }
        if (!$campaignIds) {
            $empty['per_day'] = array_values($empty['per_day']);
            return $empty;
        }
        self::rollup($from, $to);
        $today = date('Y-m-d');
        [$in, $p] = DB::in($campaignIds, 'rc');
        $rows = DB::all(
            "SELECT campaign_id, day, room_id, impressions, seconds FROM ad_stats_daily
             WHERE hotel_id = :h AND campaign_id IN $in AND day BETWEEN :f AND :t AND day < :today",
            $p + ['h' => $hid, 'f' => $from, 't' => $to, 'today' => $today]
        );
        if ($to >= $today && $from <= $today) {
            $rows = array_merge($rows, DB::all(
                "SELECT ad_campaign_id AS campaign_id, DATE(created_at) AS day, COALESCE(room_id, 0) AS room_id, COUNT(*) AS impressions, COALESCE(SUM(duration_sec), 0) AS seconds
                 FROM broadcast_logs WHERE hotel_id = :h AND event = 'played' AND ad_campaign_id IN $in AND created_at >= :f
                 GROUP BY ad_campaign_id, DATE(created_at), COALESCE(room_id, 0)",
                $p + ['h' => $hid, 'f' => $today . ' 00:00:00']
            ));
        }
        $rooms = [];
        foreach (DB::all('SELECT id, room_number FROM rooms WHERE hotel_id = :h', ['h' => $hid]) as $r) {
            $rooms[(int) $r['id']] = (string) $r['room_number'];
        }
        $names = [];
        foreach (DB::all("SELECT id, name FROM ad_campaigns WHERE hotel_id = :h AND id IN $in", $p + ['h' => $hid]) as $c) {
            $names[(int) $c['id']] = (string) $c['name'];
        }
        $out = $empty;
        $dayRooms = [];
        $allRooms = [];
        foreach ($rows as $r) {
            $day = (string) $r['day'];
            if (!isset($out['per_day'][$day])) {
                continue;
            }
            $n = (int) $r['impressions'];
            $s = (int) $r['seconds'];
            $rid = (int) $r['room_id'];
            $cid = (int) $r['campaign_id'];
            $out['per_day'][$day]['impressions'] += $n;
            $out['per_day'][$day]['seconds'] += $s;
            $dayRooms[$day][$rid] = true;
            $allRooms[$rid] = true;
            $out['per_room'][$rid] ??= ['room_id' => $rid, 'room' => $rooms[$rid] ?? ($rid ? '#' . $rid : '-'), 'impressions' => 0, 'seconds' => 0];
            $out['per_room'][$rid]['impressions'] += $n;
            $out['per_room'][$rid]['seconds'] += $s;
            $out['per_campaign'][$cid] ??= ['campaign_id' => $cid, 'name' => $names[$cid] ?? '#' . $cid, 'impressions' => 0, 'seconds' => 0, 'rooms' => []];
            $out['per_campaign'][$cid]['impressions'] += $n;
            $out['per_campaign'][$cid]['seconds'] += $s;
            $out['per_campaign'][$cid]['rooms'][$rid] = true;
            $out['totals']['impressions'] += $n;
            $out['totals']['seconds'] += $s;
        }
        foreach ($dayRooms as $day => $set) {
            $out['per_day'][$day]['rooms'] = count($set);
        }
        foreach ($out['per_campaign'] as &$pc) {
            $pc['rooms'] = count($pc['rooms']);
        }
        unset($pc);
        $out['totals']['rooms'] = count($allRooms);
        $out['totals']['days_active'] = count($dayRooms);
        $out['per_day'] = array_values($out['per_day']);
        $perRoom = array_values($out['per_room']);
        usort($perRoom, fn ($a, $b) => $b['impressions'] <=> $a['impressions'] ?: strnatcmp($a['room'], $b['room']));
        $out['per_room'] = $perRoom;
        $out['per_campaign'] = array_values($out['per_campaign']);
        return $out;
    }

    /** Impressions today in the current hotel (dashboard). */
    public static function impressionsTodayTotal(): int
    {
        return (int) DB::value(
            "SELECT COUNT(*) FROM broadcast_logs WHERE hotel_id = :h AND event = 'played' AND ad_campaign_id IS NOT NULL AND created_at >= :f",
            ['h' => Tenant::id(), 'f' => date('Y-m-d 00:00:00')]
        );
    }

    // ------------------------------------------------------------------ helpers

    public static function date(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : '';
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return $v;
    }

    public static function time(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : '';
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $v, $m)) {
            return null;
        }
        return sprintf('%02d:%02d:00', (int) $m[1], (int) $m[2]);
    }

    /** "2h 05m" / "4m 10s" for screen time. */
    public static function duration(int $seconds): string
    {
        if ($seconds >= 3600) {
            return sprintf('%dh %02dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
        }
        return sprintf('%dm %02ds', intdiv($seconds, 60), $seconds % 60);
    }
}
