<?php
declare(strict_types=1);

/**
 * Data feeds layer (2.3, features #21–#25): external data for the gold, market, cricket, currency and
 * travel widgets (core/Apps/*App.php) and for ticker placeholders ({usd_inr}, {gold_24k} …).
 * See docs/modules/data_feeds.md.
 *
 *  - Providers are fixed (PROVIDERS): each has ONE fixed https host + path; parameters are validated
 *    with strict patterns and URL-encoded, so no arbitrary URL can ever be requested (no SSRF).
 *  - A feed = provider + params + key owner (platform key, or a hotel's own key). Its state lives in the
 *    platform table `data_feeds` (last good value + "as of" time, last error, retry time, last demand).
 *    Pages and TVs only READ that state (DataFeeds::get()); the background task DataFeedsTask
 *    (core/Tasks) refreshes feeds that were used in the last 24 h when their TTL is over, within the
 *    request budget (platform_feeds_per_min requests / minute overall, an optional daily cap per provider).
 *    The very first value of a new feed may be fetched inline once ($inline).
 *  - On an error / rate limit the last good value is kept and marked stale; retries back off.
 *  - API keys: platform settings platform_feedkey_<provider> (Super Admin → Platform settings → Data
 *    feeds) with an optional hotel override feedkey_<provider> (admin/data_feeds.php). Both encrypted with
 *    Crypto (APP_KEY). Keys are only used server-side to build the request; never stored in rows, logs,
 *    errors, page HTML or data JSON.
 *  - Tests / sandboxes: when HC_TESTING is defined, the installation runs from a test sandbox or the
 *    config has 'data_feeds_offline' => true, real network requests are refused unless an Http mock is
 *    active (Http::$mock or config 'http_mock_file').
 */
final class DataFeeds
{
    /**
     * feed: what it delivers; host: the only host used; key: needs an API key; ttl: default seconds between
     * refreshes; min_ttl: lowest TTL an admin may set; cap: default daily request cap (0 = none);
     * per_fetch: requests per refresh; signup: where to get a key / docs.
     */
    public const PROVIDERS = [
        'open_er_api' => ['feed' => 'currency', 'name' => 'open.er-api.com (ExchangeRate-API)', 'host' => 'open.er-api.com', 'key' => false, 'ttl' => 3600, 'min_ttl' => 3600, 'cap' => 0, 'per_fetch' => 1, 'signup' => 'https://www.exchangerate-api.com/docs/free'],
        'frankfurter' => ['feed' => 'currency', 'name' => 'Frankfurter (ECB reference rates)', 'host' => 'api.frankfurter.dev', 'key' => false, 'ttl' => 3600, 'min_ttl' => 3600, 'cap' => 0, 'per_fetch' => 1, 'signup' => 'https://frankfurter.dev'],
        'goldapi' => ['feed' => 'metals', 'name' => 'GoldAPI.io', 'host' => 'www.goldapi.io', 'key' => true, 'ttl' => 43200, 'min_ttl' => 600, 'cap' => 6, 'per_fetch' => 2, 'signup' => 'https://www.goldapi.io'],
        'twelvedata' => ['feed' => 'market', 'name' => 'Twelve Data', 'host' => 'api.twelvedata.com', 'key' => true, 'ttl' => 900, 'min_ttl' => 60, 'cap' => 700, 'per_fetch' => 1, 'signup' => 'https://twelvedata.com/pricing'],
        'cricapi' => ['feed' => 'cricket', 'name' => 'CricketData.org (CricAPI)', 'host' => 'api.cricapi.com', 'key' => true, 'ttl' => 60, 'min_ttl' => 30, 'cap' => 100, 'per_fetch' => 1, 'signup' => 'https://cricketdata.org'],
        'aviationstack' => ['feed' => 'flights', 'name' => 'aviationstack', 'host' => 'api.aviationstack.com', 'key' => true, 'ttl' => 3600, 'min_ttl' => 300, 'cap' => 3, 'per_fetch' => 1, 'signup' => 'https://aviationstack.com'],
    ];

    /** Index labels → default Twelve Data symbols (editable in Platform settings → Data feeds). */
    public const INDICES = [
        'nifty' => ['NIFTY 50', 'NSEI'],
        'sensex' => ['SENSEX', 'BSESN'],
        'banknifty' => ['BANK NIFTY', 'NSEBANK'],
    ];

    /** Currencies offered by the currency widget: code => flag (ISO country, EU for the euro). */
    public const CURRENCIES = [
        'USD' => 'US', 'EUR' => 'EU', 'GBP' => 'GB', 'AED' => 'AE', 'SAR' => 'SA', 'QAR' => 'QA', 'KWD' => 'KW', 'OMR' => 'OM',
        'BHD' => 'BH', 'SGD' => 'SG', 'AUD' => 'AU', 'CAD' => 'CA', 'NZD' => 'NZ', 'JPY' => 'JP', 'CHF' => 'CH', 'CNY' => 'CN',
        'HKD' => 'HK', 'THB' => 'TH', 'MYR' => 'MY', 'NPR' => 'NP', 'LKR' => 'LK', 'BDT' => 'BD', 'ZAR' => 'ZA', 'RUB' => 'RU',
    ];

    /** Ticker placeholders (docs/modules/data_feeds.md). */
    public const PLACEHOLDERS = [
        'gold_24k', 'gold_22k', 'gold_18k', 'silver',
        'usd_inr', 'eur_inr', 'gbp_inr', 'aed_inr', 'sar_inr',
        'nifty', 'sensex', 'banknifty',
    ];

    /** Market symbol: NSEI, RELIANCE:NSE, EUR/USD, BSE.BANK (no "//", one exchange suffix). */
    public const SYMBOL_RE = '/^[A-Z0-9][A-Z0-9._-]{0,15}(\/[A-Z0-9._-]{1,10})?(:[A-Z0-9_-]{1,10})?$/';

    private const ACTIVE_FOR = 86400;      // a feed is refreshed while it was used in the last 24 h
    private const FORGET_AFTER = 30 * 86400;
    private const DEMAND_WRITE_EVERY = 300;
    private const TIMEOUT = 8;

    /** @var array<string, array> memo of rows per request */
    private static array $memo = [];
    /** @var array<int, array<string, string>> placeholder values per hotel (per request) */
    private static array $phMemo = [];
    /** Number of real (or mocked) HTTP requests made in this process (tests). */
    public static int $requests = 0;
    /**
     * Read-only mode (draft previews, admin/apps.php op=preview): get() only reads feeds that already
     * exist — it never registers a new feed and never fetches, so typing in a form cannot spend the
     * shared platform API budget (daily caps) of every hotel.
     */
    public static bool $readOnly = false;

    public static function flush(): void
    {
        self::$memo = [];
        self::$phMemo = [];
    }

    // ------------------------------------------------------------------ settings & keys

    public static function provider(string $p): ?array
    {
        return self::PROVIDERS[$p] ?? null;
    }

    /** Provider used for the currency feed (platform setting). */
    public static function currencyProvider(): string
    {
        $p = (string) Settings::platform('platform_feed_currency_provider', 'open_er_api');
        return in_array($p, ['open_er_api', 'frankfurter'], true) ? $p : 'open_er_api';
    }

    /** TTL in seconds: platform setting (≥ min_ttl), stretched so a daily cap is never exceeded by one feed. */
    public static function ttl(string $p): int
    {
        $def = self::PROVIDERS[$p] ?? null;
        if (!$def) {
            return 3600;
        }
        $ttl = max($def['min_ttl'], (int) Settings::platform('platform_feed_ttl_' . $p, (string) $def['ttl']) ?: $def['ttl']);
        $cap = self::dailyCap($p);
        if ($cap > 0) {
            $ttl = max($ttl, (int) ceil(86400 * $def['per_fetch'] / $cap));
        }
        return $ttl;
    }

    public static function dailyCap(string $p): int
    {
        $def = self::PROVIDERS[$p] ?? null;
        if (!$def) {
            return 0;
        }
        $v = Settings::platform('platform_feed_cap_' . $p, null);
        return max(0, is_numeric($v) ? (int) $v : (int) $def['cap']);
    }

    public static function perMinute(): int
    {
        return max(1, min(600, (int) Settings::platform('platform_feeds_per_min', '20') ?: 20));
    }

    public static function platformKey(string $p): string
    {
        $v = (string) Settings::platform('platform_feedkey_' . $p, '');
        return $v === '' ? '' : (Crypto::decrypt($v) ?? '');
    }

    public static function hotelKey(string $p, int $hotelId): string
    {
        if ($hotelId <= 0) {
            return '';
        }
        $v = (string) Settings::getFor($hotelId, 'feedkey_' . $p, '');
        return $v === '' ? '' : (Crypto::decrypt($v) ?? '');
    }

    /** Store / clear the platform key of a provider (encrypted). */
    public static function setPlatformKey(string $p, string $key): void
    {
        if (isset(self::PROVIDERS[$p]) && self::PROVIDERS[$p]['key']) {
            Settings::setPlatform('platform_feedkey_' . $p, $key === '' ? '' : Crypto::encrypt($key));
        }
    }

    /** Store / clear the current hotel's own key of a provider (encrypted). */
    public static function setHotelKey(string $p, string $key): void
    {
        if (isset(self::PROVIDERS[$p]) && self::PROVIDERS[$p]['key']) {
            Settings::setFor(Tenant::id(), 'feedkey_' . $p, $key === '' ? '' : Crypto::encrypt($key));
        }
    }

    /** A plausible API key (printable, no spaces), max 200 chars. */
    public static function validKey(string $key): bool
    {
        return (bool) preg_match('/^[\x21-\x7E]{8,200}$/', $key);
    }

    /** [key, owner hotel id (0 = platform)] for the current hotel; key '' when none. */
    public static function keyFor(string $p, ?int $hotelId = null): array
    {
        $def = self::PROVIDERS[$p] ?? null;
        if (!$def || !$def['key']) {
            return ['', 0];
        }
        $hid = $hotelId ?? (Tenant::current() ?? 0);
        $own = self::hotelKey($p, $hid);
        if ($own !== '') {
            return [$own, $hid];
        }
        return [self::platformKey($p), 0];
    }

    public static function hasKey(string $p, ?int $hotelId = null): bool
    {
        $def = self::PROVIDERS[$p] ?? null;
        return $def !== null && (!$def['key'] || self::keyFor($p, $hotelId)[0] !== '');
    }

    /**
     * Save the "Data feeds" tab of Platform settings ($in = POST). Keys: empty = keep, feedkey_clear_<p> =
     * remove. Returns translated errors (nothing saved for an invalid key).
     */
    public static function savePlatformSettings(array $in): array
    {
        $errors = [];
        $str = static fn (string $k, int $max = 200): string => is_scalar($in[$k] ?? null) ? mb_substr(trim((string) $in[$k]), 0, $max) : '';
        $cp = $str('feed_currency_provider', 30);
        Settings::setPlatform('platform_feed_currency_provider', in_array($cp, ['open_er_api', 'frankfurter'], true) ? $cp : 'open_er_api');
        $pm = $str('feeds_per_min', 5);
        Settings::setPlatform('platform_feeds_per_min', (string) max(1, min(600, ctype_digit($pm) ? (int) $pm : 20)));
        foreach (self::PROVIDERS as $p => $def) {
            $ttl = $str('feed_ttl_' . $p, 7);
            if ($ttl !== '') {
                Settings::setPlatform('platform_feed_ttl_' . $p, (string) max($def['min_ttl'], min(7 * 86400, ctype_digit($ttl) ? (int) $ttl : $def['ttl'])));
            }
            if (!$def['key']) {
                continue;
            }
            $cap = $str('feed_cap_' . $p, 7);
            if ($cap !== '') {
                Settings::setPlatform('platform_feed_cap_' . $p, (string) max(0, min(1000000, ctype_digit($cap) ? (int) $cap : $def['cap'])));
            }
            $key = $str('feedkey_' . $p, 250);
            if (!empty($in['feedkey_clear_' . $p])) {
                self::setPlatformKey($p, '');
            } elseif ($key !== '') {
                if (!self::validKey($key)) {
                    $errors[] = __(':p: the API key looks wrong (8–200 characters, no spaces).', ['p' => $def['name']]);
                } else {
                    self::setPlatformKey($p, $key);
                }
            }
        }
        foreach (self::INDICES as $k => [$label, $def]) {
            $v = strtoupper($str('feed_idx_' . $k, 30));
            if ($v !== '' && !preg_match(self::SYMBOL_RE, $v)) {
                $errors[] = __(':i: invalid symbol.', ['i' => $label]);
                continue;
            }
            Settings::setPlatform('platform_feed_idx_' . $k, $v !== '' ? $v : $def);
        }
        Settings::setPlatform('platform_feed_aviationstack_http', !empty($in['feed_aviationstack_http']) ? '1' : '0');
        self::flush();
        return $errors;
    }

    // ------------------------------------------------------------------ params & URLs (fixed hosts)

    /** Validated params of a provider, or null when invalid. */
    public static function cleanParams(string $p, array $params): ?array
    {
        switch ($p) {
            case 'twelvedata':
                $syms = [];
                foreach ((array) ($params['symbols'] ?? []) as $s) {
                    $s = strtoupper(trim((string) $s));
                    if (!preg_match(self::SYMBOL_RE, $s)) {
                        return null;
                    }
                    $syms[$s] = $s;
                }
                if (!$syms || count($syms) > 12) {
                    return null;
                }
                sort($syms);
                return ['symbols' => array_values($syms)];
            case 'aviationstack':
                $f = strtoupper(str_replace(' ', '', (string) ($params['flight'] ?? '')));
                return preg_match('/^[A-Z0-9]{2}[A-Z]?\d{1,4}[A-Z]?$/', $f) ? ['flight' => $f] : null;
            case 'open_er_api':
            case 'frankfurter':
            case 'goldapi':
            case 'cricapi':
                return [];
        }
        return null;
    }

    /**
     * Request list for a provider: [[url, headers], …]. The key goes into the URL / header here only.
     * Every URL is built from the provider's fixed host; requests() re-checks the host.
     */
    public static function requests(string $p, array $params, string $key): array
    {
        $def = self::PROVIDERS[$p] ?? null;
        $params = $def ? self::cleanParams($p, $params) : null;
        if ($params === null) {
            throw new InvalidArgumentException('Unknown data feed provider or invalid parameters');
        }
        $base = 'https://' . $def['host'];
        $list = match ($p) {
            'open_er_api' => [[$base . '/v6/latest/INR', []]],
            'frankfurter' => [[$base . '/v1/latest?base=INR', []]],
            'goldapi' => [[$base . '/api/XAU/USD', ['x-access-token: ' . $key, 'Accept: application/json']], [$base . '/api/XAG/USD', ['x-access-token: ' . $key, 'Accept: application/json']]],
            'twelvedata' => [[$base . '/quote?' . http_build_query(['symbol' => implode(',', $params['symbols']), 'apikey' => $key], '', '&', PHP_QUERY_RFC3986), []]],
            'cricapi' => [[$base . '/v1/currentMatches?' . http_build_query(['apikey' => $key, 'offset' => 0], '', '&', PHP_QUERY_RFC3986), []]],
            'aviationstack' => [[(Settings::platform('platform_feed_aviationstack_http', '0') === '1' ? 'http://' . $def['host'] : $base)
                . '/v1/flights?' . http_build_query(['access_key' => $key, 'flight_iata' => $params['flight']], '', '&', PHP_QUERY_RFC3986), []]],
        };
        foreach ($list as [$url]) {
            if (strtolower((string) parse_url($url, PHP_URL_HOST)) !== $def['host'] || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PORT) !== null) {
                throw new InvalidArgumentException('Data feed URL outside the provider host');
            }
        }
        return $list;
    }

    /** May this process make network requests? (Refused in tests / sandboxes unless an Http mock is active.) */
    public static function networkAllowed(): bool
    {
        if (Http::$mock !== null || (string) Config::get('http_mock_file', '') !== '') {
            return true;
        }
        if (defined('HC_TESTING') || str_contains(HC_ROOT, 'hotelcast_sandbox_') || Config::get('data_feeds_offline', false)) {
            return false;
        }
        return true;
    }

    /**
     * Fetch a provider now (no budget / state handling): ['ok', 'data', 'error', 'rate_limited', 'requests'].
     * The key never appears in 'error'.
     */
    public static function fetch(string $p, array $params, string $key): array
    {
        $def = self::PROVIDERS[$p] ?? null;
        if (!$def) {
            return ['ok' => false, 'data' => null, 'error' => 'Unknown provider', 'rate_limited' => false, 'requests' => 0];
        }
        if ($def['key'] && $key === '') {
            return ['ok' => false, 'data' => null, 'error' => 'No API key', 'rate_limited' => false, 'requests' => 0];
        }
        if (!self::networkAllowed()) {
            return ['ok' => false, 'data' => null, 'error' => 'Network disabled (test mode)', 'rate_limited' => false, 'requests' => 0];
        }
        try {
            $reqs = self::requests($p, $params, $key);
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'data' => null, 'error' => 'Invalid parameters', 'rate_limited' => false, 'requests' => 0];
        }
        $responses = [];
        $n = 0;
        foreach ($reqs as [$url, $headers]) {
            $n++;
            self::$requests++;
            $res = Http::request('GET', $url, $headers, null, self::TIMEOUT, null, false); // keys in the request: never follow redirects
            if ($res['status'] === 429) {
                return ['ok' => false, 'data' => null, 'error' => 'Rate limit reached (HTTP 429)', 'rate_limited' => true, 'requests' => $n];
            }
            $responses[] = $res;
        }
        try {
            $data = self::parse($p, $responses, self::cleanParams($p, $params) ?? []);
            return ['ok' => true, 'data' => $data, 'error' => null, 'rate_limited' => false, 'requests' => $n];
        } catch (DataFeedError $e) {
            $msg = mb_substr(str_replace($key !== '' ? $key : "\0", '***', $e->getMessage()), 0, 250);
            return ['ok' => false, 'data' => null, 'error' => $msg, 'rate_limited' => $e->rateLimited, 'requests' => $n];
        }
    }

    // ------------------------------------------------------------------ parsers (normalised data)

    /** @param array<int, array{status:int, body:string}> $responses */
    public static function parse(string $p, array $responses, array $params = []): array
    {
        $json = [];
        foreach ($responses as $r) {
            $status = (int) ($r['status'] ?? 0);
            $d = json_decode((string) ($r['body'] ?? ''), true);
            if ($status === 0) {
                throw new DataFeedError('Network error');
            }
            if (!is_array($d)) {
                throw new DataFeedError('Invalid response (HTTP ' . $status . ')');
            }
            $json[] = [$status, $d];
        }
        return match ($p) {
            'open_er_api' => self::parseOpenEr($json[0]),
            'frankfurter' => self::parseFrankfurter($json[0]),
            'goldapi' => self::parseGoldApi($json),
            'twelvedata' => self::parseTwelveData($json[0], $params),
            'cricapi' => self::parseCricApi($json[0]),
            'aviationstack' => self::parseAviationstack($json[0], $params),
            default => throw new DataFeedError('Unknown provider'),
        };
    }

    private static function msg(mixed $m, string $fallback): string
    {
        return is_scalar($m) && trim((string) $m) !== '' ? mb_substr(trim(strip_tags((string) $m)), 0, 200) : $fallback;
    }

    private static function limitWords(string $m): bool
    {
        return (bool) preg_match('/limit|quota|too many|exceed/i', $m);
    }

    /** {"result":"success","rates":{"USD":0.012…}} (1 INR in foreign units) → INR per 1 unit. */
    private static function parseOpenEr(array $r): array
    {
        [$status, $d] = $r;
        if (($d['result'] ?? '') !== 'success' || !is_array($d['rates'] ?? null)) {
            $m = self::msg($d['error-type'] ?? null, 'Error (HTTP ' . $status . ')');
            throw new DataFeedError($m, self::limitWords($m));
        }
        return ['base' => 'INR', 'rates' => self::invertRates($d['rates']), 'source_time' => (int) ($d['time_last_update_unix'] ?? 0) ?: null];
    }

    private static function parseFrankfurter(array $r): array
    {
        [$status, $d] = $r;
        if ($status !== 200 || !is_array($d['rates'] ?? null)) {
            throw new DataFeedError(self::msg($d['message'] ?? null, 'Error (HTTP ' . $status . ')'));
        }
        $ts = strtotime((string) ($d['date'] ?? '') . ' 16:00:00 CET');
        return ['base' => 'INR', 'rates' => self::invertRates($d['rates']), 'source_time' => $ts ?: null];
    }

    private static function invertRates(array $rates): array
    {
        $out = [];
        foreach ($rates as $code => $v) {
            if (is_string($code) && preg_match('/^[A-Z]{3}$/', $code) && is_numeric($v) && (float) $v > 0 && $code !== 'INR') {
                $out[$code] = round(1 / (float) $v, 6);
            }
        }
        if (!$out) {
            throw new DataFeedError('No rates in the response');
        }
        ksort($out);
        return $out;
    }

    /** Two responses (XAU/USD, XAG/USD): {"price": per troy ounce, "prev_close_price", "price_gram_24k", "timestamp"}. */
    private static function parseGoldApi(array $json): array
    {
        $out = ['source_time' => null];
        foreach (['gold' => 0, 'silver' => 1] as $metal => $i) {
            [$status, $d] = $json[$i] ?? [0, []];
            if ($status !== 200 || isset($d['error']) || !is_numeric($d['price'] ?? null) || (float) $d['price'] <= 0) {
                $m = self::msg($d['error'] ?? ($d['message'] ?? null), 'Error (HTTP ' . $status . ')');
                throw new DataFeedError($m, $status === 429 || self::limitWords($m));
            }
            $oz = 31.1034768;
            $g = is_numeric($d['price_gram_24k'] ?? null) && (float) $d['price_gram_24k'] > 0 ? (float) $d['price_gram_24k'] : (float) $d['price'] / $oz;
            $out[$metal . '_usd_g'] = round($g, 6);
            $out[$metal . '_prev_usd_g'] = is_numeric($d['prev_close_price'] ?? null) && (float) $d['prev_close_price'] > 0 ? round((float) $d['prev_close_price'] / $oz, 6) : null;
            $ts = (int) ($d['timestamp'] ?? 0);
            $out['source_time'] = $ts > 0 ? ($ts > 20000000000 ? intdiv($ts, 1000) : $ts) : $out['source_time'];
        }
        return $out;
    }

    /** One symbol: flat quote object; several: {"SYM": quote, …}; error: {"code":429,"status":"error","message"}. */
    private static function parseTwelveData(array $r, array $params): array
    {
        [$status, $d] = $r;
        if (($d['status'] ?? '') === 'error' && !isset($d['symbol'])) {
            $m = self::msg($d['message'] ?? null, 'Error (HTTP ' . $status . ')');
            throw new DataFeedError($m, (int) ($d['code'] ?? 0) === 429 || self::limitWords($m));
        }
        $symbols = (array) ($params['symbols'] ?? []);
        $map = isset($d['symbol']) && count($symbols) <= 1 ? [($symbols[0] ?? (string) $d['symbol']) => $d] : $d;
        $quotes = [];
        foreach ($symbols ?: array_keys($map) as $sym) {
            $q = $map[$sym] ?? null;
            if (!is_array($q) || ($q['status'] ?? '') === 'error' || !is_numeric($q['close'] ?? null)) {
                continue;
            }
            $quotes[(string) $sym] = [
                'name' => mb_substr((string) ($q['name'] ?? $sym), 0, 80),
                'price' => (float) $q['close'],
                'change' => is_numeric($q['change'] ?? null) ? (float) $q['change'] : null,
                'pct' => is_numeric($q['percent_change'] ?? null) ? round((float) $q['percent_change'], 2) : null,
                'prev' => is_numeric($q['previous_close'] ?? null) ? (float) $q['previous_close'] : null,
                'open' => (bool) ($q['is_market_open'] ?? false),
                'time' => (int) ($q['timestamp'] ?? 0) ?: null,
            ];
        }
        if (!$quotes) {
            throw new DataFeedError(self::msg(is_array($map) ? (reset($map)['message'] ?? null) : null, 'No quotes for these symbols'));
        }
        return ['quotes' => $quotes];
    }

    /** {"status":"success","data":[match…],"info":{hitsUsed,hitsLimit}} / {"status":"failure","reason"}. */
    private static function parseCricApi(array $r): array
    {
        [$status, $d] = $r;
        if (($d['status'] ?? '') !== 'success' || !is_array($d['data'] ?? null)) {
            $m = self::msg($d['reason'] ?? ($d['message'] ?? null), 'Error (HTTP ' . $status . ')');
            throw new DataFeedError($m, self::limitWords($m));
        }
        $matches = [];
        foreach (array_slice($d['data'], 0, 60) as $m) {
            if (!is_array($m) || !isset($m['id'])) {
                continue;
            }
            $teams = array_values(array_map(static fn ($t) => mb_substr((string) $t, 0, 60), array_filter((array) ($m['teams'] ?? []), 'is_scalar')));
            $short = [];
            foreach ((array) ($m['teamInfo'] ?? []) as $ti) {
                if (is_array($ti) && isset($ti['name'])) {
                    $short[(string) $ti['name']] = mb_substr((string) ($ti['shortname'] ?? $ti['name']), 0, 12);
                }
            }
            $score = [];
            foreach ((array) ($m['score'] ?? []) as $s) {
                if (is_array($s)) {
                    $score[] = ['inning' => mb_substr((string) ($s['inning'] ?? ''), 0, 80), 'r' => (int) ($s['r'] ?? 0), 'w' => (int) ($s['w'] ?? 0), 'o' => (float) ($s['o'] ?? 0)];
                }
            }
            $matches[] = [
                'id' => mb_substr((string) $m['id'], 0, 64),
                'name' => mb_substr((string) ($m['name'] ?? implode(' vs ', $teams)), 0, 160),
                'type' => strtolower(mb_substr((string) ($m['matchType'] ?? ''), 0, 10)),
                'status' => mb_substr((string) ($m['status'] ?? ''), 0, 160),
                'venue' => mb_substr((string) ($m['venue'] ?? ''), 0, 120),
                'teams' => $teams,
                'short' => array_map(static fn ($t) => $short[$t] ?? $t, $teams),
                'score' => $score,
                'started' => (bool) ($m['matchStarted'] ?? false),
                'ended' => (bool) ($m['matchEnded'] ?? false),
                'time' => strtotime((string) ($m['dateTimeGMT'] ?? '') . ' UTC') ?: null,
            ];
        }
        return ['matches' => $matches, 'hits' => isset($d['info']['hitsUsed']) ? ['used' => (int) $d['info']['hitsUsed'], 'limit' => (int) ($d['info']['hitsLimit'] ?? 0)] : null];
    }

    /** {"data":[{flight_date, flight_status, departure{…}, arrival{…}, airline{name}, flight{iata}}]} / {"error":{code,message}}. */
    private static function parseAviationstack(array $r, array $params): array
    {
        [$status, $d] = $r;
        if (isset($d['error']) || !is_array($d['data'] ?? null)) {
            $code = (string) ($d['error']['code'] ?? '');
            $m = self::msg($d['error']['message'] ?? null, 'Error (HTTP ' . $status . ')');
            throw new DataFeedError(preg_replace('/\s*\[Technical Support:.*$/', '', $m) ?? $m, str_contains($code, 'limit') || self::limitWords($m));
        }
        $today = date('Y-m-d');
        $pick = null;
        foreach ($d['data'] as $f) {
            if (!is_array($f)) {
                continue;
            }
            if ($pick === null || ((string) ($f['flight_date'] ?? '') === $today && (string) ($pick['flight_date'] ?? '') !== $today)) {
                $pick = $f;
            }
        }
        $flight = (string) ($params['flight'] ?? '');
        if ($pick === null) {
            return ['flight' => $flight, 'found' => false];
        }
        $leg = static function (mixed $x): array {
            $x = is_array($x) ? $x : [];
            $t = static fn ($k) => isset($x[$k]) && is_string($x[$k]) && $x[$k] !== '' ? (strtotime($x[$k]) ?: null) : null;
            return [
                'airport' => mb_substr((string) ($x['airport'] ?? ''), 0, 80), 'iata' => mb_substr((string) ($x['iata'] ?? ''), 0, 4),
                'scheduled' => $t('scheduled'), 'estimated' => $t('estimated'), 'actual' => $t('actual'),
                'terminal' => mb_substr((string) ($x['terminal'] ?? ''), 0, 10), 'gate' => mb_substr((string) ($x['gate'] ?? ''), 0, 10),
                'baggage' => mb_substr((string) ($x['baggage'] ?? ''), 0, 10), 'delay' => is_numeric($x['delay'] ?? null) ? (int) $x['delay'] : null,
            ];
        };
        return [
            'flight' => $flight, 'found' => true,
            'airline' => mb_substr((string) ($pick['airline']['name'] ?? ''), 0, 60),
            'status' => mb_substr((string) ($pick['flight_status'] ?? ''), 0, 20),
            'date' => mb_substr((string) ($pick['flight_date'] ?? ''), 0, 10),
            'dep' => $leg($pick['departure'] ?? null), 'arr' => $leg($pick['arrival'] ?? null),
        ];
    }

    // ------------------------------------------------------------------ feed state

    public static function feedKey(string $p, array $params, int $owner): string
    {
        return sha1($p . '|' . json_encode($params) . '|' . $owner);
    }

    /**
     * Current state of a feed for the current hotel (registers the demand, so the background task keeps
     * it fresh). $inline: fetch right now if this feed has never been fetched (first use).
     *
     * @return array{status:string, data:?array, as_of:?int, stale:bool, error:?string, provider:string}
     */
    public static function get(string $p, array $params = [], bool $inline = false): array
    {
        $out = ['status' => 'pending', 'data' => null, 'as_of' => null, 'stale' => false, 'error' => null, 'provider' => $p];
        $params = self::cleanParams($p, $params);
        if ($params === null) {
            return ['status' => 'error', 'error' => 'Invalid parameters'] + $out;
        }
        [$key, $owner] = self::keyFor($p);
        if (self::PROVIDERS[$p]['key'] && $key === '') {
            return ['status' => 'no_key'] + $out;
        }
        $fk = self::feedKey($p, $params, $owner);
        $now = time();
        try {
            $row = self::$memo[$fk] ?? DB::one('SELECT * FROM data_feeds WHERE feed_key = :k', ['k' => $fk]);
            if (self::$readOnly) {
                // Draft preview: show what is stored, register / fetch nothing.
                return $row ? self::state($row) : $out;
            }
            if (!$row) {
                DB::query(
                    'INSERT INTO data_feeds (feed_key, provider, params, key_hotel_id, demand_at, created_at) VALUES (:k, :p, :pa, :o, :d, :c)
                     ON DUPLICATE KEY UPDATE demand_at = VALUES(demand_at)',
                    ['k' => $fk, 'p' => $p, 'pa' => json_encode($params), 'o' => $owner, 'd' => $now, 'c' => now()]
                );
                $row = DB::one('SELECT * FROM data_feeds WHERE feed_key = :k', ['k' => $fk]);
            } elseif ((int) $row['demand_at'] < $now - self::DEMAND_WRITE_EVERY) {
                DB::query('UPDATE data_feeds SET demand_at = :d WHERE id = :id', ['d' => $now, 'id' => $row['id']]);
                $row['demand_at'] = $now;
            }
            if ($row && $inline && $row['data'] === null && $row['attempted_at'] === null) {
                $row = self::refreshRow($row, $key);
            }
        } catch (PDOException $e) {
            Logger::error('DataFeeds: ' . $e->getMessage()); // table not migrated yet
            return $out;
        }
        if (!$row) {
            return $out;
        }
        self::$memo[$fk] = $row;
        return self::state($row);
    }

    /** Public state of a row (no key, no URL). */
    public static function state(array $row): array
    {
        $p = (string) $row['provider'];
        $data = $row['data'] !== null ? json_decode((string) $row['data'], true) : null;
        $asOf = $row['fetched_at'] !== null ? (int) $row['fetched_at'] : null;
        $ttl = self::ttl($p);
        $failed = (int) $row['error_count'] > 0;
        $stale = $data !== null && ($failed || ($asOf !== null && time() - $asOf > 2 * $ttl + 120));
        $status = $data === null ? ($failed ? 'error' : 'pending') : ($stale ? 'stale' : 'ok');
        return ['status' => $status, 'data' => is_array($data) ? $data : null, 'as_of' => $asOf, 'stale' => $stale, 'error' => $row['error'] !== null ? (string) $row['error'] : null, 'provider' => $p];
    }

    /** Is a row due for a refresh (TTL over and no back-off pending)? */
    public static function due(array $row, ?int $now = null): bool
    {
        $now ??= time();
        if ($row['retry_at'] !== null && (int) $row['retry_at'] > $now) {
            return false;
        }
        return $row['fetched_at'] === null || $now - (int) $row['fetched_at'] >= self::ttl((string) $row['provider']);
    }

    /**
     * Reserve request budget: overall per minute and the provider's daily cap (per key owner).
     * Returns false (and nothing is requested) when exhausted.
     */
    public static function budget(string $p, int $owner): bool
    {
        $n = (int) (self::PROVIDERS[$p]['per_fetch'] ?? 1);
        $minKey = 'feeds:min';
        $dayKey = 'feeds:day:' . $p . ':' . $owner;
        $perMin = self::perMinute();
        $cap = self::dailyCap($p);
        $used = static fn (string $k, int $win): int => (int) DB::value('SELECT hits FROM rate_limits WHERE rl_key = :k AND window_start = :w', ['k' => $k, 'w' => time() - (time() % $win)]);
        if ($used($minKey, 60) + $n > $perMin || ($cap > 0 && $used($dayKey, 86400) + $n > $cap)) {
            return false;
        }
        for ($i = 0; $i < $n; $i++) {
            RateLimiter::hit($minKey, PHP_INT_MAX, 60);
            if ($cap > 0) {
                RateLimiter::hit($dayKey, PHP_INT_MAX, 86400);
            }
        }
        return true;
    }

    /** Fetch one feed row now (budget permitting) and store the result. Returns the updated row, '_result' = ok | failed | budget. */
    public static function refreshRow(array $row, ?string $key = null): array
    {
        $p = (string) $row['provider'];
        if (!isset(self::PROVIDERS[$p])) {
            return $row;
        }
        $owner = (int) $row['key_hotel_id'];
        if ($key === null) {
            $key = self::PROVIDERS[$p]['key'] ? ($owner > 0 ? self::hotelKey($p, $owner) : self::platformKey($p)) : '';
        }
        $now = time();
        if (self::PROVIDERS[$p]['key'] && $key === '') {
            $upd = ['attempted_at' => $now, 'error' => 'No API key', 'error_count' => (int) $row['error_count'] + 1, 'retry_at' => $now + 3600];
        } elseif (!self::budget($p, $owner)) {
            return ['_result' => 'budget'] + $row; // over budget: try again on a later run
        } else {
            $params = json_decode((string) $row['params'], true);
            $r = self::fetch($p, is_array($params) ? $params : [], $key);
            if ($r['ok']) {
                $upd = ['data' => json_encode($r['data'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 'fetched_at' => $now, 'attempted_at' => $now, 'error' => null, 'error_count' => 0, 'retry_at' => null];
            } else {
                $errors = (int) $row['error_count'] + 1;
                $ttl = self::ttl($p);
                $backoff = $r['rate_limited'] ? max($ttl, 1800) : min(max($ttl, 300), 60 * (2 ** min($errors, 6)));
                $upd = ['attempted_at' => $now, 'error' => mb_substr((string) $r['error'], 0, 250), 'error_count' => $errors, 'retry_at' => $now + $backoff];
            }
        }
        DB::update('data_feeds', $upd, 'id = :id', ['id' => $row['id']]);
        self::$phMemo = [];
        return array_merge($row, $upd, ['_result' => $upd['error'] === null ? 'ok' : 'failed']);
    }

    /**
     * Background refresh (DataFeedsTask): feeds used in the last 24 h whose TTL is over, oldest first,
     * until the per-minute budget is used. Forgets feeds unused for 30 days.
     */
    public static function refreshDue(int $max = 50): array
    {
        $out = ['due' => 0, 'refreshed' => 0, 'failed' => 0, 'skipped' => 0];
        $now = time();
        try {
            DB::query('DELETE FROM data_feeds WHERE demand_at < :t', ['t' => $now - self::FORGET_AFTER]);
            $rows = DB::all('SELECT * FROM data_feeds WHERE demand_at >= :t ORDER BY COALESCE(fetched_at, 0), id LIMIT 500', ['t' => $now - self::ACTIVE_FOR]);
        } catch (PDOException $e) {
            Logger::error('DataFeeds: ' . $e->getMessage());
            return $out;
        }
        foreach ($rows as $row) {
            if (!isset(self::PROVIDERS[(string) $row['provider']]) || !self::due($row, $now)) {
                continue;
            }
            $out['due']++;
            if ($out['refreshed'] + $out['failed'] >= $max) {
                $out['skipped']++;
                continue;
            }
            $new = self::refreshRow($row);
            if (($new['_result'] ?? '') === 'budget') {
                $out['skipped']++;
            } elseif ($new['error'] === null) {
                $out['refreshed']++;
            } else {
                $out['failed']++;
            }
        }
        self::$memo = [];
        return $out;
    }

    // ------------------------------------------------------------------ feeds used by widgets

    /** Currency feed: ['rates' => [CODE => INR per unit], status…]. */
    public static function currency(bool $inline = false): array
    {
        return self::get(self::currencyProvider(), [], $inline);
    }

    /** INR per 1 USD (null when unknown). */
    public static function usdInr(): ?float
    {
        $c = self::currency();
        $v = $c['data']['rates']['USD'] ?? null;
        return is_numeric($v) && (float) $v > 0 ? (float) $v : null;
    }

    /** Index → Twelve Data symbol (platform setting platform_feed_idx_<index>). */
    public static function indexSymbol(string $index): string
    {
        $def = self::INDICES[$index][1] ?? '';
        $v = strtoupper(trim((string) Settings::platform('platform_feed_idx_' . $index, $def)));
        return preg_match(self::SYMBOL_RE, $v) ? $v : $def;
    }

    /**
     * [index => quote|null] for nifty / sensex / banknifty: Twelve Data when a key is set (stored values
     * only), else the hotel's manual rows whose label matches (e.g. "NIFTY 50", "Nifty", "BANK NIFTY").
     * quote = ['label', 'price', 'pct', 'change'].
     */
    public static function indexQuotes(): array
    {
        $out = array_fill_keys(array_keys(self::INDICES), null);
        if (self::hasKey('twelvedata')) {
            $syms = [];
            foreach (array_keys(self::INDICES) as $k) {
                $syms[$k] = self::indexSymbol($k);
            }
            $feed = self::get('twelvedata', ['symbols' => array_values($syms)]);
            foreach ($syms as $k => $s) {
                $q = $feed['data']['quotes'][$s] ?? null;
                if (is_array($q)) {
                    $out[$k] = ['label' => self::INDICES[$k][0], 'price' => (float) $q['price'], 'pct' => $q['pct'], 'change' => $q['change']];
                }
            }
        }
        $aliases = ['nifty' => ['NIFTY50', 'NIFTY'], 'sensex' => ['SENSEX', 'BSESENSEX'], 'banknifty' => ['BANKNIFTY', 'NIFTYBANK']];
        foreach (self::manualMarket() as $m) {
            $norm = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $m['label']));
            foreach ($aliases as $k => $names) {
                if ($out[$k] === null && in_array($norm, $names, true)) {
                    $out[$k] = $m;
                }
            }
        }
        return $out;
    }

    /**
     * Manual market rows of the hotel (admin/rates.php): "Label | value | change %" per line →
     * [['label', 'price' (float), 'pct' (?float)]].
     */
    public static function manualMarket(?string $text = null): array
    {
        $text ??= (string) Settings::get('rates_market_manual', '');
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 2 || $parts[0] === '') {
                continue;
            }
            $price = self::number($parts[1]);
            if ($price === null) {
                continue;
            }
            $pct = isset($parts[2]) ? self::number(rtrim($parts[2], '%')) : null;
            $out[] = ['label' => mb_substr($parts[0], 0, 40), 'price' => $price, 'pct' => $pct, 'change' => null];
            if (count($out) >= 30) {
                break;
            }
        }
        return $out;
    }

    /** "1,23,456.78" / "+0.45" / "-1.2" → float, null when not a number. */
    public static function number(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $s = str_replace([',', ' ', '₹', "\u{00A0}"], '', trim((string) $v));
        return preg_match('/^[+-]?\d{1,12}(\.\d{1,6})?$/', $s) ? (float) $s : null;
    }

    // ------------------------------------------------------------------ formatting

    /** Indian digit grouping: 1234567.5 → "12,34,567.50". */
    public static function inr(?float $v, int $dec = 2): string
    {
        if ($v === null) {
            return '—';
        }
        $neg = $v < 0;
        $s = number_format(abs($v), $dec, '.', '');
        [$int, $frac] = array_pad(explode('.', $s, 2), 2, '');
        if (strlen($int) > 3) {
            $last3 = substr($int, -3);
            $rest = substr($int, 0, -3);
            $rest = (string) preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $int = $rest . ',' . $last3;
        }
        return ($neg ? '-' : '') . $int . ($dec > 0 ? '.' . $frac : '');
    }

    /** Signed percent "+0.45%" / "-1.20%" (null → ''). */
    public static function pct(?float $v): string
    {
        return $v === null ? '' : ($v > 0 ? '+' : ($v < 0 ? '−' : '')) . number_format(abs($v), 2) . '%';
    }

    /** "as of" label in the hotel time zone, e.g. "7 October, 6:05 PM" (translated month). */
    public static function asOf(?int $ts): string
    {
        if (!$ts) {
            return '';
        }
        return date('j', $ts) . ' ' . __(date('F', $ts)) . ', ' . date('g:i', $ts) . ' ' . __(date('A', $ts));
    }

    /** Emoji flag of a country code (EU = 🇪🇺). */
    public static function flag(string $cc): string
    {
        $cc = strtoupper($cc);
        if (!preg_match('/^[A-Z]{2}$/', $cc)) {
            return '';
        }
        return mb_chr(0x1F1E6 + ord($cc[0]) - 65) . mb_chr(0x1F1E6 + ord($cc[1]) - 65);
    }

    // ------------------------------------------------------------------ ticker placeholders

    /**
     * Placeholder values of the current hotel: name => text ('—' when the value is unknown).
     * Reads stored feed values only (never fetches inline) and registers the demand.
     */
    public static function placeholderValues(): array
    {
        $hid = Tenant::current() ?? 0;
        if (isset(self::$phMemo[$hid])) {
            return self::$phMemo[$hid];
        }
        $v = array_fill_keys(self::PLACEHOLDERS, '—');
        try {
            $m = MetalRates::current();
            foreach (['gold_24k' => 'gold_24k', 'gold_22k' => 'gold_22k', 'gold_18k' => 'gold_18k', 'silver' => 'silver_kg'] as $ph => $f) {
                if ($m['values'][$f] !== null) {
                    $v[$ph] = '₹' . self::inr($m['values'][$f], 0);
                }
            }
            $rates = self::currency()['data']['rates'] ?? [];
            foreach (['usd', 'eur', 'gbp', 'aed', 'sar'] as $c) {
                $r = $rates[strtoupper($c)] ?? null;
                if (is_numeric($r)) {
                    $v[$c . '_inr'] = '₹' . self::inr((float) $r, 2);
                }
            }
            foreach (self::indexQuotes() as $idx => $q) {
                if ($q !== null) {
                    $v[$idx] = self::inr($q['price'], 2) . ($q['pct'] !== null ? ' (' . self::pct($q['pct']) . ')' : '');
                }
            }
        } catch (Throwable $e) {
            Logger::error('DataFeeds placeholders: ' . $e->getMessage());
        }
        // Plain text for the TV ticker: no control characters, no braces (no recursive placeholders).
        foreach ($v as $k => $s) {
            $v[$k] = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F{}<>]+/u', ' ', $s)), 0, 60);
        }
        return self::$phMemo[$hid] = $v;
    }

    /** Replace {placeholder}s in a text (single pass; unknown placeholders stay as they are). */
    public static function applyPlaceholders(string $text): string
    {
        if (!str_contains($text, '{')) {
            return $text;
        }
        $vals = null;
        return (string) preg_replace_callback('/\{([a-z0-9_]{2,20})\}/', static function (array $m) use (&$vals): string {
            if (!in_array($m[1], self::PLACEHOLDERS, true)) {
                return $m[0];
            }
            $vals ??= self::placeholderValues();
            return $vals[$m[1]] ?? $m[0];
        }, $text);
    }

    /** Resolve placeholders in a TV ticker object (Tickers::payload()). */
    public static function applyToTicker(?array $ticker): ?array
    {
        if ($ticker === null || !str_contains((string) ($ticker['text'] ?? ''), '{')) {
            return $ticker;
        }
        $ticker['messages'] = array_map(static fn ($m) => self::applyPlaceholders((string) $m), (array) ($ticker['messages'] ?? []));
        $ticker['text'] = self::applyPlaceholders((string) $ticker['text']);
        return $ticker;
    }
}

/** Provider error while parsing a response ($rateLimited: quota / rate limit reached). */
final class DataFeedError extends RuntimeException
{
    public function __construct(string $message, public bool $rateLimited = false)
    {
        parent::__construct($message);
    }
}
