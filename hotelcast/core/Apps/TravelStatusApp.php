<?php
declare(strict_types=1);

/**
 * Train / flight status (#25): status board of the flights and trains guests care about — scheduled
 * and expected time, status, gate / platform.
 * Flights: aviationstack (API key in Platform settings → Data feeds or the hotel's own key), one feed
 * per flight number, refreshed within the plan's limits. Trains: manual rows only (Indian Railways has
 * no official public API; see docs/modules/data_feeds.md). Manual rows work for flights too.
 */
final class TravelStatusApp extends DataFeedApp
{
    private const MAX_FLIGHTS = 8;
    private const MAX_ROWS = 20;

    public function key(): string
    {
        return 'travel_status';
    }

    public function label(): string
    {
        return __('Train & flight status');
    }

    public function description(): string
    {
        return __('Status board for flights and trains: scheduled and expected time, status, gate or platform.');
    }

    public function icon(): string
    {
        return 'bi-airplane';
    }

    public function defaults(): array
    {
        return [
            'heading' => __('Flight & train status'),
            'subtitle' => '',
            'flights' => '',
            'manual' => '',
        ];
    }

    /** Flight numbers (one per line or comma separated) → normalised list. */
    public static function parseFlights(string $text, array &$errors = []): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', strtoupper($text)) ?: [] as $f) {
            if ($f === '') {
                continue;
            }
            $p = DataFeeds::cleanParams('aviationstack', ['flight' => $f]);
            if ($p === null) {
                $errors[] = __('":f" is not a flight number (e.g. AI101, 6E2134).', ['f' => mb_substr($f, 0, 20)]);
                continue;
            }
            $out[$p['flight']] = $p['flight'];
        }
        if (count($out) > self::MAX_FLIGHTS) {
            $errors[] = __('At most :n flights.', ['n' => self::MAX_FLIGHTS]);
        }
        return array_slice(array_values($out), 0, self::MAX_FLIGHTS);
    }

    /**
     * Manual lines "flight|train | number | route | scheduled | expected | status | gate / platform" →
     * rows. Errors for lines with fewer than 3 fields.
     */
    public static function parseManual(string $text, array &$errors = []): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $p = array_map(static fn ($x) => mb_substr(trim($x), 0, 60), explode('|', $line));
            if (count($p) < 3) {
                $errors[] = __('Line :n: enter "train | 12902 | Ahmedabad → Mumbai | 21:40 | 21:55 | Delayed | PF 3".', ['n' => $i + 1]);
                continue;
            }
            $type = in_array(strtolower($p[0]), ['train', 'rail', 'ટ્રેન', 'ट्रेन'], true) ? 'train' : 'flight';
            $out[] = ['type' => $type, 'number' => $p[1], 'route' => $p[2], 'scheduled' => $p[3] ?? '', 'expected' => $p[4] ?? '', 'status' => $p[5] ?? '', 'gate' => $p[6] ?? '', 'cls' => self::statusClass($p[5] ?? '')];
            if (count($out) >= self::MAX_ROWS) {
                break;
            }
        }
        return $out;
    }

    public function validate(array $in): array
    {
        $errors = [];
        $flights = self::text($in, 'flights', 400);
        self::parseFlights($flights, $errors);
        $manual = self::text($in, 'manual', 4000);
        self::parseManual($manual, $errors);
        return [[
            'heading' => self::str($in, 'heading', 120),
            'subtitle' => self::str($in, 'subtitle', 190),
            'flights' => $flights,
            'manual' => $manual,
        ], $errors];
    }

    public function form(array $config): string
    {
        $note = DataFeeds::hasKey('aviationstack') ? __('Flight status is updated automatically.') : __('No flight data key yet: use manual rows, or ask your platform admin to add an aviationstack key.');
        return self::input('heading', __('Heading'), $config['heading'], 'text', ['maxlength' => 120])
            . self::input('subtitle', __('Sub-heading'), $config['subtitle'], 'text', ['maxlength' => 190])
            . self::textarea('flights', __('Flights to track'), (string) $config['flights'], 2, __('Flight numbers, one per line or separated by commas, e.g. AI101, 6E2134.') . ' ' . $note, 'col-12', 400)
            . self::textarea('manual', __('Manual rows (trains and flights)'), (string) $config['manual'], 5, __('One per line: train | 12902 | Ahmedabad → Mumbai | 21:40 | 21:55 | Delayed | PF 3'), 'col-12', 4000);
    }

    public function refreshSec(array $config): int
    {
        return 120;
    }

    private static function statusClass(string $status): string
    {
        $s = strtolower($status);
        return match (true) {
            (bool) preg_match('/cancel|incident|divert|रद्द|રદ/u', $s) => 'tv-bad',
            (bool) preg_match('/delay|late|देर|મોડ/u', $s) => 'tv-warn',
            (bool) preg_match('/land|arriv|on.?time|depart|active|समय|સમય/u', $s) => 'tv-ok',
            default => '',
        };
    }

    /** Translated label of an aviationstack status. */
    public static function statusLabel(string $s): string
    {
        return match ($s) {
            'scheduled' => __('Scheduled'),
            'active' => __('In the air'),
            'landed' => __('Landed'),
            'cancelled' => __('Cancelled'),
            'incident' => __('Incident'),
            'diverted' => __('Diverted'),
            'delayed' => __('Delayed'),
            default => ucfirst($s),
        };
    }

    /** Rows of the board (flights from the feed, then manual rows) + oldest feed state. */
    public static function rows(array $config, bool $inline = true): array
    {
        $rows = [];
        $asOf = null;
        $stale = false;
        $hasKey = DataFeeds::hasKey('aviationstack');
        foreach (self::parseFlights((string) $config['flights']) as $f) {
            $row = ['type' => 'flight', 'number' => $f, 'route' => '', 'scheduled' => '', 'expected' => '', 'status' => __('Not available'), 'gate' => '', 'cls' => ''];
            if ($hasKey) {
                $feed = DataFeeds::get('aviationstack', ['flight' => $f], $inline);
                $d = $feed['data'];
                if (is_array($d) && !empty($d['found'])) {
                    $time = static fn (?int $t): string => $t ? date('H:i', $t) : '';
                    $exp = $d['dep']['actual'] ?? $d['dep']['estimated'] ?? null;
                    $status = (string) $d['status'];
                    if ($status === 'scheduled' && ($d['dep']['delay'] ?? 0) >= 15) {
                        $status = 'delayed';
                    }
                    $row = [
                        'type' => 'flight', 'number' => $f,
                        'route' => trim(($d['dep']['iata'] ?: $d['dep']['airport']) . ' → ' . ($d['arr']['iata'] ?: $d['arr']['airport']), ' →'),
                        'scheduled' => $time($d['dep']['scheduled']), 'expected' => $time($exp),
                        'status' => self::statusLabel($status),
                        'gate' => trim(($d['dep']['terminal'] !== '' ? 'T' . $d['dep']['terminal'] . ' ' : '') . ($d['dep']['gate'] !== '' ? __('Gate') . ' ' . $d['dep']['gate'] : '')),
                        'cls' => self::statusClass($status),
                    ];
                } elseif (is_array($d)) {
                    $row['status'] = __('No flight today');
                } else {
                    $row['status'] = __('Waiting for data…');
                }
                if ($feed['as_of'] !== null) {
                    $asOf = $asOf === null ? $feed['as_of'] : min($asOf, $feed['as_of']);
                }
                $stale = $stale || $feed['stale'];
            }
            $rows[] = $row;
        }
        return [array_merge($rows, self::parseManual((string) $config['manual'])), $asOf, $stale];
    }

    protected function body(array $config, array $ctx): string
    {
        [$rows, $asOf, $stale] = self::rows($config);
        if (!$rows) {
            return self::empty(__('Add flight numbers or manual rows to show their status here.'));
        }
        $html = '<div class="tv-row tv-head"><div>' . e(__('Flight / train')) . '</div><div>' . e(__('Route')) . '</div><div>' . e(__('Scheduled')) . '</div><div>'
            . e(__('Expected')) . '</div><div>' . e(__('Status')) . '</div><div>' . e(__('Gate / platform')) . '</div></div>';
        foreach ($rows as $r) {
            $html .= '<div class="tv-row hc-card"><div class="tv-num"><span class="tv-icon">' . ($r['type'] === 'train' ? '🚆' : '✈') . '</span> ' . e($r['number']) . '</div>'
                . '<div>' . e($r['route']) . '</div><div>' . e($r['scheduled']) . '</div><div>' . e($r['expected']) . '</div>'
                . '<div class="tv-status ' . e($r['cls']) . '">' . e($r['status']) . '</div><div>' . e($r['gate']) . '</div></div>';
        }
        return '<div class="tv-board">' . $html . '</div>' . self::foot($asOf, $stale, '', $asOf !== null ? 'aviationstack' : '');
    }
}
