<?php
declare(strict_types=1);

/**
 * Gym / yoga / school / coaching / OPD schedule (#6, display app "class_schedule").
 * Tenant table `class_sessions` (migrations/017_business_apps.sql), managed in admin/class_schedule.php,
 * shown by core/Apps/ClassScheduleApp.php. A session repeats every week on its `days`
 * (ISO weekdays, 1 = Monday … 7 = Sunday) from start_time to end_time (same day).
 *
 * NOW / NEXT logic is the pure function annotate($sessions, $now) (hotel time zone = PHP default
 * time zone, set by Tenant::set()): NOW = today and start <= now < end; NEXT = today's sessions with the
 * earliest start after now (several when they start together); DONE = ended today; LATER = the rest.
 */
final class ClassSchedule
{
    public const LEVELS = ['' => 'None', 'beginner' => 'Beginner', 'intermediate' => 'Intermediate', 'advanced' => 'Advanced', 'all' => 'All levels'];
    public const COLORS = ['#1565C0', '#2E7D32', '#C62828', '#6A1B9A', '#EF6C00', '#00838F', '#AD1457', '#5D4037'];
    public const DAY_NAMES = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
    public const DAY_SHORT = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    public const DEFAULTS = [
        'id' => 0, 'name' => '', 'trainer' => '', 'days' => '1,2,3,4,5,6,7', 'start_time' => '06:00:00', 'end_time' => '07:00:00',
        'room' => '', 'level' => '', 'color' => '#1565C0', 'photo_path' => null, 'thumb_path' => null, 'is_active' => 1,
    ];

    public static function find(int $id): ?array
    {
        return Tenant::find('class_sessions', $id);
    }

    public static function all(bool $activeOnly = false): array
    {
        return DB::all('SELECT * FROM class_sessions WHERE hotel_id = :h' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY start_time, end_time, name, id', ['h' => Tenant::id()]);
    }

    /** ISO weekdays of a session row. @return int[] */
    public static function days(array $s): array
    {
        $out = [];
        foreach (explode(',', (string) ($s['days'] ?? '')) as $d) {
            $d = (int) trim($d);
            if ($d >= 1 && $d <= 7) {
                $out[$d] = $d;
            }
        }
        ksort($out);
        return array_values($out);
    }

    /** Sessions running on ISO weekday $dow, by start time. */
    public static function forDay(array $sessions, int $dow): array
    {
        $out = array_values(array_filter($sessions, static fn (array $s): bool => in_array($dow, self::days($s), true)));
        usort($out, static fn (array $a, array $b): int => [$a['start_time'], $a['end_time']] <=> [$b['start_time'], $b['end_time']]);
        return $out;
    }

    /**
     * Today's sessions (weekday of $now) with 'state' = now | next | done | later. Pure: no DB, no clock.
     * @return array<int, array>
     */
    public static function annotate(array $sessions, int $now): array
    {
        $today = self::forDay($sessions, (int) date('N', $now));
        $m = (int) date('G', $now) * 60 + (int) date('i', $now);
        $nextStart = null;
        foreach ($today as $s) {
            $start = BusinessApps::minutes((string) $s['start_time']);
            if ($start > $m && ($nextStart === null || $start < $nextStart)) {
                $nextStart = $start;
            }
        }
        foreach ($today as &$s) {
            $start = BusinessApps::minutes((string) $s['start_time']);
            $end = BusinessApps::minutes((string) $s['end_time']);
            $s['state'] = match (true) {
                $start <= $m && $m < $end => 'now',
                $end <= $m => 'done',
                $start === $nextStart => 'next',
                default => 'later',
            };
        }
        unset($s);
        return $today;
    }

    /** @return array{0: array, 1: string[]} */
    public static function validate(array $in): array
    {
        $errors = [];
        $name = BusinessApps::str($in, 'name', 300);
        if ($name === '') {
            $errors[] = __('The class name is required.');
        } elseif (mb_strlen($name) > 120) {
            $errors[] = __('The name can have at most 120 characters.');
        }
        $days = [];
        foreach ((array) ($in['days'] ?? []) as $d) {
            if (is_scalar($d) && (int) $d >= 1 && (int) $d <= 7) {
                $days[(int) $d] = (int) $d;
            }
        }
        ksort($days);
        if (!$days) {
            $errors[] = __('Choose at least one day.');
        }
        $start = BusinessApps::time($in['start_time'] ?? null);
        $end = BusinessApps::time($in['end_time'] ?? null);
        if ($start === null || $end === null) {
            $errors[] = __('Enter a valid start and end time.');
        } elseif ($end <= $start) {
            $errors[] = __('The end time must be after the start time.');
        }
        $level = is_string($in['level'] ?? null) && isset(self::LEVELS[$in['level']]) ? $in['level'] : '';
        return [[
            'name' => mb_substr($name, 0, 120),
            'trainer' => BusinessApps::str($in, 'trainer', 120),
            'days' => implode(',', $days),
            'start_time' => $start ?? self::DEFAULTS['start_time'],
            'end_time' => $end ?? self::DEFAULTS['end_time'],
            'room' => BusinessApps::str($in, 'room', 80),
            'level' => $level,
            'color' => clean_color(is_string($in['color'] ?? null) ? $in['color'] : null, '#1565C0'),
            'is_active' => !empty($in['is_active']) ? 1 : 0,
        ], $errors];
    }

    public static function save(?int $id, array $data): int
    {
        if ($id) {
            DB::update('class_sessions', $data, 'id = :id', ['id' => $id]);
            return $id;
        }
        return DB::insert('class_sessions', $data + ['created_by' => Auth::id(), 'created_at' => now()]);
    }

    public static function delete(int $id): void
    {
        $s = self::find($id);
        if (!$s) {
            return;
        }
        DB::delete('class_sessions', 'id = :id', ['id' => $id]);
        Uploader::delete($s['photo_path'], $s['thumb_path']);
    }

    public static function photoUrl(array $s): ?string
    {
        return !empty($s['photo_path']) ? media_url((string) $s['photo_path']) : null;
    }

    /** "Mon–Fri", "Mon, Wed, Fri", "Every day" (translated). */
    public static function daysLabel(array $s): string
    {
        $d = self::days($s);
        if (count($d) === 7) {
            return __('Every day');
        }
        if (count($d) >= 3 && $d[count($d) - 1] - $d[0] === count($d) - 1) {
            return __(self::DAY_SHORT[$d[0]]) . '–' . __(self::DAY_SHORT[$d[count($d) - 1]]);
        }
        return implode(', ', array_map(static fn (int $x): string => __(self::DAY_SHORT[$x]), $d));
    }
}
