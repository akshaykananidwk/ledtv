<?php
declare(strict_types=1);

/**
 * Google reviews display (#30): average stars, total count and rotating review cards.
 *  - manual: reviews typed / pasted by the admin ("Author | rating | date | text" per line), optional
 *    overall rating and total;
 *  - auto: Google Places API Place Details (rating, user_ratings_total, reviews) via DataFeeds provider
 *    google_places — platform or hotel API key (encrypted), Place ID in this form, refreshed every 6–24 h.
 * Option to show only 4–5 star reviews. "Reviews from Google" attribution. The API key is never part of
 * the page or the data JSON (only parsed reviews are). See core/GoogleReviews.php.
 */
final class ReviewsApp extends WidgetApp
{
    public function key(): string
    {
        return 'reviews';
    }

    public function label(): string
    {
        return __('Google reviews');
    }

    public function description(): string
    {
        return __('Your Google rating, number of reviews and rotating guest reviews with stars — typed by you or loaded from Google automatically.');
    }

    public function icon(): string
    {
        return 'bi-star-half';
    }

    public function defaults(): array
    {
        return [
            'heading' => __('What our guests say'),
            'subtitle' => '',
            'mode' => 'manual',
            'place_id' => '',
            'reviews' => '',
            'manual_rating' => '',
            'manual_total' => '',
            'from_google' => true,
            'min_rating' => 4,
            'rotate_sec' => 10,
            'show_date' => true,
        ];
    }

    public function validate(array $in): array
    {
        $errors = [];
        $mode = self::choice($in, 'mode', ['manual', 'auto'], 'manual');
        $place = self::str($in, 'place_id', 300);
        if ($mode === 'auto' && !GoogleReviews::validPlaceId($place)) {
            $errors[] = __('Enter the Google Place ID of your business (starts with "ChIJ…"), or use manual mode.');
        } elseif ($place !== '' && !GoogleReviews::validPlaceId($place)) {
            $errors[] = __('The Place ID looks wrong.');
            $place = '';
        }
        $text = self::text($in, 'reviews', 20000);
        [$list, $lineErrors] = GoogleReviews::parseManual($text);
        array_push($errors, ...$lineErrors);
        if ($mode === 'manual' && !$list && !$lineErrors) {
            $errors[] = __('Add at least one review (one per line).');
        }
        $rating = self::str($in, 'manual_rating', 4);
        if ($rating !== '' && (!is_numeric($rating) || (float) $rating < 1 || (float) $rating > 5)) {
            $errors[] = __('The overall rating must be between 1 and 5.');
            $rating = '';
        }
        $total = self::str($in, 'manual_total', 9);
        if ($total !== '' && !ctype_digit($total)) {
            $errors[] = __('The number of reviews must be a whole number.');
            $total = '';
        }
        return [[
            'heading' => self::str($in, 'heading', 120),
            'subtitle' => self::str($in, 'subtitle', 190),
            'mode' => $mode,
            'place_id' => $place,
            'reviews' => $text,
            'manual_rating' => $rating === '' ? '' : (string) round((float) $rating, 1),
            'manual_total' => $total,
            'from_google' => self::bool($in, 'from_google'),
            'min_rating' => (int) self::choice($in, 'min_rating', ['1', '4', '5'], '4'),
            'rotate_sec' => self::int($in, 'rotate_sec', 4, 60, 10),
            'show_date' => self::bool($in, 'show_date'),
        ], $errors];
    }

    public function form(array $config): string
    {
        $key = DataFeeds::hasKey('google_places');
        return self::input('heading', __('Heading'), $config['heading'], 'text', ['maxlength' => 120])
            . self::input('subtitle', __('Sub-heading'), $config['subtitle'], 'text', ['maxlength' => 190])
            . self::select('mode', __('Reviews'), ['manual' => __('Typed by me'), 'auto' => __('Load from Google automatically')], (string) $config['mode'],
                $key ? __('A Google API key is available.') : __('Automatic mode needs a Google Places API key (Data feeds page or Platform settings).'))
            . self::input('place_id', __('Google Place ID'), $config['place_id'], 'text', ['maxlength' => 300, 'placeholder' => 'ChIJ…'], __('Find it with Google\'s "Place ID finder". Reviews refresh every 6–24 hours.'))
            . self::textarea('reviews', __('Reviews typed by you'), (string) $config['reviews'], 6,
                __('One per line: Author | rating 1-5 | date | text, e.g. "Ramesh P. | 5 | 2026-09-12 | Very clean and kind staff". The date may be empty.'), 'col-12', 20000)
            . self::input('manual_rating', __('Overall rating (optional)'), $config['manual_rating'], 'text', ['inputmode' => 'decimal', 'maxlength' => 4, 'placeholder' => '4.6'], __('Manual mode; empty = average of the reviews above.'), 'col-6 col-md-3')
            . self::input('manual_total', __('Total reviews (optional)'), $config['manual_total'], 'text', ['inputmode' => 'numeric', 'maxlength' => 9], '', 'col-6 col-md-3')
            . self::select('min_rating', __('Show reviews with'), ['1' => __('All ratings'), '4' => __('4 and 5 stars'), '5' => __('5 stars only')], (string) $config['min_rating'], '', 'col-6 col-md-3')
            . self::input('rotate_sec', __('Change every (seconds)'), $config['rotate_sec'], 'number', ['min' => 4, 'max' => 60], '', 'col-6 col-md-3')
            . self::checkbox('from_google', __('My typed reviews are from Google (show "Reviews from Google")'), (bool) $config['from_google'])
            . self::checkbox('show_date', __('Show review dates'), (bool) $config['show_date']);
    }

    public function refreshSec(array $config): int
    {
        return 900;
    }

    /** [reviews, rating, total, google attribution, feed state|null] for a config. */
    public static function source(array $config, string $lang): array
    {
        if ($config['mode'] === 'auto' && GoogleReviews::validPlaceId((string) $config['place_id'])) {
            $feed = DataFeeds::get('google_places', ['place_id' => (string) $config['place_id'], 'lang' => $lang], true);
            $d = $feed['data'] ?? null;
            if (is_array($d)) {
                return [(array) ($d['reviews'] ?? []), is_numeric($d['rating'] ?? null) ? (float) $d['rating'] : null, is_numeric($d['total'] ?? null) ? (int) $d['total'] : null, true, $feed];
            }
            [$list] = GoogleReviews::parseManual((string) $config['reviews']);
            return [$list, GoogleReviews::average($list), null, (bool) $config['from_google'], $feed];
        }
        [$list] = GoogleReviews::parseManual((string) $config['reviews']);
        $rating = $config['manual_rating'] !== '' ? (float) $config['manual_rating'] : GoogleReviews::average($list);
        $total = $config['manual_total'] !== '' ? (int) $config['manual_total'] : null;
        return [$list, $rating, $total, (bool) $config['from_google'], null];
    }

    protected function body(array $config, array $ctx): string
    {
        [$all, $rating, $total, $google, $feed] = self::source($config, (string) $ctx['lang']);
        $list = GoogleReviews::filter($all, (int) $config['min_rating']);
        if (!$list && $rating === null) {
            if ($feed !== null && $feed['status'] === 'no_key') {
                return self::empty($ctx['preview'] ? __('Automatic mode needs a Google Places API key (Data feeds page or Platform settings).') : __('Reviews will appear here soon.'));
            }
            return self::empty(__('Reviews will appear here soon.'));
        }
        $cards = '';
        foreach ($list as $r) {
            $text = (string) $r['text'];
            $date = $config['show_date'] ? ($r['time'] ? self::dateLabel(date('Y-m-d', (int) $r['time']), false, true) : (string) $r['relative']) : '';
            $cards .= '<div class="hc-slide rv-card hc-card">' . GoogleReviews::stars((float) $r['rating'])
                . '<blockquote class="rv-text' . (mb_strlen($text) > 280 ? ' rv-long' : '') . '">' . e(mb_strimwidth($text, 0, 600, '…')) . '</blockquote>'
                . '<div class="rv-author"><span class="rv-avatar">' . e(self::initials((string) $r['author'])) . '</span><b>' . e((string) $r['author']) . '</b>'
                . ($date !== '' ? '<span class="hc-muted"> · ' . e($date) . '</span>' : '') . '</div></div>';
        }
        $summary = '<div class="rv-summary hc-card">'
            . ($rating !== null ? '<div class="rv-avg">' . e(number_format($rating, 1)) . '</div>' . GoogleReviews::stars($rating) : '')
            . ($total !== null ? '<div class="rv-total">' . e(__(':n reviews', ['n' => DataFeeds::inr((float) $total, 0)])) . '</div>' : '')
            . ($google ? '<div class="rv-google"><span class="rv-g">G</span>' . e(__('Reviews from Google')) . '</div>' : '')
            . '</div>';
        $foot = $feed !== null ? self::foot($feed['as_of'], (bool) $feed['stale']) : '';
        return '<div class="rv-wrap">' . $summary . '<div class="rv-slides" data-rotate="' . (int) $config['rotate_sec'] . '">'
            . ($cards !== '' ? $cards : '<div class="hc-slide rv-card hc-card rv-none">' . e(__('Reviews will appear here soon.')) . '</div>') . '</div></div>' . $foot;
    }
}
