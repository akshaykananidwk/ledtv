<?php
declare(strict_types=1);

/**
 * Holiday calendar (#35, docs/modules/scheduling.md). Tenant table `holidays`: a date range
 * (start_date … end_date, inclusive, hotel local dates), a target (all / rooms / groups / floors, same
 * format as broadcasts) and an action:
 *   - tv_off:       targeted TVs are off all day, exactly like a scheduled power-off window
 *                   (mode 'off', screen_on false, off_reason 'holiday'); emergencies still wake them;
 *   - show_content: targeted TVs show the chosen content / playlist instead of their own assignment
 *                   (time-window broadcasts of that day still win);
 *   - none:         only a marker in the calendar.
 * Applied by ContentResolver::build() (Holidays::forRoom).
 */
final class Holidays
{
    public const ACTIONS = ['none', 'tv_off', 'show_content'];

    public static function find(int $id): ?array
    {
        return Tenant::find('holidays', $id);
    }

    public static function actionLabel(string $action): string
    {
        return match ($action) {
            'tv_off' => __('TVs off'),
            'show_content' => __('Show content'),
            default => __('Marker only'),
        };
    }

    /** Active holidays covering a date (Y-m-d, hotel local), newest first. */
    public static function onDate(string $date): array
    {
        return DB::all(
            'SELECT * FROM holidays WHERE hotel_id = :h AND is_active = 1 AND start_date <= :d AND end_date >= :d2 ORDER BY id DESC',
            ['h' => Tenant::id(), 'd' => $date, 'd2' => $date]
        );
    }

    /**
     * The holiday that decides what a room shows today (null = none / only markers): the most specific
     * target wins (rooms, then groups / floors, then all), newest first; 'none' holidays are ignored.
     */
    public static function forRoom(array $room, array $groupIds, ?int $ts = null): ?array
    {
        $rows = self::onDate(date('Y-m-d', $ts ?? ContentRules::now()));
        $rank = ['rooms' => 0, 'groups' => 1, 'floors' => 1, 'all' => 2];
        $best = null;
        foreach ($rows as $h) {
            if ($h['action'] === 'none' || !ContentResolver::targets($h, $room, $groupIds)) {
                continue;
            }
            if ($best === null || ($rank[$h['target_type']] ?? 3) < ($rank[$best['target_type']] ?? 3)) {
                $best = $h;
            }
        }
        return $best;
    }

    /** Validate form / JSON input. Returns [data, errors]. Target ids of another hotel → 404, of other TVs → 403. */
    public static function validate(array $in): array
    {
        $errors = [];
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 190) {
            $errors[] = __('Holiday name is required (max 190 characters).');
        }
        $start = self::parseDate($in['start_date'] ?? '');
        $end = self::parseDate($in['end_date'] ?? '') ?? $start;
        if (!$start) {
            $errors[] = __('Choose the holiday date.');
        } elseif ($end < $start) {
            $errors[] = __('The end date must not be before the start date.');
        } elseif ((strtotime($end) - strtotime($start)) > 366 * 86400) {
            $errors[] = __('A holiday can last at most one year.');
        }
        $action = in_array($in['action'] ?? '', self::ACTIONS, true) ? (string) $in['action'] : 'none';
        $contentId = null;
        $playlistId = null;
        if ($action === 'show_content') {
            $source = (string) ($in['source'] ?? '');
            if (preg_match('/^c:(\d{1,9})$/', $source, $m) && ($c = ContentManager::find((int) $m[1]))) {
                if (!ContentRules::approved($c)) {
                    $errors[] = __('This content is waiting for approval and cannot be used yet.');
                }
                $contentId = (int) $m[1];
            } elseif (preg_match('/^p:(\d{1,9})$/', $source, $m) && ContentManager::findPlaylist((int) $m[1])) {
                $playlistId = (int) $m[1];
            } else {
                $errors[] = __('Select content or a playlist.');
            }
        }
        [$type, $ids] = Broadcaster::parseTarget($in);
        if ($type !== 'all' && !$ids) {
            $errors[] = __('Select at least one target.');
        }
        return [[
            'name' => mb_substr($name, 0, 190),
            'start_date' => $start,
            'end_date' => $end,
            'action' => $action,
            'content_id' => $contentId,
            'playlist_id' => $playlistId,
            'target_type' => $type,
            'target_ids' => json_out($ids),
            'is_active' => array_key_exists('is_active', $in) ? (!empty($in['is_active']) ? 1 : 0) : 1,
        ], $errors];
    }

    public static function parseDate(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : '';
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }

    /** Insert or update; refreshes TVs. Returns the id. */
    public static function save(array $data, ?int $id = null): int
    {
        if ($id) {
            $old = self::find($id);
            DB::update('holidays', $data, 'id = :id', ['id' => $id]);
            if ($old) {
                self::refreshTvs($old);
            }
        } else {
            $id = DB::insert('holidays', $data + ['created_by' => Auth::id(), 'created_at' => now()]);
        }
        Settings::bumpContentVersion();
        self::refreshTvs($data);
        return $id;
    }

    public static function delete(array $row): void
    {
        DB::delete('holidays', 'id = :id', ['id' => $row['id']]);
        Settings::bumpContentVersion();
        self::refreshTvs($row);
    }

    /** Tell targeted TVs to fetch their content again when the holiday covers today. */
    public static function refreshTvs(array $h): void
    {
        $today = date('Y-m-d', ContentRules::now());
        if (($h['start_date'] ?? '') <= $today && ($h['end_date'] ?? '') >= $today) {
            $ids = is_string($h['target_ids'] ?? null) ? (json_decode($h['target_ids'], true) ?: []) : (array) ($h['target_ids'] ?? []);
            Broadcaster::queueForRooms(Broadcaster::targetRooms((string) $h['target_type'], $ids), 'SHOW_CONTENT');
        }
    }

    /** May the current user see this holiday (hotel-wide ones, or targets that are all theirs)? */
    public static function visible(array $h): bool
    {
        return $h['target_type'] === 'all' || Access::canBroadcast($h);
    }

    /**
     * Starter list: Indian public holidays 2026–2027 (gazetted central holidays + widely observed
     * festivals). Lunar dates (Holi, Eid, Diwali …) are approximate — the admin page says to verify
     * them against the official list of your state before relying on them.
     * @return array<int, array{0:string,1:string}> [date, English name]
     */
    public static function indianHolidays(): array
    {
        return [
            ['2026-01-14', 'Makar Sankranti / Uttarayan'],
            ['2026-01-26', 'Republic Day'],
            ['2026-03-04', 'Holi'],
            ['2026-03-21', 'Id-ul-Fitr'],
            ['2026-03-26', 'Ram Navami'],
            ['2026-03-31', 'Mahavir Jayanti'],
            ['2026-04-03', 'Good Friday'],
            ['2026-05-01', 'Buddha Purnima'],
            ['2026-05-27', 'Id-ul-Zuha (Bakrid)'],
            ['2026-06-26', 'Muharram'],
            ['2026-08-15', 'Independence Day'],
            ['2026-08-26', 'Milad-un-Nabi'],
            ['2026-09-04', 'Janmashtami'],
            ['2026-10-02', 'Gandhi Jayanti'],
            ['2026-10-20', 'Dussehra'],
            ['2026-11-08', 'Diwali'],
            ['2026-11-24', 'Guru Nanak Jayanti'],
            ['2026-12-25', 'Christmas'],
            ['2027-01-14', 'Makar Sankranti / Uttarayan'],
            ['2027-01-26', 'Republic Day'],
            ['2027-03-10', 'Id-ul-Fitr'],
            ['2027-03-22', 'Holi'],
            ['2027-03-26', 'Good Friday'],
            ['2027-04-15', 'Ram Navami'],
            ['2027-04-19', 'Mahavir Jayanti'],
            ['2027-05-17', 'Id-ul-Zuha (Bakrid)'],
            ['2027-05-20', 'Buddha Purnima'],
            ['2027-06-15', 'Muharram'],
            ['2027-08-15', 'Independence Day'],
            ['2027-08-15', 'Milad-un-Nabi'],
            ['2027-08-25', 'Janmashtami'],
            ['2027-10-02', 'Gandhi Jayanti'],
            ['2027-10-09', 'Dussehra'],
            ['2027-10-29', 'Diwali'],
            ['2027-11-14', 'Guru Nanak Jayanti'],
            ['2027-12-25', 'Christmas'],
        ];
    }

    /** Import the starter list (skips dates already present with the same name). Returns the number added. */
    public static function importIndian(string $action = 'none'): int
    {
        $action = in_array($action, ['none', 'tv_off'], true) ? $action : 'none';
        $n = 0;
        foreach (self::indianHolidays() as [$date, $name]) {
            $exists = DB::value('SELECT id FROM holidays WHERE hotel_id = :h AND start_date = :d AND name = :n', ['h' => Tenant::id(), 'd' => $date, 'n' => __($name)]);
            if ($exists) {
                continue;
            }
            DB::insert('holidays', [
                'name' => __($name), 'start_date' => $date, 'end_date' => $date, 'action' => $action,
                'target_type' => 'all', 'target_ids' => '[]', 'is_active' => 1, 'created_by' => Auth::id(), 'created_at' => now(),
            ]);
            $n++;
        }
        if ($n) {
            Settings::bumpContentVersion();
        }
        return $n;
    }
}
