<?php
declare(strict_types=1);

/**
 * Currency rates (#24): rupee rates of chosen currencies with flags, optional buy / sell columns for
 * money changers (margin % around the market rate, or fixed values per currency typed by the admin).
 * Source: the free, key-less currency feed (open.er-api.com or Frankfurter / ECB — Platform settings →
 * Data feeds), refreshed hourly in the background.
 */
final class CurrencyApp extends DataFeedApp
{
    public function key(): string
    {
        return 'currency';
    }

    public function label(): string
    {
        return __('Currency rates');
    }

    public function description(): string
    {
        return __('Foreign exchange rates in rupees with flags — USD, EUR, GBP, AED, SAR and more — with buy / sell rates for money changers.');
    }

    public function icon(): string
    {
        return 'bi-currency-exchange';
    }

    public function category(): string
    {
        return 'business';
    }

    public function defaults(): array
    {
        return [
            'heading' => __('Foreign exchange rates'),
            'subtitle' => '',
            'currencies' => ['USD', 'EUR', 'GBP', 'AED', 'SAR'],
            'buy_sell' => true,
            'buy_margin' => 1.5,
            'sell_margin' => 1.5,
            'overrides' => '',
            'flags' => true,
        ];
    }

    /** "USD | 83.10 | 84.20" lines → [CODE => [buy, sell|null]]. */
    public static function parseOverrides(string $text, array &$errors = []): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $p = array_map('trim', explode('|', $line));
            $code = strtoupper($p[0]);
            $buy = DataFeeds::number($p[1] ?? '');
            $sell = isset($p[2]) && $p[2] !== '' ? DataFeeds::number($p[2]) : null;
            if (!isset(DataFeeds::CURRENCIES[$code]) || $buy === null || $buy <= 0 || (isset($p[2]) && $p[2] !== '' && ($sell === null || $sell <= 0))) {
                $errors[] = __('Line :n: enter "CODE | buy | sell", e.g. "USD | 83.10 | 84.20".', ['n' => $i + 1]);
                continue;
            }
            $out[$code] = [$buy, $sell];
        }
        return $out;
    }

    public function validate(array $in): array
    {
        $errors = [];
        $cur = self::multi($in, 'currencies', array_keys(DataFeeds::CURRENCIES));
        if (!$cur) {
            $errors[] = __('Choose at least one currency.');
            $cur = $this->defaults()['currencies'];
        }
        $over = self::text($in, 'overrides', 2000);
        self::parseOverrides($over, $errors);
        return [[
            'heading' => self::str($in, 'heading', 120),
            'subtitle' => self::str($in, 'subtitle', 190),
            'currencies' => array_slice($cur, 0, 12),
            'buy_sell' => self::bool($in, 'buy_sell'),
            'buy_margin' => round(self::float($in, 'buy_margin', 0, 20, 1.5), 2),
            'sell_margin' => round(self::float($in, 'sell_margin', 0, 20, 1.5), 2),
            'overrides' => $over,
            'flags' => self::bool($in, 'flags'),
        ], $errors];
    }

    public function form(array $config): string
    {
        $opts = [];
        foreach (DataFeeds::CURRENCIES as $code => $cc) {
            $opts[$code] = $code;
        }
        return self::input('heading', __('Heading'), $config['heading'], 'text', ['maxlength' => 120])
            . self::input('subtitle', __('Sub-heading'), $config['subtitle'], 'text', ['maxlength' => 190])
            . self::checkboxes('currencies', __('Currencies (up to 12)'), $opts, (array) $config['currencies'])
            . self::checkbox('buy_sell', __('Show "We buy" / "We sell" columns'), (bool) $config['buy_sell'])
            . self::checkbox('flags', __('Show flags'), (bool) $config['flags'])
            . self::input('buy_margin', __('Buy margin %'), $config['buy_margin'], 'number', ['min' => 0, 'max' => 20, 'step' => '0.01'], __('We buy = market rate minus this %.'))
            . self::input('sell_margin', __('Sell margin %'), $config['sell_margin'], 'number', ['min' => 0, 'max' => 20, 'step' => '0.01'], __('We sell = market rate plus this %.'))
            . self::textarea('overrides', __('Fixed rates (optional)'), (string) $config['overrides'], 3, __('One per line: CODE | buy | sell, e.g. "USD | 83.10 | 84.20". Replaces the market rate for that currency.'), 'col-12', 2000);
    }

    public function refreshSec(array $config): int
    {
        return 300;
    }

    /** [[code, flag, mid, buy, sell]] for the chosen currencies plus the feed state. */
    public static function rows(array $config, bool $inline = true): array
    {
        $feed = DataFeeds::currency($inline);
        $rates = (array) ($feed['data']['rates'] ?? []);
        $over = self::parseOverrides((string) $config['overrides']);
        $out = [];
        foreach ((array) $config['currencies'] as $code) {
            $mid = isset($rates[$code]) && is_numeric($rates[$code]) ? (float) $rates[$code] : null;
            if (isset($over[$code])) {
                [$buy, $sell] = $over[$code];
                $sell ??= $buy;
            } else {
                $buy = $mid !== null ? $mid * (1 - (float) $config['buy_margin'] / 100) : null;
                $sell = $mid !== null ? $mid * (1 + (float) $config['sell_margin'] / 100) : null;
            }
            $out[] = ['code' => $code, 'flag' => DataFeeds::flag(DataFeeds::CURRENCIES[$code] ?? ''), 'mid' => $mid, 'buy' => $buy, 'sell' => $sell, 'fixed' => isset($over[$code])];
        }
        return [$out, $feed];
    }

    protected function body(array $config, array $ctx): string
    {
        [$rows, $feed] = self::rows($config);
        $fmt = static fn (?float $v): string => $v === null ? '—' : '₹ ' . DataFeeds::inr($v, $v < 10 ? 4 : 2);
        $head = '<div class="cx-row cx-head"><div class="cx-cur">' . e(__('Currency')) . '</div>'
            . ($config['buy_sell'] ? '<div>' . e(__('We buy')) . '</div><div>' . e(__('We sell')) . '</div>' : '<div>' . e(__('Rate')) . '</div>') . '</div>';
        $html = '';
        foreach ($rows as $r) {
            $html .= '<div class="cx-row hc-card"><div class="cx-cur">' . ($config['flags'] && $r['flag'] !== '' ? '<span class="cx-flag">' . $r['flag'] . '</span>' : '')
                . '<b>' . e($r['code']) . '</b><span class="hc-muted"> = 1</span></div>'
                . ($config['buy_sell'] ? '<div class="cx-val">' . e($fmt($r['buy'])) . '</div><div class="cx-val">' . e($fmt($r['sell'])) . '</div>' : '<div class="cx-val">' . e($fmt($r['mid'] ?? $r['buy'])) . '</div>')
                . '</div>';
        }
        $src = DataFeeds::PROVIDERS[$feed['provider']]['name'] ?? '';
        return '<div class="cx-table' . ($config['buy_sell'] ? ' cx-3' : ' cx-2') . '">' . $head . $html . '</div>'
            . self::foot($feed['as_of'], (bool) $feed['stale'], __('Indicative rates'), $src);
    }
}
