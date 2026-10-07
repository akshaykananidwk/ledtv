<?php
declare(strict_types=1);

/**
 * Cricket live score (#23): scorecard of one match — teams, runs / wickets, overs, required run rate and
 * the status line. Source: CricketData.org (CricAPI) "currentMatches" (API key in Platform settings →
 * Data feeds or the hotel's own key; one shared request for all TVs, refreshed within the daily limit),
 * match chosen as "first live match", "first match of team X" or a fixed match id. Manual score entry
 * as a fallback (no key needed).
 */
final class CricketApp extends DataFeedApp
{
    public const MODES = ['live', 'team', 'match', 'manual'];
    private const OVERS = ['t20' => 20, 'odi' => 50];

    public function key(): string
    {
        return 'cricket';
    }

    public function label(): string
    {
        return __('Cricket live score');
    }

    public function description(): string
    {
        return __('Live score of a cricket match: teams, runs, wickets, overs and required run rate. Automatic or typed by you.');
    }

    public function icon(): string
    {
        return 'bi-trophy';
    }

    public function defaults(): array
    {
        return [
            'heading' => __('Live cricket'),
            'subtitle' => '',
            'mode' => 'live',
            'team' => 'India',
            'match_id' => '',
            'm_team1' => '', 'm_score1' => '', 'm_team2' => '', 'm_score2' => '', 'm_status' => '',
        ];
    }

    public function validate(array $in): array
    {
        $errors = [];
        $c = [
            'heading' => self::str($in, 'heading', 120),
            'subtitle' => self::str($in, 'subtitle', 190),
            'mode' => self::choice($in, 'mode', self::MODES, 'live'),
            'team' => self::str($in, 'team', 60),
            'match_id' => self::str($in, 'match_id', 64),
            'm_team1' => self::str($in, 'm_team1', 60),
            'm_score1' => self::str($in, 'm_score1', 40),
            'm_team2' => self::str($in, 'm_team2', 60),
            'm_score2' => self::str($in, 'm_score2', 40),
            'm_status' => self::str($in, 'm_status', 160),
        ];
        if ($c['match_id'] !== '' && !preg_match('/^[A-Za-z0-9-]{1,64}$/', $c['match_id'])) {
            $errors[] = __('The match id is not valid.');
            $c['match_id'] = '';
        }
        if ($c['mode'] === 'team' && $c['team'] === '') {
            $errors[] = __('Enter the team to follow.');
        }
        if ($c['mode'] === 'match' && $c['match_id'] === '') {
            $errors[] = __('Choose a match.');
        }
        if ($c['mode'] === 'manual' && $c['m_team1'] === '' && $c['m_team2'] === '') {
            $errors[] = __('Enter the teams and the score.');
        }
        return [$c, $errors];
    }

    public function form(array $config): string
    {
        $matches = DataFeeds::hasKey('cricapi') ? (DataFeeds::get('cricapi', [], true)['data']['matches'] ?? []) : [];
        $opts = ['' => __('Choose a match')];
        foreach ($matches as $m) {
            $opts[(string) $m['id']] = $m['name'] . ($m['started'] && !$m['ended'] ? ' · ' . __('LIVE') : '');
        }
        if ($config['match_id'] !== '' && !isset($opts[$config['match_id']])) {
            $opts[$config['match_id']] = $config['match_id'];
        }
        $note = DataFeeds::hasKey('cricapi') ? '' : __('No cricket data key yet: use the manual score or ask your platform admin to add a CricAPI key.');
        return self::input('heading', __('Heading'), $config['heading'], 'text', ['maxlength' => 120])
            . self::input('subtitle', __('Sub-heading'), $config['subtitle'], 'text', ['maxlength' => 190])
            . self::select('mode', __('Which match'), [
                'live' => __('Automatic: first live match'),
                'team' => __('Automatic: first live match of a team'),
                'match' => __('A chosen match'),
                'manual' => __('Manual score'),
            ], (string) $config['mode'], $note)
            . self::input('team', __('Team'), $config['team'], 'text', ['maxlength' => 60], __('e.g. India, Gujarat Titans'))
            . self::select('match_id', __('Match'), $opts, (string) $config['match_id'], '', 'col-12')
            . '<div class="col-12"><div class="form-label fw-semibold mb-0">' . e(__('Manual score')) . '</div></div>'
            . self::input('m_team1', __('Team 1'), $config['m_team1'], 'text', ['maxlength' => 60])
            . self::input('m_score1', __('Score 1'), $config['m_score1'], 'text', ['maxlength' => 40, 'placeholder' => '245/6 (45.2)'])
            . self::input('m_team2', __('Team 2'), $config['m_team2'], 'text', ['maxlength' => 60])
            . self::input('m_score2', __('Score 2'), $config['m_score2'], 'text', ['maxlength' => 40, 'placeholder' => '120/3 (20)'])
            . self::input('m_status', __('Status line'), $config['m_status'], 'text', ['maxlength' => 160], '', 'col-12');
    }

    public function refreshSec(array $config): int
    {
        return $config['mode'] === 'manual' ? 60 : 30;
    }

    /** The match to show from a normalised currentMatches list (null = none). */
    public static function pick(array $matches, array $config): ?array
    {
        $live = static fn (array $m): bool => $m['started'] && !$m['ended'];
        if ($config['mode'] === 'match') {
            foreach ($matches as $m) {
                if ((string) $m['id'] === (string) $config['match_id']) {
                    return $m;
                }
            }
            return null;
        }
        if ($config['mode'] === 'team') {
            $team = mb_strtolower(trim((string) $config['team']));
            $matches = array_values(array_filter($matches, static function (array $m) use ($team): bool {
                foreach (array_merge($m['teams'], $m['short']) as $t) {
                    if ($team !== '' && mb_strtolower((string) $t) === $team) {
                        return true;
                    }
                }
                return false;
            }));
        }
        foreach ($matches as $m) {
            if ($live($m)) {
                return $m;
            }
        }
        foreach ($matches as $m) {
            if (!$m['ended']) {
                return $m;
            }
        }
        return $matches[0] ?? null;
    }

    /** Balls bowled from overs like 45.2 → 272. */
    public static function balls(float $overs): int
    {
        $full = (int) floor($overs + 1e-9);
        return $full * 6 + (int) round(($overs - $full) * 10);
    }

    /** ['need', 'balls', 'rrr'] while the side batting second chases in a T20 / ODI, else null. */
    public static function chase(array $m): ?array
    {
        $total = self::OVERS[$m['type']] ?? null;
        if ($total === null || count($m['score']) !== 2 || $m['ended']) {
            return null;
        }
        [$first, $second] = $m['score'];
        $need = $first['r'] + 1 - $second['r'];
        $balls = $total * 6 - self::balls((float) $second['o']);
        if ($need <= 0 || $balls <= 0 || $second['w'] >= 10) {
            return null;
        }
        return ['need' => $need, 'balls' => $balls, 'rrr' => round($need * 6 / $balls, 2)];
    }

    protected function body(array $config, array $ctx): string
    {
        if ($config['mode'] === 'manual' || !DataFeeds::hasKey('cricapi')) {
            if ($config['m_team1'] === '' && $config['m_team2'] === '') {
                return self::empty(__('No match to show. Enter the score manually or add a cricket data key.'));
            }
            $rows = '';
            foreach ([1, 2] as $i) {
                if ($config['m_team' . $i] !== '' || $config['m_score' . $i] !== '') {
                    $rows .= '<div class="ck-team hc-card"><div class="ck-name">' . e($config['m_team' . $i]) . '</div><div class="ck-score">' . e($config['m_score' . $i]) . '</div></div>';
                }
            }
            return '<div class="ck-board">' . $rows . '</div>'
                . ($config['m_status'] !== '' ? '<div class="ck-status">' . e($config['m_status']) . '</div>' : '');
        }
        $feed = DataFeeds::get('cricapi', [], true);
        $m = self::pick((array) ($feed['data']['matches'] ?? []), $config);
        if ($m === null) {
            return self::empty($feed['data'] === null ? __('Waiting for the live score…') : __('No match right now.'))
                . self::foot($feed['as_of'], $feed['stale']);
        }
        $rows = '';
        foreach ($m['teams'] as $i => $team) {
            $inn = [];
            foreach ($m['score'] as $s) {
                if (mb_stripos($s['inning'], $team) === 0) {
                    $inn[] = $s['r'] . '/' . $s['w'] . ' <small>(' . e(rtrim(rtrim(number_format($s['o'], 1, '.', ''), '0'), '.')) . ' ' . e(__('ov')) . ')</small>';
                }
            }
            $rows .= '<div class="ck-team hc-card"><div class="ck-name">' . e($m['short'][$i] ?? $team) . '<span>' . e($team) . '</span></div>'
                . '<div class="ck-score">' . ($inn ? implode(' &amp; ', $inn) : '<span class="hc-muted">' . e(__('Yet to bat')) . '</span>') . '</div></div>';
        }
        $chase = self::chase($m);
        $live = $m['started'] && !$m['ended'];
        return '<div class="ck-meta">' . ($live ? '<span class="hc-badge ck-live">' . e(__('LIVE')) . '</span> ' : '')
            . ($m['type'] !== '' ? '<span class="hc-badge">' . e(strtoupper($m['type'])) . '</span> ' : '') . '<span>' . e($m['name']) . '</span></div>'
            . '<div class="ck-board">' . $rows . '</div>'
            . ($chase ? '<div class="ck-chase">' . e(__('Need :r runs from :b balls · Required rate :rr', ['r' => $chase['need'], 'b' => $chase['balls'], 'rr' => number_format($chase['rrr'], 2)])) . '</div>' : '')
            . ($m['status'] !== '' ? '<div class="ck-status">' . e($m['status']) . '</div>' : '')
            . ($m['venue'] !== '' ? '<div class="ck-venue hc-muted">' . e($m['venue']) . '</div>' : '')
            . self::foot($feed['as_of'], $feed['stale'], '', 'CricketData.org');
    }
}
