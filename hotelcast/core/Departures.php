<?php
declare(strict_types=1);

/**
 * Bus / railway / airport departures & arrivals board (#7, display app "departures").
 * Tenant table `departures` (migrations/017_business_apps.sql), managed in admin/departures.php
 * (CRUD + one-tap status buttons), shown by core/Apps/DeparturesApp.php.
 *
 * A row runs on one date (service_date) or every day (service_date NULL). Its status applies to the
 * day in status_date: a daily row whose status_date is not the shown day is "on time" with no delay,
 * so yesterday's "Cancelled" never sticks. The board itself is the pure function
 * board($rows, $now, $opts) — occurrences of today and tomorrow, past ones hidden N minutes after
 * their (expected) time, sorted by scheduled time.
 */
final class Departures
{
    public const STATUSES = ['on_time' => 'On time', 'delayed' => 'Delayed', 'boarding' => 'Boarding', 'departed' => 'Departed', 'cancelled' => 'Cancelled', 'arrived' => 'Arrived'];
    public const KINDS = ['departure' => 'Departure', 'arrival' => 'Arrival'];

    public const DEFAULTS = [
        'id' => 0, 'kind' => 'departure', 'sched_time' => '', 'service_date' => null, 'number' => '', 'destination' => '',
        'destination_gu' => '', 'destination_hi' => '', 'platform' => '', 'status' => 'on_time', 'delay_min' => 0,
        'remark' => '', 'status_date' => null, 'is_active' => 1,
    ];

    public static function find(int $id): ?array
    {
        return Tenant::find('departures', $id);
    }

    public static function all(bool $activeOnly = false): array
    {
        return DB::all('SELECT * FROM departures WHERE hotel_id = :h' . ($activeOnly ? ' AND is_active = 1' : '')
            . ' ORDER BY sched_time, number, id', ['h' => Tenant::id()]);
    }

    /** Rows that can appear on today's / tomorrow's board (daily or dated today / tomorrow). */
    public static function candidates(int $now): array
    {
        $today = date('Y-m-d', $now);
        $tomorrow = date('Y-m-d', self::midnight($now, 1));
        return DB::all('SELECT * FROM departures WHERE hotel_id = :h AND is_active = 1 AND (service_date IS NULL OR service_date IN (:d1, :d2)) ORDER BY sched_time, number, id',
            ['h' => Tenant::id(), 'd1' => $today, 'd2' => $tomorrow]);
    }

    /** Unix time of local midnight $days days after the day of $now. */
    public static function midnight(int $now, int $days = 0): int
    {
        $d = new DateTimeImmutable('@' . $now);
        $d = $d->setTimezone(new DateTimeZone(date_default_timezone_get()))->setTime(0, 0);
        return $days ? $d->modify('+' . $days . ' day')->getTimestamp() : $d->getTimestamp();
    }

    /**
     * Status of a row on $date: [status, delay minutes, remark] (daily rows reset every day).
     * @return array{0: string, 1: int, 2: string}
     */
    public static function statusOn(array $row, string $date): array
    {
        if (empty($row['service_date']) && ($row['status_date'] ?? null) !== $date) {
            return ['on_time', 0, ''];
        }
        $st = isset(self::STATUSES[$row['status']]) ? (string) $row['status'] : 'on_time';
        return [$st, max(0, (int) $row['delay_min']), (string) $row['remark']];
    }

    /**
     * Board entries at $now. Pure (no DB, no clock). $opts: kind (departure|arrival|both),
     * hide_after_min (past entries stay this long after their expected time), lookahead_hours, max.
     * @return array<int, array> each: row + date, sched_ts, eff_ts, status, delay_min, remark, tomorrow
     */
    public static function board(array $rows, int $now, array $opts = []): array
    {
        $kind = $opts['kind'] ?? 'both';
        $hide = max(0, (int) ($opts['hide_after_min'] ?? 10)) * 60;
        $ahead = max(1, (int) ($opts['lookahead_hours'] ?? 12)) * 3600;
        $max = max(1, (int) ($opts['max'] ?? 100));
        $out = [];
        foreach ([0, 1] as $off) {
            $mid = self::midnight($now, $off);
            $date = date('Y-m-d', $mid);
            foreach ($rows as $r) {
                if (isset($r['is_active']) && !(int) $r['is_active']) {
                    continue;
                }
                if ($kind !== 'both' && $r['kind'] !== $kind) {
                    continue;
                }
                if (!empty($r['service_date']) && $r['service_date'] !== $date) {
                    continue;
                }
                [$status, $delay, $remark] = self::statusOn($r, $date);
                $sched = $mid + BusinessApps::minutes((string) $r['sched_time']) * 60;
                $eff = $sched + ($status === 'cancelled' ? 0 : $delay * 60);
                if ($eff + $hide < $now || $sched > $now + $ahead) {
                    continue;
                }
                $out[] = $r + ['date' => $date, 'sched_ts' => $sched, 'eff_ts' => $eff, 'tomorrow' => $off === 1]
                    + ['status_now' => $status, 'delay_now' => $delay, 'remark_now' => $remark];
            }
        }
        usort($out, static fn (array $a, array $b): int => [$a['sched_ts'], (string) $a['number'], (int) $a['id']] <=> [$b['sched_ts'], (string) $b['number'], (int) $b['id']]);
        return array_slice($out, 0, $max);
    }

    /** Destination in the page language (falls back to the main / English name). */
    public static function destination(array $r, string $lang): string
    {
        $local = $lang === 'gu' ? (string) ($r['destination_gu'] ?? '') : ($lang === 'hi' ? (string) ($r['destination_hi'] ?? '') : '');
        return $local !== '' ? $local : (string) $r['destination'];
    }

    public static function statusLabel(string $status): string
    {
        return __(self::STATUSES[$status] ?? 'On time');
    }

    /** @return array{0: array, 1: string[]} */
    public static function validate(array $in): array
    {
        $errors = [];
        $time = BusinessApps::time($in['sched_time'] ?? null);
        if ($time === null) {
            $errors[] = __('Enter a valid time.');
        }
        $dest = BusinessApps::str($in, 'destination', 300);
        if ($dest === '') {
            $errors[] = __('The destination is required.');
        } elseif (mb_strlen($dest) > 120) {
            $errors[] = __('The destination can have at most 120 characters.');
        }
        $date = !empty($in['daily']) ? null : BusinessApps::date($in, 'service_date', $errors);
        if (empty($in['daily']) && $date === null && !$errors) {
            $errors[] = __('Choose a date or tick "Runs every day".');
        }
        $status = is_string($in['status'] ?? null) && isset(self::STATUSES[$in['status']]) ? $in['status'] : 'on_time';
        $delay = is_numeric($in['delay_min'] ?? null) ? max(0, min(1440, (int) $in['delay_min'])) : 0;
        return [[
            'kind' => is_string($in['kind'] ?? null) && isset(self::KINDS[$in['kind']]) ? $in['kind'] : 'departure',
            'sched_time' => $time ?? '00:00:00',
            'service_date' => $date,
            'number' => BusinessApps::str($in, 'number', 40),
            'destination' => mb_substr($dest, 0, 120),
            'destination_gu' => BusinessApps::str($in, 'destination_gu', 120),
            'destination_hi' => BusinessApps::str($in, 'destination_hi', 120),
            'platform' => BusinessApps::str($in, 'platform', 20),
            'status' => $status,
            'delay_min' => $status === 'on_time' ? 0 : $delay,
            'remark' => BusinessApps::str($in, 'remark', 190),
            'is_active' => !empty($in['is_active']) ? 1 : 0,
        ], $errors];
    }

    public static function save(?int $id, array $data): int
    {
        // A status saved in the form applies to today (daily rows) / the service date.
        $data['status_date'] = $data['service_date'] ?? date('Y-m-d');
        if ($id) {
            DB::update('departures', $data, 'id = :id', ['id' => $id]);
            return $id;
        }
        return DB::insert('departures', $data + ['created_by' => Auth::id(), 'created_at' => now()]);
    }

    /**
     * One-tap status change for the occurrence on $date (quick buttons): $status, plus $addDelay minutes
     * ("Delayed +15"). On time clears the delay. Returns the new [status, delay].
     * @return array{0: string, 1: int}
     */
    public static function quick(array $row, string $date, string $status, int $addDelay = 0): array
    {
        if (!isset(self::STATUSES[$status])) {
            throw new InvalidArgumentException('Unknown status');
        }
        [, $delay] = self::statusOn($row, $date);
        $delay = $status === 'on_time' ? 0 : min(1440, $delay + max(0, $addDelay));
        $fresh = $status === 'on_time' || ($row['status_date'] ?? null) !== $date; // a new day starts without yesterday's remark
        DB::update('departures', ['status' => $status, 'delay_min' => $delay, 'status_date' => $date] + ($fresh ? ['remark' => ''] : []), 'id = :id', ['id' => (int) $row['id']]);
        return [$status, $delay];
    }

    public static function delete(int $id): void
    {
        DB::delete('departures', 'id = :id', ['id' => $id]);
    }
}
