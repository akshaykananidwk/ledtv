<?php
declare(strict_types=1);

/**
 * Countdown (#17): days / hours / minutes / seconds to a date and time (hotel time zone), ticking
 * on the TV with the server clock (assets/display/apps/countdown.js), then a message after zero.
 * Optional background image from the content library.
 */
final class CountdownApp extends DisplayApp
{
    public function key(): string
    {
        return 'countdown';
    }

    public function label(): string
    {
        return __('Countdown');
    }

    public function description(): string
    {
        return __('Count down to an opening, festival, sale or event: days, hours, minutes and seconds, then your message.');
    }

    public function icon(): string
    {
        return 'bi-hourglass-split';
    }

    public function category(): string
    {
        return 'widget';
    }

    public function defaults(): array
    {
        return [
            'title' => __('Grand celebration'),
            'subtitle' => '',
            'target' => date('Y-m-d H:i:00', (int) strtotime('+7 days 10:00')),
            'done_message' => __('The wait is over!'),
            'show_seconds' => true,
            'bg_image' => 0,
        ];
    }

    public function validate(array $in): array
    {
        $errors = [];
        $title = self::str($in, 'title', 120);
        if ($title === '') {
            $errors[] = __('Enter a title for the countdown.');
        }
        $target = self::datetime($in, 'target');
        if ($target === null) {
            $errors[] = __('Enter the date and time to count down to.');
        }
        $img = self::int($in, 'bg_image', 0, PHP_INT_MAX, 0);
        if ($img > 0 && self::imageUrl($img) === null) {
            $img = 0;
        }
        return [[
            'title' => $title,
            'subtitle' => self::str($in, 'subtitle', 190),
            'target' => $target ?? date('Y-m-d H:i:00', (int) strtotime('+7 days 10:00')),
            'done_message' => self::str($in, 'done_message', 190),
            'show_seconds' => self::bool($in, 'show_seconds'),
            'bg_image' => $img,
        ], $errors];
    }

    public function form(array $config): string
    {
        $ts = strtotime((string) $config['target']) ?: time();
        return self::input('title', __('Title'), $config['title'], 'text', ['maxlength' => 120, 'required' => true])
            . self::input('subtitle', __('Sub-heading'), $config['subtitle'], 'text', ['maxlength' => 190])
            . self::input('target', __('Count down to'), date('Y-m-d\TH:i', $ts), 'datetime-local', ['required' => true], __('Date and time in your time zone.'))
            . self::input('done_message', __('Message after zero'), $config['done_message'], 'text', ['maxlength' => 190])
            . self::imagePicker('bg_image', __('Background image (optional)'), (int) $config['bg_image'], __('An image from the content library.'))
            . self::checkbox('show_seconds', __('Show seconds'), (bool) $config['show_seconds']);
    }

    /** [days, hours, minutes, seconds] left at $now (all 0 after the target). */
    public static function parts(int $target, int $now): array
    {
        $left = max(0, $target - $now);
        return [intdiv($left, 86400), intdiv($left % 86400, 3600), intdiv($left % 3600, 60), $left % 60];
    }

    public function render(array $config, array $ctx): string
    {
        $target = (int) (strtotime((string) $config['target']) ?: time());
        [$dd, $hh, $mm, $ss] = self::parts($target, (int) $ctx['now']);
        $done = $target <= (int) $ctx['now'];
        $bg = self::imageUrl((int) $config['bg_image']);
        $units = [['d', $dd, __('Days')], ['h', $hh, __('Hours')], ['m', $mm, __('Minutes')]];
        if ($config['show_seconds']) {
            $units[] = ['s', $ss, __('Seconds')];
        }
        $boxes = '';
        foreach ($units as [$k, $v, $label]) {
            $boxes .= '<div class="cd-box hc-card"><div class="cd-num" data-cd="' . $k . '">' . ($k === 'd' ? $v : sprintf('%02d', $v)) . '</div><div class="cd-label">' . e($label) . '</div></div>';
        }
        return ($bg ? '<div class="cd-bg" style="background-image:url(\'' . e(str_replace(["'", '\\', "\n", '(', ')'], ['%27', '%5C', '', '%28', '%29'], $bg)) . '\')"></div>' : '')
            . '<div class="hc-center cd-wrap" id="cdRoot" data-target="' . ($target * 1000) . '">'
            . '<div class="cd-inner"><h1 class="cd-title">' . e($config['title']) . '</h1>'
            . ($config['subtitle'] !== '' ? '<div class="hc-subtitle cd-sub">' . e($config['subtitle']) . '</div>' : '')
            . '<div class="cd-boxes"' . ($done ? ' style="display:none"' : '') . '>' . $boxes . '</div>'
            . '<div class="cd-done"' . ($done ? '' : ' style="display:none"') . '>' . e($config['done_message']) . '</div>'
            . '<div class="cd-when hc-muted">' . e(date('j', $target) . ' ' . __(date('F', $target)) . ' ' . date('Y', $target) . ', ' . date('g:i', $target) . ' ' . __(date('A', $target))) . '</div>'
            . '</div></div>';
    }
}
