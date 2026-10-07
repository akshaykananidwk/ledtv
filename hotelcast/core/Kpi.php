<?php
declare(strict_types=1);

/**
 * Factory / office KPI dashboard (#8, display app "kpi_dashboard"). Tenant table `kpi_tiles`
 * (migrations/017_business_apps.sql), managed in admin/kpi.php (+1 / −1 / set buttons), shown by
 * core/Apps/KpiDashboardApp.php; machines can push values to POST /api/kpi/push with a per-tile token
 * (api/routes/kpi.php, only the SHA-256 of the token is stored).
 *
 * Types: counter (number vs target), percent (0–100 %), text (e.g. "Line 2 running"),
 * days_since ("Days without accident: 128" — counted from since_date in the hotel time zone).
 * Colour: good_at / bad_at thresholds; good_at >= bad_at means "higher is better", else lower is better.
 */
final class Kpi
{
    public const TYPES = ['counter' => 'Counter', 'percent' => 'Percent', 'text' => 'Text', 'days_since' => 'Days since'];
    public const MAX_VALUE = 9999999999999.99;

    public const DEFAULTS = [
        'id' => 0, 'label' => '', 'type' => 'counter', 'value_num' => 0, 'value_text' => '', 'since_date' => null, 'target' => null,
        'unit' => '', 'good_at' => null, 'bad_at' => null, 'sort' => 0, 'is_active' => 1, 'push_token_hash' => null, 'push_token_hint' => '', 'pushed_at' => null,
    ];

    public static function find(int $id): ?array
    {
        return Tenant::find('kpi_tiles', $id);
    }

    public static function all(bool $activeOnly = false): array
    {
        return DB::all('SELECT * FROM kpi_tiles WHERE hotel_id = :h' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY sort, id', ['h' => Tenant::id()]);
    }

    /** Whole days from since_date to the day of $now (hotel time zone), never negative. */
    public static function daysSince(?string $since, int $now): int
    {
        if (!$since) {
            return 0;
        }
        $tz = new DateTimeZone(date_default_timezone_get());
        $a = DateTimeImmutable::createFromFormat('!Y-m-d', $since, $tz);
        if (!$a) {
            return 0;
        }
        $b = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0);
        return $b < $a ? 0 : (int) $a->diff($b)->days;
    }

    /** 'good' | 'warn' | 'bad' | '' for a number and the thresholds. Pure. */
    public static function level(?float $v, ?float $goodAt, ?float $badAt): string
    {
        if ($v === null || ($goodAt === null && $badAt === null)) {
            return '';
        }
        $higher = $goodAt === null || $badAt === null || $goodAt >= $badAt;
        if ($higher) {
            if ($goodAt !== null && $v >= $goodAt) {
                return 'good';
            }
            if ($badAt !== null && $v <= $badAt) {
                return 'bad';
            }
            return $goodAt !== null && $badAt !== null ? 'warn' : ($goodAt !== null ? 'warn' : 'good');
        }
        if ($v <= $goodAt) {
            return 'good';
        }
        return $v >= $badAt ? 'bad' : 'warn';
    }

    /** 1234.5 → "1,234.5", 128 → "128" (Indian grouping). */
    public static function fmt(float $n): string
    {
        $s = BusinessApps::indian($n);
        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }

    /**
     * What the TV shows for a tile at $now: value text, number, unit, progress % vs target (0–100),
     * level and target text. Pure (no DB).
     */
    public static function view(array $t, int $now): array
    {
        $type = isset(self::TYPES[$t['type']]) ? (string) $t['type'] : 'counter';
        $target = $t['target'] !== null && $t['target'] !== '' ? (float) $t['target'] : null;
        $num = null;
        $unit = (string) $t['unit'];
        if ($type === 'text') {
            $value = (string) $t['value_text'];
        } elseif ($type === 'days_since') {
            $num = (float) self::daysSince($t['since_date'] ?? null, $now);
            $value = self::fmt($num);
        } else {
            $num = (float) $t['value_num'];
            $value = self::fmt($num);
            if ($type === 'percent' && $unit === '') {
                $unit = '%';
            }
        }
        $progress = null;
        if ($num !== null && $target !== null && $target > 0) {
            $progress = max(0.0, min(100.0, round($num / $target * 100, 1)));
        } elseif ($type === 'percent' && $num !== null) {
            $progress = max(0.0, min(100.0, $num));
        }
        $good = $t['good_at'] !== null && $t['good_at'] !== '' ? (float) $t['good_at'] : null;
        $bad = $t['bad_at'] !== null && $t['bad_at'] !== '' ? (float) $t['bad_at'] : null;
        return [
            'id' => (int) $t['id'],
            'label' => (string) $t['label'],
            'type' => $type,
            'value' => $value,
            'num' => $num,
            'unit' => $unit,
            'target' => $target,
            'target_text' => $target !== null ? self::fmt($target) . ($unit !== '' ? ' ' . $unit : '') : '',
            'progress' => $progress,
            'level' => self::level($num, $good, $bad),
        ];
    }

    /** @return array{0: array, 1: string[]} */
    public static function validate(array $in): array
    {
        $errors = [];
        $label = BusinessApps::str($in, 'label', 300);
        if ($label === '') {
            $errors[] = __('The tile name is required.');
        } elseif (mb_strlen($label) > 120) {
            $errors[] = __('The name can have at most 120 characters.');
        }
        $type = is_string($in['type'] ?? null) && isset(self::TYPES[$in['type']]) ? $in['type'] : 'counter';
        $value = BusinessApps::number($in, 'value_num', __('Value'), -self::MAX_VALUE, self::MAX_VALUE, $errors);
        if ($type === 'percent' && $value !== null && ($value < 0 || $value > 100)) {
            $errors[] = __('A percent value must be between 0 and 100.');
        }
        $since = BusinessApps::date($in, 'since_date', $errors);
        if ($type === 'days_since' && $since === null && !$errors) {
            $errors[] = __('Enter the date to count the days from.');
        }
        if ($since !== null && $since > date('Y-m-d')) {
            $errors[] = __('The date cannot be in the future.');
        }
        return [[
            'label' => mb_substr($label, 0, 120),
            'type' => $type,
            'value_num' => $value ?? 0,
            'value_text' => BusinessApps::str($in, 'value_text', 190),
            'since_date' => $since,
            'target' => BusinessApps::number($in, 'target', __('Target'), 0, self::MAX_VALUE, $errors),
            'unit' => BusinessApps::str($in, 'unit', 20),
            'good_at' => BusinessApps::number($in, 'good_at', __('Green from'), -self::MAX_VALUE, self::MAX_VALUE, $errors),
            'bad_at' => BusinessApps::number($in, 'bad_at', __('Red at'), -self::MAX_VALUE, self::MAX_VALUE, $errors),
            'sort' => is_numeric($in['sort'] ?? null) ? max(-1000, min(1000, (int) $in['sort'])) : 0,
            'is_active' => !empty($in['is_active']) ? 1 : 0,
        ], $errors];
    }

    public static function save(?int $id, array $data): int
    {
        if ($id) {
            DB::update('kpi_tiles', $data, 'id = :id', ['id' => $id]);
            return $id;
        }
        return DB::insert('kpi_tiles', $data + ['created_by' => Auth::id(), 'created_at' => now()]);
    }

    public static function delete(int $id): void
    {
        DB::delete('kpi_tiles', 'id = :id', ['id' => $id]);
    }

    /**
     * Change a tile's value (quick buttons and the push API). $op: add (number), set (number or text),
     * reset (days_since → today), since (days_since → date). Throws InvalidArgumentException on bad input.
     * Returns the updated row.
     */
    public static function apply(array $t, string $op, mixed $value = null): array
    {
        $type = (string) $t['type'];
        $data = [];
        switch ($op) {
            case 'add':
            case 'set':
                if ($type === 'days_since') {
                    throw new InvalidArgumentException(__('Use "reset" or a date for a days-since tile.'));
                }
                if ($type === 'text') {
                    if ($op === 'add' || !is_scalar($value)) {
                        throw new InvalidArgumentException(__('A text tile needs a text value.'));
                    }
                    $data['value_text'] = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $value) ?? ''), 0, 190);
                    break;
                }
                if (is_string($value)) {
                    $value = str_replace([',', ' '], '', $value);
                }
                if (!is_numeric($value)) {
                    throw new InvalidArgumentException(__('The value must be a number.'));
                }
                $n = round($op === 'add' ? (float) $t['value_num'] + (float) $value : (float) $value, 2);
                if ($type === 'percent') {
                    $n = max(0.0, min(100.0, $n));
                }
                if (abs($n) > self::MAX_VALUE) {
                    throw new InvalidArgumentException(__('The value is too large.'));
                }
                $data['value_num'] = $n;
                break;
            case 'reset':
                if ($type !== 'days_since') {
                    $data['value_num'] = 0;
                    break;
                }
                $data['since_date'] = date('Y-m-d');
                break;
            case 'since':
                $errors = [];
                $d = BusinessApps::date(['d' => is_string($value) ? $value : ''], 'd', $errors);
                if ($type !== 'days_since' || $d === null || $d > date('Y-m-d')) {
                    throw new InvalidArgumentException(__('Enter a valid past date.'));
                }
                $data['since_date'] = $d;
                break;
            default:
                throw new InvalidArgumentException(__('Unknown action.'));
        }
        DB::update('kpi_tiles', $data, 'id = :id', ['id' => (int) $t['id']]);
        return array_replace($t, $data);
    }

    // ------------------------------------------------------------------ machine push tokens

    /** New push token for a tile (returned once; only its hash is stored). */
    public static function newToken(int $id): string
    {
        $token = 'kpi_' . random_token(24);
        DB::update('kpi_tiles', ['push_token_hash' => hash('sha256', $token), 'push_token_hint' => substr($token, -4)], 'id = :id', ['id' => $id]);
        return $token;
    }

    public static function revokeToken(int $id): void
    {
        DB::update('kpi_tiles', ['push_token_hash' => null, 'push_token_hint' => ''], 'id = :id', ['id' => $id]);
    }

    /** Tile of any hotel by its push token (null when unknown / malformed). */
    public static function byToken(string $token): ?array
    {
        if (!preg_match('/^kpi_[0-9a-f]{48}$/', $token)) {
            return null;
        }
        return DB::one('SELECT * FROM kpi_tiles WHERE push_token_hash = :h LIMIT 1', ['h' => hash('sha256', $token)]);
    }

    // ------------------------------------------------------------------ shifts

    /**
     * Shifts from text lines "Name | 06:00 | 14:00" (end before start = overnight).
     * @return array<int, array{name: string, start: string, end: string}>
     */
    public static function parseShifts(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $p = array_map('trim', explode('|', $line));
            if (count($p) < 3 || $p[0] === '') {
                continue;
            }
            $s = BusinessApps::time($p[1]);
            $e = BusinessApps::time($p[2]);
            if ($s === null || $e === null || $s === $e) {
                continue;
            }
            $out[] = ['name' => mb_substr($p[0], 0, 60), 'start' => $s, 'end' => $e];
        }
        return $out;
    }

    /** The shift running at $now (hotel time zone), or null. Pure. */
    public static function currentShift(array $shifts, int $now): ?array
    {
        $m = (int) date('G', $now) * 60 + (int) date('i', $now);
        foreach ($shifts as $s) {
            $a = BusinessApps::minutes($s['start']);
            $b = BusinessApps::minutes($s['end']);
            if ($a < $b ? ($m >= $a && $m < $b) : ($m >= $a || $m < $b)) {
                return $s;
            }
        }
        return null;
    }
}
