<?php
declare(strict_types=1);

/**
 * Notice board (#3): school / college / office notices from admin/notices.php (core/Notices.php),
 * rotating one by one or as a scrolling list, with category badges and dates; optional temple /
 * school timetable (an existing "timetable" content item) on the right. Live refresh every 60 s.
 */
final class NoticeBoardApp extends DisplayApp
{
    public function key(): string
    {
        return 'notice_board';
    }

    public function label(): string
    {
        return __('Notice board');
    }

    public function description(): string
    {
        return __('School, college or office notices with exam, holiday, result and event badges. Notices are managed on their own page and update live.');
    }

    public function icon(): string
    {
        return 'bi-pin-angle';
    }

    public function category(): string
    {
        return 'content';
    }

    public function adminPage(): ?string
    {
        return admin_url('notices.php');
    }

    public function defaults(): array
    {
        return [
            'heading' => '',
            'subheading' => '',
            'categories' => [],
            'layout' => 'rotate',
            'rotate_sec' => 10,
            'max' => 20,
            'show_dates' => true,
            'show_clock' => true,
            'timetable_id' => 0,
            'chime' => false,
        ];
    }

    public function validate(array $in): array
    {
        $errors = [];
        $tt = self::int($in, 'timetable_id', 0, PHP_INT_MAX, 0);
        if ($tt > 0) {
            $row = ContentManager::find($tt); // another hotel's id → 404 (Tenant::deny)
            if (!$row || $row['type'] !== 'timetable') {
                $errors[] = __('Choose a timetable from the content library.');
                $tt = 0;
            }
        }
        return [[
            'heading' => self::str($in, 'heading', 120),
            'subheading' => self::str($in, 'subheading', 190),
            'categories' => self::multi($in, 'categories', Notices::CATEGORIES),
            'layout' => self::choice($in, 'layout', ['rotate', 'list'], 'rotate'),
            'rotate_sec' => self::int($in, 'rotate_sec', 3, 120, 10),
            'max' => self::int($in, 'max', 1, 50, 20),
            'show_dates' => self::bool($in, 'show_dates'),
            'show_clock' => self::bool($in, 'show_clock'),
            'timetable_id' => $tt,
            'chime' => self::bool($in, 'chime'),
        ], $errors];
    }

    public function form(array $config): string
    {
        $cats = [];
        foreach (Notices::CATEGORIES as $k => $l) {
            $cats[$k] = __($l);
        }
        $tts = [0 => __('None')];
        foreach (DB::all("SELECT id, title FROM content_items WHERE hotel_id = :h AND type = 'timetable' ORDER BY title", ['h' => Tenant::id()]) as $r) {
            $tts[(int) $r['id']] = (string) $r['title'];
        }
        return self::input('heading', __('Heading'), $config['heading'], 'text', ['maxlength' => 120, 'placeholder' => __('Notice board')])
            . self::input('subheading', __('Sub-heading'), $config['subheading'], 'text', ['maxlength' => 190])
            . self::checkboxes('categories', __('Show these categories (none ticked = all)'), $cats, $config['categories'])
            . self::select('layout', __('Layout'), ['rotate' => __('One notice at a time (rotating)'), 'list' => __('List (scrolls when long)')], $config['layout'], '', 'col-md-4')
            . self::input('rotate_sec', __('Change every (seconds)'), $config['rotate_sec'], 'number', ['min' => 3, 'max' => 120], '', 'col-md-4')
            . self::input('max', __('Maximum notices'), $config['max'], 'number', ['min' => 1, 'max' => 50], '', 'col-md-4')
            . self::select('timetable_id', __('Timetable beside the notices (optional)'), $tts, (string) $config['timetable_id'], __('A timetable from the content library, e.g. the weekly class timetable.'))
            . self::checkbox('show_dates', __('Show dates'), (bool) $config['show_dates'], 'col-md-3')
            . self::checkbox('show_clock', __('Show clock'), (bool) $config['show_clock'], 'col-md-3')
            . self::checkbox('chime', __('Play a chime when a new notice appears'), !empty($config['chime']), 'col-md-6');
    }

    /** Notices as JSON-able rows for the TV. */
    private function notices(array $config, array $ctx): array
    {
        $rows = Notices::current((array) $config['categories'], (int) $config['max']);
        if (!$rows && !empty($ctx['preview'])) {
            // Admin preview without notices yet: sample notices so the layout can be judged.
            $rows = [
                ['id' => 0, 'title' => __('Annual exams start on Monday'), 'body' => __('Bring your hall ticket. Reporting time 9:30 AM.'), 'category' => 'exam', 'starts_on' => date('Y-m-d'), 'ends_on' => null, 'image_path' => null],
                ['id' => 0, 'title' => __('Holiday on Friday'), 'body' => '', 'category' => 'holiday', 'starts_on' => date('Y-m-d'), 'ends_on' => null, 'image_path' => null],
            ];
        }
        $out = [];
        foreach ($rows as $n) {
            $cat = isset(Notices::CATEGORIES[$n['category']]) ? $n['category'] : 'general';
            $out[] = [
                'id' => (int) $n['id'],
                'title' => (string) $n['title'],
                'body' => (string) ($n['body'] ?? ''),
                'category' => $cat,
                'label' => Notices::label($cat),
                'color' => Notices::COLORS[$cat],
                'date' => $config['show_dates'] ? Notices::dateLabel($n) : '',
                'image' => Notices::imageUrl($n),
            ];
        }
        return $out;
    }

    public function data(array $config, array $ctx): ?array
    {
        return [
            'notices' => $this->notices($config, $ctx),
            'layout' => $config['layout'],
            'rotate_sec' => (int) $config['rotate_sec'],
            'empty' => __('No notices right now.'),
            // 2.4.1: chime (built-in notice_chime.wav) when a notice that was not on the screen appears;
            // never in the admin preview. The Android WebView allows autoplay; browsers may block it.
            'chime_url' => !empty($config['chime']) && empty($ctx['preview']) ? Sounds::resolve(Sounds::NOTICE_CHIME)['url'] ?? null : null,
        ];
    }

    public function refreshSec(array $config): int
    {
        return 60;
    }

    /** Same markup as assets/display/apps/notice_board.js → card(). */
    public static function card(array $n, bool $slide): string
    {
        $h = '<div class="nb-card hc-card' . ($slide ? ' hc-slide' : '') . ($n['image'] ? ' has-img' : '') . '">'
            . '<div class="nb-meta"><span class="hc-badge" style="background:' . e($n['color']) . ';color:#fff">' . e($n['label']) . '</span>'
            . ($n['date'] !== '' ? '<span class="nb-date">' . e($n['date']) . '</span>' : '') . '</div>'
            . '<div class="nb-main">';
        if ($n['image']) {
            $h .= '<img class="nb-img" alt="" src="' . e($n['image']) . '">';
        }
        $h .= '<div class="nb-text"><div class="nb-title">' . e($n['title']) . '</div>';
        if ($n['body'] !== '') {
            $h .= '<div class="nb-body">' . nl2br(e($n['body']), false) . '</div>';
        }
        return $h . '</div></div></div>';
    }

    public function render(array $config, array $ctx): string
    {
        $notices = $this->notices($config, $ctx);
        $heading = $config['heading'] !== '' ? $config['heading'] : __('Notice board');
        $rotate = $config['layout'] === 'rotate';
        $h = '<div class="hc-header">';
        if ($ctx['hotel']['logo_url']) {
            $h .= '<img class="hc-logo" alt="" src="' . e($ctx['hotel']['logo_url']) . '">';
        }
        $h .= '<div class="hc-title"><h1>' . e($heading) . '</h1>' . ($config['subheading'] !== '' ? '<div class="hc-subtitle">' . e($config['subheading']) . '</div>' : '') . '</div>';
        if ($config['show_clock']) {
            $h .= '<div><div class="hc-clock" data-hc-clock="12"></div><div class="hc-date" data-hc-date></div></div>';
        }
        $h .= '</div><div class="hc-body nb-wrap"><div class="nb-notices">';
        $list = '';
        foreach ($notices as $n) {
            $list .= self::card($n, $rotate);
        }
        $h .= '<div id="nbList" class="' . ($rotate ? 'hc-slides nb-rotate' : 'nb-list') . '" data-rotate="' . (int) $config['rotate_sec'] . '">'
            . ($list !== '' ? $list : '<div class="hc-empty">' . e(__('No notices right now.')) . '</div>') . '</div></div>';
        $tt = (int) $config['timetable_id'] > 0 ? ContentManager::findOwn((int) $config['timetable_id']) : null;
        if ($tt && $tt['type'] === 'timetable') {
            $h .= '<div class="nb-side"><iframe class="nb-tt" title="' . e($tt['title']) . '" srcdoc="' . e(ContentManager::renderTimetable($tt)) . '"></iframe></div>';
        }
        return $h . '</div>';
    }
}
