<?php
declare(strict_types=1);

/**
 * Birthday / anniversary wall (#29, display app "celebrations"). Tenant table `celebrations`
 * (migrations/021_widgets.sql), managed in admin/celebrations.php (incl. CSV import), shown by
 * core/Apps/CelebrationsApp.php.
 *
 *  - Date = month + day; the year is optional and only used for "turns 30" / "5 years" when the screen
 *    enables it. 29 February is celebrated on 28 February in non-leap years.
 *  - PRIVACY: only rows with consent = 1 (and active) are ever shown on TVs. Imported rows have no
 *    consent unless the importer confirms it for the whole file.
 */
final class Celebrations
{
    public const TYPES = ['birthday', 'anniversary', 'work_anniversary'];
    public const MAX_IMPORT = 1000;

    public const DEFAULTS = [
        'id' => 0, 'name' => '', 'type' => 'birthday', 'month' => 0, 'day' => 0, 'year' => null, 'group_label' => '',
        'photo_path' => null, 'thumb_path' => null, 'consent' => 0, 'is_active' => 1,
    ];

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'anniversary' => __('Wedding anniversary'),
            'work_anniversary' => __('Work anniversary'),
            default => __('Birthday'),
        };
    }

    public static function find(int $id): ?array
    {
        return Tenant::find('celebrations', $id);
    }

    public static function all(): array
    {
        return DB::all('SELECT * FROM celebrations WHERE hotel_id = :h ORDER BY month, day, name, id', ['h' => Tenant::id()]);
    }

    /**
     * Parse a date typed by people: "1990-10-08", "08/10/1990", "08-10-1990", "08.10.1990" (day first),
     * "08/10", "08-10" (no year) or "--10-08". Returns [month, day, year|null] or null when invalid.
     */
    public static function parseDate(string $v): ?array
    {
        $v = trim($v);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $v, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/', $v, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})$/', $v, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], null];
        } elseif (preg_match('/^--(\d{2})-(\d{2})$/', $v, $m)) {
            [$mo, $d, $y] = [(int) $m[1], (int) $m[2], null];
        } else {
            return null;
        }
        if ($y !== null && ($y < 1900 || $y > (int) date('Y'))) {
            return null;
        }
        return checkdate($mo, $d, $y ?? 2000) ? [$mo, $d, $y] : null;
    }

    /** Type from free text (birthday / anniversary / work anniversary, a few aliases); null when unknown. */
    public static function parseType(string $v): ?string
    {
        $v = strtolower(trim(str_replace(['-', ' '], '_', $v)));
        return match ($v) {
            '', 'birthday', 'bday', 'birth', 'janmdin' => 'birthday',
            'anniversary', 'wedding', 'wedding_anniversary', 'marriage' => 'anniversary',
            'work', 'work_anniversary', 'workanniversary', 'joining', 'service' => 'work_anniversary',
            default => null,
        };
    }

    /** @return array{0: array, 1: string[]} [row data, errors] */
    public static function validate(array $in): array
    {
        $errors = [];
        $name = BusinessApps::str($in, 'name', 400);
        if ($name === '') {
            $errors[] = __('The name is required.');
        } elseif (mb_strlen($name) > 120) {
            $errors[] = __('The name can have at most 120 characters.');
        }
        $type = is_string($in['type'] ?? null) && in_array($in['type'], self::TYPES, true) ? $in['type'] : 'birthday';
        $mo = is_numeric($in['month'] ?? null) ? (int) $in['month'] : 0;
        $d = is_numeric($in['day'] ?? null) ? (int) $in['day'] : 0;
        $yRaw = is_scalar($in['year'] ?? null) ? trim((string) $in['year']) : '';
        $y = null;
        if ($yRaw !== '') {
            if (!ctype_digit($yRaw) || (int) $yRaw < 1900 || (int) $yRaw > (int) date('Y')) {
                $errors[] = __('The year must be between 1900 and :y (or empty).', ['y' => date('Y')]);
            } else {
                $y = (int) $yRaw;
            }
        }
        if ($mo < 1 || $mo > 12 || $d < 1 || !checkdate($mo, $d, $y ?? 2000)) {
            $errors[] = __('Choose a valid day and month.');
        }
        return [[
            'name' => mb_substr($name, 0, 120),
            'type' => $type,
            'month' => max(0, min(12, $mo)),
            'day' => max(0, min(31, $d)),
            'year' => $y,
            'group_label' => BusinessApps::str($in, 'group_label', 80),
            'consent' => !empty($in['consent']) ? 1 : 0,
            'is_active' => !empty($in['is_active']) ? 1 : 0,
        ], $errors];
    }

    public static function save(?int $id, array $data): int
    {
        if ($id) {
            DB::update('celebrations', $data, 'id = :id', ['id' => $id]);
            return $id;
        }
        return DB::insert('celebrations', $data + ['created_by' => Auth::id(), 'created_at' => now()]);
    }

    public static function delete(int $id): void
    {
        $c = self::find($id);
        if (!$c) {
            return;
        }
        DB::delete('celebrations', 'id = :id', ['id' => $id]);
        Uploader::delete($c['photo_path'], $c['thumb_path']);
    }

    /**
     * CSV import (name,date,type,group; header row optional). $consent: the importer confirms that every
     * person agreed to be shown. Returns [added, errors (max 20 lines)].
     * @return array{0: int, 1: string[]}
     */
    public static function importCsv(string $csv, bool $consent): array
    {
        $errors = [];
        $rows = [];
        $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $lines = preg_split('/\R/', trim($csv)) ?: [];
        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $f = array_map('trim', str_getcsv($line, str_contains($line, ';') && !str_contains($line, ',') ? ';' : ',', '"', ''));
            if ($i === 0 && strtolower($f[0] ?? '') === 'name') {
                continue; // header
            }
            if (count($rows) >= self::MAX_IMPORT) {
                $errors[] = __('Only the first :n rows were imported.', ['n' => self::MAX_IMPORT]);
                break;
            }
            $name = mb_substr((string) preg_replace('/\s+/u', ' ', $f[0] ?? ''), 0, 120);
            $date = self::parseDate($f[1] ?? '');
            $type = self::parseType($f[2] ?? '');
            if ($name === '' || $date === null || $type === null) {
                if (count($errors) < 20) {
                    $errors[] = __('Line :n skipped: write "name,date,type,group", e.g. "Asha Patel,08/10/1990,birthday,Front office".', ['n' => $i + 1]);
                }
                continue;
            }
            $rows[] = ['name' => $name, 'month' => $date[0], 'day' => $date[1], 'year' => $date[2], 'type' => $type,
                'group_label' => mb_substr((string) preg_replace('/\s+/u', ' ', $f[3] ?? ''), 0, 80), 'consent' => $consent ? 1 : 0, 'is_active' => 1];
        }
        foreach ($rows as $r) {
            DB::insert('celebrations', $r + ['created_by' => Auth::id(), 'created_at' => now()]);
        }
        return [count($rows), $errors];
    }

    /** Date ('Y-m-d') of this person's day in $year (29 Feb → 28 Feb in non-leap years). */
    public static function occurrence(array $c, int $year): string
    {
        $m = (int) $c['month'];
        $d = (int) $c['day'];
        if ($m === 2 && $d === 29 && !checkdate(2, 29, $year)) {
            $d = 28;
        }
        return sprintf('%04d-%02d-%02d', $year, $m, $d);
    }

    /** Years completed on the occurrence (age, years married, years of service); null without a year. */
    public static function years(array $c, string $onDate): ?int
    {
        if (empty($c['year'])) {
            return null;
        }
        $n = (int) substr($onDate, 0, 4) - (int) $c['year'];
        return $n >= 0 ? $n : null;
    }

    /**
     * Celebrations shown on TVs from $today: ['today' => rows, 'upcoming' => rows with 'on' (Y-m-d) and 'in'
     * (days)], consent + active only, optional type / group filters.
     */
    public static function board(string $today, int $days = 7, array $types = [], string $group = '', int $limit = 30): array
    {
        $sql = 'SELECT * FROM celebrations WHERE hotel_id = :h AND is_active = 1 AND consent = 1';
        $p = ['h' => Tenant::id()];
        if ($group !== '') {
            $sql .= ' AND group_label = :g';
            $p['g'] = $group;
        }
        $rows = DB::all($sql . ' ORDER BY name, id', $p);
        $types = $types ?: self::TYPES;
        $t0 = new DateTimeImmutable($today . ' 00:00:00', new DateTimeZone('UTC'));
        $y = (int) substr($today, 0, 4);
        $out = ['today' => [], 'upcoming' => []];
        foreach ($rows as $r) {
            if (!in_array($r['type'], $types, true)) {
                continue;
            }
            foreach ([$y, $y + 1] as $yy) {
                $on = self::occurrence($r, $yy);
                $in = (int) round(((new DateTimeImmutable($on . ' 00:00:00', new DateTimeZone('UTC')))->getTimestamp() - $t0->getTimestamp()) / 86400);
                if ($in === 0) {
                    $out['today'][] = $r + ['on' => $on, 'in' => 0];
                    break;
                }
                if ($in > 0 && $in <= $days) {
                    $out['upcoming'][] = $r + ['on' => $on, 'in' => $in];
                    break;
                }
            }
        }
        usort($out['upcoming'], static fn ($a, $b) => [$a['in'], $a['name']] <=> [$b['in'], $b['name']]);
        $out['today'] = array_slice($out['today'], 0, $limit);
        $out['upcoming'] = array_slice($out['upcoming'], 0, $limit);
        return $out;
    }

    /** Photo URL (thumbnail unless $full), null without a photo. */
    public static function photoUrl(array $c, bool $full = false): ?string
    {
        if (empty($c['photo_path'])) {
            return null;
        }
        return media_url((string) ($full || empty($c['thumb_path']) ? $c['photo_path'] : $c['thumb_path']));
    }
}
