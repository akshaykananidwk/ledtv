<?php
declare(strict_types=1);

/**
 * Notice board (#3, display app "notice_board"): school / college / office notices.
 * Tenant table `notices` (migrations/014_display_apps.sql), managed in admin/notices.php, shown by
 * core/Apps/NoticeBoardApp.php. A notice is "current" when active and today is inside
 * starts_on … ends_on (both optional, inclusive). Order: priority desc, newest first.
 */
final class Notices
{
    public const CATEGORIES = ['exam' => 'Exam', 'holiday' => 'Holiday', 'result' => 'Result', 'event' => 'Event', 'general' => 'General'];
    /** Badge colours per category (background). */
    public const COLORS = ['exam' => '#C62828', 'holiday' => '#2E7D32', 'result' => '#1565C0', 'event' => '#6A1B9A', 'general' => '#546E7A'];
    public const MAX_BODY = 3000;

    public const DEFAULTS = [
        'id' => 0, 'title' => '', 'body' => '', 'category' => 'general', 'starts_on' => null, 'ends_on' => null,
        'priority' => 0, 'image_path' => null, 'thumb_path' => null, 'is_active' => 1,
    ];

    public static function find(int $id): ?array
    {
        return Tenant::find('notices', $id);
    }

    /** All notices of the hotel for the admin list (current first). */
    public static function all(): array
    {
        return DB::all('SELECT * FROM notices WHERE hotel_id = :h ORDER BY is_active DESC, priority DESC, COALESCE(starts_on, DATE(created_at)) DESC, id DESC', ['h' => Tenant::id()]);
    }

    /**
     * Notices shown now. $categories = subset of CATEGORIES keys ([] = all).
     * @return array<int, array>
     */
    public static function current(array $categories = [], int $limit = 50, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $sql = 'SELECT * FROM notices WHERE hotel_id = :h AND is_active = 1 AND (starts_on IS NULL OR starts_on <= :t1) AND (ends_on IS NULL OR ends_on >= :t2)';
        $p = ['h' => Tenant::id(), 't1' => $today, 't2' => $today];
        $categories = array_values(array_intersect($categories, array_keys(self::CATEGORIES)));
        if ($categories) {
            [$in, $cp] = DB::in($categories, 'cat');
            $sql .= " AND category IN $in";
            $p += $cp;
        }
        $sql .= ' ORDER BY priority DESC, COALESCE(starts_on, DATE(created_at)) DESC, id DESC LIMIT ' . max(1, min(200, $limit));
        return DB::all($sql, $p);
    }

    /** 'live' | 'scheduled' | 'expired' | 'off' */
    public static function state(array $n, ?string $today = null): string
    {
        $today ??= date('Y-m-d');
        if (!(int) $n['is_active']) {
            return 'off';
        }
        if (!empty($n['starts_on']) && $n['starts_on'] > $today) {
            return 'scheduled';
        }
        if (!empty($n['ends_on']) && $n['ends_on'] < $today) {
            return 'expired';
        }
        return 'live';
    }

    /**
     * Validate form input. @return array{0: array, 1: string[]} [row data, errors]
     */
    public static function validate(array $in): array
    {
        $errors = [];
        $title = trim(preg_replace('/\s+/', ' ', is_string($in['title'] ?? null) ? $in['title'] : '') ?? '');
        if ($title === '') {
            $errors[] = __('The notice title is required.');
        } elseif (mb_strlen($title) > 190) {
            $errors[] = __('The title can have at most 190 characters.');
        }
        $body = trim(str_replace("\r\n", "\n", is_string($in['body'] ?? null) ? $in['body'] : ''));
        if (mb_strlen($body) > self::MAX_BODY) {
            $errors[] = __('The notice text can have at most :n characters.', ['n' => self::MAX_BODY]);
        }
        $cat = is_string($in['category'] ?? null) && isset(self::CATEGORIES[$in['category']]) ? $in['category'] : 'general';
        $date = static function (string $key) use ($in, &$errors): ?string {
            $v = trim(is_string($in[$key] ?? null) ? $in[$key] : '');
            if ($v === '') {
                return null;
            }
            $dt = DateTime::createFromFormat('!Y-m-d', $v);
            if (!$dt || $dt->format('Y-m-d') !== $v) {
                $errors[] = __('Invalid date: :d', ['d' => $v]);
                return null;
            }
            return $v;
        };
        $starts = $date('starts_on');
        $ends = $date('ends_on');
        if ($starts && $ends && $ends < $starts) {
            $errors[] = __('The end date must be on or after the start date.');
        }
        $priority = is_numeric($in['priority'] ?? null) ? max(-100, min(100, (int) $in['priority'])) : 0;
        return [[
            'title' => mb_substr($title, 0, 190),
            'body' => mb_substr($body, 0, self::MAX_BODY),
            'category' => $cat,
            'starts_on' => $starts,
            'ends_on' => $ends,
            'priority' => $priority,
            'is_active' => !empty($in['is_active']) ? 1 : 0,
        ], $errors];
    }

    /** Insert (id null) or update a notice of the current hotel. Returns the id. */
    public static function save(?int $id, array $data): int
    {
        if ($id) {
            DB::update('notices', $data, 'id = :id', ['id' => $id]);
            return $id;
        }
        return DB::insert('notices', $data + ['created_by' => Auth::id(), 'created_at' => now()]);
    }

    public static function delete(int $id): void
    {
        $n = self::find($id);
        if (!$n) {
            return;
        }
        DB::delete('notices', 'id = :id', ['id' => $id]);
        Uploader::delete($n['image_path'], $n['thumb_path']);
    }

    public static function imageUrl(array $n): ?string
    {
        return !empty($n['image_path']) ? media_url((string) $n['image_path']) : null;
    }

    /** Translated category label. */
    public static function label(string $category): string
    {
        return __(self::CATEGORIES[$category] ?? 'General');
    }

    /** "7 Oct" / "7 Oct – 9 Oct 2026" for the TV (month names translated). */
    public static function dateLabel(array $n): string
    {
        $fmt = static function (string $d, bool $year): string {
            $ts = (int) strtotime($d);
            return date('j', $ts) . ' ' . __(date('F', $ts)) . ($year ? ' ' . date('Y', $ts) : '');
        };
        $s = $n['starts_on'] ?? null;
        $e = $n['ends_on'] ?? null;
        if ($s && $e && $s !== $e) {
            return $fmt($s, false) . ' – ' . $fmt($e, true);
        }
        if ($s || $e) {
            return $fmt((string) ($s ?: $e), true);
        }
        return $fmt(substr((string) ($n['created_at'] ?? date('Y-m-d')), 0, 10), true);
    }
}
