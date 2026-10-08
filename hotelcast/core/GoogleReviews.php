<?php
declare(strict_types=1);

/**
 * Google reviews display (#30): reviews typed / pasted by the admin (manual mode) or Google Places API
 * "Place Details" (fields rating, user_ratings_total, reviews) through DataFeeds provider google_places
 * (fixed host maps.googleapis.com, platform or hotel API key stored encrypted, TTL ≥ 6 h).
 * The API key is only used server-side to build the request; the TV page and the data JSON contain the
 * parsed reviews only (author, rating, text, time) — never the key or the request URL.
 */
final class GoogleReviews
{
    /** Place ID as issued by Google ("ChIJ…"), letters / digits / _ / -. */
    public const PLACE_ID_RE = '/^[A-Za-z0-9_-]{10,300}$/';

    public static function validPlaceId(string $id): bool
    {
        return (bool) preg_match(self::PLACE_ID_RE, $id);
    }

    /**
     * Place Details answer: {"status":"OK","result":{"name","rating","user_ratings_total","reviews":[{author_name,
     * rating, text, time, relative_time_description, language}]}} / {"status":"REQUEST_DENIED","error_message"}.
     */
    public static function parse(array $r): array
    {
        [$status, $d] = $r;
        $st = (string) ($d['status'] ?? '');
        if ($status !== 200 || $st !== 'OK' || !is_array($d['result'] ?? null)) {
            $m = is_scalar($d['error_message'] ?? null) && trim((string) $d['error_message']) !== '' ? (string) $d['error_message'] : ($st !== '' ? $st : 'Error (HTTP ' . $status . ')');
            $m = mb_substr(trim(strip_tags($m)), 0, 200);
            if ($st !== '' && !str_contains($m, $st)) {
                $m = $st . ': ' . $m;
            }
            throw new DataFeedError($m, $status === 429 || $st === 'OVER_QUERY_LIMIT' || (bool) preg_match('/limit|quota|too many|exceed/i', $m));
        }
        $res = $d['result'];
        $reviews = [];
        foreach (array_slice((array) ($res['reviews'] ?? []), 0, 10) as $rv) {
            if (!is_array($rv) || !is_numeric($rv['rating'] ?? null)) {
                continue;
            }
            $reviews[] = [
                'author' => mb_substr(trim((string) ($rv['author_name'] ?? '')), 0, 80),
                'rating' => max(1, min(5, (int) round((float) $rv['rating']))),
                'text' => mb_substr(trim((string) ($rv['text'] ?? '')), 0, 1200),
                'time' => is_numeric($rv['time'] ?? null) ? (int) $rv['time'] : null,
                'relative' => mb_substr((string) ($rv['relative_time_description'] ?? ''), 0, 40),
            ];
        }
        return [
            'name' => mb_substr((string) ($res['name'] ?? ''), 0, 120),
            'rating' => is_numeric($res['rating'] ?? null) ? round((float) $res['rating'], 1) : null,
            'total' => is_numeric($res['user_ratings_total'] ?? null) ? (int) $res['user_ratings_total'] : null,
            'reviews' => $reviews,
        ];
    }

    /**
     * Manual reviews, one per line: "Author | rating 1-5 | date (optional) | text".
     * @return array{0: list<array>, 1: string[]} [reviews, errors]
     */
    public static function parseManual(string $text): array
    {
        $out = [];
        $errors = [];
        foreach (preg_split('/\R/', $text) ?: [] as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $p = array_map('trim', explode('|', $line, 4));
            $rating = isset($p[1]) && preg_match('/^[1-5]$/', $p[1]) ? (int) $p[1] : null;
            $date = $p[2] ?? '';
            $ts = $date !== '' ? strtotime($date) : null;
            if ($p[0] === '' || $rating === null || count($p) < 4 || trim($p[3]) === '' || ($date !== '' && $ts === false)) {
                $errors[] = __('Review line :n: write "Author | rating 1-5 | date | text", e.g. "Ramesh P. | 5 | 2026-09-12 | Very clean and friendly".', ['n' => $i + 1]);
                continue;
            }
            $out[] = ['author' => mb_substr($p[0], 0, 80), 'rating' => $rating, 'text' => mb_substr($p[3], 0, 1200), 'time' => $ts ?: null, 'relative' => ''];
            if (count($out) >= 50) {
                break;
            }
        }
        return [$out, $errors];
    }

    /** Reviews with at least $min stars and some text. */
    public static function filter(array $reviews, int $min): array
    {
        return array_values(array_filter($reviews, static fn ($r) => is_array($r) && (int) ($r['rating'] ?? 0) >= $min && trim((string) ($r['text'] ?? '')) !== ''));
    }

    /** Average of the reviews' ratings (1 decimal), null when there are none. */
    public static function average(array $reviews): ?float
    {
        $n = count($reviews);
        return $n ? round(array_sum(array_map(static fn ($r) => (int) $r['rating'], $reviews)) / $n, 1) : null;
    }

    /** "★★★★☆" HTML (full / empty stars; rating rounded to the nearest half shows a half star). */
    public static function stars(float $rating): string
    {
        $h = '<span class="rv-stars" aria-label="' . e(number_format($rating, 1)) . '">';
        $r = round($rating * 2) / 2;
        for ($i = 1; $i <= 5; $i++) {
            $cls = $r >= $i ? 'on' : ($r >= $i - 0.5 ? 'half' : 'off');
            $h .= '<span class="rv-star rv-' . $cls . '">★</span>';
        }
        return $h . '</span>';
    }
}
