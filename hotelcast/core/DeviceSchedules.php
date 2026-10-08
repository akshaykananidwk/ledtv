<?php
declare(strict_types=1);

/**
 * Device schedules (2.4, docs/modules/device_schedules.md): timed TV actions of the current hotel.
 *
 *  volume / mute / unmute  → SET_VOLUME {level} / MUTE / UNMUTE                       (#38)
 *  input / player          → OPEN_INPUT {input} / RELOAD (back to the player app)    (#39)
 *  reboot                  → REBOOT, staggered 0–N minutes per TV, skips offline TVs and TVs
 *                            up for less than N hours                                (#43)
 *  bell                    → PLAY_SOUND {url, volume?, repeat}                       (#49)
 *  speak                   → SPEAK {text, lang, rate, repeat, volume?, chime_before}
 *
 * A schedule runs at run_time (hotel time zone) once on run_date, every day, or on chosen weekdays
 * (1 = Mon … 7 = Sun). DeviceSchedulesTask calls tick() every minute for every active hotel; an occurrence
 * is claimed with INSERT IGNORE on device_schedule_runs (unique schedule + occurrence + device), so it fires
 * exactly once even when ticks overlap or run late (late ticks catch up for CATCHUP_SEC). TVs of rooms with
 * an active emergency are skipped (emergency always wins).
 */
final class DeviceSchedules
{
    public const ACTIONS = [
        'volume' => 'Set volume',
        'mute' => 'Mute',
        'unmute' => 'Unmute',
        'input' => 'Switch input',
        'player' => 'Back to the player app',
        'reboot' => 'Restart TV',
        'bell' => 'Bell / chime',
        'speak' => 'Spoken announcement',
    ];
    public const ICONS = [
        'volume' => 'bi-volume-down', 'mute' => 'bi-volume-mute', 'unmute' => 'bi-volume-up', 'input' => 'bi-hdmi',
        'player' => 'bi-play-btn', 'reboot' => 'bi-arrow-clockwise', 'bell' => 'bi-bell', 'speak' => 'bi-megaphone',
    ];
    public const REPEATS = ['once' => 'Once', 'daily' => 'Every day', 'weekly' => 'On chosen days'];
    public const SPEAK_LANGS = ['auto' => 'Automatic', 'gu' => 'ગુજરાતી', 'hi' => 'हिन्दी', 'en' => 'English'];
    /** A tick that comes late (no cron, server busy) still fires occurrences of the last 10 minutes. */
    public const CATCHUP_SEC = 600;
    /** Staggered restarts not sent within 30 minutes of their time are dropped (server was down). */
    public const LATE_SEC = 1800;
    public const MAX_STAGGER_MIN = 10;
    public const MAX_BULK_TIMES = 60;

    // ------------------------------------------------------------------ payloads

    /** SPEAK payload from form / JSON input. Throws InvalidArgumentException. */
    public static function speakPayload(array $in): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($in['text'] ?? ''))) ?? '');
        if ($text === '') {
            throw new InvalidArgumentException(__('Enter the text to announce.'));
        }
        if (mb_strlen($text) > 500) {
            throw new InvalidArgumentException(__('The announcement can have at most 500 characters.'));
        }
        $lang = (string) ($in['lang'] ?? 'auto');
        if (!isset(self::SPEAK_LANGS[$lang])) {
            throw new InvalidArgumentException(__('Choose the announcement language.'));
        }
        $rate = is_numeric($in['rate'] ?? null) ? round((float) $in['rate'], 2) : 1.0;
        if ($rate < 0.5 || $rate > 2.0) {
            throw new InvalidArgumentException(__('Speed must be between 0.5 and 2.'));
        }
        $repeat = self::intIn($in['repeat'] ?? 1, 1, 3, __('Repeat must be between 1 and 3 times.'));
        $p = ['text' => $text, 'lang' => $lang, 'rate' => $rate, 'repeat' => $repeat];
        $vol = self::optVolume($in['volume'] ?? null);
        if ($vol !== null) {
            $p['volume'] = $vol;
        }
        $p['chime_before'] = !empty($in['chime_before']) && $in['chime_before'] !== 'false';
        return $p;
    }

    /** Validated options of an action (stored as JSON). Throws InvalidArgumentException. */
    public static function options(string $action, array $in): array
    {
        switch ($action) {
            case 'volume':
                return ['level' => self::intIn($in['level'] ?? null, 0, 100, __('Volume must be between 0 and 100.'))];
            case 'input':
                $input = (string) ($in['input'] ?? '');
                if (!in_array($input, TvControls::OPEN_INPUTS, true)) {
                    throw new InvalidArgumentException(__('Choose Live TV or an HDMI input.'));
                }
                return ['input' => $input];
            case 'reboot':
                return [
                    'min_uptime_h' => self::intIn(($in['min_uptime_h'] ?? '') === '' ? 0 : $in['min_uptime_h'], 0, 720, __('Minimum uptime must be between 0 and 720 hours.')),
                    'stagger_min' => self::intIn(($in['stagger_min'] ?? '') === '' ? self::MAX_STAGGER_MIN : $in['stagger_min'], 0, self::MAX_STAGGER_MIN, __('Spread must be between 0 and 10 minutes.')),
                ];
            case 'bell':
                $ref = (string) ($in['sound'] ?? '');
                if (Sounds::resolve($ref) === null) {
                    throw new InvalidArgumentException(__('Choose a sound.'));
                }
                $o = ['sound' => $ref, 'repeat' => self::intIn($in['sound_repeat'] ?? $in['repeat'] ?? 1, 1, 10, __('Repeat must be between 1 and 10 times.'))];
                $vol = self::optVolume($in['sound_volume'] ?? $in['volume'] ?? null);
                if ($vol !== null) {
                    $o['volume'] = $vol;
                }
                return $o;
            case 'speak':
                return self::speakPayload($in);
            case 'mute':
            case 'unmute':
            case 'player':
                return [];
        }
        throw new InvalidArgumentException(__('Choose an action.'));
    }

    /**
     * [command, payload] sent to the TVs for a schedule row, or null when it cannot be sent (sound deleted).
     * REBOOT schedules are sent per TV by the stagger logic.
     */
    public static function command(array $s): ?array
    {
        $o = self::opts($s);
        switch ($s['action']) {
            case 'volume':
                return ['SET_VOLUME', ['level' => (int) ($o['level'] ?? 0)]];
            case 'mute':
                return ['MUTE', []];
            case 'unmute':
                return ['UNMUTE', []];
            case 'input':
                return ['OPEN_INPUT', ['input' => (string) ($o['input'] ?? 'live_tv')]];
            case 'player':
                // The player app is brought back by restarting it (RELOAD), as after "Restart app".
                return ['RELOAD', []];
            case 'reboot':
                return ['REBOOT', []];
            case 'bell':
                $sound = Sounds::resolve((string) ($o['sound'] ?? ''));
                if (!$sound) {
                    return null;
                }
                $p = ['url' => $sound['url']];
                if (isset($o['volume'])) {
                    $p['volume'] = (int) $o['volume'];
                }
                $p['repeat'] = max(1, min(10, (int) ($o['repeat'] ?? 1)));
                return ['PLAY_SOUND', $p];
            case 'speak':
                try {
                    return ['SPEAK', self::speakPayload($o)];
                } catch (InvalidArgumentException) {
                    return null;
                }
        }
        return null;
    }

    public static function opts(array $s): array
    {
        $o = json_decode((string) ($s['options'] ?? ''), true);
        return is_array($o) ? $o : [];
    }

    private static function intIn(mixed $v, int $min, int $max, string $error): int
    {
        if (is_bool($v) || !is_numeric($v) || (float) $v != (int) $v || (int) $v < $min || (int) $v > $max) {
            throw new InvalidArgumentException($error);
        }
        return (int) $v;
    }

    private static function optVolume(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        return self::intIn($v, 0, 100, __('Volume must be between 0 and 100.'));
    }

    // ------------------------------------------------------------------ CRUD

    public static function find(int $id): ?array
    {
        return Tenant::find('device_schedules', $id);
    }

    /** Schedules of the current hotel the current user may see (limited users: only their TVs). */
    public static function list(): array
    {
        $rows = DB::all(
            'SELECT s.*, u.username FROM device_schedules s LEFT JOIN users u ON u.id = s.created_by
             WHERE s.hotel_id = :h ORDER BY s.is_active DESC, s.run_time, s.id',
            ['h' => Tenant::id()]
        );
        return array_values(array_filter($rows, [Access::class, 'canBroadcast']));
    }

    /**
     * Validate form input. Returns [data, errors]. Targets go through Broadcaster::parseTarget (another
     * hotel's room / group → 404, a limited user's foreign TVs → 403).
     */
    public static function validate(array $in): array
    {
        $errors = [];
        $action = (string) ($in['action'] ?? '');
        $options = [];
        if (!isset(self::ACTIONS[$action])) {
            $errors[] = __('Choose an action.');
        } else {
            try {
                $options = self::options($action, $in);
            } catch (InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }
        [$data, $more] = self::validateWhen($in);
        $errors = array_merge($errors, $more);
        [$type, $ids] = Broadcaster::parseTarget($in);
        if ($type !== 'all' && !$ids) {
            $errors[] = __('Select at least one target.');
        }
        $title = mb_substr(trim(strip_tags((string) ($in['title'] ?? ''))), 0, 190);
        if ($title === '' && isset(self::ACTIONS[$action])) {
            $title = $action === 'speak' && isset($options['text']) ? mb_substr($options['text'], 0, 60) : __(self::ACTIONS[$action]);
        }
        return [$data + [
            'title' => $title,
            'action' => $action,
            'options' => $options,
            'target_type' => $type,
            'target_ids' => $ids,
        ], array_values(array_unique($errors))];
    }

    /** Time / repeat part of the form. Returns [['run_time', 'repeat_mode', 'run_date', 'days'], errors]. */
    public static function validateWhen(array $in, bool $needTime = true): array
    {
        $errors = [];
        $time = Broadcaster::parseTime((string) ($in['run_time'] ?? ''));
        if ($needTime && !$time) {
            $errors[] = __('Enter the time as HH:MM.');
        }
        $mode = (string) ($in['repeat_mode'] ?? 'daily');
        $mode = isset(self::REPEATS[$mode]) ? $mode : 'daily';
        $date = null;
        $days = null;
        if ($mode === 'once') {
            $d = (string) ($in['run_date'] ?? '');
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
            if (!$dt || $dt->format('Y-m-d') !== $d) {
                $errors[] = __('Choose the date.');
            } elseif ($d < date('Y-m-d')) {
                $errors[] = __('The date is in the past.');
            } else {
                $date = $d;
            }
        } elseif ($mode === 'weekly') {
            $list = array_values(array_unique(array_filter(array_map('intval', (array) ($in['days'] ?? [])), fn ($x) => $x >= 1 && $x <= 7)));
            sort($list);
            if (!$list) {
                $errors[] = __('Choose at least one day.');
            } elseif (count($list) === 7) {
                $mode = 'daily';
            } else {
                $days = implode(',', $list);
            }
        }
        return [['run_time' => $time, 'repeat_mode' => $mode, 'run_date' => $date, 'days' => $days], $errors];
    }

    /** Insert or update a validated schedule. Returns its id. */
    public static function save(?int $id, array $data, ?int $userId = null): int
    {
        $row = [
            'title' => $data['title'],
            'action' => $data['action'],
            'options' => json_out((object) $data['options']),
            'run_time' => $data['run_time'],
            'repeat_mode' => $data['repeat_mode'],
            'run_date' => $data['run_date'],
            'days' => $data['days'],
            'target_type' => $data['target_type'],
            'target_ids' => json_out(array_values($data['target_ids'])),
            // Occurrences before this minute never fire (no surprise bell for a time that already passed).
            'active_from' => date('Y-m-d H:i:00'),
        ];
        if ($id) {
            DB::update('device_schedules', $row, 'id = :id', ['id' => $id]);
            return $id;
        }
        return DB::insert('device_schedules', $row + ['is_active' => 1, 'created_by' => $userId, 'created_at' => now()]);
    }

    public static function setActive(int $id, bool $on): void
    {
        DB::update('device_schedules', ['is_active' => $on ? 1 : 0] + ($on ? ['active_from' => date('Y-m-d H:i:00')] : []), 'id = :id', ['id' => $id]);
    }

    public static function delete(int $id): void
    {
        DB::delete('device_schedule_runs', 'schedule_id = :id', ['id' => $id]);
        DB::delete('device_schedules', 'id = :id', ['id' => $id]);
    }

    /**
     * Bell timetable quick setup: many times at once ("08:00, 08:45, 09:30 …"), one bell schedule per time,
     * same sound / days / TVs. Returns [created ids, errors] — nothing is created when there is an error.
     */
    public static function bulkBells(array $in, ?int $userId = null): array
    {
        $errors = [];
        [$times, $bad] = self::parseTimes((string) ($in['times'] ?? ''));
        if ($bad) {
            $errors[] = __('Not a time: :t', ['t' => implode(', ', array_slice($bad, 0, 10))]);
        }
        if (!$times && !$bad) {
            $errors[] = __('Enter at least one time, e.g. 08:00, 08:45, 09:30.');
        }
        if (count($times) > self::MAX_BULK_TIMES) {
            $errors[] = __('At most :n times at once.', ['n' => self::MAX_BULK_TIMES]);
        }
        $options = [];
        try {
            $options = self::options('bell', $in);
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }
        $in['repeat_mode'] = 'weekly';
        [$when, $more] = self::validateWhen($in, false);
        $errors = array_merge($errors, $more);
        [$type, $ids] = Broadcaster::parseTarget($in);
        if ($type !== 'all' && !$ids) {
            $errors[] = __('Select at least one target.');
        }
        if ($errors) {
            return [[], array_values(array_unique($errors))];
        }
        $prefix = mb_substr(trim(strip_tags((string) ($in['title'] ?? ''))), 0, 170) ?: __('Bell');
        $created = [];
        DB::transaction(function () use ($times, $prefix, $options, $when, $type, $ids, $userId, &$created): void {
            foreach ($times as $t) {
                $created[] = self::save(null, [
                    'title' => $prefix . ' ' . substr($t, 0, 5),
                    'action' => 'bell',
                    'options' => $options,
                    'run_time' => $t,
                    'target_type' => $type,
                    'target_ids' => $ids,
                ] + $when, $userId);
            }
        });
        return [$created, []];
    }

    /** "08:00, 8:45; 09.30\n13:15" → [['08:00:00', '08:45:00', …] sorted unique, [invalid tokens]]. */
    public static function parseTimes(string $text): array
    {
        $ok = [];
        $bad = [];
        foreach (preg_split('/[\s,;|]+/u', trim($text)) ?: [] as $tok) {
            if ($tok === '') {
                continue;
            }
            if (preg_match('/^(\d{1,2})[:.](\d{2})$/', $tok, $m) && (int) $m[1] < 24 && (int) $m[2] < 60) {
                $ok[] = sprintf('%02d:%02d:00', (int) $m[1], (int) $m[2]);
            } else {
                $bad[] = mb_substr($tok, 0, 20);
            }
        }
        $ok = array_values(array_unique($ok));
        sort($ok);
        return [$ok, $bad];
    }

    // ------------------------------------------------------------------ occurrences

    /** Does the schedule run on the calendar day $day (Y-m-d)? */
    public static function runsOn(array $s, string $day): bool
    {
        return match ($s['repeat_mode']) {
            'once' => (string) $s['run_date'] === $day,
            'weekly' => in_array((int) date('N', (int) strtotime($day . ' 12:00:00')), array_map('intval', explode(',', (string) $s['days'])), true),
            default => true,
        };
    }

    /**
     * The occurrence (unix time, hotel time zone) that is due at $now and was not fired yet, or null.
     * Due = between $now - CATCHUP_SEC and $now, not before active_from and after last_fired_for.
     */
    public static function dueOccurrence(array $s, int $now): ?int
    {
        $best = null;
        $from = !empty($s['active_from']) ? (int) strtotime((string) $s['active_from']) : 0;
        $last = !empty($s['last_fired_for']) ? (int) strtotime((string) $s['last_fired_for']) : 0;
        foreach ([date('Y-m-d', $now), date('Y-m-d', $now - self::CATCHUP_SEC)] as $day) {
            $occ = (int) strtotime($day . ' ' . $s['run_time']);
            if ($occ > $now || $occ <= $now - self::CATCHUP_SEC || $occ < $from || $occ <= $last || !self::runsOn($s, $day)) {
                continue;
            }
            $best = max($best ?? 0, $occ);
        }
        return $best;
    }

    /** Next time the schedule runs after $now (unix), or null (one-off in the past / paused). */
    public static function nextRun(array $s, ?int $now = null): ?int
    {
        $now ??= time();
        if (!(int) $s['is_active']) {
            return null;
        }
        for ($i = 0; $i < 8; $i++) {
            $day = date('Y-m-d', (int) strtotime('+' . $i . ' days', $now));
            $occ = (int) strtotime($day . ' ' . $s['run_time']);
            if ($occ > $now && self::runsOn($s, $day)) {
                return $occ;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ firing (DeviceSchedulesTask)

    /** Fire due schedules + staggered restarts of the current hotel. Returns counters. */
    public static function tick(?int $now = null): array
    {
        $now ??= time();
        $out = ['fired' => 0, 'tvs' => 0, 'restarts' => 0, 'skipped' => 0];
        if (!Tenant::isActive()) {
            return $out;
        }
        $hid = Tenant::id();
        $rows = DB::all('SELECT * FROM device_schedules WHERE hotel_id = :h AND is_active = 1', ['h' => $hid]);
        foreach ($rows as $s) {
            $occ = self::dueOccurrence($s, $now);
            if ($occ === null) {
                continue;
            }
            $occAt = date('Y-m-d H:i:s', $occ);
            // Claim the occurrence: the unique key lets exactly one tick through.
            $claimed = DB::query(
                "INSERT IGNORE INTO device_schedule_runs (hotel_id, schedule_id, occurrence_at, device_id, due_at, status, created_at)
                 VALUES (:h, :s, :o, 0, :o2, 'sent', :c)",
                ['h' => $hid, 's' => $s['id'], 'o' => $occAt, 'o2' => $occAt, 'c' => date('Y-m-d H:i:s', $now)]
            )->rowCount();
            if ($claimed !== 1) {
                continue;
            }
            [$result, $tvs] = self::fire($s, $occ, $now);
            DB::update('device_schedules', ['last_fired_for' => $occAt, 'last_fired_at' => date('Y-m-d H:i:s', $now), 'last_result' => mb_substr($result, 0, 255)], 'id = :id', ['id' => $s['id']]);
            DB::query('UPDATE device_schedule_runs SET note = :n WHERE schedule_id = :s AND occurrence_at = :o AND device_id = 0', ['n' => mb_substr($result, 0, 190), 's' => $s['id'], 'o' => $occAt]);
            $out['fired']++;
            $out['tvs'] += $tvs;
        }
        $r = self::deliverRestarts($now);
        $out['restarts'] = $r['sent'];
        $out['skipped'] = $r['skipped'];
        // Keep 60 days of run history.
        DB::query('DELETE FROM device_schedule_runs WHERE hotel_id = :h AND created_at < :c', ['h' => $hid, 'c' => date('Y-m-d H:i:s', $now - 60 * 86400)]);
        return $out;
    }

    /** Fire one occurrence. Returns [result text, number of TVs]. */
    private static function fire(array $s, int $occ, int $now): array
    {
        $ids = json_decode((string) $s['target_ids'], true) ?: [];
        $emergency = self::emergencyRoomIds();
        $all = Broadcaster::targetRooms((string) $s['target_type'], (array) $ids);
        $rooms = array_values(array_filter($all, fn ($r) => !isset($emergency[(int) $r['id']])));
        $held = count($rooms) < count($all) ? ' · ' . __(':n room(s) with an emergency skipped', ['n' => count($all) - count($rooms)]) : '';
        if ($s['action'] === 'reboot') {
            return self::planRestarts($s, $occ, $rooms, $now, $held);
        }
        $cmd = self::command($s);
        if ($cmd === null) {
            return [__('Not sent: the sound was deleted.'), 0];
        }
        [$command, $payload] = $cmd;
        $bid = self::broadcastRow($s, $command, $payload, $now);
        $n = Broadcaster::queueForRooms($rooms, $command, $payload, $bid);
        return [__('Sent to :n TV(s).', ['n' => $n]) . $held, $n];
    }

    /** One broadcast_commands row per fired occurrence (history / TV acks in Logs & History). */
    private static function broadcastRow(array $s, string $command, array $payload, int $now): int
    {
        return DB::insert('broadcast_commands', [
            'title' => mb_substr(__('Schedule') . ': ' . $s['title'], 0, 190),
            'command' => $command,
            'target_type' => $s['target_type'],
            'target_ids' => (string) $s['target_ids'],
            'payload' => json_out((object) $payload),
            'mode' => 'now',
            'status' => 'completed',
            'start_at' => date('Y-m-d H:i:s', $now),
            'created_by' => $s['created_by'] ?: null,
            'created_at' => date('Y-m-d H:i:s', $now),
        ]);
    }

    /** Plan a REBOOT per TV at the occurrence + a random 0…stagger minutes offset. */
    private static function planRestarts(array $s, int $occ, array $rooms, int $now, string $held): array
    {
        $roomIds = array_map(fn ($r) => (int) $r['id'], $rooms);
        if (!$roomIds) {
            return [__('No TV to restart.') . $held, 0];
        }
        [$in, $p] = DB::in($roomIds, 'r');
        $devices = DB::all("SELECT id FROM devices WHERE hotel_id = :h AND is_revoked = 0 AND room_id IN $in ORDER BY id", $p + ['h' => Tenant::id()]);
        $stagger = max(0, min(self::MAX_STAGGER_MIN, (int) (self::opts($s)['stagger_min'] ?? self::MAX_STAGGER_MIN)));
        $bid = self::broadcastRow($s, 'REBOOT', [], $now);
        foreach ($devices as $d) {
            $due = $occ + ($stagger > 0 ? random_int(0, $stagger * 60) : 0);
            DB::query(
                "INSERT IGNORE INTO device_schedule_runs (hotel_id, schedule_id, occurrence_at, device_id, due_at, status, broadcast_id, created_at)
                 VALUES (:h, :s, :o, :d, :due, 'planned', :b, :c)",
                ['h' => Tenant::id(), 's' => $s['id'], 'o' => date('Y-m-d H:i:s', $occ), 'd' => $d['id'], 'due' => date('Y-m-d H:i:s', $due), 'b' => $bid, 'c' => date('Y-m-d H:i:s', $now)]
            );
        }
        return [__('Restart of :n TV(s) planned over :m minutes.', ['n' => count($devices), 'm' => $stagger]) . $held, count($devices)];
    }

    /**
     * Send planned restarts whose time has come: one REBOOT per TV. Offline TVs, TVs that have not been up
     * for the schedule's minimum hours and TVs in a room with an active emergency are skipped.
     */
    public static function deliverRestarts(?int $now = null): array
    {
        $now ??= time();
        $out = ['sent' => 0, 'skipped' => 0];
        $hid = Tenant::id();
        $due = DB::all(
            "SELECT r.*, s.options, s.title FROM device_schedule_runs r JOIN device_schedules s ON s.id = r.schedule_id AND s.hotel_id = r.hotel_id
             WHERE r.hotel_id = :h AND r.status = 'planned' AND r.device_id > 0 AND r.due_at <= :n ORDER BY r.due_at, r.id LIMIT 500",
            ['h' => $hid, 'n' => date('Y-m-d H:i:s', $now)]
        );
        $emergency = $due ? self::emergencyRoomIds() : [];
        foreach ($due as $run) {
            if (DB::update('device_schedule_runs', ['status' => 'sent'], "id = :id AND status = 'planned'", ['id' => $run['id']]) !== 1) {
                continue;
            }
            $device = DB::one('SELECT * FROM devices WHERE id = :id AND hotel_id = :h', ['id' => $run['device_id'], 'h' => $hid]);
            $minUp = (int) (self::opts($run)['min_uptime_h'] ?? 0);
            $skip = null;
            if (!$device || (int) $device['is_revoked'] || !$device['room_id']) {
                $skip = 'removed';
            } elseif ((int) strtotime((string) $run['due_at']) < $now - self::LATE_SEC) {
                $skip = 'late';
            } elseif (!DeviceManager::isOnline($device)) {
                $skip = 'offline';
            } elseif (isset($emergency[(int) $device['room_id']])) {
                $skip = 'emergency';
            } elseif ($minUp > 0 && ($up = self::uptime($device, $now)) !== null && $up < $minUp * 3600) {
                $skip = 'uptime';
            }
            if ($skip !== null) {
                DB::update('device_schedule_runs', ['status' => 'skipped', 'note' => $skip], 'id = :id', ['id' => $run['id']]);
                $out['skipped']++;
                continue;
            }
            DB::insert('device_commands', [
                'device_id' => $device['id'], 'broadcast_id' => $run['broadcast_id'] ?: null, 'command' => 'REBOOT',
                'payload' => '{}', 'status' => 'pending', 'created_at' => date('Y-m-d H:i:s', $now),
            ]);
            if ($run['broadcast_id']) {
                DB::insert('broadcast_logs', [
                    'broadcast_id' => $run['broadcast_id'], 'device_id' => $device['id'], 'room_id' => $device['room_id'],
                    'event' => 'queued', 'message' => 'REBOOT', 'created_at' => date('Y-m-d H:i:s', $now),
                ]);
            }
            $out['sent']++;
        }
        return $out;
    }

    /** Seconds the TV has been running (last heartbeat's uptime + time since), null when unknown. */
    public static function uptime(array $device, int $now): ?int
    {
        if ($device['uptime_sec'] === null || $device['uptime_sec'] === '' || empty($device['last_heartbeat'])) {
            return null;
        }
        return (int) $device['uptime_sec'] + max(0, $now - (int) strtotime((string) $device['last_heartbeat']));
    }

    /** [room id => true] for rooms reached by an active emergency of the current hotel. */
    public static function emergencyRoomIds(): array
    {
        $out = [];
        foreach (Broadcaster::activeEmergencies() as $b) {
            foreach (Broadcaster::targetRooms((string) $b['target_type'], json_decode((string) $b['target_ids'], true) ?: []) as $r) {
                $out[(int) $r['id']] = true;
            }
        }
        return $out;
    }

    /** Last runs of a schedule (newest first) for the page: [occurrence_at, status counts, note]. */
    public static function history(int $id, int $limit = 5): array
    {
        return DB::all(
            "SELECT occurrence_at, MAX(CASE WHEN device_id = 0 THEN note END) AS note,
                    SUM(device_id > 0 AND status = 'sent') AS sent, SUM(device_id > 0 AND status = 'skipped') AS skipped,
                    SUM(device_id > 0 AND status = 'planned') AS planned
             FROM device_schedule_runs WHERE hotel_id = :h AND schedule_id = :s
             GROUP BY occurrence_at ORDER BY occurrence_at DESC LIMIT " . max(1, min(50, $limit)),
            ['h' => Tenant::id(), 's' => $id]
        );
    }

    // ------------------------------------------------------------------ announce now

    /** Send a spoken announcement now (Announce now form). Returns [broadcastId, deviceCount]. */
    public static function announce(array $in, ?int $userId = null): array
    {
        [$type, $ids] = Broadcaster::parseTarget($in);
        if ($type !== 'all' && !$ids) {
            throw new InvalidArgumentException(__('Select at least one target.'));
        }
        $payload = self::speakPayload($in);
        [$bid, $count] = Broadcaster::sendCommand('SPEAK', $type, $ids, $payload, $userId);
        DB::update('broadcast_commands', ['title' => mb_substr(__('Announcement') . ': ' . $payload['text'], 0, 190)], 'id = :id', ['id' => $bid]);
        ActivityLog::add('announce', 'broadcast', $bid, mb_substr($payload['text'], 0, 120) . ' → ' . Broadcaster::describeTarget($type, $ids) . " ($count TVs)");
        return [$bid, $count];
    }

    /** Short human description of what a schedule does ("Volume 10 %", "HDMI 2", "School bell ×2" …). */
    public static function describe(array $s): string
    {
        $o = self::opts($s);
        return match ($s['action']) {
            'volume' => __('Volume :v %', ['v' => (int) ($o['level'] ?? 0)]),
            'input' => ($o['input'] ?? '') === 'live_tv' ? __('Live TV') : 'HDMI ' . substr((string) ($o['input'] ?? ''), 4),
            'reboot' => __('Spread over :m min', ['m' => (int) ($o['stagger_min'] ?? self::MAX_STAGGER_MIN)])
                . (!empty($o['min_uptime_h']) ? ' · ' . __('only when up :h h+', ['h' => (int) $o['min_uptime_h']]) : '') . ' · ' . __('offline TVs skipped'),
            'bell' => (Sounds::resolve((string) ($o['sound'] ?? ''))['name'] ?? __('Sound deleted')) . ((int) ($o['repeat'] ?? 1) > 1 ? ' ×' . (int) $o['repeat'] : '')
                . (isset($o['volume']) ? ' · ' . __('Volume :v %', ['v' => (int) $o['volume']]) : ''),
            'speak' => '“' . mb_strimwidth((string) ($o['text'] ?? ''), 0, 80, '…') . '” · ' . self::langLabel((string) ($o['lang'] ?? 'auto')),
            default => '',
        };
    }

    public static function langLabel(string $lang): string
    {
        return $lang === 'auto' || !isset(self::SPEAK_LANGS[$lang]) ? __('Automatic') : self::SPEAK_LANGS[$lang];
    }

    /** "22:00 · every day" / "22:00 · Mon, Tue" / "22:00 · 15 Oct 2026". */
    public static function whenLabel(array $s): string
    {
        $t = substr((string) $s['run_time'], 0, 5);
        $names = [1 => __('Mon'), 2 => __('Tue'), 3 => __('Wed'), 4 => __('Thu'), 5 => __('Fri'), 6 => __('Sat'), 7 => __('Sun')];
        return match ($s['repeat_mode']) {
            'once' => $t . ' · ' . date('d M Y', (int) strtotime((string) $s['run_date'])),
            'weekly' => $t . ' · ' . implode(', ', array_map(fn ($d) => $names[(int) $d] ?? $d, explode(',', (string) $s['days']))),
            default => $t . ' · ' . __('every day'),
        };
    }
}
