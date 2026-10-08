<?php
declare(strict_types=1);

/**
 * Class schedule (#6): gym / yoga / dance classes, school periods, coaching batches or doctor OPD
 * timings from admin/class_schedule.php (core/ClassSchedule.php). "Today" view with NOW / NEXT
 * highlighting (hotel time zone) and trainer photos, or a weekly grid. The "Trainer" / "Studio"
 * labels can be renamed (Doctor, Teacher, Room …). Live refresh every 30 s (NOW / NEXT move on).
 */
final class ClassScheduleApp extends DisplayApp
{
    public function key(): string
    {
        return 'class_schedule';
    }

    public function label(): string
    {
        return __('Class schedule');
    }

    public function description(): string
    {
        return __('Gym, yoga or school timetable with NOW and NEXT highlighting and trainer photos. Also for coaching classes and doctor OPD timings.');
    }

    public function icon(): string
    {
        return 'bi-calendar-week';
    }

    public function category(): string
    {
        return 'business';
    }

    public function adminPage(): ?string
    {
        return admin_url('class_schedule.php');
    }

    public function defaults(): array
    {
        return [
            'heading' => '',
            'subheading' => '',
            'view' => 'today',
            'trainer_label' => '',
            'room_label' => '',
            'show_photos' => true,
            'hide_ended' => true,
            'h24' => false,
            'show_clock' => true,
            'rows_per_page' => 6,
            'page_sec' => 10,
        ];
    }

    public function validate(array $in): array
    {
        return [[
            'heading' => self::str($in, 'heading', 120),
            'subheading' => self::str($in, 'subheading', 190),
            'view' => self::choice($in, 'view', ['today', 'week'], 'today'),
            'trainer_label' => self::str($in, 'trainer_label', 40),
            'room_label' => self::str($in, 'room_label', 40),
            'show_photos' => self::bool($in, 'show_photos'),
            'hide_ended' => self::bool($in, 'hide_ended'),
            'h24' => self::bool($in, 'h24'),
            'show_clock' => self::bool($in, 'show_clock'),
            'rows_per_page' => self::int($in, 'rows_per_page', 3, 10, 6),
            'page_sec' => self::int($in, 'page_sec', 3, 120, 10),
        ], []];
    }

    public function form(array $config): string
    {
        return self::input('heading', __('Heading'), $config['heading'], 'text', ['maxlength' => 120, 'placeholder' => __('Class schedule')])
            . self::input('subheading', __('Sub-heading'), $config['subheading'], 'text', ['maxlength' => 190])
            . self::select('view', __('Show as'), ['today' => __('Today (NOW / NEXT)'), 'week' => __('Whole week')], $config['view'], '', 'col-md-4')
            . self::input('trainer_label', __('Name for "Trainer"'), $config['trainer_label'], 'text', ['maxlength' => 40, 'placeholder' => __('Trainer')], __('e.g. Doctor, Teacher, Instructor'), 'col-md-4')
            . self::input('room_label', __('Name for "Studio"'), $config['room_label'], 'text', ['maxlength' => 40, 'placeholder' => __('Studio')], __('e.g. Hall, Cabin, Lab'), 'col-md-4')
            . self::input('rows_per_page', __('Rows per page (today view)'), $config['rows_per_page'], 'number', ['min' => 3, 'max' => 10], '', 'col-md-4')
            . self::input('page_sec', __('Change page every (seconds)'), $config['page_sec'], 'number', ['min' => 3, 'max' => 120], '', 'col-md-4')
            . self::checkbox('show_photos', __('Show trainer photos'), (bool) $config['show_photos'], 'col-md-4')
            . self::checkbox('hide_ended', __('Hide classes that are over'), (bool) $config['hide_ended'], 'col-md-4')
            . self::checkbox('h24', __('24-hour time'), (bool) $config['h24'], 'col-md-4')
            . self::checkbox('show_clock', __('Show clock'), (bool) $config['show_clock'], 'col-md-4');
    }

    /** Sample sessions for the preview of a hotel without classes (around the current time, every day). */
    private static function samples(int $now): array
    {
        $h = (int) date('G', $now);
        $t = static fn (int $hour, int $min = 0): string => sprintf('%02d:%02d:00', max(0, min(23, $hour)), $min);
        $base = ['id' => 0, 'days' => '1,2,3,4,5,6,7', 'photo_path' => null, 'thumb_path' => null, 'is_active' => 1];
        $first = max(0, min(20, $h - 1));
        return [
            ['name' => __('Morning yoga'), 'trainer' => 'Priya Shah', 'start_time' => $t($first), 'end_time' => $t($first + 1), 'room' => 'A', 'level' => 'all', 'color' => '#2E7D32'] + $base,
            ['name' => __('Zumba'), 'trainer' => 'Rahul Mehta', 'start_time' => $t($first + 1), 'end_time' => $t($first + 2), 'room' => 'B', 'level' => 'beginner', 'color' => '#AD1457'] + $base,
            ['name' => __('Strength training'), 'trainer' => 'Amit Patel', 'start_time' => $t($first + 2), 'end_time' => $t($first + 3), 'room' => 'A', 'level' => 'advanced', 'color' => '#C62828'] + $base,
            ['name' => __('Meditation'), 'trainer' => 'Neha Joshi', 'start_time' => $t($first + 3), 'end_time' => $t($first + 3, 45), 'room' => 'C', 'level' => '', 'color' => '#6A1B9A'] + $base,
        ];
    }

    private static function sessions(array $ctx): array
    {
        $rows = ClassSchedule::all(true);
        if (!$rows && !empty($ctx['preview'])) {
            $rows = self::samples((int) $ctx['now']);
        }
        return $rows;
    }

    private static function labels(array $config): array
    {
        return [
            $config['trainer_label'] !== '' ? $config['trainer_label'] : __('Trainer'),
            $config['room_label'] !== '' ? $config['room_label'] : __('Studio'),
        ];
    }

    /** Initials for a trainer without a photo ("PS" for "Priya Shah"). */
    private static function initials(string $name): string
    {
        $out = '';
        foreach (array_slice(preg_split('/\s+/u', trim($name)) ?: [], 0, 2) as $w) {
            $out .= mb_strtoupper(mb_substr($w, 0, 1));
        }
        return $out;
    }

    private static function stateBadge(string $state): string
    {
        return match ($state) {
            'now' => '<span class="cs-pill cs-pill-now">' . e(__('NOW')) . '</span>',
            'next' => '<span class="cs-pill cs-pill-next">' . e(__('NEXT')) . '</span>',
            default => '',
        };
    }

    private static function todayHtml(array $config, array $sessions, int $now): string
    {
        [$tl, $rl] = self::labels($config);
        $rows = ClassSchedule::annotate($sessions, $now);
        if ($config['hide_ended']) {
            $rows = array_values(array_filter($rows, static fn (array $s): bool => $s['state'] !== 'done'));
        }
        if (!$rows) {
            return '<div class="hc-empty">' . e($sessions ? __('No more classes today.') : __('Add classes on the Class schedule page.')) . '</div>';
        }
        $pages = '';
        foreach (array_chunk($rows, (int) $config['rows_per_page']) as $chunk) {
            $pages .= '<div class="hc-slide cs-page">';
            foreach ($chunk as $s) {
                $color = clean_color((string) $s['color'], '#1565C0');
                $pages .= '<div class="cs-row hc-card cs-' . e($s['state']) . '" style="border-left-color:' . e($color) . '">'
                    . '<div class="cs-time"><b>' . e(BusinessApps::timeLabel((string) $s['start_time'], (bool) $config['h24'])) . '</b><span>'
                    . e(BusinessApps::timeLabel((string) $s['end_time'], (bool) $config['h24'])) . '</span></div>';
                if ($config['show_photos'] && $s['trainer'] !== '') {
                    $photo = ClassSchedule::photoUrl($s);
                    $pages .= $photo ? '<img class="cs-photo" alt="" src="' . e($photo) . '">'
                        : '<div class="cs-photo cs-initials" style="background:' . e($color) . '">' . e(self::initials((string) $s['trainer'])) . '</div>';
                }
                $meta = [];
                if ($s['trainer'] !== '') {
                    $meta[] = '<span class="cs-k">' . e($tl) . ':</span> ' . e($s['trainer']);
                }
                if ($s['room'] !== '') {
                    $meta[] = '<span class="cs-k">' . e($rl) . ':</span> ' . e($s['room']);
                }
                $pages .= '<div class="cs-main"><div class="cs-name">' . e($s['name'])
                    . ($s['level'] !== '' && isset(ClassSchedule::LEVELS[$s['level']]) ? ' <span class="cs-level">' . e(__(ClassSchedule::LEVELS[$s['level']])) . '</span>' : '')
                    . '</div>' . ($meta ? '<div class="cs-meta">' . implode(' &nbsp;·&nbsp; ', $meta) . '</div>' : '') . '</div>'
                    . '<div class="cs-state">' . self::stateBadge((string) $s['state']) . '</div></div>';
            }
            $pages .= '</div>';
        }
        return '<div id="csPages" class="hc-slides cs-today">' . $pages . '</div>';
    }

    private static function weekHtml(array $config, array $sessions, int $now): string
    {
        if (!$sessions) {
            return '<div class="hc-empty">' . e(__('Add classes on the Class schedule page.')) . '</div>';
        }
        $todayDow = (int) date('N', $now);
        $states = [];
        foreach (ClassSchedule::annotate($sessions, $now) as $s) {
            $states[(int) $s['id'] . '|' . $s['start_time'] . '|' . $s['name']] = $s['state'];
        }
        $h = '<div class="cs-week">';
        for ($d = 1; $d <= 7; $d++) {
            $h .= '<div class="cs-day' . ($d === $todayDow ? ' is-today' : '') . '"><div class="cs-dayname">' . e(__(ClassSchedule::DAY_NAMES[$d])) . '</div>';
            foreach (ClassSchedule::forDay($sessions, $d) as $s) {
                $state = $d === $todayDow ? ($states[(int) $s['id'] . '|' . $s['start_time'] . '|' . $s['name']] ?? '') : '';
                $h .= '<div class="cs-item' . ($state !== '' ? ' cs-' . e($state) : '') . '" style="border-left-color:' . e(clean_color((string) $s['color'], '#1565C0')) . '">'
                    . '<div class="cs-item-time">' . e(BusinessApps::timeLabel((string) $s['start_time'], (bool) $config['h24'])) . ' ' . self::stateBadge($state) . '</div>'
                    . '<div class="cs-item-name">' . e($s['name']) . '</div>'
                    . ($s['trainer'] !== '' ? '<div class="cs-item-meta">' . e($s['trainer']) . '</div>' : '') . '</div>';
            }
            $h .= '</div>';
        }
        return $h . '</div>';
    }

    /** Body HTML below the header (also sent as live data). */
    public static function inner(array $config, array $ctx): string
    {
        $sessions = self::sessions($ctx);
        $now = (int) $ctx['now'];
        return $config['view'] === 'week' ? self::weekHtml($config, $sessions, $now) : self::todayHtml($config, $sessions, $now);
    }

    public function data(array $config, array $ctx): ?array
    {
        return ['html' => self::inner($config, $ctx), 'page_sec' => (int) $config['page_sec']];
    }

    public function refreshSec(array $config): int
    {
        return 30;
    }

    public function render(array $config, array $ctx): string
    {
        $heading = $config['heading'] !== '' ? $config['heading'] : __('Class schedule');
        $h = '<div class="hc-header">';
        if ($ctx['hotel']['logo_url']) {
            $h .= '<img class="hc-logo" alt="" src="' . e($ctx['hotel']['logo_url']) . '">';
        }
        $h .= '<div class="hc-title"><h1>' . e($heading) . '</h1>' . ($config['subheading'] !== '' ? '<div class="hc-subtitle">' . e($config['subheading']) . '</div>' : '') . '</div>';
        if ($config['show_clock']) {
            $h .= '<div><div class="hc-clock" data-hc-clock="' . ($config['h24'] ? '24' : '12') . '"></div><div class="hc-date" data-hc-date></div></div>';
        }
        return $h . '</div><div class="hc-body" id="csBody">' . self::inner($config, $ctx) . '</div>';
    }
}
