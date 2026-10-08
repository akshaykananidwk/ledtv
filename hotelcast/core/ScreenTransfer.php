<?php
declare(strict_types=1);

/**
 * 2.5.1 "Transfer everything" (docs/modules/platform_screens.md § 2b). When PlatformScreens::transfer()
 * moves a TV to ANOTHER customer, this class copies what belongs to the TV's screen into the target:
 *
 *  - details  (option 'details'): the screen's name, area / floor, on/off, settings PIN, notes, USB mode,
 *             HDMI-CEC mode, plus its own power-off windows and device schedules (volume, input, restart,
 *             bell with a built-in sound, spoken announcement) that target exactly this screen;
 *  - content  (option 'content'): the content the screen plays — its own assignment, else the group
 *             assignment it follows, else the customer's default content — as new rows of the target
 *             (playlist + items, split screen layouts with their zone sources, media files copied
 *             physically into uploads/h{target}/media/…), assigned directly to the target screen; the
 *             tickers aimed at this screen and its scheduled content windows.
 *
 * Copy only: the old customer's rows and files are never changed. Every source row is read with
 * hotel_id = the old customer (never another tenant); a media path of a third customer is refused.
 * Display apps are not copied (their data lives in the old customer's module tables) and are listed
 * as "not copied", like everything outside the target's plan or a missing media file.
 *
 * Runs inside the move transaction. Files written are recorded in $ctx['files']; the caller removes
 * them (cleanup()) when the transaction fails, so a failed transfer leaves neither rows nor files.
 */
final class ScreenTransfer
{
    /** Columns of `rooms` copied as screen details (room_number is the screen ID, kept by mode "same"). */
    public const DETAIL_FIELDS = ['name', 'floor', 'is_enabled', 'settings_pin', 'notes', 'usb_mode', 'cec_mode'];
    /** Content types that are copied as plain rows (no file, no reference to other rows). */
    private const PLAIN_TYPES = ['stream', 'timetable', 'announcement', 'html', 'url', 'youtube', 'clock'];
    private const MAX_DEPTH = 3;

    /** Options from input: ['details' => bool, 'content' => bool]. */
    public static function options(array $in): array
    {
        return ['details' => !empty($in['details']), 'content' => !empty($in['content'])];
    }

    /** New transfer context for one target customer. */
    public static function context(int $to): array
    {
        return [
            'to' => $to,
            'map' => ['c' => [], 'p' => []], // "from:id" => new id (or 0 = could not be copied)
            'files' => [],
            'bytes' => 0,
            'pairs' => [],
            'copied' => ['screens' => 0, 'playlists' => 0, 'items' => 0, 'media' => 0, 'layouts' => 0, 'tickers' => 0, 'schedules' => 0],
            'skipped' => [],
            'via' => [],
        ];
    }

    // ------------------------------------------------------------------ collect (read only)

    /**
     * What would be copied for $room (row of customer $from): source assignment, content items,
     * playlists, tickers, windows, device schedules. Every query is limited to hotel_id = $from.
     */
    public static function collect(array $room, int $from, array $opt): array
    {
        $out = ['room' => $room, 'from' => $from, 'source' => null, 'via' => '', 'items' => [], 'playlists' => [], 'pl_items' => [],
            'tickers' => [], 'windows' => [], 'schedules' => [], 'other_schedules' => 0];
        $rid = (int) $room['id'];
        if ($opt['content']) {
            $src = self::sourceOf($room, $from);
            if ($src) {
                $out['source'] = $src;
                $out['via'] = $src['via'];
                self::addSource($out, $src['content_id'], $src['playlist_id'], 0);
            }
            $out['tickers'] = DB::all("SELECT * FROM tickers WHERE hotel_id = :h AND target_type = 'room' AND target_id = :r ORDER BY id", ['h' => $from, 'r' => $rid]);
        }
        $windows = DB::all(
            "SELECT * FROM broadcast_commands WHERE hotel_id = :h AND mode = 'window' AND is_emergency = 0
               AND status IN ('scheduled','active') AND command IN ('SHOW_CONTENT','SCREEN_OFF') ORDER BY id",
            ['h' => $from]
        );
        foreach ($windows as $b) {
            $mine = self::targetsOnly($b, $rid);
            if ($mine === null) {
                continue;
            }
            $isContent = $b['command'] === 'SHOW_CONTENT';
            if (!($isContent ? $opt['content'] : $opt['details'])) {
                continue;
            }
            if (!$mine) {
                $out['other_schedules']++;
                continue;
            }
            $out['windows'][] = $b;
            if ($isContent) {
                self::addSource($out, $b['content_id'] ? (int) $b['content_id'] : null, $b['playlist_id'] ? (int) $b['playlist_id'] : null, 0);
            }
        }
        if ($opt['details'] && Migrator::hasTable(DB::pdo(), 'device_schedules')) {
            foreach (DB::all('SELECT * FROM device_schedules WHERE hotel_id = :h AND is_active = 1 ORDER BY id', ['h' => $from]) as $s) {
                $mine = self::targetsOnly($s, $rid);
                if ($mine === null) {
                    continue;
                }
                if ($mine) {
                    $out['schedules'][] = $s;
                } else {
                    $out['other_schedules']++;
                }
            }
        }
        return $out;
    }

    /**
     * Does a schedule row (target_type / target_ids) reach screen $rid? null = no; true = it is aimed at
     * screens only (copied for this screen); false = through all screens / groups / floors (not copied).
     */
    private static function targetsOnly(array $row, int $rid): ?bool
    {
        $type = (string) $row['target_type'];
        if ($type === 'rooms') {
            $ids = array_map('intval', json_decode((string) ($row['target_ids'] ?? '[]'), true) ?: []);
            return in_array($rid, $ids, true) ? true : null;
        }
        if ($type === 'all') {
            return false;
        }
        return null; // groups / floors: shown as "other" only for all-screen rows (cheap, no group lookup)
    }

    /** Content source the screen plays: own assignment → first group with content → default content. */
    public static function sourceOf(array $room, int $from): ?array
    {
        $own = self::validSource($from, $room['content_id'] ? (int) $room['content_id'] : null, $room['playlist_id'] ? (int) $room['playlist_id'] : null);
        if ($own) {
            return $own + ['via' => 'screen'];
        }
        $groups = DB::all(
            'SELECT g.name, g.content_id, g.playlist_id FROM room_group_members m JOIN room_groups g ON g.id = m.group_id AND g.hotel_id = :h
             WHERE m.room_id = :r AND (g.content_id IS NOT NULL OR g.playlist_id IS NOT NULL) ORDER BY g.id',
            ['h' => $from, 'r' => (int) $room['id']]
        );
        foreach ($groups as $g) {
            $src = self::validSource($from, $g['content_id'] ? (int) $g['content_id'] : null, $g['playlist_id'] ? (int) $g['playlist_id'] : null);
            if ($src) {
                return $src + ['via' => 'group'];
            }
        }
        $def = self::validSource($from, (int) Settings::getFor($from, 'default_content_id', 0) ?: null, (int) Settings::getFor($from, 'default_playlist_id', 0) ?: null);
        return $def ? $def + ['via' => 'default'] : null;
    }

    /** [content_id, playlist_id] when they exist in customer $from (playlist preferred, like ContentResolver). */
    private static function validSource(int $from, ?int $cid, ?int $pid): ?array
    {
        if ($pid && DB::value('SELECT id FROM content_playlists WHERE id = :id AND hotel_id = :h', ['id' => $pid, 'h' => $from])) {
            return ['content_id' => null, 'playlist_id' => $pid];
        }
        if ($cid && DB::value('SELECT id FROM content_items WHERE id = :id AND hotel_id = :h', ['id' => $cid, 'h' => $from])) {
            return ['content_id' => $cid, 'playlist_id' => null];
        }
        return null;
    }

    private static function addSource(array &$out, ?int $cid, ?int $pid, int $depth): void
    {
        if ($pid) {
            self::addPlaylist($out, $pid, $depth);
        }
        if ($cid) {
            self::addItem($out, $cid, $depth);
        }
    }

    private static function addPlaylist(array &$out, int $pid, int $depth): void
    {
        if (isset($out['playlists'][$pid]) || $depth > self::MAX_DEPTH) {
            return;
        }
        $pl = DB::one('SELECT * FROM content_playlists WHERE id = :id AND hotel_id = :h', ['id' => $pid, 'h' => $out['from']]);
        if (!$pl) {
            return;
        }
        $out['playlists'][$pid] = $pl;
        $out['pl_items'][$pid] = DB::all(
            'SELECT pi.* FROM playlist_items pi JOIN content_items c ON c.id = pi.content_id AND c.hotel_id = :h
             WHERE pi.playlist_id = :p ORDER BY pi.sort_order, pi.id',
            ['p' => $pid, 'h' => $out['from']]
        );
        foreach ($out['pl_items'][$pid] as $pi) {
            self::addItem($out, (int) $pi['content_id'], $depth + 1);
        }
    }

    private static function addItem(array &$out, int $cid, int $depth): void
    {
        if (isset($out['items'][$cid]) || $depth > self::MAX_DEPTH) {
            return;
        }
        $item = DB::one('SELECT * FROM content_items WHERE id = :id AND hotel_id = :h', ['id' => $cid, 'h' => $out['from']]);
        if (!$item) {
            return;
        }
        $out['items'][$cid] = $item;
        if ($item['type'] === 'layout') {
            foreach ((array) (ContentManager::settings($item)['zones'] ?? []) as $z) {
                if (is_array($z)) {
                    self::addSource($out, !empty($z['content_id']) ? (int) $z['content_id'] : null, !empty($z['playlist_id']) ? (int) $z['playlist_id'] : null, $depth + 1);
                }
            }
        }
    }

    /** Bytes of the media files (and thumbnails) that would be copied. */
    public static function bytes(array $plan): int
    {
        $n = 0;
        foreach ($plan['items'] as $item) {
            if (in_array($item['type'], ['image', 'video'], true) && !empty($item['file_path'])) {
                foreach ([$item['file_path'], $item['thumb_path']] as $rel) {
                    $abs = $rel ? self::sourceFile((string) $rel, (int) $plan['from']) : null;
                    $n += $abs ? (int) filesize($abs) : 0;
                }
            }
        }
        return $n;
    }

    /**
     * Absolute path of an upload of customer $from, or null when the path is unsafe, belongs to another
     * customer (uploads/h{other}/…) or the file is missing. Files from before 2.0 (media/…) are allowed.
     */
    public static function sourceFile(string $rel, int $from): ?string
    {
        if ($rel === '' || str_contains($rel, '..') || str_contains($rel, "\0") || str_starts_with($rel, '/')) {
            return null;
        }
        if (preg_match('#^h(\d+)/#', $rel, $m) && (int) $m[1] !== $from) {
            return null;
        }
        $base = realpath(HC_ROOT . '/uploads');
        $abs = realpath(HC_ROOT . '/uploads/' . $rel);
        if ($base === false || $abs === false || !str_starts_with($abs, $base . DIRECTORY_SEPARATOR) || !is_file($abs)) {
            return null;
        }
        return $abs;
    }

    /** Throw RuntimeException when $bytes more would exceed the target's storage limit (Features storage_mb). */
    public static function assertStorage(int $to, int $bytes): void
    {
        if ($bytes <= 0) {
            return;
        }
        $max = Features::limits($to)['storage_mb'];
        if ($max === null) {
            return;
        }
        $used = Features::storageUsed($to);
        if ($used + $bytes > $max * 1024 * 1024) {
            throw new RuntimeException(__('STORAGE_LIMIT: :c has :u of :m MB storage in use; the content of this screen needs :n MB more. Raise the storage limit or transfer without content.', [
                'c' => (string) (DB::value('SELECT name FROM hotels WHERE id = :id', ['id' => $to]) ?? '#' . $to),
                'u' => (int) ceil($used / 1048576), 'm' => $max, 'n' => max(1, (int) ceil($bytes / 1048576)),
            ]));
        }
    }

    // ------------------------------------------------------------------ copy (inside the transaction)

    /** Copy $plan (collect()) onto $target (row of rooms in $ctx['to']). $newScreen: keep the given name. */
    public static function apply(array &$ctx, array $plan, array $target, array $opt, bool $keepName): void
    {
        $to = (int) $ctx['to'];
        $from = (int) $plan['from'];
        $pair = $from . ':' . $plan['room']['id'] . '>' . $target['id'];
        if (isset($ctx['pairs'][$pair])) {
            return; // several TVs of one screen → copied once
        }
        $ctx['pairs'][$pair] = true;
        $dst = (int) $target['id'];
        Tenant::run($to, static function () use (&$ctx, $plan, $dst, $to, $from, $opt, $keepName): void {
            if ($opt['details']) {
                $data = [];
                foreach (self::DETAIL_FIELDS as $f) {
                    if (array_key_exists($f, $plan['room']) && !($f === 'name' && $keepName)) {
                        $data[$f] = $plan['room'][$f];
                    }
                }
                if (!Features::enabled('usb_mode', $to)) {
                    unset($data['usb_mode']);
                }
                if ($data) {
                    $sets = implode(', ', array_map(static fn ($c) => "`$c` = :v_$c", array_keys($data)));
                    $params = ['id' => $dst, 'h' => $to];
                    foreach ($data as $c => $v) {
                        $params['v_' . $c] = $v;
                    }
                    DB::query("UPDATE rooms SET $sets WHERE id = :id AND hotel_id = :h", $params);
                    $ctx['copied']['screens']++;
                }
            }
            if ($opt['content'] && $plan['source']) {
                $cid = $plan['source']['content_id'] ? self::copyItem($ctx, $plan, (int) $plan['source']['content_id']) : null;
                $pid = $plan['source']['playlist_id'] ? self::copyPlaylist($ctx, $plan, (int) $plan['source']['playlist_id']) : null;
                if ($cid || $pid) {
                    DB::query('UPDATE rooms SET content_id = :c, playlist_id = :p WHERE id = :id AND hotel_id = :h', ['c' => $cid, 'p' => $pid, 'id' => $dst, 'h' => $to]);
                }
            }
            foreach ($plan['tickers'] as $t) {
                if (!Features::enabled('tickers', $to)) {
                    self::skip($ctx, __('Ticker ":t" (not in the plan of the target customer)', ['t' => $t['name'] ?: mb_substr((string) $t['message'], 0, 30)]));
                    continue;
                }
                $row = $t;
                unset($row['id'], $row['updated_at']);
                DB::insert('tickers', ['hotel_id' => $to, 'target_type' => 'room', 'target_id' => $dst, 'created_by' => null, 'created_at' => now()] + $row);
                $ctx['copied']['tickers']++;
            }
            foreach ($plan['windows'] as $b) {
                $isContent = $b['command'] === 'SHOW_CONTENT';
                $feature = $isContent ? 'schedule' : 'power_schedules';
                if (!Features::enabled($feature, $to)) {
                    self::skip($ctx, __('Schedule ":t" (not in the plan of the target customer)', ['t' => $b['title'] ?: $b['command']]));
                    continue;
                }
                $cid = $isContent && $b['content_id'] ? self::copyItem($ctx, $plan, (int) $b['content_id']) : null;
                $pid = $isContent && $b['playlist_id'] ? self::copyPlaylist($ctx, $plan, (int) $b['playlist_id']) : null;
                if ($isContent && !$cid && !$pid) {
                    self::skip($ctx, __('Schedule ":t" (its content could not be copied)', ['t' => $b['title'] ?: $b['command']]));
                    continue;
                }
                $row = $b;
                unset($row['id'], $row['updated_at']);
                DB::insert('broadcast_commands', ['hotel_id' => $to, 'target_type' => 'rooms', 'target_ids' => json_out([$dst]),
                    'content_id' => $cid, 'playlist_id' => $pid, 'created_by' => null, 'created_at' => now()] + $row);
                $ctx['copied']['schedules']++;
            }
            foreach ($plan['schedules'] as $s) {
                if (!Features::enabled('device_schedules', $to)) {
                    self::skip($ctx, __('Device schedule ":t" (not in the plan of the target customer)', ['t' => $s['title'] ?: $s['action']]));
                    continue;
                }
                $o = DeviceSchedules::opts($s);
                if ($s['action'] === 'bell' && !str_starts_with((string) ($o['sound'] ?? ''), 'b:')) {
                    self::skip($ctx, __('Device schedule ":t" (uses an uploaded sound of the old customer)', ['t' => $s['title'] ?: $s['action']]));
                    continue;
                }
                $row = $s;
                unset($row['id'], $row['updated_at']);
                DB::insert('device_schedules', ['hotel_id' => $to, 'target_type' => 'rooms', 'target_ids' => json_out([$dst]), 'active_from' => date('Y-m-d H:i:00'),
                    'last_fired_for' => null, 'last_fired_at' => null, 'last_result' => null, 'created_by' => null, 'created_at' => now()] + $row);
                $ctx['copied']['schedules']++;
            }
            if ($plan['other_schedules']) {
                self::skip($ctx, __(':n schedule(s) for all screens of the old customer', ['n' => $plan['other_schedules']]));
            }
        });
        if ($opt['content'] && $plan['via'] !== '' && $plan['via'] !== 'screen') {
            $ctx['via'][] = $plan['via'];
        }
    }

    /** Copy a playlist (and its items) once. Returns the new id. */
    private static function copyPlaylist(array &$ctx, array $plan, int $pid): ?int
    {
        $key = $plan['from'] . ':' . $pid;
        if (isset($ctx['map']['p'][$key])) {
            return $ctx['map']['p'][$key] ?: null;
        }
        $pl = $plan['playlists'][$pid] ?? null;
        if (!$pl) {
            return null;
        }
        $ctx['map']['p'][$key] = 0;
        $new = DB::insert('content_playlists', [
            'hotel_id' => $ctx['to'], 'name' => $pl['name'], 'description' => $pl['description'], 'transition' => $pl['transition'],
            'sync_playback' => (int) ($pl['sync_playback'] ?? 0), 'created_by' => null, 'created_at' => now(),
        ]);
        $ctx['map']['p'][$key] = $new;
        $ctx['copied']['playlists']++;
        foreach ($plan['pl_items'][$pid] ?? [] as $pi) {
            $cid = self::copyItem($ctx, $plan, (int) $pi['content_id']);
            if (!$cid) {
                continue;
            }
            DB::insert('playlist_items', ['playlist_id' => $new, 'content_id' => $cid, 'sort_order' => (int) $pi['sort_order'], 'duration' => $pi['duration'],
                'daypart_from' => $pi['daypart_from'], 'daypart_to' => $pi['daypart_to'], 'daypart_days' => $pi['daypart_days']]);
        }
        return $new;
    }

    /** Copy a content item once (files copied physically). Returns the new id, null when skipped. */
    private static function copyItem(array &$ctx, array $plan, int $cid): ?int
    {
        $key = $plan['from'] . ':' . $cid;
        if (isset($ctx['map']['c'][$key])) {
            return $ctx['map']['c'][$key] ?: null;
        }
        $item = $plan['items'][$cid] ?? null;
        if (!$item) {
            return null;
        }
        $ctx['map']['c'][$key] = 0; // also guards against cycles
        $to = (int) $ctx['to'];
        $title = (string) $item['title'];
        $settings = $item['settings'];
        $file = null;
        $thumb = null;
        switch ($item['type']) {
            case 'app':
                self::skip($ctx, __('Display app ":t" (its data stays with the old customer)', ['t' => $title]));
                return null;
            case 'layout':
                if (!Features::enabled('layouts', $to)) {
                    self::skip($ctx, __('Layout ":t" (not in the plan of the target customer)', ['t' => $title]));
                    return null;
                }
                $s = ContentManager::settings($item);
                foreach ((array) ($s['zones'] ?? []) as $i => $z) {
                    if (!is_array($z)) {
                        continue;
                    }
                    $zc = !empty($z['content_id']) ? self::copyItem($ctx, $plan, (int) $z['content_id']) : null;
                    $zp = !empty($z['playlist_id']) ? self::copyPlaylist($ctx, $plan, (int) $z['playlist_id']) : null;
                    if (!$zc && !$zp) {
                        self::skip($ctx, __('Layout ":t" (a zone could not be copied)', ['t' => $title]));
                        return null;
                    }
                    $s['zones'][$i]['content_id'] = $zc;
                    $s['zones'][$i]['playlist_id'] = $zc ? null : $zp;
                }
                $settings = json_out($s);
                $ctx['copied']['layouts']++;
                break;
            case 'image':
            case 'video':
                if (!empty($item['file_path'])) {
                    $src = self::sourceFile((string) $item['file_path'], (int) $plan['from']);
                    if (!$src) {
                        self::skip($ctx, __('":t" (media file missing)', ['t' => $title]));
                        return null;
                    }
                    [$rel, $abs] = self::destDir($to);
                    $name = random_token(12);
                    $ext = strtolower((string) pathinfo($src, PATHINFO_EXTENSION));
                    $ext = preg_match('/^[a-z0-9]{1,5}$/', $ext) ? $ext : 'bin';
                    $file = $rel . '/' . $name . '.' . $ext;
                    self::copyFile($ctx, $src, $abs . '/' . $name . '.' . $ext);
                    $srcThumb = !empty($item['thumb_path']) ? self::sourceFile((string) $item['thumb_path'], (int) $plan['from']) : null;
                    if ($srcThumb) {
                        $thumb = $rel . '/' . $name . '_thumb.jpg';
                        self::copyFile($ctx, $srcThumb, $abs . '/' . $name . '_thumb.jpg');
                    }
                    $ctx['copied']['media']++;
                }
                break;
            default:
                if (!in_array($item['type'], self::PLAIN_TYPES, true)) {
                    self::skip($ctx, __('":t" (content type not supported)', ['t' => $title]));
                    return null;
                }
        }
        $new = DB::insert('content_items', [
            'hotel_id' => $to, 'title' => $title, 'type' => $item['type'], 'file_path' => $file, 'thumb_path' => $thumb,
            'mime_type' => $item['mime_type'], 'file_size' => $file ? $item['file_size'] : null, 'url' => $item['url'], 'body' => $item['body'],
            'settings' => $settings, 'duration' => (int) $item['duration'], 'is_active' => (int) $item['is_active'],
            'approval_status' => $item['approval_status'] ?? 'approved', 'valid_from' => $item['valid_from'] ?? null, 'valid_to' => $item['valid_to'] ?? null,
            'created_by' => null, 'created_at' => now(),
        ]);
        $ctx['map']['c'][$key] = $new;
        $ctx['copied']['items']++;
        return $new;
    }

    /** [relative dir, absolute dir] for copied media of customer $to (same layout as Uploader). */
    private static function destDir(int $to): array
    {
        $rel = 'h' . $to . '/media/' . date('Y/m');
        $abs = HC_ROOT . '/uploads/' . $rel;
        if (!is_dir($abs) && !@mkdir($abs, 0755, true) && !is_dir($abs)) {
            throw new RuntimeException(__('Could not save the uploaded file. Check folder permissions.'));
        }
        return [$rel, $abs];
    }

    private static function copyFile(array &$ctx, string $src, string $dest): void
    {
        if (!@copy($src, $dest)) {
            throw new RuntimeException(__('Could not copy a media file of the screen. Nothing was transferred.'));
        }
        @chmod($dest, 0644);
        $ctx['files'][] = $dest;
        $ctx['bytes'] += (int) filesize($dest);
    }

    private static function skip(array &$ctx, string $what): void
    {
        if (!in_array($what, $ctx['skipped'], true)) {
            $ctx['skipped'][] = $what;
        }
    }

    /** Remove the files of a failed transfer. */
    public static function cleanup(array $ctx): void
    {
        foreach ($ctx['files'] as $f) {
            @unlink($f);
        }
    }

    /** After a successful copy: count the new files in the target's cached storage usage. */
    public static function committed(array $ctx): void
    {
        if ($ctx['bytes'] > 0 && Features::$storageOverride === null) {
            Cache::set('storage', 'h' . $ctx['to'], Features::storageUsed((int) $ctx['to']) + (int) $ctx['bytes']);
        }
    }

    /** Human summary: "Copied: 1 playlist, 12 media files, 1 ticker; Not copied: …". */
    public static function summary(array $ctx): string
    {
        $c = $ctx['copied'];
        $parts = [];
        $labels = [
            'screens' => [':n screen setting', ':n screen settings'], 'playlists' => [':n playlist', ':n playlists'],
            'items' => [':n content item', ':n content items'], 'media' => [':n media file', ':n media files'],
            'layouts' => [':n layout', ':n layouts'], 'tickers' => [':n ticker', ':n tickers'], 'schedules' => [':n schedule', ':n schedules'],
        ];
        foreach ($labels as $k => [$one, $many]) {
            if (!empty($c[$k])) {
                $parts[] = __($c[$k] === 1 ? $one : $many, ['n' => $c[$k]]);
            }
        }
        $out = __('Copied: :list', ['list' => $parts ? implode(', ', $parts) : __('nothing')]);
        if (!empty($ctx['via'])) {
            $out .= ' ' . __('(the screen followed group / default content; it is now assigned to the screen directly)');
        }
        if ($ctx['skipped']) {
            $out .= '; ' . __('Not copied: :list', ['list' => implode(', ', array_slice($ctx['skipped'], 0, 15)) . (count($ctx['skipped']) > 15 ? ' …' : '')]);
        }
        return $out;
    }
}
