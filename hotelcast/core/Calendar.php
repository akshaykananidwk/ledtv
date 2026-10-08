<?php
declare(strict_types=1);

/**
 * Calendar of everything scheduled (#31, admin/calendar.php, docs/modules/scheduling.md).
 *
 * Event sources (FullCalendar event objects, times as hotel wall-clock "Y-m-d\TH:i:s" without offset —
 * the page runs FullCalendar with timeZone 'UTC' so it shows hotel time whatever the browser's zone):
 *   schedule  content schedules (broadcast_commands SHOW_CONTENT once / window); repeating windows expand
 *             into occurrences (groupId = the series, so dragging one moves the series)
 *   power     TV off/on schedules (SCREEN_OFF windows), read-only here (edited on TV Power)
 *   content   content start (valid_from) and expiry (valid_to) dates, read-only
 *   holiday   holidays (all-day, draggable for holidays.manage)
 *   device    device schedules of admin/device_schedules.php (when that module is installed), read-only
 *
 * A user limited to some TVs (Access) sees hotel-wide rows and rows that target only their TVs; they
 * may move only the latter.
 */
final class Calendar
{
    public const MAX_RANGE_DAYS = 100;
    public const MAX_EVENTS = 3000;
    public const COLORS = [
        'schedule' => '#2563eb', 'schedule_active' => '#16a34a', 'schedule_done' => '#94a3b8',
        'power' => '#475569', 'content_start' => '#0891b2', 'content_end' => '#d97706',
        'holiday' => '#db2777', 'holiday_off' => '#9f1239', 'device' => '#7c3aed',
    ];

    /** Parse a FullCalendar date ("2026-10-08", "2026-10-08T10:30:00", "…Z", "…+05:30") as hotel wall-clock time. */
    public static function parseLocal(mixed $v): ?int
    {
        $v = is_string($v) ? trim($v) : '';
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2}:\d{2}(?::\d{2})?)(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?$/', $v, $m)) {
            return null;
        }
        $ts = strtotime($m[1] . ' ' . ($m[2] ?? '00:00:00'));
        return $ts === false ? null : $ts;
    }

    public static function fmt(int $ts): string
    {
        return date('Y-m-d\TH:i:s', $ts);
    }

    /** All events between $start and $end (FullCalendar range strings). */
    public static function events(string $start, string $end): array
    {
        $rs = self::parseLocal($start) ?? strtotime('monday this week');
        $re = self::parseLocal($end) ?? $rs + 42 * 86400;
        if ($re <= $rs) {
            $re = $rs + 86400;
        }
        $re = min($re, $rs + self::MAX_RANGE_DAYS * 86400);
        $events = array_merge(
            self::broadcastEvents($rs, $re),
            self::contentEvents($rs, $re),
            self::holidayEvents($rs, $re),
            self::deviceEvents($rs, $re),
        );
        return array_slice($events, 0, self::MAX_EVENTS);
    }

    /** Visible to the current user: hotel-wide rows, or rows whose targets are all theirs. */
    public static function visible(array $row): bool
    {
        return ($row['target_type'] ?? 'all') === 'all' || Access::canBroadcast($row);
    }

    // ------------------------------------------------------------------ schedules & power

    private static function broadcastEvents(int $rs, int $re): array
    {
        $rows = DB::all(
            "SELECT * FROM broadcast_commands
             WHERE hotel_id = :h AND mode IN ('once','window') AND is_emergency = 0 AND command IN ('SHOW_CONTENT','SCREEN_OFF') AND status <> 'cancelled'
               AND (start_at IS NULL OR start_at < :re) AND (end_at IS NULL OR end_at >= :rs)
             ORDER BY id DESC LIMIT 1000",
            ['h' => Tenant::id(), 're' => date('Y-m-d H:i:s', $re), 'rs' => date('Y-m-d H:i:s', $rs)]
        );
        $out = [];
        foreach ($rows as $b) {
            if (!self::visible($b) || ($b['mode'] === 'once' && $b['command'] !== 'SHOW_CONTENT')) {
                continue;
            }
            $power = $b['command'] === 'SCREEN_OFF';
            $live = in_array($b['status'], ['scheduled', 'active'], true);
            $canEdit = !$power && $live && Access::canBroadcast($b);
            $repeating = self::repeating($b);
            $color = $power ? self::COLORS['power'] : ($b['status'] === 'active' ? self::COLORS['schedule_active'] : ($live ? self::COLORS['schedule'] : self::COLORS['schedule_done']));
            $target = Broadcaster::describeTarget($b['target_type'], $b['target_ids']);
            $base = [
                'title' => ($power ? '⏻ ' : '') . $b['title'] . ' · ' . $target,
                'url' => $power ? admin_url('power.php') : admin_url('schedule.php', ['action' => 'edit', 'id' => $b['id']]),
                'color' => $color,
                'extendedProps' => [
                    'kind' => $power ? 'power' : 'schedule', 'ref' => (int) $b['id'], 'status' => $b['status'],
                    'repeating' => $repeating, 'mode' => $b['mode'], 'target' => $target,
                    'source' => $power ? '' : self::sourceLabel($b['content_id'], $b['playlist_id']),
                ],
            ];
            foreach (self::occurrences($b, $rs, $re) as [$s, $e, $allDay, $key]) {
                $ev = $base + [
                    'id' => 'b' . $b['id'] . ($repeating ? '@' . $key : ''),
                    'start' => $allDay ? date('Y-m-d', $s) : self::fmt($s),
                    'allDay' => $allDay,
                ];
                if ($e !== null) {
                    $ev['end'] = $allDay ? date('Y-m-d', $e) : self::fmt($e);
                }
                if ($repeating) {
                    $ev['groupId'] = 'b' . $b['id'];
                }
                $ev['startEditable'] = $canEdit;
                $ev['durationEditable'] = $canEdit && $b['mode'] === 'window' && !$allDay && ($repeating || !empty($b['end_at']));
                $out[] = $ev;
            }
        }
        return $out;
    }

    /** Does a window repeat (daily times and/or weekdays)? */
    public static function repeating(array $b): bool
    {
        return $b['mode'] === 'window' && (!empty($b['repeat_days']) || (!empty($b['daily_start']) && !empty($b['daily_end'])));
    }

    /**
     * Occurrences of a broadcast row inside [rs, re): list of [start, end|null, allDay, key(Y-m-d)].
     * 'once' → 30 minute block; plain window → its range; repeating → one per matching day.
     */
    public static function occurrences(array $b, int $rs, int $re): array
    {
        $bs = !empty($b['start_at']) ? (int) strtotime((string) $b['start_at']) : null;
        $be = !empty($b['end_at']) ? (int) strtotime((string) $b['end_at']) : null;
        if ($b['mode'] === 'once') {
            return $bs !== null && $bs < $re && $bs + 1800 > $rs ? [[$bs, $bs + 1800, false, date('Y-m-d', $bs)]] : [];
        }
        if (!self::repeating($b)) {
            $s = $bs ?? $rs;
            $e = $be ?? $re;
            return $e > $rs && $s < $re ? [[$s, $e, false, date('Y-m-d', $s)]] : [];
        }
        $days = !empty($b['repeat_days']) ? array_map('intval', explode(',', (string) $b['repeat_days'])) : null;
        $hasDaily = !empty($b['daily_start']) && !empty($b['daily_end']);
        // Start one day early: an overnight window of the previous day reaches into the range.
        $day = strtotime(date('Y-m-d', max($rs, $bs ?? $rs)) . ' 00:00:00 -1 day');
        $last = min($re, $be ?? $re);
        $out = [];
        for ($n = 0; $day < $last && $n < 400; $n++) {
            $dateStr = date('Y-m-d', $day);
            $next = (int) strtotime($dateStr . ' +1 day');
            if ($days === null || in_array((int) date('N', $day), $days, true)) {
                if ($hasDaily) {
                    $es = (int) strtotime($dateStr . ' ' . $b['daily_start']);
                    $ee = (int) strtotime($dateStr . ' ' . $b['daily_end']);
                    if ($ee <= $es) {
                        $ee = (int) strtotime(date('Y-m-d', $next) . ' ' . $b['daily_end']); // overnight
                    }
                    $es = max($es, $bs ?? $es);
                    $ee = min($ee, $be ?? $ee);
                    if ($ee > $es && $ee > $rs && $es < $re) {
                        $out[] = [$es, $ee, false, $dateStr];
                    }
                } elseif ($next > $rs && $day < $re && ($bs === null || $next > $bs)) {
                    $out[] = [$day, $next, true, $dateStr];
                }
            }
            $day = $next;
        }
        return $out;
    }

    private static function sourceLabel(mixed $contentId, mixed $playlistId): string
    {
        if ($playlistId) {
            $n = DB::value('SELECT name FROM content_playlists WHERE id = :id AND hotel_id = :h', ['id' => (int) $playlistId, 'h' => Tenant::id()]);
            return $n !== null && $n !== false ? '▶ ' . $n : '';
        }
        if ($contentId) {
            $t = DB::value('SELECT title FROM content_items WHERE id = :id AND hotel_id = :h', ['id' => (int) $contentId, 'h' => Tenant::id()]);
            return $t !== null && $t !== false ? (string) $t : '';
        }
        return '';
    }

    // ------------------------------------------------------------------ content dates, holidays, device schedules

    private static function contentEvents(int $rs, int $re): array
    {
        $rows = DB::all(
            'SELECT id, title, valid_from, valid_to FROM content_items
             WHERE hotel_id = :h AND ((valid_from >= :rs AND valid_from < :re) OR (valid_to >= :rs2 AND valid_to < :re2)) LIMIT 500',
            ['h' => Tenant::id(), 'rs' => date('Y-m-d H:i:s', $rs), 're' => date('Y-m-d H:i:s', $re), 'rs2' => date('Y-m-d H:i:s', $rs), 're2' => date('Y-m-d H:i:s', $re)]
        );
        $out = [];
        $canEdit = Approvals::canEdit();
        foreach ($rows as $c) {
            foreach (['valid_from' => ['content_start', '▶ ', __('Starts')], 'valid_to' => ['content_end', '■ ', __('Expires')]] as $col => [$kind, $icon, $label]) {
                $ts = $c[$col] ? (int) strtotime((string) $c[$col]) : null;
                if ($ts === null || $ts < $rs || $ts >= $re) {
                    continue;
                }
                $out[] = [
                    'id' => 'c' . $c['id'] . ':' . $col,
                    'title' => $icon . $label . ': ' . $c['title'],
                    'start' => self::fmt($ts),
                    'allDay' => false,
                    'color' => self::COLORS[$kind],
                    'url' => $canEdit ? admin_url('content.php', ['action' => 'edit', 'id' => $c['id']]) : admin_url('preview.php', ['content_id' => $c['id']]),
                    'editable' => false,
                    'extendedProps' => ['kind' => 'content', 'ref' => (int) $c['id'], 'repeating' => false],
                ];
            }
        }
        return $out;
    }

    private static function holidayEvents(int $rs, int $re): array
    {
        $rows = DB::all(
            'SELECT * FROM holidays WHERE hotel_id = :h AND start_date < :re AND end_date >= :rs ORDER BY start_date LIMIT 500',
            ['h' => Tenant::id(), 're' => date('Y-m-d', $re + 86399), 'rs' => date('Y-m-d', $rs)]
        );
        $out = [];
        $mayManage = Auth::can('holidays.manage');
        foreach ($rows as $h) {
            if (!Holidays::visible($h)) {
                continue;
            }
            $canEdit = $mayManage && Access::canBroadcast($h);
            $target = Broadcaster::describeTarget($h['target_type'], $h['target_ids']);
            $out[] = [
                'id' => 'h' . $h['id'],
                'title' => '🎉 ' . $h['name'] . ' · ' . Holidays::actionLabel($h['action']) . ($h['target_type'] !== 'all' ? ' · ' . $target : '') . ((int) $h['is_active'] ? '' : ' (' . __('paused') . ')'),
                'start' => $h['start_date'],
                'end' => date('Y-m-d', (int) strtotime($h['end_date'] . ' +1 day')),
                'allDay' => true,
                'color' => $h['action'] === 'tv_off' ? self::COLORS['holiday_off'] : self::COLORS['holiday'],
                'url' => $mayManage ? admin_url('holidays.php', ['action' => 'edit', 'id' => $h['id']]) : '',
                'startEditable' => $canEdit,
                'durationEditable' => $canEdit,
                'extendedProps' => ['kind' => 'holiday', 'ref' => (int) $h['id'], 'repeating' => false, 'target' => $target],
            ];
        }
        return $out;
    }

    /** Device schedules (other 2.4 module, table device_schedules), read-only, when installed. */
    private static function deviceEvents(int $rs, int $re): array
    {
        if (!class_exists('DeviceSchedules') || !self::tableExists('device_schedules')) {
            return [];
        }
        try {
            $rows = DB::all('SELECT * FROM device_schedules WHERE hotel_id = :h AND is_active = 1 ORDER BY run_time LIMIT 300', ['h' => Tenant::id()]);
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        $page = is_file(HC_ROOT . '/admin/device_schedules.php') ? 'device_schedules.php' : '';
        $mayOpen = $page !== '' && Auth::can('device_schedules.manage');
        foreach ($rows as $s) {
            $ids = json_decode((string) ($s['target_ids'] ?? '[]'), true);
            $row = ['target_type' => (string) ($s['target_type'] ?? 'all'), 'target_ids' => is_array($ids) ? $ids : []];
            if (!in_array($row['target_type'], Broadcaster::TARGET_TYPES, true) || !self::visible($row)) {
                continue;
            }
            $label = __((string) (DeviceSchedules::ACTIONS[$s['action']] ?? $s['action']));
            $title = '⚙ ' . $label . ((string) $s['title'] !== '' ? ': ' . $s['title'] : '');
            for ($day = strtotime(date('Y-m-d', $rs)), $n = 0; $day < $re && $n < 120; $day = (int) strtotime(date('Y-m-d', $day) . ' +1 day'), $n++) {
                $date = date('Y-m-d', $day);
                $runs = method_exists('DeviceSchedules', 'runsOn') ? DeviceSchedules::runsOn($s, $date) : self::deviceRunsOn($s, $date);
                if (!$runs) {
                    continue;
                }
                $ts = (int) strtotime($date . ' ' . $s['run_time']);
                if ($ts < $rs || $ts >= $re) {
                    continue;
                }
                $out[] = [
                    'id' => 'd' . $s['id'] . '@' . $date,
                    'groupId' => 'd' . $s['id'],
                    'title' => $title,
                    'start' => self::fmt($ts),
                    'end' => self::fmt($ts + 900),
                    'allDay' => false,
                    'color' => self::COLORS['device'],
                    'url' => $mayOpen ? admin_url($page, ['action' => 'edit', 'id' => $s['id']]) : '',
                    'editable' => false,
                    'extendedProps' => ['kind' => 'device', 'ref' => (int) $s['id'], 'repeating' => ($s['repeat_mode'] ?? '') !== 'once'],
                ];
            }
        }
        return $out;
    }

    private static function deviceRunsOn(array $s, string $date): bool
    {
        return match ((string) ($s['repeat_mode'] ?? '')) {
            'once' => (string) ($s['run_date'] ?? '') === $date,
            'daily' => true,
            'weekly' => in_array((int) date('N', (int) strtotime($date)), array_map('intval', explode(',', (string) ($s['days'] ?? ''))), true),
            default => false,
        };
    }

    private static function tableExists(string $table): bool
    {
        static $known = [];
        return $known[$table] ??= (bool) DB::value('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t', ['t' => $table]);
    }

    // ------------------------------------------------------------------ editing

    /** Schedule row of the current hotel that the calendar may change (404 / 403 otherwise). */
    private static function scheduleRow(int $id): array
    {
        $b = $id ? Tenant::find('broadcast_commands', $id, "mode IN ('once','window') AND is_emergency = 0 AND command = 'SHOW_CONTENT'") : null;
        if (!$b) {
            throw new DomainException(__('Schedule not found.'));
        }
        Access::requireBroadcast($b);
        if (!in_array($b['status'], ['scheduled', 'active'], true)) {
            throw new InvalidArgumentException(__('Only upcoming or running schedules can be edited.'));
        }
        return $b;
    }

    /** Form-style input for Broadcaster::validateSchedule() from a stored row. */
    private static function rowInput(array $b): array
    {
        return [
            'title' => $b['title'],
            'source' => $b['playlist_id'] ? 'p:' . (int) $b['playlist_id'] : ($b['content_id'] ? 'c:' . (int) $b['content_id'] : ''),
            'target_type' => $b['target_type'],
            'target_ids' => json_decode((string) $b['target_ids'], true) ?: [],
            'mode' => $b['mode'],
            'start_at' => $b['start_at'] ?? '',
            'end_at' => $b['end_at'] ?? '',
            'daily_start' => $b['daily_start'] ?? '',
            'daily_end' => $b['daily_end'] ?? '',
            'repeat_days' => $b['repeat_days'] ? explode(',', (string) $b['repeat_days']) : [],
        ];
    }

    /**
     * Move / resize an event (drag & drop). $in: kind (schedule | holiday), id, start, end, orig_start.
     * One-off schedules change their start (and end); a repeating schedule changes as a whole series:
     * the daily times follow the dragged occurrence and its weekdays shift by the days it moved.
     */
    public static function move(array $in): array
    {
        $kind = (string) ($in['kind'] ?? '');
        $id = is_numeric($in['id'] ?? null) ? (int) $in['id'] : 0;
        $start = self::parseLocal($in['start'] ?? '');
        $end = self::parseLocal($in['end'] ?? '');
        if ($start === null) {
            throw new InvalidArgumentException(__('Invalid date.'));
        }
        if ($kind === 'holiday') {
            return self::moveHoliday($id, $start, $end);
        }
        if ($kind !== 'schedule') {
            throw new InvalidArgumentException(__('This entry cannot be moved here.'));
        }
        $b = self::scheduleRow($id);
        $input = self::rowInput($b);
        if (self::repeating($b)) {
            $orig = self::parseLocal($in['orig_start'] ?? '') ?? $start;
            $delta = (int) round((strtotime(date('Y-m-d', $start)) - strtotime(date('Y-m-d', $orig))) / 86400);
            if ($b['daily_start'] && $b['daily_end']) {
                if ($end === null || $end <= $start) {
                    $end = $start + (self::durationOf($b));
                }
                if ($end - $start >= 86400) {
                    throw new InvalidArgumentException(__('A daily time window must be shorter than one day.'));
                }
                $input['daily_start'] = date('H:i', $start);
                $input['daily_end'] = date('H:i', $end);
            }
            if ($delta !== 0 && $input['repeat_days']) {
                $input['repeat_days'] = array_map(static fn ($d) => (((int) $d - 1 + $delta) % 7 + 7) % 7 + 1, $input['repeat_days']);
            }
        } elseif ($b['mode'] === 'once') {
            if ($start < time() - 60) {
                throw new InvalidArgumentException(__('Choose a time in the future.'));
            }
            $input['start_at'] = date('Y-m-d H:i:s', $start);
        } else {
            $input['start_at'] = date('Y-m-d H:i:s', $start);
            if ($b['end_at']) {
                $input['end_at'] = date('Y-m-d H:i:s', $end ?? ((int) strtotime((string) $b['end_at']) + $start - (int) strtotime((string) $b['start_at'])));
            }
        }
        [$data, $errors] = Broadcaster::validateSchedule($input);
        if ($errors) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }
        Broadcaster::updateSchedule($b, $data);
        ActivityLog::add('schedule_move', 'broadcast', (int) $b['id'], $data['title']);
        return ['id' => (int) $b['id']];
    }

    private static function durationOf(array $b): int
    {
        $s = (int) strtotime('2000-01-01 ' . $b['daily_start']);
        $e = (int) strtotime('2000-01-01 ' . $b['daily_end']);
        return $e > $s ? $e - $s : $e + 86400 - $s;
    }

    private static function moveHoliday(int $id, int $start, ?int $end): array
    {
        if (!Auth::can('holidays.manage')) {
            throw new DomainException(__('You do not have permission for this action'), 403);
        }
        $h = $id ? Holidays::find($id) : null;
        if (!$h) {
            throw new DomainException(__('Holiday not found.'));
        }
        Access::requireBroadcast($h);
        $startDate = date('Y-m-d', $start);
        // FullCalendar all-day ends are exclusive.
        $endDate = $end !== null && $end > $start ? date('Y-m-d', (int) strtotime(date('Y-m-d', $end) . ' -1 day')) : date('Y-m-d', (int) strtotime($startDate) + (int) strtotime($h['end_date']) - (int) strtotime($h['start_date']));
        $in = ['name' => $h['name'], 'start_date' => $startDate, 'end_date' => max($startDate, $endDate), 'action' => $h['action'],
            'source' => $h['playlist_id'] ? 'p:' . $h['playlist_id'] : ($h['content_id'] ? 'c:' . $h['content_id'] : ''),
            'target_type' => $h['target_type'], 'target_ids' => json_decode((string) $h['target_ids'], true) ?: [], 'is_active' => (int) $h['is_active']];
        [$data, $errors] = Holidays::validate($in);
        if ($errors) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }
        Holidays::save($data, (int) $h['id']);
        ActivityLog::add('holiday_move', 'holiday', (int) $h['id'], $data['name'] . ' ' . $data['start_date']);
        return ['id' => (int) $h['id']];
    }

    /**
     * New schedule from a selected time range: $in = title, source (c:N / p:N), target (target_type +
     * target_ids / room_ids …), start, end. With an end it is a time window (content between start and
     * end, then back to normal); without, a one-time push at start. Uses Broadcaster::validateSchedule()
     * and Broadcaster::schedule(). Returns the new id.
     */
    public static function add(array $in): int
    {
        $start = self::parseLocal($in['start'] ?? '');
        $end = self::parseLocal($in['end'] ?? '');
        if ($start === null) {
            throw new InvalidArgumentException(__('Invalid date.'));
        }
        $window = $end !== null && $end > $start && ($in['mode'] ?? 'window') !== 'once';
        if (!$window && $start < time() - 60) {
            throw new InvalidArgumentException(__('Choose a time in the future.'));
        }
        $input = array_intersect_key($in, array_flip(['title', 'source', 'target_type', 'target_ids', 'room_ids', 'group_ids', 'floors']));
        $input += ['mode' => $window ? 'window' : 'once', 'start_at' => date('Y-m-d H:i:s', $start), 'end_at' => $window ? date('Y-m-d H:i:s', (int) $end) : ''];
        [$data, $errors] = Broadcaster::validateSchedule($input);
        if ($errors) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }
        $id = Broadcaster::schedule($data, Auth::id());
        Broadcaster::processSchedules();
        ActivityLog::add('schedule_create', 'broadcast', $id, $data['title'] . ' · ' . Broadcaster::describeTarget($data['target_type'], $data['target_ids']));
        return $id;
    }
}
