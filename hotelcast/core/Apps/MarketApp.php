<?php
declare(strict_types=1);

/**
 * Stock market ticker (#22): NIFTY 50, SENSEX, BANK NIFTY and custom symbols as a tile grid or a
 * scrolling strip, green / red change %, labelled "Delayed / indicative".
 * Source "auto": Twelve Data quotes (API key in Platform settings → Data feeds, or the hotel's own key;
 * symbols like NSEI, BSESN, RELIANCE:NSE — check the provider's symbol search). Source "manual" (or no
 * key): the rows typed on admin/rates.php ("Label | value | change %"). No scraping.
 */
final class MarketApp extends DataFeedApp
{
    private const MAX_CUSTOM = 9;

    public function key(): string
    {
        return 'market';
    }

    public function label(): string
    {
        return __('Stock market');
    }

    public function description(): string
    {
        return __('NIFTY 50, SENSEX, BANK NIFTY and your own symbols with green / red change, as tiles or a scrolling strip.');
    }

    public function icon(): string
    {
        return 'bi-graph-up-arrow';
    }

    public function adminPage(): ?string
    {
        return admin_url('rates.php', ['tab' => 'market']);
    }

    public function defaults(): array
    {
        return [
            'heading' => __('Market watch'),
            'subtitle' => '',
            'source' => 'auto',
            'indices' => array_keys(DataFeeds::INDICES),
            'symbols' => '',
            'layout' => 'grid',
        ];
    }

    /** "Label | SYMBOL" lines → [[label, symbol]]; invalid lines are reported in $errors. */
    public static function parseSymbols(string $text, array &$errors = []): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $i => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line, 2));
            $sym = strtoupper($parts[1] ?? $parts[0]);
            $label = $parts[0] !== '' ? mb_substr($parts[0], 0, 30) : $sym;
            if (!preg_match(DataFeeds::SYMBOL_RE, $sym)) {
                $errors[] = __('Line :n: enter "Label | SYMBOL", e.g. "Reliance | RELIANCE:NSE".', ['n' => $i + 1]);
                continue;
            }
            $out[] = [$label, $sym];
        }
        if (count($out) > self::MAX_CUSTOM) {
            $errors[] = __('At most :n custom symbols.', ['n' => self::MAX_CUSTOM]);
            $out = array_slice($out, 0, self::MAX_CUSTOM);
        }
        return $out;
    }

    public function validate(array $in): array
    {
        $errors = [];
        $symbols = self::text($in, 'symbols', 2000);
        self::parseSymbols($symbols, $errors);
        return [[
            'heading' => self::str($in, 'heading', 120),
            'subtitle' => self::str($in, 'subtitle', 190),
            'source' => self::choice($in, 'source', ['auto', 'manual'], 'auto'),
            'indices' => self::multi($in, 'indices', array_keys(DataFeeds::INDICES)),
            'symbols' => $symbols,
            'layout' => self::choice($in, 'layout', ['grid', 'ticker'], 'grid'),
        ], $errors];
    }

    public function form(array $config): string
    {
        $idx = [];
        foreach (DataFeeds::INDICES as $k => [$label]) {
            $idx[$k] = $label;
        }
        $keyNote = DataFeeds::hasKey('twelvedata') ? __('A market data key is set.') : __('No market data key yet: the manual values are shown.');
        return self::input('heading', __('Heading'), $config['heading'], 'text', ['maxlength' => 120])
            . self::input('subtitle', __('Sub-heading'), $config['subtitle'], 'text', ['maxlength' => 190])
            . self::select('source', __('Values'), ['auto' => __('Automatic (market data provider)'), 'manual' => __('Manual (typed on the Rates page)')], (string) $config['source'], $keyNote)
            . self::select('layout', __('Layout'), ['grid' => __('Tiles'), 'ticker' => __('Scrolling strip')], (string) $config['layout'])
            . self::checkboxes('indices', __('Indices'), $idx, (array) $config['indices'])
            . self::textarea('symbols', __('More symbols (automatic mode)'), (string) $config['symbols'], 4, __('One per line: Label | SYMBOL, e.g. "Reliance | RELIANCE:NSE". Check the symbol on the provider\'s symbol search.'), 'col-12', 2000)
            . '<div class="col-12"><a class="btn btn-sm btn-outline-primary" href="' . e(admin_url('rates.php', ['tab' => 'market'])) . '"><i class="bi bi-pencil-square"></i> ' . e(__('Enter market values manually')) . '</a></div>';
    }

    public function refreshSec(array $config): int
    {
        return 60;
    }

    /**
     * Rows to show: [[label, price, change, pct]], plus the feed state (null for manual values).
     * @return array{0: array, 1: ?array}
     */
    public static function quotes(array $config, bool $inline = true): array
    {
        if ($config['source'] === 'auto' && DataFeeds::hasKey('twelvedata')) {
            $want = [];
            foreach ((array) $config['indices'] as $k) {
                if (isset(DataFeeds::INDICES[$k])) {
                    $want[] = [DataFeeds::INDICES[$k][0], DataFeeds::indexSymbol($k)];
                }
            }
            foreach (self::parseSymbols((string) $config['symbols']) as $s) {
                $want[] = $s;
            }
            if ($want) {
                $feed = DataFeeds::get('twelvedata', ['symbols' => array_map(static fn ($w) => $w[1], $want)], $inline);
                $rows = [];
                foreach ($want as [$label, $sym]) {
                    $q = $feed['data']['quotes'][$sym] ?? null;
                    $rows[] = ['label' => $label, 'price' => is_array($q) ? (float) $q['price'] : null, 'change' => is_array($q) ? $q['change'] : null, 'pct' => is_array($q) ? $q['pct'] : null];
                }
                return [$rows, $feed];
            }
        }
        return [DataFeeds::manualMarket(), null];
    }

    protected function body(array $config, array $ctx): string
    {
        [$rows, $feed] = self::quotes($config);
        if (!$rows) {
            return self::empty(__('Market values will appear here. Enter them on the Rates page or add a market data key.'));
        }
        $items = '';
        foreach ($rows as $r) {
            $pct = $r['pct'] !== null ? (float) $r['pct'] : null;
            $cls = $pct === null || abs($pct) < 0.005 ? 'df-flat' : ($pct > 0 ? 'df-up' : 'df-down');
            $items .= '<div class="mk-item hc-card ' . $cls . '"><div class="mk-label">' . e($r['label']) . '</div>'
                . '<div class="mk-price">' . e($r['price'] !== null ? DataFeeds::inr((float) $r['price'], 2) : '—') . '</div>'
                . '<div class="mk-change">' . ($pct !== null ? self::arrow($pct > 0 ? 'up' : ($pct < 0 ? 'down' : '')) . ' ' . e(DataFeeds::pct($pct)) : '') . '</div></div>';
        }
        $html = $config['layout'] === 'ticker'
            ? '<div class="mk-strip"><div class="mk-track" style="-webkit-animation-duration:' . (count($rows) * 6 + 10) . 's;animation-duration:' . (count($rows) * 6 + 10) . 's">' . $items . $items . '</div></div>'
            : '<div class="mk-grid">' . $items . '</div>';
        $asOf = $feed !== null ? $feed['as_of'] : ((int) Settings::get('rates_market_updated', '0') ?: null);
        return $html . self::foot($asOf, (bool) ($feed['stale'] ?? false), __('Delayed / indicative'), $feed ? 'Twelve Data' : '');
    }
}
