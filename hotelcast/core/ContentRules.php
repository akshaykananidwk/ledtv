<?php
declare(strict_types=1);

/**
 * Which content may be on air right now (2.4, docs/modules/scheduling.md):
 *
 *  - approval (#32): only content_items.approval_status = 'approved' plays (missing column = approved);
 *  - validity window (#33): valid_from <= now < valid_to (hotel local time, NULL = no limit);
 *  - dayparting (#34): a playlist item with daypart_from/daypart_to/daypart_days plays only inside
 *    that daily window (overnight like 22:00–02:00 allowed; after midnight it belongs to the day
 *    it started) and on those weekdays (1 = Mon … 7 = Sun).
 *
 * Used by ContentManager::playlistItems() (playlists, layout zones, previews) and
 * ContentResolver / Layouts for single items. All times use the hotel time zone (PHP default
 * time zone, set by Tenant::set()).
 */
final class ContentRules
{
    /** Test hook: fixed "now" (unix time) for every rule here; null = time(). */
    public static ?int $now = null;

    public static function now(): int
    {
        return self::$now ?? time();
    }

    /** Approved, active and inside its validity window. */
    public static function playable(array $item, ?int $ts = null): bool
    {
        return (int) ($item['is_active'] ?? 1) === 1
            && self::approved($item)
            && self::validity($item, $ts) === 'live';
    }

    public static function approved(array $item): bool
    {
        return ($item['approval_status'] ?? 'approved') === 'approved';
    }

    /** 'scheduled' (starts later) | 'live' | 'expired' for the item's valid_from / valid_to. */
    public static function validity(array $item, ?int $ts = null): string
    {
        $ts ??= self::now();
        $from = (string) ($item['valid_from'] ?? '');
        $to = (string) ($item['valid_to'] ?? '');
        if ($from !== '' && (int) strtotime($from) > $ts) {
            return 'scheduled';
        }
        if ($to !== '' && (int) strtotime($to) <= $ts) {
            return 'expired';
        }
        return 'live';
    }

    /** Is a playlist item's daypart (time window + weekdays) active at $ts? No daypart = always. */
    public static function daypartActive(array $pli, ?int $ts = null): bool
    {
        $from = (string) ($pli['daypart_from'] ?? '');
        $to = (string) ($pli['daypart_to'] ?? '');
        $days = (string) ($pli['daypart_days'] ?? '');
        if ($from === '' && $to === '' && $days === '') {
            return true;
        }
        // Same rules as time-window broadcasts (repeat days + daily range, overnight aware).
        return ContentResolver::windowActive([
            'daily_start' => $from !== '' && $to !== '' ? $from : null,
            'daily_end' => $from !== '' && $to !== '' ? $to : null,
            'repeat_days' => $days !== '' ? $days : null,
        ], $ts ?? self::now());
    }

    /** Playlist rows (ContentManager::playlistItems) that may play now. */
    public static function filterPlaylistRows(array $rows, ?int $ts = null): array
    {
        $ts ??= self::now();
        return array_values(array_filter($rows, static fn (array $r) => self::playable($r, $ts) && self::daypartActive($r, $ts)));
    }

    /**
     * Normalise daypart input of the playlist editor. Returns [from|null, to|null, days|null, error|null].
     * Both times or none; days 1..7; same from/to is refused.
     */
    public static function parseDaypart(array $row): array
    {
        $from = Broadcaster::parseTime((string) ($row['daypart_from'] ?? ''));
        $to = Broadcaster::parseTime((string) ($row['daypart_to'] ?? ''));
        $rawDays = $row['daypart_days'] ?? [];
        if (is_string($rawDays)) {
            $rawDays = explode(',', $rawDays);
        }
        $days = array_values(array_unique(array_filter(array_map('intval', (array) $rawDays), static fn ($d) => $d >= 1 && $d <= 7)));
        sort($days);
        $error = null;
        if (($from xor $to) || ($from && $from === $to)) {
            $error = __('Give both "from" and "until" times (different values) for the item time window.');
            $from = $to = null;
        }
        if (count($days) === 7) {
            $days = []; // every day = no day limit
        }
        return [$from, $to, $days ? implode(',', $days) : null, $error];
    }

    /** Short text for a daypart, e.g. "22:00–02:00 · Mon, Tue". '' when none. */
    public static function daypartLabel(array $pli): string
    {
        $parts = [];
        if (!empty($pli['daypart_from']) && !empty($pli['daypart_to'])) {
            $parts[] = substr((string) $pli['daypart_from'], 0, 5) . '–' . substr((string) $pli['daypart_to'], 0, 5);
        }
        if (!empty($pli['daypart_days'])) {
            $names = function_exists('day_names') ? day_names() : [];
            $parts[] = implode(', ', array_map(static fn ($d) => $names[(int) $d] ?? (string) $d, explode(',', (string) $pli['daypart_days'])));
        }
        return implode(' · ', $parts);
    }

    /** Parse a datetime-local / "Y-m-d H:i" form value into 'Y-m-d H:i:s' (hotel time) or null. */
    public static function parseDateTime(mixed $v): ?string
    {
        return is_string($v) ? Broadcaster::parseDateTime($v) : null;
    }

    /**
     * Validity input of the content form: [valid_from|null, valid_to|null, errors[]].
     */
    public static function validateWindow(array $in): array
    {
        $from = self::parseDateTime($in['valid_from'] ?? '');
        $to = self::parseDateTime($in['valid_to'] ?? '');
        $errors = [];
        if (trim((string) ($in['valid_from'] ?? '')) !== '' && !$from) {
            $errors[] = __('Invalid "show from" date.');
        }
        if (trim((string) ($in['valid_to'] ?? '')) !== '' && !$to) {
            $errors[] = __('Invalid "show until" date.');
        }
        if ($from && $to && strtotime($to) <= strtotime($from)) {
            $errors[] = __('"Show until" must be after "show from".');
        }
        return [$from, $to, $errors];
    }

    /** Badge HTML for the validity state (empty when the item has no window). */
    public static function validityBadge(array $item): string
    {
        if (empty($item['valid_from']) && empty($item['valid_to'])) {
            return '';
        }
        $state = self::validity($item);
        [$cls, $label, $tip] = match ($state) {
            'scheduled' => ['text-bg-info', __('Scheduled'), __('Starts :d', ['d' => date('d M Y H:i', (int) strtotime((string) $item['valid_from']))])],
            'expired' => ['text-bg-secondary', __('Expired'), __('Ended :d', ['d' => date('d M Y H:i', (int) strtotime((string) $item['valid_to']))])],
            default => ['text-bg-success', __('Live'), !empty($item['valid_to']) ? __('Until :d', ['d' => date('d M Y H:i', (int) strtotime((string) $item['valid_to']))]) : ''],
        };
        return '<span class="badge ' . $cls . '" title="' . e($tip) . '"><i class="bi bi-hourglass-split"></i> ' . e($label) . '</span>';
    }
}
